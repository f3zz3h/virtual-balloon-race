<?php
/**
 * Races: scheduling, stages and lookups.
 *
 * @package Virtual_Balloon_Race
 */

defined( 'ABSPATH' ) || exit;

/**
 * A site can schedule several races. Each has an entries-open time, a launch
 * time and an optional end time; its stage is worked out from the clock.
 *
 * Times are stored in UTC (`*_gmt`-style MySQL datetimes).
 */
class VBR_Races {

	const UPCOMING = 'upcoming';
	const ON_SALE  = 'on_sale';
	const FLYING   = 'flying';
	const FINISHED = 'finished';

	/**
	 * Per-request cache of rows by ID.
	 *
	 * @var array
	 */
	private static $cache = array();

	const FROM_START = 'from_start';
	const TRAVELLED  = 'travelled';

	/**
	 * How a race decides its winner.
	 *
	 * @return array
	 */
	public static function ranking_labels() {
		return array(
			self::FROM_START => __( 'Distance from launch (as the crow flies)', 'virtual-balloon-race' ),
			self::TRAVELLED  => __( 'Distance travelled (whole trail)', 'virtual-balloon-race' ),
		);
	}

	/**
	 * A race's ranking rule (distance from launch unless it chose distance travelled).
	 *
	 * @param object|null $race Race.
	 * @return string
	 */
	public static function ranking( $race ) {
		return $race && isset( $race->ranking ) && self::TRAVELLED === $race->ranking ? self::TRAVELLED : self::FROM_START;
	}

	/**
	 * Balloons-table column a race is ranked by (a fixed column name, safe to put in SQL).
	 *
	 * @param object|null $race Race.
	 * @return string
	 */
	public static function ranking_column( $race ) {
		return self::TRAVELLED === self::ranking( $race ) ? 'distance_km' : 'from_start_km';
	}

	/**
	 * Races table name.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;

		return $wpdb->prefix . 'vbr_races';
	}

	/**
	 * Stage labels.
	 *
	 * @return array
	 */
	public static function status_labels() {
		return array(
			self::UPCOMING => __( 'Upcoming', 'virtual-balloon-race' ),
			self::ON_SALE  => __( 'Entries open', 'virtual-balloon-race' ),
			self::FLYING   => __( 'Flying', 'virtual-balloon-race' ),
			self::FINISHED => __( 'Finished', 'virtual-balloon-race' ),
		);
	}

	/**
	 * Forget cached rows (after writes).
	 */
	public static function flush() {
		self::$cache = array();
		VBR_Race_Entries::flush_hidden();
	}

	/**
	 * One race by ID.
	 *
	 * @param int $id Race ID.
	 * @return object|null
	 */
	public static function get( $id ) {
		global $wpdb;

		$id = (int) $id;
		if ( $id <= 0 ) {
			return null;
		}

		if ( ! array_key_exists( $id, self::$cache ) ) {
			$table              = self::table();
			self::$cache[ $id ] = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the plugin's own table.
		}

		return self::$cache[ $id ];
	}

