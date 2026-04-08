<?php
defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Better_Messages_WooCommerce' ) ) {

    class Better_Messages_WooCommerce
    {

        public static function instance()
        {
            static $instance = null;

            if ( null === $instance ) {
                $instance = new Better_Messages_WooCommerce();
            }

            return $instance;
        }

        const PRODUCT_META_KEY = '_bm_product_support_user';

        protected $product_button_block_rendered     = false;
        protected $pre_purchase_button_block_rendered = false;

        public function __construct(){
            add_shortcode( 'better_messages_woocommerce_product_button',      array( $this, 'product_button_shortcode' ) );
            add_shortcode( 'better_messages_woocommerce_order_button',        array( $this, 'order_button_shortcode' ) );
            add_shortcode( 'better_messages_woocommerce_pre_purchase_button', array( $this, 'pre_purchase_button_shortcode' ) );

            if ( Better_Messages()->settings['wooCommerceIntegration'] !== '1' ) {
                return;
            }

            add_filter( 'better_messages_get_unique_conversation', array( $this, 'unique_conversation_id' ), 20, 3 );
            add_filter( 'better_messages_rest_thread_item',        array( $this, 'thread_item' ), 10, 5 );
            add_filter( 'bp_better_messages_after_format_message', array( $this, 'format_product_links' ), 110, 4 );
            add_filter( 'better_messages_allowed_tags',            array( $this, 'extend_allowed_message_tags' ) );

            add_action( 'add_meta_boxes',    array( $this, 'register_product_metabox' ) );
            add_action( 'save_post_product', array( $this, 'save_product_metabox' ), 10, 2 );

            add_action( 'template_redirect', array( $this, 'maybe_redirect_view_order' ) );

            add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );

            if ( Better_Messages()->settings['wooCommercePrePurchaseButton'] === '1' ) {
                add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_cart_snapshot_script' ) );
            }

            if ( Better_Messages()->settings['wooCommerceProductButton'] === '1' ) {
                $product_placement = Better_Messages()->settings['wooCommerceProductButtonPlacement'];
                $is_block_theme    = $this->is_block_theme();

                switch ( $product_placement ) {
                    case 'manual':
                        break;
                    case 'before_add_to_cart':
                        add_action( 'woocommerce_before_add_to_cart_form', array( $this, 'product_button' ), 10 );
                        break;
                    case 'after_add_to_cart':
                        add_action( 'woocommerce_after_add_to_cart_form', array( $this, 'product_button' ), 10 );
                        break;
                    case 'after_summary':
                        if ( $is_block_theme ) {
                            add_filter( 'render_block', array( $this, 'inject_product_button_into_block' ), 10, 2 );
                        } else {
                            add_action( 'woocommerce_single_product_summary', array( $this, 'product_button' ), 21 );
                        }
                        break;
                    case 'before_summary':
                    default:
                        if ( $is_block_theme ) {
                            add_filter( 'render_block', array( $this, 'inject_product_button_into_block' ), 10, 2 );
                        } else {
                            add_action( 'woocommerce_single_product_summary', array( $this, 'product_button' ), 19 );
                        }
                        break;
                }
            }

            if ( Better_Messages()->settings['wooCommerceOrderButton'] === '1' ) {
                $order_placement = Better_Messages()->settings['wooCommerceOrderButtonPlacement'];
                switch ( $order_placement ) {
                    case 'manual':
                        break;
                    case 'before_order_table':
                        add_action( 'woocommerce_order_details_before_order_table', array( $this, 'order_button' ), 10, 1 );
                        break;
                    case 'after_customer_details':
                        add_action( 'woocommerce_order_details_after_customer_details', array( $this, 'order_button' ), 10, 1 );
                        break;
                    case 'after_order_table':
                    default:
                        add_action( 'woocommerce_order_details_after_order_table', array( $this, 'order_button' ), 10, 1 );
                        break;
                }
            }

            if ( Better_Messages()->settings['wooCommercePrePurchaseButton'] === '1' ) {
                $cart_placement = Better_Messages()->settings['wooCommercePrePurchaseCartPlacement'];
                switch ( $cart_placement ) {
                    case 'manual':
                        break;
                    case 'before_cart':
                        add_action( 'woocommerce_before_cart', array( $this, 'pre_purchase_button' ), 10 );
                        break;
                    case 'cart_collaterals':
                        add_action( 'woocommerce_cart_collaterals', array( $this, 'pre_purchase_button' ), 5 );
                        break;
                    case 'proceed_to_checkout':
                        add_action( 'woocommerce_proceed_to_checkout', array( $this, 'pre_purchase_button' ), 25 );
                        break;
                    case 'after_cart':
                        add_action( 'woocommerce_after_cart', array( $this, 'pre_purchase_button' ), 10 );
                        break;
                    case 'after_cart_table':
                    default:
                        add_action( 'woocommerce_after_cart_table', array( $this, 'pre_purchase_button' ), 10 );
                        break;
                }

                $checkout_placement = Better_Messages()->settings['wooCommercePrePurchaseCheckoutPlacement'];
                switch ( $checkout_placement ) {
                    case 'manual':
                        break;
                    case 'before_form':
                        add_action( 'woocommerce_before_checkout_form', array( $this, 'pre_purchase_button' ), 10 );
                        break;
                    case 'before_order_summary':
                        add_action( 'woocommerce_checkout_before_order_review', array( $this, 'pre_purchase_button' ), 10 );
                        break;
                    case 'after_form':
                        add_action( 'woocommerce_after_checkout_form', array( $this, 'pre_purchase_button' ), 10 );
                        break;
                    case 'after_order_summary':
                    default:
                        add_action( 'woocommerce_review_order_after_submit', array( $this, 'pre_purchase_button' ), 10 );
                        break;
                }

                if ( $cart_placement !== 'manual' || $checkout_placement !== 'manual' ) {
                    add_filter( 'render_block', array( $this, 'inject_pre_purchase_into_block' ), 10, 2 );
                }
            }

            if ( Better_Messages()->settings['wooCommerceMyAccountLink'] === '1'
                && Better_Messages()->settings['chatPage'] !== 'woocommerce' ) {
                add_filter( 'woocommerce_account_menu_items', array( $this, 'add_my_account_link' ), 20 );
                add_filter( 'woocommerce_get_endpoint_url',   array( $this, 'filter_my_account_link_url' ), 10, 4 );
            }
        }

        protected function resolve_user( $user_id ){
            $user_id = (int) $user_id;
            if ( $user_id <= 0 ) {
                return 0;
            }
            if ( ! Better_Messages()->functions->is_user_exists( $user_id ) ) {
                return 0;
            }
            return $user_id;
        }

        public function get_product_support_user( $product_id = 0 ){
            $product_id = (int) $product_id;
            if ( $product_id > 0 ) {
                $override = (int) get_post_meta( $product_id, self::PRODUCT_META_KEY, true );
                $resolved = $this->resolve_user( $override );
                if ( $resolved ) {
                    return $resolved;
                }
            }
            return $this->resolve_user( Better_Messages()->settings['wooCommerceProductSupportUser'] );
        }

        public function get_order_support_user(){
            return $this->resolve_user( Better_Messages()->settings['wooCommerceOrderSupportUser'] );
        }

        public function get_pre_purchase_support_user(){
            return $this->resolve_user( Better_Messages()->settings['wooCommercePrePurchaseSupportUser'] );
        }

        protected function is_block_theme(){
            return function_exists( 'wp_is_block_theme' ) && wp_is_block_theme();
        }

        public function get_universal_view_order_url( $order_id ){
            return add_query_arg( 'bm_view_order', (int) $order_id, home_url( '/' ) );
        }

        protected function get_admin_order_edit_url( $order_id ){
            if ( class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' )
                && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() ) {
                return admin_url( 'admin.php?page=wc-orders&action=edit&id=' . (int) $order_id );
            }
            return admin_url( 'post.php?post=' . (int) $order_id . '&action=edit' );
        }

        public function maybe_redirect_view_order(){
            if ( ! isset( $_GET['bm_view_order'] ) ) {
                return;
            }

            $order_id = (int) $_GET['bm_view_order'];
            if ( $order_id <= 0 ) {
                return;
            }

            if ( ! function_exists( 'wc_get_order' ) ) {
                wp_safe_redirect( home_url( '/' ) );
                exit;
            }

            if ( ! is_user_logged_in() ) {
                wp_safe_redirect( wp_login_url( $this->get_universal_view_order_url( $order_id ) ) );
                exit;
            }

            $order = wc_get_order( $order_id );
            if ( ! $order ) {
                wp_safe_redirect( home_url( '/' ) );
                exit;
            }

            if ( current_user_can( 'edit_shop_orders' ) || current_user_can( 'manage_woocommerce' ) ) {
                wp_safe_redirect( $this->get_admin_order_edit_url( $order_id ) );
                exit;
            }

            if ( (int) $order->get_customer_id() === (int) Better_Messages()->functions->get_current_user_id() ) {
                wp_safe_redirect( $order->get_view_order_url() );
                exit;
            }

            wp_safe_redirect( home_url( '/' ) );
            exit;
        }

        public function inject_pre_purchase_into_block( $block_content, $block ){
            if ( $this->pre_purchase_button_block_rendered ) {
                return $block_content;
            }

            $block_name = isset( $block['blockName'] ) ? $block['blockName'] : '';
            if ( $block_name === '' ) {
                return $block_content;
            }

            if ( function_exists( 'is_cart' ) && is_cart() ) {
                $placement = Better_Messages()->settings['wooCommercePrePurchaseCartPlacement'];
                $target    = $this->get_cart_block_target( $placement );
                if ( $target && in_array( $block_name, $target['blocks'], true ) ) {
                    $this->pre_purchase_button_block_rendered = true;
                    $button_html = $this->wrap_auto_placed_button( $this->pre_purchase_button_shortcode( array() ) );
                    return $target['position'] === 'before'
                        ? $button_html . $block_content
                        : $block_content . $button_html;
                }
            }

            if ( function_exists( 'is_checkout' ) && is_checkout() && ! ( function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url( 'order-received' ) ) ) {
                $placement = Better_Messages()->settings['wooCommercePrePurchaseCheckoutPlacement'];
                $target    = $this->get_checkout_block_target( $placement );
                if ( $target && in_array( $block_name, $target['blocks'], true ) ) {
                    $this->pre_purchase_button_block_rendered = true;
                    $button_html = $this->wrap_auto_placed_button( $this->pre_purchase_button_shortcode( array() ) );
                    return $target['position'] === 'before'
                        ? $button_html . $block_content
                        : $block_content . $button_html;
                }
            }

            return $block_content;
        }

        protected function get_cart_block_target( $placement ){
            switch ( $placement ) {
                case 'before_cart':
                    return array( 'blocks' => array( 'woocommerce/cart' ), 'position' => 'before' );
                case 'after_cart_table':
                    return array( 'blocks' => array( 'woocommerce/cart-line-items-block', 'woocommerce/cart-items-block' ), 'position' => 'after' );
                case 'cart_collaterals':
                    return array( 'blocks' => array( 'woocommerce/cart-order-summary-block', 'woocommerce/cart-totals-block' ), 'position' => 'before' );
                case 'proceed_to_checkout':
                    return array( 'blocks' => array( 'woocommerce/proceed-to-checkout-block' ), 'position' => 'before' );
                case 'after_cart':
                    return array( 'blocks' => array( 'woocommerce/cart' ), 'position' => 'after' );
            }
            return null;
        }

        protected function get_checkout_block_target( $placement ){
            switch ( $placement ) {
                case 'before_form':
                    return array( 'blocks' => array( 'woocommerce/checkout' ), 'position' => 'before' );
                case 'before_order_summary':
                    return array( 'blocks' => array( 'woocommerce/checkout-order-summary-block' ), 'position' => 'before' );
                case 'after_order_summary':
                    return array( 'blocks' => array( 'woocommerce/checkout-order-summary-block' ), 'position' => 'after' );
                case 'after_form':
                    return array( 'blocks' => array( 'woocommerce/checkout' ), 'position' => 'after' );
            }
            return null;
        }

        public function inject_product_button_into_block( $block_content, $block ){
            if ( $this->product_button_block_rendered ) {
                return $block_content;
            }
            if ( ! function_exists( 'is_product' ) || ! is_product() ) {
                return $block_content;
            }

            $block_name = isset( $block['blockName'] ) ? $block['blockName'] : '';
            if ( $block_name === '' ) {
                return $block_content;
            }

            $placement = Better_Messages()->settings['wooCommerceProductButtonPlacement'];

            $summary_targets = array( 'core/post-excerpt', 'woocommerce/product-summary' );

            if ( ! in_array( $block_name, $summary_targets, true ) ) {
                return $block_content;
            }

            if ( $placement === 'before_summary' ) {
                $this->product_button_block_rendered = true;
                return $this->wrap_auto_placed_button( $this->product_button_shortcode( array() ) ) . $block_content;
            }

            if ( $placement === 'after_summary' ) {
                $this->product_button_block_rendered = true;
                return $block_content . $this->wrap_auto_placed_button( $this->product_button_shortcode( array() ) );
            }

            return $block_content;
        }

        protected function build_thread_participants( $customer_id, $support_user_id ){
            $customer_id     = (int) $customer_id;
            $support_user_id = (int) $support_user_id;

            if ( $support_user_id <= 0 || $support_user_id === $customer_id ) {
                return false;
            }

            return array( $customer_id, $support_user_id );
        }

        public function unique_conversation_id( $conversation_id, $key, $user_id ){
            if ( $conversation_id ) {
                return $conversation_id;
            }

            if ( empty( $key ) || ! is_string( $key ) ) {
                return $conversation_id;
            }

            $user_id = (int) $user_id;
            if ( $user_id <= 0 ) {
                return $conversation_id;
            }

            if ( ! function_exists( 'wc_get_product' ) ) {
                return $conversation_id;
            }

            if ( strpos( $key, 'wc_product_' ) === 0 ) {
                $product_id = (int) substr( $key, strlen( 'wc_product_' ) );
                if ( $product_id <= 0 ) {
                    return $conversation_id;
                }

                $product = wc_get_product( $product_id );
                if ( ! $product ) {
                    return $conversation_id;
                }

                $participants = $this->build_thread_participants( $user_id, $this->get_product_support_user( $product_id ) );
                if ( ! $participants ) {
                    return $conversation_id;
                }

                $subject = sprintf(
                    _x( 'Question about %s', 'WooCommerce Integration', 'bp-better-messages' ),
                    $product->get_title()
                );

                return Better_Messages()->functions->get_unique_conversation_id( $participants, $key, $subject );
            }

            if ( strpos( $key, 'wc_order_' ) === 0 ) {
                $order_id = (int) substr( $key, strlen( 'wc_order_' ) );
                if ( $order_id <= 0 ) {
                    return $conversation_id;
                }

                $order = wc_get_order( $order_id );
                if ( ! $order ) {
                    return $conversation_id;
                }

                if ( (int) $order->get_customer_id() !== $user_id ) {
                    return $conversation_id;
                }

                $participants = $this->build_thread_participants( $user_id, $this->get_order_support_user() );
                if ( ! $participants ) {
                    return $conversation_id;
                }

                $subject = sprintf(
                    _x( 'Question about order #%s', 'WooCommerce Integration', 'bp-better-messages' ),
                    $order->get_order_number()
                );

                return Better_Messages()->functions->get_unique_conversation_id( $participants, $key, $subject );
            }

            if ( $key === 'wc_pre_purchase' ) {
                $participants = $this->build_thread_participants( $user_id, $this->get_pre_purchase_support_user() );
                if ( ! $participants ) {
                    return $conversation_id;
                }

                $subject = _x( 'Need help with my purchase', 'WooCommerce Integration', 'bp-better-messages' );

                return Better_Messages()->functions->get_unique_conversation_id( $participants, $key, $subject );
            }

            return $conversation_id;
        }

        public function thread_item( $thread_item, $thread_id, $thread_type, $include_personal, $user_id ){
            if ( $thread_type !== 'thread' ) {
                return $thread_item;
            }

            $unique_tag = Better_Messages()->functions->get_thread_meta( $thread_id, 'unique_tag' );
            if ( empty( $unique_tag ) || ! is_string( $unique_tag ) ) {
                return $thread_item;
            }

            $key = strtok( $unique_tag, '|' );
            if ( ! $key ) {
                return $thread_item;
            }

            $info_html = '';

            if ( strpos( $key, 'wc_product_' ) === 0 ) {
                $product_id = (int) substr( $key, strlen( 'wc_product_' ) );
                $info_html  = $this->render_product_info( $product_id );
            } elseif ( strpos( $key, 'wc_order_' ) === 0 ) {
                $order_id  = (int) substr( $key, strlen( 'wc_order_' ) );
                $info_html = $this->render_order_info( $order_id );
            }

            if ( $info_html !== '' ) {
                $existing = isset( $thread_item['threadInfo'] ) ? $thread_item['threadInfo'] : '';
                $thread_item['threadInfo'] = $existing . $info_html;
            }

            return $thread_item;
        }

        public function extend_allowed_message_tags( $tags ){
            if ( ! is_array( $tags ) ) {
                return $tags;
            }

            if ( ! isset( $tags['div'] ) || ! is_array( $tags['div'] ) ) {
                $tags['div'] = array();
            }
            $tags['div']['class'] = array();

            if ( ! isset( $tags['a'] ) || ! is_array( $tags['a'] ) ) {
                $tags['a'] = array();
            }
            $tags['a']['target'] = array();
            $tags['a']['rel']    = array();
            $tags['a']['href']   = array();

            foreach ( array( 'ul', 'ol', 'li' ) as $list_tag ) {
                if ( ! isset( $tags[ $list_tag ] ) || ! is_array( $tags[ $list_tag ] ) ) {
                    $tags[ $list_tag ] = array();
                }
                $tags[ $list_tag ]['class'] = array();
            }

            foreach ( array( 'ins', 'del', 'mark' ) as $price_tag ) {
                if ( ! isset( $tags[ $price_tag ] ) ) {
                    $tags[ $price_tag ] = array( 'class' => array() );
                }
            }

            return $tags;
        }

        public function format_product_links( $message, $message_id, $context, $user_id ){
            if ( $context !== 'stack' ) {
                return $message;
            }
            if ( ! function_exists( 'wc_get_product' ) || ! function_exists( 'url_to_postid' ) ) {
                return $message;
            }
            if ( ! is_string( $message ) || $message === '' || strpos( $message, 'href' ) === false ) {
                return $message;
            }

            $home_host = wp_parse_url( home_url(), PHP_URL_HOST );
            if ( ! $home_host ) {
                return $message;
            }

            return preg_replace_callback(
                '#<a\s+([^>]*?)href=["\'](https?://[^"\']+)["\']([^>]*)>(.*?)</a>#is',
                function( $matches ) use ( $home_host ) {
                    $attrs_before = $matches[1];
                    $url          = $matches[2];
                    $attrs_after  = $matches[3];
                    $all_attrs    = $attrs_before . $attrs_after;

                    // Skip links that are part of our own cart snapshot or product cards
                    if ( strpos( $all_attrs, 'bm-wc-cart-link' ) !== false ) {
                        return $matches[0];
                    }
                    if ( strpos( $all_attrs, 'bm-wc-product-link' ) !== false ) {
                        return $matches[0];
                    }

                    $url_host = wp_parse_url( $url, PHP_URL_HOST );
                    if ( $url_host !== $home_host ) {
                        return $matches[0];
                    }

                    $post_id = url_to_postid( $url );
                    if ( ! $post_id || get_post_type( $post_id ) !== 'product' ) {
                        return $matches[0];
                    }

                    $product = wc_get_product( $post_id );
                    if ( ! $product ) {
                        return $matches[0];
                    }

                    return $this->render_product_link_card( $product );
                },
                $message
            );
        }

        protected function render_product_link_card( $product ){
            $image_id  = $product->get_image_id();
            $image_src = $image_id ? wp_get_attachment_image_src( $image_id, array( 100, 100 ) ) : false;
            $image     = $image_src ? $image_src[0] : false;
            $title     = $product->get_title();
            $url       = $product->get_permalink();
            $price     = $product->get_price_html();

            $html  = '<a href="' . esc_url( $url ) . '" class="bm-wc-product-link" target="_blank" rel="noopener noreferrer">';

            if ( $image ) {
                $html .= '<span class="bm-wc-product-link-image"><img src="' . esc_url( $image ) . '" alt="' . esc_attr( $title ) . '" /></span>';
            }

            $html .= '<span class="bm-wc-product-link-details">';
            $html .= '<span class="bm-wc-product-link-title">' . esc_html( $title ) . '</span>';
            if ( $price !== '' ) {
                $html .= '<span class="bm-wc-product-link-price">' . wp_kses_post( $price ) . '</span>';
            }
            $html .= '</span>';
            $html .= '</a>';

            return $html;
        }

        public function render_product_info( $product_id ){
            if ( ! function_exists( 'wc_get_product' ) ) {
                return '';
            }

            $product = wc_get_product( $product_id );
            if ( ! $product ) {
                return '';
            }

            $image_id  = $product->get_image_id();
            $image_src = $image_id ? wp_get_attachment_image_src( $image_id, array( 100, 100 ) ) : false;
            $image     = $image_src ? $image_src[0] : false;
            $title     = $product->get_title();
            $url       = $product->get_permalink();
            $price     = $product->get_price_html();

            $html  = '<div class="bm-product-info">';
            if ( $image ) {
                $html .= '<div class="bm-product-image">';
                $html .= '<a href="' . esc_url( $url ) . '" target="_blank" rel="noopener noreferrer"><img src="' . esc_url( $image ) . '" alt="' . esc_attr( $title ) . '" /></a>';
                $html .= '</div>';
            }
            $html .= '<div class="bm-product-details">';
            $html .= '<div class="bm-product-title"><a href="' . esc_url( $url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $title ) . '</a></div>';
            if ( $price !== '' ) {
                $html .= '<div class="bm-product-price">' . wp_kses_post( $price ) . '</div>';
            }
            $html .= '</div>';
            $html .= '</div>';

            return $html;
        }

        public function render_order_info( $order_id ){
            if ( ! function_exists( 'wc_get_order' ) ) {
                return '';
            }

            $order = wc_get_order( $order_id );
            if ( ! $order ) {
                return '';
            }

            $number    = $order->get_order_number();
            $status    = wc_get_order_status_name( $order->get_status() );
            $total     = $order->get_formatted_order_total();
            $item_count = $order->get_item_count();
            $url       = $this->get_universal_view_order_url( $order_id );

            $html  = '<div class="bm-order-info">';
            $html .= '<div class="bm-order-info-header">';
            $html .= '<span class="bm-order-info-number">' . sprintf(
                esc_html_x( 'Order #%s', 'WooCommerce Integration', 'bp-better-messages' ),
                esc_html( $number )
            ) . '</span>';
            $html .= '<span class="bm-order-info-status bm-order-status-' . esc_attr( sanitize_html_class( $order->get_status() ) ) . '">' . esc_html( $status ) . '</span>';
            $html .= '</div>';

            $html .= '<div class="bm-order-info-meta">';
            $html .= '<span class="bm-order-info-items">' . esc_html( sprintf(
                _nx( '%d item', '%d items', $item_count, 'WooCommerce Integration', 'bp-better-messages' ),
                $item_count
            ) ) . '</span>';
            $html .= '<span class="bm-order-info-total">' . wp_kses_post( $total ) . '</span>';
            $html .= '</div>';

            if ( $url ) {
                $html .= '<a class="bm-order-info-link" href="' . esc_url( $url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html_x( 'View order', 'WooCommerce Integration', 'bp-better-messages' ) . '</a>';
            }

            $html .= '</div>';

            return $html;
        }

        public function product_button(){
            echo $this->wrap_auto_placed_button( $this->product_button_shortcode( array() ) );
        }

        public function order_button( $order ){
            if ( ! is_a( $order, 'WC_Order' ) ) {
                return;
            }
            echo $this->wrap_auto_placed_button( $this->order_button_shortcode( array( 'order_id' => $order->get_id() ) ) );
        }

        public function pre_purchase_button(){
            echo $this->wrap_auto_placed_button( $this->pre_purchase_button_shortcode( array() ) );
        }

        protected function wrap_auto_placed_button( $button_html ){
            if ( $button_html === '' ) {
                return '';
            }
            return '<div class="bm-wc-button-wrap">' . $button_html . '</div>';
        }

        /* ---------------------------------------------------------------- *
         * Shortcodes
         * ---------------------------------------------------------------- */

        public function product_button_shortcode( $atts ){
            if ( Better_Messages()->settings['wooCommerceIntegration'] !== '1' ) {
                return '';
            }

            if ( ! function_exists( 'wc_get_product' ) ) {
                return '';
            }

            $atts = shortcode_atts( array(
                'product_id' => 0,
                'text'       => '',
                'class'      => '',
            ), $atts, 'better_messages_woocommerce_product_button' );

            $product_id = (int) $atts['product_id'];
            if ( $product_id <= 0 ) {
                global $post;
                if ( $post && $post->post_type === 'product' ) {
                    $product_id = (int) $post->ID;
                }
            }

            if ( $product_id <= 0 ) {
                return '';
            }

            $product = wc_get_product( $product_id );
            if ( ! $product ) {
                return '';
            }

            $support_user = $this->get_product_support_user( $product_id );
            if ( ! $support_user ) {
                return '';
            }

            $text  = $atts['text'] !== '' ? $atts['text'] : _x( 'Contact us about this product', 'WooCommerce Integration', 'bp-better-messages' );
            $class = trim( 'bm-style-btn bm-wc-product-button ' . $atts['class'] );

            $subject = sprintf(
                _x( 'Question about %s', 'WooCommerce Integration', 'bp-better-messages' ),
                $product->get_title()
            );

            return do_shortcode( '[better_messages_live_chat_button'
                . ' type="button"'
                . ' class="' . esc_attr( $class ) . '"'
                . ' text="' . Better_Messages()->shortcodes->esc_brackets( esc_attr( $text ) ) . '"'
                . ' user_id="' . (int) $support_user . '"'
                . ' subject="' . Better_Messages()->shortcodes->esc_brackets( esc_attr( $subject ) ) . '"'
                . ' unique_tag="wc_product_' . (int) $product_id . '"'
                . ']' );
        }

        public function order_button_shortcode( $atts ){
            if ( Better_Messages()->settings['wooCommerceIntegration'] !== '1' ) {
                return '';
            }

            if ( ! function_exists( 'wc_get_order' ) ) {
                return '';
            }

            $support_user = $this->get_order_support_user();
            if ( ! $support_user ) {
                return '';
            }

            $atts = shortcode_atts( array(
                'order_id' => 0,
                'text'     => '',
                'class'    => '',
            ), $atts, 'better_messages_woocommerce_order_button' );

            $order_id = (int) $atts['order_id'];
            if ( $order_id <= 0 ) {
                $view_order = get_query_var( 'view-order' );
                if ( $view_order ) {
                    $order_id = (int) $view_order;
                }
            }

            if ( $order_id <= 0 ) {
                return '';
            }

            $order = wc_get_order( $order_id );
            if ( ! $order ) {
                return '';
            }

            $current_user_id = (int) Better_Messages()->functions->get_current_user_id();
            if ( $current_user_id === 0 || (int) $order->get_customer_id() !== $current_user_id ) {
                return '';
            }

            $text  = $atts['text'] !== '' ? $atts['text'] : _x( 'Contact us about this order', 'WooCommerce Integration', 'bp-better-messages' );
            $class = trim( 'bm-style-btn bm-wc-order-button ' . $atts['class'] );

            $subject = sprintf(
                _x( 'Question about order #%s', 'WooCommerce Integration', 'bp-better-messages' ),
                $order->get_order_number()
            );

            return do_shortcode( '[better_messages_live_chat_button'
                . ' type="button"'
                . ' class="' . esc_attr( $class ) . '"'
                . ' text="' . Better_Messages()->shortcodes->esc_brackets( esc_attr( $text ) ) . '"'
                . ' user_id="' . (int) $support_user . '"'
                . ' subject="' . Better_Messages()->shortcodes->esc_brackets( esc_attr( $subject ) ) . '"'
                . ' unique_tag="wc_order_' . (int) $order_id . '"'
                . ']' );
        }

        public function pre_purchase_button_shortcode( $atts ){
            if ( Better_Messages()->settings['wooCommerceIntegration'] !== '1' ) {
                return '';
            }

            if ( Better_Messages()->settings['wooCommercePrePurchaseButton'] !== '1' ) {
                return '';
            }

            $support_user = $this->get_pre_purchase_support_user();
            if ( ! $support_user ) {
                return '';
            }

            $atts = shortcode_atts( array(
                'text'  => '',
                'class' => '',
            ), $atts, 'better_messages_woocommerce_pre_purchase_button' );

            $text  = $atts['text'] !== '' ? $atts['text'] : _x( 'Need help? Chat with us', 'WooCommerce Integration', 'bp-better-messages' );
            $class = trim( 'bm-style-btn bm-wc-pre-purchase-button ' . $atts['class'] );

            $subject = _x( 'Need help with my purchase', 'WooCommerce Integration', 'bp-better-messages' );

            return do_shortcode( '[better_messages_live_chat_button'
                . ' type="button"'
                . ' class="' . esc_attr( $class ) . '"'
                . ' text="' . Better_Messages()->shortcodes->esc_brackets( esc_attr( $text ) ) . '"'
                . ' user_id="' . (int) $support_user . '"'
                . ' subject="' . Better_Messages()->shortcodes->esc_brackets( esc_attr( $subject ) ) . '"'
                . ' unique_tag="wc_pre_purchase"'
                . ']' );
        }

        public function add_my_account_link( $items ){
            if ( ! is_array( $items ) ) {
                return $items;
            }

            $label = _x( 'Messages', 'WooCommerce Integration', 'bp-better-messages' );

            $new = array();
            $inserted = false;
            foreach ( $items as $key => $val ) {
                if ( $key === 'customer-logout' && ! $inserted ) {
                    $new['better-messages'] = $label;
                    $inserted = true;
                }
                $new[ $key ] = $val;
            }

            if ( ! $inserted ) {
                $new['better-messages'] = $label;
            }

            return $new;
        }

        public function filter_my_account_link_url( $url, $endpoint, $value, $permalink ){
            if ( $endpoint !== 'better-messages' ) {
                return $url;
            }

            $messages_url = Better_Messages()->functions->get_link( Better_Messages()->functions->get_current_user_id() );

            return $messages_url ? $messages_url : $url;
        }

        public function register_product_metabox(){
            add_meta_box(
                'bm-wc-product-support-user',
                _x( 'Better Messages Contact', 'WooCommerce Integration', 'bp-better-messages' ),
                array( $this, 'render_product_metabox' ),
                'product',
                'side',
                'default'
            );
        }

        public function render_product_metabox( $post ){
            wp_nonce_field( 'bm_wc_product_support_user', 'bm_wc_product_support_user_nonce' );

            $current = (int) get_post_meta( $post->ID, self::PRODUCT_META_KEY, true );
            $default = (int) Better_Messages()->settings['wooCommerceProductSupportUser'];

            $initial_json = 'null';
            if ( $current > 0 && Better_Messages()->functions->is_user_exists( $current ) ) {
                $user_item = Better_Messages()->functions->rest_user_item( $current );
                $initial_json = wp_json_encode( array(
                    'value'  => (int) $user_item['user_id'],
                    'label'  => $user_item['name'],
                    'avatar' => $user_item['avatar'],
                ) );
            }

            $default_name = '';
            if ( $default > 0 ) {
                $default_user = get_userdata( $default );
                if ( $default_user ) {
                    $default_name = $default_user->display_name;
                }
            }

            echo '<p class="description">';
            echo esc_html_x( 'Override the global product contact user for this product only', 'WooCommerce Integration', 'bp-better-messages' );
            echo '</p>';

            printf(
                '<div class="bm-wc-product-support-picker" data-field="%s" data-initial="%s" data-default-name="%s"></div>',
                esc_attr( 'bm_wc_product_support_user' ),
                esc_attr( $initial_json ),
                esc_attr( $default_name )
            );
        }

        public function register_rest_routes(){
            register_rest_route( 'better-messages/v1', '/wc/cart-snapshot', array(
                'methods'             => 'POST',
                'callback'            => array( $this, 'rest_post_cart_snapshot' ),
                'permission_callback' => function(){
                    return is_user_logged_in() || ( isset( Better_Messages()->guests ) && Better_Messages()->guests->guest_access_enabled() );
                },
                'args'                => array(
                    'thread_id' => array(
                        'required' => true,
                        'validate_callback' => function( $param ){
                            return is_numeric( $param ) && (int) $param > 0;
                        },
                    ),
                ),
            ) );
        }

        public function rest_post_cart_snapshot( $request ){
            $thread_id       = (int) $request->get_param( 'thread_id' );
            $current_user_id = (int) Better_Messages()->functions->get_current_user_id();

            if ( $current_user_id === 0 ) {
                return new WP_Error( 'forbidden', 'Not authenticated', array( 'status' => 403 ) );
            }

            if ( ! Better_Messages()->functions->check_access( $thread_id, $current_user_id ) ) {
                return new WP_Error( 'forbidden', 'No access to thread', array( 'status' => 403 ) );
            }

            $unique_tag = Better_Messages()->functions->get_thread_meta( $thread_id, 'unique_tag' );
            if ( ! is_string( $unique_tag ) || strpos( $unique_tag, 'wc_pre_purchase' ) !== 0 ) {
                return new WP_Error( 'wrong_thread', 'Not a pre-purchase thread', array( 'status' => 400 ) );
            }

            if ( ! function_exists( 'WC' ) ) {
                return new WP_REST_Response( array( 'posted' => false, 'reason' => 'no_wc' ) );
            }

            // WC does not initialize the cart in REST API context. Force-load it
            // so WC()->cart contains the customer's actual session cart.
            if ( ( ! WC()->cart || ! WC()->session ) && function_exists( 'wc_load_cart' ) ) {
                wc_load_cart();
            }

            if ( ! WC()->cart ) {
                return new WP_REST_Response( array( 'posted' => false, 'reason' => 'no_cart' ) );
            }

            $cart = WC()->cart;
            if ( $cart->is_empty() ) {
                return new WP_REST_Response( array( 'posted' => false, 'reason' => 'empty_cart' ) );
            }

            $hash      = $this->build_cart_snapshot_hash( $cart );
            $last_hash = Better_Messages()->functions->get_thread_meta( $thread_id, 'wc_cart_snapshot_hash' );

            if ( $last_hash === $hash ) {
                return new WP_REST_Response( array( 'posted' => false, 'reason' => 'unchanged' ) );
            }

            $message_html = $this->build_cart_snapshot_html( $cart );
            if ( $message_html === '' ) {
                return new WP_REST_Response( array( 'posted' => false, 'reason' => 'empty_message' ) );
            }

            $result = Better_Messages()->functions->new_message( array(
                'sender_id' => $current_user_id,
                'thread_id' => $thread_id,
                'content'   => $message_html,
            ) );

            if ( ! $result ) {
                return new WP_Error( 'send_failed', 'Failed to send snapshot', array( 'status' => 500 ) );
            }

            Better_Messages()->functions->update_thread_meta( $thread_id, 'wc_cart_snapshot_hash', $hash );

            return new WP_REST_Response( array( 'posted' => true ) );
        }

        protected function build_cart_snapshot_hash( $cart ){
            $items = array();
            foreach ( $cart->get_cart() as $item ) {
                $variant_or_product = ! empty( $item['variation_id'] ) ? (int) $item['variation_id'] : (int) $item['product_id'];
                $items[] = $variant_or_product . ':' . (int) $item['quantity'];
            }
            sort( $items );
            return md5( implode( '|', $items ) . '|' . (string) $cart->get_total( 'edit' ) );
        }

        protected function build_cart_snapshot_html( $cart ){
            $html  = '<div class="bm-wc-cart-snapshot">';
            $html .= '<div class="bm-wc-cart-snapshot-header">';
            $html .= '<span class="bm-wc-cart-snapshot-icon" aria-hidden="true">🛒</span>';
            $html .= '<span class="bm-wc-cart-snapshot-title">' . esc_html_x( 'My current cart', 'WooCommerce Integration', 'bp-better-messages' ) . '</span>';
            $html .= '</div>';
            $html .= '<ul class="bm-wc-cart-snapshot-items">';

            foreach ( $cart->get_cart() as $item ) {
                $product = isset( $item['data'] ) ? $item['data'] : null;
                if ( ! $product ) {
                    continue;
                }
                $name       = $product->get_name();
                $qty        = (int) $item['quantity'];
                $line_total = wp_strip_all_tags( wc_price( $item['line_total'] + $item['line_tax'] ) );
                $url        = $product->get_permalink();

                $image_id   = $product->get_image_id();
                $image_src  = $image_id ? wp_get_attachment_image_src( $image_id, array( 80, 80 ) ) : false;
                $image      = $image_src ? $image_src[0] : '';

                $html .= '<li class="bm-wc-cart-snapshot-item">';
                $html .= '<a href="' . esc_url( $url ) . '" class="bm-wc-cart-link" target="_blank" rel="noopener noreferrer">';

                if ( $image ) {
                    $html .= '<span class="bm-wc-cart-snapshot-item-thumb"><img src="' . esc_url( $image ) . '" alt="' . esc_attr( $name ) . '" /></span>';
                } else {
                    $html .= '<span class="bm-wc-cart-snapshot-item-thumb bm-wc-cart-snapshot-item-thumb-empty"></span>';
                }

                $html .= '<span class="bm-wc-cart-snapshot-item-info">';
                $html .= '<span class="bm-wc-cart-snapshot-item-name">' . esc_html( $name ) . '</span>';
                $html .= '<span class="bm-wc-cart-snapshot-item-meta">×&nbsp;' . $qty . ' · ' . esc_html( $line_total ) . '</span>';
                $html .= '</span>';

                $html .= '</a>';
                $html .= '</li>';
            }

            $html .= '</ul>';

            $cart_total = wp_strip_all_tags( wc_price( $cart->get_total( 'edit' ) ) );
            $html .= '<div class="bm-wc-cart-snapshot-total">';
            $html .= '<span>' . esc_html_x( 'Total', 'WooCommerce Integration', 'bp-better-messages' ) . '</span>';
            $html .= '<strong>' . esc_html( $cart_total ) . '</strong>';
            $html .= '</div>';
            $html .= '</div>';

            return $html;
        }

        public function enqueue_cart_snapshot_script(){
            if ( ! function_exists( 'is_cart' ) ) {
                return;
            }
            $is_pre_purchase_page = is_cart() || ( is_checkout() && ! ( function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url( 'order-received' ) ) );
            if ( ! $is_pre_purchase_page ) {
                return;
            }

            // Ensure BM frontend JS + CSS are loaded so the button click handler works.
            Better_Messages()->load_scripts();

            if ( ! is_user_logged_in() && ! ( isset( Better_Messages()->guests ) && Better_Messages()->guests->guest_access_enabled() ) ) {
                return;
            }

            $script = "(function(){
                if ( ! window.wp || ! window.wp.hooks ) return;
                wp.hooks.addAction(
                    'better_messages_conversation_with_user_opened',
                    'bm-wc/post-cart-snapshot',
                    function(element, threadId, data, uniqueKey){
                        if (uniqueKey !== 'wc_pre_purchase') return;
                        if (!threadId) return;
                        if ( ! window.BetterMessages || typeof window.BetterMessages.getApi !== 'function' ) return;
                        window.BetterMessages.getApi().then(function(api){
                            return api.post('wc/cart-snapshot', { thread_id: threadId });
                        }).catch(function(){});
                    }
                );
            })();";

            wp_add_inline_script( 'better-messages', $script );
        }

        public function save_product_metabox( $post_id, $post ){
            if ( ! isset( $_POST['bm_wc_product_support_user_nonce'] ) ) {
                return;
            }
            if ( ! wp_verify_nonce( $_POST['bm_wc_product_support_user_nonce'], 'bm_wc_product_support_user' ) ) {
                return;
            }
            if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
                return;
            }
            if ( ! current_user_can( 'edit_post', $post_id ) ) {
                return;
            }

            $value = isset( $_POST['bm_wc_product_support_user'] ) ? (int) $_POST['bm_wc_product_support_user'] : 0;

            if ( $value > 0 ) {
                update_post_meta( $post_id, self::PRODUCT_META_KEY, $value );
            } else {
                delete_post_meta( $post_id, self::PRODUCT_META_KEY );
            }
        }
    }
}
