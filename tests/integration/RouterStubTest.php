<?php
declare(strict_types=1);

namespace Olon\WP\OlonJs\Tests\Integration;

/**
 * Rewrite plumbing checks. The original T2 stub assertion is gone (T5 replaced
 * the stub with the real PageProjector output and T7 added 404 JSON), but the
 * rewrite-rule + query-var wiring is still worth exercising in isolation.
 */
final class RouterStubTest extends IntegrationTestCase
{
    public function test_nested_slug_is_captured_verbatim(): void
    {
        $this->go_to(\home_url('/parent/child.json'));
        $this->assertSame('parent/child', \get_query_var('olon_page'));
    }

    public function test_non_json_url_does_not_set_query_var(): void
    {
        $this->go_to(\home_url('/about'));
        $this->assertEmpty(\get_query_var('olon_page'));
    }
}
