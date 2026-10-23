<?php
/**
 * Shared base for the race emails to entrants.
 *
 * @package Virtual_Balloon_Race
 */

defined( 'ABSPATH' ) || exit;

/**
 * A WooCommerce customer email about a race, sent to one entrant with their balloons.
 *
 * Templates live in the plugin's templates/emails/ and can be overridden in a theme's
 * woocommerce/emails/ folder like any WooCommerce email.
 */
abstract class VBR_Email_Race extends WC_Email {

	/**
	 * Race the email is about.
	 *
	 * @var object|null
	 */
	public $race = null;

	/**
	 * The entrant's balloons, from VBR_Race_Emails::balloons_for().
	 *
	 * @var array[]
	 */
	public $balloons = array();

	/**
	 * Set up the shared parts.
	 */
	public function __construct() {
		$this->customer_email = true;
		$this->template_base  = VBR_PLUGIN_DIR . 'templates/';
		$this->placeholders   = array_merge(
			$this->placeholders,
			array(
				'{race_name}'  => '',
				'{first_name}' => '',
			)
		);

		parent::__construct();
	}

	/**
	 * Send to one entrant.
	 *
	 * @param object  $race     Race.
	 * @param string  $email    Entrant's email address.
	 * @param array[] $balloons Their balloons in the race.
	 */
	public function trigger( $race, $email, $balloons ) {
		$this->setup_locale();

		$this->race      = $race;
		$this->balloons  = $balloons;
		$this->recipient = $email;

		$first = $balloons ? strtok( trim( (string) $balloons[0]['owner'] ), ' ' ) : '';

		$this->placeholders['{race_name}']  = $race->name;
		$this->placeholders['{first_name}'] = false !== $first ? $first : '';

		if ( $this->is_enabled() && $this->get_recipient() ) {
			$this->send( $this->get_recipient(), $this->get_subject(), $this->get_content(), $this->get_headers(), $this->get_attachments() );
		}

		$this->restore_locale();
	}

	/**
	 * Template arguments shared by the HTML and plain versions.
	 *
	 * @param bool $plain_text Plain text version.
	 * @return array
	 */
	protected function template_args( $plain_text ) {
		return array(
			'email_heading'      => $this->get_heading(),
			'race'               => $this->race,
			'balloons'           => $this->balloons,
			'first_name'         => $this->placeholders['{first_name}'],
			'tracking_url'       => VBR_Pages::tracking_url( $this->race ),
			'prizes'             => $this->race ? VBR_Prizes::results( $this->race ) : array(),
			'sponsors_line'      => VBR_Sponsors::email_line( $this->race ),
			'additional_content' => $this->get_additional_content(),
			'sent_to_admin'      => false,
			'plain_text'         => $plain_text,
			'email'              => $this,
		);
	}

	/**
	 * HTML content.
	 *
	 * @return string
	 */
	public function get_content_html() {
		return wc_get_template_html( $this->template_html, $this->template_args( false ), '', $this->template_base );
	}

	/**
	 * Plain-text content.
	 *
	 * @return string
	 */
	public function get_content_plain() {
		return wc_get_template_html( $this->template_plain, $this->template_args( true ), '', $this->template_base );
	}

	/**
	 * A balloon's colour and team, for the email.
	 *
	 * @param array $balloon Balloon from VBR_Race_Emails::balloons_for().
	 * @return string
	 */
	public static function balloon_details( $balloon ) {
		return implode( ' · ', array_filter( array( $balloon['color'], $balloon['team'] ) ) );
	}
}
