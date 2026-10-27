<?php
/**
 * Race sponsors: per race, fixed tiers, and where each sponsor appears.
 *
 * @package Virtual_Balloon_Race
 */

defined( 'ABSPATH' ) || exit;

/**
 * Sponsors belong to one race and are added by admins on the race screen (nothing is sold).
 * A tier ticks its usual placements; any sponsor's placements can then be changed.
 */
class LVBR_Sponsors {

	const HEADLINE  = 'headline';
	const GOLD      = 'gold';
	const SILVER    = 'silver';
	const SUPPORTER = 'supporter';

	/**
	 * Register hooks.
	 */
	public function __construct() {
		add_shortcode( 'lvbr_sponsors', array( $this, 'render_wall' ) );
	}

	/**
	 * Sponsors table name.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;

		return $wpdb->prefix . 'lvbr_sponsors';
	}

	/**
	 * Tiers, highest first.
	 *
	 * @return array<string, string>
	 */
	public static function tier_labels() {
		return array(
			self::HEADLINE  => __( 'Headline', 'lumaha-virtual-balloon-race' ),
			self::GOLD      => __( 'Gold', 'lumaha-virtual-balloon-race' ),
			self::SILVER    => __( 'Silver', 'lumaha-virtual-balloon-race' ),
			self::SUPPORTER => __( 'Supporter', 'lumaha-virtual-balloon-race' ),
		);
	}

	/**
	 * Where a sponsor can appear.
	 *
	 * @return array<string, string>
	 */
	public static function placement_labels() {
		return array(
			'presented_by'      => __( 'Presented by', 'lumaha-virtual-balloon-race' ),
			'hot_air'           => __( 'Hot-air balloon on the map', 'lumaha-virtual-balloon-race' ),
			'launch_flag'       => __( 'Launch flag', 'lumaha-virtual-balloon-race' ),
			'map_badge'         => __( 'Map badge', 'lumaha-virtual-balloon-race' ),
			'leaderboard_strip' => __( 'Leaderboard strip', 'lumaha-virtual-balloon-race' ),
			'emails'            => __( 'Emails', 'lumaha-virtual-balloon-race' ),
			'wall'              => __( 'Sponsor wall', 'lumaha-virtual-balloon-race' ),
		);
	}

	/**
	 * Placements each race has only one of.
	 *
	 * @return string[]
	 */
	public static function single_placements() {
		return array( 'presented_by', 'launch_flag', 'map_badge', 'leaderboard_strip' );
	}

	/**
	 * Hot-air balloon envelope styles.
	 *
	 * @return array<string, string>
	 */
	public static function style_labels() {
		return array(
			'stripes' => __( 'Stripes and name band', 'lumaha-virtual-balloon-race' ),
			'panel'   => __( 'Logo panel', 'lumaha-virtual-balloon-race' ),
			'full'    => __( 'Logo fills the balloon', 'lumaha-virtual-balloon-race' ),
		);
	}

	/**
	 * The placements a tier ticks to start with.
	 *
	 * @return array<string, string[]>
	 */
	public static function tier_defaults() {
		return array(
			self::HEADLINE  => array( 'presented_by', 'hot_air', 'emails', 'wall' ),
			self::GOLD      => array( 'hot_air', 'emails', 'wall' ),
			self::SILVER    => array( 'wall' ),
			self::SUPPORTER => array( 'wall' ),
		);
	}

