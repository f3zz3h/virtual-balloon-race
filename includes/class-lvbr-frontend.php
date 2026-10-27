<?php
/**
 * Frontend assets and the [lvbr_leaderboard] shortcode.
 *
 * @package Virtual_Balloon_Race
 */

defined( 'ABSPATH' ) || exit;

/**
 * Public leaderboard.
 */
class LVBR_Frontend {

	/**
	 * Register hooks.
	 */
	public function __construct() {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_frontend_assets' ) );
		add_shortcode( 'lvbr_leaderboard', array( $this, 'render_leaderboard_shortcode' ) );
		add_shortcode( 'lvbr_races', array( $this, 'render_races_shortcode' ) );
		add_shortcode( 'lvbr_teams', array( $this, 'render_teams_shortcode' ) );
	}

	/**
	 * Register frontend assets; enqueue on balloon products and leaderboard pages.
	 */
	public function enqueue_frontend_assets() {
		wp_register_style(
			'lvbr-frontend',
			LVBR_PLUGIN_URL . 'assets/css/frontend.css',
			array(),
			LVBR_Balloons::asset_version( 'assets/css/frontend.css' )
		);

		wp_register_script(
			'lvbr-frontend',
			LVBR_PLUGIN_URL . 'assets/js/frontend.js',
			array(),
			LVBR_Balloons::asset_version( 'assets/js/frontend.js' ),
			true
		);
		wp_localize_script(
			'lvbr-frontend',
			'lvbrLeaderboard',
			array(
				'i18n' => array(
					/* translators: 1: first row, 2: last row, 3: total */
					'showing'  => __( 'Showing %1$s–%2$s of %3$s', 'lumaha-virtual-balloon-race' ),
					'none'     => __( 'No balloons to show', 'lumaha-virtual-balloon-race' ),
					'previous' => __( 'Previous', 'lumaha-virtual-balloon-race' ),
					'next'     => __( 'Next', 'lumaha-virtual-balloon-race' ),
					/* translators: %s: page number */
					'page'     => __( 'Page %s', 'lumaha-virtual-balloon-race' ),
				),
			)
		);

		$needs_leaderboard = false;
		if ( is_singular() ) {
			$post = get_post();
			if ( $post && ( has_shortcode( $post->post_content, 'lvbr_leaderboard' ) || has_shortcode( $post->post_content, 'lvbr_races' ) || has_shortcode( $post->post_content, 'lvbr_teams' ) || has_shortcode( $post->post_content, 'lvbr_prizes' ) || has_shortcode( $post->post_content, 'lvbr_sponsors' ) ) ) {
				$needs_leaderboard = true;
			}
		}

		if ( $needs_leaderboard ) {
			wp_enqueue_style( 'lvbr-frontend' );
			wp_enqueue_script( 'lvbr-frontend' );
		}

		if ( function_exists( 'is_product' ) && is_product() ) {
			wp_enqueue_style( 'lvbr-frontend' );
		}
	}

	/**
	 * Entries-per-page choices for leaderboards (0 = all).
	 *
	 * @return int[]
	 */
	public static function per_page_options() {
		return array( 10, 25, 50, 100, 0 );
	}

	/**
	 * Default entries per page (Settings), or a valid override.
	 *
	 * @param mixed $requested Requested value ('' = use the default; 0 or 'all' = all).
	 * @return int 0 for all.
	 */
	public static function per_page( $requested = '' ) {
		if ( 'all' === $requested ) {
			return 0;
		}

		$value = '' === $requested || null === $requested ? (int) LVBR_Settings::get( 'leaderboard_per_page' ) : (int) $requested;

		if ( $value > 0 ) {
			return $value;
		}

		return '0' === (string) $requested ? 0 : 10;
	}

