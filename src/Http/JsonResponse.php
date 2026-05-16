<?php
declare(strict_types=1);

namespace Olon\WP\OlonJs\Http;

final class JsonResponse
{
    public const SCHEMA_URL = 'https://olon.js.org/schemas/v1/page.schema.json';

    /**
     * @param array<string,mixed> $body
     */
    public function send(int $status, array $body): void
    {
        if (!headers_sent()) {
            status_header($status);
            header('Content-Type: application/json; charset=utf-8');
            header('X-Olon-Schema: ' . self::SCHEMA_URL);
        }

        echo wp_json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if (!defined('OLON_TESTING')) {
            exit;
        }
    }
}
