<?php

declare(strict_types=1);

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- This standalone CLI test owns its process-local variables; declarations remain subject to prefix checks.

$root  = dirname( __DIR__ );
$phpcs = $root . '/vendor/bin/phpcs';

if ( ! is_file( $phpcs ) ) {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Write process-local CLI diagnostics to STDOUT or STDERR without a WordPress runtime.
	fwrite( STDERR, "Missing vendor/bin/phpcs. Run composer install first.\n" );
	exit( 1 );
}

$rulesets = array(
	'RAN'                 => $root . '/RAN/ruleset.xml',
	'RANWordPress'        => $root . '/RANWordPress/ruleset.xml',
	'RANWordPressPlugin'  => $root . '/RANWordPressPlugin/ruleset.xml',
	'RANWordPressLibrary' => $root . '/RANWordPressLibrary/ruleset.xml',
	'RANOwnedMethods'     => $root . '/RANOwnedMethods/ruleset.xml',
);

$required_rules = array(
	'RAN'                 => array( 'Generic.PHP.Syntax' ),
	'RANWordPress'        => array( 'RAN', 'PHPCompatibilityWP', 'WordPress-Extra' ),
	'RANWordPressPlugin'  => array( 'RANWordPress' ),
	'RANWordPressLibrary' => array( 'RANWordPress' ),
	'RANOwnedMethods'     => array( 'RANOwnedMethods.NamingConventions.ValidMethodName' ),
);

$forbidden_setting_patterns = array(
	'PHPCompatibility testVersion'        => '/^testversion$/',
	'minimum supported WordPress version' => '/^minimum_(?:supported_)?wp_version$/',
	'prefix property or value'            => '/prefix(?:es)?/',
	'text-domain property or value'       => '/text_?domains?/',
	'namespace property'                  => '/namespace/',
	'product-specific support range'      => '/^(?:(?:php|wp|wordpress)_)?support(?:ed)?_(?:range|versions?)$/',
);

$repository_namespace_pattern = '/^(?:RAN|RocketsAreNostalgic)\\\\[A-Za-z_][A-Za-z0-9_\\\\]*$/i';
$inspected_rulesets           = array();

$find_selector_violation = static function ( $document, string $standard ): ?string {
	$conditional_nodes = $document->xpath( '//*[@phpcs-only or @phpcbf-only]' );
	if ( false === $conditional_nodes || array() !== $conditional_nodes ) {
		return sprintf( '%s conditionally changes checker or fixer rules.', $standard );
	}

	$selector_nodes = $document->xpath( '//file | //include-pattern | //exclude-pattern' );
	if ( ! is_array( $selector_nodes ) ) {
		return sprintf( 'Could not inspect file selectors in %s.', $standard );
	}

	foreach ( $selector_nodes as $selector_node ) {
		if ( '' !== trim( (string) $selector_node ) ) {
			return sprintf(
				'%s embeds a consumer-specific <%s> file selector.',
				$standard,
				$selector_node->getName()
			);
		}
	}

	$selector_arg_nodes = $document->xpath( '//arg[@name]' );
	if ( ! is_array( $selector_arg_nodes ) ) {
		return sprintf( 'Could not inspect selector arguments in %s.', $standard );
	}

	$selector_arg_names = array( 'ignore', 'extensions', 'file-list', 'filter', 'stdin-path' );
	foreach ( $selector_arg_nodes as $selector_arg_node ) {
		$selector_arg_name = strtolower( (string) $selector_arg_node['name'] );
		if ( in_array( $selector_arg_name, $selector_arg_names, true ) ) {
			return sprintf(
				'%s embeds a consumer-specific selector argument --%s.',
				$standard,
				$selector_arg_name
			);
		}
	}

	return null;
};

