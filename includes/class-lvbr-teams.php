<?php
/**
 * Optional teams: per-race team lists, scoring and the shared team editor.
 *
 * @package Virtual_Balloon_Race
 */

defined( 'ABSPATH' ) || exit;

/**
 * Team model and helpers.
 */
class LVBR_Teams {

	const AVERAGE = 'average';
	const TOTAL   = 'total';
	const BEST    = 'best';

	/**
	 * Teams table name.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;

		return $wpdb->prefix . 'lvbr_teams';
	}

	/**
	 * Whether teams are switched on in Settings.
	 *
	 * @return bool
	 */
	public static function enabled() {
		return (bool) LVBR_Settings::get( 'teams_enabled' );
	}

	/**
	 * Scoring methods.
	 *
	 * @return array
	 */
	public static function scoring_labels() {
		return array(
			self::AVERAGE => __( 'Average distance', 'lumaha-virtual-balloon-race' ),
			self::TOTAL   => __( 'Total distance', 'lumaha-virtual-balloon-race' ),
			self::BEST    => __( 'Best balloon', 'lumaha-virtual-balloon-race' ),
		);
	}

	/**
	 * Colours handed to teams added without one.
	 *
	 * @return string[]
	 */
	public static function palette() {
		return array( '#d64545', '#2b6cb0', '#2f855a', '#d4a800', '#6b46c1', '#e26a2c', '#d6457f', '#0f8b8d' );
	}

	/**
	 * Clean a list of submitted (already unslashed) teams: `[ { id?, name, color } ]`, empty names dropped.
	 *
	 * @param mixed $rows Raw rows.
	 * @return array[]
	 */
	public static function clean_rows( $rows ) {
		$palette = self::palette();
		$out     = array();

		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$name = isset( $row['name'] ) ? sanitize_text_field( $row['name'] ) : '';

			if ( '' === $name ) {
				continue;
			}

			$color = isset( $row['color'] ) ? sanitize_hex_color( $row['color'] ) : '';
			$out[] = array(
				'id'    => isset( $row['id'] ) ? absint( $row['id'] ) : 0,
				'name'  => mb_substr( $name, 0, 100 ),
				'color' => $color ? $color : $palette[ count( $out ) % count( $palette ) ],
			);
		}

