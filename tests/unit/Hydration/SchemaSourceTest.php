<?php
declare(strict_types=1);

namespace Olon\WP\OlonJs\Tests\Unit\Hydration;

use Olon\WP\OlonJs\Hydration\RichTextToMarkdown;
use Olon\WP\OlonJs\Hydration\SchemaSource;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Olon\WP\OlonJs\Hydration\SchemaSource
 */
final class SchemaSourceTest extends TestCase
{
    private SchemaSource $sut;

    protected function setUp(): void
    {
        $this->sut = new SchemaSource(new RichTextToMarkdown());
    }

    public function test_no_source_keeps_existing_value(): void
    {
        $schema = ['level' => ['type' => 'integer']];
        $result = $this->sut->applyAll($schema, ['level' => 3], '<h3>x</h3>');
        $this->assertSame(3, $result['level']);
    }

    public function test_no_source_applies_default_when_value_absent(): void
    {
        $schema = ['level' => ['type' => 'integer', 'default' => 2]];
        $result = $this->sut->applyAll($schema, [], '<h2>x</h2>');
        $this->assertSame(2, $result['level']);
    }

    public function test_text_source(): void
    {
        $schema = ['content' => ['source' => 'text', 'selector' => 'code']];
        $result = $this->sut->applyAll($schema, [], '<pre><code>echo 1;</code></pre>');
        $this->assertSame('echo 1;', $result['content']);
    }

    public function test_rich_text_source_returns_markdown(): void
    {
        $schema = ['content' => ['source' => 'rich-text', 'selector' => 'p']];
        $result = $this->sut->applyAll($schema, [], '<p>Hello <strong>world</strong>!</p>');
        $this->assertSame('Hello **world**!', $result['content']);
    }

    public function test_rich_text_source_with_link(): void
    {
        $schema = ['content' => ['source' => 'rich-text', 'selector' => 'p']];
        $result = $this->sut->applyAll($schema, [], '<p>Click <a href="/x">here</a></p>');
        $this->assertSame('Click [here](/x)', $result['content']);
    }

    public function test_html_source_preserves_html(): void
    {
        $schema = ['value' => ['source' => 'html', 'selector' => 'blockquote']];
        $result = $this->sut->applyAll($schema, [], '<blockquote><p>quoted</p></blockquote>');
        $this->assertSame('<p>quoted</p>', $result['value']);
    }

    public function test_attribute_source(): void
    {
        $schema = [
            'url' => ['source' => 'attribute', 'selector' => 'img', 'attribute' => 'src'],
            'alt' => ['source' => 'attribute', 'selector' => 'img', 'attribute' => 'alt'],
        ];
        $result = $this->sut->applyAll($schema, [], '<figure><img src="/a.jpg" alt="An image"/></figure>');
        $this->assertSame('/a.jpg', $result['url']);
        $this->assertSame('An image', $result['alt']);
    }

    public function test_tag_source_returns_lowercased_tag_name(): void
    {
        $schema = ['tagName' => ['source' => 'tag', 'selector' => 'h1,h2,h3']];
        $result = $this->sut->applyAll($schema, [], '<h3>Title</h3>');
        $this->assertSame('h3', $result['tagName']);
    }

    public function test_query_source_without_subselector_uses_item_node(): void
    {
        // Sub-schema has no `selector` → applies to the matched item itself.
        $schema = [
            'items' => [
                'source' => 'query',
                'selector' => 'li',
                'query' => [
                    'text' => ['source' => 'text'],
                ],
            ],
        ];
        $result = $this->sut->applyAll($schema, [], '<ul><li>One</li><li>Two</li><li>Three</li></ul>');
        $this->assertCount(3, $result['items']);
        $this->assertSame('One', $result['items'][0]['text']);
        $this->assertSame('Three', $result['items'][2]['text']);
    }

    public function test_query_source_with_attribute_on_item_node(): void
    {
        // Sub-schema reads an attribute from the matched item itself
        // (no inner selector → operates on the item node).
        $schema = [
            'items' => [
                'source' => 'query',
                'selector' => 'a',
                'query' => [
                    'href' => ['source' => 'attribute', 'attribute' => 'href'],
                    'text' => ['source' => 'text'],
                ],
            ],
        ];
        $result = $this->sut->applyAll($schema, [], '<nav><a href="/a">A</a><a href="/b">B</a></nav>');
        $this->assertCount(2, $result['items']);
        $this->assertSame('/a', $result['items'][0]['href']);
        $this->assertSame('A',  $result['items'][0]['text']);
        $this->assertSame('/b', $result['items'][1]['href']);
        $this->assertSame('B',  $result['items'][1]['text']);
    }

    public function test_query_source_with_descendant_subselector(): void
    {
        // Realistic case: outer `li`, inner `a` is a DESCENDANT.
        $schema = [
            'items' => [
                'source' => 'query',
                'selector' => 'li',
                'query' => [
                    'url' => ['source' => 'attribute', 'selector' => 'a', 'attribute' => 'href'],
                    'label' => ['source' => 'text', 'selector' => 'a'],
                ],
            ],
        ];
        $html = '<ul><li><a href="/x">X</a></li><li><a href="/y">Y</a></li></ul>';
        $result = $this->sut->applyAll($schema, [], $html);
        $this->assertCount(2, $result['items']);
        $this->assertSame('/x', $result['items'][0]['url']);
        $this->assertSame('X',  $result['items'][0]['label']);
    }

    public function test_existing_attr_is_overridden_by_extraction(): void
    {
        // The block comment had `{content: "stale"}` but the HTML has the real text.
        $schema = ['content' => ['source' => 'rich-text', 'selector' => 'p']];
        $result = $this->sut->applyAll($schema, ['content' => 'stale'], '<p>fresh</p>');
        $this->assertSame('fresh', $result['content']);
    }

    public function test_unsupported_source_leaves_attr_untouched(): void
    {
        $schema = ['content' => ['source' => 'children', 'selector' => 'p']];
        $result = $this->sut->applyAll($schema, ['content' => ['legacy']], '<p>ignored</p>');
        $this->assertSame(['legacy'], $result['content']);
    }

    public function test_no_match_with_default_applies_default(): void
    {
        $schema = ['content' => ['source' => 'text', 'selector' => 'p', 'default' => 'fallback']];
        $result = $this->sut->applyAll($schema, [], '<div>no p here</div>');
        $this->assertSame('fallback', $result['content']);
    }

    public function test_no_match_without_default_leaves_attr_absent(): void
    {
        $schema = ['content' => ['source' => 'text', 'selector' => 'p']];
        $result = $this->sut->applyAll($schema, [], '<div>no p here</div>');
        $this->assertArrayNotHasKey('content', $result);
    }

    public function test_unparsable_selector_does_not_crash(): void
    {
        $schema = ['content' => ['source' => 'text', 'selector' => 'p:nth-child(2)']];
        $result = $this->sut->applyAll($schema, ['content' => 'kept'], '<p>x</p>');
        $this->assertSame('kept', $result['content']);
    }

    public function test_empty_inner_html_only_applies_defaults(): void
    {
        $schema = [
            'a' => ['source' => 'text', 'selector' => 'p', 'default' => 'fallback'],
            'b' => ['type' => 'integer', 'default' => 7],
        ];
        $result = $this->sut->applyAll($schema, [], '');
        $this->assertSame('fallback', $result['a']);
        $this->assertSame(7, $result['b']);
    }
}
