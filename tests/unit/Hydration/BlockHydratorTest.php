<?php
declare(strict_types=1);

namespace Olon\WP\OlonJs\Tests\Unit\Hydration;

use Olon\WP\OlonJs\Hydration\BlockHydrator;
use Olon\WP\OlonJs\Hydration\RichTextToMarkdown;
use Olon\WP\OlonJs\Hydration\SchemaSource;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Olon\WP\OlonJs\Hydration\BlockHydrator
 */
final class BlockHydratorTest extends TestCase
{
    /**
     * @param array<string,array<string,array<string,mixed>>> $schemas
     */
    private function makeSut(array $schemas): BlockHydrator
    {
        $resolver = static fn (string $name): array => $schemas[$name] ?? [];
        return new BlockHydrator(new SchemaSource(new RichTextToMarkdown()), \Closure::fromCallable($resolver));
    }

    public function test_hydrates_paragraph_content_from_innerHTML(): void
    {
        $sut = $this->makeSut([
            'core/paragraph' => [
                'content' => ['source' => 'rich-text', 'selector' => 'p'],
            ],
        ]);

        $block = [
            'blockName'    => 'core/paragraph',
            'attrs'        => [],
            'innerHTML'    => '<p>Hello <strong>world</strong></p>',
            'innerBlocks'  => [],
        ];

        $hydrated = $sut->hydrate($block);

        $this->assertSame('Hello **world**', $hydrated['attrs']['content']);
    }

    public function test_unknown_block_passes_through_untouched(): void
    {
        $sut = $this->makeSut([]);

        $block = [
            'blockName'   => 'third-party/unknown',
            'attrs'       => ['foo' => 'bar'],
            'innerHTML'   => '<p>ignored</p>',
            'innerBlocks' => [],
        ];

        $hydrated = $sut->hydrate($block);

        $this->assertSame(['foo' => 'bar'], $hydrated['attrs']);
    }

    public function test_applies_default_for_attrs_with_default_and_no_source(): void
    {
        $sut = $this->makeSut([
            'core/heading' => [
                'level' => ['type' => 'integer', 'default' => 2],
                'content' => ['source' => 'rich-text', 'selector' => 'h1,h2,h3,h4,h5,h6'],
            ],
        ]);

        $block = [
            'blockName'   => 'core/heading',
            'attrs'       => [],
            'innerHTML'   => '<h2>My title</h2>',
            'innerBlocks' => [],
        ];

        $hydrated = $sut->hydrate($block);
        $this->assertSame(2, $hydrated['attrs']['level']);
        $this->assertSame('My title', $hydrated['attrs']['content']);
    }

    public function test_recurses_into_innerBlocks(): void
    {
        $sut = $this->makeSut([
            'core/paragraph' => [
                'content' => ['source' => 'rich-text', 'selector' => 'p'],
            ],
            'core/columns' => [], // no own attrs
            'core/column'  => [],
        ]);

        $block = [
            'blockName'   => 'core/columns',
            'attrs'       => [],
            'innerHTML'   => '<div class="wp-block-columns"></div>',
            'innerBlocks' => [
                [
                    'blockName'   => 'core/column',
                    'attrs'       => [],
                    'innerHTML'   => '<div class="wp-block-column"></div>',
                    'innerBlocks' => [
                        [
                            'blockName'   => 'core/paragraph',
                            'attrs'       => [],
                            'innerHTML'   => '<p>Deep text</p>',
                            'innerBlocks' => [],
                        ],
                    ],
                ],
            ],
        ];

        $hydrated = $sut->hydrate($block);

        $this->assertSame(
            'Deep text',
            $hydrated['innerBlocks'][0]['innerBlocks'][0]['attrs']['content']
        );
    }

    public function test_existing_attrs_are_preserved_when_no_source_overrides(): void
    {
        $sut = $this->makeSut([
            'core/image' => [
                'id'  => ['type' => 'integer'],
                'url' => ['source' => 'attribute', 'selector' => 'img', 'attribute' => 'src'],
                'alt' => ['source' => 'attribute', 'selector' => 'img', 'attribute' => 'alt'],
            ],
        ]);

        $block = [
            'blockName'   => 'core/image',
            'attrs'       => ['id' => 123, 'sizeSlug' => 'large'],
            'innerHTML'   => '<figure><img src="/a.jpg" alt="An image"/></figure>',
            'innerBlocks' => [],
        ];

        $hydrated = $sut->hydrate($block);

        $this->assertSame(123, $hydrated['attrs']['id']);
        $this->assertSame('large', $hydrated['attrs']['sizeSlug']);
        $this->assertSame('/a.jpg', $hydrated['attrs']['url']);
        $this->assertSame('An image', $hydrated['attrs']['alt']);
    }

    public function test_null_blockName_is_not_hydrated_but_innerBlocks_still_recurse(): void
    {
        $sut = $this->makeSut([
            'core/paragraph' => ['content' => ['source' => 'rich-text', 'selector' => 'p']],
        ]);

        $block = [
            'blockName'   => null,
            'attrs'       => [],
            'innerHTML'   => '',
            'innerBlocks' => [
                ['blockName' => 'core/paragraph', 'attrs' => [], 'innerHTML' => '<p>x</p>', 'innerBlocks' => []],
            ],
        ];

        $hydrated = $sut->hydrate($block);
        $this->assertSame('x', $hydrated['innerBlocks'][0]['attrs']['content']);
    }
}
