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
		add_shortcode( 'balloon_races', array( $this, 'render_races_shortcode' ) );
		add_shortcode( 'balloon_teams', array( $this, 'render_teams_shortcode' ) );
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
			if ( $post && ( has_shortcode( $post->post_content, 'balloon_leaderboard' ) || has_shortcode( $post->post_content, 'balloon_races' ) || has_shortcode( $post->post_content, 'balloon_teams' ) ) ) {
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

		$ref  = VBR_Races::ref_from_shortcode( $atts );
		$atts = shortcode_atts(
			array(
				'limit'   => 50,
				'map_url' => '',
				'race'    => 'current',
			),
			$atts,
			'balloon_leaderboard'
		);

		$atts['race'] = $ref;
		$limit        = max( 1, intval( $atts['limit'] ) );
		$race         = VBR_Races::resolve( $atts['race'] );
		$map_url      = '' !== $atts['map_url'] ? esc_url( $atts['map_url'] ) : VBR_Pages::tracking_url( $race );

		if ( ! $race && 'current' !== $atts['race'] ) {
			return current_user_can( 'manage_options' ) ? '<p>' . esc_html__( 'Balloon leaderboard: race not found.', 'virtual-balloon-race' ) . '</p>' : '';
		}

		global $wpdb;
		$table    = VBR_Balloons::table();
		$balloons = array();
		$stage    = $race ? VBR_Races::status( $race ) : '';

		$order    = VBR_Races::ranking_column( $race );
		$by_start = VBR_Races::FROM_START === VBR_Races::ranking( $race );

		if ( $race ) {
			// Every flying balloon is rendered so search covers the whole race; rows past `limit` start hidden.
			$balloons = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$table} WHERE is_burst = 0 AND race_id = %d ORDER BY {$order} DESC, id ASC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					(int) $race->id
				)
			);
		}

		// Two distance columns, the one that decides the race first.
		$distance_columns = array(
			'from_start_km' => __( 'From launch (km)', 'virtual-balloon-race' ),
			'distance_km'   => __( 'Travelled (km)', 'virtual-balloon-race' ),
		);
		if ( ! $by_start ) {
			$distance_columns = array_reverse( $distance_columns, true );
		}

		// Page caches normally skip logged-in visitors, so the viewer's own balloons can go in the HTML.
		$mine  = $race && is_user_logged_in() ? VBR_Balloons::owned_by( get_current_user_id(), (int) $race->id ) : array();
		$ranks = array();
		foreach ( $balloons as $index => $balloon ) {
			$ranks[ (int) $balloon->id ] = $index + 1;
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

		$waiting = in_array( $stage, array( VBR_Races::UPCOMING, VBR_Races::ON_SALE ), true );
		$teams   = VBR_Teams::by_id( $race );

		ob_start();
		?>
		<div class="vbr-leaderboard" data-vbr-leaderboard data-vbr-limit="<?php echo esc_attr( (string) $limit ); ?>">
			<div class="vbr-leaderboard__header">
				<div>
					<h2 class="vbr-leaderboard__title"><?php echo esc_html( $race ? $race->name : __( 'Balloon Race Leaderboard', 'virtual-balloon-race' ) ); ?></h2>
					<?php if ( $subtitle ) : ?>
						<p class="vbr-leaderboard__subtitle"><?php echo esc_html( $subtitle ); ?></p>
					<?php endif; ?>
				</div>
				<div class="vbr-leaderboard__tools">
					<?php if ( $mine ) : ?>
						<div class="vbr-toggle" role="group" aria-label="<?php esc_attr_e( 'Show', 'virtual-balloon-race' ); ?>">
							<button type="button" class="vbr-toggle__btn" data-vbr-show="all" aria-pressed="true"><?php esc_html_e( 'All balloons', 'virtual-balloon-race' ); ?></button>
							<button type="button" class="vbr-toggle__btn" data-vbr-show="mine" aria-pressed="false"><?php esc_html_e( 'My balloons', 'virtual-balloon-race' ); ?></button>
						</div>
					<?php endif; ?>
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
			</div>

			<?php if ( $mine ) : ?>
				<div class="vbr-leaderboard__mine">
					<h3 class="vbr-leaderboard__mine-title"><?php echo esc_html( _n( 'Your balloon', 'Your balloons', count( $mine ), 'virtual-balloon-race' ) ); ?></h3>
					<ul class="vbr-leaderboard__mine-list">
						<?php foreach ( $mine as $own ) : ?>
							<?php
							if ( (int) $own->is_burst ) {
								$where = __( 'Burst', 'virtual-balloon-race' );
							} elseif ( $waiting ) {
								$where = __( 'Waiting for launch', 'virtual-balloon-race' );
							} else {
								$where = sprintf(
									$by_start
										/* translators: 1: position, 2: number of balloons, 3: distance in km */
										? __( '#%1$s of %2$s · %3$s km from launch', 'virtual-balloon-race' )
										/* translators: 1: position, 2: number of balloons, 3: distance in km */
										: __( '#%1$s of %2$s · %3$s km travelled', 'virtual-balloon-race' ),
									number_format_i18n( isset( $ranks[ (int) $own->id ] ) ? $ranks[ (int) $own->id ] : 0 ),
									number_format_i18n( count( $balloons ) ),
									number_format_i18n( VBR_Balloons::ranked_distance( $own, $race ), 1 )
								);
							}
							?>
							<li data-vbr-balloon="<?php echo esc_attr( (string) $own->id ); ?>">
								<?php if ( $map_url ) : ?>
									<a class="vbr-leaderboard__link" href="<?php echo esc_url( add_query_arg( 'balloon', (int) $own->id, $map_url ) ); ?>"><?php echo esc_html( $own->balloon_name ); ?></a>
								<?php else : ?>
									<strong><?php echo esc_html( $own->balloon_name ); ?></strong>
								<?php endif; ?>
								<span class="vbr-leaderboard__mine-where"><?php echo esc_html( $where ); ?></span>
							</li>
						<?php endforeach; ?>
					</ul>
				</div>
			<?php endif; ?>

			<div class="vbr-leaderboard__table-wrap">
				<table class="vbr-leaderboard__table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Rank', 'virtual-balloon-race' ); ?></th>
							<th><?php esc_html_e( 'Balloon Name', 'virtual-balloon-race' ); ?></th>
							<th><?php esc_html_e( 'Owner', 'virtual-balloon-race' ); ?></th>
							<?php if ( $teams ) : ?>
								<th><?php esc_html_e( 'Team', 'virtual-balloon-race' ); ?></th>
							<?php endif; ?>
							<th><?php esc_html_e( 'Color', 'virtual-balloon-race' ); ?></th>
							<?php foreach ( $distance_columns as $label ) : ?>
								<th><?php echo esc_html( $label ); ?></th>
							<?php endforeach; ?>
							<th><?php esc_html_e( 'Status', 'virtual-balloon-race' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php if ( empty( $balloons ) ) : ?>
							<tr class="vbr-leaderboard__empty">
								<td colspan="<?php echo esc_attr( (string) ( $teams ? 8 : 7 ) ); ?>">
									<?php
									echo esc_html(
										! $race ? __( 'No race scheduled yet.', 'virtual-balloon-race' )
											: ( VBR_Races::FINISHED === $stage ? __( 'No balloons finished this race.', 'virtual-balloon-race' ) : __( 'No balloons yet. Be the first in the sky!', 'virtual-balloon-race' ) )
									);
									?>
								</td>
							</tr>
						<?php else : ?>
							<?php $mine_ids = array_map( 'intval', wp_list_pluck( $mine, 'id' ) ); ?>
							<?php foreach ( $balloons as $index => $balloon ) : ?>
								<?php
								$owner           = VBR_Balloons::public_owner_name( $balloon );
								$team            = isset( $teams[ (int) $balloon->team_id ] ) ? $teams[ (int) $balloon->team_id ] : null;
								$search_haystack = mb_strtolower( $balloon->balloon_name . ' ' . $owner . ( $team ? ' ' . $team->name : '' ) );
								$color_slug      = sanitize_html_class( strtolower( $balloon->balloon_color ) );
								$is_mine         = in_array( (int) $balloon->id, $mine_ids, true );
								$classes         = array_filter( array( $is_mine ? 'is-mine' : '', $index >= $limit ? 'is-extra' : '' ) );
								?>
								<tr data-vbr-search="<?php echo esc_attr( $search_haystack ); ?>" data-vbr-balloon="<?php echo esc_attr( (string) $balloon->id ); ?>"<?php echo $classes ? ' class="' . esc_attr( implode( ' ', $classes ) ) . '"' : ''; ?><?php echo $is_mine ? ' data-vbr-mine' : ''; ?><?php echo $index >= $limit ? ' hidden' : ''; ?>>
									<td data-label="<?php esc_attr_e( 'Rank', 'virtual-balloon-race' ); ?>"><?php echo esc_html( (string) ( $index + 1 ) ); ?></td>
									<td data-label="<?php esc_attr_e( 'Balloon Name', 'virtual-balloon-race' ); ?>">
										<?php if ( $map_url ) : ?>
											<a class="vbr-leaderboard__link" href="<?php echo esc_url( add_query_arg( 'balloon', (int) $balloon->id, $map_url ) ); ?>"><?php echo esc_html( $balloon->balloon_name ); ?></a>
										<?php else : ?>
											<?php echo esc_html( $balloon->balloon_name ); ?>
										<?php endif; ?>
										<?php if ( $is_mine ) : ?>
											<span class="vbr-leaderboard__yours"><?php esc_html_e( 'Yours', 'virtual-balloon-race' ); ?></span>
										<?php endif; ?>
									</td>
									<td data-label="<?php esc_attr_e( 'Owner', 'virtual-balloon-race' ); ?>"><?php echo esc_html( $owner ); ?></td>
									<?php if ( $teams ) : ?>
										<td data-label="<?php esc_attr_e( 'Team', 'virtual-balloon-race' ); ?>">
											<?php if ( $team ) : ?>
												<?php self::team_badge( $team->name, $team->color ); ?>
											<?php endif; ?>
										</td>
									<?php endif; ?>
									<td data-label="<?php esc_attr_e( 'Color', 'virtual-balloon-race' ); ?>">
										<span class="vbr-color vbr-color--<?php echo esc_attr( $color_slug ); ?>">
											<?php echo esc_html( $balloon->balloon_color ); ?>
										</span>
									</td>
									<?php foreach ( $distance_columns as $column => $label ) : ?>
										<td data-label="<?php echo esc_attr( $label ); ?>"><?php echo esc_html( number_format_i18n( (float) $balloon->{$column}, 1 ) ); ?></td>
									<?php endforeach; ?>
									<td data-label="<?php esc_attr_e( 'Status', 'virtual-balloon-race' ); ?>">
										<span class="vbr-pill vbr-pill--<?php echo esc_attr( $pill[0] ); ?>"><?php echo esc_html( $pill[1] ); ?></span>
									</td>
								</tr>
							<?php endforeach; ?>
						<?php endif; ?>
					</tbody>
				</table>
			</div>
			<?php if ( count( $balloons ) > $limit ) : ?>
				<p class="vbr-leaderboard__more">
					<?php
					echo esc_html(
						sprintf(
							/* translators: 1: rows shown, 2: total balloons */
							__( 'Showing the top %1$s of %2$s balloons. Search to find any balloon.', 'virtual-balloon-race' ),
							number_format_i18n( $limit ),
							number_format_i18n( count( $balloons ) )
						)
					);
					?>
				</p>
			<?php endif; ?>
			<p class="vbr-leaderboard__empty-search" hidden><?php esc_html_e( 'No balloons match that search.', 'virtual-balloon-race' ); ?></p>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Render [balloon_races]: links to each race on this page (shown when there are at least `min` races).
	 *
	 * @param array|string $atts Shortcode attributes.
	 * @return string
	 */
	public function render_races_shortcode( $atts ) {
		$atts = shortcode_atts(
			array(
				'title' => __( 'All races', 'virtual-balloon-race' ),
				'min'   => 2,
			),
			$atts,
			'balloon_races'
		);

		$races = VBR_Races::all();

		if ( count( $races ) < max( 1, (int) $atts['min'] ) ) {
			return '';
		}

		wp_enqueue_style( 'vbr-frontend' );

		$shown = VBR_Races::resolve( VBR_Races::ref_from_shortcode( array() ) );
		$base  = remove_query_arg( array( 'race', 'balloon' ) );
		$pill  = array(
			VBR_Races::UPCOMING => 'waiting',
			VBR_Races::ON_SALE  => 'waiting',
			VBR_Races::FLYING   => 'active',
			VBR_Races::FINISHED => 'finished',
		);

		ob_start();
		?>
		<nav class="vbr-races" aria-label="<?php echo esc_attr( $atts['title'] ); ?>">
			<?php if ( '' !== $atts['title'] ) : ?>
				<h2 class="vbr-races__title"><?php echo esc_html( $atts['title'] ); ?></h2>
			<?php endif; ?>
			<ul class="vbr-races__list">
				<?php foreach ( $races as $race ) : ?>
					<?php
					$stage   = VBR_Races::status( $race );
					$current = $shown && (int) $shown->id === (int) $race->id;
					$date    = VBR_Races::FINISHED === $stage && $race->end_at ? $race->end_at : $race->launch_at;
					?>
					<li class="vbr-races__item<?php echo $current ? ' is-current' : ''; ?>">
						<a href="<?php echo esc_url( add_query_arg( 'race', $race->slug, $base ) ); ?>"<?php echo $current ? ' aria-current="page"' : ''; ?>><?php echo esc_html( $race->name ); ?></a>
						<span class="vbr-pill vbr-pill--<?php echo esc_attr( $pill[ $stage ] ); ?>"><?php echo esc_html( VBR_Races::status_label( $race ) ); ?></span>
						<span class="vbr-races__date"><?php echo esc_html( VBR_Races::format_date( $date ) ); ?></span>
					</li>
				<?php endforeach; ?>
			</ul>
		</nav>
		<?php
		return ob_get_clean();
	}

	/**
	 * Team name with its colour dot.
	 *
	 * @param string $name  Team name.
	 * @param string $color Hex colour.
	 */
	public static function team_badge( $name, $color ) {
		printf(
			'<span class="vbr-team"><span class="vbr-team__dot" style="background:%1$s"></span>%2$s</span>',
			esc_attr( sanitize_hex_color( $color ) ? $color : '#555555' ),
			esc_html( $name )
		);
	}

	/**
	 * Render [balloon_teams]: team standings for a race (nothing when the race doesn't use teams).
	 *
	 * @param array|string $atts Shortcode attributes.
	 * @return string
	 */
	public function render_teams_shortcode( $atts ) {
		$ref  = VBR_Races::ref_from_shortcode( $atts );
		$atts = shortcode_atts(
			array(
				'race'  => 'current',
				'title' => __( 'Team standings', 'virtual-balloon-race' ),
			),
			$atts,
			'balloon_teams'
		);
		$race = VBR_Races::resolve( $ref );

		if ( ! VBR_Teams::race_uses_teams( $race ) ) {
			return '';
		}

		wp_enqueue_style( 'vbr-frontend' );

		$standings = VBR_Teams::standings( $race );
		$method    = VBR_Teams::scoring( $race );
		$labels    = VBR_Teams::scoring_labels();
		$waiting   = in_array( VBR_Races::status( $race ), array( VBR_Races::UPCOMING, VBR_Races::ON_SALE ), true );

		ob_start();
		?>
		<div class="vbr-leaderboard vbr-teams">
			<div class="vbr-leaderboard__header">
				<div>
					<?php if ( '' !== $atts['title'] ) : ?>
						<h2 class="vbr-leaderboard__title"><?php echo esc_html( $atts['title'] ); ?></h2>
					<?php endif; ?>
					<p class="vbr-leaderboard__subtitle">
						<?php
						echo esc_html(
							$waiting
								? __( 'Teams are ranked once the balloons launch.', 'virtual-balloon-race' )
								: sprintf(
									/* translators: 1: scoring method, e.g. Average distance; 2: how distance is measured */
									__( 'Ranked by %1$s, %2$s', 'virtual-balloon-race' ),
									mb_strtolower( $labels[ $method ] ),
									VBR_Races::FROM_START === VBR_Races::ranking( $race )
										? __( 'measured from the launch point', 'virtual-balloon-race' )
										: __( 'measured along the whole trail', 'virtual-balloon-race' )
								)
						);
						?>
					</p>
				</div>
			</div>
			<div class="vbr-leaderboard__table-wrap">
				<table class="vbr-leaderboard__table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Rank', 'virtual-balloon-race' ); ?></th>
							<th><?php esc_html_e( 'Team', 'virtual-balloon-race' ); ?></th>
							<th><?php esc_html_e( 'Balloons', 'virtual-balloon-race' ); ?></th>
							<th><?php echo esc_html( $labels[ $method ] . ' (km)' ); ?></th>
							<th><?php esc_html_e( 'Leading balloon', 'virtual-balloon-race' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $standings as $team ) : ?>
							<tr>
								<td data-label="<?php esc_attr_e( 'Rank', 'virtual-balloon-race' ); ?>"><?php echo esc_html( $team['rank'] && ! $waiting ? (string) $team['rank'] : '–' ); ?></td>
								<td data-label="<?php esc_attr_e( 'Team', 'virtual-balloon-race' ); ?>"><?php self::team_badge( $team['name'], $team['color'] ); ?></td>
								<td data-label="<?php esc_attr_e( 'Balloons', 'virtual-balloon-race' ); ?>"><?php echo esc_html( number_format_i18n( $team['balloons'] ) ); ?></td>
								<td data-label="<?php echo esc_attr( $labels[ $method ] ); ?>"><?php echo esc_html( $waiting ? '–' : number_format_i18n( $team['score'], 1 ) ); ?></td>
								<td data-label="<?php esc_attr_e( 'Leading balloon', 'virtual-balloon-race' ); ?>"><?php echo esc_html( $waiting ? '' : $team['leader'] ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}
}
