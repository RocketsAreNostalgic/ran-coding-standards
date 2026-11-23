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
use PHPCSUtils\Utils\Context;
use PHPCSUtils\Utils\PassedParameters;
use PHPCSUtils\Utils\TextStrings;
use PHPCSUtils\Utils\UseStatements;

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
	 * PCRE2 pattern-bearing (*keyword:...) group names whose bodies must be scanned.
	 *
	 * @var array<string, bool>
	 */
	private const PATTERN_STAR_GROUPS = array(
		'atomic'                         => true,
		'positive_lookahead'             => true,
		'pla'                            => true,
		'negative_lookahead'             => true,
		'nla'                            => true,
		'positive_lookbehind'            => true,
		'plb'                            => true,
		'negative_lookbehind'            => true,
		'nlb'                            => true,
		'non_atomic_positive_lookahead'  => true,
		'napla'                          => true,
		'non_atomic_positive_lookbehind' => true,
		'naplb'                          => true,
		'script_run'                     => true,
		'sr'                             => true,
		'atomic_script_run'              => true,
		'asr'                            => true,
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

		if ( Context::inAttribute( $phpcs_file, $stack_ptr ) ) {
			return;
		}

		$is_global_qualified = false;
		$prev                = $this->find_non_empty( $tokens, $stack_ptr - 1, -1 );
		if ( null !== $prev ) {
			$prev_code = $tokens[ $prev ]['code'];
			if (
				T_FUNCTION === $prev_code
				|| T_CONST === $prev_code
				|| T_NEW === $prev_code
				|| isset( Collections::objectOperators()[ $prev_code ] )
			) {
				return;
			}
			if ( T_NS_SEPARATOR === $prev_code ) {
				$before_ns = $this->find_non_empty( $tokens, $prev - 1, -1 );
				if (
					null !== $before_ns
					&& (
						T_STRING === $tokens[ $before_ns ]['code']
						|| T_NAMESPACE === $tokens[ $before_ns ]['code']
						|| T_NEW === $tokens[ $before_ns ]['code']
					)
				) {
					return;
				}
				$is_global_qualified = true;
			}
		}

		if ( ! $is_global_qualified && $this->is_aliased_to_non_global_function( $phpcs_file, $stack_ptr, $name ) ) {
			return;
		}

		$next = $this->find_non_empty( $tokens, $stack_ptr + 1, 1 );
		if ( null === $next || 'T_OPEN_PARENTHESIS' !== $tokens[ $next ]['type'] ) {
			return;
		}

		$param = PassedParameters::getParameter( $phpcs_file, $stack_ptr, 1, 'pattern' );
		if ( false === $param ) {
			return;
		}

		$literal = $this->extract_single_literal( $phpcs_file, (int) $param['start'], (int) $param['end'] );
		if ( null === $literal ) {
			return;
		}

		$pattern_ptr = $literal['ptr'];
		$pattern     = $this->decode_literal( $literal['raw'] );
		$parsed      = $this->split_pcre_pattern( $pattern );
		if ( null === $parsed ) {
			return;
		}

		$body      = $parsed['body'];
		$modifiers = $parsed['modifiers'];
		if ( false !== strpos( $modifiers, 'D' ) ) {
			return;
		}

		$initial_multiline = false !== strpos( $modifiers, 'm' );
		$initial_extended  = false !== strpos( $modifiers, 'x' );
		$initial_utf       = false !== strpos( $modifiers, 'u' );
		if ( ! $this->has_active_single_line_dollar( $body, $initial_multiline, $initial_extended, $initial_utf ) ) {
			return;
		}

		$phpcs_file->addError(
			'PCRE pattern uses "$" without the "D" modifier, which allows a trailing newline; use "\\z" or add the "D" modifier.',
			$pattern_ptr,
			'UnanchoredEndDollar'
		);
	}

	/**
	 * Determine whether an unqualified function name is imported via `use function` to a
	 * non-global target (or a different global function) in the active namespace scope.
	 *
	 * @param File   $phpcs_file The file being checked.
	 * @param int    $stack_ptr  Call identifier token position.
	 * @param string $name       Lowercased function name.
	 * @return bool
	 */
	private function is_aliased_to_non_global_function( File $phpcs_file, int $stack_ptr, string $name ): bool {
		$tokens          = $phpcs_file->getTokens();
		$active_ns_token = null;
		$scope_start     = 0;

		if ( isset( $tokens[ $stack_ptr ]['conditions'] ) && is_array( $tokens[ $stack_ptr ]['conditions'] ) ) {
			foreach ( $tokens[ $stack_ptr ]['conditions'] as $cond_ptr => $cond_code ) {
				if ( T_NAMESPACE === $cond_code ) {
					$active_ns_token = (int) $cond_ptr;
					$scope_start     = (int) $cond_ptr;
				}
			}
		}

		if ( null === $active_ns_token ) {
			for ( $i = $stack_ptr - 1; $i >= 0; --$i ) {
				if ( T_NAMESPACE !== $tokens[ $i ]['code'] ) {
					continue;
				}
				$after_ns = $this->find_non_empty( $tokens, $i + 1, 1 );
				if ( null !== $after_ns && T_NS_SEPARATOR === $tokens[ $after_ns ]['code'] ) {
					continue;
				}
				$scope_start = $i;
				break;
			}
		}

		for ( $i = $scope_start; $i < $stack_ptr; ++$i ) {
			if ( T_USE !== $tokens[ $i ]['code'] ) {
				continue;
			}
			if ( ! UseStatements::isImportUse( $phpcs_file, $i ) ) {
				continue;
			}
			if ( null !== $active_ns_token ) {
				$use_conditions = isset( $tokens[ $i ]['conditions'] ) && is_array( $tokens[ $i ]['conditions'] )
					? $tokens[ $i ]['conditions']
					: array();
				if ( ! isset( $use_conditions[ $active_ns_token ] ) ) {
					continue;
				}
			} elseif ( isset( $tokens[ $i ]['conditions'] ) && is_array( $tokens[ $i ]['conditions'] ) && array() !== $tokens[ $i ]['conditions'] ) {
				continue;
			}

			$split = UseStatements::splitImportUseStatement( $phpcs_file, $i );
			foreach ( $split['function'] as $alias => $target ) {
				if ( strtolower( $alias ) !== $name ) {
					continue;
				}
				$normalized_target = strtolower( ltrim( $target, '\\' ) );
				if ( $normalized_target !== $name ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Extract one complete constant string literal (including physically multiline tokens)
	 * from a parameter range.
	 *
	 * @param File $phpcs_file The file being checked.
	 * @param int  $start      First token in the parameter.
	 * @param int  $end        Last token in the parameter.
	 * @return array{ptr:int,raw:string}|null
	 */
	private function extract_single_literal( File $phpcs_file, int $start, int $end ): ?array {
		$tokens          = $phpcs_file->getTokens();
		$first           = null;
		$last_str        = null;
		$seen_binary_pfx = false;
		$raw             = '';

		for ( $i = $start; $i <= $end; ++$i ) {
			if ( T_CONSTANT_ENCAPSED_STRING === $tokens[ $i ]['code'] ) {
				if ( null === $first ) {
					$first = $i;
				} elseif ( $last_str + 1 !== $i ) {
					return null;
				}
				$last_str = $i;
				$raw     .= $tokens[ $i ]['content'];
				continue;
			}
			if ( null === $first && ! $seen_binary_pfx && 'T_BINARY_CAST' === $tokens[ $i ]['type'] ) {
				$seen_binary_pfx = true;
				continue;
			}
			if ( $this->is_empty_token( $tokens[ $i ] ) ) {
				continue;
			}
			return null;
		}

		if ( null === $first ) {
			return null;
		}

		return array(
			'ptr' => $first,
			'raw' => $raw,
		);
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
	 * Decode a PHP single- or double-quoted constant string literal in one pass.
	 *
	 * @param string $raw Raw token content including surrounding quotes.
	 * @return string
	 */
	private function decode_literal( string $raw ): string {
		if ( strlen( $raw ) >= 2 && ( 'b' === $raw[0] || 'B' === $raw[0] ) && ( "'" === $raw[1] || '"' === $raw[1] ) ) {
			$raw = substr( $raw, 1 );
		}
		$unquoted = TextStrings::stripQuotes( $raw );
		$length   = strlen( $unquoted );
		if ( '' === $raw || "'" === $raw[0] ) {
			$result = '';
			for ( $i = 0; $i < $length; ++$i ) {
				if ( '\\' === $unquoted[ $i ] && $i + 1 < $length && ( '\\' === $unquoted[ $i + 1 ] || "'" === $unquoted[ $i + 1 ] ) ) {
					$result .= $unquoted[ $i + 1 ];
					++$i;
					continue;
				}
				$result .= $unquoted[ $i ];
			}
			return $result;
		}

		$simple_escapes = array(
			'n'  => "\n",
			'r'  => "\r",
			't'  => "\t",
			'v'  => "\v",
			'e'  => "\e",
			'f'  => "\f",
			'\\' => '\\',
			'"'  => '"',
			'$'  => '$',
		);

		$result = '';
		for ( $i = 0; $i < $length; ++$i ) {
			if ( '\\' !== $unquoted[ $i ] || $i + 1 >= $length ) {
				$result .= $unquoted[ $i ];
				continue;
			}
			$next = $unquoted[ $i + 1 ];
			if ( isset( $simple_escapes[ $next ] ) ) {
				$result .= $simple_escapes[ $next ];
				++$i;
				continue;
			}
			if ( ( 'x' === $next || 'X' === $next ) && $i + 2 < $length && ctype_xdigit( $unquoted[ $i + 2 ] ) ) {
				$hex = $unquoted[ $i + 2 ];
				++$i;
				if ( $i + 2 < $length && ctype_xdigit( $unquoted[ $i + 2 ] ) ) {
					$hex .= $unquoted[ $i + 2 ];
					++$i;
				}
				++$i;
				$result .= chr( (int) hexdec( $hex ) );
				continue;
			}
			if ( $next >= '0' && $next <= '7' ) {
				$oct = $next;
				++$i;
				for ( $k = 0; $k < 2 && $i + 1 < $length; ++$k ) {
					$digit = $unquoted[ $i + 1 ];
					if ( $digit < '0' || $digit > '7' ) {
						break;
					}
					$oct .= $digit;
					++$i;
				}
				$result .= chr( (int) octdec( $oct ) & 0xFF );
				continue;
			}
			if ( 'u' === $next && $i + 2 < $length && '{' === $unquoted[ $i + 2 ] ) {
				$close_brace = strpos( $unquoted, '}', $i + 3 );
				if ( false !== $close_brace ) {
					$hex = substr( $unquoted, $i + 3, $close_brace - ( $i + 3 ) );
					if ( '' !== $hex && ctype_xdigit( $hex ) ) {
						$codepoint = (int) hexdec( $hex );
						$encoded   = $this->encode_utf8_codepoint( $codepoint );
						if ( null !== $encoded ) {
							$result .= $encoded;
							$i       = $close_brace;
							continue;
						}
					}
				}
			}
			$result .= '\\' . $next;
			++$i;
		}

		return $result;
	}

	/**
	 * Encode a Unicode scalar value to UTF-8 bytes without requiring ext-mbstring.
	 *
	 * @param int $codepoint Unicode code point.
	 * @return string|null
	 */
	private function encode_utf8_codepoint( int $codepoint ): ?string {
		if ( $codepoint < 0 || $codepoint > 0x10FFFF || ( $codepoint >= 0xD800 && $codepoint <= 0xDFFF ) ) {
			return null;
		}
		if ( $codepoint <= 0x7F ) {
			return chr( $codepoint );
		}
		if ( $codepoint <= 0x7FF ) {
			return chr( 0xC0 | ( $codepoint >> 6 ) ) . chr( 0x80 | ( $codepoint & 0x3F ) );
		}
		if ( $codepoint <= 0xFFFF ) {
			return chr( 0xE0 | ( $codepoint >> 12 ) )
				. chr( 0x80 | ( ( $codepoint >> 6 ) & 0x3F ) )
				. chr( 0x80 | ( $codepoint & 0x3F ) );
		}

		return chr( 0xF0 | ( $codepoint >> 18 ) )
			. chr( 0x80 | ( ( $codepoint >> 12 ) & 0x3F ) )
			. chr( 0x80 | ( ( $codepoint >> 6 ) & 0x3F ) )
			. chr( 0x80 | ( $codepoint & 0x3F ) );
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
	 * Detect an active non-multiline "$" anchor while respecting escapes, \Q...\E,
	 * character classes (including leading "]" and POSIX classes), comments, newline verbs,
	 * pattern-bearing (*keyword:...) groups, and inline modifiers.
	 *
	 * @param string $body              PCRE pattern body without delimiters.
	 * @param bool   $initial_multiline Whether trailing /m is active initially.
	 * @param bool   $initial_extended  Whether trailing /x is active initially.
	 * @param bool   $initial_utf       Whether trailing /u is active initially.
	 * @return bool
	 */
	private function has_active_single_line_dollar( string $body, bool $initial_multiline, bool $initial_extended, bool $initial_utf ): bool {
		$length         = strlen( $body );
		$in_quote       = false;
		$in_class       = false;
		$class_first    = false;
		$class_negation = false;
		$newline_mode   = 'LF';
		$utf            = $initial_utf;
		/** @var array<int, array{m:bool,x:bool}> $stack */
		$stack = array(
			array(
				'm' => $initial_multiline,
				'x' => $initial_extended,
			),
		);

		for ( $i = 0; $i < $length; ++$i ) {
			$char = $body[ $i ];

			if ( $in_quote ) {
				if ( '\\' === $char && $i + 1 < $length && 'E' === $body[ $i + 1 ] ) {
					$in_quote = false;
					++$i;
				}
				continue;
			}

			if ( $in_class ) {
				if ( '\\' === $char ) {
					if ( $i + 1 < $length && 'Q' === $body[ $i + 1 ] ) {
						$in_quote       = true;
						$class_first    = false;
						$class_negation = false;
						++$i;
						continue;
					}
					if ( $i + 2 < $length && 'c' === $body[ $i + 1 ] ) {
						$class_first    = false;
						$class_negation = false;
						$i             += 2;
						continue;
					}
					$class_first    = false;
					$class_negation = false;
					++$i;
					continue;
				}
				if ( $class_negation && '^' === $char ) {
					$class_negation = false;
					continue;
				}
				$was_first      = $class_first;
				$class_first    = false;
				$class_negation = false;
				if ( '[' === $char && $i + 1 < $length && ( ':' === $body[ $i + 1 ] || '.' === $body[ $i + 1 ] || '=' === $body[ $i + 1 ] ) ) {
					$marker = $body[ $i + 1 ];
					$end    = strpos( $body, $marker . ']', $i + 2 );
					if ( false !== $end ) {
						$i = $end + 1;
						continue;
					}
				}
				if ( ']' === $char && ! $was_first ) {
					$in_class = false;
				}
				continue;
			}

			if ( '\\' === $char ) {
				if ( $i + 1 < $length && 'Q' === $body[ $i + 1 ] ) {
					$in_quote = true;
					++$i;
					continue;
				}
				if ( $i + 2 < $length && 'c' === $body[ $i + 1 ] ) {
					$i += 2;
					continue;
				}
				++$i;
				continue;
			}

			if ( '[' === $char ) {
				$in_class       = true;
				$class_first    = true;
				$class_negation = true;
				continue;
			}

			$top_index = count( $stack ) - 1;
			$current   = $stack[ $top_index ];

			if ( '#' === $char && $current['x'] ) {
				$comment_end = $this->find_extended_comment_end( $body, $i + 1, $newline_mode, $utf );
				if ( null === $comment_end ) {
					break;
				}
				$i = $comment_end;
				continue;
			}

			if ( '(' === $char ) {
				if ( $i + 1 < $length && '*' === $body[ $i + 1 ] ) {
					$colon = strpos( $body, ':', $i + 2 );
					$close = strpos( $body, ')', $i + 2 );
					if ( false !== $colon && ( false === $close || $colon < $close ) ) {
						$keyword = substr( $body, $i + 2, $colon - ( $i + 2 ) );
						if ( isset( self::PATTERN_STAR_GROUPS[ $keyword ] ) ) {
							$stack[] = $current;
							$i       = $colon;
							continue;
						}
					}
					if ( false === $close ) {
						break;
					}
					$verb = substr( $body, $i + 2, $close - ( $i + 2 ) );
					if ( 'CR' === $verb || 'LF' === $verb || 'CRLF' === $verb || 'ANYCRLF' === $verb || 'ANY' === $verb ) {
						$newline_mode = $verb;
					} elseif ( 'UTF' === $verb ) {
						$utf = true;
					}
					$i = $close;
					continue;
				}
				if ( $i + 2 < $length && '?' === $body[ $i + 1 ] && '#' === $body[ $i + 2 ] ) {
					$comment_end = strpos( $body, ')', $i + 3 );
					if ( false === $comment_end ) {
						break;
					}
					$i = $comment_end;
					continue;
				}

				$inline = $this->parse_inline_modifiers( $body, $i, $current );
				if ( null !== $inline ) {
					if ( $inline['scoped'] ) {
						$stack[] = $inline['state'];
					} else {
						$stack[ $top_index ] = $inline['state'];
					}
					$i = $inline['end'];
					continue;
				}

				$stack[] = $current;
				continue;
			}

			if ( ')' === $char ) {
				if ( count( $stack ) > 1 ) {
					array_pop( $stack );
				}
				continue;
			}

			if ( '$' === $char && ! $current['m'] ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Find the byte index of the end of an extended-mode (/x) "#" comment under the active
	 * PCRE2 newline convention, returning null if the comment runs to the end of the pattern.
	 *
	 * @param string $body         Pattern body.
	 * @param int    $from         First byte after "#".
	 * @param string $newline_mode Active PCRE2 newline convention (LF, CR, CRLF, ANYCRLF, ANY).
	 * @param bool   $utf          Whether UTF mode (/u or (*UTF)) is active.
	 * @return int|null
	 */
	private function find_extended_comment_end( string $body, int $from, string $newline_mode, bool $utf ): ?int {
		if ( 'LF' === $newline_mode ) {
			$pos = strpos( $body, "\n", $from );
			return false === $pos ? null : $pos;
		}
		if ( 'CR' === $newline_mode ) {
			$pos = strpos( $body, "\r", $from );
			return false === $pos ? null : $pos;
		}
		if ( 'CRLF' === $newline_mode ) {
			$pos = strpos( $body, "\r\n", $from );
			return false === $pos ? null : $pos + 1;
		}

		$candidates = array( "\n", "\r" );
		if ( 'ANY' === $newline_mode ) {
			$candidates[] = "\x0B";
			$candidates[] = "\x0C";
			if ( $utf ) {
				$candidates[] = "\xC2\x85";
				$candidates[] = "\xE2\x80\xA8";
				$candidates[] = "\xE2\x80\xA9";
			} else {
				$candidates[] = "\x85";
			}
		}

		$best = null;
		foreach ( $candidates as $seq ) {
			$pos = strpos( $body, $seq, $from );
			if ( false === $pos ) {
				continue;
			}
			$end = $pos + strlen( $seq ) - 1;
			if ( null === $best || $pos < $best['pos'] ) {
				$best = array(
					'pos' => $pos,
					'end' => $end,
				);
			}
		}

		return null === $best ? null : $best['end'];
	}

	/**
	 * Parse a PCRE inline modifier directive (?imsx-imsx) or scoped group (?imsx-imsx:...).
	 *
	 * @param string              $body    Pattern body.
	 * @param int                 $pos     Index of opening "(".
	 * @param array{m:bool,x:bool} $current Active option state.
	 * @return array{scoped:bool,state:array{m:bool,x:bool},end:int}|null
	 */
	private function parse_inline_modifiers( string $body, int $pos, array $current ): ?array {
		$length = strlen( $body );
		if ( $pos + 2 >= $length || '?' !== $body[ $pos + 1 ] ) {
			return null;
		}

		$cursor = $pos + 2;
		$state  = $current;
		$enable = true;
		$seen   = false;

		if ( '^' === $body[ $cursor ] ) {
			$state['m'] = false;
			$state['x'] = false;
			++$cursor;
			$seen = true;
		}

		while ( $cursor < $length ) {
			$ch = $body[ $cursor ];
			if ( '-' === $ch ) {
				if ( ! $enable ) {
					return null;
				}
				$enable = false;
				++$cursor;
				continue;
			}
			if ( false !== strpos( 'imsxUJnr', $ch ) ) {
				$seen = true;
				if ( 'm' === $ch ) {
					$state['m'] = $enable;
				} elseif ( 'x' === $ch ) {
					$state['x'] = $enable;
				}
				++$cursor;
				continue;
			}
			if ( ')' === $ch && $seen ) {
				return array(
					'scoped' => false,
					'state'  => $state,
					'end'    => $cursor,
				);
			}
			if ( ':' === $ch && $seen ) {
				return array(
					'scoped' => true,
					'state'  => $state,
					'end'    => $cursor,
				);
			}
			return null;
		}

		return null;
	}
}