	/**
	 * Render [lvbr_leaderboard].
	 *
	 * @param array|string $atts Shortcode attributes.
	 * @return string
	 */
	public function render_leaderboard_shortcode( $atts ) {
		wp_enqueue_style( 'lvbr-frontend' );
		wp_enqueue_script( 'lvbr-frontend' );

		$ref  = LVBR_Races::ref_from_shortcode( $atts );
		$atts = shortcode_atts(
			array(
				'per_page' => '',
				'limit'    => '',
				'map_url'  => '',
				'race'     => 'current',
			),
			$atts,
			'lvbr_leaderboard'
		);

		$atts['race'] = $ref;
		// `limit` is the older name for `per_page`.
		$per_page     = self::per_page( '' !== $atts['per_page'] ? $atts['per_page'] : $atts['limit'] );
		$race         = LVBR_Races::resolve( $atts['race'] );
		$map_url      = '' !== $atts['map_url'] ? esc_url( $atts['map_url'] ) : LVBR_Pages::tracking_url( $race );

		if ( ! $race && 'current' !== $atts['race'] ) {
			return current_user_can( 'manage_options' ) ? '<p>' . esc_html__( 'Balloon leaderboard: race not found.', 'lumaha-virtual-balloon-race' ) . '</p>' : '';
		}

		global $wpdb;
		$table    = LVBR_Balloons::table();
		$rows     = array();
		$stage    = $race ? LVBR_Races::status( $race ) : '';

		$order    = LVBR_Races::ranking_column( $race );
		$by_start = LVBR_Races::FROM_START === LVBR_Races::ranking( $race );

		$ranks  = $race ? self::ranked_ids( $race ) : array();
		$remote = $race && count( $ranks ) > self::server_paging_from();

		if ( $remote ) {
			// Big race: only the first page goes in the HTML; paging and search ask the server (GET /lvbr/v1/leaderboard).
			$per_page = $per_page ? min( $per_page, 100 ) : 100;
			$rows     = self::leaderboard_page( $race, 1, $per_page, '' )['rows'];
		} elseif ( $race ) {
			// Every flying balloon is rendered so search covers the whole race; the script pages them (rows past the first page start hidden).
			$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the plugin's own table.
				$wpdb->prepare(
					"SELECT * FROM {$table} WHERE is_burst = 0 AND withdrawn_at IS NULL AND race_id = %d ORDER BY {$order} DESC, id ASC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					(int) $race->id
				)
			);
		}
		$balloons = $remote ? array_keys( $ranks ) : $rows;

		// Two distance columns, the one that decides the race first.
		$distance_columns = array(
			'from_start_km' => __( 'From launch (km)', 'lumaha-virtual-balloon-race' ),
			'distance_km'   => __( 'Travelled (km)', 'lumaha-virtual-balloon-race' ),
		);
		if ( ! $by_start ) {
			$distance_columns = array_reverse( $distance_columns, true );
		}

		// Page caches normally skip logged-in visitors, so the viewer's own balloons can go in the HTML.
		$mine = $race && is_user_logged_in() ? LVBR_Balloons::owned_by( get_current_user_id(), (int) $race->id ) : array();

		$pill = array(
			LVBR_Races::UPCOMING => array( 'waiting', __( 'Waiting', 'lumaha-virtual-balloon-race' ) ),
			LVBR_Races::ON_SALE  => array( 'waiting', __( 'Waiting', 'lumaha-virtual-balloon-race' ) ),
			LVBR_Races::FLYING   => array( 'active', __( 'Flying', 'lumaha-virtual-balloon-race' ) ),
			LVBR_Races::FINISHED => array( 'finished', __( 'Finished', 'lumaha-virtual-balloon-race' ) ),
		);
		$pill = isset( $pill[ $stage ] ) ? $pill[ $stage ] : $pill[ LVBR_Races::FLYING ];

		$subtitle = '';
		if ( $race ) {
			$dates = array(
				/* translators: %s: date */
				LVBR_Races::UPCOMING => sprintf( __( 'Entries open %s', 'lumaha-virtual-balloon-race' ), LVBR_Races::format_date( $race->sales_open_at ) ),
				/* translators: %s: date */
				LVBR_Races::ON_SALE  => sprintf( __( 'Taking entries · launches %s', 'lumaha-virtual-balloon-race' ), LVBR_Races::format_date( $race->launch_at ) ),
				LVBR_Races::FLYING   => $race->end_at
					/* translators: %s: date */
					? sprintf( __( 'Flying now · ends %s', 'lumaha-virtual-balloon-race' ), LVBR_Races::format_date( $race->end_at ) )
					: __( 'Flying now', 'lumaha-virtual-balloon-race' ),
				/* translators: %s: date */
				LVBR_Races::FINISHED => sprintf( __( 'Final results · finished %s', 'lumaha-virtual-balloon-race' ), LVBR_Races::format_date( $race->end_at ) ),
			);
			$subtitle = $dates[ $stage ];
		}

		$waiting = in_array( $stage, array( LVBR_Races::UPCOMING, LVBR_Races::ON_SALE ), true );
		$teams   = LVBR_Teams::by_id( $race );
		$context = $race ? self::row_context( $race, $map_url, wp_list_pluck( $mine, 'id' ) ) : array();

