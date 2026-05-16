<?php
declare(strict_types=1);

namespace Olon\WP\OlonJs\Hydration;

use InvalidArgumentException;

/**
 * Translates the subset of CSS selectors that Gutenberg uses in block.json
 * `selector` declarations into XPath, runnable by DOMXPath.
 *
 * Supported forms (combinable):
 *   - tag                       (e.g. "p", "img", "h2")
 *   - tag list                  (e.g. "h1,h2,h3,h4,h5,h6")
 *   - tag with class            (e.g. "div.wp-block-quote")
 *   - descendant chain          (e.g. "figure img", "blockquote p")
 *
 * Everything else → InvalidArgumentException (handled by caller as
 * "leave attribute unhydrated").
 */
final class SelectorToXPath
{
    private const PART_PATTERN = '/^([a-z][a-z0-9]*)(?:\.([a-zA-Z0-9_-]+))?$/i';

    public static function translate(string $selector): string
    {
        $alternations = array_map('trim', explode(',', $selector));
        $xpaths       = [];

        foreach ($alternations as $alt) {
            if ($alt === '') {
                continue;
            }
            $parts = preg_split('/\s+/', $alt) ?: [];
            $expr  = '';
            foreach ($parts as $part) {
                if (preg_match(self::PART_PATTERN, $part, $m) !== 1) {
                    throw new InvalidArgumentException("Unsupported selector segment: '$part' in '$selector'");
                }
                $tag      = strtolower($m[1]);
                $class    = $m[2] ?? null;
                $segment  = '//' . $tag;
                if ($class !== null && $class !== '') {
                    $segment .= "[contains(concat(' ', normalize-space(@class), ' '), ' $class ')]";
                }
                $expr .= $segment;
            }
            if ($expr !== '') {
                $xpaths[] = $expr;
            }
        }

        if ($xpaths === []) {
            throw new InvalidArgumentException("Empty selector: '$selector'");
        }

        return implode('|', $xpaths);
    }
}
