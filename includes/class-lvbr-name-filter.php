<?php
/**
 * Rude-word check for balloon names shown on the map and leaderboards.
 *
 * Runs on this site only: nothing is sent to an outside service.
 *
 * @package Virtual_Balloon_Race
 */

defined( 'ABSPATH' ) || exit;

/**
 * Checks names against a word list.
 *
 * List entries match whole words by default. A `*` widens the match:
 * `word*` matches words starting with it, `*word` words ending with it,
 * and `*word*` the letters anywhere in a word.
 */
class LVBR_Name_Filter {

	/**
	 * Built-in list: common English swear words and slurs.
	 *
	 * Kept short and whole-word where it can be, so ordinary names (Scunthorpe,
	 * Moby Dick, Blue Tit, Cockermouth) are not caught. Admins add more in Settings.
	 *
	 * @return string[]
	 */
	public static function default_words() {
		$words = array(
			'*fuck*', '*cunt*', '*nigger*', '*nigga*', 'shit*', '*shit', 'bastard*', 'bitch*', 'bollock*',
			'wank*', 'twat*', 'slut*', 'whore*', 'dickhead*', 'arsehole*', 'asshole*', 'cocksuck*', 'retard*',
			'faggot*', 'tosser*', 'knobhead*', 'bellend*', 'jizz*', 'spunk*', 'paedo*', 'pedo', 'pedos', 'rapist*',
			'ass', 'arse', 'arses', 'cock', 'cocks', 'prick', 'pricks', 'tits', 'titty',
			'titties', 'piss', 'pissed', 'pisser', 'pissing', 'pussy', 'pussies', 'minge', 'nonce', 'cum',
			'porn', 'porno', 'rape', 'raped', 'raping', 'nazi', 'nazis', 'hitler', 'fag', 'fags', 'poof',
			'poofs', 'poofter', 'spaz', 'spastic', 'paki', 'pakis', 'chink', 'chinks', 'coon', 'coons', 'kike',
			'kikes', 'spic', 'spics', 'wog', 'wogs', 'gook', 'gooks', 'tranny', 'trannies', 'shemale', 'gypo',
			'pikey', 'pikeys',
		);

		/**
		 * Filter the built-in blocked words.
		 *
		 * @param string[] $words List entries (see the class comment for `*`).
		 */
		return (array) apply_filters( 'lvbr_name_filter_default_words', $words );
	}

	/**
	 * Words that are never blocked, even when part of one matches.
	 *
	 * @return string[]
	 */
	private static function default_allowed() {
		return array( 'scunthorpe', 'cockermouth', 'cockburn', 'cockney', 'cockerel', 'cockpit', 'shitake', 'shittim' );
	}

	/**
	 * Is the check on?
	 *
	 * @return bool
	 */
	public static function enabled() {
		return (bool) LVBR_Settings::get( 'name_filter' );
	}

	/**
	 * The words in use: built-in (unless turned off) plus the admin's own.
	 *
	 * @return string[]
	 */
	public static function blocked_words() {
		$words = LVBR_Settings::get( 'name_filter_builtin' ) ? self::default_words() : array();
		$words = array_merge( $words, self::parse_list( (string) LVBR_Settings::get( 'name_filter_words' ) ) );

		/**
		 * Filter every blocked word in use.
		 *
		 * @param string[] $words List entries (see the class comment for `*`).
		 */
		$words = (array) apply_filters( 'lvbr_name_filter_words', $words );

		return array_values( array_unique( array_filter( array_map( array( __CLASS__, 'normalise_entry' ), $words ) ) ) );
	}

	/**
	 * Words the admin has allowed.
	 *
	 * @return string[]
	 */
	public static function allowed_words() {
		$words = array_merge( self::default_allowed(), self::parse_list( (string) LVBR_Settings::get( 'name_filter_allow' ) ) );

		return array_values( array_unique( array_filter( array_map( array( __CLASS__, 'normalise_word' ), $words ) ) ) );
	}

	/**
	 * Does a name contain a blocked word? Always false when the check is off.
	 *
	 * @param string $name Name as entered.
	 * @return bool
	 */
	public static function is_blocked( $name ) {
		if ( ! self::enabled() ) {
			return false;
		}

		$blocked = '' !== self::find( (string) $name, self::blocked_words(), self::allowed_words() );

		/**
		 * Filter whether a name is blocked.
		 *
		 * @param bool   $blocked Whether a blocked word was found.
		 * @param string $name    Name as entered.
		 */
		return (bool) apply_filters( 'lvbr_name_is_blocked', $blocked, (string) $name );
	}

