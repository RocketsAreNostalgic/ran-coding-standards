<?php
/**
 * PCRE end-of-subject anchor checks for validation patterns.
 *
 * @package RANSecurity
 */

namespace RANSecurity\Sniffs\Pcre;

use PHP_CodeSniffer\Files\File;
use PHP_CodeSniffer\Sniffs\Sniff;
use PHPCSUtils\Tokens\Collections;
use PHPCSUtils\Utils\PassedParameters;
use PHPCSUtils\Utils\TextStrings;

/**
 * Reject unescaped "$" anchors in single-line PCRE validation patterns lacking the "D" modifier.
 */
final class StrictEndAnchorSniff implements Sniff {

	/**
	 * PCRE matching functions typically used for input validation.
	 *
	 * @var array<string, bool>
	 */
	private const TARGET_FUNCTIONS = array(
		'preg_match'     => true,
		'preg_match_all' => true,
	);

	/**
	 * Paired PCRE bracket delimiters.
	 *
	 * @var array<string, string>
	 */
	private const PAIRED_DELIMITERS = array(
		'(' => ')',
		'[' => ']',
		'{' => '}',
		'<' => '>',
	);

	/**
	 * Register function-call identifier tokens.
	 *
	 * @return array<int>
	 */
	public function register() {
		return array( T_STRING );
	}

	/**
	 * Inspect literal pattern arguments passed to preg_match() / preg_match_all().
	 *
	 * @param File $phpcs_file The file being checked.
	 * @param int  $stack_ptr  Identifier token position.
	 * @return void
	 */
	public function process( File $phpcs_file, $stack_ptr ) {
		$tokens = $phpcs_file->getTokens();
		$name   = strtolower( $tokens[ $stack_ptr ]['content'] );
		if ( ! isset( self::TARGET_FUNCTIONS[ $name ] ) ) {
			return;
		}

		$prev = $this->find_non_empty( $tokens, $stack_ptr - 1, -1 );
		if ( null !== $prev ) {
			$prev_code = $tokens[ $prev ]['code'];
			if (
				T_FUNCTION === $prev_code
				|| T_CONST === $prev_code
				|| isset( Collections::objectOperators()[ $prev_code ] )
			) {
				return;
			}
		}

		$next = $this->find_non_empty( $tokens, $stack_ptr + 1, 1 );
		if ( null === $next || 'T_OPEN_PARENTHESIS' !== $tokens[ $next ]['type'] ) {
			return;
		}

		$param = PassedParameters::getParameter( $phpcs_file, $stack_ptr, 1, 'pattern' );
		if ( false === $param ) {
			return;
		}

		$pattern_ptr = $this->single_literal_token( $phpcs_file, (int) $param['start'], (int) $param['end'] );
		if ( null === $pattern_ptr ) {
			return;
		}

		$raw_pattern = $tokens[ $pattern_ptr ]['content'];
		$pattern     = $this->decode_literal( $raw_pattern );
		$parsed      = $this->split_pcre_pattern( $pattern );
		if ( null === $parsed ) {
			return;
		}

		$body      = $parsed['body'];
		$modifiers = $parsed['modifiers'];
		if ( false !== strpos( $modifiers, 'm' ) || false !== strpos( $modifiers, 'D' ) ) {
			return;
		}

		if ( ! $this->has_unescaped_end_dollar( $body ) ) {
			return;
		}

		$phpcs_file->addError(
			'PCRE pattern uses "$" without the "D" modifier, which allows a trailing newline; use "\\z" or add the "D" modifier.',
			$pattern_ptr,
			'UnanchoredEndDollar'
		);
	}

	/**
	 * Return the token pointer when a parameter range contains exactly one constant string literal.
	 *
	 * @param File $phpcs_file The file being checked.
	 * @param int  $start      First token in the parameter.
	 * @param int  $end        Last token in the parameter.
	 * @return int|null
	 */
	private function single_literal_token( File $phpcs_file, int $start, int $end ): ?int {
		$tokens  = $phpcs_file->getTokens();
		$literal = null;

		for ( $i = $start; $i <= $end; ++$i ) {
			if ( $this->is_empty_token( $tokens[ $i ] ) ) {
				continue;
			}
			if ( T_CONSTANT_ENCAPSED_STRING !== $tokens[ $i ]['code'] || null !== $literal ) {
				return null;
			}
			$literal = $i;
		}

		return $literal;
	}

