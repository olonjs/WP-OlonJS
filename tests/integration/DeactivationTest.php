<?php
declare(strict_types=1);

namespace Olon\WP\OlonJs\Tests\Integration;

use Olon\WP\OlonJs\Plugin;
use Olon\WP\OlonJs\Rewrite\JsonEndpoint;

/**
 * T9: after deactivation, the /$slug.json rewrite rule must be gone on the
 * next request. We simulate this by invoking deactivate() and verifying the
 * rewrite_rules option is empty (which forces WP to regenerate without our
 * hook on the next request, since the plugin would not be loaded).
 */
final class DeactivationTest extends IntegrationTestCase
{
    public function test_deactivation_drops_the_rewrite_rules_option(): void
    {
        // Trigger a flush so the option is populated with our rule attached.
        Plugin::boot(__DIR__ . '/wp-olonjs.php');
        flush_rewrite_rules(false);

        $rulesBefore = (array) get_option('rewrite_rules', []);
        $this->assertNotSame([], $rulesBefore, 'Sanity: rewrite_rules should be populated before deactivation.');

        $endpoint = new JsonEndpoint();
        $endpoint->deactivate();

        $this->assertFalse(get_option('rewrite_rules'), 'rewrite_rules option must be deleted on deactivation.');
    }
}
