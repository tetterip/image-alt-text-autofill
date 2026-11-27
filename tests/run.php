<?php
/**
 * Zero-dependency test runner: php tests/run.php (from the plugin root).
 * Loads only the pure classes, no WordPress needed.
 *
 * @package Tet_Smart_Alt_Text
 */

define( 'ABSPATH', __DIR__ . '/' );

require dirname( __DIR__ ) . '/includes/class-tet-alt-logic.php';

$tet_tests = array(
	'pass' => 0,
	'fail' => 0,
);

function test( string $name, callable $fn ): void {
	global $tet_tests;
	try {
		$fn();
		$tet_tests['pass']++;
	} catch ( Throwable $e ) {
		$tet_tests['fail']++;
		echo "FAIL  {$name}: {$e->getMessage()}\n";
	}
}

function eq( $expected, $actual, string $msg = '' ): void {
	if ( $expected !== $actual ) {
		throw new Exception( ( $msg ? $msg . ': ' : '' ) . 'expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) );
	}
}

foreach ( glob( __DIR__ . '/test-*.php' ) as $file ) {
	require $file;
}

printf( "%d passed, %d failed\n", $tet_tests['pass'], $tet_tests['fail'] );
exit( $tet_tests['fail'] ? 1 : 0 );
