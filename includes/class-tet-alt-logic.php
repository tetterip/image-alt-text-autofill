<?php
/**
 * Pure logic, no WordPress calls (unit tested in tests/test-logic.php).
 *
 * @package Tet_Smart_Alt_Text
 */

defined( 'ABSPATH' ) || exit;

/**
 * Filename cleaning, templates, alt sanitising and <img> tag helpers.
 */
class Tet_Alt_Logic {

	/** Template tags, in the order they are documented on the settings page. */
	const TAGS = array( 'filename', 'title', 'caption', 'parent', 'site' );

	/** Separators that are dropped when the tag next to them is empty. */
	const SEPARATORS = '-–—|·:,';

	/**
	 * Turns an image file name into words: "red-leather_sofa-2-1024x768.jpg" → "Red leather sofa".
	 * Returns '' for names that carry no meaning (IMG_1234, DSC00012, hashes, dates, screenshots).
	 *
	 * @param string $name File name or path.
	 */
	public static function clean_filename( string $name ): string {
		$name = basename( str_replace( '\\', '/', $name ) );
		$name = preg_replace( '/\.[a-z0-9]{2,5}$/i', '', $name );
		$name = rawurldecode( (string) $name );

		// WordPress suffixes: -1024x768, -scaled, -rotated, -e1712345678 (edited), -150x150.
		do {
			$before = $name;
			$name   = preg_replace( '/[-_](\d+x\d+|scaled|rotated|e\d{10,13})$/i', '', (string) $name );
		} while ( $name !== $before );

		// Duplicate-upload counter: "sofa-2", "sofa (1)", "sofa copy".
		$name = preg_replace( '/(\s*\(\d+\)|[-_ ]copy|[-_]\d)$/i', '', (string) $name );

		// camelCase / PascalCase → words (Latin only).
		$plain = trim( (string) preg_replace( '/[-_.+~\s]+/u', ' ', (string) $name ) );
		$name  = preg_replace( '/(?<=[a-z])(?=[A-Z])/', ' ', (string) $name );
		$name  = preg_replace( '/[-_.+~]+/', ' ', (string) $name );
		$name  = trim( (string) preg_replace( '/\s+/u', ' ', (string) $name ) );

		if ( self::is_meaningless( $plain ) || self::is_meaningless( $name ) ) {
			return '';
		}
		return self::ucfirst( $name );
	}

	/**
	 * Whether a cleaned file name says nothing about the picture.
	 *
	 * @param string $words Words from clean_filename().
	 */
	public static function is_meaningless( string $words ): bool {
		$w = strtolower( trim( $words ) );
		if ( '' === $w ) {
			return true;
		}
		// Fewer than two letters in any script.
		if ( preg_match_all( '/\p{L}/u', $w ) < 2 ) {
			return true;
		}
		// Camera / phone / screenshot names, optionally followed by numbers or dates.
		$camera = '(img|image|dsc|dscn|dscf|dcim|pxl|mvimg|photo|pic|picture|screenshot|screen shot|capture|scan|whatsapp image|signal|untitled|unnamed|download|file|wp|gopr|p|vid)';
		if ( preg_match( '/^' . $camera . '[\s\d]*(at|pm|am|[\s\d])*$/', $w ) ) {
			return true;
		}
		// Hashes / UUIDs: long runs of hex mixed with digits, or no vowels with digits.
		$compact = str_replace( ' ', '', $w );
		if ( strlen( $compact ) >= 8 && preg_match( '/^[0-9a-f]+$/', $compact ) && preg_match( '/\d/', $compact ) ) {
			return true;
		}
		if ( preg_match( '/\d/', $compact ) && ! preg_match( '/[aeiouy\x{0370}-\x{03FF}\x{0400}-\x{04FF}]/u', $compact ) ) {
			return true;
		}
		return false;
	}

