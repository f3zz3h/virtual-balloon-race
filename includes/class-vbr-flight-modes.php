<?php
/**
 * Flight modes: how quickly balloons lose lift (and so how far a race goes).
 *
 * @package Virtual_Balloon_Race
 */

defined( 'ABSPATH' ) || exit;

/**
 * Drift, Soar and Jetstream, and the maths that slows balloons down as they age.
 */
class VBR_Flight_Modes {

	const DRIFT     = 'drift';
	const SOAR      = 'soar';
	const JETSTREAM = 'jetstream';

	/**
	 * The leader's distance from launch as a share of a base-speed trail: swirling winds (low) to steady, strong winds (high).
	 * From simulated week-long races with live wind; the leader also gets Max Height and strong-wind boosts.
	 */
	const LEADER_LOW  = 0.2;
	const LEADER_HIGH = 0.85;

	/**
	 * The modes. `half_life` is the hours of flight after which a balloon moves at half speed (0 = never slows);
	 * `lands_after` is the hours of flight after which it comes down and stops (0 = never).
	 *
	 * @return array[]
	 */
	public static function all() {
		return array(
			self::DRIFT     => array(
				'label'       => __( 'Drift', 'virtual-balloon-race' ),
				'tagline'     => __( 'True to life', 'virtual-balloon-race' ),
				'description' => __( 'Like a real party balloon: a burst of speed on launch day, then it loses lift and comes down within a day and a half, staying where it lands. Leaders finish a few hundred kilometres away, however long the race runs.', 'virtual-balloon-race' ),
				'half_life'   => 12,
				'lands_after' => 36,
			),
			self::SOAR      => array(
				'label'       => __( 'Soar', 'virtual-balloon-race' ),
				'tagline'     => __( 'Exciting but believable', 'virtual-balloon-race' ),
				'description' => __( 'Balloons fly well for the first day or two, then gradually slow down. Most of the action happens early, and long races don’t run away to the other side of the world.', 'virtual-balloon-race' ),
				'half_life'   => 36,
				'lands_after' => 0,
			),
			self::JETSTREAM => array(
				'label'       => __( 'Jetstream', 'virtual-balloon-race' ),
				'tagline'     => __( 'Maximum distance', 'virtual-balloon-race' ),
				'description' => __( 'Balloons never tire: they keep full speed for the whole race, so long races cover huge distances. Great for short races or big drama.', 'virtual-balloon-race' ),
				'half_life'   => 0,
				'lands_after' => 0,
			),
		);
	}

	/**
	 * Mode labels for selects.
	 *
	 * @return array
	 */
	public static function labels() {
		return wp_list_pluck( self::all(), 'label' );
	}

	/**
	 * A race's mode (its own, else the Settings default).
	 *
	 * @param object|null $race Race.
	 * @return string
	 */
	public static function for_race( $race ) {
		$modes = self::all();
		$own   = $race && isset( $race->flight_mode ) ? (string) $race->flight_mode : '';
		$mode  = isset( $modes[ $own ] ) ? $own : (string) VBR_Settings::get( 'flight_mode' );

		return isset( $modes[ $mode ] ) ? $mode : self::SOAR;
	}

	/**
	 * Full-speed hours a balloon covers between $age and $age + $hours of flight (0 once it has landed).
	 *
	 * Speed halves every half-life, so this integrates 0.5^(t / half_life) over the tick:
	 * the result doesn't depend on how often updates run.
	 *
	 * @param string $mode  Mode key.
	 * @param float  $age   Hours the balloon had already flown.
	 * @param float  $hours Hours in this update.
	 * @return float
	 */
	public static function effective_hours( $mode, $age, $hours ) {
		$modes     = self::all();
		$half_life = isset( $modes[ $mode ] ) ? (float) $modes[ $mode ]['half_life'] : 0.0;
		$lands     = isset( $modes[ $mode ] ) ? (float) $modes[ $mode ]['lands_after'] : 0.0;
		$end       = $age + $hours;

		if ( $lands > 0 ) {
			$end = min( $end, $lands );
		}

		if ( $end <= $age ) {
			return 0.0;
		}

		if ( $half_life <= 0 ) {
			return $end - $age;
		}

		$k = $half_life / M_LN2;

		return $k * ( pow( 0.5, $age / $half_life ) - pow( 0.5, $end / $half_life ) );
	}

	/**
	 * Rough trail length (km) of a balloon flying at the base speed for $hours.
	 *
	 * @param string $mode  Mode key.
	 * @param float  $hours Race length so far, in hours.
	 * @return float
	 */
	public static function estimate_trail_km( $mode, $hours ) {
		return (float) VBR_Settings::get( 'base_kmh' ) * self::effective_hours( $mode, 0, $hours );
	}

	/**
	 * Mode picker cards with descriptions and distance estimates (Settings).
	 *
	 * @param string $name  Input name.
	 * @param string $value Selected mode.
	 */
	public static function render_cards( $name, $value ) {
		$spans = array(
			DAY_IN_SECONDS     => __( '1 day', 'virtual-balloon-race' ),
			3 * DAY_IN_SECONDS => __( '3 days', 'virtual-balloon-race' ),
			WEEK_IN_SECONDS    => __( '1 week', 'virtual-balloon-race' ),
		);
		?>
		<div class="vbr-mode-cards" role="radiogroup">
			<?php foreach ( self::all() as $key => $mode ) : ?>
				<label class="vbr-mode-card">
					<input type="radio" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( $key ); ?>" <?php checked( $value, $key ); ?> />
					<span class="vbr-mode-card__body">
						<strong class="vbr-mode-card__name"><?php echo esc_html( $mode['label'] ); ?></strong>
						<span class="vbr-mode-card__tagline"><?php echo esc_html( $mode['tagline'] ); ?></span>
						<span class="vbr-mode-card__description"><?php echo esc_html( $mode['description'] ); ?></span>
						<span class="vbr-mode-card__estimate">
							<span class="vbr-mode-card__estimate-title"><?php esc_html_e( 'Leader distance from launch', 'virtual-balloon-race' ); ?></span>
							<?php foreach ( $spans as $seconds => $label ) : ?>
								<?php $trail = self::estimate_trail_km( $key, $seconds / HOUR_IN_SECONDS ); ?>
								<span class="vbr-mode-card__estimate-row">
									<?php
									/* translators: 1: race length, 2: low km, 3: high km */
									echo esc_html( sprintf( __( '%1$s: %2$s–%3$s km', 'virtual-balloon-race' ), $label, number_format_i18n( self::round_km( $trail * self::LEADER_LOW ) ), number_format_i18n( self::round_km( $trail * self::LEADER_HIGH ) ) ) );
									?>
								</span>
							<?php endforeach; ?>
						</span>
					</span>
				</label>
			<?php endforeach; ?>
		</div>
		<p class="description">
			<?php esc_html_e( 'Estimates are how far the leader typically ends up from the launch point at the current base speed. The real distance depends on the wind: steady winds carry balloons further, swirling winds keep them closer.', 'virtual-balloon-race' ); ?>
		</p>
		<?php
	}

	/**
	 * Round an estimate to a friendly figure.
	 *
	 * @param float $km Kilometres.
	 * @return int
	 */
	private static function round_km( $km ) {
		$step = $km >= 1000 ? 100 : ( $km >= 100 ? 10 : 5 );

		return (int) ( round( $km / $step ) * $step );
	}
}
