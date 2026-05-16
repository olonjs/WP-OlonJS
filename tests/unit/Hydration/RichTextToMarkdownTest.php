<?php
declare(strict_types=1);

namespace Olon\WP\OlonJs\Tests\Unit\Hydration;

use Olon\WP\OlonJs\Hydration\RichTextToMarkdown;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Olon\WP\OlonJs\Hydration\RichTextToMarkdown
 */
final class RichTextToMarkdownTest extends TestCase
{
    private RichTextToMarkdown $sut;

    protected function setUp(): void
    {
        $this->sut = new RichTextToMarkdown();
    }

    public function test_plain_text_passes_through(): void
    {
        $this->assertSame('Hello world', $this->sut->convert('Hello world'));
    }

    public function test_strong_becomes_double_asterisk(): void
    {
        $this->assertSame('Hello **world**', $this->sut->convert('Hello <strong>world</strong>'));
    }

    public function test_b_becomes_double_asterisk(): void
    {
        $this->assertSame('Hello **world**', $this->sut->convert('Hello <b>world</b>'));
    }

    public function test_em_becomes_single_asterisk(): void
    {
        $this->assertSame('Hello *world*', $this->sut->convert('Hello <em>world</em>'));
    }

    public function test_i_becomes_single_asterisk(): void
    {
        $this->assertSame('Hello *world*', $this->sut->convert('Hello <i>world</i>'));
    }

    public function test_code_becomes_backtick(): void
    {
        $this->assertSame('Use `array_map`', $this->sut->convert('Use <code>array_map</code>'));
    }

    public function test_link_becomes_md_link(): void
    {
        $this->assertSame(
            'Click [here](https://example.com)',
            $this->sut->convert('Click <a href="https://example.com">here</a>')
        );
    }

    public function test_strikethrough_s_and_del(): void
    {
        $this->assertSame('~~gone~~', $this->sut->convert('<s>gone</s>'));
        $this->assertSame('~~gone~~', $this->sut->convert('<del>gone</del>'));
    }

    public function test_combined_inline_formatting(): void
    {
        $this->assertSame(
            'Hello **world**, [link](/x) and *italic*',
            $this->sut->convert('Hello <strong>world</strong>, <a href="/x">link</a> and <em>italic</em>')
        );
    }

    public function test_block_level_tags_are_stripped_preserving_inner_text(): void
    {
        // A stray <p> wrapper should not leak Markdown paragraph spacing.
        $this->assertSame('Inner text', $this->sut->convert('<p>Inner text</p>'));
    }

    public function test_unknown_tag_strips_keeping_inner_text(): void
    {
        $this->assertSame('Keep me', $this->sut->convert('<span class="x">Keep me</span>'));
    }

    public function test_empty_string_returns_empty(): void
    {
        $this->assertSame('', $this->sut->convert(''));
        $this->assertSame('', $this->sut->convert('   '));
    }
}
