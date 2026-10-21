<?php
/**
 * WP-CLI commands: `wp vbr tick`, `wp vbr races`, `wp vbr status`, `wp vbr demo`.
 *
 * @package Virtual_Balloon_Race
 */

defined( 'ABSPATH' ) || exit;

/**
 * Manage the balloon race from the command line.
 */
class VBR_CLI {

	/**
	 * Run the scheduled update now, or simulate hours of drift for one race.
	 *
	 * Point a system cron at `wp vbr tick` for accurate timing on low-traffic sites.
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
	 *     wp vbr tick
	 *     wp vbr tick --race=summer-2026 --hours=6
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Named args.
	 */
	public function tick( $args, $assoc_args ) {
		if ( isset( $assoc_args['hours'] ) ) {
			$race = VBR_Races::resolve( isset( $assoc_args['race'] ) ? $assoc_args['race'] : 'current' );

			if ( ! $race ) {
				WP_CLI::error( 'Race not found.' );
			}

			$results = array(
				(int) $race->id => VBR_Cron::tick(
					max( 0, (float) $assoc_args['hours'] ),
					array(
						'race_id' => (int) $race->id,
						'source'  => 'cli',
					)
				),
			);
		} else {
			if ( ! VBR_Settings::interval_seconds() ) {
				WP_CLI::warning( 'Automatic updates are off in Settings, so nothing moved. Use --race and --hours to move a race by hand.' );
				return;
			}
			$results = VBR_Cron::run_scheduled();
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

		foreach ( VBR_Races::all() as $race ) {
			$rows[] = array(
				'id'       => $race->id,
				'name'     => $race->name,
				'slug'     => $race->slug,
				'stage'    => VBR_Races::status_label( $race ),
				'opens'    => VBR_Races::format_date( $race->sales_open_at ),
				'launch'   => VBR_Races::format_date( $race->launch_at ),
				'end'      => VBR_Races::format_date( $race->end_at ),
				'balloons' => VBR_Races::balloon_count( $race->id ),
			);
		}

		WP_CLI\Utils\format_items( 'table', $rows, array( 'id', 'name', 'slug', 'stage', 'opens', 'launch', 'end', 'balloons' ) );
	}

	/**
	 * Show schedule and the last update for each race.
	 */
	public function status() {
		$next = VBR_Cron::next_run();

		WP_CLI::line( 'Interval:    ' . VBR_Settings::get( 'interval' ) );
		WP_CLI::line( 'Next run:    ' . ( $next ? wp_date( 'Y-m-d H:i:s', $next ) : 'not scheduled' ) );
		WP_CLI::line( 'Scheduler:   ' . ( function_exists( 'as_schedule_recurring_action' ) ? 'Action Scheduler' : 'WP-Cron' ) );

		foreach ( VBR_Races::all() as $race ) {
			WP_CLI::line( sprintf( 'Race %d (%s, %s): last tick %s', $race->id, $race->name, VBR_Races::status_label( $race ), wp_json_encode( VBR_Cron::last_summary( $race->id ) ) ) );
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
	 *     wp vbr demo
	 *     wp vbr demo --balloons=60
	 *     wp vbr demo --remove
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Options.
	 */
	public function demo( $args, $assoc_args ) {
		if ( isset( $assoc_args['remove'] ) ) {
			WP_CLI::success( sprintf( 'Removed %d demo race(s).', VBR_Demo::remove() ) );
			return;
		}

		$race_id = VBR_Demo::create( max( 1, (int) ( isset( $assoc_args['balloons'] ) ? $assoc_args['balloons'] : 30 ) ) );

		if ( is_wp_error( $race_id ) ) {
			WP_CLI::error( $race_id->get_error_message() );
		}

		WP_CLI::success( sprintf( 'Demo race %d created: %s', $race_id, VBR_Pages::tracking_url( VBR_Races::get( $race_id ) ) ) );
	}
}
