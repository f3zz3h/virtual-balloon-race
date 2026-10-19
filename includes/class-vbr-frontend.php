<?php
/**
 * Frontend assets and the [balloon_leaderboard] shortcode.
 *
 * @package Virtual_Balloon_Race
 */

defined( 'ABSPATH' ) || exit;

/**
 * Public leaderboard.
 */
class VBR_Frontend {

	/**
	 * Register hooks.
	 */
	public function __construct() {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_frontend_assets' ) );
		add_shortcode( 'balloon_leaderboard', array( $this, 'render_leaderboard_shortcode' ) );
	}

	/**
	 * Register frontend assets; enqueue on balloon products and leaderboard pages.
	 */
	public function enqueue_frontend_assets() {
		wp_register_style(
			'vbr-frontend',
			VBR_PLUGIN_URL . 'assets/css/frontend.css',
			array(),
			VBR_Balloons::asset_version( 'assets/css/frontend.css' )
		);

		wp_register_script(
			'vbr-frontend',
			VBR_PLUGIN_URL . 'assets/js/frontend.js',
			array(),
			VBR_Balloons::asset_version( 'assets/js/frontend.js' ),
			true
		);

		$needs_leaderboard = false;
		if ( is_singular() ) {
			$post = get_post();
			if ( $post && has_shortcode( $post->post_content, 'balloon_leaderboard' ) ) {
				$needs_leaderboard = true;
			}
		}

		if ( $needs_leaderboard ) {
			wp_enqueue_style( 'vbr-frontend' );
			wp_enqueue_script( 'vbr-frontend' );
		}

		if ( function_exists( 'is_product' ) && is_product() ) {
			wp_enqueue_style( 'vbr-frontend' );
		}
	}

