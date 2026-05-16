<?php
declare(strict_types=1);

$autoload = dirname(__DIR__) . '/vendor/autoload.php';
require_once $autoload;

require_once dirname(__DIR__) . '/vendor/yoast/wp-test-utils/src/WPIntegration/bootstrap-functions.php';

/**
 * Load this plugin before WordPress finishes booting so its hooks register.
 */
\tests_add_filter('muplugins_loaded', static function (): void {
    require dirname(__DIR__) . '/wp-olonjs.php';
});

\Yoast\WPTestUtils\WPIntegration\bootstrap_it();
