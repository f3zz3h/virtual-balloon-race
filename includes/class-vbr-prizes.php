<?php
/**
 * Race prizes, each optionally donated by one of the race's sponsors.
 *
 * @package Virtual_Balloon_Race
 */

defined( 'ABSPATH' ) || exit;

/**
 * Prizes per race: places, best team, a random draw or anything else, shown on the tracking page
 * (`[balloon_prizes]`), next to Add to basket, and with the winners in the results email.
 */
class VBR_Prizes {

	/**
	 * Register hooks.
	 */
	public function __construct() {
		add_shortcode( 'balloon_prizes', array( $this, 'render_shortcode' ) );
	}

	/**
	 * Prizes table name.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;

		return $wpdb->prefix . 'vbr_prizes';
	}

	/**
	 * What a prize is for. `other` uses the prize's own label.
	 *
	 * @return array<string, string>
	 */
	public static function kind_labels() {
		return array(
			'place_1'   => __( '1st place', 'virtual-balloon-race' ),
			'place_2'   => __( '2nd place', 'virtual-balloon-race' ),
			'place_3'   => __( '3rd place', 'virtual-balloon-race' ),
			'place_4'   => __( '4th place', 'virtual-balloon-race' ),
			'place_5'   => __( '5th place', 'virtual-balloon-race' ),
			'best_team' => __( 'Best team', 'virtual-balloon-race' ),
			'draw'      => __( 'Random draw', 'virtual-balloon-race' ),
			'other'     => __( 'Other (your own label)', 'virtual-balloon-race' ),
		);
	}

	/**
	 * A prize's title: its kind, or its own label for `other`.
	 *
	 * @param object $prize Prize row.
	 * @return string
	 */
	public static function title( $prize ) {
		$kinds = self::kind_labels();

		if ( 'other' === $prize->kind || ! isset( $kinds[ $prize->kind ] ) ) {
			return '' !== (string) $prize->label ? (string) $prize->label : __( 'Prize', 'virtual-balloon-race' );
		}

		return $kinds[ $prize->kind ];
	}

	/**
	 * A race's prizes in their order, each with its sponsor (or null).
	 *
	 * @param int $race_id Race ID.
	 * @return object[]
	 */
	public static function for_race( $race_id ) {
		global $wpdb;

		$table    = self::table();
		$prizes   = (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE race_id = %d ORDER BY sort ASC, id ASC", (int) $race_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sponsors = array();

		foreach ( VBR_Sponsors::for_race( $race_id ) as $sponsor ) {
			$sponsors[ (int) $sponsor->id ] = $sponsor;
		}

		foreach ( $prizes as $prize ) {
			$prize->sponsor = isset( $sponsors[ (int) $prize->sponsor_id ] ) ? $sponsors[ (int) $prize->sponsor_id ] : null;
		}

		return $prizes;
	}

	/**
	 * Clean submitted editor rows. Rows without a description are dropped; a sponsor must belong to the race.
	 *
	 * @param mixed $rows    Raw rows.
	 * @param int   $race_id Race ID.
	 * @return array[] Rows: id, kind, label, description, sponsor_id.
	 */
	public static function clean_rows( $rows, $race_id ) {
		$clean    = array();
		$kinds    = self::kind_labels();
		$sponsors = array_map( 'intval', wp_list_pluck( VBR_Sponsors::for_race( $race_id ), 'id' ) );

		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$description = isset( $row['description'] ) ? sanitize_text_field( (string) $row['description'] ) : '';

			if ( '' === $description ) {
				continue;
			}

			$kind    = isset( $row['kind'] ) ? sanitize_key( (string) $row['kind'] ) : '';
			$kind    = isset( $kinds[ $kind ] ) ? $kind : 'other';
			$sponsor = isset( $row['sponsor_id'] ) ? absint( $row['sponsor_id'] ) : 0;

			$clean[] = array(
				'id'          => isset( $row['id'] ) ? absint( $row['id'] ) : 0,
				'kind'        => $kind,
				'label'       => 'other' === $kind && isset( $row['label'] ) ? mb_substr( sanitize_text_field( (string) $row['label'] ), 0, 100 ) : '',
				'description' => mb_substr( $description, 0, 255 ),
				'sponsor_id'  => in_array( $sponsor, $sponsors, true ) ? $sponsor : 0,
			);
		}

		return $clean;
	}

