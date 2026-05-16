<?php
declare(strict_types=1);

namespace Olon\WP\OlonJs\Rewrite;

use Olon\WP\OlonJs\Http\JsonResponse;
use Olon\WP\OlonJs\Hydration\BlockHydrator;
use Olon\WP\OlonJs\Projection\PageProjector;
use WP_Post;

final class Router
{
    private const SLUG_PATTERN = '#^[a-z0-9\-/]+$#';

    public function __construct(
        private readonly JsonResponse $response,
        private readonly PageProjector $projector,
        private readonly BlockHydrator $hydrator,
    ) {
    }

    public function dispatch(): void
    {
        $requested = get_query_var(JsonEndpoint::QUERY_VAR);
        if (!is_string($requested) || $requested === '') {
            return;
        }

        $slug = trim($requested, '/');
        if ($slug === '' || preg_match(self::SLUG_PATTERN, $slug) !== 1) {
            $this->notFound();
            return;
        }

        $post = get_page_by_path($slug, OBJECT, 'page');
        if (!$post instanceof WP_Post || $post->post_status !== 'publish') {
            $this->notFound();
            return;
        }

        $blocks = parse_blocks($post->post_content);
        $hydrated = array_map(fn (array $b) => $this->hydrator->hydrate($b), array_values(array_filter($blocks, 'is_array')));

        $page = $this->projector->project([
            'postId'      => $post->ID,
            'slug'        => $slug,
            'title'       => get_the_title($post),
            'description' => get_the_excerpt($post),
            'blocks'      => $hydrated,
        ]);

        $this->response->send(200, $page);
    }

    private function notFound(): void
    {
        $this->response->send(404, ['error' => 'not_found']);
    }
}
