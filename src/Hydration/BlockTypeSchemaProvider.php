<?php
declare(strict_types=1);

namespace Olon\WP\OlonJs\Hydration;

use WP_Block_Type_Registry;

/**
 * Single seam between the pure Hydration namespace and the WordPress global
 * `WP_Block_Type_Registry`. Looks up a block type's `attributes` declaration
 * map by blockName.
 *
 * Schemas are cached per-request: every block instance on a page asks for the
 * same blockName many times; we only hit the registry once per name.
 */
final class BlockTypeSchemaProvider
{
    /** @var array<string,array<string,array<string,mixed>>> */
    private array $cache = [];

    /**
     * @return array<string,array<string,mixed>>  attribute-name → declaration map; [] when unknown
     */
    public function forBlock(string $blockName): array
    {
        if (array_key_exists($blockName, $this->cache)) {
            return $this->cache[$blockName];
        }

        $registry = WP_Block_Type_Registry::get_instance();
        $type     = $registry->get_registered($blockName);

        if ($type === null || !is_array($type->attributes ?? null)) {
            return $this->cache[$blockName] = [];
        }

        // Each entry in $type->attributes is itself an array describing the
        // attribute. Filter out non-array entries defensively.
        $schema = [];
        foreach ($type->attributes as $name => $declaration) {
            if (is_string($name) && is_array($declaration)) {
                $schema[$name] = $declaration;
            }
        }
        return $this->cache[$blockName] = $schema;
    }

    /**
     * @return \Closure(string):array<string,array<string,mixed>>
     */
    public function asResolver(): \Closure
    {
        return fn (string $name): array => $this->forBlock($name);
    }
}
