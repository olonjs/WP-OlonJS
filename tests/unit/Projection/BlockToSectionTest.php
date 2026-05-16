<?php
declare(strict_types=1);

namespace Olon\WP\OlonJs\Tests\Unit\Projection;

use Olon\WP\OlonJs\Projection\BlockToSection;
use Olon\WP\OlonJs\Projection\IdAssigner;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Olon\WP\OlonJs\Projection\BlockToSection
 * @covers \Olon\WP\OlonJs\Projection\IdAssigner
 */
final class BlockToSectionTest extends TestCase
{
    private const POST_ID = 42;

    private BlockToSection $sut;

    protected function setUp(): void
    {
        $this->sut = new BlockToSection(new IdAssigner());
    }

    public function test_single_block_projects_to_one_section(): void
    {
        $block = [
            'blockName'   => 'core/paragraph',
            'attrs'       => ['content' => 'Hello'],
            'innerBlocks' => [],
        ];

        $section = $this->sut->project($block, self::POST_ID, [0]);

        $this->assertSame('42-0', $section['id']);
        $this->assertSame('core/paragraph', $section['type']);
        $this->assertSame(['content' => 'Hello'], $section['data']);
        $this->assertArrayNotHasKey('settings', $section);
    }

    public function test_existing_olonId_in_attrs_wins_over_path_derived_id(): void
    {
        $block = [
            'blockName'   => 'core/paragraph',
            'attrs'       => ['olonId' => 'sticky-id-xyz', 'content' => 'x'],
            'innerBlocks' => [],
        ];

        $section = $this->sut->project($block, self::POST_ID, [0]);

        $this->assertSame('sticky-id-xyz', $section['id']);
    }

    public function test_attrs_settings_are_promoted_to_section_settings(): void
    {
        $block = [
            'blockName'   => 'core/cover',
            'attrs'       => [
                'title'    => 'T',
                'settings' => ['theme' => 'dark', 'padding' => 'lg'],
            ],
            'innerBlocks' => [],
        ];

        $section = $this->sut->project($block, self::POST_ID, [0]);

        $this->assertArrayHasKey('settings', $section);
        $this->assertSame(['theme' => 'dark', 'padding' => 'lg'], $section['settings']);
    }

    public function test_no_settings_key_when_attrs_has_no_settings(): void
    {
        $block = [
            'blockName'   => 'core/cover',
            'attrs'       => ['title' => 'T'],
            'innerBlocks' => [],
        ];

        $section = $this->sut->project($block, self::POST_ID, [0]);

        $this->assertArrayNotHasKey('settings', $section);
    }

    public function test_attrs_settings_must_be_an_array_to_be_promoted(): void
    {
        $block = [
            'blockName'   => 'core/cover',
            'attrs'       => ['settings' => 'not-an-object'],
            'innerBlocks' => [],
        ];

        $section = $this->sut->project($block, self::POST_ID, [0]);

        $this->assertArrayNotHasKey('settings', $section);
    }

    public function test_nested_columns_project_into_data_innerBlocks_recursively(): void
    {
        $block = [
            'blockName'   => 'core/columns',
            'attrs'       => [],
            'innerBlocks' => [
                [
                    'blockName'   => 'core/column',
                    'attrs'       => ['width' => '50%'],
                    'innerBlocks' => [
                        [
                            'blockName'   => 'core/paragraph',
                            'attrs'       => ['content' => 'left'],
                            'innerBlocks' => [],
                        ],
                    ],
                ],
                [
                    'blockName'   => 'core/column',
                    'attrs'       => ['width' => '50%'],
                    'innerBlocks' => [
                        [
                            'blockName'   => 'core/paragraph',
                            'attrs'       => ['content' => 'right'],
                            'innerBlocks' => [],
                        ],
                    ],
                ],
            ],
        ];

        $section = $this->sut->project($block, self::POST_ID, [0]);

        $this->assertSame('core/columns', $section['type']);
        $this->assertArrayHasKey('innerBlocks', $section['data']);
        $this->assertCount(2, $section['data']['innerBlocks']);

        $left = $section['data']['innerBlocks'][0];
        $this->assertSame('core/column', $left['type']);
        $this->assertSame('42-0-0', $left['id']);
        $this->assertSame('50%', $left['data']['width']);
        $this->assertCount(1, $left['data']['innerBlocks']);
        $this->assertSame('left', $left['data']['innerBlocks'][0]['data']['content']);
        $this->assertSame('42-0-0-0', $left['data']['innerBlocks'][0]['id']);

        $right = $section['data']['innerBlocks'][1];
        $this->assertSame('42-0-1', $right['id']);
        $this->assertSame('right', $right['data']['innerBlocks'][0]['data']['content']);
    }

