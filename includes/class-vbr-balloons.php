<?php
/**
 * Balloon table access and shared balloon vocabulary.
 *
 * @package Virtual_Balloon_Race
 */

defined( 'ABSPATH' ) || exit;

/**
 * Static helpers for the balloons table.
 */
class VBR_Balloons {

	/**
	 * Fully qualified custom table name (per site on Multisite).
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;

		return $wpdb->prefix . VBR_TABLE_SUFFIX;
	}

	/**
	 * Cache-busting version for a plugin asset (changes whenever the file does).
	 *
	 * @param string $relative Path relative to the plugin folder.
	 * @return string
	 */
	public static function asset_version( $relative ) {
		$mtime = @filemtime( VBR_PLUGIN_DIR . $relative ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		return $mtime ? VBR_VERSION . '.' . $mtime : VBR_VERSION;
	}

	/**
	 * Allowed balloon colours.
	 *
	 * @return string[]
	 */
	public static function colors() {
		return array( 'Red', 'Blue', 'Green', 'Yellow', 'Purple' );
	}

	/**
	 * Helium fill strategies (stored in helium_level).
	 *
	 * @return string[]
	 */
	public static function helium_strategies() {
		return array( 'Balanced', 'Max Height', 'Eco-Glide' );
	}

	/**
	 * Human-readable helium strategy labels.
	 *
	 * @param string $strategy Strategy name.
	 * @return string
	 */
	public static function helium_label( $strategy ) {
		$labels = array(
			'Balanced'   => __( 'Balanced (Medium)', 'virtual-balloon-race' ),
			'Max Height' => __( 'Max Height (High) — faster, small burst risk', 'virtual-balloon-race' ),
			'Eco-Glide'  => __( 'Eco-Glide (Low) — slower, immune to storm bursts', 'virtual-balloon-race' ),
			'Low'        => __( 'Low', 'virtual-balloon-race' ),
			'Medium'     => __( 'Medium', 'virtual-balloon-race' ),
			'High'       => __( 'High', 'virtual-balloon-race' ),
		);

		return isset( $labels[ $strategy ] ) ? $labels[ $strategy ] : $strategy;
	}

	/**
	 * Configured launch point.
	 *
	 * @return array{lat: float, lng: float, label: string}
	 */
	public static function launch_point() {
		return array(
			'lat'   => (float) VBR_Settings::get( 'launch_lat' ),
			'lng'   => (float) VBR_Settings::get( 'launch_lng' ),
			'label' => (string) VBR_Settings::get( 'launch_label' ),
		);
	}

	/**
	 * Decode a stored trail into [[lat, lng], ...].
	 *
	 * @param string|null $json Stored JSON.
	 * @return array
	 */
	public static function decode_trail( $json ) {
		$points = json_decode( (string) $json, true );

		if ( ! is_array( $points ) ) {
			return array();
		}

		$clean = array();
		foreach ( $points as $point ) {
			if ( is_array( $point ) && 2 === count( $point ) && is_numeric( $point[0] ) && is_numeric( $point[1] ) ) {
				$clean[] = array( (float) $point[0], (float) $point[1] );
			}
		}

		return $clean;
	}

	/**
	 * Encode a trail, keeping only the most recent points.
	 *
	 * @param array    $points [[lat, lng], ...].
	 * @param int|null $cap    Max points (defaults to the trail length setting).
	 * @return string
	 */
	public static function encode_trail( $points, $cap = null ) {
		$cap    = null === $cap ? (int) VBR_Settings::get( 'trail_cap' ) : (int) $cap;
		$points = array_slice( array_values( $points ), -max( 2, $cap ) );

		$rounded = array_map(
			static function ( $p ) {
				return array( round( (float) $p[0], 5 ), round( (float) $p[1], 5 ) );
			},
			$points
		);

		return wp_json_encode( $rounded );
	}

	/**
	 * Position columns for a brand new balloon starting at a point.
	 *
	 * @param float $lat Start latitude.
	 * @param float $lng Start longitude.
	 * @return array Column => value.
	 */
	public static function start_columns( $lat, $lng ) {
		return array(
			'latitude'   => (float) $lat,
			'longitude'  => (float) $lng,
			'start_lat'  => (float) $lat,
			'start_lng'  => (float) $lng,
			'trail_json' => self::encode_trail( array( array( $lat, $lng ) ) ),
		);
	}

	/**
	 * SQL condition matching a user's balloons: bought while logged in, or with their account or billing email.
	 *
	 * @param int $user_id User ID.
	 * @return string Empty when the user doesn't exist.
	 */
	public static function owner_where( $user_id ) {
		global $wpdb;

		$user = get_userdata( (int) $user_id );

		if ( ! $user ) {
			return '';
		}

		$sql    = $wpdb->prepare( 'user_id = %d', $user->ID );
		$emails = array_unique( array_filter( array( strtolower( $user->user_email ), strtolower( (string) get_user_meta( $user->ID, 'billing_email', true ) ) ) ) );

		foreach ( $emails as $email ) {
			$sql .= $wpdb->prepare( ' OR owner_email = %s', $email );
		}

		return '(' . $sql . ')';
	}

	/**
	 * A user's balloons, newest race first.
	 *
	 * @param int $user_id User ID.
	 * @param int $race_id Only this race (0 = all races).
	 * @return object[]
	 */
	public static function owned_by( $user_id, $race_id = 0 ) {
		global $wpdb;

		$where = self::owner_where( $user_id );

		if ( ! $where ) {
			return array();
		}

		$table = self::table();
		$races = VBR_Races::table();
		$race  = $race_id ? $wpdb->prepare( ' AND b.race_id = %d', $race_id ) : '';

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- parts prepared above.
		return (array) $wpdb->get_results( "SELECT b.* FROM {$table} b LEFT JOIN {$races} r ON r.id = b.race_id WHERE {$where}{$race} ORDER BY r.launch_at DESC, b.id ASC" );
	}

	/**
	 * Leaderboard position of a balloon in its race (null when burst).
	 *
	 * @param object $balloon Balloon row.
	 * @return int|null
	 */
	public static function rank( $balloon ) {
		global $wpdb;

		if ( (int) $balloon->is_burst ) {
			return null;
		}

		$table  = self::table();
		$race   = VBR_Races::get( (int) $balloon->race_id );
		$column = VBR_Races::ranking_column( $race );
		$value  = self::ranked_distance( $balloon, $race );

		return 1 + (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE race_id = %d AND is_burst = 0 AND ( {$column} > %f OR ( {$column} = %f AND id < %d ) )", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				(int) $balloon->race_id,
				$value,
				$value,
				(int) $balloon->id
			)
		);
	}

