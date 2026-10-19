<?php
/**
 * Selling entries for a specific race: only while it is taking entries.
 *
 * @package Virtual_Balloon_Race
 */

defined( 'ABSPATH' ) || exit;

/**
 * Race choice on products, purchasability, cart guard, and race_id on new balloons.
 */
class VBR_Race_Entries {

	const PRODUCT_META = '_vbr_race_id';

	/**
	 * Register hooks.
	 */
	public function __construct() {
		// Product admin: lock a product to one race.
		add_action( 'woocommerce_product_options_general_product_data', array( $this, 'output_product_option' ), 20 );
		add_action( 'woocommerce_process_product_meta', array( $this, 'save_product_option' ), 20 );

		// Only purchasable while a race is taking entries.
		add_filter( 'woocommerce_is_purchasable', array( $this, 'is_purchasable' ), 20, 2 );
		add_filter( 'woocommerce_variation_is_purchasable', array( $this, 'is_purchasable' ), 20, 2 );
		add_action( 'woocommerce_single_product_summary', array( $this, 'closed_message' ), 31 );
		add_filter( 'woocommerce_loop_add_to_cart_link', array( $this, 'loop_button' ), 20, 2 );

		// Product page → cart → order.
		add_action( 'vbr_product_fields_before', array( $this, 'output_race_field' ) );
		add_filter( 'woocommerce_add_to_cart_validation', array( $this, 'validate' ), 15, 2 );
		add_filter( 'woocommerce_add_cart_item_data', array( $this, 'add_cart_item_data' ), 15, 2 );
		add_filter( 'woocommerce_get_item_data', array( $this, 'display_cart_item_data' ), 5, 2 );
		add_action( 'woocommerce_check_cart_items', array( $this, 'check_cart_items' ) );
		add_action( 'woocommerce_checkout_create_order_line_item', array( $this, 'save_order_item_meta' ), 15, 3 );

		// New balloon rows start at their race's launch point (custom launch runs later).
		add_filter( 'vbr_new_balloon_row', array( $this, 'apply_race' ), 5, 2 );
	}

	/**
	 * Race a product is locked to (0 = any open race).
	 *
	 * @param int $product_id Product ID (variations use their parent).
	 * @return int
	 */
	public static function product_race( $product_id ) {
		$product = wc_get_product( $product_id );

		if ( $product && $product->get_parent_id() ) {
			$product_id = $product->get_parent_id();
		}

		return (int) get_post_meta( $product_id, self::PRODUCT_META, true );
	}

	/**
	 * Races a product can be bought for right now.
	 *
	 * @param int $product_id Product ID.
	 * @return object[]
	 */
	public static function open_races_for( $product_id ) {
		return VBR_Races::open_for_entries( self::product_race( $product_id ) );
	}

	/**
	 * Product data: race dropdown.
	 */
	public function output_product_option() {
		global $post;

		$current = $post ? (int) get_post_meta( $post->ID, self::PRODUCT_META, true ) : 0;
		$options = array( 0 => __( 'Any race taking entries', 'virtual-balloon-race' ) );

		foreach ( VBR_Races::all() as $race ) {
			if ( VBR_Races::FINISHED !== VBR_Races::status( $race ) || (int) $race->id === $current ) {
				$options[ (int) $race->id ] = $race->name . ' (' . VBR_Races::status_label( $race ) . ')';
			}
		}

		echo '<div class="options_group show_if_simple show_if_variable">';

		woocommerce_wp_select(
			array(
				'id'          => self::PRODUCT_META,
				'label'       => __( 'Balloon race', 'virtual-balloon-race' ),
				'options'     => $options,
				'value'       => $current,
				'desc_tip'    => true,
				'description' => __( 'Balloon entries only: which race this product sells entries for. It can only be bought while that race is taking entries.', 'virtual-balloon-race' ),
			)
		);

		echo '</div>';
	}