		ob_start();
		?>
		<div class="lvbr-leaderboard" data-lvbr-leaderboard data-lvbr-per-page="<?php echo esc_attr( (string) $per_page ); ?>"
			<?php if ( $remote ) : ?>
				data-lvbr-remote="<?php echo esc_url( rest_url( LVBR_Map::REST_NS . '/leaderboard' ) ); ?>"
				data-lvbr-race="<?php echo esc_attr( (string) $race->id ); ?>"
				data-lvbr-map-url="<?php echo esc_url( $map_url ); ?>"
				data-lvbr-total="<?php echo esc_attr( (string) count( $ranks ) ); ?>"
				<?php if ( is_user_logged_in() ) : ?>
					data-lvbr-nonce="<?php echo esc_attr( wp_create_nonce( 'wp_rest' ) ); ?>"
				<?php endif; ?>
			<?php endif; ?>>
			<div class="lvbr-leaderboard__header">
				<div>
					<h2 class="lvbr-leaderboard__title"><?php echo esc_html( $race ? $race->name : __( 'Balloon Race Leaderboard', 'lumaha-virtual-balloon-race' ) ); ?></h2>
					<?php if ( $subtitle ) : ?>
						<p class="lvbr-leaderboard__subtitle"><?php echo esc_html( $subtitle ); ?></p>
					<?php endif; ?>
					<?php $presented = LVBR_Sponsors::presented_by( $race ); ?>
					<?php if ( $presented ) : ?>
						<p class="lvbr-leaderboard__presented"><?php echo wp_kses_post( $presented ); ?></p>
					<?php endif; ?>
				</div>
				<div class="lvbr-leaderboard__tools">
					<?php if ( $mine ) : ?>
						<div class="lvbr-toggle" role="group" aria-label="<?php esc_attr_e( 'Show', 'lumaha-virtual-balloon-race' ); ?>">
							<button type="button" class="lvbr-toggle__btn" data-lvbr-show="all" aria-pressed="true"><?php esc_html_e( 'All balloons', 'lumaha-virtual-balloon-race' ); ?></button>
							<button type="button" class="lvbr-toggle__btn" data-lvbr-show="mine" aria-pressed="false"><?php esc_html_e( 'My balloons', 'lumaha-virtual-balloon-race' ); ?></button>
						</div>
					<?php endif; ?>
					<label class="lvbr-leaderboard__search">
						<span class="screen-reader-text"><?php esc_html_e( 'Search balloons', 'lumaha-virtual-balloon-race' ); ?></span>
						<input
							type="search"
							class="lvbr-leaderboard__search-input"
							placeholder="<?php esc_attr_e( 'Search balloon or owner name…', 'lumaha-virtual-balloon-race' ); ?>"
							autocomplete="off"
						/>
					</label>
				</div>
			</div>

			<?php if ( $mine ) : ?>
				<div class="lvbr-leaderboard__mine">
					<h3 class="lvbr-leaderboard__mine-title"><?php echo esc_html( _n( 'Your balloon', 'Your balloons', count( $mine ), 'lumaha-virtual-balloon-race' ) ); ?></h3>
					<ul class="lvbr-leaderboard__mine-list">
						<?php foreach ( $mine as $own ) : ?>
							<?php
							if ( (int) $own->is_burst ) {
								$where = __( 'Burst', 'lumaha-virtual-balloon-race' );
							} elseif ( $waiting ) {
								$where = __( 'Waiting for launch', 'lumaha-virtual-balloon-race' );
							} else {
								$where = sprintf(
									$by_start
										/* translators: 1: position, 2: number of balloons, 3: distance in km */
										? __( '#%1$s of %2$s · %3$s km from launch', 'lumaha-virtual-balloon-race' )
										/* translators: 1: position, 2: number of balloons, 3: distance in km */
										: __( '#%1$s of %2$s · %3$s km travelled', 'lumaha-virtual-balloon-race' ),
									number_format_i18n( isset( $ranks[ (int) $own->id ] ) ? $ranks[ (int) $own->id ] : 0 ),
									number_format_i18n( count( $balloons ) ),
									number_format_i18n( LVBR_Balloons::ranked_distance( $own, $race ), 1 )
								);
							}
							?>
							<li data-lvbr-balloon="<?php echo esc_attr( (string) $own->id ); ?>">
								<?php if ( $map_url ) : ?>
									<a class="lvbr-leaderboard__link" href="<?php echo esc_url( add_query_arg( 'balloon', (int) $own->id, $map_url ) ); ?>"><?php echo esc_html( $own->balloon_name ); ?></a>
								<?php else : ?>
									<strong><?php echo esc_html( $own->balloon_name ); ?></strong>
								<?php endif; ?>
								<span class="lvbr-leaderboard__mine-where"><?php echo esc_html( $where ); ?></span>
							</li>
						<?php endforeach; ?>
					</ul>
				</div>
			<?php endif; ?>