	/**
	 * Replace a race's prizes with the submitted rows.
	 *
	 * @param int     $race_id Race ID.
	 * @param array[] $rows    Rows from clean_rows().
	 */
	public static function save_for_race( $race_id, $rows ) {
		global $wpdb;

		$table    = self::table();
		$existing = array_flip( array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$table} WHERE race_id = %d", (int) $race_id ) ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$kept     = array();

		foreach ( array_values( $rows ) as $sort => $row ) {
			$data = array(
				'race_id'     => (int) $race_id,
				'kind'        => $row['kind'],
				'label'       => $row['label'],
				'description' => $row['description'],
				'sponsor_id'  => $row['sponsor_id'],
				'sort'        => $sort,
			);

			if ( $row['id'] && isset( $existing[ $row['id'] ] ) ) {
				$wpdb->update( $table, $data, array( 'id' => $row['id'] ) );
				$kept[ $row['id'] ] = true;
			} else {
				$wpdb->insert( $table, $data );
			}
		}

		foreach ( array_keys( $existing ) as $id ) {
			if ( ! isset( $kept[ $id ] ) ) {
				$wpdb->delete( $table, array( 'id' => $id ), array( '%d' ) );
			}
		}
	}

	/**
	 * Remove a race's prizes (when the race is deleted).
	 *
	 * @param int $race_id Race ID.
	 */
	public static function delete_for_race( $race_id ) {
		global $wpdb;

		$wpdb->delete( self::table(), array( 'race_id' => (int) $race_id ), array( '%d' ) );
	}

	/**
	 * Who won each prize, once a race has finished: the balloon in that place, the leading team,
	 * or nobody yet (draws and other prizes are decided by the organisers).
	 *
	 * @param object $race Race.
	 * @return array[] Per prize: title, description, donor (name or ''), winner (text or '').
	 */
	public static function results( $race ) {
		global $wpdb;

		$table   = VBR_Balloons::table();
		$by_rank = array_flip( VBR_Frontend::ranked_ids( $race ) );
		$teams   = VBR_Teams::race_uses_teams( $race ) ? VBR_Teams::standings( $race ) : array();
		$out     = array();

		foreach ( self::for_race( (int) $race->id ) as $prize ) {
			$winner = '';

			if ( 0 === strpos( $prize->kind, 'place_' ) ) {
				$place = (int) substr( $prize->kind, 6 );
				if ( isset( $by_rank[ $place ] ) ) {
					$balloon = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $by_rank[ $place ] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$winner  = $balloon ? sprintf( '%1$s (%2$s)', $balloon->balloon_name, VBR_Balloons::public_owner_name( $balloon ) ) : '';
				}
			} elseif ( 'best_team' === $prize->kind && $teams && $teams[0]['balloons'] > 0 ) {
				$winner = $teams[0]['name'];
			}

			$out[] = array(
				'title'       => self::title( $prize ),
				'description' => $prize->description,
				'donor'       => $prize->sponsor ? $prize->sponsor->name : '',
				'winner'      => $winner,
			);
		}

		return $out;
	}

	/**
	 * "Up for grabs: 1st place: … · 2nd place: …" for a race (plain text), or ''.
	 *
	 * @param int $race_id Race ID.
	 * @return string
	 */
	public static function summary( $race_id ) {
		$parts = array();

		foreach ( self::for_race( $race_id ) as $prize ) {
			$parts[] = self::title( $prize ) . ': ' . $prize->description;
		}

		return implode( ' · ', $parts );
	}

	/**
	 * "Up for grabs" on the product page: the race's prizes, linked to the tracking page.
	 *
	 * @param object $race Race being entered.
	 */
	public static function render_up_for_grabs( $race ) {
		$summary = self::summary( (int) $race->id );

		if ( '' === $summary ) {
			return;
		}
		?>
		<p class="vbr-up-for-grabs">
			<strong><?php esc_html_e( 'Up for grabs:', 'virtual-balloon-race' ); ?></strong>
			<?php echo esc_html( $summary ); ?>
			<a href="<?php echo esc_url( VBR_Pages::tracking_url( $race ) ); ?>"><?php esc_html_e( 'See the prizes', 'virtual-balloon-race' ); ?></a>
		</p>
		<?php
	}

	/**
	 * A sponsor's name, linked to their website (marked as sponsored) when they have one.
	 *
	 * @param object $sponsor Sponsor row.
	 * @return string HTML.
	 */
	public static function sponsor_link( $sponsor ) {
		return VBR_Sponsors::link( $sponsor, esc_html( $sponsor->name ), '', 'prize' );
	}

