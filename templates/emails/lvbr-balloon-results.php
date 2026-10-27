<?php
/**
 * "Final results" email (HTML).
 *
 * Override by copying to yourtheme/woocommerce/emails/lvbr-balloon-results.php.
 *
 * @package Virtual_Balloon_Race
 *
 * @var string           $email_heading      Heading.
 * @var object           $race               Race.
 * @var array[]          $balloons           The entrant's balloons, best first.
 * @var string           $first_name         Entrant's first name.
 * @var string           $tracking_url       Race tracking page.
 * @var string           $sponsors_line      "This race was made possible by …", or ''.
 * @var array[]          $prizes             Prizes: title, description, donor, winner.
 * @var string           $additional_content Text from the email settings.
 * @var LVBR_Email_Race   $email              Email object.
 */

defined( 'ABSPATH' ) || exit;

do_action( 'woocommerce_email_header', $email_heading, $email ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce's email hook. ?>

<p>
	<?php
	/* translators: %s: first name */
	echo esc_html( $first_name ? sprintf( __( 'Hi %s,', 'lumaha-virtual-balloon-race' ), $first_name ) : __( 'Hi,', 'lumaha-virtual-balloon-race' ) );
	?>
</p>

<p>
	<?php
	echo esc_html(
		sprintf(
			/* translators: %s: race name */
			_n( '%s has finished. Here’s how your balloon did:', '%s has finished. Here’s how your balloons did:', count( $balloons ), 'lumaha-virtual-balloon-race' ),
			$race->name
		)
	);
	?>
</p>

<table class="td" cellspacing="0" cellpadding="6" border="1" style="width: 100%; margin-bottom: 24px;">
	<tbody>
		<?php foreach ( $balloons as $lvbr_balloon ) : ?>
			<tr>
				<td class="td" style="text-align: left;">
					<strong><?php echo esc_html( $lvbr_balloon['name'] ); ?></strong><br />
					<small><?php echo esc_html( LVBR_Email_Race::balloon_details( $lvbr_balloon ) ); ?></small>
				</td>
				<td class="td" style="text-align: left;">
					<?php echo esc_html( LVBR_Email_Results::result_text( $lvbr_balloon, $race ) ); ?>
					<?php if ( $lvbr_balloon['url'] ) : ?>
						<br /><a href="<?php echo esc_url( $lvbr_balloon['url'] ); ?>"><?php esc_html_e( 'See where it ended up', 'lumaha-virtual-balloon-race' ); ?></a>
					<?php endif; ?>
				</td>
			</tr>
		<?php endforeach; ?>
	</tbody>
</table>

<?php if ( ! empty( $prizes ) ) : ?>
	<h2><?php esc_html_e( 'Prizes', 'lumaha-virtual-balloon-race' ); ?></h2>
	<table class="td" cellspacing="0" cellpadding="6" border="1" style="width: 100%; margin-bottom: 24px;">
		<tbody>
			<?php foreach ( $prizes as $lvbr_prize ) : ?>
				<tr>
					<td class="td" style="text-align: left;">
						<strong><?php echo esc_html( $lvbr_prize['title'] ); ?></strong><br />
						<?php echo esc_html( $lvbr_prize['description'] ); ?>
						<?php if ( $lvbr_prize['donor'] ) : ?>
							<br /><small>
							<?php
							/* translators: %s: sponsor name */
							echo esc_html( sprintf( __( 'Donated by %s', 'lumaha-virtual-balloon-race' ), $lvbr_prize['donor'] ) );
							?>
							</small>
						<?php endif; ?>
					</td>
					<td class="td" style="text-align: left;"><?php echo esc_html( $lvbr_prize['winner'] ? $lvbr_prize['winner'] : __( 'To be announced', 'lumaha-virtual-balloon-race' ) ); ?></td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
<?php endif; ?>

<?php if ( ! empty( $sponsors_line ) ) : ?>
	<p><small><?php echo esc_html( $sponsors_line ); ?></small></p>
<?php endif; ?>

<?php if ( $tracking_url ) : ?>
	<p><a href="<?php echo esc_url( $tracking_url ); ?>"><?php esc_html_e( 'See the final leaderboard', 'lumaha-virtual-balloon-race' ); ?></a></p>
<?php endif; ?>

<?php
if ( $additional_content ) {
	echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) );
}

do_action( 'woocommerce_email_footer', $email ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce's email hook.
