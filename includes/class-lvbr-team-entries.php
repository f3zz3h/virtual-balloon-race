<?php
/**
 * Team choice when buying a balloon entry.
 *
 * @package Virtual_Balloon_Race
 */

defined( 'ABSPATH' ) || exit;

/**
 * Adds a required Team select for races with teams and carries the team to the balloon.
 */
class LVBR_Team_Entries {

	/**
	 * Register hooks (just after the matching race hooks in LVBR_Race_Entries).
	 */
	public function __construct() {
		add_action( 'lvbr_product_fields_before', array( $this, 'output_field' ), 20 );
		add_filter( 'woocommerce_add_to_cart_validation', array( $this, 'validate' ), 16, 2 );
		add_filter( 'woocommerce_add_cart_item_data', array( $this, 'add_cart_item_data' ), 16, 2 );
		add_filter( 'woocommerce_get_item_data', array( $this, 'display_cart_item_data' ), 6, 2 );
		add_action( 'woocommerce_checkout_create_order_line_item', array( $this, 'save_order_item_meta' ), 16, 3 );
		add_filter( 'lvbr_new_balloon_row', array( $this, 'apply_team' ), 6, 2 );
	}

	/**
	 * Posted team, if it belongs to the posted race.
	 *
	 * @param int $product_id Product ID.
	 * @return object|null
	 */
	private function posted_team( $product_id ) {
		$race = LVBR_Race_Entries::posted_race( $product_id );
		$id   = isset( $_POST['lvbr_team_id'] ) ? absint( $_POST['lvbr_team_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified by LVBR_WooCommerce.
		$team = LVBR_Teams::get( $id );

		return $race && $team && (int) $team->race_id === (int) $race->id ? $team : null;
	}

	/**
	 * Team select. With several open races, options carry their race so the script can filter them.
	 *
	 * @param WC_Product $product Product.
	 */
	public function output_field( $product ) {
		if ( ! LVBR_Teams::enabled() ) {
			return;
		}

		$races   = LVBR_Race_Entries::open_races_for( $product->get_id() );
		$options = array();

		foreach ( $races as $race ) {
			foreach ( LVBR_Teams::for_race( (int) $race->id ) as $team ) {
				$options[] = array( $race, $team );
			}
		}

		if ( ! $options ) {
			return;
		}

		$posted = isset( $_POST['lvbr_team_id'] ) ? absint( $_POST['lvbr_team_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		?>
		<p class="form-row form-row-wide lvbr-team-field">
			<label for="lvbr_team_id">
				<?php esc_html_e( 'Team', 'lumaha-virtual-balloon-race' ); ?>
				<abbr class="required" title="<?php esc_attr_e( 'Required', 'lumaha-virtual-balloon-race' ); ?>">*</abbr>
			</label>
			<select id="lvbr_team_id" name="lvbr_team_id" required>
				<option value=""><?php esc_html_e( 'Choose your team', 'lumaha-virtual-balloon-race' ); ?></option>
				<?php foreach ( $options as $option ) : ?>
					<option value="<?php echo esc_attr( (string) $option[1]->id ); ?>" data-race="<?php echo esc_attr( (string) $option[0]->id ); ?>" <?php selected( $posted, (int) $option[1]->id ); ?>><?php echo esc_html( $option[1]->name ); ?></option>
				<?php endforeach; ?>
			</select>
		</p>
		<?php
	}

	/**
	 * Require a team of the chosen race when that race has teams.
	 *
	 * @param bool $passed     Validation so far.
	 * @param int  $product_id Product ID.
	 * @return bool
	 */
	public function validate( $passed, $product_id ) {
		if ( ! $passed || ! LVBR_Teams::enabled() || ! LVBR_WooCommerce::is_balloon_product( $product_id ) ) {
			return $passed;
		}

		$race = LVBR_Race_Entries::posted_race( $product_id );

		if ( LVBR_Teams::race_uses_teams( $race ) && ! $this->posted_team( $product_id ) ) {
			wc_add_notice( __( 'Please choose a team for your balloon.', 'lumaha-virtual-balloon-race' ), 'error' );
			return false;
		}

		return $passed;
	}

	/**
	 * Store the team on the cart item.
	 *
	 * @param array $cart_item_data Cart item data.
	 * @param int   $product_id     Product ID.
	 * @return array
	 */
	public function add_cart_item_data( $cart_item_data, $product_id ) {
		if ( empty( $cart_item_data['lvbr_balloon_name'] ) || ! LVBR_Teams::enabled() ) {
			return $cart_item_data;
		}

		$team = $this->posted_team( $product_id );

		if ( $team ) {
			$cart_item_data['lvbr_team_id'] = (int) $team->id;
		}

		return $cart_item_data;
	}

	/**
	 * Show the team on cart lines.
	 *
	 * @param array $item_data Rows.
	 * @param array $cart_item Cart item.
	 * @return array
	 */
	public function display_cart_item_data( $item_data, $cart_item ) {
		$team = ! empty( $cart_item['lvbr_team_id'] ) ? LVBR_Teams::get( (int) $cart_item['lvbr_team_id'] ) : null;

		if ( $team ) {
			$item_data[] = array(
				'key'   => __( 'Team', 'lumaha-virtual-balloon-race' ),
				'value' => $team->name,
			);
		}

		return $item_data;
	}

	/**
	 * Copy the team to the order line.
	 *
	 * @param WC_Order_Item_Product $item          Order item.
	 * @param string                $cart_item_key Cart item key.
	 * @param array                 $values        Cart values.
	 */
	public function save_order_item_meta( $item, $cart_item_key, $values ) {
		$team = ! empty( $values['lvbr_team_id'] ) ? LVBR_Teams::get( (int) $values['lvbr_team_id'] ) : null;

		if ( $team ) {
			$item->add_meta_data( __( 'Team', 'lumaha-virtual-balloon-race' ), $team->name, true );
			$item->add_meta_data( '_lvbr_team_id', (string) (int) $team->id, true );
		}
	}

	/**
	 * Put a new balloon in its team (runs after the race is set).
	 *
	 * @param array                 $row  Balloon row.
	 * @param WC_Order_Item_Product $item Order item.
	 * @return array
	 */
	public function apply_team( $row, $item ) {
		$team = LVBR_Teams::get( (int) $item->get_meta( '_lvbr_team_id', true ) );

		if ( $team && isset( $row['race_id'] ) && (int) $team->race_id === (int) $row['race_id'] ) {
			$row['team_id'] = (int) $team->id;
		}

		return $row;
	}
}