	/**
	 * Find the nearest non-empty token starting at $start in direction $step (+1 or -1).
	 *
	 * @param array<int, array<string, mixed>> $tokens File token stack.
	 * @param int                              $start  Starting token index.
	 * @param int                              $step   Search direction (+1 or -1).
	 * @return int|null
	 */
	private function find_non_empty( array $tokens, int $start, int $step ): ?int {
		for ( $i = $start; isset( $tokens[ $i ] ); $i += $step ) {
			if ( ! $this->is_empty_token( $tokens[ $i ] ) ) {
				return $i;
			}
		}

		return null;
	}

	/**
	 * Determine whether a token is whitespace, a comment, or a PHPCS annotation.
	 *
	 * @param array<string, mixed> $token Token entry from File::getTokens().
	 * @return bool
	 */
	private function is_empty_token( array $token ): bool {
		$code = $token['code'];
		if ( T_WHITESPACE === $code || T_COMMENT === $code || T_DOC_COMMENT === $code ) {
			return true;
		}

		$type = is_string( $token['type'] ?? null ) ? $token['type'] : '';

		return 0 === strpos( $type, 'T_DOC_COMMENT_' ) || 0 === strpos( $type, 'T_PHPCS_' );
	}

	/**
	 * Unquote a PHP constant string literal, preserving PCRE escapes accurately.
	 *
	 * @param string $raw Raw token content including surrounding quotes.
	 * @return string
	 */
	private function decode_literal( string $raw ): string {
		$unquoted = TextStrings::stripQuotes( $raw );
		if ( '' === $raw || "'" === $raw[0] ) {
			return str_replace( array( '\\\\', "\\'" ), array( '\\', "'" ), $unquoted );
		}

		return str_replace( array( '\\\\', '\\"', '\\$' ), array( '\\', '"', '$' ), $unquoted );
	}

	/**
	 * Split a delimited PCRE string into body and modifier flags.
	 *
	 * @param string $pattern Full PCRE pattern string.
	 * @return array{body:string,modifiers:string}|null
	 */
	private function split_pcre_pattern( string $pattern ): ?array {
		$trimmed = ltrim( $pattern );
		$length  = strlen( $trimmed );
		if ( $length < 2 ) {
			return null;
		}

		$open  = $trimmed[0];
		$close = self::PAIRED_DELIMITERS[ $open ] ?? $open;
		if ( ctype_alnum( $open ) || '\\' === $open ) {
			return null;
		}

		$close_pos = null;
		$escaped   = false;
		$depth     = 1;
		for ( $i = 1; $i < $length; ++$i ) {
			$char = $trimmed[ $i ];
			if ( $escaped ) {
				$escaped = false;
				continue;
			}
			if ( '\\' === $char ) {
				$escaped = true;
				continue;
			}
			if ( $open !== $close && $open === $char ) {
				++$depth;
				continue;
			}
			if ( $close === $char ) {
				--$depth;
				if ( 0 === $depth ) {
					$close_pos = $i;
					break;
				}
			}
		}

		if ( null === $close_pos ) {
			return null;
		}

		return array(
			'body'      => substr( $trimmed, 1, $close_pos - 1 ),
			'modifiers' => substr( $trimmed, $close_pos + 1 ),
		);
	}

	/**
	 * Detect an unescaped "$" anchor outside character classes.
	 *
	 * @param string $body PCRE pattern body without delimiters.
	 * @return bool
	 */
	private function has_unescaped_end_dollar( string $body ): bool {
		$length   = strlen( $body );
		$escaped  = false;
		$in_class = false;

		for ( $i = 0; $i < $length; ++$i ) {
			$char = $body[ $i ];
			if ( $escaped ) {
				$escaped = false;
				continue;
			}
			if ( '\\' === $char ) {
				$escaped = true;
				continue;
			}
			if ( $in_class ) {
				if ( ']' === $char ) {
					$in_class = false;
				}
				continue;
			}
			if ( '[' === $char ) {
				$in_class = true;
				continue;
			}
			if ( '$' === $char ) {
				return true;
			}
		}

		return false;
	}
}
