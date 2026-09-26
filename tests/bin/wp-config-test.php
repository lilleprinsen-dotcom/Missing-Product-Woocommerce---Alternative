<?php
// wp-config.php for disposable test sites built by tests/bin/setup-env.sh (SQLite, debug log on, WP-Cron off).
define( 'DB_NAME', 'wp' );
define( 'DB_USER', 'wp' );
define( 'DB_PASSWORD', 'wp' );
define( 'DB_HOST', 'localhost' );
define( 'DB_CHARSET', 'utf8mb4' );
define( 'DB_COLLATE', '' );
define( 'DB_ENGINE', 'sqlite' );
define( 'AUTH_KEY', 'test-a' );
define( 'SECURE_AUTH_KEY', 'test-b' );
define( 'LOGGED_IN_KEY', 'test-c' );
define( 'NONCE_KEY', 'test-d' );
define( 'AUTH_SALT', 'test-e' );
define( 'SECURE_AUTH_SALT', 'test-f' );
define( 'LOGGED_IN_SALT', 'test-g' );
define( 'NONCE_SALT', 'test-h' );
$table_prefix = 'wp_';
define( 'WP_DEBUG', true );
define( 'WP_DEBUG_LOG', true );
define( 'WP_DEBUG_DISPLAY', false );
define( 'DISABLE_WP_CRON', true );
define( 'WP_HTTP_BLOCK_EXTERNAL', true );
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}
require_once ABSPATH . 'wp-settings.php';
