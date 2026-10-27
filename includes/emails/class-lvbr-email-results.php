<?php
/**
 * "Final results" email.
 *
 * @package Virtual_Balloon_Race
 */

defined( 'ABSPATH' ) || exit;

/**
 * Sent to each entrant when their race finishes, with each balloon's final position.
 */
class LVBR_Email_Results extends LVBR_Email_Race {

	/**
	 * Set up the email.
	 */
	public function __construct() {
		$this->id             = 'lvbr_balloon_results';
		$this->title          = __( 'Balloon race: final results', 'lumaha-virtual-balloon-race' );
		$this->description    = __( 'Sent to everyone with a balloon in a race when the race finishes, with each balloon’s final position and distance.', 'lumaha-virtual-balloon-race' );
		$this->template_html  = 'emails/lvbr-balloon-results.php';
		$this->template_plain = 'emails/plain/lvbr-balloon-results.php';

		parent::__construct();
	}

	/**
	 * Default subject.
	 *
	 * @return string
	 */
	public function get_default_subject() {
		return __( 'Final results: {race_name}', 'lumaha-virtual-balloon-race' );
	}

	/**
	 * Default heading.
	 *
	 * @return string
	 */
	public function get_default_heading() {
		return __( 'The race is over!', 'lumaha-virtual-balloon-race' );
	}

	/**
	 * Default text under the results.
	 *
	 * @return string
	 */
	public function get_default_additional_content() {
		return __( 'Thank you for taking part and supporting our fundraiser!', 'lumaha-virtual-balloon-race' );
	}

	/**
	 * How a balloon finished, in words.
	 *
	 * @param array  $balloon Balloon from LVBR_Race_Emails::balloons_for().
	 * @param object $race    Race.
	 * @return string
	 */
	public static function result_text( $balloon, $race ) {
		$km = number_format_i18n( $balloon['distance'], 1 );

		if ( $balloon['burst'] ) {
			return LVBR_Races::FROM_START === LVBR_Races::ranking( $race )
				/* translators: %s: distance in km */
				? sprintf( __( 'Burst %s km from launch', 'lumaha-virtual-balloon-race' ), $km )
				/* translators: %s: distance in km */
				: sprintf( __( 'Burst after %s km', 'lumaha-virtual-balloon-race' ), $km );
		}

		$text = LVBR_Races::FROM_START === LVBR_Races::ranking( $race )
			/* translators: 1: position, 2: number of balloons, 3: distance in km */
			? sprintf( __( '#%1$s of %2$s · %3$s km from launch', 'lumaha-virtual-balloon-race' ), number_format_i18n( (int) $balloon['rank'] ), number_format_i18n( $balloon['entries'] ), $km )
			/* translators: 1: position, 2: number of balloons, 3: distance in km */
			: sprintf( __( '#%1$s of %2$s · %3$s km travelled', 'lumaha-virtual-balloon-race' ), number_format_i18n( (int) $balloon['rank'] ), number_format_i18n( $balloon['entries'] ), $km );

		if ( $balloon['team'] && $balloon['team_rank'] ) {
			/* translators: 1: team name, 2: team position, 3: number of teams */
			$text .= ' · ' . sprintf( __( '%1$s finished #%2$s of %3$s teams', 'lumaha-virtual-balloon-race' ), $balloon['team'], number_format_i18n( (int) $balloon['team_rank'] ), number_format_i18n( $balloon['teams'] ) );
		}

		return $text;
	}
}
