<?php
declare(strict_types=1);

namespace Olon\WP\OlonJs\Tests\Unit\Hydration;

use InvalidArgumentException;
use Olon\WP\OlonJs\Hydration\SelectorToXPath;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Olon\WP\OlonJs\Hydration\SelectorToXPath
 */
final class SelectorToXPathTest extends TestCase
{
    public function test_single_tag(): void
    {
        $this->assertSame('//p', SelectorToXPath::translate('p'));
        $this->assertSame('//img', SelectorToXPath::translate('img'));
        $this->assertSame('//h2', SelectorToXPath::translate('h2'));
    }

    public function test_tag_list_becomes_xpath_union(): void
    {
        $this->assertSame(
            '//h1|//h2|//h3|//h4|//h5|//h6',
            SelectorToXPath::translate('h1,h2,h3,h4,h5,h6')
        );
    }

    public function test_tag_with_class(): void
    {
        $this->assertSame(
            "//div[contains(concat(' ', normalize-space(@class), ' '), ' wp-block-quote ')]",
            SelectorToXPath::translate('div.wp-block-quote')
        );
    }

    public function test_descendant_chain(): void
    {
        $this->assertSame('//figure//img', SelectorToXPath::translate('figure img'));
        $this->assertSame('//blockquote//p', SelectorToXPath::translate('blockquote p'));
    }

    public function test_descendant_chain_with_class(): void
    {
        $this->assertSame(
            "//figure//img[contains(concat(' ', normalize-space(@class), ' '), ' hero ')]",
            SelectorToXPath::translate('figure img.hero')
        );
    }

    public function test_whitespace_normalisation(): void
    {
        $this->assertSame('//figure//img', SelectorToXPath::translate('  figure   img  '));
        $this->assertSame('//h1|//h2', SelectorToXPath::translate(' h1 , h2 '));
    }

    public function test_tag_is_lowercased(): void
    {
        $this->assertSame('//p', SelectorToXPath::translate('P'));
    }

    public function test_unsupported_selector_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        SelectorToXPath::translate('p:nth-child(2)');
    }

    public function test_attribute_selector_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        SelectorToXPath::translate('a[href]');
    }

    public function test_empty_selector_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        SelectorToXPath::translate('');
    }

    public function test_only_commas_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        SelectorToXPath::translate(' , , ');
    }
}