	/**
	 * A race's sponsors, in their order.
	 *
	 * @param int $race_id Race ID.
	 * @return object[] Rows, with `placements` as an array.
	 */
	public static function for_race( $race_id ) {
		global $wpdb;

		$table = self::table();
		$rows  = (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE race_id = %d ORDER BY sort ASC, id ASC", (int) $race_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the plugin's own table.

		foreach ( $rows as $row ) {
			$row->placements = array_values( array_filter( explode( ',', (string) $row->placements ) ) );
		}

		return $rows;
	}

	/**
	 * A race's sponsors with a placement, in their order.
	 *
	 * @param int    $race_id   Race ID.
	 * @param string $placement Placement key.
	 * @return object[]
	 */
	public static function with_placement( $race_id, $placement ) {
		return array_values(
			array_filter(
				self::for_race( $race_id ),
				static function ( $sponsor ) use ( $placement ) {
					return in_array( $placement, $sponsor->placements, true );
				}
			)
		);
	}

	/**
	 * The sponsor holding a one-per-race placement, if any.
	 *
	 * @param int    $race_id   Race ID.
	 * @param string $placement Placement key.
	 * @return object|null
	 */
	public static function holder( $race_id, $placement ) {
		$sponsors = self::with_placement( $race_id, $placement );

		return $sponsors ? $sponsors[0] : null;
	}

	/**
	 * Wrap HTML in a link to the sponsor's website (marked as sponsored, new tab), or a span without one.
	 * Every sponsor link on the site goes through here; it's tagged with its placement for the views and
	 * clicks report (LVBR_Sponsor_Stats).
	 *
	 * @param object $sponsor   Sponsor row.
	 * @param string $inner     Escaped inner HTML.
	 * @param string $class     CSS class.
	 * @param string $placement Placement key (or 'prize'), for the report.
	 * @return string
	 */
	public static function link( $sponsor, $inner, $class = '', $placement = '' ) {
		$attrs = $class ? ' class="' . esc_attr( $class ) . '"' : '';

		if ( $placement ) {
			$attrs .= ' data-lvbr-sponsor="' . esc_attr( (string) (int) $sponsor->id ) . '" data-lvbr-placement="' . esc_attr( $placement ) . '"';
			LVBR_Sponsor_Stats::enqueue();
		}

		if ( ! $sponsor->url ) {
			return '<span' . $attrs . '>' . $inner . '</span>';
		}

		return '<a' . $attrs . ' href="' . esc_url( $sponsor->url ) . '" rel="sponsored noopener" target="_blank">' . $inner . '</a>';
	}

	/**
	 * The sponsor's logo (alt text: their name), or '' when they have none. Uses an uncropped size, so wide
	 * logos keep their shape; CSS limits the height.
	 *
	 * @param object $sponsor Sponsor row.
	 * @param string $size    Image size.
	 * @return string
	 */
	public static function logo( $sponsor, $size = 'medium' ) {
		if ( ! $sponsor->logo_id ) {
			return '';
		}

		return (string) wp_get_attachment_image(
			(int) $sponsor->logo_id,
			$size,
			false,
			array(
				'class'   => 'lvbr-sponsor-logo',
				'alt'     => $sponsor->name,
				'loading' => 'lazy',
			)
		);
	}

	/**
	 * Sponsor hot-air balloons for the map: look, position (the launch point until the race moves) and trail.
	 * Headline sponsors fly a large balloon.
	 *
	 * @param object $race Race.
	 * @return array[]
	 */
	public static function hot_air_payload( $race ) {
		$launch = LVBR_Races::launch_point( $race );
		$out    = array();

		if ( self::with_placement( (int) $race->id, 'hot_air' ) || self::holder( (int) $race->id, 'launch_flag' ) ) {
			LVBR_Sponsor_Stats::enqueue();
		}

		foreach ( self::with_placement( (int) $race->id, 'hot_air' ) as $sponsor ) {
			$lat   = null !== $sponsor->latitude ? (float) $sponsor->latitude : (float) $launch['lat'];
			$lng   = null !== $sponsor->longitude ? (float) $sponsor->longitude : (float) $launch['lng'];
			$trail = LVBR_Balloons::decode_trail( (string) $sponsor->trail_json );
			$src   = $sponsor->logo_id ? wp_get_attachment_image_src( (int) $sponsor->logo_id, 'medium' ) : false;
			$style = in_array( $sponsor->balloon_style, array( 'panel', 'full' ), true ) && $src ? $sponsor->balloon_style : 'stripes';
			$band  = '' !== (string) $sponsor->band_text ? $sponsor->band_text : mb_strtoupper( (string) strtok( $sponsor->name, ' ' ) );

			$out[] = array(
				'id'     => (int) $sponsor->id,
				'name'   => $sponsor->name,
				'url'    => $sponsor->url,
				'size'   => self::HEADLINE === $sponsor->tier ? 'large' : 'normal',
				'style'  => $style,
				'color1' => $sponsor->color1 ? $sponsor->color1 : '#2b6cb0',
				'color2' => $sponsor->color2 ? $sponsor->color2 : '#ffffff',
				'band'   => mb_substr( $band, 0, 12 ),
				'logo'   => $src ? $src[0] : '',
				// A logo much wider or taller than the envelope is shown whole rather than cropped (as on racing balloons).
				'fit'    => $src && $src[2] && ( $src[1] / $src[2] > 1.6 || $src[1] / $src[2] < 0.6 ) ? 'contain' : 'cover',
				'lat'    => $lat,
				'lng'    => $lng,
				'trail'  => $trail ? $trail : array( array( $lat, $lng ) ),
			);
		}

		return $out;
	}

	/**
	 * "presented by [logo] Name" for a race, or ''.
	 *
	 * @param object|null $race Race.
	 * @return string HTML.
	 */
	public static function presented_by( $race ) {
		$sponsor = $race ? self::holder( (int) $race->id, 'presented_by' ) : null;

		if ( ! $sponsor ) {
			return '';
		}

		return '<span class="lvbr-presented-by">' . esc_html__( 'presented by', 'lumaha-virtual-balloon-race' ) . ' '
			. self::link( $sponsor, self::logo( $sponsor ) . '<span class="lvbr-presented-by__name">' . esc_html( $sponsor->name ) . '</span>', 'lvbr-presented-by__link', 'presented_by' )
			. '</span>';
	}

	/**
	 * A one-per-race credit line, e.g. "Leaderboard brought to you by [logo] Name", or ''.
	 *
	 * @param object|null $race      Race.
	 * @param string      $placement leaderboard_strip or map_badge.
	 * @return string HTML.
	 */
	public static function credit( $race, $placement ) {
		$sponsor = $race ? self::holder( (int) $race->id, $placement ) : null;

		if ( ! $sponsor ) {
			return '';
		}

		if ( 'map_badge' === $placement ) {
			// Kept small so it never covers the map's zoom buttons or credits: the logo alone (its alt text is the name), else the name.
			$logo = self::logo( $sponsor );

			return '<p class="lvbr-map__badge"><span class="lvbr-map__badge-label">' . esc_html__( 'Map supported by', 'lumaha-virtual-balloon-race' ) . '</span> '
				. self::link( $sponsor, $logo ? $logo : '<strong>' . esc_html( $sponsor->name ) . '</strong>', '', 'map_badge' )
				. '</p>';
		}

		return '<p class="lvbr-leaderboard__strip">' . esc_html__( 'Leaderboard brought to you by', 'lumaha-virtual-balloon-race' ) . ' '
			. self::link( $sponsor, self::logo( $sponsor ) . '<strong>' . esc_html( $sponsor->name ) . '</strong>', '', 'leaderboard_strip' )
			. '</p>';
	}

	/**
	 * "This race was made possible by A, B and C." for emails (plain text), or ''.
	 *
	 * @param object|null $race Race.
	 * @return string
	 */
	public static function email_line( $race ) {
		$names = $race ? wp_list_pluck( self::with_placement( (int) $race->id, 'emails' ), 'name' ) : array();

		if ( ! $names ) {
			return '';
		}

		/* translators: %s: sponsor names */
		return sprintf( __( 'This race was made possible by %s.', 'lumaha-virtual-balloon-race' ), wp_sprintf( '%l', $names ) );
	}

	/**
	 * Render [lvbr_sponsors]: the race's sponsor wall, by tier (nothing when there are none).
	 *
	 * @param array|string $atts Shortcode attributes (race).
	 * @return string
	 */
	public function render_wall( $atts ) {
		$race     = LVBR_Races::resolve( LVBR_Races::ref_from_shortcode( $atts ) );
		$sponsors = $race ? self::with_placement( (int) $race->id, 'wall' ) : array();

		if ( ! $sponsors ) {
			return '';
		}

		wp_enqueue_style( 'lvbr-frontend' );

		$tiers  = self::tier_labels();
		$groups = array();
		foreach ( array_keys( $tiers ) as $tier ) {
			$group = array_values(
				array_filter(
					$sponsors,
					static function ( $sponsor ) use ( $tier, $tiers ) {
						return $tier === ( isset( $tiers[ $sponsor->tier ] ) ? $sponsor->tier : self::SUPPORTER );
					}
				)
			);
			if ( $group ) {
				$groups[ $tier ] = $group;
			}
		}

		$heading = array(
			self::HEADLINE  => __( 'Headline sponsor', 'lumaha-virtual-balloon-race' ),
			self::GOLD      => __( 'Gold sponsors', 'lumaha-virtual-balloon-race' ),
			self::SILVER    => __( 'Silver sponsors', 'lumaha-virtual-balloon-race' ),
			self::SUPPORTER => __( 'Supporters', 'lumaha-virtual-balloon-race' ),
		);

		ob_start();
		?>
		<section class="lvbr-sponsors">
			<h2 class="lvbr-sponsors__title"><?php esc_html_e( 'Thank you to our sponsors', 'lumaha-virtual-balloon-race' ); ?></h2>
			<?php foreach ( $groups as $tier => $group ) : ?>
				<div class="lvbr-sponsors__tier lvbr-sponsors__tier--<?php echo esc_attr( $tier ); ?>">
					<h3 class="lvbr-sponsors__tier-name"><?php echo esc_html( $heading[ $tier ] ); ?></h3>
					<?php if ( self::SUPPORTER === $tier ) : ?>
						<p class="lvbr-sponsors__names">
							<?php
							echo wp_kses_post(
								implode(
									' · ',
									array_map(
										static function ( $sponsor ) {
											return self::link( $sponsor, esc_html( $sponsor->name ), '', 'wall' );
										},
										$group
									)
								)
							);
							?>
						</p>
					<?php else : ?>
						<ul class="lvbr-sponsors__list">
							<?php foreach ( $group as $sponsor ) : ?>
								<li class="lvbr-sponsors__item">
									<?php echo wp_kses_post( self::link( $sponsor, self::logo( $sponsor ) . '<span class="lvbr-sponsors__name">' . esc_html( $sponsor->name ) . '</span>', 'lvbr-sponsors__card', 'wall' ) ); ?>
								</li>
							<?php endforeach; ?>
						</ul>
					<?php endif; ?>
				</div>
			<?php endforeach; ?>
		</section>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Clean submitted editor rows. Rows without a name are dropped.
	 *
	 * @param mixed $rows Raw rows.
	 * @return array[] Rows: id, name, url, logo_id, tier, placements.
	 */
	public static function clean_rows( $rows ) {
		$clean = array();
		$tiers = self::tier_labels();
		$keys  = array_keys( self::placement_labels() );

		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$name = isset( $row['name'] ) ? sanitize_text_field( (string) $row['name'] ) : '';

			if ( '' === $name ) {
				continue;
			}

			$url     = isset( $row['url'] ) ? esc_url_raw( trim( (string) $row['url'] ), array( 'http', 'https' ) ) : '';
			$logo_id = isset( $row['logo_id'] ) ? absint( $row['logo_id'] ) : 0;
			$tier    = isset( $row['tier'] ) ? sanitize_key( (string) $row['tier'] ) : '';
			$picked  = isset( $row['placements'] ) && is_array( $row['placements'] ) ? array_map( 'sanitize_key', $row['placements'] ) : array();

			$style   = isset( $row['balloon_style'] ) ? sanitize_key( (string) $row['balloon_style'] ) : '';
			$color1  = isset( $row['color1'] ) ? sanitize_hex_color( (string) $row['color1'] ) : '';
			$color2  = isset( $row['color2'] ) ? sanitize_hex_color( (string) $row['color2'] ) : '';
			$band    = isset( $row['band_text'] ) ? sanitize_text_field( (string) $row['band_text'] ) : '';

			$clean[] = array(
				'id'            => isset( $row['id'] ) ? absint( $row['id'] ) : 0,
				'name'          => mb_substr( $name, 0, 100 ),
				'url'           => mb_substr( $url, 0, 255 ),
				'logo_id'       => $logo_id && wp_attachment_is_image( $logo_id ) ? $logo_id : 0,
				'tier'          => isset( $tiers[ $tier ] ) ? $tier : self::SUPPORTER,
				'placements'    => array_values( array_intersect( $keys, $picked ) ),
				'balloon_style' => isset( self::style_labels()[ $style ] ) ? $style : 'stripes',
				'color1'        => $color1 ? $color1 : '#2b6cb0',
				'color2'        => $color2 ? $color2 : '#ffffff',
				'band_text'     => mb_substr( $band, 0, 12 ),
			);
		}

		return $clean;
	}

	/**
	 * Replace a race's sponsors with the submitted rows. A one-per-race placement stays with the sponsor in
	 * the highest tier that has it (the first in the list, between equal tiers); the others lose it.
	 *
	 * @param int     $race_id Race ID.
	 * @param array[] $rows    Rows from clean_rows().
	 * @return string[] Messages about placements that were taken away.
	 */
	public static function save_for_race( $race_id, $rows ) {
		global $wpdb;

		$table    = self::table();
		$labels   = self::placement_labels();
		$rows     = array_values( $rows );
		$existing = array();
		$kept     = array();
		$messages = array();

		foreach ( self::for_race( $race_id ) as $sponsor ) {
			$existing[ (int) $sponsor->id ] = true;
		}

		// Who keeps each one-per-race placement: highest tier, then list order.
		$rank  = array_flip( array_keys( self::tier_labels() ) );
		$order = array_keys( $rows );
		usort(
			$order,
			static function ( $a, $b ) use ( $rows, $rank ) {
				return array( $rank[ $rows[ $a ]['tier'] ], $a ) <=> array( $rank[ $rows[ $b ]['tier'] ], $b );
			}
		);

		foreach ( self::single_placements() as $single ) {
			$holder = null;
			foreach ( $order as $index ) {
				if ( ! in_array( $single, $rows[ $index ]['placements'], true ) ) {
					continue;
				}
				if ( null === $holder ) {
					$holder = $index;
					continue;
				}
				$rows[ $index ]['placements'] = array_values( array_diff( $rows[ $index ]['placements'], array( $single ) ) );
				$messages[]                   = sprintf(
					/* translators: 1: sponsor that keeps the spot, 2: placement, 3: sponsor that lost it */
					__( '%1$s keeps the “%2$s” spot, so it was taken off %3$s.', 'lumaha-virtual-balloon-race' ),
					$rows[ $holder ]['name'],
					$labels[ $single ],
					$rows[ $index ]['name']
				);
			}
		}

		foreach ( $rows as $sort => $row ) {
			$data = array(
				'race_id'       => (int) $race_id,
				'name'          => $row['name'],
				'url'           => $row['url'],
				'logo_id'       => $row['logo_id'],
				'tier'          => $row['tier'],
				'placements'    => implode( ',', $row['placements'] ),
				'balloon_style' => $row['balloon_style'],
				'color1'        => $row['color1'],
				'color2'        => $row['color2'],
				'band_text'     => $row['band_text'],
				'sort'          => $sort,
			);

			if ( $row['id'] && isset( $existing[ $row['id'] ] ) ) {
				$wpdb->update( $table, $data, array( 'id' => $row['id'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the plugin's own table.
				$kept[ $row['id'] ] = true;
			} else {
				$wpdb->insert( $table, $data ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the plugin's own table.
			}
		}

		foreach ( array_keys( $existing ) as $id ) {
			if ( ! isset( $kept[ $id ] ) ) {
				$wpdb->delete( $table, array( 'id' => $id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the plugin's own table.
				LVBR_Sponsor_Stats::delete_for_sponsors( array( $id ) );
			}
		}

		return $messages;
	}

	/**
	 * Remove a race's sponsors (when the race is deleted). Their logos stay in the Media Library.
	 *
	 * @param int $race_id Race ID.
	 */
	public static function delete_for_race( $race_id ) {
		global $wpdb;

		LVBR_Sponsor_Stats::delete_for_sponsors( wp_list_pluck( self::for_race( $race_id ), 'id' ) );
		$wpdb->delete( self::table(), array( 'race_id' => (int) $race_id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the plugin's own table.
	}

	/**
	 * Sponsor editor on the race screen: one card per sponsor, saved with the race.
	 *
	 * @param string   $name     Input name prefix, e.g. `lvbr_race[sponsors]`.
	 * @param object[] $sponsors Rows from for_race().
	 * @param array    $stats    Views and clicks from LVBR_Sponsor_Stats::for_race().
	 */
	public static function render_editor( $name, $sponsors, $stats = array() ) {
		$tiers      = self::tier_labels();
		$placements = self::placement_labels();
		$card       = static function ( $index, $sponsor ) use ( $name, $tiers, $placements, $stats ) {
			$prefix = $name . '[' . $index . ']';
			$logo   = $sponsor['logo_id'] ? wp_get_attachment_image_url( (int) $sponsor['logo_id'], 'medium' ) : '';
			?>
			<li class="lvbr-sponsors-editor__card">
				<input type="hidden" name="<?php echo esc_attr( $prefix ); ?>[id]" value="<?php echo esc_attr( (string) (int) $sponsor['id'] ); ?>" />
				<input type="hidden" class="lvbr-sponsors-editor__logo-id" name="<?php echo esc_attr( $prefix ); ?>[logo_id]" value="<?php echo esc_attr( (string) (int) $sponsor['logo_id'] ); ?>" />
				<div class="lvbr-sponsors-editor__logo">
					<img class="lvbr-sponsors-editor__logo-img" src="<?php echo esc_url( $logo ); ?>" alt=""<?php echo $logo ? '' : ' hidden'; ?> />
					<button type="button" class="button-link lvbr-sponsors-editor__pick"><?php echo esc_html( $logo ? __( 'Change logo', 'lumaha-virtual-balloon-race' ) : __( 'Add logo', 'lumaha-virtual-balloon-race' ) ); ?></button>
				</div>
				<div class="lvbr-sponsors-editor__fields">
					<label>
						<span><?php esc_html_e( 'Name', 'lumaha-virtual-balloon-race' ); ?></span>
						<input type="text" class="regular-text" name="<?php echo esc_attr( $prefix ); ?>[name]" value="<?php echo esc_attr( $sponsor['name'] ); ?>" maxlength="100" placeholder="<?php esc_attr_e( 'e.g. The Corner Café', 'lumaha-virtual-balloon-race' ); ?>" />
					</label>
					<label>
						<span><?php esc_html_e( 'Website', 'lumaha-virtual-balloon-race' ); ?></span>
						<input type="url" class="regular-text" name="<?php echo esc_attr( $prefix ); ?>[url]" value="<?php echo esc_attr( $sponsor['url'] ); ?>" placeholder="https://" />
					</label>
					<label>
						<span><?php esc_html_e( 'Tier', 'lumaha-virtual-balloon-race' ); ?></span>
						<select class="lvbr-sponsors-editor__tier" name="<?php echo esc_attr( $prefix ); ?>[tier]">
							<?php foreach ( $tiers as $key => $label ) : ?>
								<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $sponsor['tier'], $key ); ?>><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
					</label>
					<fieldset class="lvbr-sponsors-editor__hot-air"<?php echo in_array( 'hot_air', $sponsor['placements'], true ) ? '' : ' hidden'; ?>>
						<legend><?php esc_html_e( 'Hot-air balloon', 'lumaha-virtual-balloon-race' ); ?></legend>
						<label>
							<span><?php esc_html_e( 'Style', 'lumaha-virtual-balloon-race' ); ?></span>
							<select name="<?php echo esc_attr( $prefix ); ?>[balloon_style]">
								<?php foreach ( self::style_labels() as $key => $label ) : ?>
									<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $sponsor['balloon_style'], $key ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
						</label>
						<label>
							<span><?php esc_html_e( 'Colours', 'lumaha-virtual-balloon-race' ); ?></span>
							<span class="lvbr-sponsors-editor__colours">
								<input type="color" name="<?php echo esc_attr( $prefix ); ?>[color1]" value="<?php echo esc_attr( $sponsor['color1'] ); ?>" aria-label="<?php esc_attr_e( 'Main colour', 'lumaha-virtual-balloon-race' ); ?>" />
								<input type="color" name="<?php echo esc_attr( $prefix ); ?>[color2]" value="<?php echo esc_attr( $sponsor['color2'] ); ?>" aria-label="<?php esc_attr_e( 'Second colour', 'lumaha-virtual-balloon-race' ); ?>" />
							</span>
						</label>
						<label>
							<span><?php esc_html_e( 'Name band', 'lumaha-virtual-balloon-race' ); ?></span>
							<input type="text" name="<?php echo esc_attr( $prefix ); ?>[band_text]" value="<?php echo esc_attr( $sponsor['band_text'] ); ?>" maxlength="12" placeholder="<?php esc_attr_e( 'Short name, e.g. BRAMBLE', 'lumaha-virtual-balloon-race' ); ?>" />
						</label>
						<p class="description"><?php esc_html_e( 'Flies with the race on the same wind, never ranked. The logo styles need a logo; without one, stripes are used.', 'lumaha-virtual-balloon-race' ); ?></p>
					</fieldset>
					<fieldset class="lvbr-sponsors-editor__placements">
						<legend><?php esc_html_e( 'Shown in', 'lumaha-virtual-balloon-race' ); ?></legend>
						<?php foreach ( $placements as $key => $label ) : ?>
							<label><input type="checkbox" name="<?php echo esc_attr( $prefix ); ?>[placements][]" value="<?php echo esc_attr( $key ); ?>" <?php checked( in_array( $key, $sponsor['placements'], true ) ); ?> /> <?php echo esc_html( $label ); ?></label>
						<?php endforeach; ?>
					</fieldset>
				</div>
				<?php if ( $sponsor['id'] && LVBR_Sponsor_Stats::enabled() ) : ?>
					<?php $counts = LVBR_Sponsor_Stats::totals( isset( $stats[ $sponsor['id'] ] ) ? $stats[ $sponsor['id'] ] : array() ); ?>
					<p class="lvbr-sponsors-editor__stats">
						<?php
						echo esc_html(
							sprintf(
								/* translators: 1: number of views, 2: number of clicks */
								__( 'Shown %1$s times · %2$s clicks', 'lumaha-virtual-balloon-race' ),
								number_format_i18n( $counts['views'] ),
								number_format_i18n( $counts['clicks'] )
							)
						);
						?>
					</p>
				<?php endif; ?>
				<div class="lvbr-sponsors-editor__actions">
					<button type="button" class="button-link lvbr-sponsors-editor__up" aria-label="<?php esc_attr_e( 'Move up', 'lumaha-virtual-balloon-race' ); ?>">↑</button>
					<button type="button" class="button-link lvbr-sponsors-editor__down" aria-label="<?php esc_attr_e( 'Move down', 'lumaha-virtual-balloon-race' ); ?>">↓</button>
					<button type="button" class="button-link button-link-delete lvbr-sponsors-editor__remove"><?php esc_html_e( 'Remove', 'lumaha-virtual-balloon-race' ); ?></button>
				</div>
			</li>
			<?php
		};
		?>
		<div class="lvbr-sponsors-editor" data-tier-defaults="<?php echo esc_attr( wp_json_encode( self::tier_defaults() ) ); ?>" data-choose="<?php esc_attr_e( 'Choose a sponsor logo', 'lumaha-virtual-balloon-race' ); ?>" data-use="<?php esc_attr_e( 'Use this logo', 'lumaha-virtual-balloon-race' ); ?>" data-change="<?php esc_attr_e( 'Change logo', 'lumaha-virtual-balloon-race' ); ?>">
			<ol class="lvbr-sponsors-editor__list">
				<?php
				foreach ( array_values( $sponsors ) as $index => $sponsor ) {
					$card(
						$index,
						array(
							'id'            => (int) $sponsor->id,
							'name'          => $sponsor->name,
							'url'           => $sponsor->url,
							'logo_id'       => (int) $sponsor->logo_id,
							'tier'          => $sponsor->tier,
							'placements'    => $sponsor->placements,
							'balloon_style' => $sponsor->balloon_style,
							'color1'        => $sponsor->color1 ? $sponsor->color1 : '#2b6cb0',
							'color2'        => $sponsor->color2 ? $sponsor->color2 : '#ffffff',
							'band_text'     => $sponsor->band_text,
						)
					);
				}
				?>
			</ol>
			<template class="lvbr-sponsors-editor__template">
				<?php
				$card(
					'__i__',
					array(
						'id'            => 0,
						'name'          => '',
						'url'           => '',
						'logo_id'       => 0,
						'tier'          => self::SUPPORTER,
						'placements'    => self::tier_defaults()[ self::SUPPORTER ],
						'balloon_style' => 'stripes',
						'color1'        => '#2b6cb0',
						'color2'        => '#ffffff',
						'band_text'     => '',
					)
				);
				?>
			</template>
			<p><button type="button" class="button lvbr-sponsors-editor__add"><?php esc_html_e( 'Add sponsor', 'lumaha-virtual-balloon-race' ); ?></button></p>
		</div>
		<?php
	}
}