    public function test_null_blockName_children_are_skipped_at_every_depth(): void
    {
        $block = [
            'blockName'   => 'core/group',
            'attrs'       => [],
            'innerBlocks' => [
                ['blockName' => null, 'attrs' => [], 'innerBlocks' => []],
                ['blockName' => 'core/paragraph', 'attrs' => ['content' => 'keep'], 'innerBlocks' => []],
                ['blockName' => null, 'attrs' => [], 'innerBlocks' => []],
                [
                    'blockName'   => 'core/group',
                    'attrs'       => [],
                    'innerBlocks' => [
                        ['blockName' => null, 'attrs' => [], 'innerBlocks' => []],
                        ['blockName' => 'core/paragraph', 'attrs' => ['content' => 'deep'], 'innerBlocks' => []],
                    ],
                ],
            ],
        ];

        $section = $this->sut->project($block, self::POST_ID, [0]);

        $this->assertCount(2, $section['data']['innerBlocks']);
        $this->assertSame('keep', $section['data']['innerBlocks'][0]['data']['content']);
        $this->assertSame('42-0-0', $section['data']['innerBlocks'][0]['id']);

        $innerGroup = $section['data']['innerBlocks'][1];
        $this->assertCount(1, $innerGroup['data']['innerBlocks']);
        $this->assertSame('deep', $innerGroup['data']['innerBlocks'][0]['data']['content']);
        $this->assertSame('42-0-1-0', $innerGroup['data']['innerBlocks'][0]['id']);
    }

    public function test_child_order_is_preserved(): void
    {
        $block = [
            'blockName'   => 'core/group',
            'attrs'       => [],
            'innerBlocks' => [
                ['blockName' => 'core/paragraph', 'attrs' => ['content' => 'A'], 'innerBlocks' => []],
                ['blockName' => 'core/paragraph', 'attrs' => ['content' => 'B'], 'innerBlocks' => []],
                ['blockName' => 'core/paragraph', 'attrs' => ['content' => 'C'], 'innerBlocks' => []],
            ],
        ];

        $section = $this->sut->project($block, self::POST_ID, [0]);

        $contents = array_map(
            static fn (array $child): string => $child['data']['content'],
            $section['data']['innerBlocks']
        );
        $this->assertSame(['A', 'B', 'C'], $contents);
    }

    public function test_innerBlocks_key_absent_when_all_children_skipped(): void
    {
        $block = [
            'blockName'   => 'core/group',
            'attrs'       => ['note' => 'whitespace only'],
            'innerBlocks' => [
                ['blockName' => null, 'attrs' => [], 'innerBlocks' => []],
                ['blockName' => null, 'attrs' => [], 'innerBlocks' => []],
            ],
        ];

        $section = $this->sut->project($block, self::POST_ID, [0]);

        $this->assertSame(['note' => 'whitespace only'], $section['data']);
        $this->assertArrayNotHasKey('innerBlocks', $section['data']);
    }

    public function test_missing_attrs_defaults_to_empty_array(): void
    {
        $block = [
            'blockName'   => 'core/spacer',
            'innerBlocks' => [],
        ];

        $section = $this->sut->project($block, self::POST_ID, [0]);

        $this->assertSame([], $section['data']);
    }
}