	/**
	 * The first list entry a name matches, or '' if none.
	 *
	 * @param string   $name    Name as entered.
	 * @param string[] $words   Normalised list entries.
	 * @param string[] $allowed Normalised allowed words.
	 * @return string
	 */
	public static function find( $name, $words, $allowed = array() ) {
		foreach ( self::tokens( $name ) as $token ) {
			// "fuuuck" is checked as "fuck" too; only for longer entries so "as" isn't read as "ass".
			$squashed = preg_replace( '/(.)\1+/u', '$1', $token );

			if ( in_array( $token, $allowed, true ) || in_array( $squashed, $allowed, true ) ) {
				continue;
			}

			foreach ( $words as $entry ) {
				if ( self::matches( $token, $entry ) ) {
					return $entry;
				}

				$plain = preg_replace( '/(.)\1+/u', '$1', $entry );
				if ( $squashed !== $token && strlen( trim( $plain, '*' ) ) >= 4 && self::matches( $squashed, $plain ) ) {
					return $entry;
				}
			}
		}

		return '';
	}

	/**
	 * Split a name into normalised words.
	 *
	 * Look-alike characters are swapped back ("sh1t" → "shit"), punctuation inside
	 * a word is dropped ("f.u.c.k" → "fuck"), and runs of single letters are joined
	 * ("f u c k" → "fuck").
	 *
	 * @param string $name Name as entered.
	 * @return string[]
	 */
	public static function tokens( $name ) {
		$parts  = preg_split( '/[\s\-_\/,+]+/u', (string) $name, -1, PREG_SPLIT_NO_EMPTY );
		$tokens = array();
		$run    = '';

		foreach ( (array) $parts as $part ) {
			// A dotted word like "f.u.c.k" becomes single letters, joined below.
			foreach ( explode( '.', $part ) as $piece ) {
				$word = self::normalise_word( $piece );

				if ( '' === $word ) {
					continue;
				}

				if ( 1 === mb_strlen( $word ) ) {
					$run .= $word;
					continue;
				}

				if ( mb_strlen( $run ) > 1 ) {
					$tokens[] = $run;
				}
				$run      = '';
				$tokens[] = $word;
			}
		}

		if ( mb_strlen( $run ) > 1 ) {
			$tokens[] = $run;
		}

		return $tokens;
	}

	/**
	 * Lower-case a word, swap look-alikes and keep only letters.
	 *
	 * @param string $word Word.
	 * @return string
	 */
	public static function normalise_word( $word ) {
		$word = mb_strtolower( remove_accents( (string) $word ) );
		$word = strtr(
			$word,
			array(
				'0' => 'o',
				'1' => 'i',
				'3' => 'e',
				'4' => 'a',
				'5' => 's',
				'7' => 't',
				'8' => 'b',
				'@' => 'a',
				'$' => 's',
				'|' => 'i',
			)
		);

		return (string) preg_replace( '/[^a-z]/', '', $word );
	}

	/**
	 * Normalise a list entry, keeping a `*` at either end.
	 *
	 * @param string $entry List entry.
	 * @return string
	 */
	private static function normalise_entry( $entry ) {
		$entry  = trim( (string) $entry );
		$start  = 0 === strpos( $entry, '*' ) ? '*' : '';
		$end    = '' !== $entry && '*' === substr( $entry, -1 ) && '*' !== $entry ? '*' : '';
		$letter = self::normalise_word( trim( $entry, '*' ) );

		return '' === $letter ? '' : $start . $letter . $end;
	}

	/**
	 * Does one word match one list entry?
	 *
	 * @param string $token Normalised word.
	 * @param string $entry Normalised list entry.
	 * @return bool
	 */
	private static function matches( $token, $entry ) {
		$start = '*' === $entry[0];
		$end   = '*' === substr( $entry, -1 );
		$word  = trim( $entry, '*' );

		if ( '' === $word ) {
			return false;
		}

		if ( $start && $end ) {
			return false !== strpos( $token, $word );
		}

		if ( $end ) {
			return 0 === strpos( $token, $word );
		}

		if ( $start ) {
			return substr( $token, -strlen( $word ) ) === $word;
		}

		return $token === $word;
	}

	/**
	 * Read a word list typed by the admin: one per line, or separated by commas.
	 *
	 * @param string $text List.
	 * @return string[]
	 */
	public static function parse_list( $text ) {
		return array_values( array_filter( array_map( 'trim', preg_split( '/[\r\n,]+/', (string) $text ) ) ) );
	}

	/**
	 * Clean a word list from the Settings form: one entry per line, lower case, no duplicates.
	 *
	 * @param mixed $raw Submitted text.
	 * @return string
	 */
	public static function sanitize_list( $raw ) {
		$entries = array();

		foreach ( self::parse_list( sanitize_textarea_field( (string) $raw ) ) as $entry ) {
			$entry = self::normalise_entry( $entry );
			if ( '' !== $entry ) {
				$entries[ $entry ] = $entry;
			}
		}

		return implode( "\n", $entries );
	}
}
