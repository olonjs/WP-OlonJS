<?php
declare(strict_types=1);

namespace Olon\WP\OlonJs\Rewrite;

final class JsonEndpoint
{
    public const QUERY_VAR = 'olon_page';
    public const RULE      = '^(.+)\.json/?$';

    public function register(): void
    {
        add_rewrite_rule(self::RULE, 'index.php?' . self::QUERY_VAR . '=$matches[1]', 'top');
        add_rewrite_tag('%' . self::QUERY_VAR . '%', '([^&]+)');
    }

    public function activate(): void
    {
        $this->register();
        flush_rewrite_rules(false);
    }

    public function deactivate(): void
    {
        /*
         * Deactivation runs in the same request where the plugin is still
         * loaded, so a normal flush_rewrite_rules() would regenerate the rule
         * array WITH our rule still attached. Dropping the option forces WP to
         * rebuild on the next request — at which point the plugin is no longer
         * active, so our rule is gone.
         */
        delete_option('rewrite_rules');
    }
}