		return $out;
	}

	/**
	 * A race's teams, in display order.
	 *
	 * @param int $race_id Race ID.
	 * @return object[]
	 */
	public static function for_race( $race_id ) {
		global $wpdb;

		$table = self::table();

		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE race_id = %d ORDER BY sort ASC, id ASC", (int) $race_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the plugin's own table.
	}

	/**
	 * A race's teams keyed by ID (empty when the race doesn't use teams).
	 *
	 * @param object|null $race Race.
	 * @return object[]
	 */
	public static function by_id( $race ) {
		$out = array();

		if ( self::race_uses_teams( $race ) ) {
			foreach ( self::for_race( (int) $race->id ) as $team ) {
				$out[ (int) $team->id ] = $team;
			}
		}

		return $out;
	}

	/**
	 * One team.
	 *
	 * @param int $id Team ID.
	 * @return object|null
	 */
	public static function get( $id ) {
		global $wpdb;

		$table = self::table();

		return $id ? $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $id ) ) : null; // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the plugin's own table.
	}

	/**
	 * Whether a race shows teams (teams switched on and the race has some).
	 *
	 * @param object|null $race Race.
	 * @return bool
	 */
	public static function race_uses_teams( $race ) {
		return $race && self::enabled() && self::for_race( (int) $race->id );
	}

	/**
	 * Scoring method for a race (its own, else the Settings default).
	 *
	 * @param object|null $race Race.
	 * @return string
	 */
	public static function scoring( $race ) {
		$labels = self::scoring_labels();
		$own    = $race && isset( $race->team_scoring ) ? (string) $race->team_scoring : '';
		$method = isset( $labels[ $own ] ) ? $own : (string) LVBR_Settings::get( 'team_scoring' );

		return isset( $labels[ $method ] ) ? $method : self::AVERAGE;
	}

	/**
	 * Replace a race's teams with the submitted rows. Teams that still have balloons are kept.
	 *
	 * @param int     $race_id Race ID.
	 * @param array[] $rows    Rows from clean_rows().
	 * @return string[] Names of teams that couldn't be removed.
	 */
	public static function save_for_race( $race_id, $rows ) {
		global $wpdb;

		$table    = self::table();
		$existing = array();
		$kept     = array();

		foreach ( self::for_race( $race_id ) as $team ) {
			$existing[ (int) $team->id ] = $team;
		}

		foreach ( array_values( $rows ) as $sort => $row ) {
			$data = array(
				'race_id' => (int) $race_id,
				'name'    => $row['name'],
				'color'   => $row['color'],
				'sort'    => $sort,
			);

			if ( $row['id'] && isset( $existing[ $row['id'] ] ) ) {
				$wpdb->update( $table, $data, array( 'id' => $row['id'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the plugin's own table.
				$kept[ $row['id'] ] = true;
			} else {
				$wpdb->insert( $table, $data ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the plugin's own table.
			}
		}

		$blocked = array();

		foreach ( $existing as $id => $team ) {
			if ( isset( $kept[ $id ] ) ) {
				continue;
			}
			if ( self::balloon_count( $id ) ) {
				$blocked[] = $team->name;
				continue;
			}
			$wpdb->delete( $table, array( 'id' => $id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the plugin's own table.
		}

		return $blocked;
	}

	/**
	 * Give a race a copy of the default teams.
	 *
	 * @param int $race_id Race ID.
	 */
	public static function copy_defaults( $race_id ) {
		self::save_for_race( $race_id, self::clean_rows( (array) LVBR_Settings::get( 'default_teams' ) ) );
	}

	/**
	 * Remove a race's teams (when the race is deleted).
	 *
	 * @param int $race_id Race ID.
	 */
	public static function delete_for_race( $race_id ) {
		global $wpdb;

		$wpdb->delete( self::table(), array( 'race_id' => (int) $race_id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the plugin's own table.
	}

	/**
	 * Balloons in a team.
	 *
	 * @param int $team_id Team ID.
	 * @return int
	 */
	public static function balloon_count( $team_id ) {
		global $wpdb;

		$table = LVBR_Balloons::table();

		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE team_id = %d", (int) $team_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the plugin's own table.
	}

	/**
	 * Team standings for a race, best first, scored on the race's ranking distance. Teams without balloons come last.
	 *
	 * @param object|null $race Race.
	 * @return array[] Each: id, rank, name, color, balloons, score, leader (balloon name).
	 */
	public static function standings( $race ) {
		global $wpdb;

		if ( ! $race ) {
			return array();
		}

		$teams    = self::table();
		$balloons = LVBR_Balloons::table();
		$method   = self::scoring( $race );
		$column   = LVBR_Races::ranking_column( $race );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT t.id, t.name, t.color, COUNT(b.id) AS balloons, COALESCE(SUM(b.{$column}), 0) AS total, COALESCE(AVG(b.{$column}), 0) AS average, COALESCE(MAX(b.{$column}), 0) AS best FROM {$teams} t LEFT JOIN {$balloons} b ON b.team_id = t.id AND b.race_id = t.race_id AND b.withdrawn_at IS NULL WHERE t.race_id = %d GROUP BY t.id, t.name, t.color, t.sort ORDER BY t.sort ASC, t.id ASC", (int) $race->id ) );

		// Furthest balloon per team, for the "leading balloon" column.
		$leaders = array();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT team_id, balloon_name FROM {$balloons} WHERE race_id = %d AND team_id > 0 AND withdrawn_at IS NULL ORDER BY {$column} DESC, id ASC", (int) $race->id ) ) as $b ) {
			if ( ! isset( $leaders[ (int) $b->team_id ] ) ) {
				$leaders[ (int) $b->team_id ] = $b->balloon_name;
			}
		}

		$out = array();
		foreach ( $rows as $row ) {
			$out[] = array(
				'id'       => (int) $row->id,
				'name'     => $row->name,
				'color'    => $row->color,
				'balloons' => (int) $row->balloons,
				'score'    => round( (float) $row->{$method}, 2 ),
				'leader'   => isset( $leaders[ (int) $row->id ] ) ? $leaders[ (int) $row->id ] : '',
			);
		}

		usort(
			$out,
			static function ( $a, $b ) {
				if ( ( $a['balloons'] > 0 ) !== ( $b['balloons'] > 0 ) ) {
					return $a['balloons'] > 0 ? -1 : 1;
				}
				return $b['score'] <=> $a['score'];
			}
		);

		foreach ( $out as $i => &$team ) {
			$team['rank'] = $team['balloons'] ? $i + 1 : null;
		}

		return $out;
	}

	/**
	 * Team editor rows (Settings default list and the race screen).
	 *
	 * @param string  $name     Input name prefix, e.g. `lvbr_race[teams]`.
	 * @param array[] $teams    Rows: name, color, and id on races.
	 * @param array   $defaults Default teams to offer via "Add default teams" (race screen only).
	 */
	public static function render_editor( $name, $teams, $defaults = array() ) {
		$row = static function ( $index, $team ) use ( $name ) {
			$prefix = $name . '[' . $index . ']';
			?>
			<li class="lvbr-teams-editor__row">
				<input type="hidden" name="<?php echo esc_attr( $prefix ); ?>[id]" value="<?php echo esc_attr( (string) ( isset( $team['id'] ) ? (int) $team['id'] : 0 ) ); ?>" />
				<input type="color" name="<?php echo esc_attr( $prefix ); ?>[color]" value="<?php echo esc_attr( $team['color'] ); ?>" aria-label="<?php esc_attr_e( 'Team colour', 'lumaha-virtual-balloon-race' ); ?>" />
				<input type="text" class="regular-text" name="<?php echo esc_attr( $prefix ); ?>[name]" value="<?php echo esc_attr( $team['name'] ); ?>" placeholder="<?php esc_attr_e( 'Team name, e.g. Year 3 or Oak House', 'lumaha-virtual-balloon-race' ); ?>" aria-label="<?php esc_attr_e( 'Team name', 'lumaha-virtual-balloon-race' ); ?>" />
				<?php if ( ! empty( $team['balloons'] ) ) : ?>
					<span class="description">
						<?php
						/* translators: %s: number of balloons */
						echo esc_html( sprintf( _n( '%s balloon', '%s balloons', (int) $team['balloons'], 'lumaha-virtual-balloon-race' ), number_format_i18n( (int) $team['balloons'] ) ) );
						?>
					</span>
				<?php else : ?>
					<button type="button" class="button-link button-link-delete lvbr-teams-editor__remove"><?php esc_html_e( 'Remove', 'lumaha-virtual-balloon-race' ); ?></button>
				<?php endif; ?>
			</li>
			<?php
		};

		$palette = self::palette();
		?>
		<div class="lvbr-teams-editor" data-name="<?php echo esc_attr( $name ); ?>" data-palette="<?php echo esc_attr( wp_json_encode( $palette ) ); ?>"<?php echo $defaults ? ' data-defaults="' . esc_attr( wp_json_encode( $defaults ) ) . '"' : ''; ?>>
			<ul class="lvbr-teams-editor__list">
				<?php
				foreach ( array_values( $teams ) as $index => $team ) {
					$row( $index, $team );
				}
				?>
			</ul>
			<template class="lvbr-teams-editor__template">
				<?php $row( '__i__', array( 'name' => '', 'color' => $palette[0] ) ); ?>
			</template>
			<p>
				<button type="button" class="button lvbr-teams-editor__add"><?php esc_html_e( 'Add team', 'lumaha-virtual-balloon-race' ); ?></button>
				<?php if ( $defaults ) : ?>
					<button type="button" class="button lvbr-teams-editor__defaults"><?php esc_html_e( 'Add default teams', 'lumaha-virtual-balloon-race' ); ?></button>
				<?php endif; ?>
			</p>
		</div>
		<?php
	}
}
