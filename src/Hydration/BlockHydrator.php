<?php
declare(strict_types=1);

namespace Olon\WP\OlonJs\Hydration;

/**
 * Walks a parse_blocks() tree and enriches each block's `attrs` by applying
 * the attribute source declarations from its block type schema to the
 * block's `innerHTML`.
 *
 * Pure: the schema map is supplied by the caller via a closure (the WP
 * boundary lives in BlockTypeSchemaProvider; this class never touches it
 * directly).
 *
 * For every block found in the tree (recursively, at every depth):
 *   1. look up the schema by blockName via the resolver
 *   2. delegate the enrichment to SchemaSource
 *   3. recurse into innerBlocks
 *
 * Blocks whose blockName is unknown to the resolver, or whose schema is
 * empty, pass through with their `attrs` untouched.
 */
final class BlockHydrator
{
    /**
     * @param SchemaSource                                                $sources
     * @param callable(string):array<string,array<string,mixed>>          $schemaResolver  Maps blockName → attribute-schema map
     */
    public function __construct(
        private readonly SchemaSource $sources,
        private readonly \Closure $schemaResolver,
    ) {
    }

    /**
     * @param  array<string,mixed> $block
     * @return array<string,mixed>
     */
    public function hydrate(array $block): array
    {
        $name = $block['blockName'] ?? null;
        if (is_string($name) && $name !== '') {
            $schema = ($this->schemaResolver)($name);
            if (is_array($schema) && $schema !== []) {
                $attrs = is_array($block['attrs'] ?? null) ? $block['attrs'] : [];
                $html  = is_string($block['innerHTML'] ?? null) ? $block['innerHTML'] : '';
                $block['attrs'] = $this->sources->applyAll($schema, $attrs, $html);
            }
        }

        if (isset($block['innerBlocks']) && is_array($block['innerBlocks'])) {
            foreach ($block['innerBlocks'] as $i => $child) {
                if (is_array($child)) {
                    $block['innerBlocks'][$i] = $this->hydrate($child);
                }
            }
        }

        return $block;
    }
}