	/**
	 * Render [balloon_prizes]: prize cards for a race (nothing when it has none).
	 *
	 * @param array|string $atts Shortcode attributes (race).
	 * @return string
	 */
	public function render_shortcode( $atts ) {
		$race   = VBR_Races::resolve( VBR_Races::ref_from_shortcode( $atts ) );
		$prizes = $race ? self::for_race( (int) $race->id ) : array();

		if ( ! $prizes ) {
			return '';
		}

		wp_enqueue_style( 'vbr-frontend' );

		ob_start();
		?>
		<section class="vbr-prizes">
			<h2 class="vbr-prizes__title"><?php esc_html_e( 'Prizes', 'virtual-balloon-race' ); ?></h2>
			<ul class="vbr-prizes__list">
				<?php foreach ( $prizes as $index => $prize ) : ?>
					<li class="vbr-prizes__card<?php echo 0 === $index ? ' vbr-prizes__card--top' : ''; ?>">
						<span class="vbr-prizes__kind"><?php echo esc_html( self::title( $prize ) ); ?></span>
						<span class="vbr-prizes__what"><?php echo esc_html( $prize->description ); ?></span>
						<?php if ( $prize->sponsor ) : ?>
							<span class="vbr-prizes__donor">
								<?php
								/* translators: %s: sponsor name (linked) */
								echo wp_kses_post( sprintf( __( 'Donated by %s', 'virtual-balloon-race' ), self::sponsor_link( $prize->sponsor ) ) );
								?>
							</span>
						<?php endif; ?>
					</li>
				<?php endforeach; ?>
			</ul>
		</section>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Prize rows on the race screen, saved with the race.
	 *
	 * @param string   $name     Input name prefix, e.g. `vbr_race[prizes]`.
	 * @param object[] $prizes   Rows from for_race().
	 * @param object[] $sponsors The race's saved sponsors.
	 */
	public static function render_editor( $name, $prizes, $sponsors ) {
		$kinds = self::kind_labels();
		$row   = static function ( $index, $prize ) use ( $name, $kinds, $sponsors ) {
			$prefix = $name . '[' . $index . ']';
			?>
			<li class="vbr-prizes-editor__row">
				<input type="hidden" name="<?php echo esc_attr( $prefix ); ?>[id]" value="<?php echo esc_attr( (string) (int) $prize['id'] ); ?>" />
				<label>
					<span><?php esc_html_e( 'Prize for', 'virtual-balloon-race' ); ?></span>
					<select class="vbr-prizes-editor__kind" name="<?php echo esc_attr( $prefix ); ?>[kind]">
						<?php foreach ( $kinds as $key => $label ) : ?>
							<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $prize['kind'], $key ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
				<label class="vbr-prizes-editor__label"<?php echo 'other' === $prize['kind'] ? '' : ' hidden'; ?>>
					<span><?php esc_html_e( 'Label', 'virtual-balloon-race' ); ?></span>
					<input type="text" name="<?php echo esc_attr( $prefix ); ?>[label]" value="<?php echo esc_attr( $prize['label'] ); ?>" maxlength="100" placeholder="<?php esc_attr_e( 'e.g. Furthest Eco-Glide', 'virtual-balloon-race' ); ?>" />
				</label>
				<label class="vbr-prizes-editor__description">
					<span><?php esc_html_e( 'Prize', 'virtual-balloon-race' ); ?></span>
					<input type="text" name="<?php echo esc_attr( $prefix ); ?>[description]" value="<?php echo esc_attr( $prize['description'] ); ?>" maxlength="255" placeholder="<?php esc_attr_e( 'e.g. Family Sunday lunch for four', 'virtual-balloon-race' ); ?>" />
				</label>
				<label>
					<span><?php esc_html_e( 'Donated by', 'virtual-balloon-race' ); ?></span>
					<select name="<?php echo esc_attr( $prefix ); ?>[sponsor_id]">
						<option value="0"><?php esc_html_e( 'No sponsor', 'virtual-balloon-race' ); ?></option>
						<?php foreach ( $sponsors as $sponsor ) : ?>
							<option value="<?php echo esc_attr( (string) $sponsor->id ); ?>" <?php selected( (int) $prize['sponsor_id'], (int) $sponsor->id ); ?>><?php echo esc_html( $sponsor->name ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
				<button type="button" class="button-link button-link-delete vbr-prizes-editor__remove"><?php esc_html_e( 'Remove', 'virtual-balloon-race' ); ?></button>
			</li>
			<?php
		};
		?>
		<div class="vbr-prizes-editor">
			<ol class="vbr-prizes-editor__list">
				<?php
				foreach ( array_values( $prizes ) as $index => $prize ) {
					$row(
						$index,
						array(
							'id'          => (int) $prize->id,
							'kind'        => $prize->kind,
							'label'       => $prize->label,
							'description' => $prize->description,
							'sponsor_id'  => (int) $prize->sponsor_id,
						)
					);
				}
				?>
			</ol>
			<template class="vbr-prizes-editor__template">
				<?php
				$row(
					'__i__',
					array(
						'id'          => 0,
						'kind'        => 'place_1',
						'label'       => '',
						'description' => '',
						'sponsor_id'  => 0,
					)
				);
				?>
			</template>
			<p><button type="button" class="button vbr-prizes-editor__add"><?php esc_html_e( 'Add prize', 'virtual-balloon-race' ); ?></button></p>
		</div>
		<?php
	}
}
