<?php
/**
 * WP-CLI commands: `wp lvbr tick`, `wp lvbr races`, `wp lvbr status`, `wp lvbr demo`.
 *
 * @package Virtual_Balloon_Race
 */

defined( 'ABSPATH' ) || exit;

/**
 * Manage the balloon race from the command line.
 */
class LVBR_CLI {

	/**
	 * Run the scheduled update now, or simulate hours of drift for one race.
	 *
	 * Point a system cron at `wp lvbr tick` for accurate timing on low-traffic sites.
	 *
	 * ## OPTIONS
	 *
	 * [--race=<id>]
	 * : Race ID or slug (required with --hours).
	 *
	 * [--hours=<hours>]
	 * : Simulate this many hours instead of the time since the last update.
	 *
	 * ## EXAMPLES
	 *
	 *     wp lvbr tick
	 *     wp lvbr tick --race=summer-2026 --hours=6
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Named args.
	 */
	public function tick( $args, $assoc_args ) {
		if ( isset( $assoc_args['hours'] ) ) {
			$race = LVBR_Races::resolve( isset( $assoc_args['race'] ) ? $assoc_args['race'] : 'current' );

			if ( ! $race ) {
				WP_CLI::error( 'Race not found.' );
			}

			$results = array(
				(int) $race->id => LVBR_Cron::tick(
					max( 0, (float) $assoc_args['hours'] ),
					array(
						'race_id' => (int) $race->id,
						'source'  => 'cli',
					)
				),
			);
		} else {
			if ( ! LVBR_Cron::schedule_seconds() ) {
				WP_CLI::warning( 'Automatic updates are off in Settings and for every race, so nothing moved. Use --race and --hours to move a race by hand.' );
				return;
			}
			$results = LVBR_Cron::run_scheduled();
		}

		if ( ! $results ) {
			WP_CLI::success( 'No races are flying.' );
			return;
		}

		foreach ( $results as $race_id => $summary ) {
			if ( ! empty( $summary['locked'] ) ) {
				WP_CLI::warning( "Race {$race_id}: another update is already running." );
				continue;
			}
			WP_CLI::success( "Race {$race_id}: " . wp_json_encode( $summary ) );
		}
	}

	/**
	 * List races with their stage and balloon count.
	 *
	 * @subcommand races
	 */
	public function races() {
		$rows = array();

		foreach ( LVBR_Races::all() as $race ) {
			$rows[] = array(
				'id'       => $race->id,
				'name'     => $race->name,
				'slug'     => $race->slug,
				'stage'    => LVBR_Races::status_label( $race ),
				'opens'    => LVBR_Races::format_date( $race->sales_open_at ),
				'launch'   => LVBR_Races::format_date( $race->launch_at ),
				'end'      => LVBR_Races::format_date( $race->end_at ),
				'balloons' => LVBR_Races::balloon_count( $race->id ),
			);
		}

		WP_CLI\Utils\format_items( 'table', $rows, array( 'id', 'name', 'slug', 'stage', 'opens', 'launch', 'end', 'balloons' ) );
	}

	/**
	 * Show schedule and the last update for each race.
	 */
	public function status() {
		$next = LVBR_Cron::next_run();

		WP_CLI::line( 'Interval:    ' . LVBR_Settings::get( 'interval' ) . ' (site default; runs every ' . (int) ( LVBR_Cron::schedule_seconds() / MINUTE_IN_SECONDS ) . ' min for the races)' );
		WP_CLI::line( 'Next run:    ' . ( $next ? wp_date( 'Y-m-d H:i:s', $next ) : 'not scheduled' ) );
		WP_CLI::line( 'Scheduler:   ' . ( function_exists( 'as_schedule_recurring_action' ) ? 'Action Scheduler' : 'WP-Cron' ) );

		foreach ( LVBR_Races::all() as $race ) {
			$own = LVBR_Races::movement_overrides( $race );
			WP_CLI::line( sprintf( 'Race %d (%s, %s): interval %s, own settings %s, last tick %s', $race->id, $race->name, LVBR_Races::status_label( $race ), LVBR_Races::movement( $race )['interval'], $own ? wp_json_encode( $own ) : 'none', wp_json_encode( LVBR_Cron::last_summary( $race->id ) ) ) );
		}
	}

	/**
	 * Create a demo race with made-up balloons (flown on the wind for three days), or remove demo races.
	 *
	 * ## OPTIONS
	 *
	 * [--balloons=<count>]
	 * : How many balloons. Default 30.
	 *
	 * [--remove]
	 * : Remove all demo races and their balloons instead.
	 *
	 * ## EXAMPLES
	 *
	 *     wp lvbr demo
	 *     wp lvbr demo --balloons=60
	 *     wp lvbr demo --remove
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Options.
	 */
	public function demo( $args, $assoc_args ) {
		if ( isset( $assoc_args['remove'] ) ) {
			WP_CLI::success( sprintf( 'Removed %d demo race(s).', LVBR_Demo::remove() ) );
			return;
		}

		$race_id = LVBR_Demo::create( max( 1, (int) ( isset( $assoc_args['balloons'] ) ? $assoc_args['balloons'] : 30 ) ) );

		if ( is_wp_error( $race_id ) ) {
			WP_CLI::error( $race_id->get_error_message() );
		}

		WP_CLI::success( sprintf( 'Demo race %d created: %s', $race_id, LVBR_Pages::tracking_url( LVBR_Races::get( $race_id ) ) ) );
	}
}