	/**
	 * Save the race dropdown.
	 *
	 * @param int $product_id Product ID.
	 */
	public function save_product_option( $product_id ) {
		if ( ! current_user_can( 'edit_post', $product_id ) ) {
			return;
		}

		$race_id = isset( $_POST[ self::PRODUCT_META ] ) ? absint( $_POST[ self::PRODUCT_META ] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce verifies the product save nonce.
		update_post_meta( $product_id, self::PRODUCT_META, VBR_Races::get( $race_id ) ? $race_id : 0 );
	}

	/**
	 * Balloon products can't be bought when no race is taking entries for them.
	 *
	 * @param bool       $purchasable Purchasable so far.
	 * @param WC_Product $product     Product.
	 * @return bool
	 */
	public function is_purchasable( $purchasable, $product ) {
		if ( ! $purchasable || is_admin() && ! wp_doing_ajax() ) {
			return $purchasable;
		}

		$check = $product->get_parent_id() ? $product->get_parent_id() : $product->get_id();

		if ( ! VBR_WooCommerce::is_balloon_product( $check ) ) {
			return $purchasable;
		}

		return (bool) self::open_races_for( $check );
	}

	/**
	 * Explain why a balloon product can't be bought right now.
	 */
	public function closed_message() {
		global $product;

		if ( ! $product || ! VBR_WooCommerce::is_balloon_product( $product ) || self::open_races_for( $product->get_id() ) ) {
			return;
		}

		$locked = self::product_race( $product->get_id() );
		$next   = VBR_Races::next_upcoming( $locked );
		$race   = $locked ? VBR_Races::get( $locked ) : null;

		if ( $next && VBR_Races::ts( $next->sales_open_at ) ) {
			$message = sprintf(
				/* translators: 1: race name, 2: date */
				__( 'Entries for %1$s open on %2$s.', 'virtual-balloon-race' ),
				$next->name,
				VBR_Races::format_date( $next->sales_open_at )
			);
		} elseif ( $race ) {
			/* translators: %s: race name */
			$message = sprintf( __( 'Entries for %s have closed.', 'virtual-balloon-race' ), $race->name );
		} else {
			$message = __( 'No balloon races are taking entries right now. Please check back soon!', 'virtual-balloon-race' );
		}

		echo '<p class="vbr-entries-closed">' . esc_html( $message ) . '</p>';
	}

	/**
	 * Shop-grid buttons can't collect balloon details, so link to the product instead.
	 *
	 * @param string     $html    Button HTML.
	 * @param WC_Product $product Product.
	 * @return string
	 */
	public function loop_button( $html, $product ) {
		if ( ! VBR_WooCommerce::is_balloon_product( $product ) || ! $product->is_purchasable() ) {
			return $html;
		}

		return sprintf(
			'<a href="%1$s" class="button vbr-choose-balloon">%2$s</a>',
			esc_url( $product->get_permalink() ),
			esc_html__( 'Design your balloon', 'virtual-balloon-race' )
		);
	}

	/**
	 * Race picker at the top of the balloon box.
	 *
	 * @param WC_Product $product Product.
	 */
	public function output_race_field( $product ) {
		$races  = self::open_races_for( $product->get_id() );
		$posted = isset( $_POST['vbr_race_id'] ) ? absint( $_POST['vbr_race_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing

		if ( ! $races ) {
			return;
		}

		if ( 1 === count( $races ) ) {
			$race = $races[0];
			?>
			<p class="vbr-race-entering">
				<?php
				echo esc_html(
					sprintf(
						/* translators: 1: race name, 2: launch date */
						__( 'Entering: %1$s — launches %2$s', 'virtual-balloon-race' ),
						$race->name,
						VBR_Races::format_date( $race->launch_at )
					)
				);
				?>
				<input type="hidden" name="vbr_race_id" value="<?php echo esc_attr( (string) $race->id ); ?>" />
			</p>
			<?php
			return;
		}
		?>
		<p class="form-row form-row-wide">
			<label for="vbr_race_id">
				<?php esc_html_e( 'Race', 'virtual-balloon-race' ); ?>
				<abbr class="required" title="<?php esc_attr_e( 'Required', 'virtual-balloon-race' ); ?>">*</abbr>
			</label>
			<select id="vbr_race_id" name="vbr_race_id" required>
				<option value=""><?php esc_html_e( 'Choose a race', 'virtual-balloon-race' ); ?></option>
				<?php foreach ( $races as $race ) : ?>
					<option value="<?php echo esc_attr( (string) $race->id ); ?>" <?php selected( $posted, (int) $race->id ); ?>>
						<?php
						echo esc_html(
							sprintf(
								/* translators: 1: race name, 2: launch date */
								__( '%1$s — launches %2$s', 'virtual-balloon-race' ),
								$race->name,
								VBR_Races::format_date( $race->launch_at )
							)
						);
						?>
					</option>
				<?php endforeach; ?>
			</select>
		</p>
		<?php
	}

	/**
	 * Posted race, if it is open for this product.
	 *
	 * @param int $product_id Product ID.
	 * @return object|null
	 */
	private function posted_race( $product_id ) {
		$id = isset( $_POST['vbr_race_id'] ) ? absint( $_POST['vbr_race_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified by VBR_WooCommerce.

		foreach ( self::open_races_for( $product_id ) as $race ) {
			if ( (int) $race->id === $id ) {
				return $race;
			}
		}

		return null;
	}

	/**
	 * Require an open race on add to cart.
	 *
	 * @param bool $passed     Validation so far.
	 * @param int  $product_id Product ID.
	 * @return bool
	 */
	public function validate( $passed, $product_id ) {
		if ( ! $passed || ! VBR_WooCommerce::is_balloon_product( $product_id ) ) {
			return $passed;
		}

		if ( ! $this->posted_race( $product_id ) ) {
			wc_add_notice( __( 'Please choose a race that is taking entries.', 'virtual-balloon-race' ), 'error' );
			return false;
		}

		return $passed;
	}

	/**
	 * Store the race on the cart item.
	 *
	 * @param array $cart_item_data Cart item data.
	 * @param int   $product_id     Product ID.
	 * @return array
	 */
	public function add_cart_item_data( $cart_item_data, $product_id ) {
		if ( empty( $cart_item_data['vbr_balloon_name'] ) ) {
			return $cart_item_data;
		}

		$race = $this->posted_race( $product_id );

		if ( $race ) {
			$cart_item_data['vbr_race_id'] = (int) $race->id;
		}

		return $cart_item_data;
	}

	/**
	 * Show the race on cart lines.
	 *
	 * @param array $item_data Rows.
	 * @param array $cart_item Cart item.
	 * @return array
	 */
	public function display_cart_item_data( $item_data, $cart_item ) {
		$race = ! empty( $cart_item['vbr_race_id'] ) ? VBR_Races::get( $cart_item['vbr_race_id'] ) : null;

		if ( $race ) {
			$item_data[] = array(
				'key'   => __( 'Race', 'virtual-balloon-race' ),
				'value' => $race->name,
			);
		}

		return $item_data;
	}

	/**
	 * Drop entries for races that have stopped taking entries (runs on cart and at checkout).
	 */
	public function check_cart_items() {
		$cart = WC()->cart;

		if ( ! $cart ) {
			return;
		}

		foreach ( $cart->get_cart() as $key => $item ) {
			if ( empty( $item['vbr_race_id'] ) || VBR_Races::is_open( (int) $item['vbr_race_id'] ) ) {
				continue;
			}

			$race = VBR_Races::get( (int) $item['vbr_race_id'] );
			$cart->remove_cart_item( $key );

			wc_add_notice(
				sprintf(
					/* translators: 1: balloon name, 2: race name */
					__( '“%1$s” was removed from your basket because %2$s is no longer taking entries.', 'virtual-balloon-race' ),
					isset( $item['vbr_balloon_name'] ) ? $item['vbr_balloon_name'] : __( 'Balloon', 'virtual-balloon-race' ),
					$race ? $race->name : __( 'that race', 'virtual-balloon-race' )
				),
				'error'
			);
		}
	}

	/**
	 * Copy the race to the order line.
	 *
	 * @param WC_Order_Item_Product $item          Order item.
	 * @param string                $cart_item_key Cart item key.
	 * @param array                 $values        Cart values.
	 */
	public function save_order_item_meta( $item, $cart_item_key, $values ) {
		$race = ! empty( $values['vbr_race_id'] ) ? VBR_Races::get( $values['vbr_race_id'] ) : null;

		if ( $race ) {
			$item->add_meta_data( __( 'Balloon Race', 'virtual-balloon-race' ), $race->name, true );
			$item->add_meta_data( '_vbr_race_id', (int) $race->id, true );
		}
	}

	/**
	 * Put a new balloon in its race, at the race's launch point.
	 *
	 * Older orders without a race fall back to the product's race, then the current race.
	 *
	 * @param array                 $row  Balloon row.
	 * @param WC_Order_Item_Product $item Order item.
	 * @return array
	 */
	public function apply_race( $row, $item ) {
		$race = VBR_Races::get( (int) $item->get_meta( '_vbr_race_id', true ) );

		if ( ! $race && $item->get_product_id() ) {
			$race = VBR_Races::get( self::product_race( $item->get_product_id() ) );
		}

		$race = $race ? $race : VBR_Races::current();

		if ( ! $race ) {
			return $row;
		}

		$row['race_id'] = (int) $race->id;

		return array_merge( $row, VBR_Balloons::start_columns( (float) $race->launch_lat, (float) $race->launch_lng ) );
	}
}
