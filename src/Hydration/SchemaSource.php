<?php
declare(strict_types=1);

namespace Olon\WP\OlonJs\Hydration;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use InvalidArgumentException;

/**
 * Applies a single Gutenberg attribute source declaration to an innerHTML
 * fragment and returns the extracted value.
 *
 * Supported sources (per block.json `attributes[*].source`):
 *   - (none)       value already provided by parse_blocks(); apply schema default
 *   - attribute    selector + attribute name → attribute value
 *   - text         text content of selector match (no tags)
 *   - rich-text    inner HTML of selector match, converted to Markdown
 *   - html         inner HTML of selector match (HTML preserved)
 *   - tag          tag name of selector match (lowercased)
 *   - query        per-item sub-schema applied to each selector match
 *
 * Unsupported sources (children/raw/meta) leave the value untouched.
 */
final class SchemaSource
{
    public function __construct(private readonly RichTextToMarkdown $markdown)
    {
    }

    /**
     * Apply every declared source in $schema to $innerHTML, merging the
     * results on top of any values already present in $existingAttrs.
     *
     * @param  array<string,array<string,mixed>> $schema       Per attribute name: its raw block.json declaration
     * @param  array<string,mixed>               $existingAttrs Attrs already extracted from the block comment JSON
     * @return array<string,mixed>
     */
    public function applyAll(array $schema, array $existingAttrs, string $innerHTML): array
    {
        $xpath = $this->loadXPath($innerHTML);
        $out   = $existingAttrs;

        foreach ($schema as $attrName => $declaration) {
            if (!is_array($declaration)) {
                continue;
            }

            $source = $declaration['source'] ?? null;

            // No source: rely on the value already in $existingAttrs.
            // If absent, fall back to the schema default (when declared).
            if ($source === null) {
                if (!array_key_exists($attrName, $out) && array_key_exists('default', $declaration)) {
                    $out[$attrName] = $declaration['default'];
                }
                continue;
            }

            if ($xpath === null) {
                // No DOM to query → nothing to extract from. Apply default if any.
                if (!array_key_exists($attrName, $out) && array_key_exists('default', $declaration)) {
                    $out[$attrName] = $declaration['default'];
                }
                continue;
            }

            $extracted = $this->extract($source, $declaration, $xpath);
            if ($extracted !== null) {
                $out[$attrName] = $extracted;
            } elseif (!array_key_exists($attrName, $out) && array_key_exists('default', $declaration)) {
                $out[$attrName] = $declaration['default'];
            }
        }

        return $out;
    }

    /**
     * @param array<string,mixed> $declaration
     * @return mixed|null  null = nothing matched (caller may apply default)
     */
    private function extract(string $source, array $declaration, DOMXPath $xpath): mixed
    {
        $selector = $declaration['selector'] ?? null;
        if (!is_string($selector) || $selector === '') {
            return null;
        }

        try {
            $expr = SelectorToXPath::translate($selector);
        } catch (InvalidArgumentException) {
            return null;
        }

        $nodes = $xpath->query($expr);
        if ($nodes === false || $nodes->length === 0) {
            return null;
        }

        return match ($source) {
            'attribute' => $this->extractAttribute($nodes->item(0), $declaration),
            'text'      => $this->extractText($nodes->item(0)),
            'rich-text' => $this->extractRichText($nodes->item(0)),
            'html'      => $this->extractHtml($nodes->item(0)),
            'tag'       => $this->extractTag($nodes->item(0)),
            'query'     => $this->extractQuery($nodes, $declaration, $xpath),
            default     => null,
        };
    }

    /**
     * @param array<string,mixed> $declaration
     */
    private function extractAttribute(?DOMNode $node, array $declaration): ?string
    {
        if (!$node instanceof DOMElement) {
            return null;
        }
        $attribute = $declaration['attribute'] ?? null;
        if (!is_string($attribute) || $attribute === '') {
            return null;
        }
        if (!$node->hasAttribute($attribute)) {
            return null;
        }
        return $node->getAttribute($attribute);
    }

    private function extractText(?DOMNode $node): ?string
    {
        if (!$node instanceof DOMElement) {
            return null;
        }
        $text = $node->textContent;
        return $text === '' ? null : $text;
    }

    private function extractRichText(?DOMNode $node): ?string
    {
        if (!$node instanceof DOMElement) {
            return null;
        }
        $html = $this->innerHtml($node);
        if (trim($html) === '') {
            return null;
        }
        return $this->markdown->convert($html);
    }

    private function extractHtml(?DOMNode $node): ?string
    {
        if (!$node instanceof DOMElement) {
            return null;
        }
        $html = $this->innerHtml($node);
        return trim($html) === '' ? null : $html;
    }

    private function extractTag(?DOMNode $node): ?string
    {
        if (!$node instanceof DOMElement) {
            return null;
        }
        return strtolower($node->tagName);
    }

    /**
     * @param \DOMNodeList<DOMNode> $nodes
     * @param array<string,mixed>   $declaration
     * @return list<array<string,mixed>>
     */
    private function extractQuery(\DOMNodeList $nodes, array $declaration, DOMXPath $xpath): array
    {
        $subSchema = $declaration['query'] ?? null;
        if (!is_array($subSchema)) {
            return [];
        }

        $items = [];
        foreach ($nodes as $node) {
            if (!$node instanceof DOMElement) {
                continue;
            }
            $itemXPath = new DOMXPath($node->ownerDocument);
            $item      = [];
            foreach ($subSchema as $key => $sub) {
                if (!is_array($sub)) {
                    continue;
                }
                $sel = $sub['selector'] ?? null;
                if (is_string($sel) && $sel !== '') {
                    try {
                        $expr = SelectorToXPath::translate($sel);
                    } catch (InvalidArgumentException) {
                        continue;
                    }
                    // Scope sub-query to the current item subtree (descendants only).
                    $childNodes = $itemXPath->query('.' . $expr, $node);
                    if ($childNodes === false || $childNodes->length === 0) {
                        continue;
                    }
                    $target = $childNodes->item(0);
                } else {
                    // No sub-selector → operate on the item node itself.
                    $target = $node;
                }

                $subSource = $sub['source'] ?? null;
                $value     = match ($subSource) {
                    'attribute' => $this->extractAttribute($target, $sub),
                    'text'      => $this->extractText($target),
                    'rich-text' => $this->extractRichText($target),
                    'html'      => $this->extractHtml($target),
                    'tag'       => $this->extractTag($target),
                    default     => null,
                };
                if ($value !== null) {
                    $item[$key] = $value;
                }
            }
            if ($item !== []) {
                $items[] = $item;
            }
        }
        return $items;
    }

    private function innerHtml(DOMElement $node): string
    {
        $html = '';
        foreach ($node->childNodes as $child) {
            $html .= $node->ownerDocument->saveHTML($child);
        }
        return $html;
    }

    private function loadXPath(string $innerHTML): ?DOMXPath
    {
        $innerHTML = trim($innerHTML);
        if ($innerHTML === '') {
            return null;
        }

        $doc = new DOMDocument();
        // Wrap fragment to give DOMDocument a valid root and force UTF-8.
        $wrapped = '<?xml encoding="utf-8" ?><root>' . $innerHTML . '</root>';
        $loaded  = @$doc->loadHTML($wrapped, LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        if ($loaded === false) {
            return null;
        }
        return new DOMXPath($doc);
    }
}
