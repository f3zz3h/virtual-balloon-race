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
	 * Show "only N places left" at or below this many.
	 */
	const FEW_PLACES = 10;

	/**
	 * Entries taken per race, cached for the request (the shop asks many times per page).
	 *
	 * @var int[]
	 */
	private static $taken = array();

	/**
	 * Entry products with no race planned, worked out once per request (null = not yet).
	 *
	 * @var int[]|null
	 */
	private static $hidden = null;

	/**
	 * True while working out $hidden, whose product query must not be filtered itself.
	 *
	 * @var bool
	 */
	private static $finding = false;

	/**
	 * Register hooks.
	 */
	public function __construct() {
		// Product admin: lock a product to one race.
		add_action( 'vbr_product_data_panel', array( $this, 'output_product_option' ) );
		add_action( 'woocommerce_process_product_meta', array( $this, 'save_product_option' ), 20 );

		// Only purchasable while a race is taking entries, and only listed once a race is planned.
		add_filter( 'woocommerce_is_purchasable', array( $this, 'is_purchasable' ), 20, 2 );
		add_filter( 'woocommerce_product_is_visible', array( $this, 'is_visible' ), 20, 2 );
		add_action( 'woocommerce_product_query', array( $this, 'hide_from_query' ) );
		add_action( 'pre_get_posts', array( $this, 'hide_from_search' ) );
		add_filter( 'woocommerce_shortcode_products_query', array( $this, 'hide_from_args' ) );
		add_filter( 'query_loop_block_query_vars', array( $this, 'hide_from_args' ) );
		add_filter( 'wp_sitemaps_posts_query_args', array( $this, 'hide_from_sitemap' ), 10, 2 );
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

		// Places taken change when orders do.
		add_action( 'woocommerce_order_status_changed', array( __CLASS__, 'flush_counts' ) );
		add_action( 'woocommerce_new_order', array( __CLASS__, 'flush_counts' ) );
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
	 * Whether a race is planned for a product: upcoming or taking entries (sold out counts, it's still shown).
	 * A product locked to one race depends on that race only.
	 *
	 * @param int $product_id Product ID.
	 * @return bool
	 */
	public static function planned_for( $product_id ) {
		$locked = self::product_race( $product_id );
		$races  = $locked ? array_filter( array( VBR_Races::get( $locked ) ) ) : VBR_Races::all();

		foreach ( $races as $race ) {
			if ( in_array( VBR_Races::status( $race ), array( VBR_Races::UPCOMING, VBR_Races::ON_SALE ), true ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Entry products to keep out of the shop because no race is planned for them.
	 *
	 * @return int[]
	 */
	public static function hidden_product_ids() {
		if ( null !== self::$hidden ) {
			return self::$hidden;
		}

		if ( self::$finding ) {
			return array();
		}

		self::$finding = true;
		$hidden        = array();

		foreach ( VBR_Product_Type::all_products() as $product ) {
			if ( ! self::planned_for( $product->get_id() ) ) {
				$hidden[] = (int) $product->get_id();
			}
		}

		self::$finding = false;
		self::$hidden  = $hidden;

		return $hidden;
	}

	/**
	 * Forget which products are hidden (races changed during this request).
	 */
	public static function flush_hidden() {
		self::$hidden = null;
	}

	/**
	 * Not listed in the shop, related products and the like until a race is planned.
	 *
	 * @param bool $visible    Visible so far.
	 * @param int  $product_id Product ID.
	 * @return bool
	 */
	public function is_visible( $visible, $product_id ) {
		return $visible && ! in_array( (int) $product_id, self::hidden_product_ids(), true );
	}

	/**
	 * Keep unplanned entry products out of the places that list products to visitors: the shop, category and
	 * tag pages (`woocommerce_product_query`), search results, the [products] shortcode, Query Loop and product
	 * collection blocks, and the sitemap. Related products and upsells use is_visible(). The product's own page
	 * still opens (with "no races taking entries" and nothing to buy), so a link shared early still works.
	 * Other product queries (the plugin's own, WooCommerce's, the admin) are left alone.
	 *
	 * @param WP_Query $query The shop's product query.
	 */
	public function hide_from_query( $query ) {
		$hidden = self::hidden_product_ids();

		if ( $hidden ) {
			$query->set( 'post__not_in', array_merge( (array) $query->get( 'post__not_in' ), $hidden ) );
		}
	}

	/**
	 * Site search results (the main query of a search page).
	 *
	 * @param WP_Query $query Query.
	 */
	public function hide_from_search( $query ) {
		if ( ! is_admin() && $query->is_main_query() && $query->is_search() ) {
			$this->hide_from_query( $query );
		}
	}

	/**
	 * Query arguments for product listings ([products] shortcode, Query Loop / product collection blocks).
	 *
	 * @param array $args WP_Query arguments.
	 * @return array
	 */
	public function hide_from_args( $args ) {
		$types = isset( $args['post_type'] ) ? (array) $args['post_type'] : array();

		if ( in_array( 'product', $types, true ) ) {
			$hidden = self::hidden_product_ids();
			if ( $hidden ) {
				// A handful of entry product IDs at most, only while no race is planned for them.
				$args['post__not_in'] = array_merge( isset( $args['post__not_in'] ) ? (array) $args['post__not_in'] : array(), $hidden ); // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_post__not_in
			}
		}

		return $args;
	}

	/**
	 * The products sitemap.
	 *
	 * @param array  $args      WP_Query arguments.
	 * @param string $post_type Post type.
	 * @return array
	 */
	public function hide_from_sitemap( $args, $post_type ) {
		return 'product' === $post_type ? $this->hide_from_args( array_merge( $args, array( 'post_type' => 'product' ) ) ) : $args;
	}

	/**
	 * Races a product can be bought for right now (taking entries and not sold out).
	 *
	 * @param int $product_id Product ID.
	 * @return object[]
	 */
	public static function open_races_for( $product_id ) {
		return array_values(
			array_filter(
				VBR_Races::open_for_entries( self::product_race( $product_id ) ),
				static function ( $race ) {
					return 0 !== self::places_left( $race );
				}
			)
		);
	}

	/**
	 * Entries a race has taken: balloons still in the race, plus entries in orders awaiting payment
	 * (on hold, e.g. bank transfer, and pending payment from the last day), so a race can't be oversold
	 * while payments are pending.
	 *
	 * @param object $race Race.
	 * @return int
	 */
	public static function entries_taken( $race ) {
		global $wpdb;

		$id = (int) $race->id;

		if ( isset( self::$taken[ $id ] ) ) {
			return self::$taken[ $id ];
		}

		$table = VBR_Balloons::table();
		$taken = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE race_id = %d AND withdrawn_at IS NULL", $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the plugin's own table.

		if ( function_exists( 'wc_get_orders' ) ) {
			// This visitor's own order awaiting payment is already their basket: don't count it twice.
			/** @var WC_Session|null $session Not set outside the shop front end (e.g. admin, WP-CLI). */
			$session = function_exists( 'WC' ) ? WC()->session : null;
			$own     = $session ? (int) $session->get( 'order_awaiting_payment' ) : 0;
			$unpaid  = array_merge(
				wc_get_orders(
					array(
						'status' => array( 'on-hold' ),
						'limit'  => -1,
					)
				),
				wc_get_orders(
					array(
						'status'       => array( 'pending' ),
						'limit'        => -1,
						'date_created' => '>' . ( time() - DAY_IN_SECONDS ),
					)
				)
			);

			foreach ( $unpaid as $order ) {
				if ( ! $order instanceof WC_Order || $order->get_id() === $own ) {
					continue;
				}
				foreach ( $order->get_items() as $item ) {
					if ( $item instanceof WC_Order_Item_Product && (int) $item->get_meta( '_vbr_race_id', true ) === $id ) {
						$taken += max( 1, (int) $item->get_quantity() );
					}
				}
			}
		}

		self::$taken[ $id ] = $taken;

		return $taken;
	}

	/**
	 * Forget cached entry counts (an order or balloon changed during this request).
	 */
	public static function flush_counts() {
		self::$taken = array();
	}

	/**
	 * Places left in a race, or null when it has no entry limit.
	 *
	 * @param object $race Race.
	 * @return int|null
	 */
	public static function places_left( $race ) {
		$limit = isset( $race->entry_limit ) ? (int) $race->entry_limit : 0;

		return $limit > 0 ? max( 0, $limit - self::entries_taken( $race ) ) : null;
	}

	/**
	 * Entries for a race already in this visitor's basket.
	 *
	 * @param int $race_id Race ID.
	 * @return int
	 */
	private static function in_cart( $race_id ) {
		/** @var WC_Cart|null $cart Not set outside the shop front end. */
		$cart  = WC()->cart;
		$count = 0;

		foreach ( $cart ? $cart->get_cart() : array() as $item ) {
			if ( ! empty( $item['vbr_race_id'] ) && (int) $item['vbr_race_id'] === (int) $race_id ) {
				$count += (int) $item['quantity'];
			}
		}

		return $count;
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

		echo '<div class="options_group">';

		woocommerce_wp_select(
			array(
				'id'          => self::PRODUCT_META,
				'label'       => __( 'Balloon race', 'virtual-balloon-race' ),
				'options'     => $options,
				'value'       => $current,
				'desc_tip'    => true,
				'description' => __( 'Which race this product sells entries for. It can only be bought while that race is taking entries.', 'virtual-balloon-race' ),
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
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce verifies the product save nonce.
		if ( ! current_user_can( 'edit_post', $product_id ) || ! isset( $_POST['product-type'] ) || VBR_Product_Type::TYPE !== $_POST['product-type'] ) {
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

		if ( ! VBR_WooCommerce::is_balloon_product( $product ) ) {
			return $purchasable;
		}

		return (bool) self::open_races_for( $product->get_id() );
	}

	/**
	 * Explain why a balloon product can't be bought right now.
	 */
	public function closed_message() {
		global $product;

		if ( ! $product || ! VBR_WooCommerce::is_balloon_product( $product ) || self::open_races_for( $product->get_id() ) ) {
			return;
		}

		$locked   = self::product_race( $product->get_id() );
		$next     = VBR_Races::next_upcoming( $locked );
		$race     = $locked ? VBR_Races::get( $locked ) : null;
		$sold_out = VBR_Races::open_for_entries( $locked );

		if ( $sold_out ) {
			$message = 1 === count( $sold_out )
				/* translators: %s: race name */
				? sprintf( __( 'Sorry, %s is sold out.', 'virtual-balloon-race' ), $sold_out[0]->name )
				: __( 'Sorry, all the races taking entries are sold out.', 'virtual-balloon-race' );
		} elseif ( $next && VBR_Races::ts( $next->sales_open_at ) ) {
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
	 * "Only 3 places left!" under the race, when a race with a limit is nearly full.
	 *
	 * @param object $race Race.
	 */
	public static function places_left_notice( $race ) {
		$left = self::places_left( $race );

		if ( null === $left || $left > self::FEW_PLACES ) {
			return;
		}

		/* translators: %s: places left */
		echo '<p class="vbr-places-left">' . esc_html( sprintf( _n( 'Only %s place left!', 'Only %s places left!', $left, 'virtual-balloon-race' ), number_format_i18n( $left ) ) ) . '</p>';
	}

	/**
	 * " (3 places left)" after a race in the race picker, when nearly full.
	 *
	 * @param object $race Race.
	 * @return string
	 */
	private static function places_left_suffix( $race ) {
		$left = self::places_left( $race );

		/* translators: %s: places left */
		return null === $left || $left > self::FEW_PLACES ? '' : ' (' . sprintf( _n( '%s place left', '%s places left', $left, 'virtual-balloon-race' ), number_format_i18n( $left ) ) . ')';
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
			<?php $presented = VBR_Sponsors::presented_by( $race ); ?>
			<?php if ( $presented ) : ?>
				<p class="vbr-race-presented"><?php echo wp_kses_post( $presented ); ?></p>
			<?php endif; ?>
			<?php self::places_left_notice( $race ); ?>
			<?php VBR_Prizes::render_up_for_grabs( $race ); ?>
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
							) . self::places_left_suffix( $race )
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
	public static function posted_race( $product_id ) {
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

		$race = self::posted_race( $product_id );

		if ( ! $race ) {
			wc_add_notice( __( 'Please choose a race that is taking entries.', 'virtual-balloon-race' ), 'error' );
			return false;
		}

		$left = self::places_left( $race );

		if ( null !== $left && self::in_cart( (int) $race->id ) + 1 > $left ) {
			wc_add_notice(
				sprintf(
					/* translators: 1: race name, 2: places left */
					_n( 'Sorry, %1$s has only %2$s place left, and it’s already in your basket.', 'Sorry, %1$s has only %2$s places left, and they’re all in your basket.', $left, 'virtual-balloon-race' ),
					$race->name,
					number_format_i18n( $left )
				),
				'error'
			);
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

		$race = self::posted_race( $product_id );

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
		/** @var WC_Cart|null $cart Not set outside the shop front end (e.g. admin, REST, WP-CLI). */
		$cart = WC()->cart;

		if ( ! $cart ) {
			return;
		}

		// Entries beyond a race's places left (others bought the last places first): keep the earliest ones.
		$kept = array();
		foreach ( $cart->get_cart() as $key => $item ) {
			$race = empty( $item['vbr_race_id'] ) ? null : VBR_Races::get( (int) $item['vbr_race_id'] );

			if ( ! $race || ! VBR_Races::is_open( $race ) ) {
				continue;
			}

			$left = self::places_left( $race );
			$id   = (int) $race->id;

			$kept[ $id ] = isset( $kept[ $id ] ) ? $kept[ $id ] : 0;

			if ( null === $left || $kept[ $id ] < $left ) {
				++$kept[ $id ];
				continue;
			}

			$cart->remove_cart_item( $key );
			wc_add_notice(
				sprintf(
					/* translators: 1: balloon name, 2: race name */
					__( '“%1$s” was removed from your basket because %2$s has sold out.', 'virtual-balloon-race' ),
					isset( $item['vbr_balloon_name'] ) ? $item['vbr_balloon_name'] : __( 'Balloon', 'virtual-balloon-race' ),
					$race->name
				),
				'error'
			);
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
			$item->add_meta_data( '_vbr_race_id', (string) (int) $race->id, true );
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
