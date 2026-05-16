<?php
declare(strict_types=1);

namespace Olon\WP\OlonJs\Projection;

final class PageProjector
{
    public function __construct(private readonly BlockToSection $blockToSection)
    {
    }

    /**
     * Project a WordPress page (already extracted from WP_Post by the caller)
     * into an OlonJS Page document.
     *
     * The caller is responsible for resolving the post and extracting `title`,
     * `description`, `slug` and the `blocks` array (`parse_blocks()` output).
     * This keeps the Projection namespace WP-free and unit-testable.
     *
     * @param array{
     *     postId: int,
     *     slug: string,
     *     title: string,
     *     description: string,
     *     blocks: list<array<string,mixed>>
     * } $input
     * @return array{
     *     id: string,
     *     slug: string,
     *     meta: array{title:string,description:string},
     *     sections: list<array<string,mixed>>
     * }
     */
    public function project(array $input): array
    {
        return [
            'id'       => str_replace('/', '-', $input['slug']) . '-page',
            'slug'     => $input['slug'],
            'meta'     => [
                'title'       => $input['title'],
                'description' => $input['description'],
            ],
            'sections' => $this->projectSections($input['blocks'], $input['postId']),
        ];
    }

    /**
     * @param list<array<string,mixed>> $blocks
     * @return list<array<string,mixed>>
     */
    private function projectSections(array $blocks, int $postId): array
    {
        $sections   = [];
        $blockIndex = 0;
        foreach ($blocks as $block) {
            if (!is_array($block) || ($block['blockName'] ?? null) === null) {
                continue;
            }
            $sections[] = $this->blockToSection->project($block, $postId, [$blockIndex]);
            $blockIndex++;
        }
        return $sections;
    }
}
