<?php
declare(strict_types=1);

namespace Olon\WP\OlonJs\Tests\Integration;

/**
 * T7: 404 JSON for missing / non-published / wrong-type / malformed-slug requests.
 */
final class NotFoundTest extends IntegrationTestCase
{
    public function test_unknown_slug_returns_json_404(): void
    {
        $this->assertNotFound('/no-such-page.json');
    }

    public function test_draft_page_returns_json_404(): void
    {
        self::factory()->post->create([
            'post_type'    => 'page',
            'post_status'  => 'draft',
            'post_name'    => 'draft-page',
            'post_title'   => 'Draft page',
            'post_content' => '',
        ]);

        $this->assertNotFound('/draft-page.json');
    }

    public function test_private_page_returns_json_404(): void
    {
        self::factory()->post->create([
            'post_type'    => 'page',
            'post_status'  => 'private',
            'post_name'    => 'private-page',
            'post_title'   => 'Private page',
            'post_content' => '',
        ]);

        $this->assertNotFound('/private-page.json');
    }

    public function test_post_post_type_returns_json_404(): void
    {
        self::factory()->post->create([
            'post_type'    => 'post',
            'post_status'  => 'publish',
            'post_name'    => 'a-blog-post',
            'post_title'   => 'A blog post',
            'post_content' => '',
        ]);

        $this->assertNotFound('/a-blog-post.json');
    }

    public function test_slug_with_disallowed_chars_returns_json_404(): void
    {
        $this->assertNotFound('/About.json');
    }

    private function assertNotFound(string $path): void
    {
        $body = $this->dispatch($path);

        $this->assertJson($body);
        $decoded = \json_decode($body, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(['error' => 'not_found'], $decoded);
    }
}
