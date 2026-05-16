<?php
declare(strict_types=1);

namespace Olon\WP\OlonJs\Projection;

final class BlockToSection
{
    public function __construct(private readonly IdAssigner $ids)
    {
    }

    /**
     * Project a Gutenberg block (already hydrated by BlockHydrator) into an
     * OlonJS section. Recurses into innerBlocks, attaching children under
     * `data.innerBlocks` with the same shape at every depth.
     *
     * `data` mirrors the (hydrated) `attrs`. The hydration step extracts
     * structured content from innerHTML upstream, so this projector never
     * deals with HTML — `data` is content-only by construction.
     *
     * Metadata that is promoted to top-level section fields (`olonId` → `id`,
     * `settings` → `settings`) is stripped from `data` to avoid duplication.
     *
     * @param array<string,mixed> $block
     * @param list<int>           $path  Indices from root, used by IdAssigner.
     * @return array{id:string,type:string,data:array<string,mixed>,settings?:array<string,mixed>}
     */
    public function project(array $block, int $postId, array $path): array
    {
        $attrs = is_array($block['attrs'] ?? null) ? $block['attrs'] : [];
        $id    = $this->ids->ensure($postId, $path, $attrs);

        $data = $attrs;
        unset($data['olonId'], $data['settings']);

        $inner = is_array($block['innerBlocks'] ?? null) ? $block['innerBlocks'] : [];
        if ($inner !== []) {
            $projectedChildren = [];
            $childIndex        = 0;
            foreach ($inner as $child) {
                if (!is_array($child) || ($child['blockName'] ?? null) === null) {
                    continue;
                }
                $projectedChildren[] = $this->project($child, $postId, [...$path, $childIndex]);
                $childIndex++;
            }
            if ($projectedChildren !== []) {
                $data['innerBlocks'] = $projectedChildren;
            }
        }

        $section = [
            'id'   => $id,
            'type' => (string) ($block['blockName'] ?? ''),
            'data' => $data,
        ];

        if (isset($attrs['settings']) && is_array($attrs['settings'])) {
            $section['settings'] = $attrs['settings'];
        }

        return $section;
    }
}
