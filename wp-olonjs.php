<?php
/**
 * Plugin Name: WP-OlonJS
 * Plugin URI:  https://olon.design/wp-olonjs
 * Description: Exposes every published page at /$slug.json as an OlonJS Page document.
 * Version:     0.1.0
 * Requires PHP: 8.1
 * Requires at least: 6.4
 * Author:      Olon
 * License:     GPL-2.0-or-later
 * Text Domain: wp-olonjs
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

$autoload = __DIR__ . '/vendor/autoload.php';
if (!is_file($autoload)) {
    add_action('admin_notices', static function (): void {
        echo '<div class="notice notice-error"><p>'
            . esc_html__('WP-OlonJS: run "composer install" inside the plugin directory.', 'wp-olonjs')
            . '</p></div>';
    });
    return;
}

require_once $autoload;

\Olon\WP\OlonJs\Plugin::boot(__FILE__);
