<?php
/**
 * "Final results" email (plain text).
 *
 * Override by copying to yourtheme/woocommerce/emails/plain/lvbr-balloon-results.php.
 *
 * @package Virtual_Balloon_Race
 *
 * @var string         $email_heading      Heading.
 * @var object         $race               Race.
 * @var array[]        $balloons           The entrant's balloons, best first.
 * @var string         $first_name         Entrant's first name.
 * @var string         $tracking_url       Race tracking page.
 * @var string         $sponsors_line      "This race was made possible by …", or ''.
 * @var array[]        $prizes             Prizes: title, description, donor, winner.
 * @var string         $additional_content Text from the email settings.
 * @var LVBR_Email_Race $email              Email object.
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- plain-text email.
echo "=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n";
echo esc_html( wp_strip_all_tags( $email_heading ) ) . "\n";
echo "=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n\n";

/* translators: %s: first name */
echo esc_html( $first_name ? sprintf( __( 'Hi %s,', 'lumaha-virtual-balloon-race' ), $first_name ) : __( 'Hi,', 'lumaha-virtual-balloon-race' ) ) . "\n\n";

/* translators: %s: race name */
echo esc_html( sprintf( _n( '%s has finished. Here’s how your balloon did:', '%s has finished. Here’s how your balloons did:', count( $balloons ), 'lumaha-virtual-balloon-race' ), $race->name ) ) . "\n\n";

foreach ( $balloons as $lvbr_balloon ) {
	echo '- ' . esc_html( $lvbr_balloon['name'] ) . ' (' . esc_html( LVBR_Email_Race::balloon_details( $lvbr_balloon ) ) . "):\n";
	echo '  ' . esc_html( LVBR_Email_Results::result_text( $lvbr_balloon, $race ) ) . "\n";
	if ( $lvbr_balloon['url'] ) {
		echo '  ' . esc_url_raw( $lvbr_balloon['url'] ) . "\n";
	}
}
echo "\n";

if ( ! empty( $prizes ) ) {
	echo esc_html__( 'Prizes', 'lumaha-virtual-balloon-race' ) . "\n";
	foreach ( $prizes as $lvbr_prize ) {
		echo '- ' . esc_html( $lvbr_prize['title'] . ': ' . $lvbr_prize['description'] );
		if ( $lvbr_prize['donor'] ) {
			/* translators: %s: sponsor name */
			echo ' (' . esc_html( sprintf( __( 'donated by %s', 'lumaha-virtual-balloon-race' ), $lvbr_prize['donor'] ) ) . ')';
		}
		echo "\n  " . esc_html( $lvbr_prize['winner'] ? $lvbr_prize['winner'] : __( 'To be announced', 'lumaha-virtual-balloon-race' ) ) . "\n";
	}
	echo "\n";
}

if ( ! empty( $sponsors_line ) ) {
	echo esc_html( $sponsors_line ) . "\n\n";
}

if ( $tracking_url ) {
	echo esc_html__( 'See the final leaderboard:', 'lumaha-virtual-balloon-race' ) . ' ' . esc_url_raw( $tracking_url ) . "\n\n";
}

if ( $additional_content ) {
	echo esc_html( wp_strip_all_tags( wptexturize( $additional_content ) ) ) . "\n\n";
}

echo "=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n\n";
echo wp_kses_post( apply_filters( 'woocommerce_email_footer_text', get_option( 'woocommerce_email_footer_text' ) ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce's email footer filter.
