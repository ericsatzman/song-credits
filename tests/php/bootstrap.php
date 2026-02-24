<?php
/**
 * Bootstrap for WordPress integration tests.
 */

$_tests_dir = getenv( 'WP_TESTS_DIR' );
if ( ! $_tests_dir ) {
    $_tests_dir = '/tmp/wordpress-tests-lib';
}

require_once $_tests_dir . '/includes/functions.php';

tests_add_filter(
    'muplugins_loaded',
    static function() {
        require dirname( __DIR__, 2 ) . '/song-credits.php';
    }
);

require $_tests_dir . '/includes/bootstrap.php';
