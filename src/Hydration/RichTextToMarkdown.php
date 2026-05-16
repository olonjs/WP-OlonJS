<?php
declare(strict_types=1);

namespace Olon\WP\OlonJs\Hydration;

use League\HTMLToMarkdown\Converter\CodeConverter;
use League\HTMLToMarkdown\Converter\ConverterInterface;
use League\HTMLToMarkdown\Converter\EmphasisConverter;
use League\HTMLToMarkdown\Converter\HardBreakConverter;
use League\HTMLToMarkdown\Converter\LinkConverter;
use League\HTMLToMarkdown\Converter\TextConverter;
use League\HTMLToMarkdown\ElementInterface;
use League\HTMLToMarkdown\Environment;
use League\HTMLToMarkdown\HtmlConverter;

/**
 * Converts a Gutenberg `rich-text` HTML fragment to inline Markdown.
 *
 * Gutenberg already breaks block-level structure into separate blocks, so a
 * single rich-text value never legitimately contains paragraphs, headings,
 * lists, etc. We therefore build an Environment with only the converters for
 * the inline subset we support. Any block-level tag accidentally fed in has
 * its markup stripped (via the library's strip_tags config) while its inner
 * text is preserved.
 *
 * Supported inline conversions:
 *   <strong>/<b>  → **x**
 *   <em>/<i>      → *x*
 *   <code>        → `x`
 *   <a href="u">  → [x](u)
 *   <br>          → newline
 *   <s>/<del>     → ~~x~~  (via the custom converter below)
 *
 * Anything else: inner text is kept, surrounding tags are stripped.
 */
final class RichTextToMarkdown
{
    private readonly HtmlConverter $converter;

    public function __construct()
    {
        $environment = new Environment([
            'strip_tags'    => true,
            'hard_break'    => true,
            'use_autolinks' => false,
            'italic_style'  => '*',
            'bold_style'    => '**',
        ]);

        $environment->addConverter(new TextConverter());
        $environment->addConverter(new EmphasisConverter());
        $environment->addConverter(new CodeConverter());
        $environment->addConverter(new LinkConverter());
        $environment->addConverter(new HardBreakConverter());
        $environment->addConverter(new StrikethroughInlineConverter());

        $this->converter = new HtmlConverter($environment);
    }

    public function convert(string $html): string
    {
        $html = trim($html);
        if ($html === '') {
            return '';
        }

        return trim($this->converter->convert($html));
    }
}

/**
 * Inline converter for ~~strikethrough~~ on <s> and <del>.
 * The upstream library does not ship one out of the box.
 */
final class StrikethroughInlineConverter implements ConverterInterface
{
    public function convert(ElementInterface $element): string
    {
        $content = $element->getValue();
        if (trim($content) === '') {
            return $content;
        }
        return '~~' . $content . '~~';
    }

    /**
     * @return list<string>
     */
    public function getSupportedTags(): array
    {
        return ['s', 'del'];
    }
}
