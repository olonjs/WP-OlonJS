<?php
declare(strict_types=1);

namespace Olon\WP\OlonJs\Tests\Integration;

/**
 * T6: olonId persistence on save_post.
 *
 * - First save populates olonId on every block at every depth.
 * - Re-saving unchanged content does not change ids.
 * - Editing one block leaves the others' ids alone.
 * - Adding a block produces a new id; reordering does not.
 * - Reads (GET /$slug.json) do not mutate the post.
 */
final class IdStabilityTest extends IntegrationTestCase
{
    private const NESTED_CONTENT = <<<HTML
<!-- wp:columns -->
<div class="wp-block-columns"><!-- wp:column -->
<div class="wp-block-column"><!-- wp:paragraph -->
<p>Left</p>
<!-- /wp:paragraph --></div>
<!-- /wp:column -->

<!-- wp:column -->
<div class="wp-block-column"><!-- wp:paragraph -->
<p>Right</p>
<!-- /wp:paragraph --></div>
<!-- /wp:column --></div>
<!-- /wp:columns -->

<!-- wp:paragraph -->
<p>Trailing paragraph</p>
<!-- /wp:paragraph -->
HTML;

    public function test_first_save_assigns_olonId_at_every_depth(): void
    {
        $postId = $this->createPage(self::NESTED_CONTENT);
        $blocks = $this->blocksOf($postId);

        $this->assertCount(2, $blocks);
        $columnsId = $this->assertUuidId($blocks[0]);
        $trailingId = $this->assertUuidId($blocks[1]);

        $this->assertCount(2, $blocks[0]['innerBlocks']);
        $leftColId  = $this->assertUuidId($blocks[0]['innerBlocks'][0]);
        $rightColId = $this->assertUuidId($blocks[0]['innerBlocks'][1]);

        $leftPara  = $this->assertUuidId($blocks[0]['innerBlocks'][0]['innerBlocks'][0]);
        $rightPara = $this->assertUuidId($blocks[0]['innerBlocks'][1]['innerBlocks'][0]);

        $all = [$columnsId, $trailingId, $leftColId, $rightColId, $leftPara, $rightPara];
        $this->assertCount(count($all), array_unique($all), 'All assigned ids must be unique.');
    }

    public function test_resaving_unchanged_content_keeps_ids_stable(): void
    {
        $postId = $this->createPage(self::NESTED_CONTENT);
        $first  = $this->collectIds($this->blocksOf($postId));

        \wp_update_post(['ID' => $postId, 'post_title' => 'Touched title']);
        $second = $this->collectIds($this->blocksOf($postId));

        $this->assertSame($first, $second);
    }

    public function test_editing_one_block_leaves_other_ids_untouched(): void
    {
        $postId = $this->createPage(self::NESTED_CONTENT);
        $before = $this->collectIds($this->blocksOf($postId));

        $blocks = $this->blocksOf($postId);
        $blocks[1]['innerHTML']      = "\n<p>Edited trailing paragraph</p>\n";
        $blocks[1]['innerContent']   = ["\n<p>Edited trailing paragraph</p>\n"];

        \wp_update_post(['ID' => $postId, 'post_content' => \serialize_blocks($blocks)]);
        $after = $this->collectIds($this->blocksOf($postId));

        $this->assertSame($before, $after, 'Editing inner HTML of one block must not change any olonId.');
    }

    public function test_adding_a_block_assigns_a_new_id_only_to_the_new_block(): void
    {
        $postId = $this->createPage(self::NESTED_CONTENT);
        $before = $this->collectIds($this->blocksOf($postId));

        $blocks   = $this->blocksOf($postId);
        $blocks[] = [
            'blockName'    => 'core/paragraph',
            'attrs'        => [],
            'innerBlocks'  => [],
            'innerHTML'    => "\n<p>New block</p>\n",
            'innerContent' => ["\n<p>New block</p>\n"],
        ];

        \wp_update_post(['ID' => $postId, 'post_content' => \serialize_blocks($blocks)]);
        $after = $this->collectIds($this->blocksOf($postId));

        $this->assertCount(count($before) + 1, $after);
        foreach ($before as $existing) {
            $this->assertContains($existing, $after, 'Existing ids must survive the addition.');
        }
        $newIds = array_values(array_diff($after, $before));
        $this->assertCount(1, $newIds);
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $newIds[0]);
    }

    public function test_reordering_blocks_keeps_their_ids(): void
    {
        $postId = $this->createPage(self::NESTED_CONTENT);
        $before = $this->collectIds($this->blocksOf($postId));

        $blocks   = $this->blocksOf($postId);
        $reversed = array_reverse($blocks);

        \wp_update_post(['ID' => $postId, 'post_content' => \serialize_blocks($reversed)]);
        $after = $this->collectIds($this->blocksOf($postId));

        sort($before);
        sort($after);
        $this->assertSame($before, $after);
    }

    public function test_get_request_does_not_mutate_the_post(): void
    {
        $postId = $this->createPage(self::NESTED_CONTENT);
        $modifiedBefore = get_post_field('post_modified_gmt', $postId);

        $this->go_to(\home_url('/id-stability.json'));
        \ob_start();
        \do_action('template_redirect');
        \ob_get_clean();

        \clean_post_cache($postId);
        $modifiedAfter = get_post_field('post_modified_gmt', $postId);

        $this->assertSame($modifiedBefore, $modifiedAfter, 'GET must not bump post_modified_gmt.');
    }

    private function createPage(string $content): int
    {
        return (int) self::factory()->post->create([
            'post_type'    => 'page',
            'post_status'  => 'publish',
            'post_name'    => 'id-stability',
            'post_title'   => 'Id stability test page',
            'post_excerpt' => 'A description long enough to satisfy schema minLength of fifty characters.',
            'post_content' => $content,
        ]);
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function blocksOf(int $postId): array
    {
        \clean_post_cache($postId);
        $post = \get_post($postId);
        return \parse_blocks($post->post_content);
    }

    private function assertUuidId(array $block): string
    {
        $this->assertArrayHasKey('attrs', $block);
        $this->assertArrayHasKey('olonId', $block['attrs']);
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $block['attrs']['olonId']);
        return $block['attrs']['olonId'];
    }

    /**
     * @param list<array<string,mixed>> $blocks
     * @return list<string>
     */
    private function collectIds(array $blocks): array
    {
        $ids = [];
        $walk = function (array $blocks) use (&$walk, &$ids): void {
            foreach ($blocks as $block) {
                if (!is_array($block) || ($block['blockName'] ?? null) === null) {
                    continue;
                }
                if (isset($block['attrs']['olonId']) && is_string($block['attrs']['olonId'])) {
                    $ids[] = $block['attrs']['olonId'];
                }
                if (isset($block['innerBlocks']) && is_array($block['innerBlocks'])) {
                    $walk($block['innerBlocks']);
                }
            }
        };
        $walk($blocks);
        return $ids;
    }
}