	/**
	 * Fills a template such as "{title} - {site}". A separator next to an empty tag is dropped,
	 * so "{caption} - {site}" with no caption gives "My Shop", not "- My Shop".
	 *
	 * @param string               $template Template with {tag} placeholders.
	 * @param array<string,string> $vars     Tag => value.
	 */
	public static function apply_template( string $template, array $vars ): string {
		$sep     = preg_quote( self::SEPARATORS, '/' );
		$parts   = preg_split( '/(\{[a-z_]+\})/', $template, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY );
		$result  = '';
		$pending = '';
		foreach ( (array) $parts as $part ) {
			if ( preg_match( '/^\{([a-z_]+)\}$/', $part, $m ) ) {
				$value = isset( $vars[ $m[1] ] ) ? trim( (string) $vars[ $m[1] ] ) : '';
			} elseif ( preg_match( '/^[\s' . $sep . ']*$/u', $part ) ) {
				// Pure separator: kept only between two non-empty values.
				$pending = $part;
				continue;
			} else {
				$value = $part;
			}
			if ( '' === $value ) {
				continue;
			}
			$result .= ( '' !== $result ? $pending : '' ) . $value;
			$pending = '';
		}
		$result = preg_replace( '/\s+/u', ' ', $result );
		return trim( (string) $result, " \t\n\r\0\x0B" . self::SEPARATORS );
	}

