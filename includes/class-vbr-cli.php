<?php
/**
 * WP-CLI commands: `wp vbr tick`, `wp vbr status`.
 *
 * @package Virtual_Balloon_Race
 */

defined( 'ABSPATH' ) || exit;

/**
 * Manage the balloon race from the command line.
 */
class VBR_CLI {

	/**
	 * Run the scheduled update now (or simulate a number of hours).
	 *
	 * Point a system cron at `wp vbr tick` for accurate timing on low-traffic sites.
	 *
	 * ## OPTIONS
	 *
	 * [--hours=<hours>]
	 * : Simulate this many hours of drift instead of the time since the last update.
	 *
	 * ## EXAMPLES
	 *
	 *     wp vbr tick
	 *     wp vbr tick --hours=6
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Named args.
	 */
	public function tick( $args, $assoc_args ) {
		if ( isset( $assoc_args['hours'] ) ) {
			$summary = VBR_Cron::tick( max( 0, (float) $assoc_args['hours'] ), array( 'source' => 'cli' ) );
		} else {
			VBR_Cron::run_scheduled();
			$summary = get_option( 'vbr_last_tick_summary', array() );
		}

		if ( ! empty( $summary['locked'] ) ) {
			WP_CLI::warning( 'Another update is already running.' );
			return;
		}

		WP_CLI::success( wp_json_encode( $summary ) );
	}

	/**
	 * Show schedule and last update.
	 */
	public function status() {
		$next = VBR_Cron::next_run();

		WP_CLI::line( 'Interval:    ' . VBR_Settings::get( 'interval' ) );
		WP_CLI::line( 'Next run:    ' . ( $next ? wp_date( 'Y-m-d H:i:s', $next ) : 'not scheduled' ) );
		WP_CLI::line( 'Last tick:   ' . wp_json_encode( get_option( 'vbr_last_tick_summary', array() ) ) );
		WP_CLI::line( 'Scheduler:   ' . ( function_exists( 'as_schedule_recurring_action' ) ? 'Action Scheduler' : 'WP-Cron' ) );
	}
}
