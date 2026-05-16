<?php
declare(strict_types=1);

namespace Olon\WP\OlonJs\Tests\Integration;

use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;
use Yoast\WPTestUtils\WPIntegration\TestCase;

/**
 * Shared base for integration tests. Provides:
 *  - rewrite setup
 *  - dispatch helper (go_to + template_redirect + capture)
 *  - assertConformsToPageSchema() against the committed Page schema
 */
abstract class IntegrationTestCase extends TestCase
{
    private static ?Validator $validator = null;
    private static ?\stdClass $pageSchema = null;

    protected function setUp(): void
    {
        parent::setUp();
        global $wp_rewrite;
        $wp_rewrite->set_permalink_structure('/%postname%/');
        flush_rewrite_rules(false);
    }

    protected function dispatch(string $path): string
    {
        $this->go_to(\home_url($path));
        \ob_start();
        \do_action('template_redirect');
        return (string) \ob_get_clean();
    }

    /**
     * @param array<string,mixed> $body
     */
    protected function assertConformsToPageSchema(array $body): void
    {
        $result = $this->validator()->validate(
            \json_decode((string) \json_encode($body, JSON_THROW_ON_ERROR), false, flags: JSON_THROW_ON_ERROR),
            self::pageSchema(),
        );

        if ($result->isValid()) {
            $this->addToAssertionCount(1);
            return;
        }

        $errors = (new ErrorFormatter())->format($result->error(), false);
        $this->fail("Page schema validation failed:\n" . \print_r($errors, true));
    }

    private function validator(): Validator
    {
        if (self::$validator === null) {
            self::$validator = new Validator();
        }
        return self::$validator;
    }

    private static function pageSchema(): \stdClass
    {
        if (self::$pageSchema === null) {
            $path = dirname(__DIR__) . '/fixtures/schemas/page.schema.json';
            self::$pageSchema = \json_decode((string) \file_get_contents($path), false, flags: JSON_THROW_ON_ERROR);
        }
        return self::$pageSchema;
    }
}
