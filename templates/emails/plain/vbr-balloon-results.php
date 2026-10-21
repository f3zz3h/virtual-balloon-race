<?php
/**
 * "Final results" email (plain text).
 *
 * Override by copying to yourtheme/woocommerce/emails/plain/vbr-balloon-results.php.
 *
 * @package Virtual_Balloon_Race
 *
 * @var string         $email_heading      Heading.
 * @var object         $race               Race.
 * @var array[]        $balloons           The entrant's balloons, best first.
 * @var string         $first_name         Entrant's first name.
 * @var string         $tracking_url       Race tracking page.
 * @var string         $additional_content Text from the email settings.
 * @var VBR_Email_Race $email              Email object.
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- plain-text email.
echo "=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n";
echo esc_html( wp_strip_all_tags( $email_heading ) ) . "\n";
echo "=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n\n";

/* translators: %s: first name */
echo esc_html( $first_name ? sprintf( __( 'Hi %s,', 'virtual-balloon-race' ), $first_name ) : __( 'Hi,', 'virtual-balloon-race' ) ) . "\n\n";

/* translators: %s: race name */
echo esc_html( sprintf( _n( '%s has finished. Here’s how your balloon did:', '%s has finished. Here’s how your balloons did:', count( $balloons ), 'virtual-balloon-race' ), $race->name ) ) . "\n\n";

foreach ( $balloons as $balloon ) {
	echo '- ' . esc_html( $balloon['name'] ) . ' (' . esc_html( VBR_Email_Race::balloon_details( $balloon ) ) . "):\n";
	echo '  ' . esc_html( VBR_Email_Results::result_text( $balloon, $race ) ) . "\n";
	if ( $balloon['url'] ) {
		echo '  ' . esc_url_raw( $balloon['url'] ) . "\n";
	}
}
echo "\n";

if ( $tracking_url ) {
	echo esc_html__( 'See the final leaderboard:', 'virtual-balloon-race' ) . ' ' . esc_url_raw( $tracking_url ) . "\n\n";
}

if ( $additional_content ) {
	echo esc_html( wp_strip_all_tags( wptexturize( $additional_content ) ) ) . "\n\n";
}

echo "=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n\n";
echo wp_kses_post( apply_filters( 'woocommerce_email_footer_text', get_option( 'woocommerce_email_footer_text' ) ) );
