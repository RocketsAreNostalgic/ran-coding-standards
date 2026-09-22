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

		// Permit lowercase or uncased letters, marks, ASCII digits and underscores.
		// A complete allowlist excludes uppercase, symbols and invisible controls.
		// Invalid UTF-8 identifiers fail closed instead of bypassing the check.
		$valid_name = preg_match( '/\A[_\p{Ll}\p{Lo}][_\p{Ll}\p{Lo}\p{Mn}\p{Mc}0-9]*\z/u', $name );

		$display_name = false === $valid_name ? '0x' . bin2hex( $name ) : $name;

		if ( 1 !== $valid_name ) {
			$phpcs_file->addError(
				'Owned method "%s" must use snake_case; inheritance does not exempt owned declarations.',
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
