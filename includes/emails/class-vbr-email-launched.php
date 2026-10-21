<?php
/**
 * "Your balloon has launched" email.
 *
 * @package Virtual_Balloon_Race
 */

defined( 'ABSPATH' ) || exit;

/**
 * Sent to each entrant when their race starts flying.
 */
class VBR_Email_Launched extends VBR_Email_Race {

	/**
	 * Set up the email.
	 */
	public function __construct() {
		$this->id             = 'vbr_balloon_launched';
		$this->title          = __( 'Balloon race: launched', 'virtual-balloon-race' );
		$this->description    = __( 'Sent to everyone with a balloon in a race when the race launches, with a link to track each balloon.', 'virtual-balloon-race' );
		$this->template_html  = 'emails/vbr-balloon-launched.php';
		$this->template_plain = 'emails/plain/vbr-balloon-launched.php';

		parent::__construct();
	}

	/**
	 * Default subject.
	 *
	 * @return string
	 */
	public function get_default_subject() {
		return __( 'Your balloon has launched in {race_name}!', 'virtual-balloon-race' );
	}

	/**
	 * Default heading.
	 *
	 * @return string
	 */
	public function get_default_heading() {
		return __( 'Up, up and away!', 'virtual-balloon-race' );
	}

	/**
	 * Default text under the balloons.
	 *
	 * @return string
	 */
	public function get_default_additional_content() {
		return __( 'Thank you for supporting our fundraiser. Check back on the map to see how far your balloon gets!', 'virtual-balloon-race' );
	}
}
