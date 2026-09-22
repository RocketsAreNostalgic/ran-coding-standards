<?php
/**
 * Naming checks for consumer-selected first-party methods.
 *
 * @package RANOwnedMethods
 */

namespace RANOwnedMethods\Sniffs\NamingConventions;

use PHP_CodeSniffer\Files\File;
use PHP_CodeSniffer\Sniffs\Sniff;
use PHPCSUtils\Utils\FunctionDeclarations;
use PHPCSUtils\Utils\Scopes;

/**
 * Check declarations without treating inheritance as an ownership exemption.
 */
final class ValidMethodNameSniff implements Sniff {

	/**
	 * Register named function declarations; scope is checked during processing.
	 *
	 * @return array<int>
	 */
	public function register() {
		return array( T_FUNCTION );
	}

	/**
	 * Check a method in the consumer's selected source scope.
	 *
	 * @param File $phpcs_file The file being checked.
	 * @param int  $stack_ptr  Declaration token position.
	 * @return void
	 */
	public function process( File $phpcs_file, $stack_ptr ) {
		if ( ! Scopes::isOOMethod( $phpcs_file, $stack_ptr ) ) {
			return;
		}

		$name = FunctionDeclarations::getName( $phpcs_file, $stack_ptr );
		if ( null === $name || FunctionDeclarations::isMagicMethodName( $name ) ) {
			return;
		}

		// Explicit ASCII policy is independent of Unicode database and mbstring versions.
		$valid_name = preg_match( '/\A[_a-z][_a-z0-9]*\z/', $name );

		// Render non-ASCII bytes safely in both text and JSON reports.
		$display_name = 1 === preg_match( '/\A[_a-zA-Z0-9]+\z/', $name ) ? $name : '0x' . bin2hex( $name );

		if ( 1 !== $valid_name ) {
			$phpcs_file->addError(
				'Owned method "%s" must use ASCII snake_case; inheritance does not exempt owned declarations.',
				$stack_ptr,
				'NotSnakeCase',
				array( $display_name )
			);
		}

		if ( 1 === preg_match( '/^__/', $name ) ) {
			$phpcs_file->addError(
				'Owned method "%s" uses a double-underscore prefix reserved for PHP magic methods.',
				$stack_ptr,
				'ReservedPrefix',
				array( $display_name )
			);
		}
	}
}
