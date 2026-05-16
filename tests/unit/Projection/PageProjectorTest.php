<?php
declare(strict_types=1);

namespace Olon\WP\OlonJs\Tests\Unit\Projection;

use Olon\WP\OlonJs\Projection\BlockToSection;
use Olon\WP\OlonJs\Projection\IdAssigner;
use Olon\WP\OlonJs\Projection\PageProjector;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Olon\WP\OlonJs\Projection\PageProjector
 * @covers \Olon\WP\OlonJs\Projection\BlockToSection
 * @covers \Olon\WP\OlonJs\Projection\IdAssigner
 */
final class PageProjectorTest extends TestCase
{
    private PageProjector $sut;

    protected function setUp(): void
    {
        $this->sut = new PageProjector(new BlockToSection(new IdAssigner()));
    }

    public function test_projects_full_page_document(): void
    {
        $page = $this->sut->project([
            'postId'      => 42,
            'slug'        => 'about',
            'title'       => 'About the company',
            'description' => 'A long-enough description explaining who we are and what we build.',
            'blocks'      => [
                ['blockName' => 'core/heading', 'attrs' => ['level' => 1, 'content' => 'Hello'], 'innerBlocks' => []],
                ['blockName' => 'core/paragraph', 'attrs' => ['content' => 'Body'], 'innerBlocks' => []],
            ],
        ]);

        $this->assertSame('about-page', $page['id']);
        $this->assertSame('about', $page['slug']);
        $this->assertSame('About the company', $page['meta']['title']);
        $this->assertSame(
            'A long-enough description explaining who we are and what we build.',
            $page['meta']['description']
        );
        $this->assertCount(2, $page['sections']);
        $this->assertSame('core/heading', $page['sections'][0]['type']);
        $this->assertSame('42-0', $page['sections'][0]['id']);
        $this->assertSame('core/paragraph', $page['sections'][1]['type']);
        $this->assertSame('42-1', $page['sections'][1]['id']);
    }

    public function test_empty_blocks_produces_empty_sections_array(): void
    {
        $page = $this->sut->project([
            'postId'      => 1,
            'slug'        => 'empty',
            'title'       => 'Empty page title',
            'description' => 'This page has no content blocks at all but still must serialize.',
            'blocks'      => [],
        ]);

        $this->assertSame([], $page['sections']);
    }

    public function test_top_level_null_blockName_blocks_are_skipped(): void
    {
        $page = $this->sut->project([
            'postId'      => 7,
            'slug'        => 'mixed',
            'title'       => 'Mixed top-level blocks',
            'description' => 'Whitespace-only blocks between real ones should not produce sections.',
            'blocks'      => [
                ['blockName' => null, 'attrs' => [], 'innerBlocks' => []],
                ['blockName' => 'core/paragraph', 'attrs' => ['content' => 'A'], 'innerBlocks' => []],
                ['blockName' => null, 'attrs' => [], 'innerBlocks' => []],
                ['blockName' => 'core/paragraph', 'attrs' => ['content' => 'B'], 'innerBlocks' => []],
                ['blockName' => null, 'attrs' => [], 'innerBlocks' => []],
            ],
        ]);

        $this->assertCount(2, $page['sections']);
        $this->assertSame('7-0', $page['sections'][0]['id']);
        $this->assertSame('A', $page['sections'][0]['data']['content']);
        $this->assertSame('7-1', $page['sections'][1]['id']);
        $this->assertSame('B', $page['sections'][1]['data']['content']);
    }

    public function test_id_pattern_matches_page_schema(): void
    {
        $page = $this->sut->project([
            'postId'      => 1,
            'slug'        => 'parent/child-with-dashes',
            'title'       => 'Nested page title',
            'description' => 'A page slug with a slash and dashes still maps to the schema-id pattern after sanitisation by the Router.',
            'blocks'      => [],
        ]);

        // Schema: id pattern disallows slashes, slug pattern allows them.
        // Slashes in the slug are flattened to dashes when deriving the id.
        $this->assertSame('parent-child-with-dashes-page', $page['id']);
        $this->assertSame('parent/child-with-dashes', $page['slug']);
        $this->assertMatchesRegularExpression('/^[a-z0-9-]+-page$/', $page['id']);
    }

    public function test_existing_olonId_in_top_level_attrs_is_preserved(): void
    {
        $page = $this->sut->project([
            'postId'      => 1,
            'slug'        => 'sticky',
            'title'       => 'Sticky ids page',
            'description' => 'Top-level blocks that already carry olonId must keep it after projection.',
            'blocks'      => [
                ['blockName' => 'core/paragraph', 'attrs' => ['olonId' => 'persisted-uuid', 'content' => 'x'], 'innerBlocks' => []],
            ],
        ]);

        $this->assertSame('persisted-uuid', $page['sections'][0]['id']);
    }
}
