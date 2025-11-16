<?php
/**
 * WP-CLI Runner Script
 *
 * This script bootstraps WordPress and executes wp-cli search-replace programmatically.
 * Designed to be called from within the movepress PHAR on remote servers.
 *
 * Usage: php phar://path/to/movepress.phar/src/wp-cli-runner.php <wordpress-path> search-replace <old> <new> [--path=...] [--skip-columns=...] [--quiet]
 */

if ($argc < 4) {
    fwrite(STDERR, "Usage: php wp-cli-runner.php <wordpress-path> search-replace <old> <new> [options...]\n");
    exit(1);
}

$wordpress_path = rtrim($argv[1], '/');
$command = $argv[2];

// Validate WordPress path
if (!file_exists($wordpress_path . '/wp-load.php')) {
    fwrite(STDERR, "Error: WordPress not found at: {$wordpress_path}\n");
    exit(1);
}

// Only support search-replace for now
if ($command !== 'search-replace') {
    fwrite(STDERR, "Error: Only 'search-replace' command is supported\n");
    exit(1);
}

// Bootstrap WordPress
define('WP_USE_THEMES', false);
$_SERVER['HTTP_HOST'] = 'localhost'; // Prevent WordPress from trying to redirect
require_once $wordpress_path . '/wp-load.php';

// Detect if we're running from PHAR
$phar_path = Phar::running(false);
if (!empty($phar_path)) {
    // Running from PHAR
    $vendor_path = 'phar://' . $phar_path . '/vendor';
} else {
    // Running in dev mode
    $vendor_path = dirname(__DIR__) . '/vendor';
}

// Bootstrap minimal WP-CLI environment
define('WP_CLI_ROOT', $vendor_path . '/wp-cli/wp-cli/php');
define('WP_CLI', true);
define('WP_CLI_VERSION', '2.10.0');

// Load WP-CLI autoloader and core classes
require_once $vendor_path . '/autoload.php';

// Parse command arguments (old, new, and options)
$old = $argv[3];
$new = $argv[4];
$options = array_slice($argv, 5);

// Parse options into associative array
$assoc_args = [];
foreach ($options as $option) {
    if (strpos($option, '--') === 0) {
        $parts = explode('=', substr($option, 2), 2);
        if (count($parts) === 2) {
            $assoc_args[$parts[0]] = $parts[1];
        } else {
            $assoc_args[$parts[0]] = true;
        }
    }
}

// Execute search-replace directly without full WP-CLI bootstrap
try {
    $search_replace = new Search_Replace_Command();
    $search_replace->__invoke([$old, $new], $assoc_args);
    exit(0);
} catch (Exception $e) {
    fwrite(STDERR, 'Error: ' . $e->getMessage() . "\n");
    exit(1);
}