$inspect_ruleset = static function ( string $standard ) use ( &$inspect_ruleset, &$inspected_rulesets, $root, $rulesets, $required_rules, $forbidden_setting_patterns, $repository_namespace_pattern, $find_selector_violation ): void {
	$ruleset = $rulesets[ $standard ] ?? $standard;
	if ( isset( $inspected_rulesets[ $ruleset ] ) ) {
		return;
	}

	$document = simplexml_load_file( $ruleset, 'SimpleXMLElement', LIBXML_NONET );
	if ( false === $document ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Write process-local CLI diagnostics to STDOUT or STDERR without a WordPress runtime.
		fwrite( STDERR, sprintf( "Could not parse %s ruleset.\n", $standard ) );
		exit( 1 );
	}

	$rule_nodes = $document->xpath( '/ruleset/rule[@ref]' );
	if ( ! is_array( $rule_nodes ) ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Write process-local CLI diagnostics to STDOUT or STDERR without a WordPress runtime.
		fwrite( STDERR, sprintf( "Could not inspect active rules in %s.\n", $standard ) );
		exit( 1 );
	}

	$active_rules = array();
	foreach ( $rule_nodes as $rule_node ) {
		$active_rules[] = (string) $rule_node['ref'];
	}

	foreach ( $required_rules[ $standard ] ?? array() as $required_rule ) {
		if ( ! in_array( $required_rule, $active_rules, true ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Write process-local CLI diagnostics to STDOUT or STDERR without a WordPress runtime.
			fwrite( STDERR, sprintf( "%s is missing active rule %s.\n", $standard, $required_rule ) );
			exit( 1 );
		}
	}

	$setting_nodes = $document->xpath( '//config[@name] | //property[@name]' );
	if ( ! is_array( $setting_nodes ) ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Write process-local CLI diagnostics to STDOUT or STDERR without a WordPress runtime.
		fwrite( STDERR, sprintf( "Could not inspect settings in %s.\n", $standard ) );
		exit( 1 );
	}

	foreach ( $setting_nodes as $setting_node ) {
		$setting_name = strtolower( str_replace( '-', '_', (string) $setting_node['name'] ) );

		foreach ( $forbidden_setting_patterns as $setting => $pattern ) {
			if ( 1 === preg_match( $pattern, $setting_name ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Write process-local CLI diagnostics to STDOUT or STDERR without a WordPress runtime.
				fwrite( STDERR, sprintf( "%s embeds consumer-specific %s configuration.\n", $standard, $setting ) );
				exit( 1 );
			}
		}
	}

	$value_nodes = $document->xpath( '//config[@value] | //property[@value] | //element[@value]' );
	if ( ! is_array( $value_nodes ) ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Write process-local CLI diagnostics to STDOUT or STDERR without a WordPress runtime.
		fwrite( STDERR, sprintf( "Could not inspect values in %s.\n", $standard ) );
		exit( 1 );
	}

	foreach ( $value_nodes as $value_node ) {
		if ( 1 === preg_match( $repository_namespace_pattern, (string) $value_node['value'] ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Write process-local CLI diagnostics to STDOUT or STDERR without a WordPress runtime.
			fwrite( STDERR, sprintf( "%s embeds a repository-specific namespace identity.\n", $standard ) );
			exit( 1 );
		}
	}

	$selector_violation = $find_selector_violation( $document, $standard );
	if ( null !== $selector_violation ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Write process-local CLI diagnostics to STDOUT or STDERR without a WordPress runtime.
		fwrite( STDERR, $selector_violation . "\n" );
		exit( 1 );
	}

	$inspected_rulesets[ $ruleset ] = true;

	foreach ( $active_rules as $active_rule ) {
		if ( isset( $rulesets[ $active_rule ] ) ) {
			$inspect_ruleset( $active_rule );
			continue;
		}

		// Inspect local file/directory references as well as public standard names.
		foreach ( array( dirname( $ruleset ) . '/' . $active_rule, $root . '/' . $active_rule, $active_rule ) as $candidate ) {
			if ( is_dir( $candidate ) ) {
				$candidate .= '/ruleset.xml';
			}
			$resolved = realpath( $candidate );
			if ( false !== $resolved && is_file( $resolved )
				&& 0 === strpos( $resolved, $root . '/' )
				&& 0 !== strpos( $resolved, $root . '/vendor/' )
				&& 'xml' === pathinfo( $resolved, PATHINFO_EXTENSION )
			) {
				$inspect_ruleset( $resolved );
				break;
			}
		}
	}
};

foreach ( array_keys( $rulesets ) as $standard ) {
	$output  = array();
	$command = escapeshellarg( $phpcs ) . ' -e --standard=' . escapeshellarg( $standard ) . ' 2>&1';
	// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec -- Run the selected local checker or isolated Composer consumer proof as a CLI subprocess.
	exec( $command, $output, $command_status );

	if ( 0 !== $command_status ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Write process-local CLI diagnostics to STDOUT or STDERR without a WordPress runtime.
		fwrite( STDERR, sprintf( "Standard %s failed to resolve:\n%s\n", $standard, implode( "\n", $output ) ) );
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- This integer is a process exit status, not rendered output.
		exit( $command_status );
	}

	$inspect_ruleset( $standard );
}

foreach ( array( 'file', 'include-pattern', 'exclude-pattern' ) as $selector_name ) {
	$selector_fixture = simplexml_load_string(
		sprintf( '<ruleset name="Selector negative"><%1$s>booster-only/</%1$s></ruleset>', $selector_name ),
		'SimpleXMLElement',
		LIBXML_NONET
	);
	if ( false === $selector_fixture ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Write process-local CLI diagnostics to STDOUT or STDERR without a WordPress runtime.
		fwrite( STDERR, sprintf( "Could not parse the %s negative selector fixture.\n", $selector_name ) );
		exit( 1 );
	}

	if ( null === $find_selector_violation( $selector_fixture, 'selector negative fixture' ) ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Write process-local CLI diagnostics to STDOUT or STDERR without a WordPress runtime.
		fwrite( STDERR, sprintf( "The package boundary failed to reject an active <%s> selector.\n", $selector_name ) );
		exit( 1 );
	}
}

$selector_arg_fixture = simplexml_load_string(
	'<ruleset name="Selector argument negative"><arg name="ignore" value="*/booster-only/*"/></ruleset>',
	'SimpleXMLElement',
	LIBXML_NONET
);
if ( false === $selector_arg_fixture ) {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Write process-local CLI diagnostics to STDOUT or STDERR without a WordPress runtime.
	fwrite( STDERR, "Could not parse the negative selector-argument fixture.\n" );
	exit( 1 );
}

if ( null === $find_selector_violation( $selector_arg_fixture, 'selector argument negative fixture' ) ) {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Write process-local CLI diagnostics to STDOUT or STDERR without a WordPress runtime.
	fwrite( STDERR, "The package boundary failed to reject an active selector-bearing <arg>.\n" );
	exit( 1 );
}

$tmp = sys_get_temp_dir() . '/ran-coding-standards-' . bin2hex( random_bytes( 6 ) );
// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Create only the private disposable directory used by this CLI fixture.
if ( ! mkdir( $tmp ) && ! is_dir( $tmp ) ) {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Write process-local CLI diagnostics to STDOUT or STDERR without a WordPress runtime.
	fwrite( STDERR, "Could not create temporary fixture directory.\n" );
	exit( 1 );
}

$fixtures = array(
	'alignment'      => $tmp . '/alignment.php',
	'valid'          => $tmp . '/valid.php',
	'syntaxInvalid'  => $tmp . '/syntax-invalid.php',
	'wordpress'      => $tmp . '/ran-shared-profile-fixture.php',
	'compatInvalid'  => $tmp . '/compatibility-invalid.php',
	'prefixInvalid'  => $tmp . '/prefix-invalid.php',
	'pluginRuleset'  => $tmp . '/plugin-ruleset.xml',
	'libraryRuleset' => $tmp . '/library-ruleset.xml',
	'ownedMethods'   => $tmp . '/owned-methods.php',
	'exception'      => $tmp . '/exception.php',
	'output'         => $tmp . '/output.php',
	'native'         => $tmp . '/native.php',
);

register_shutdown_function(
	static function () use ( $fixtures, $tmp ): void {
		foreach ( $fixtures as $file ) {
			if ( is_file( $file ) ) {
				// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink -- Best-effort shutdown cleanup must not mask the original fixture result. Remove only a disposable file created by this fixture.
				@unlink( $file );
			}
		}
		if ( is_dir( $tmp ) ) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Best-effort shutdown cleanup must not mask the original fixture result. Remove only the disposable directory created for this CLI fixture.
			@rmdir( $tmp );
		}
	}
);

$fixture_contents = array(
	'exception'     => <<<'PHP'
<?php
namespace RANFixture;

function fail_with_context( $message ) {
	throw new \RuntimeException( $message );
}
PHP
	,
	'output'        => <<<'PHP'
<?php
namespace RANFixture;

function render_failure( \Throwable $failure ) {
	echo $failure->getMessage();
}
PHP
	,
	'native'        => <<<'PHP'
<?php
namespace RANFixture;

function native_operations( $value, $path ) {
	file_get_contents( $path );
	json_encode( $value );
	base64_encode( $value );
}
PHP
	,
	'alignment'     => <<<'PHP'
<?php
/**
 * Alignment fixture.
 *
 * @package RANFixture
 */

namespace RANFixture;

/**
 * Return aligned values.
 *
 * @return array
 */
function alignment_fixture() {
	$short = 1;
	$longer = 2;

	return array(
		'short' => $short,
		'longer' => $longer,
	);
}
PHP
	,
	'valid'         => "<?php\n\ndeclare(strict_types=1);\n\nfunction ran_fixture(): void {}\n",
	'syntaxInvalid' => "<?php\n\nfunction ran_fixture( {\n",
	'wordpress'     => <<<'PHP'
<?php
/**
 * Shared RAN WordPress profile fixture.
 *
 * @package RANFixture
 */

namespace RANFixture;

/**
 * Representative consumer fixture.
 */
final class Example {
	/**
	 * Return a stable fixture value.
	 *
	 * @return bool
	 */
	public function is_ready() {
		return true;
	}
}
PHP
	,
	'compatInvalid' => <<<'PHP'
<?php
/**
 * Compatibility-negative fixture.
 *
 * @package RANFixture
 */

namespace RANFixture;

get_debug_type( true );
PHP
	,
	'prefixInvalid' => <<<'PHP'
<?php
/**
 * Prefix-negative fixture.
 *
 * @package RANFixture
 */

function unrelated_global_fixture() {
	return true;
}
PHP
	,
);

foreach ( $fixture_contents as $fixture => $content ) {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Write exact disposable fixture or configuration bytes in this CLI-only test.
	if ( false === file_put_contents( $fixtures[ $fixture ], $content . "\n" ) ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Write process-local CLI diagnostics to STDOUT or STDERR without a WordPress runtime.
		fwrite( STDERR, sprintf( "Could not write the %s fixture.\n", $fixture ) );
		exit( 1 );
	}
}

$consumer_ruleset_template = <<<'XML'
<?xml version="1.0"?>
<ruleset name="RAN Consumer Fixture">
    <config name="minimum_wp_version" value="7.0"/>
    <config name="testVersion" value="7.4-7.4"/>
    <rule ref="%s"/>
    <rule ref="WordPress.NamingConventions.PrefixAllGlobals">
        <properties>
            <property name="prefixes" type="array">
                <element value="RANFixture"/>
            </property>
        </properties>
    </rule>
    <rule ref="WordPress.WP.I18n">
        <properties>
            <property name="text_domain" type="array">
                <element value="ran-fixture"/>
            </property>
        </properties>
    </rule>
</ruleset>
XML;

foreach ( array(
	'pluginRuleset'  => 'RANWordPressPlugin',
	'libraryRuleset' => 'RANWordPressLibrary',
) as $fixture => $standard ) {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Write exact disposable fixture or configuration bytes in this CLI-only test.
	if ( false === file_put_contents( $fixtures[ $fixture ], sprintf( $consumer_ruleset_template, $standard ) . "\n" ) ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Write process-local CLI diagnostics to STDOUT or STDERR without a WordPress runtime.
		fwrite( STDERR, sprintf( "Could not write the %s consumer ruleset.\n", $standard ) );
		exit( 1 );
	}
}

$run_phpcs = static function ( string $standard, string $fixture, bool $hide_warnings = false ) use ( $phpcs ): array {
	$output  = array();
	$command = escapeshellarg( $phpcs ) . ( $hide_warnings ? ' -n' : '' ) . ' --report=json --standard=' . escapeshellarg( $standard ) . ' ' . escapeshellarg( $fixture ) . ' 2>&1';
	// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec -- Run the selected local checker or isolated Composer consumer proof as a CLI subprocess.
	exec( $command, $output, $command_status );

	$report = json_decode( implode( "\n", $output ), true );
	if ( ! is_array( $report ) || ! isset( $report['files'] ) || ! is_array( $report['files'] ) ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Write process-local CLI diagnostics to STDOUT or STDERR without a WordPress runtime.
		fwrite( STDERR, "PHPCS did not return a valid JSON report:\n" . implode( "\n", $output ) . "\n" );
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- This integer is a process exit status, not rendered output.
		exit( 0 === $command_status ? 1 : $command_status );
	}

	return array( $command_status, $report );
};

$assert_phpcs_passes = static function ( string $standard, string $fixture, string $message ) use ( $run_phpcs ): void {
	list($command_status, $report) = $run_phpcs( $standard, $fixture );
	if ( 0 !== $command_status ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite, WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Write process-local CLI diagnostics to STDOUT or STDERR without a WordPress runtime. Encode machine-readable fixture data or CLI diagnostics without WordPress helpers.
		fwrite( STDERR, $message . ":\n" . json_encode( $report, JSON_PRETTY_PRINT ) . "\n" );
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- This integer is a process exit status, not rendered output.
		exit( $command_status );
	}
};

$assert_phpcs_reports = static function ( string $standard, string $fixture, string $source_pattern, string $message ) use ( $run_phpcs ): void {
	list($command_status, $report) = $run_phpcs( $standard, $fixture );
	if ( 0 === $command_status ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Write process-local CLI diagnostics to STDOUT or STDERR without a WordPress runtime.
		fwrite( STDERR, $message . ".\n" );
		exit( 1 );
	}

	foreach ( $report['files'] as $file ) {
		if ( ! isset( $file['messages'] ) || ! is_array( $file['messages'] ) ) {
			continue;
		}

		foreach ( $file['messages'] as $diagnostic ) {
			if ( isset( $diagnostic['source'] ) && 1 === preg_match( $source_pattern, $diagnostic['source'] ) ) {
				return;
			}
		}
	}

	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite, WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Write process-local CLI diagnostics to STDOUT or STDERR without a WordPress runtime. Encode machine-readable fixture data or CLI diagnostics without WordPress helpers.
	fwrite( STDERR, $message . ":\n" . json_encode( $report, JSON_PRETTY_PRINT ) . "\n" );
	exit( 1 );
};

$assert_phpcs_passes( 'RAN', $fixtures['valid'], 'RAN rejected a syntactically valid fixture' );
$assert_phpcs_reports( 'RAN', $fixtures['syntaxInvalid'], '/^Generic\.PHP\.Syntax\./', 'RAN failed to report the syntax error' );
$assert_phpcs_reports( 'WordPress-Extra', $fixtures['exception'], '/^WordPress\.Security\.EscapeOutput\.ExceptionNotEscaped$/', 'The locked upstream checker no longer demonstrates the exception-payload diagnostic' );

foreach ( array( 'pluginRuleset', 'libraryRuleset' ) as $profile_ruleset ) {
	// Exercise the same ruleset with warnings hidden, then prove PHPCBF convergence.
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Write exact disposable fixture or configuration bytes in this CLI-only test.
	if ( false === file_put_contents( $fixtures['alignment'], $fixture_contents['alignment'] . "\n" ) ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Write process-local CLI diagnostics to STDOUT or STDERR without a WordPress runtime.
		fwrite( STDERR, "Could not reset the alignment fixture.\n" );
		exit( 1 );
	}
	list($command_status, $report) = $run_phpcs( $fixtures[ $profile_ruleset ], $fixtures['alignment'], true );
	$alignment_sources             = array();
	foreach ( $report['files'] as $file ) {
		foreach ( $file['messages'] as $diagnostic ) {
			if ( 'ERROR' === $diagnostic['type'] && $diagnostic['fixable'] ) {
				$alignment_sources[] = $diagnostic['source'];
			}
		}
	}
	foreach ( array( 'Generic.Formatting.MultipleStatementAlignment', 'WordPress.Arrays.MultipleStatementAlignment' ) as $source ) {
		if ( 0 === $command_status || ! preg_grep( '/^' . preg_quote( $source, '/' ) . '\./', $alignment_sources ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite, WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Write process-local CLI diagnostics to STDOUT or STDERR without a WordPress runtime. Encode machine-readable fixture data or CLI diagnostics without WordPress helpers.
			fwrite( STDERR, 'Alignment did not remain a fixable error with -n: ' . $source . "\n" . json_encode( $report, JSON_PRETTY_PRINT ) . "\n" );
			exit( 1 );
		}
	}
	$fix_command = escapeshellarg( $root . '/vendor/bin/phpcbf' ) . ' -n --standard='
		. escapeshellarg( $fixtures[ $profile_ruleset ] ) . ' ' . escapeshellarg( $fixtures['alignment'] ) . ' 2>&1';
	$output      = array();
	// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec -- Run the selected local checker or isolated Composer consumer proof as a CLI subprocess.
	exec( $fix_command, $output, $command_status );
	if ( 1 !== $command_status ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Write process-local CLI diagnostics to STDOUT or STDERR without a WordPress runtime.
		fwrite( STDERR, "PHPCBF failed to fix alignment:\n" . implode( "\n", $output ) . "\n" );
		exit( 1 );
	}
	$assert_phpcs_passes( $fixtures[ $profile_ruleset ], $fixtures['alignment'], 'PHPCBF left the alignment fixture unclean' );
	$fixed_hash = hash_file( 'sha256', $fixtures['alignment'] );
	$output     = array();
	// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec -- Run the selected local checker or isolated Composer consumer proof as a CLI subprocess.
	exec( $fix_command, $output, $command_status );
	if ( 0 !== $command_status || hash_file( 'sha256', $fixtures['alignment'] ) !== $fixed_hash ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Write process-local CLI diagnostics to STDOUT or STDERR without a WordPress runtime.
		fwrite( STDERR, "A second PHPCBF pass was not stable.\n" );
		exit( 1 );
	}
	$assert_phpcs_passes( $fixtures[ $profile_ruleset ], $fixtures['wordpress'], 'A WordPress profile rejected the clean consumer fixture' );
	$assert_phpcs_passes( $fixtures[ $profile_ruleset ], $fixtures['exception'], 'A WordPress profile treated an internal Throwable payload as rendered output' );
	$assert_phpcs_reports( $fixtures[ $profile_ruleset ], $fixtures['output'], '/^WordPress\.Security\.EscapeOutput\.OutputNotEscaped$/', 'The exception-message policy disabled escaping at the actual rendering boundary' );
	foreach ( array(
		'WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents',
		'WordPress.WP.AlternativeFunctions.json_encode_json_encode',
		'WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode',
	) as $native_source ) {
		$assert_phpcs_reports( $fixtures[ $profile_ruleset ], $fixtures['native'], '/^' . preg_quote( $native_source, '/' ) . '$/', 'A native-operation exception was silently promoted to shared policy' );
	}
	$assert_phpcs_reports( $fixtures[ $profile_ruleset ], $fixtures['compatInvalid'], '/^PHPCompatibility\./', 'PHPCompatibilityWP failed to report syntax outside testVersion' );
	$assert_phpcs_reports( $fixtures[ $profile_ruleset ], $fixtures['prefixInvalid'], '/^WordPress\.NamingConventions\.PrefixAllGlobals\.NonPrefixedFunctionFound$/', 'The consumer-local prefix rule failed to report a non-prefixed global' );
}

require __DIR__ . '/owned-methods.php';

// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Write process-local CLI diagnostics to STDOUT or STDERR without a WordPress runtime.
fwrite( STDOUT, "All RAN standards preserve active inheritance and consumer boundaries, and their behavioral fixtures pass.\n" );

// Compare independent maintained-file discovery with the locked CLI's actual debug paths.
$maintained_files                       = static function ( string $root ): array {
	$filter = new RecursiveCallbackFilterIterator(
		new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ),
		static function ( SplFileInfo $file ) use ( $root ): bool {
			return ! in_array( $file->getPathname(), array( $root . '/vendor', $root . '/.git' ), true );
		}
	);
	// Ignore only a genuine leading XML declaration, never XML-like PHP or later tags.
	$xml_declaration = '~\A(?:\xEF\xBB\xBF)?<\?xml[ \t\r\n]+version[ \t\r\n]*=[ \t\r\n]*(?:"1\.[01]"|\'1\.[01]\')(?:[ \t\r\n]+encoding[ \t\r\n]*=[ \t\r\n]*(?:"[A-Za-z][A-Za-z0-9._-]*"|\'[A-Za-z][A-Za-z0-9._-]*\'))?(?:[ \t\r\n]+standalone[ \t\r\n]*=[ \t\r\n]*(?:"(?:yes|no)"|\'(?:yes|no)\'))?[ \t\r\n]*\?>~';
	$files           = array();
	foreach ( new RecursiveIteratorIterator( $filter ) as $file ) {
		if ( ! $file->isFile() ) {
			continue;
		}
		$extension = strtolower( $file->getExtension() );
		if ( 'php' === $file->getExtension() ) {
			$files[] = $file->getPathname();
			continue;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read local repository or fixture bytes without requiring a WordPress runtime.
		$contents = file_get_contents( $file->getPathname() );
		if ( false === $contents ) {
			throw new RuntimeException( 'Cannot inspect maintained candidate.' );
		}
		// Quoted examples in Markdown, valid JSON and declared Bash scripts remain inert.
		$inert    = 'md' === $extension
			|| ( 'json' === $extension && null !== json_decode( $contents ) && JSON_ERROR_NONE === json_last_error() )
			|| ( 'sh' === $extension && 1 === preg_match( '~\A#!(?:/usr/bin/env[ \t]+bash|/bin/bash)(?:[ \t][^\r\n]*)?\r?\n~', $contents ) );
		$contents = preg_replace( $xml_declaration, '', $contents ) ?? $contents;
		$opening  = ! $inert ? '/<\?/' : '/\A(?:\xEF\xBB\xBF)?(?:#![^\n]*\n)?\s*<\?/';
		if ( in_array( $extension, array( 'php', 'phtml' ), true ) || preg_match( $opening, $contents ) ) {
			throw new RuntimeException( 'Review PHP with a nonstandard extension: ' . $file->getPathname() );
		}
	}
	sort( $files );
	return $files;
};
$run_analysis                           = static function ( string $configuration ) use ( $root ): array {
	$lines = array();
	// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec -- Run the selected local checker or isolated Composer consumer proof as a CLI subprocess.
	exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $root . '/vendor/bin/phpstan' ) . ' analyse --debug --no-progress --error-format=json --configuration=' . escapeshellarg( $configuration ) . ' 2>&1', $lines, $command_status );
	$output = implode( "\n", $lines );
	$start  = strpos( $output, '{"totals"' );
	if ( false === $start || ! in_array( $command_status, array( 0, 1 ), true ) ) {
		throw new RuntimeException( 'Analysis failed to return diagnostic evidence: ' . $output );
	}
	$paths = array_values( array_filter( explode( "\n", substr( $output, 0, $start ) ), 'is_file' ) );
	sort( $paths );
	return array( $command_status, $paths, json_decode( substr( $output, $start ), true, 512, JSON_THROW_ON_ERROR ) );
};
$configuration                          = $root . '/phpstan.neon';
list($analysis_status, $analysed_files) = $run_analysis( $configuration );
if ( 0 !== $analysis_status || $maintained_files( $root ) !== $analysed_files ) {
	throw new RuntimeException( 'Every maintained PHP file must pass direct analysis; vendor is the only dependency exemption.' );
}
$assert_analysis_parameters = static function ( string $configuration ) use ( $root ): void {
	$parameters = array();
	// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec -- Read the locked analyzer's effective configuration, including inherited suppression settings.
	exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $root . '/vendor/bin/phpstan' ) . ' dump-parameters --json --configuration=' . escapeshellarg( $configuration ), $parameters, $parameter_status );
	$parameters = json_decode( implode( "\n", $parameters ), true, 512, JSON_THROW_ON_ERROR );
	if ( 0 !== $parameter_status || 8 > (int) $parameters['level'] ) {
		throw new RuntimeException( 'The effective PHPStan level must remain at least eight.' );
	}
	if ( 70400 !== $parameters['phpVersion'] ) {
		throw new RuntimeException( 'Local PHPStan compatibility target must remain PHP 7.4.' );
	}
	if ( false !== $parameters['treatPhpDocTypesAsCertain'] ) {
		throw new RuntimeException( 'Defensive PHPDoc checks must remain uncertain.' );
	}
	if ( array() !== $parameters['ignoreErrors'] ) {
		throw new RuntimeException( 'PHPStan ignored errors need explicit review.' );
	}
};
$assert_analysis_parameters( $configuration );
$probe_root          = sys_get_temp_dir() . '/ran-analysis-probe-' . bin2hex( random_bytes( 6 ) );
$probe_file          = $probe_root . '/tests/split.php';
$excluded_config     = $probe_root . '/excluded.neon';
$ignored_config      = $probe_root . '/ignored.neon';
$probe_config        = $probe_root . '/phpstan.neon';
$root_probe          = $probe_root . '/root.php';
$extensionless_probe = $probe_root . '/entrypoint';
try {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Create only the private disposable directory used by this CLI fixture.
	if ( ! mkdir( $probe_root . '/tests', 0700, true ) ) {
		throw new RuntimeException( 'Cannot create private analysis probe.' );
	}
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Create only the private disposable directory used by this CLI fixture.
	mkdir( $probe_root . '/vendor', 0700 );
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Write exact disposable fixture or configuration bytes in this CLI-only test. Read local repository or fixture bytes without requiring a WordPress runtime.
	file_put_contents( $probe_config, str_replace( 'vendor/', $root . '/vendor/', (string) file_get_contents( $configuration ) ) );
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Write exact disposable fixture or configuration bytes in this CLI-only test.
	file_put_contents( $root_probe, "<?php\n" );
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Write exact disposable fixture or configuration bytes in this CLI-only test.
	file_put_contents( $probe_file, "<?php\nran_missing_analysis_probe();\n" );
	list($analysis_status, $analysed_files, $analysis_report) = $run_analysis( $probe_config );
	if ( 1 !== $analysis_status || $maintained_files( $probe_root ) !== $analysed_files || ! in_array( 'function.notFound', array_column( $analysis_report['files'][ $probe_file ]['messages'], 'identifier' ), true ) ) {
		throw new RuntimeException( 'New nested PHP must enter analysis and report its actual diagnostic.' );
	}
	foreach ( array( "        - '#.*#'\n", "        - identifier: function.notFound\n" ) as $ignored_error ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Mutate only an inherited disposable configuration to demonstrate effective suppression.
		file_put_contents( $ignored_config, "parameters:\n    ignoreErrors:\n" . $ignored_error );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Include the suppression indirectly so raw top-level inspection cannot satisfy the guard control.
		file_put_contents( $excluded_config, "includes:\n    - " . $probe_config . "\n    - " . $ignored_config . "\n" );
		list($analysis_status, $analysed_files, $analysis_report) = $run_analysis( $excluded_config );
		if ( 0 !== $analysis_status || $maintained_files( $probe_root ) !== $analysed_files || 0 !== $analysis_report['totals']['file_errors'] || array() !== $analysis_report['errors'] ) {
			throw new RuntimeException( 'An effective ignore must hide the actual diagnostic without changing analyzed files.' );
		}
		$ignored_error_rejected = false;
		try {
			$assert_analysis_parameters( $excluded_config );
		} catch ( RuntimeException $error ) {
			$ignored_error_rejected = 'PHPStan ignored errors need explicit review.' === $error->getMessage();
		}
		if ( ! $ignored_error_rejected ) {
			throw new RuntimeException( 'Effective diagnostic suppression must fail independently of clean analysis.' );
		}
	}
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- An actual nullable-method diagnostic distinguishes the Level 8 gate from a clean Level 7 run.
	file_put_contents( $probe_file, '<?php function ran_nullable_analysis_probe(?DateTimeImmutable $value): int { return $value->getTimestamp(); } echo ran_nullable_analysis_probe(null);' );
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Override only the private fixture level to prove its effective downgrade is independently rejected.
	file_put_contents( $excluded_config, "includes:\n    - " . $probe_config . "\nparameters:\n    level: 7\n" );
	list($analysis_status, $analysed_files, $analysis_report) = $run_analysis( $excluded_config );
	if ( 0 !== $analysis_status || $maintained_files( $probe_root ) !== $analysed_files || 0 !== $analysis_report['totals']['file_errors'] ) {
		throw new RuntimeException( 'The nullable probe must be clean at Level 7 with the same maintained population.' );
	}
	$downgrade_rejected = false;
	try {
		$assert_analysis_parameters( $excluded_config );
	} catch ( RuntimeException $error ) {
		$downgrade_rejected = 'The effective PHPStan level must remain at least eight.' === $error->getMessage();
	}
	if ( ! $downgrade_rejected ) {
		throw new RuntimeException( 'An inherited Level 7 downgrade must fail the effective configuration guard.' );
	}
	list($analysis_status, $analysed_files, $analysis_report) = $run_analysis( $probe_config );
	if ( 1 !== $analysis_status || $maintained_files( $probe_root ) !== $analysed_files || 1 !== $analysis_report['totals']['file_errors'] || array( 'method.nonObject' ) !== array_column( $analysis_report['files'][ $probe_file ]['messages'], 'identifier' ) ) {
		throw new RuntimeException( 'The real Level 8 runner must report the nullable-method diagnostic.' );
	}
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Protect defensive producer checks against an inherited PHPDoc-trust relaxation.
	file_put_contents( $excluded_config, "includes:\n    - " . $probe_config . "\nparameters:\n    treatPhpDocTypesAsCertain: true\n" );
	$trust_relaxation_rejected = false;
	try {
		$assert_analysis_parameters( $excluded_config );
	} catch ( RuntimeException $error ) {
		$trust_relaxation_rejected = 'Defensive PHPDoc checks must remain uncertain.' === $error->getMessage();
	}
	if ( ! $trust_relaxation_rejected ) {
		throw new RuntimeException( 'An inherited PHPDoc-trust relaxation must fail the effective configuration guard.' );
	}
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Write an inert newer native API whose compatibility diagnostic must survive the package's PHP 7.4 target.
	file_put_contents( $probe_file, "<?php\n/** @param array<array-key,mixed> \$values */ function ran_compatibility_probe( array \$values ): bool { return array_is_list( \$values ); }\n" );
	foreach ( array( 70400, 80100 ) as $version ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Exercise effective compatibility settings inherited through a disposable configuration.
		file_put_contents( $excluded_config, "includes:\n    - " . $probe_config . "\nparameters:\n    phpVersion: " . $version . "\n" );
		list($analysis_status, $analysed_files, $analysis_report) = $run_analysis( $excluded_config );
		if ( ( 70400 === $version ? 1 : 0 ) !== $analysis_status || $maintained_files( $probe_root ) !== $analysed_files ) {
			throw new RuntimeException( 'A raised PHPStan target must reproduce the lost compatibility diagnostic.' );
		}
		if ( 70400 === $version && ! in_array( 'function.notFound', array_column( $analysis_report['files'][ $probe_file ]['messages'], 'identifier' ), true ) ) {
			throw new RuntimeException( 'The supported PHP target must diagnose the newer native API.' );
		}
		$target_rejected = false;
		try {
			$assert_analysis_parameters( $excluded_config );
		} catch ( RuntimeException $error ) {
			$target_rejected = 'Local PHPStan compatibility target must remain PHP 7.4.' === $error->getMessage();
		}
		if ( ( 70400 !== $version ) !== $target_rejected ) {
			throw new RuntimeException( 'Only the supported PHPStan compatibility target may pass the guard.' );
		}
	}
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Write exact disposable fixture or configuration bytes in this CLI-only test.
	file_put_contents( $excluded_config, "includes:\n    - " . $probe_config . "\nparameters:\n    excludePaths:\n        analyse:\n            - " . $probe_file . "\n" );
	list($analysis_status, $analysed_files) = $run_analysis( $excluded_config );
	if ( 0 !== $analysis_status || array( $probe_file ) !== array_values( array_diff( $maintained_files( $probe_root ), $analysed_files ) ) ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Encode machine-readable fixture data or CLI diagnostics without WordPress helpers.
		throw new RuntimeException( 'An excluded maintained file must fail independent coverage despite clean analysis: ' . json_encode( array( $analysis_status, array_values( array_diff( $maintained_files( $probe_root ), $analysed_files ) ) ) ) );
	}
	foreach ( array( 'entrypoint', 'template.phtml', 'template.PHTML', 'template.inc', 'template.html', 'template.htm', 'template.tpl', 'template.custom', 'undeclared.sh' ) as $name ) {
		$extensionless_probe = $probe_root . '/' . $name;
		foreach ( array( "#!/usr/bin/env php\n", "<main>Template</main>\n", str_repeat( '<p>Template</p>', 100 ) ) as $preamble ) {
			foreach ( array( '<?php echo 1;', '<?php function ( {', '<? echo 1;', '<?xmlfoo echo 1;', '<?xml version="1.0"?><root><? echo 1;</root>' ) as $body ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Both valid and malformed mixed templates must require deliberate coverage regardless of preamble length.
				file_put_contents( $extensionless_probe, $preamble . $body );
				$nonstandard_rejected = false;
				try {
					$maintained_files( $probe_root );
				} catch ( RuntimeException $error ) {
					$nonstandard_rejected = 0 === strpos( $error->getMessage(), 'Review PHP with a nonstandard extension:' );
				}
				if ( ! $nonstandard_rejected ) {
					throw new RuntimeException( 'Mixed PHP templates must not silently escape independent coverage.' );
				}
			}
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Remove only the current inert template fixture.
		unlink( $extensionless_probe );
	}
	foreach ( array( 'template.inc', 'template.xml' ) as $name ) {
		$extensionless_probe = $probe_root . '/' . $name;
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- A genuine leading XML declaration alone is inert; quoted PHP documentation is tested separately.
		file_put_contents( $extensionless_probe, '<?xml version="1.0" encoding="UTF-8"?><root/>' );
		$maintained_files( $probe_root );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Remove only the current XML fixture.
		unlink( $extensionless_probe );
	}
	foreach ( array( 'example.md', 'example.json', 'example.sh' ) as $name ) {
		$extensionless_probe = $probe_root . '/' . $name;
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Non-PHP documents and shell examples retain their existing classification.
		file_put_contents( $extensionless_probe, 'example.sh' === $name ? "#!/bin/bash\n# Example: <?php echo 1;" : '"Example: <?php echo 1;"' );
		$maintained_files( $probe_root );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Remove only the current non-PHP fixture.
		unlink( $extensionless_probe );
	}
} finally {
	foreach ( array( $probe_file, $excluded_config, $ignored_config, $probe_config, $root_probe, $extensionless_probe ) as $probe_path ) {
		if ( is_file( $probe_path ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Remove only a disposable file created by this fixture.
			unlink( $probe_path );
		}
	}
	if ( is_dir( $probe_root . '/tests' ) ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Remove only the disposable directory created for this CLI fixture.
		rmdir( $probe_root . '/tests' );
	}
	if ( is_dir( $probe_root . '/vendor' ) ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Remove only the disposable directory created for this CLI fixture.
		rmdir( $probe_root . '/vendor' );
	}
	if ( is_dir( $probe_root ) ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Remove only the disposable directory created for this CLI fixture.
		rmdir( $probe_root );
	}
}
// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Write process-local CLI diagnostics to STDOUT or STDERR without a WordPress runtime.
fwrite( STDOUT, "All maintained PHP is directly analysed at level eight or stronger; future paths and exclusions are guarded.\n" );

// The local source profile is independent of exported consumer rules and their fixture payloads.
$source_profile_is_scoped = static function ( SimpleXMLElement $document ): bool {
	$configs = $document->xpath( '//config' );
	if ( ! is_array( $configs ) || 1 !== count( $configs ) || ! isset( $configs[0] ) || 2 !== count( $configs[0]->attributes() )
		|| 'testVersion' !== (string) $configs[0]['name'] || '7.4-' !== (string) $configs[0]['value']
	) {
		return true;
	}
	$exclusions = $document->xpath( '//exclude-pattern' );
	if ( ! is_array( $exclusions ) || 1 !== count( $exclusions ) ) {
		return true;
	}
	$vendor = $document->xpath( '/ruleset/exclude-pattern' );
	if ( ! is_array( $vendor ) || 1 !== count( $vendor ) || ! isset( $vendor[0] ) || '^vendor/' !== (string) $vendor[0]
		|| 1 !== count( $vendor[0]->attributes() ) || 'relative' !== (string) $vendor[0]['type']
	) {
		return true;
	}
	return array() !== $document->xpath( '//include-pattern | //rule/exclude | //*[@phpcs-only or @phpcbf-only]' );
};
$source_profile           = simplexml_load_file( $root . '/.phpcs.xml', 'SimpleXMLElement', LIBXML_NONET );
if ( false === $source_profile || $source_profile_is_scoped( $source_profile ) ) {
	throw new RuntimeException( 'Local source rules must preserve PHP 7.4 and reject conditional or path-scoped exceptions.' );
}
$source_profile_xml = $source_profile->asXML();
if ( ! is_string( $source_profile_xml ) ) {
	throw new RuntimeException( 'Could not serialize the local source profile.' );
}
foreach ( array(
	str_replace( 'value="7.4-"', 'value="8.3-"', $source_profile_xml ),
	str_replace( '<config name="testVersion" value="7.4-"/>', '', $source_profile_xml ),
	str_replace( '</ruleset>', '<config name="testVersion" value="7.4-"/></ruleset>', $source_profile_xml ),
	str_replace( '</ruleset>', '<exclude-pattern>*/not-yet-created/*</exclude-pattern></ruleset>', $source_profile_xml ),
	str_replace( '^vendor/', 'vendor/', $source_profile_xml ),
	str_replace( 'type="relative"', 'type="absolute"', $source_profile_xml ),
	str_replace( '<exclude-pattern type="relative">^vendor/</exclude-pattern>', '', $source_profile_xml ),
) as $mutant_xml ) {
	$document = simplexml_load_string( $mutant_xml, 'SimpleXMLElement', LIBXML_NONET );
	if ( false === $document || ! $source_profile_is_scoped( $document ) ) {
		throw new RuntimeException( 'Only the reviewed PHP target and root vendor exclusion may survive, including before a future file exists.' );
	}
}
require_once $root . '/vendor/squizlabs/php_codesniffer/autoload.php';
// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read the canonical checker/fixer contract as local JSON data.
$source_manifest_bytes = file_get_contents( $root . '/composer.json' );
if ( ! is_string( $source_manifest_bytes ) ) {
	throw new RuntimeException( 'Could not read the canonical source manifest.' );
}
$source_manifest = json_decode( $source_manifest_bytes, true, 512, JSON_THROW_ON_ERROR );
if ( 'phpcs --standard=.phpcs.xml --report=summary' !== $source_manifest['scripts']['standards'] || 'phpcbf --standard=.phpcs.xml --report=summary' !== $source_manifest['scripts']['standards:fix'] ) {
	throw new RuntimeException( 'Source check and fix must retain the same reviewed ruleset and default scope.' );
}
$source_files   = $maintained_files( $root );
$runner         = new PHP_CodeSniffer\Runner();
$runner->config = new PHP_CodeSniffer\Config( array( '--standard=' . $root . '/.phpcs.xml' ) );
$runner->init();
$selected_files = array();
foreach ( new PHP_CodeSniffer\Files\FileList( $runner->config, $runner->ruleset ) as $source_path => $file ) {
	if ( ! is_string( $source_path ) ) {
		throw new RuntimeException( 'The source checker did not select a file path.' );
	}
	$selected_files[] = $source_path;
}
sort( $selected_files );
if ( $source_files !== $selected_files ) {
	throw new RuntimeException( 'Local standards must select every maintained PHP file.' );
}
$inspect_source = static function ( string $source, string $source_path, string $standard = '.phpcs.xml' ) use ( $root ): array {
	// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_proc_open -- Run only the locked checker on inert source over private process pipes.
	$process = proc_open( array( PHP_BINARY, $root . '/vendor/bin/phpcs', '--standard=' . $root . '/' . $standard, '--report=json', '-q', '--stdin-path=' . $source_path, '-' ), array( array( 'pipe', 'r' ), array( 'pipe', 'w' ), array( 'pipe', 'w' ) ), $pipes, $root );
	if ( ! is_resource( $process ) ) {
		throw new RuntimeException( 'Could not start the locked source checker.' );
	}
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Write only CLI diagnostics or inert source to the local checker pipe.
	fwrite( $pipes[0], $source );
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Close only this child-process pipe after collecting checker evidence.
	fclose( $pipes[0] );
	$output = stream_get_contents( $pipes[1] );
	$error  = stream_get_contents( $pipes[2] );
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Close only this child-process pipe after collecting checker evidence.
	fclose( $pipes[1] );
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Close only this child-process pipe after collecting checker evidence.
	fclose( $pipes[2] );
	proc_close( $process );
	if ( '' !== $error ) {
		throw new RuntimeException( 'Source checker failed: ' . $error );
	}
	$report  = json_decode( $output, true, 512, JSON_THROW_ON_ERROR );
	$sources = array();
	foreach ( $report['files'] as $file ) {
		foreach ( $file['messages'] as $message ) {
			$sources[] = $message['source'];
		}
	}
	return $sources;
};
$selector_probe = 'source-selector-' . bin2hex( random_bytes( 8 ) ) . '.xml';
$json_code      = 'WordPress.WP.AlternativeFunctions.json_encode_json_encode';
$path_probe     = '<?php json_encode( array() );';
if ( ! in_array( $json_code, $inspect_source( $path_probe, $root . '/src/unreviewed-future.php' ), true ) ) {
	throw new RuntimeException( 'A future source path must enforce the native JSON diagnostic.' );
}
try {
	$compatibility_code  = 'PHPCompatibility.FunctionUse.NewFunctions.json_validateFound';
	$compatibility_probe = "<?php json_validate( 'null' );";
	if ( ! in_array( $compatibility_code, $inspect_source( $compatibility_probe, $root . '/tests/compatibility.php' ), true ) ) {
		throw new RuntimeException( 'The local PHP 7.4 profile must diagnose the newer native API.' );
	}
	$mutant_xml = str_replace( 'value="7.4-"', 'value="8.3-"', $source_profile_xml );
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Reproduce lost compatibility diagnostics only in the disposable local ruleset.
	file_put_contents( $root . '/' . $selector_probe, $mutant_xml );
	$document = simplexml_load_string( $mutant_xml, 'SimpleXMLElement', LIBXML_NONET );
	if ( false === $document || in_array( $compatibility_code, $inspect_source( $compatibility_probe, $root . '/tests/compatibility.php', $selector_probe ), true )
		|| ! $source_profile_is_scoped( $document )
	) {
		throw new RuntimeException( 'A raised local compatibility target must hide the diagnostic but fail the guard.' );
	}
	foreach ( array(
		'<rule ref="WordPress.WP.AlternativeFunctions.json_encode_json_encode"><include-pattern>^(?!*unreviewed-future[.]php)</include-pattern></rule>',
		'<rule ref="WordPress.WP.AlternativeFunctions.json_encode_json_encode"><exclude-pattern>*/src/unreviewed-future.php</exclude-pattern></rule>',
		'<rule ref="RANWordPressLibrary" phpcbf-only="true"/>',
	) as $selector ) {
		$mutant_xml = false !== strpos( $selector, 'phpcbf-only' )
			? str_replace( '<rule ref="RANWordPressLibrary"/>', $selector, $source_profile_xml )
			: str_replace( '</ruleset>', $selector . '</ruleset>', $source_profile_xml );
		$document   = simplexml_load_string( $mutant_xml, 'SimpleXMLElement', LIBXML_NONET );
		if ( false === $document || ! $source_profile_is_scoped( $document ) ) {
			throw new RuntimeException( 'A conditional or path selector escaped the local source guard.' );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Create only the disposable local ruleset mutant for the diagnostic control.
		file_put_contents( $root . '/' . $selector_probe, $mutant_xml );
		if ( in_array( $json_code, $inspect_source( $path_probe, $root . '/src/unreviewed-future.php', $selector_probe ), true ) ) {
			throw new RuntimeException( 'The selector control did not reproduce its hidden diagnostic. ' . $selector );
		}
		if ( false === strpos( $selector, 'phpcbf-only' ) && ! in_array( $json_code, $inspect_source( $path_probe, $root . '/src/adjacent-future.php', $selector_probe ), true ) ) {
			throw new RuntimeException( 'The targeted selector must leave the adjacent path checked.' );
		}
	}
	foreach ( array( false, true ) as $exclude_fixture ) {
		$mutant_xml = $exclude_fixture
			? str_replace( '</ruleset>', '<exclude-pattern>*/native.php</exclude-pattern></ruleset>', $source_profile_xml )
			: $source_profile_xml;
		$document   = simplexml_load_string( $mutant_xml, 'SimpleXMLElement', LIBXML_NONET );
		if ( false === $document || $exclude_fixture !== $source_profile_is_scoped( $document ) ) {
			throw new RuntimeException( 'The root exclusion mutation must be rejected independently of current selection.' );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Create only the disposable local ruleset to prove actual file-selection behavior.
		file_put_contents( $root . '/' . $selector_probe, $mutant_xml );
		$selection_runner         = new PHP_CodeSniffer\Runner();
		$selection_runner->config = new PHP_CodeSniffer\Config( array( '--standard=' . $root . '/' . $selector_probe, $fixtures['native'], $fixtures['valid'] ) );
		$selection_runner->init();
		$selected = iterator_to_array( new PHP_CodeSniffer\Files\FileList( $selection_runner->config, $selection_runner->ruleset ) );
		if ( array_key_exists( $fixtures['native'], $selected ) === $exclude_fixture || ! array_key_exists( $fixtures['valid'], $selected ) ) {
			throw new RuntimeException( 'The real checker must omit only the targeted file when the root exclusion is added.' );
		}
	}
	// Exercise the same predicate used by the recursive inspector on exported ancestry.
	$exported_profile = simplexml_load_file( $root . '/RANWordPress/ruleset.xml', 'SimpleXMLElement', LIBXML_NONET );
	if ( false === $exported_profile ) {
		throw new RuntimeException( 'Could not load the exported ancestor for conditional controls.' );
	}
	$exported_profile_xml = $exported_profile->asXML();
	if ( ! is_string( $exported_profile_xml ) ) {
		throw new RuntimeException( 'Could not serialize the exported ancestor control.' );
	}
	foreach ( array( '', ' phpcbf-only="true"', ' phpcs-only="false"', ' phpcs-only="true"', ' phpcbf-only="false"' ) as $condition ) {
		$mutant_xml = str_replace( '<rule ref="WordPress-Extra">', '<rule ref="WordPress-Extra"' . $condition . '>', $exported_profile_xml );
		$document   = simplexml_load_string( $mutant_xml, 'SimpleXMLElement', LIBXML_NONET );
		if ( false === $document || ( '' === $condition ) !== ( null === $find_selector_violation( $document, 'exported ancestor control' ) ) ) {
			throw new RuntimeException( 'Recursive exported inspection must reject checker/fixer conditional attributes.' );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Write an inert copy of the exported ancestor; never alter the exported standard.
		file_put_contents( $root . '/' . $selector_probe, $mutant_xml );
		$hidden = in_array( $condition, array( ' phpcbf-only="true"', ' phpcs-only="false"', ' phpcbf-only="false"' ), true );
		if ( in_array( $json_code, $inspect_source( $path_probe, $root . '/src/unreviewed-future.php', $selector_probe ), true ) === $hidden ) {
			throw new RuntimeException( 'The exported conditional must demonstrate its actual checker diagnostic effect.' );
		}
	}
} finally {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Remove only the disposable local selector-control ruleset.
	unlink( $root . '/' . $selector_probe );
}
$outside_probes = array(
	'WordPress.PHP.DiscouragedPHPFunctions.system_calls_proc_open' => "proc_open( 'never executed', array(), \$pipes );",
	'WordPress.WP.AlternativeFunctions.file_system_operations_fclose' => 'fclose( STDOUT );',
	'WordPress.WP.AlternativeFunctions.file_system_operations_fwrite' => "fwrite( STDOUT, 'probe' );",
	'WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents' => "file_put_contents( 'never-executed', 'probe' );",
	'WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec' => "exec( 'never executed' );",
	'WordPress.WP.AlternativeFunctions.json_encode_json_encode' => 'json_encode( array() );',
	'WordPress.WP.AlternativeFunctions.file_system_operations_rmdir' => "rmdir( 'never-executed' );",
	'WordPress.PHP.NoSilencedErrors.Discouraged'       => "@strlen( 'probe' );",
	'WordPress.Security.EscapeOutput.OutputNotEscaped' => 'echo $unescaped;',
	'WordPress.WP.AlternativeFunctions.file_system_operations_mkdir' => "mkdir( 'never-executed' );",
	'WordPress.WP.AlternativeFunctions.unlink_unlink'  => "unlink( 'never-executed' );",
	'WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents' => "file_get_contents( 'never-executed' );",
	'PHPCompatibility.Numbers.RemovedHexadecimalNumericStrings.Found' => "\$hex = array( '0xc384' );",
);
foreach ( $outside_probes as $diagnostic => $probe ) {
	$adjacent         = "<?php\n// phpcs:ignore " . $diagnostic . " -- Intentional boundary probe.\n" . $probe . "\n" . $probe . "\n";
	$adjacent_sources = $inspect_source( $adjacent, $root . '/tests/new-source.php' );
	$matching_sources = array_filter(
		$adjacent_sources,
		static function ( string $source ) use ( $diagnostic ): bool {
			return $diagnostic === $source;
		}
	);
	if ( 1 !== count( $matching_sources ) ) {
		throw new RuntimeException( 'A precise occurrence must leave its immediate sibling checked: ' . $diagnostic );
	}
}
$inspect_annotations = static function ( string $source, string $source_path ) use ( $root, $outside_probes ): array {
	$exceptions                = array();
	$has_cli_binding_allowance = false;
	foreach ( token_get_all( $source ) as $token ) {
		if ( ! is_array( $token ) || ! in_array( $token[0], array( T_COMMENT, T_DOC_COMMENT ), true ) ) {
			continue;
		}
		if ( preg_match( '/@codingStandards|phpcs:(?:ignoreFile|set)/i', $token[1] ) ) {
			throw new RuntimeException( 'Legacy, file-wide and property-changing annotations are not source exceptions.' );
		}
		if ( ! preg_match_all( '/phpcs:(disable|ignore)\b([^\r\n]*?)(?=@?phpcs:|[\r\n]|$)/i', $token[1], $annotations, PREG_SET_ORDER ) ) {
			if ( preg_match( '/phpcs:(?:disable|ignore)/i', $token[1] ) ) {
				throw new RuntimeException( 'Source exceptions must name exact diagnostics and their reason.' );
			}
			continue;
		}
		foreach ( $annotations as $directive ) {
			if ( ! preg_match( '/^\s+(.+?)\s+--\s+\S/', $directive[2], $detail ) ) {
				throw new RuntimeException( 'Every source exception must name exact diagnostics and its reason.' );
			}
			foreach ( explode( ',', $detail[1] ) as $selector ) {
				$selector = trim( $selector );
				if ( 'disable' === strtolower( $directive[1] ) ) {
					$has_cli_binding_allowance = true;
					if ( 'WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound' !== $selector || ! in_array( $source_path, array( $root . '/tests/run.php', $root . '/tests/owned-methods.php', $root . '/tests/consumer-install.php' ), true ) ) {
						throw new RuntimeException( 'Only the three existing CLI files justify process-local variable allowances.' );
					}
				} elseif ( ! isset( $outside_probes[ $selector ] ) ) {
					throw new RuntimeException( 'A new source exception needs an actual outside-scope regression: ' . $selector );
				} else {
					$exceptions[ $selector ] = $outside_probes[ $selector ];
				}
			}
		}
	}
	return array( $exceptions, $has_cli_binding_allowance );
};
foreach ( array( 'phpcs:ignore WordPress', '@PHPCS:IGNORE WordPress.NamingConventions', 'PHPCS:DISABLE WordPress.NamingConventions.PrefixAllGlobals', '@phpcs:disable' ) as $second_directive ) {
	$multiline = "<?php\n/*\n phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Precise first directive.\n " . $second_directive . " -- Unacceptable second directive.\n */\nfunction unrelated_helper() {}\n";
	$rejected  = false;
	try {
		$inspect_annotations( $multiline, $root . '/tests/run.php' );
	} catch ( RuntimeException $error ) {
		$rejected = true;
	}
	if ( ! $rejected ) {
		throw new RuntimeException( 'Every directive in a multiline comment must be inspected, including case and annotation prefixes.' );
	}
}
$hidden_declaration = "<?php\n/*\n phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Precise first directive.\n phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- Unacceptable second directive.\n */\nfunction unrelated_helper() {}\n";
if ( in_array( 'WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound', $inspect_source( $hidden_declaration, $root . '/tests/run.php' ), true ) ) {
	throw new RuntimeException( 'The real checker must demonstrate the multiline suppression bypass rejected by the annotation guard.' );
}
foreach ( $source_files as $source_path ) {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read owned source as inert checker input without executing its fixture operations.
	$source = file_get_contents( $source_path );
	if ( ! is_string( $source ) ) {
		throw new RuntimeException( 'Could not read owned source: ' . $source_path );
	}
	if ( array() !== $inspect_source( $source, $source_path ) ) {
		throw new RuntimeException( 'Existing source exceptions must pass the complete local profile: ' . $source_path );
	}
	list($exceptions, $has_cli_binding_allowance) = $inspect_annotations( $source, $source_path );
	foreach ( $exceptions as $diagnostic => $probe ) {
		if ( ! in_array( $diagnostic, $inspect_source( $source . "\n" . $probe . "\n", $source_path ), true ) ) {
			throw new RuntimeException( 'An occurrence exception hid a new operation after the source: ' . $source_path . ' ' . $diagnostic );
		}
	}
	$new_declaration    = "\nfunction unrelated_helper() {}\n\$camelCase = 1;\n";
	$sources            = $inspect_source( $source . $new_declaration, $source_path );
	$naming_diagnostics = array( 'WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase' );
	if ( $has_cli_binding_allowance ) {
		$naming_diagnostics[] = 'WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound';
	}
	foreach ( $naming_diagnostics as $diagnostic ) {
		if ( ! in_array( $diagnostic, $sources, true ) ) {
			throw new RuntimeException( 'CLI variable exceptions hid a new declaration or naming violation.' );
		}
	}
}
foreach ( array( 'new.php', 'new-source/tests/owned.php', 'tests/vendor/owned.php' ) as $source_path ) {
	$sources = $inspect_source( '<?php class RogueClass {} const ROGUE_VALUE = 1; function unrelated_helper() {} $unrelated = 1;', $root . '/' . $source_path );
	foreach ( array( 'Class', 'Constant', 'Function', 'Variable' ) as $declaration ) {
		if ( ! in_array( 'WordPress.NamingConventions.PrefixAllGlobals.NonPrefixed' . $declaration . 'Found', $sources, true ) ) {
			throw new RuntimeException( 'New maintained source escaped the local prefix profile: ' . $declaration );
		}
	}
	$namespace_sources = $inspect_source( '<?php namespace RogueVendor;', $root . '/' . $source_path );
	if ( ! in_array( 'WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedNamespaceFound', $namespace_sources, true ) ) {
		throw new RuntimeException( 'New maintained namespaces escaped the local prefix profile.' );
	}
}
$method_sources = $inspect_source( '<?php class RANOwnedMethodsProbe extends \\RuntimeException { public function camelCase() {} }', $root . '/new.php' );
if ( ! in_array( 'RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase', $method_sources, true ) ) {
	throw new RuntimeException( 'The local source profile must enforce owned inherited-method names.' );
}
// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Write only CLI diagnostics or inert source to the local checker pipe.
fwrite( STDOUT, "Local source coverage and exact CLI exceptions reject outside-scope violations.\n" );