	/**
	 * Owner name as the public sees it: first name and last initial ("Luke H.").
	 *
	 * @param object $balloon Balloon row (needs owner_name).
	 * @return string
	 */
	public static function public_owner_name( $balloon ) {
		$parts = preg_split( '/\s+/u', trim( (string) $balloon->owner_name ), -1, PREG_SPLIT_NO_EMPTY );
		$name  = '';

		if ( $parts ) {
			$name = $parts[0];
			if ( count( $parts ) > 1 ) {
				$name .= ' ' . mb_strtoupper( mb_substr( end( $parts ), 0, 1 ) ) . '.';
			}
		}

		return (string) apply_filters( 'vbr_public_owner_name', $name, $balloon );
	}

	/**
	 * Balloons created from an order.
	 *
	 * @param int $order_id Order ID.
	 * @return object[]
	 */
	public static function for_order( $order_id ) {
		global $wpdb;

		$table = self::table();

		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE order_id = %d ORDER BY id ASC", (int) $order_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Where a balloon is measured from: its own start (custom launch point), else its race's launch point.
	 *
	 * @param object $balloon Balloon row (start_lat, start_lng, race_id).
	 * @return float[] [lat, lng]
	 */
	public static function start_point( $balloon ) {
		if ( null !== $balloon->start_lat && null !== $balloon->start_lng ) {
			return array( (float) $balloon->start_lat, (float) $balloon->start_lng );
		}

		$launch = VBR_Races::launch_point( VBR_Races::get( (int) $balloon->race_id ) );

		return array( (float) $launch['lat'], (float) $launch['lng'] );
	}

	/**
	 * 1.5.0: work out the straight-line distance from start for existing balloons.
	 */
	public static function backfill_from_start() {
		global $wpdb;

		$table = self::table();
		$rows  = (array) $wpdb->get_results( "SELECT id, race_id, latitude, longitude, start_lat, start_lng FROM {$table} WHERE latitude IS NOT NULL AND longitude IS NOT NULL" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		foreach ( $rows as $row ) {
			list( $lat, $lng ) = self::start_point( $row );

			$wpdb->update(
				$table,
				array( 'from_start_km' => round( VBR_Geo::distance_km( $lat, $lng, (float) $row->latitude, (float) $row->longitude ), 2 ) ),
				array( 'id' => (int) $row->id ),
				array( '%f' ),
				array( '%d' )
			);
		}
	}

	/**
	 * The distance a race ranks balloons by.
	 *
	 * @param object      $balloon Balloon row.
	 * @param object|null $race    Race.
	 * @return float
	 */
	public static function ranked_distance( $balloon, $race ) {
		return (float) $balloon->{VBR_Races::ranking_column( $race )};
	}

	/**
	 * 1.6.0: balloons that have already flown get their flight time (launch to now, or to the race end).
	 */
	public static function backfill_flight_hours() {
		global $wpdb;

		$table = self::table();

		foreach ( VBR_Races::all() as $race ) {
			$launch = VBR_Races::ts( $race->launch_at );
			$end    = $race->end_at ? min( time(), VBR_Races::ts( $race->end_at ) ) : time();
			$hours  = $launch && $end > $launch ? ( $end - $launch ) / HOUR_IN_SECONDS : 0;

			if ( $hours > 0 ) {
				$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET flight_hours = %f WHERE race_id = %d AND distance_km > 0", $hours, (int) $race->id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			}
		}
	}
}