	/**
	 * Render [balloon_leaderboard].
	 *
	 * @param array|string $atts Shortcode attributes.
	 * @return string
	 */
	public function render_leaderboard_shortcode( $atts ) {
		wp_enqueue_style( 'vbr-frontend' );
		wp_enqueue_script( 'vbr-frontend' );

		$atts = shortcode_atts(
			array(
				'limit'   => 50,
				'map_url' => '',
				'race'    => 'current',
			),
			$atts,
			'balloon_leaderboard'
		);

		$limit   = max( 1, intval( $atts['limit'] ) );
		$map_url = '' !== $atts['map_url'] ? esc_url( $atts['map_url'] ) : '';

		$race = VBR_Races::resolve( $atts['race'] );

		if ( ! $race && 'current' !== $atts['race'] ) {
			return current_user_can( 'manage_options' ) ? '<p>' . esc_html__( 'Balloon leaderboard: race not found.', 'virtual-balloon-race' ) . '</p>' : '';
		}

		global $wpdb;
		$table    = VBR_Balloons::table();
		$balloons = array();
		$stage    = $race ? VBR_Races::status( $race ) : '';

		if ( $race ) {
			$balloons = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$table} WHERE is_burst = 0 AND race_id = %d ORDER BY distance_km DESC, id ASC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					(int) $race->id,
					$limit
				)
			);
		}

		$pill = array(
			VBR_Races::UPCOMING => array( 'waiting', __( 'Waiting', 'virtual-balloon-race' ) ),
			VBR_Races::ON_SALE  => array( 'waiting', __( 'Waiting', 'virtual-balloon-race' ) ),
			VBR_Races::FLYING   => array( 'active', __( 'Flying', 'virtual-balloon-race' ) ),
			VBR_Races::FINISHED => array( 'finished', __( 'Finished', 'virtual-balloon-race' ) ),
		);
		$pill = isset( $pill[ $stage ] ) ? $pill[ $stage ] : $pill[ VBR_Races::FLYING ];

		$subtitle = '';
		if ( $race ) {
			$dates = array(
				/* translators: %s: date */
				VBR_Races::UPCOMING => sprintf( __( 'Entries open %s', 'virtual-balloon-race' ), VBR_Races::format_date( $race->sales_open_at ) ),
				/* translators: %s: date */
				VBR_Races::ON_SALE  => sprintf( __( 'Taking entries · launches %s', 'virtual-balloon-race' ), VBR_Races::format_date( $race->launch_at ) ),
				VBR_Races::FLYING   => $race->end_at
					/* translators: %s: date */
					? sprintf( __( 'Flying now · ends %s', 'virtual-balloon-race' ), VBR_Races::format_date( $race->end_at ) )
					: __( 'Flying now', 'virtual-balloon-race' ),
				/* translators: %s: date */
				VBR_Races::FINISHED => sprintf( __( 'Final results · finished %s', 'virtual-balloon-race' ), VBR_Races::format_date( $race->end_at ) ),
			);
			$subtitle = $dates[ $stage ];
		}

		ob_start();
		?>
		<div class="vbr-leaderboard" data-vbr-leaderboard>
			<div class="vbr-leaderboard__header">
				<div>
					<h2 class="vbr-leaderboard__title"><?php echo esc_html( $race ? $race->name : __( 'Balloon Race Leaderboard', 'virtual-balloon-race' ) ); ?></h2>
					<?php if ( $subtitle ) : ?>
						<p class="vbr-leaderboard__subtitle"><?php echo esc_html( $subtitle ); ?></p>
					<?php endif; ?>
				</div>
				<label class="vbr-leaderboard__search">
					<span class="screen-reader-text"><?php esc_html_e( 'Search balloons', 'virtual-balloon-race' ); ?></span>
					<input
						type="search"
						class="vbr-leaderboard__search-input"
						placeholder="<?php esc_attr_e( 'Search balloon or owner name…', 'virtual-balloon-race' ); ?>"
						autocomplete="off"
					/>
				</label>
			</div>

			<div class="vbr-leaderboard__table-wrap">
				<table class="vbr-leaderboard__table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Rank', 'virtual-balloon-race' ); ?></th>
							<th><?php esc_html_e( 'Balloon Name', 'virtual-balloon-race' ); ?></th>
							<th><?php esc_html_e( 'Owner', 'virtual-balloon-race' ); ?></th>
							<th><?php esc_html_e( 'Color', 'virtual-balloon-race' ); ?></th>
							<th><?php esc_html_e( 'Distance (km)', 'virtual-balloon-race' ); ?></th>
							<th><?php esc_html_e( 'Status', 'virtual-balloon-race' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php if ( empty( $balloons ) ) : ?>
							<tr class="vbr-leaderboard__empty">
								<td colspan="6">
									<?php
									echo esc_html(
										! $race ? __( 'No race scheduled yet.', 'virtual-balloon-race' )
											: ( VBR_Races::FINISHED === $stage ? __( 'No balloons finished this race.', 'virtual-balloon-race' ) : __( 'No balloons yet. Be the first in the sky!', 'virtual-balloon-race' ) )
									);
									?>
								</td>
							</tr>
						<?php else : ?>
							<?php foreach ( $balloons as $index => $balloon ) : ?>
								<?php
								$search_haystack = strtolower( $balloon->balloon_name . ' ' . $balloon->owner_name );
								$color_slug      = sanitize_html_class( strtolower( $balloon->balloon_color ) );
								?>
								<tr data-vbr-search="<?php echo esc_attr( $search_haystack ); ?>" data-vbr-balloon="<?php echo esc_attr( (string) $balloon->id ); ?>">
									<td data-label="<?php esc_attr_e( 'Rank', 'virtual-balloon-race' ); ?>"><?php echo esc_html( (string) ( $index + 1 ) ); ?></td>
									<td data-label="<?php esc_attr_e( 'Balloon Name', 'virtual-balloon-race' ); ?>">
										<?php if ( $map_url ) : ?>
											<a class="vbr-leaderboard__link" href="<?php echo esc_url( add_query_arg( 'balloon', (int) $balloon->id, $map_url ) ); ?>"><?php echo esc_html( $balloon->balloon_name ); ?></a>
										<?php else : ?>
											<?php echo esc_html( $balloon->balloon_name ); ?>
										<?php endif; ?>
									</td>
									<td data-label="<?php esc_attr_e( 'Owner', 'virtual-balloon-race' ); ?>"><?php echo esc_html( $balloon->owner_name ); ?></td>
									<td data-label="<?php esc_attr_e( 'Color', 'virtual-balloon-race' ); ?>">
										<span class="vbr-color vbr-color--<?php echo esc_attr( $color_slug ); ?>">
											<?php echo esc_html( $balloon->balloon_color ); ?>
										</span>
									</td>
									<td data-label="<?php esc_attr_e( 'Distance (km)', 'virtual-balloon-race' ); ?>">
										<?php echo esc_html( number_format_i18n( (float) $balloon->distance_km, 2 ) ); ?>
									</td>
									<td data-label="<?php esc_attr_e( 'Status', 'virtual-balloon-race' ); ?>">
										<span class="vbr-pill vbr-pill--<?php echo esc_attr( $pill[0] ); ?>"><?php echo esc_html( $pill[1] ); ?></span>
									</td>
								</tr>
							<?php endforeach; ?>
						<?php endif; ?>
					</tbody>
				</table>
			</div>
			<p class="vbr-leaderboard__empty-search" hidden><?php esc_html_e( 'No balloons match that search.', 'virtual-balloon-race' ); ?></p>
		</div>
		<?php
		return ob_get_clean();
	}
}