	/**
	 * Cleans text from a model or a template into a usable alt text.
	 *
	 * @param string $text Raw text.
	 * @param int    $max  Maximum length in characters (0 = no limit).
	 */
	public static function sanitize_alt( string $text, int $max = 0 ): string {
		$text = html_entity_decode( strip_tags( $text ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text = trim( (string) preg_replace( '/\s+/u', ' ', $text ) );

		// Labels and wrappers models sometimes add.
		$text = preg_replace( '/^(alt(ernative)?[\s-]*text|alt|description|περιγραφή|εναλλακτικό κείμενο)\s*[:：]\s*/iu', '', $text );
		$text = trim( (string) $text, " \t\"'`“”‘’«»*" );
		$text = preg_replace( '/^(an?\s+)?(image|picture|photo|photograph)\s+(of|showing)\s+/i', '', (string) $text );
		$text = preg_replace( '/^(μια\s+|μία\s+)?(εικόνα|φωτογραφία)\s+(με|που δείχνει|από)\s+/iu', '', (string) $text );
		$text = self::ucfirst( trim( (string) $text ) );

		if ( $max > 0 && self::length( $text ) > $max ) {
			$cut = self::substr( $text, 0, $max );
			$sp  = self::strrpos_space( $cut );
			$at  = self::substr( $text, $max, 1 );
			if ( ' ' !== $at && $sp > (int) ( $max * 0.6 ) ) {
				$cut = self::substr( $cut, 0, $sp );
			}
			$text = rtrim( $cut, " ,;:-–—" );
		}
		return $text;
	}

	/**
	 * Whether an <img> tag lacks a useful alt.
	 *
	 * @param string $tag              The <img …> tag.
	 * @param bool   $empty_is_missing Treat alt="" as missing (otherwise it is respected as decorative).
	 */
	public static function img_needs_alt( string $tag, bool $empty_is_missing ): bool {
		$alt = self::img_attr( $tag, 'alt' );
		if ( null === $alt ) {
			return true;
		}
		return $empty_is_missing && '' === trim( html_entity_decode( $alt, ENT_QUOTES, 'UTF-8' ) );
	}

	/**
	 * Reads an attribute from an HTML tag; null when absent.
	 *
	 * @param string $tag  HTML tag.
	 * @param string $name Attribute name.
	 */
	public static function img_attr( string $tag, string $name ): ?string {
		$re = '/\s' . preg_quote( $name, '/' ) . '\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+))/i';
		if ( ! preg_match( $re, $tag, $m ) ) {
			return preg_match( '/\s' . preg_quote( $name, '/' ) . '(?=[\s\/>])/i', $tag ) ? '' : null;
		}
		if ( isset( $m[3] ) && '' !== $m[3] ) {
			return $m[3];
		}
		if ( isset( $m[2] ) && '' !== $m[2] ) {
			return $m[2];
		}
		return $m[1];
	}

	/**
	 * Sets (or replaces) the alt attribute of an <img> tag.
	 *
	 * @param string $tag The <img …> tag.
	 * @param string $alt Plain-text alt.
	 */
	public static function img_set_alt( string $tag, string $alt ): string {
		$attr = 'alt="' . htmlspecialchars( $alt, ENT_QUOTES, 'UTF-8' ) . '"';
		$re   = '/\salt(\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s>]+))?(?=[\s\/>])/i';
		if ( preg_match( $re, $tag ) ) {
			return (string) preg_replace( $re, ' ' . $attr, $tag, 1 );
		}
		return (string) preg_replace( '/^<img\b/i', '<img ' . $attr, $tag, 1 );
	}

	/**
	 * A human-readable language name for a locale, for the AI prompt.
	 *
	 * @param string $locale WordPress locale, e.g. el or el_GR.
	 */
	public static function language_name( string $locale ): string {
		$names = array(
			'el' => 'Greek',
			'en' => 'English',
			'de' => 'German',
			'fr' => 'French',
			'it' => 'Italian',
			'es' => 'Spanish',
			'pt' => 'Portuguese',
			'nl' => 'Dutch',
			'pl' => 'Polish',
			'ro' => 'Romanian',
			'bg' => 'Bulgarian',
			'cs' => 'Czech',
			'sv' => 'Swedish',
			'da' => 'Danish',
			'fi' => 'Finnish',
			'tr' => 'Turkish',
			'ru' => 'Russian',
			'uk' => 'Ukrainian',
			'ar' => 'Arabic',
			'he' => 'Hebrew',
			'ja' => 'Japanese',
			'zh' => 'Chinese',
			'ko' => 'Korean',
		);
		$lang  = strtolower( (string) strtok( $locale, '_-' ) );
		return isset( $names[ $lang ] ) ? $names[ $lang ] . ' (' . $locale . ')' : $locale;
	}

	/**
	 * Which alt texts a run may write, given the current alt and who wrote it.
	 *
	 * @param string $scope   missing | auto (missing + our template ones) | all.
	 * @param string $current Current alt text.
	 * @param string $source  Our meta: '' (someone else / a person), template, ai.
	 */
	public static function may_write( string $scope, string $current, string $source ): bool {
		if ( '' === trim( $current ) ) {
			return true;
		}
		if ( 'all' === $scope ) {
			return true;
		}
		return 'auto' === $scope && 'template' === $source;
	}

	/* ── Multibyte helpers (mbstring is optional on some hosts) ── */

	private static function length( string $s ): int {
		return function_exists( 'mb_strlen' ) ? mb_strlen( $s, 'UTF-8' ) : (int) preg_match_all( '/./us', $s );
	}

	private static function substr( string $s, int $start, int $len ): string {
		if ( function_exists( 'mb_substr' ) ) {
			return mb_substr( $s, $start, $len, 'UTF-8' );
		}
		preg_match_all( '/./us', $s, $m );
		return implode( '', array_slice( $m[0], $start, $len ) );
	}

	private static function strrpos_space( string $s ): int {
		$pos = function_exists( 'mb_strrpos' ) ? mb_strrpos( $s, ' ', 0, 'UTF-8' ) : false;
		if ( false === $pos && ! function_exists( 'mb_strrpos' ) ) {
			$chars = preg_split( '//u', $s, -1, PREG_SPLIT_NO_EMPTY );
			$found = array_keys( (array) $chars, ' ', true );
			$pos   = $found ? end( $found ) : false;
		}
		return false === $pos ? -1 : (int) $pos;
	}

	private static function ucfirst( string $s ): string {
		if ( '' === $s ) {
			return '';
		}
		$first = self::substr( $s, 0, 1 );
		$upper = function_exists( 'mb_strtoupper' ) ? mb_strtoupper( $first, 'UTF-8' ) : strtoupper( $first );
		return $upper . self::substr( $s, 1, self::length( $s ) );
	}
}
