<?php
/**
 * PHPUnit bootstrap for Masthead plugin tests.
 */

$_tests_dir = getenv( 'WP_TESTS_DIR' );

if ( ! $_tests_dir ) {
	$_tests_dir = '/tmp/wordpress-tests-lib';
}

require_once $_tests_dir . '/includes/functions.php';

tests_add_filter( 'muplugins_loaded', function () {
	require_once dirname( __DIR__ ) . '/masthead.php';
} );

require_once $_tests_dir . '/includes/bootstrap.php';
