<?php
declare(strict_types=1);

namespace Olon\WP\OlonJs\Projection;

/**
 * Section id resolution and persistence.
 *
 * - `ensure()` is a pure read used by `BlockToSection` during projection.
 * - `assignMissingIds()` walks a serialised block tree, assigns a uuid to any
 *   block missing `attrs.olonId` at any depth, and returns the (possibly
 *   re-serialised) content. It is the only mutation entry-point and is invoked
 *   exclusively from the `save_post_page` hook.
 *
 * If no id is missing the original content string is returned verbatim, which
 * keeps `serialize_blocks()` normalisation out of the way of clean saves.
 */
final class IdAssigner
{
    /**
     * @param list<int>           $path
     * @param array<string,mixed> $attrs
     */
    public function ensure(int $postId, array $path, array $attrs): string
    {
        $existing = $attrs['olonId'] ?? null;
        if (is_string($existing) && $existing !== '') {
            return $existing;
        }

        return $path === []
            ? (string) $postId
            : $postId . '-' . implode('-', $path);
    }

    public function assignMissingIds(string $content): string
    {
        if ($content === '') {
            return $content;
        }

        $blocks  = parse_blocks($content);
        $mutated = false;

        foreach ($blocks as &$block) {
            if (!is_array($block)) {
                continue;
            }
            if ($this->walk($block)) {
                $mutated = true;
            }
        }
        unset($block);

        return $mutated ? serialize_blocks($blocks) : $content;
    }

    /**
     * @param array<string,mixed> $block
     */
    private function walk(array &$block): bool
    {
        if (($block['blockName'] ?? null) === null) {
            return false;
        }

        $mutated = false;

        $attrs = is_array($block['attrs'] ?? null) ? $block['attrs'] : [];
        if (!isset($attrs['olonId']) || !is_string($attrs['olonId']) || $attrs['olonId'] === '') {
            $attrs['olonId'] = $this->newId();
            $block['attrs']  = $attrs;
            $mutated         = true;
        }

        if (isset($block['innerBlocks']) && is_array($block['innerBlocks'])) {
            foreach ($block['innerBlocks'] as &$child) {
                if (!is_array($child)) {
                    continue;
                }
                if ($this->walk($child)) {
                    $mutated = true;
                }
            }
            unset($child);
        }

        return $mutated;
    }

    private function newId(): string
    {
        return wp_generate_uuid4();
    }
}
