<?php
declare(strict_types=1);

namespace Olon\WP\OlonJs\Tests\Integration;

/**
 * T5: real published pages return a full OlonJS Page document.
 * T8 retrofit: every 200 body is validated against the Page schema.
 */
final class EndpointHappyPathTest extends IntegrationTestCase
{
    public function test_published_page_returns_full_page_document(): void
    {
        self::factory()->post->create([
            'post_type'    => 'page',
            'post_status'  => 'publish',
            'post_name'    => 'about',
            'post_title'   => 'About the OlonJS company',
            'post_excerpt' => 'A description long enough to satisfy the Page schema requirement of fifty characters.',
            'post_content' => "<!-- wp:heading -->\n<h2>Hello</h2>\n<!-- /wp:heading -->\n\n<!-- wp:paragraph -->\n<p>Body</p>\n<!-- /wp:paragraph -->",
        ]);

        $body = $this->dispatch('/about.json');
        $page = \json_decode($body, true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('about-page', $page['id']);
        $this->assertSame('about', $page['slug']);
        $this->assertSame('About the OlonJS company', $page['meta']['title']);
        $this->assertStringContainsString('long enough to satisfy', $page['meta']['description']);
        $this->assertCount(2, $page['sections']);
        $this->assertSame('core/heading', $page['sections'][0]['type']);
        $this->assertSame('core/paragraph', $page['sections'][1]['type']);

        $this->assertConformsToPageSchema($page);
    }

    public function test_nested_page_path_resolves_and_uses_full_path_as_slug(): void
    {
        $parentId = self::factory()->post->create([
            'post_type'   => 'page',
            'post_status' => 'publish',
            'post_name'   => 'parent',
            'post_title'  => 'Parent page',
        ]);
        self::factory()->post->create([
            'post_type'    => 'page',
            'post_status'  => 'publish',
            'post_name'    => 'child',
            'post_parent'  => $parentId,
            'post_title'   => 'Child page title here',
            'post_excerpt' => 'A description for the child page that is over fifty characters long for sure.',
            'post_content' => "<!-- wp:paragraph -->\n<p>Child</p>\n<!-- /wp:paragraph -->",
        ]);

        $body = $this->dispatch('/parent/child.json');
        $page = \json_decode($body, true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('parent/child', $page['slug']);
        $this->assertCount(1, $page['sections']);
        $this->assertSame('parent-child-page', $page['id']);
        $this->assertConformsToPageSchema($page);
    }

    public function test_excerpt_fallback_is_used_when_post_excerpt_is_empty(): void
    {
        self::factory()->post->create([
            'post_type'    => 'page',
            'post_status'  => 'publish',
            'post_name'    => 'no-excerpt',
            'post_title'   => 'Page without an excerpt set',
            'post_excerpt' => '',
            'post_content' => "<!-- wp:paragraph -->\n<p>This long body will be used as the excerpt fallback by WordPress when no explicit excerpt has been provided by the author.</p>\n<!-- /wp:paragraph -->",
        ]);

        $body = $this->dispatch('/no-excerpt.json');
        $page = \json_decode($body, true, flags: JSON_THROW_ON_ERROR);

        $this->assertNotSame('', $page['meta']['description']);
        $this->assertStringContainsString('This long body', $page['meta']['description']);
        $this->assertConformsToPageSchema($page);
    }
}