	/**
	 * All races, newest launch first.
	 *
	 * @return object[]
	 */
	public static function all() {
		global $wpdb;

		$table = self::table();
		$rows  = (array) $wpdb->get_results( "SELECT * FROM {$table} ORDER BY launch_at DESC, id DESC" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the plugin's own table.

		foreach ( $rows as $row ) {
			self::$cache[ (int) $row->id ] = $row;
		}

		return $rows;
	}

	/**
	 * Find a race from a shortcode / REST reference: ID, slug or "current".
	 *
	 * @param mixed $ref Reference.
	 * @return object|null
	 */
	public static function resolve( $ref ) {
		global $wpdb;

		$ref = is_string( $ref ) ? trim( $ref ) : $ref;

		if ( '' === $ref || null === $ref || 'current' === $ref ) {
			return self::current();
		}

		if ( is_numeric( $ref ) ) {
			return self::get( (int) $ref );
		}

		$table = self::table();
		$id    = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE slug = %s", sanitize_title( $ref ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the plugin's own table.

		return $id ? self::get( $id ) : null;
	}

	/**
	 * Race reference for a shortcode: its `race` attribute, else a valid `?race=` on the page, else "current".
	 *
	 * @param array|string $atts Raw shortcode attributes.
	 * @return string
	 */
	public static function ref_from_shortcode( $atts ) {
		if ( is_array( $atts ) && isset( $atts['race'] ) && '' !== trim( (string) $atts['race'] ) ) {
			return (string) $atts['race'];
		}

		$query = isset( $_GET['race'] ) ? sanitize_title( wp_unslash( $_GET['race'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		return '' !== $query && self::resolve( $query ) ? $query : 'current';
	}

	/**
	 * The race to show by default: flying (latest launch), else the next one
	 * taking entries, else the next upcoming, else the last finished.
	 *
	 * @return object|null
	 */
	public static function current() {
		$by_status = array();

		foreach ( self::all() as $race ) {
			$by_status[ self::status( $race ) ][] = $race;
		}

		if ( ! empty( $by_status[ self::FLYING ] ) ) {
			return $by_status[ self::FLYING ][0];
		}

		foreach ( array( self::ON_SALE, self::UPCOMING ) as $status ) {
			if ( ! empty( $by_status[ $status ] ) ) {
				// all() is newest-first; the soonest launch is last.
				return end( $by_status[ $status ] );
			}
		}

		return ! empty( $by_status[ self::FINISHED ] ) ? $by_status[ self::FINISHED ][0] : null;
	}

	/**
	 * Races currently flying.
	 *
	 * @return object[]
	 */
	public static function flying() {
		return array_values(
			array_filter(
				self::all(),
				static function ( $race ) {
					return self::FLYING === self::status( $race );
				}
			)
		);
	}

	/**
	 * Races taking entries, soonest launch first.
	 *
	 * @param int $only_id Limit to this race (product locked to a race), 0 for any.
	 * @return object[]
	 */
	public static function open_for_entries( $only_id = 0 ) {
		$open = array_filter(
			self::all(),
			static function ( $race ) use ( $only_id ) {
				return self::ON_SALE === self::status( $race ) && ( ! $only_id || (int) $race->id === (int) $only_id );
			}
		);

		return array_reverse( array_values( $open ) );
	}

	/**
	 * Next race whose entries haven't opened yet (for "entries open on …").
	 *
	 * @param int $only_id Limit to this race, 0 for any.
	 * @return object|null
	 */
	public static function next_upcoming( $only_id = 0 ) {
		$next = null;

		foreach ( self::all() as $race ) {
			if ( self::UPCOMING === self::status( $race ) && ( ! $only_id || (int) $race->id === (int) $only_id ) ) {
				$next = $race; // Newest-first, so the last match opens soonest.
			}
		}

		return $next;
	}

	/**
	 * Whether a race accepts entries right now.
	 *
	 * @param object|int|null $race Race or ID.
	 * @return bool
	 */
	public static function is_open( $race ) {
		$race = is_object( $race ) ? $race : self::get( (int) $race );

		return $race && self::ON_SALE === self::status( $race );
	}

	/**
	 * Stage of a race at a moment.
	 *
	 * @param object   $race Race row.
	 * @param int|null $now  Timestamp (default now).
	 * @return string
	 */
	public static function status( $race, $now = null ) {
		$now    = null === $now ? time() : (int) $now;
		$opens  = self::ts( $race->sales_open_at );
		$launch = self::ts( $race->launch_at );
		$end    = self::ts( $race->end_at );

		if ( $end && $now >= $end ) {
			return self::FINISHED;
		}

		if ( $launch && $now >= $launch ) {
			return self::FLYING;
		}

		if ( $opens && $now < $opens ) {
			return self::UPCOMING;
		}

		return self::ON_SALE;
	}

	/**
	 * Label for a race's stage.
	 *
	 * @param object $race Race row.
	 * @return string
	 */
	public static function status_label( $race ) {
		$labels = self::status_labels();

		return $labels[ self::status( $race ) ];
	}

	/**
	 * UTC MySQL datetime → timestamp (0 for empty).
	 *
	 * @param string|null $gmt Datetime.
	 * @return int
	 */
	public static function ts( $gmt ) {
		if ( empty( $gmt ) || '0000-00-00 00:00:00' === $gmt ) {
			return 0;
		}

		$ts = strtotime( $gmt . ' UTC' );

		return $ts ? (int) $ts : 0;
	}

	/**
	 * Site-local "Y-m-d\TH:i" (datetime-local input) → UTC MySQL datetime, or null.
	 *
	 * @param string $local Local datetime.
	 * @return string|null
	 */
	public static function local_input_to_gmt( $local ) {
		$local = trim( (string) $local );

		if ( '' === $local ) {
			return null;
		}

		$date = date_create_immutable_from_format( 'Y-m-d\TH:i', $local, wp_timezone() );

		return $date ? $date->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' ) : null;
	}

	/**
	 * UTC MySQL datetime → site-local "Y-m-d\TH:i" for a datetime-local input.
	 *
	 * @param string|null $gmt Datetime.
	 * @return string
	 */
	public static function gmt_to_local_input( $gmt ) {
		$ts = self::ts( $gmt );

		return $ts ? wp_date( 'Y-m-d\TH:i', $ts ) : '';
	}

	/**
	 * Human date in the site timezone.
	 *
	 * @param string|null $gmt Datetime.
	 * @return string
	 */
	public static function format_date( $gmt ) {
		$ts = self::ts( $gmt );

		return $ts ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $ts ) : '';
	}

	/**
	 * Launch point of a race.
	 *
	 * @param object|null $race Race row (null → Settings default).
	 * @return array{lat: float, lng: float, label: string}
	 */
	public static function launch_point( $race ) {
		if ( ! $race ) {
			return VBR_Balloons::launch_point();
		}

		return array(
			'lat'   => (float) $race->launch_lat,
			'lng'   => (float) $race->launch_lng,
			'label' => (string) $race->launch_label,
		);
	}

	/**
	 * Number of balloons in a race.
	 *
	 * @param int $race_id Race ID.
	 * @return int
	 */
	public static function balloon_count( $race_id ) {
		global $wpdb;

		$table = VBR_Balloons::table();

		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE race_id = %d", (int) $race_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the plugin's own table.
	}

	/**
	 * Create or update a race from clean data.
	 *
	 * @param array $data {name, launch_lat, launch_lng, launch_label, sales_open_at, launch_at, end_at} (UTC datetimes).
	 * @param int   $id   Existing race ID, or 0 to create.
	 * @return int|WP_Error Race ID.
	 */
	public static function save( array $data, $id = 0 ) {
		global $wpdb;

		$name = isset( $data['name'] ) ? sanitize_text_field( $data['name'] ) : '';

		if ( '' === $name ) {
			return new WP_Error( 'vbr_race_name', __( 'Please give the race a name.', 'virtual-balloon-race' ) );
		}

		if ( empty( $data['launch_at'] ) ) {
			return new WP_Error( 'vbr_race_launch', __( 'Please set a launch time.', 'virtual-balloon-race' ) );
		}

		if ( ! VBR_Geo::is_valid( isset( $data['launch_lat'] ) ? $data['launch_lat'] : null, isset( $data['launch_lng'] ) ? $data['launch_lng'] : null ) ) {
			return new WP_Error( 'vbr_race_point', __( 'Please choose a valid launch point.', 'virtual-balloon-race' ) );
		}

		$launch = self::ts( $data['launch_at'] );
		$opens  = self::ts( isset( $data['sales_open_at'] ) ? $data['sales_open_at'] : null );
		$end    = self::ts( isset( $data['end_at'] ) ? $data['end_at'] : null );

		if ( $opens && $opens >= $launch ) {
			return new WP_Error( 'vbr_race_opens', __( 'Entries must open before the launch time.', 'virtual-balloon-race' ) );
		}

		if ( $end && $end <= $launch ) {
			return new WP_Error( 'vbr_race_end', __( 'The end time must be after the launch time.', 'virtual-balloon-race' ) );
		}

		$row = array(
			'name'          => $name,
			'slug'          => self::unique_slug( $name, (int) $id ),
			'launch_lat'    => (float) $data['launch_lat'],
			'launch_lng'    => (float) $data['launch_lng'],
			'launch_label'  => isset( $data['launch_label'] ) ? sanitize_text_field( $data['launch_label'] ) : '',
			'sales_open_at' => $opens ? gmdate( 'Y-m-d H:i:s', $opens ) : null,
			'launch_at'     => gmdate( 'Y-m-d H:i:s', $launch ),
			'end_at'        => $end ? gmdate( 'Y-m-d H:i:s', $end ) : null,
		);

		if ( isset( $data['entry_limit'] ) ) {
			$row['entry_limit'] = max( 0, (int) $data['entry_limit'] );
		}

		if ( isset( $data['flight_mode'] ) ) {
			$row['flight_mode'] = array_key_exists( $data['flight_mode'], VBR_Flight_Modes::all() ) ? $data['flight_mode'] : '';
		}

		if ( isset( $data['ranking'] ) ) {
			$row['ranking'] = self::TRAVELLED === $data['ranking'] ? self::TRAVELLED : self::FROM_START;
		}

		if ( isset( $data['team_scoring'] ) ) {
			$row['team_scoring'] = isset( VBR_Teams::scoring_labels()[ $data['team_scoring'] ] ) ? $data['team_scoring'] : '';
		}

		if ( $id ) {
			$ok = $wpdb->update( self::table(), $row, array( 'id' => (int) $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the plugin's own table.
		} else {
			$row['created_at'] = current_time( 'mysql', true );
			$ok                = $wpdb->insert( self::table(), $row ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the plugin's own table.
			$id                = (int) $wpdb->insert_id;
		}

		self::flush();

		if ( false === $ok ) {
			return new WP_Error( 'vbr_race_db', __( 'The race could not be saved.', 'virtual-balloon-race' ) );
		}

		// Balloons that haven't launched yet follow a moved launch point.
		self::move_waiting_balloons( (int) $id );

		return (int) $id;
	}

	/**
	 * Keep not-yet-launched balloons (without a paid custom start) on the race's launch point.
	 *
	 * @param int $race_id Race ID.
	 */
	private static function move_waiting_balloons( $race_id ) {
		global $wpdb;

		$race = self::get( $race_id );

		if ( ! $race || ! in_array( self::status( $race ), array( self::UPCOMING, self::ON_SALE ), true ) ) {
			return;
		}

		$table = VBR_Balloons::table();
		$ids   = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$table} WHERE race_id = %d AND has_custom_start = 0", $race_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the plugin's own table.

		foreach ( $ids as $balloon_id ) {
			$wpdb->update( $table, VBR_Balloons::start_columns( $race->launch_lat, $race->launch_lng ), array( 'id' => (int) $balloon_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the plugin's own table.
		}
	}

	/**
	 * Unique slug from a name.
	 *
	 * @param string $name Name.
	 * @param int    $id   Race being edited (ignored in the clash check).
	 * @return string
	 */
	private static function unique_slug( $name, $id ) {
		global $wpdb;

		$table = self::table();
		$base  = sanitize_title( $name );
		$base  = '' !== $base ? $base : 'race';
		$slug  = $base;
		$n     = 2;

		while ( (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE slug = %s AND id <> %d", $slug, $id ) ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the plugin's own table.
			$slug = $base . '-' . $n++;
		}

		return $slug;
	}

	/**
	 * Set one timestamp column (Launch now / Finish now).
	 *
	 * @param int    $id     Race ID.
	 * @param string $column 'launch_at' | 'end_at' | 'last_tick_at'.
	 * @param int    $ts     Timestamp.
	 */
	public static function set_time( $id, $column, $ts ) {
		global $wpdb;

		if ( ! in_array( $column, array( 'launch_at', 'end_at', 'last_tick_at', 'launch_email_sent_at', 'results_email_sent_at' ), true ) ) {
			return;
		}

		$wpdb->update( self::table(), array( $column => gmdate( 'Y-m-d H:i:s', (int) $ts ) ), array( 'id' => (int) $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the plugin's own table.
		self::flush();
	}

	/**
	 * Delete a race that has no balloons.
	 *
	 * @param int $id Race ID.
	 * @return true|WP_Error
	 */
	public static function delete( $id ) {
		global $wpdb;

		if ( self::balloon_count( $id ) > 0 ) {
			return new WP_Error( 'vbr_race_has_balloons', __( 'This race has balloons, so it can’t be deleted.', 'virtual-balloon-race' ) );
		}

		$wpdb->delete( self::table(), array( 'id' => (int) $id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the plugin's own table.
		VBR_Teams::delete_for_race( $id );
		VBR_Sponsors::delete_for_race( $id );
		VBR_Prizes::delete_for_race( $id );
		self::flush();

		return true;
	}

	/**
	 * Public race fields.
	 *
	 * @param object|null $race Race.
	 * @return array|null
	 */
	public static function to_public( $race ) {
		if ( ! $race ) {
			return null;
		}

		$iso = static function ( $gmt ) {
			$ts = self::ts( $gmt );
			return $ts ? gmdate( 'c', $ts ) : null;
		};

		return array(
			'id'           => (int) $race->id,
			'name'         => $race->name,
			'slug'         => $race->slug,
			'status'       => self::status( $race ),
			'status_label' => self::status_label( $race ),
			'entries_open' => $iso( $race->sales_open_at ),
			'launch_at'    => $iso( $race->launch_at ),
			'end_at'       => $iso( $race->end_at ),
			'launch'       => self::launch_point( $race ),
			'entry_limit'  => (int) $race->entry_limit ? (int) $race->entry_limit : null,
			'places_left'  => VBR_Race_Entries::places_left( $race ),
		);
	}
}
