<?php

declare(strict_types=1);

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- This standalone CLI test owns its process-local variables; declarations remain subject to prefix checks.

$root = dirname( __DIR__ );
$tmp  = sys_get_temp_dir() . '/ran-coding-standards-consumer-' . bin2hex( random_bytes( 6 ) );

// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Create only the private disposable directory used by this CLI fixture.
if ( ! mkdir( $tmp ) && ! is_dir( $tmp ) ) {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Write process-local CLI diagnostics to STDOUT or STDERR without a WordPress runtime.
	fwrite( STDERR, "Could not create the consumer fixture directory.\n" );
	exit( 1 );
}

register_shutdown_function(
	static function () use ( $tmp ): void {
		if ( ! is_dir( $tmp ) ) {
			return;
		}

		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $tmp, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::CHILD_FIRST
		);

		foreach ( $iterator as $item ) {
			if ( $item->isLink() || ! $item->isDir() ) {
				// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink -- Best-effort shutdown cleanup must not mask the original fixture result. Remove only a disposable file created by this fixture.
				@unlink( $item->getPathname() );
			} else {
				// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Best-effort shutdown cleanup must not mask the original fixture result. Remove only the disposable directory created for this CLI fixture.
				@rmdir( $item->getPathname() );
			}
		}

		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Best-effort shutdown cleanup must not mask the original fixture result. Remove only the disposable directory created for this CLI fixture.
		@rmdir( $tmp );
	}
);

$composer_config = array(
	'name'         => 'ran/consumer-install-fixture',
	'type'         => 'project',
	'require-dev'  => array(
		'ran/coding-standards' => '1.0.0',
	),
	'repositories' => array(
		array(
			'type'    => 'path',
			'url'     => str_replace( '\\', '/', $root ),
			'options' => array(
				'symlink'  => false,
				'versions' => array(
					'ran/coding-standards' => '1.0.0',
				),
			),
		),
	),
	'config'       => array(
		'allow-plugins' => array(
			'dealerdirect/phpcodesniffer-composer-installer' => true,
		),
	),
);

// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Encode machine-readable fixture data or CLI diagnostics without WordPress helpers.
$json = json_encode( $composer_config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Write exact disposable fixture or configuration bytes in this CLI-only test.
if ( false === $json || false === file_put_contents( $tmp . '/composer.json', $json . "\n" ) ) {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Write process-local CLI diagnostics to STDOUT or STDERR without a WordPress runtime.
	fwrite( STDERR, "Could not write the consumer Composer fixture.\n" );
	exit( 1 );
}

$composer = getenv( 'COMPOSER_BINARY' );
if ( false === $composer || '' === $composer ) {
	$composer = 'composer';
}

$composer_command = 'composer' === $composer ? $composer : escapeshellarg( $composer );
$output           = array();
$command          = 'cd ' . escapeshellarg( $tmp )
	. ' && ' . $composer_command
	. ' install --no-interaction --prefer-dist --no-progress 2>&1';
// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec -- Run the selected local checker or isolated Composer consumer proof as a CLI subprocess.
exec( $command, $output, $command_status );

if ( 0 !== $command_status ) {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Write process-local CLI diagnostics to STDOUT or STDERR without a WordPress runtime.
	fwrite( STDERR, "A stable consumer root could not install ran/coding-standards:\n" . implode( "\n", $output ) . "\n" );
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- This integer is a process exit status, not rendered output.
	exit( $command_status );
}

$installed_package = $tmp . '/vendor/ran/coding-standards/composer.json';
if ( ! is_file( $installed_package ) ) {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Write process-local CLI diagnostics to STDOUT or STDERR without a WordPress runtime.
	fwrite( STDERR, "The consumer fixture did not install ran/coding-standards.\n" );
	exit( 1 );
}

// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read local repository or fixture bytes without requiring a WordPress runtime.
$installed = file_get_contents( $tmp . '/composer.lock' );
if ( false === $installed ) {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Write process-local CLI diagnostics to STDOUT or STDERR without a WordPress runtime.
	fwrite( STDERR, "Could not read the consumer fixture lockfile.\n" );
	exit( 1 );
}

foreach ( array( 'phpcompatibility/php-compatibility', 'phpcompatibility/phpcompatibility-paragonie', 'phpcompatibility/phpcompatibility-wp', 'phpstan/phpstan' ) as $development_package ) {
	if ( false !== strpos( $installed, '"name": "' . $development_package . '"' ) ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Write process-local CLI diagnostics to STDOUT or STDERR without a WordPress runtime.
		fwrite( STDERR, sprintf( "Stable consumers unexpectedly install development package %s.\n", $development_package ) );
		exit( 1 );
	}
}

// Prove the opt-in sniff is registered and runnable from the installed package,
// even when the consumer does not install this repository's dev dependencies.
$owned_fixture = $tmp . '/owned-methods.php';
// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Write exact disposable fixture or configuration bytes in this CLI-only test.
if ( false === file_put_contents( $owned_fixture, '<?php class OwnedExample extends \\RuntimeException { private function ownedCamelCase() {} }' ) ) {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Write process-local CLI diagnostics to STDOUT or STDERR without a WordPress runtime.
	fwrite( STDERR, "Could not write the installed owned-method fixture.\n" );
	exit( 1 );
}
$output = array();
// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec -- Run the selected local checker or isolated Composer consumer proof as a CLI subprocess.
exec(
	escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $tmp . '/vendor/bin/phpcs' )
	. ' -n --standard=RANOwnedMethods --report=json ' . escapeshellarg( $owned_fixture ) . ' 2>&1',
	$output,
	$command_status
);
$owned_report   = json_decode( implode( "\n", $output ), true );
$owned_messages = array();
foreach ( $owned_report['files'] ?? array() as $owned_file ) {
	$owned_messages = array_merge( $owned_messages, $owned_file['messages'] );
}
if ( 1 !== $command_status || 1 !== count( $owned_messages )
	|| 'RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase' !== $owned_messages[0]['source']
) {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Write process-local CLI diagnostics to STDOUT or STDERR without a WordPress runtime.
	fwrite( STDERR, "Installed owned-method enforcement failed:\n" . implode( "\n", $output ) . "\n" );
	exit( 1 );
}

// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Write process-local CLI diagnostics to STDOUT or STDERR without a WordPress runtime.
fwrite( STDOUT, "A stable consumer root installs and runs the opt-in owned-method check without development-only alpha dependencies.\n" );
