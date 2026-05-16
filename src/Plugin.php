<?php
declare(strict_types=1);

namespace Olon\WP\OlonJs;

use Olon\WP\OlonJs\Http\JsonResponse;
use Olon\WP\OlonJs\Hydration\BlockHydrator;
use Olon\WP\OlonJs\Hydration\BlockTypeSchemaProvider;
use Olon\WP\OlonJs\Hydration\RichTextToMarkdown;
use Olon\WP\OlonJs\Hydration\SchemaSource;
use Olon\WP\OlonJs\Projection\BlockToSection;
use Olon\WP\OlonJs\Projection\IdAssigner;
use Olon\WP\OlonJs\Projection\PageProjector;
use Olon\WP\OlonJs\Rewrite\JsonEndpoint;
use Olon\WP\OlonJs\Rewrite\Router;
use WP_Post;

final class Plugin
{
    private const SAVE_HOOK_PRIORITY = 10;

    private static ?self $instance = null;

    public readonly IdAssigner $idAssigner;
    public readonly JsonEndpoint $endpoint;
    public readonly Router $router;

    private function __construct(public readonly string $pluginFile)
    {
        $this->idAssigner = new IdAssigner();
        $this->endpoint   = new JsonEndpoint();

        $schemaProvider = new BlockTypeSchemaProvider();
        $hydrator       = new BlockHydrator(
            new SchemaSource(new RichTextToMarkdown()),
            $schemaProvider->asResolver(),
        );

        $this->router = new Router(
            new JsonResponse(),
            new PageProjector(new BlockToSection($this->idAssigner)),
            $hydrator,
        );
    }

    public static function boot(string $pluginFile): self
    {
        if (self::$instance === null) {
            self::$instance = new self($pluginFile);
            self::$instance->register();
        }
        return self::$instance;
    }

    public function handleSavePost(int $postId, WP_Post $post): void
    {
        if (wp_is_post_autosave($postId) || wp_is_post_revision($postId)) {
            return;
        }
        if ($post->post_type !== 'page' || $post->post_status === 'auto-draft') {
            return;
        }

        $updated = $this->idAssigner->assignMissingIds($post->post_content);
        if ($updated === $post->post_content) {
            return;
        }

        remove_action('save_post_page', [$this, 'handleSavePost'], self::SAVE_HOOK_PRIORITY);
        wp_update_post([
            'ID'           => $postId,
            'post_content' => $updated,
        ]);
        add_action('save_post_page', [$this, 'handleSavePost'], self::SAVE_HOOK_PRIORITY, 2);
    }

    private function register(): void
    {
        add_action('init', [$this->endpoint, 'register']);
        add_action('template_redirect', [$this->router, 'dispatch']);
        add_action('save_post_page', [$this, 'handleSavePost'], self::SAVE_HOOK_PRIORITY, 2);

        register_activation_hook($this->pluginFile, [$this->endpoint, 'activate']);
        register_deactivation_hook($this->pluginFile, [$this->endpoint, 'deactivate']);
    }
}