			<?php echo wp_kses_post( LVBR_Sponsors::credit( $race, 'leaderboard_strip' ) ); ?>

			<div class="lvbr-leaderboard__table-wrap">
				<table class="lvbr-leaderboard__table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Rank', 'lumaha-virtual-balloon-race' ); ?></th>
							<th><?php esc_html_e( 'Balloon Name', 'lumaha-virtual-balloon-race' ); ?></th>
							<th><?php esc_html_e( 'Owner', 'lumaha-virtual-balloon-race' ); ?></th>
							<?php if ( $teams ) : ?>
								<th><?php esc_html_e( 'Team', 'lumaha-virtual-balloon-race' ); ?></th>
							<?php endif; ?>
							<th><?php esc_html_e( 'Color', 'lumaha-virtual-balloon-race' ); ?></th>
							<?php foreach ( $distance_columns as $label ) : ?>
								<th><?php echo esc_html( $label ); ?></th>
							<?php endforeach; ?>
							<th><?php esc_html_e( 'Status', 'lumaha-virtual-balloon-race' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php if ( empty( $rows ) ) : ?>
							<tr class="lvbr-leaderboard__empty">
								<td colspan="<?php echo esc_attr( (string) ( $teams ? 8 : 7 ) ); ?>">
									<?php
									echo esc_html(
										! $race ? __( 'No race scheduled yet.', 'lumaha-virtual-balloon-race' )
											: ( LVBR_Races::FINISHED === $stage ? __( 'No balloons finished this race.', 'lumaha-virtual-balloon-race' ) : __( 'No balloons yet. Be the first in the sky!', 'lumaha-virtual-balloon-race' ) )
									);
									?>
								</td>
							</tr>
						<?php else : ?>
							<?php
							foreach ( $rows as $index => $balloon ) {
								self::render_row( $balloon, $remote ? $ranks[ (int) $balloon->id ] : $index + 1, $context, ! $remote && $per_page && $index >= $per_page );
							}
							?>
						<?php endif; ?>
					</tbody>
				</table>
			</div>
			<?php if ( $rows ) : ?>
				<?php self::render_pager( $per_page, count( $balloons ), $remote ); ?>
			<?php endif; ?>
			<p class="lvbr-leaderboard__empty-search" hidden><?php esc_html_e( 'No balloons match that search.', 'lumaha-virtual-balloon-race' ); ?></p>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * GET /lvbr/v1/leaderboard: one page of a big race's leaderboard, as rendered rows.
	 *
	 * @param WP_REST_Request $request Request (race, page, per_page, search, mine, map_url).
	 * @return WP_REST_Response|WP_Error
	 */
	public static function rest_leaderboard( $request ) {
		$race = LVBR_Races::resolve( $request->get_param( 'race' ) );

		if ( ! $race ) {
			return new WP_Error( 'lvbr_race_not_found', __( 'Race not found.', 'lumaha-virtual-balloon-race' ), array( 'status' => 404 ) );
		}

		$mine     = is_user_logged_in() ? array_map( 'intval', wp_list_pluck( LVBR_Balloons::owned_by( get_current_user_id(), (int) $race->id ), 'id' ) ) : array();
		$map_url  = (string) $request->get_param( 'map_url' );
		$map_url  = $map_url && wp_http_validate_url( $map_url ) ? $map_url : LVBR_Pages::tracking_url( $race );
		$per_page = min( 100, max( 1, (int) $request->get_param( 'per_page' ) ) );
		$result   = self::leaderboard_page( $race, (int) $request->get_param( 'page' ), $per_page, (string) $request->get_param( 'search' ), $request->get_param( 'mine' ) ? $mine : null );
		$context  = self::row_context( $race, $map_url, $mine );

		ob_start();
		foreach ( $result['rows'] as $balloon ) {
			self::render_row( $balloon, $result['ranks'][ (int) $balloon->id ], $context );
		}

		$response = rest_ensure_response(
			array(
				'html'     => ob_get_clean(),
				'total'    => $result['total'],
				'page'     => $result['page'],
				'pages'    => $result['pages'],
				'per_page' => $per_page,
			)
		);
		$response->header( 'Cache-Control', 'no-cache, must-revalidate, max-age=0' );
		do_action( 'litespeed_control_set_nocache', 'lvbr leaderboard page' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- LiteSpeed Cache's hook.

		return $response;
	}

	/**
	 * From how many balloons a leaderboard is paged and searched on the server instead of in the browser
	 * (filter `lvbr_leaderboard_server_paging_from`, default 500).
	 *
	 * @return int
	 */
	public static function server_paging_from() {
		return max( 1, (int) apply_filters( 'lvbr_leaderboard_server_paging_from', 500 ) );
	}

	/**
	 * Every ranked balloon in a race (flying or landed, not burst or withdrawn), best first: id => rank.
	 *
	 * @param object $race Race.
	 * @return array<int, int>
	 */
	public static function ranked_ids( $race ) {
		global $wpdb;

		$table = LVBR_Balloons::table();
		$order = LVBR_Races::ranking_column( $race );
		$ids   = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$table} WHERE is_burst = 0 AND withdrawn_at IS NULL AND race_id = %d ORDER BY {$order} DESC, id ASC", (int) $race->id ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the plugin's own table.

		return $ids ? array_combine( $ids, range( 1, count( $ids ) ) ) : array();
	}

	/**
	 * One page of a race's leaderboard, optionally searched (balloon name, public owner name, team: as the
	 * browser search does, so surnames stay private) or limited to some balloons ("My balloons").
	 *
	 * @param object     $race     Race.
	 * @param int        $page     Page, from 1.
	 * @param int        $per_page Rows per page.
	 * @param string     $search   Search text.
	 * @param int[]|null $only     Only these balloon IDs.
	 * @return array{rows: object[], ranks: array<int, int>, total: int, page: int, pages: int}
	 */
	public static function leaderboard_page( $race, $page, $per_page, $search = '', $only = null ) {
		global $wpdb;

		$table = LVBR_Balloons::table();
		$ranks = self::ranked_ids( $race );
		$ids   = array_keys( $ranks );

		if ( null !== $only ) {
			$ids = array_values( array_intersect( $ids, array_map( 'intval', $only ) ) );
		}

		$query = mb_strtolower( trim( (string) $search ) );

		if ( '' !== $query && $ids ) {
			$teams = LVBR_Teams::by_id( $race );
			$light = $wpdb->get_results( $wpdb->prepare( "SELECT id, race_id, balloon_name, owner_name, team_id FROM {$table} WHERE race_id = %d", (int) $race->id ), OBJECT_K ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the plugin's own table.
			$ids   = array_values(
				array_filter(
					$ids,
					static function ( $id ) use ( $light, $teams, $query ) {
						if ( ! isset( $light[ $id ] ) ) {
							return false;
						}
						$row  = $light[ $id ];
						$team = isset( $teams[ (int) $row->team_id ] ) ? ' ' . $teams[ (int) $row->team_id ]->name : '';
						return false !== mb_strpos( mb_strtolower( $row->balloon_name . ' ' . LVBR_Balloons::public_owner_name( $row ) . $team ), $query );
					}
				)
			);
		}

		$per_page = max( 1, (int) $per_page );
		$total    = count( $ids );
		$pages    = max( 1, (int) ceil( $total / $per_page ) );
		$page     = min( max( 1, (int) $page ), $pages );
		$slice    = array_slice( $ids, ( $page - 1 ) * $per_page, $per_page );
		$rows     = array();

		if ( $slice ) {
			$found = $wpdb->get_results( "SELECT * FROM {$table} WHERE id IN (" . implode( ',', $slice ) . ')', OBJECT_K ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- integer IDs.
			foreach ( $slice as $id ) {
				if ( isset( $found[ $id ] ) ) {
					$rows[] = $found[ $id ];
				}
			}
		}

		return array(
			'rows'  => $rows,
			'ranks' => $ranks,
			'total' => $total,
			'page'  => $page,
			'pages' => $pages,
		);
	}

	/**
	 * What every leaderboard row needs: teams, map link, the viewer's balloons, distance columns, status pill.
	 *
	 * @param object $race    Race.
	 * @param string $map_url Map page URL ('' = no links).
	 * @param int[]  $mine    The viewer's balloon IDs.
	 * @return array
	 */
	public static function row_context( $race, $map_url, $mine ) {
		$columns = array(
			'from_start_km' => __( 'From launch (km)', 'lumaha-virtual-balloon-race' ),
			'distance_km'   => __( 'Travelled (km)', 'lumaha-virtual-balloon-race' ),
		);
		if ( LVBR_Races::FROM_START !== LVBR_Races::ranking( $race ) ) {
			$columns = array_reverse( $columns, true );
		}

		$pills = array(
			LVBR_Races::UPCOMING => array( 'waiting', __( 'Waiting', 'lumaha-virtual-balloon-race' ) ),
			LVBR_Races::ON_SALE  => array( 'waiting', __( 'Waiting', 'lumaha-virtual-balloon-race' ) ),
			LVBR_Races::FLYING   => array( 'active', __( 'Flying', 'lumaha-virtual-balloon-race' ) ),
			LVBR_Races::FINISHED => array( 'finished', __( 'Finished', 'lumaha-virtual-balloon-race' ) ),
		);
		$stage = LVBR_Races::status( $race );

		return array(
			'teams'    => LVBR_Teams::by_id( $race ),
			'map_url'  => $map_url,
			'mine_ids' => array_map( 'intval', $mine ),
			'columns'  => $columns,
			'pill'     => isset( $pills[ $stage ] ) ? $pills[ $stage ] : $pills[ LVBR_Races::FLYING ],
		);
	}

	/**
	 * One leaderboard row (also used for the server-paged rows of big races).
	 *
	 * @param object $balloon Balloon row.
	 * @param int    $rank    Position in the race.
	 * @param array  $context From row_context(): teams, map_url, mine_ids, columns, pill.
	 * @param bool   $hidden  Start hidden (beyond the first page, for the browser to page).
	 */
	public static function render_row( $balloon, $rank, $context, $hidden = false ) {
		$owner           = LVBR_Balloons::public_owner_name( $balloon );
		$team            = isset( $context['teams'][ (int) $balloon->team_id ] ) ? $context['teams'][ (int) $balloon->team_id ] : null;
		$search_haystack = mb_strtolower( $balloon->balloon_name . ' ' . $owner . ( $team ? ' ' . $team->name : '' ) );
		$color_slug      = sanitize_html_class( strtolower( $balloon->balloon_color ) );
		$is_mine         = in_array( (int) $balloon->id, $context['mine_ids'], true );
		$map_url         = $context['map_url'];
		?>
		<tr data-lvbr-search="<?php echo esc_attr( $search_haystack ); ?>" data-lvbr-balloon="<?php echo esc_attr( (string) $balloon->id ); ?>"<?php echo $is_mine ? ' class="is-mine" data-lvbr-mine' : ''; ?><?php echo $hidden ? ' hidden' : ''; ?>>
			<td data-label="<?php esc_attr_e( 'Rank', 'lumaha-virtual-balloon-race' ); ?>"><?php echo esc_html( number_format_i18n( $rank ) ); ?></td>
			<td data-label="<?php esc_attr_e( 'Balloon Name', 'lumaha-virtual-balloon-race' ); ?>">
				<?php if ( $map_url ) : ?>
					<a class="lvbr-leaderboard__link" href="<?php echo esc_url( add_query_arg( 'balloon', (int) $balloon->id, $map_url ) ); ?>"><?php echo esc_html( $balloon->balloon_name ); ?></a>
				<?php else : ?>
					<?php echo esc_html( $balloon->balloon_name ); ?>
				<?php endif; ?>
				<?php if ( $is_mine ) : ?>
					<span class="lvbr-leaderboard__yours"><?php esc_html_e( 'Yours', 'lumaha-virtual-balloon-race' ); ?></span>
				<?php endif; ?>
			</td>
			<td data-label="<?php esc_attr_e( 'Owner', 'lumaha-virtual-balloon-race' ); ?>"><?php echo esc_html( $owner ); ?></td>
			<?php if ( $context['teams'] ) : ?>
				<td data-label="<?php esc_attr_e( 'Team', 'lumaha-virtual-balloon-race' ); ?>">
					<?php if ( $team ) : ?>
						<?php self::team_badge( $team->name, $team->color ); ?>
					<?php endif; ?>
				</td>
			<?php endif; ?>
			<td data-label="<?php esc_attr_e( 'Color', 'lumaha-virtual-balloon-race' ); ?>">
				<span class="lvbr-color lvbr-color--<?php echo esc_attr( $color_slug ); ?>">
					<?php echo esc_html( $balloon->balloon_color ); ?>
				</span>
			</td>
			<?php foreach ( $context['columns'] as $column => $label ) : ?>
				<td data-label="<?php echo esc_attr( $label ); ?>"><?php echo esc_html( number_format_i18n( (float) $balloon->{$column}, 1 ) ); ?></td>
			<?php endforeach; ?>
			<td data-label="<?php esc_attr_e( 'Status', 'lumaha-virtual-balloon-race' ); ?>">
				<span class="lvbr-pill lvbr-pill--<?php echo esc_attr( $context['pill'][0] ); ?>"><?php echo esc_html( $context['pill'][1] ); ?></span>
			</td>
		</tr>
		<?php
	}

	/**
	 * Paging controls under a leaderboard: entries per page, a summary and page links (filled in by frontend.js).
	 *
	 * @param int  $per_page Entries per page (0 = all).
	 * @param int  $total    Number of rows.
	 * @param bool $remote   Paged on the server (no "All": that would send the whole race).
	 */
	private static function render_pager( $per_page, $total, $remote = false ) {
		?>
		<div class="lvbr-pager" data-lvbr-pager>
			<label class="lvbr-pager__size">
				<?php esc_html_e( 'Show', 'lumaha-virtual-balloon-race' ); ?>
				<select class="lvbr-pager__select">
					<?php foreach ( self::per_page_options() as $option ) : ?>
						<?php
						if ( $remote && ! $option ) {
							continue;
						}
						?>
						<option value="<?php echo esc_attr( (string) $option ); ?>" <?php selected( $per_page, $option ); ?>><?php echo esc_html( $option ? number_format_i18n( $option ) : __( 'All', 'lumaha-virtual-balloon-race' ) ); ?></option>
					<?php endforeach; ?>
					<?php if ( ! in_array( $per_page, self::per_page_options(), true ) ) : ?>
						<option value="<?php echo esc_attr( (string) $per_page ); ?>" selected><?php echo esc_html( number_format_i18n( $per_page ) ); ?></option>
					<?php endif; ?>
				</select>
				<?php esc_html_e( 'per page', 'lumaha-virtual-balloon-race' ); ?>
			</label>
			<p class="lvbr-pager__summary" aria-live="polite">
				<?php
				$shown = $per_page ? min( $per_page, $total ) : $total;
				echo esc_html(
					sprintf(
						/* translators: 1: first row, 2: last row, 3: total */
						__( 'Showing %1$s–%2$s of %3$s', 'lumaha-virtual-balloon-race' ),
						number_format_i18n( $total ? 1 : 0 ),
						number_format_i18n( $shown ),
						number_format_i18n( $total )
					)
				);
				?>
			</p>
			<nav class="lvbr-pager__pages" aria-label="<?php esc_attr_e( 'Leaderboard pages', 'lumaha-virtual-balloon-race' ); ?>"></nav>
		</div>
		<?php
	}

	/**
	 * Render [lvbr_races]: links to each race on this page (shown when there are at least `min` races).
	 *
	 * @param array|string $atts Shortcode attributes.
	 * @return string
	 */
	public function render_races_shortcode( $atts ) {
		$atts = shortcode_atts(
			array(
				'title' => __( 'All races', 'lumaha-virtual-balloon-race' ),
				'min'   => 2,
			),
			$atts,
			'lvbr_races'
		);

		$races = LVBR_Races::all();

		if ( count( $races ) < max( 1, (int) $atts['min'] ) ) {
			return '';
		}

		wp_enqueue_style( 'lvbr-frontend' );

		$shown = LVBR_Races::resolve( LVBR_Races::ref_from_shortcode( array() ) );
		$base  = remove_query_arg( array( 'race', 'balloon' ) );
		$pill  = array(
			LVBR_Races::UPCOMING => 'waiting',
			LVBR_Races::ON_SALE  => 'waiting',
			LVBR_Races::FLYING   => 'active',
			LVBR_Races::FINISHED => 'finished',
		);

		ob_start();
		?>
		<nav class="lvbr-races" aria-label="<?php echo esc_attr( $atts['title'] ); ?>">
			<?php if ( '' !== $atts['title'] ) : ?>
				<h2 class="lvbr-races__title"><?php echo esc_html( $atts['title'] ); ?></h2>
			<?php endif; ?>
			<ul class="lvbr-races__list">
				<?php foreach ( $races as $race ) : ?>
					<?php
					$stage   = LVBR_Races::status( $race );
					$current = $shown && (int) $shown->id === (int) $race->id;
					$date    = LVBR_Races::FINISHED === $stage && $race->end_at ? $race->end_at : $race->launch_at;
					?>
					<li class="lvbr-races__item<?php echo $current ? ' is-current' : ''; ?>">
						<a href="<?php echo esc_url( add_query_arg( 'race', $race->slug, $base ) ); ?>"<?php echo $current ? ' aria-current="page"' : ''; ?>><?php echo esc_html( $race->name ); ?></a>
						<span class="lvbr-pill lvbr-pill--<?php echo esc_attr( $pill[ $stage ] ); ?>"><?php echo esc_html( LVBR_Races::status_label( $race ) ); ?></span>
						<?php if ( LVBR_Races::ON_SALE === $stage && 0 === LVBR_Race_Entries::places_left( $race ) ) : ?>
							<span class="lvbr-pill lvbr-pill--finished"><?php esc_html_e( 'Sold out', 'lumaha-virtual-balloon-race' ); ?></span>
						<?php endif; ?>
						<span class="lvbr-races__date"><?php echo esc_html( LVBR_Races::format_date( $date ) ); ?></span>
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
			'<span class="lvbr-team"><span class="lvbr-team__dot" style="background:%1$s"></span>%2$s</span>',
			esc_attr( sanitize_hex_color( $color ) ? $color : '#555555' ),
			esc_html( $name )
		);
	}

	/**
	 * Render [lvbr_teams]: team standings for a race (nothing when the race doesn't use teams).
	 *
	 * @param array|string $atts Shortcode attributes.
	 * @return string
	 */
	public function render_teams_shortcode( $atts ) {
		$ref  = LVBR_Races::ref_from_shortcode( $atts );
		$atts = shortcode_atts(
			array(
				'race'  => 'current',
				'title' => __( 'Team standings', 'lumaha-virtual-balloon-race' ),
			),
			$atts,
			'lvbr_teams'
		);
		$race = LVBR_Races::resolve( $ref );

		if ( ! LVBR_Teams::race_uses_teams( $race ) ) {
			return '';
		}

		wp_enqueue_style( 'lvbr-frontend' );

		$standings = LVBR_Teams::standings( $race );
		$method    = LVBR_Teams::scoring( $race );
		$labels    = LVBR_Teams::scoring_labels();
		$waiting   = in_array( LVBR_Races::status( $race ), array( LVBR_Races::UPCOMING, LVBR_Races::ON_SALE ), true );

		ob_start();
		?>
		<div class="lvbr-leaderboard lvbr-teams">
			<div class="lvbr-leaderboard__header">
				<div>
					<?php if ( '' !== $atts['title'] ) : ?>
						<h2 class="lvbr-leaderboard__title"><?php echo esc_html( $atts['title'] ); ?></h2>
					<?php endif; ?>
					<p class="lvbr-leaderboard__subtitle">
						<?php
						echo esc_html(
							$waiting
								? __( 'Teams are ranked once the balloons launch.', 'lumaha-virtual-balloon-race' )
								: sprintf(
									/* translators: 1: scoring method, e.g. Average distance; 2: how distance is measured */
									__( 'Ranked by %1$s, %2$s', 'lumaha-virtual-balloon-race' ),
									mb_strtolower( $labels[ $method ] ),
									LVBR_Races::FROM_START === LVBR_Races::ranking( $race )
										? __( 'measured from the launch point', 'lumaha-virtual-balloon-race' )
										: __( 'measured along the whole trail', 'lumaha-virtual-balloon-race' )
								)
						);
						?>
					</p>
				</div>
			</div>
			<div class="lvbr-leaderboard__table-wrap">
				<table class="lvbr-leaderboard__table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Rank', 'lumaha-virtual-balloon-race' ); ?></th>
							<th><?php esc_html_e( 'Team', 'lumaha-virtual-balloon-race' ); ?></th>
							<th><?php esc_html_e( 'Balloons', 'lumaha-virtual-balloon-race' ); ?></th>
							<th><?php echo esc_html( $labels[ $method ] . ' (km)' ); ?></th>
							<th><?php esc_html_e( 'Leading balloon', 'lumaha-virtual-balloon-race' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $standings as $team ) : ?>
							<tr>
								<td data-label="<?php esc_attr_e( 'Rank', 'lumaha-virtual-balloon-race' ); ?>"><?php echo esc_html( $team['rank'] && ! $waiting ? (string) $team['rank'] : '–' ); ?></td>
								<td data-label="<?php esc_attr_e( 'Team', 'lumaha-virtual-balloon-race' ); ?>"><?php self::team_badge( $team['name'], $team['color'] ); ?></td>
								<td data-label="<?php esc_attr_e( 'Balloons', 'lumaha-virtual-balloon-race' ); ?>"><?php echo esc_html( number_format_i18n( $team['balloons'] ) ); ?></td>
								<td data-label="<?php echo esc_attr( $labels[ $method ] ); ?>"><?php echo esc_html( $waiting ? '–' : number_format_i18n( $team['score'], 1 ) ); ?></td>
								<td data-label="<?php esc_attr_e( 'Leading balloon', 'lumaha-virtual-balloon-race' ); ?>"><?php echo esc_html( $waiting ? '' : $team['leader'] ); ?></td>
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
