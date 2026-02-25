<?php
defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Better_Messages_WC_Vendors' ) ) {

    class Better_Messages_WC_Vendors
    {

        public static function instance()
        {

            static $instance = null;

            if (null === $instance) {
                $instance = new Better_Messages_WC_Vendors();
            }

            return $instance;
        }

        public function __construct(){
            /**
             * WC Vendors loads WCV_Vendors class on setup_theme hook,
             * which fires after plugins_loaded. Defer hook registration.
             */
            add_action( 'after_setup_theme', array( $this, 'register_hooks' ), 21 );
        }

        public function register_hooks(){
            if( ! class_exists('WCV_Vendors') ) return;

            add_shortcode( 'better_messages_wc_vendors_product_button', array( $this, 'product_page_contact_button_shortcode' ) );
            add_shortcode( 'better_messages_wc_vendors_store_button', array( $this, 'store_page_contact_button_shortcode' ) );

            if( Better_Messages()->settings['wcVendorsIntegration'] !== '1' ) return;

            add_action( 'woocommerce_single_product_summary', array( $this, 'product_page_contact_button' ), 35 );

            add_action( 'wcvendors_settings_after_shop_description', array( $this, 'store_settings_output' ), 100 );
            add_action( 'wcvendors_store_settings_saved', array( $this, 'store_settings_save' ), 10 );

            add_action( 'wcvendors_after_main_header', array( $this, 'display_store_button' ), 10, 1 );

            add_filter( 'better_messages_rest_thread_item', array( $this, 'thread_item' ), 10, 5 );
            add_filter( 'better_messages_rest_user_item', array( $this, 'vendor_user_meta' ), 20, 3 );
            add_filter( 'bp_better_messages_page', array( $this, 'vendor_messages_page' ), 20, 2 );

            add_filter( 'wcv_dashboard_urls', array( $this, 'add_messages_page' ), 9 );
            add_filter( 'wcv_dashboard_pages_nav', array( $this, 'maybe_hide_messages_nav' ) );
            add_filter( 'wcvendors_dashboard_custom_page', array( $this, 'messages_page' ), 10, 4 );

            add_action( 'wp_enqueue_scripts', array( $this, 'inbox_counter_javascript' ) );
        }

        public function vendor_messages_page( $url, $user_id ){
            if( ! class_exists('WCV_Vendors') ) return $url;
            if( ! WCV_Vendors::is_vendor( $user_id ) ) return $url;

            $livechat_enabled = $this->is_livechat_enabled( $user_id );
            if( $livechat_enabled ){
                $pro_dashboard_pages = (array) get_option( 'wcvendors_vendor_dashboard_page_id', array() );
                if( ! empty( $pro_dashboard_pages ) ) {
                    $dashboard_page_id = $pro_dashboard_pages[0];
                    $permalink = get_permalink( $dashboard_page_id );
                    $url = trailingslashit( $permalink ) . 'messages/';
                }
            }

            return $url;
        }

        public function messages_page( $object, $object_id, $template, $custom ){
            if ( 'messages' === $object ){
                echo do_shortcode( '[better_messages]' );
            }
        }

        public function add_messages_page( $pages ){
            $pages['messages'] = array(
                'slug'    => 'messages',
                'id'      => 'messages',
                'label'   => _x( 'Messages', 'Marketplace Integrations', 'bp-better-messages' ),
                'actions' => array(),
            );

            return $pages;
        }

        public function maybe_hide_messages_nav( $pages ){
            $vendor_id = get_current_user_id();
            if( ! $this->is_livechat_enabled( $vendor_id ) ){
                unset( $pages['messages'] );
            }

            return $pages;
        }

        public function inbox_counter_javascript(){
            if( ! is_user_logged_in() ) return;
            if( ! class_exists('WCV_Vendors') ) return;
            if( ! WCV_Vendors::is_vendor( get_current_user_id() ) ) return;
            if( ! $this->is_livechat_enabled( get_current_user_id() ) ) return;

            $js = 'wp.hooks.addAction("better_messages_update_unread", "better_messages", function( unread ){
                var element = document.querySelector(".wcvendors-pro-dashboard-wrapper .wcv-navigation #dashboard-menu-item-messages a .bp-better-messages-unread");
                if( ! element ){
                    var parent = document.querySelector(".wcvendors-pro-dashboard-wrapper .wcv-navigation #dashboard-menu-item-messages a");
                    if( parent ){
                        element = document.createElement("span");
                        element.className = "bp-better-messages-unread bpbmuc bpbmuc-hide-when-null";
                        parent.appendChild(element);
                    }
                }
                if( element ){
                    element.dataset.count = unread;
                    element.textContent = unread;
                }
            });';

            wp_add_inline_script( 'better-messages', Better_Messages()->functions->minify_js( $js ), 'before' );

            $css = '.wcvendors-pro-dashboard-wrapper .wcv-navigation #dashboard-menu-item-messages a .bp-better-messages-unread{ margin-left: 10px; }';

            wp_add_inline_style( 'better-messages', Better_Messages()->functions->minify_css( $css ) );
        }

        public function display_store_button( $vendor_id ){
            if( ! $this->is_livechat_enabled( $vendor_id ) ) return;

            echo do_shortcode('[better_messages_live_chat_button
            type="button"
            class="bm-style-btn"
            text="' . Better_Messages()->shortcodes->esc_brackets( esc_attr_x( 'Live Chat', 'WC Vendors Integration (Store page)', 'bp-better-messages' ) ) . '"
            user_id="' . $vendor_id . '"
            unique_tag="wc_vendors_store_chat_' . $vendor_id . '"
            ]');
        }

        public function store_page_contact_button_shortcode(){
            if( ! class_exists('WCV_Vendors') ) return '';
            $vendor_id = WCV_Vendors::get_vendor_from_shop();
            if( ! $vendor_id ) return '';

            return $this->_render_store_button( $vendor_id );
        }

        private function _render_store_button( $vendor_id ){
            if( ! $this->is_livechat_enabled( $vendor_id ) ) return '';

            return do_shortcode('[better_messages_live_chat_button
            type="button"
            class="bm-style-btn"
            text="' . Better_Messages()->shortcodes->esc_brackets( esc_attr_x( 'Live Chat', 'WC Vendors Integration (Store page)', 'bp-better-messages' ) ) . '"
            user_id="' . $vendor_id . '"
            unique_tag="wc_vendors_store_chat_' . $vendor_id . '"
            ]');
        }

        public function product_page_contact_button_shortcode(){
            return $this->product_page_contact_button( true );
        }

        public function product_page_contact_button( $return = false ){
            global $post;

            if( is_product() && $post && is_object( $post ) ) {
                $seller_id = (int) $post->post_author;

                if( ! class_exists('WCV_Vendors') ) return $return ? '' : null;
                if( ! WCV_Vendors::is_vendor( $seller_id ) ) return $return ? '' : null;

                $livechat_enabled = $this->is_livechat_enabled( $seller_id );
                if( $livechat_enabled ){
                    $product = wc_get_product( get_the_ID() );

                    $subject = esc_attr( sprintf( _x( 'Question about your product %s', 'WC Vendors Integration (Product page)', 'bp-better-messages' ), $product->get_title() ) );

                    $shortcode = do_shortcode('[better_messages_live_chat_button
                    type="button"
                    class="bm-style-btn"
                    text="' . Better_Messages()->shortcodes->esc_brackets( esc_attr_x( 'Live Chat', 'WC Vendors Integration (Product page)', 'bp-better-messages' ) ) . '"
                    user_id="' . $seller_id . '"
                    subject="' . Better_Messages()->shortcodes->esc_brackets( $subject ) . '"
                    unique_tag="wc_vendors_product_chat_' . get_the_ID() . '"
                    ]');

                    if( $return ){
                        return $shortcode;
                    } else {
                        echo $shortcode;
                    }
                }
            }

            return $return ? '' : null;
        }

        public function is_livechat_enabled( $store_id ){
            if( ! class_exists('WCV_Vendors') ) return false;
            if( ! WCV_Vendors::is_vendor( $store_id ) ) return false;

            $meta = get_user_meta( $store_id, 'bpbm_wc_vendors', true );

            if( $meta === 'disabled' ) {
                return false;
            } else if( $meta === 'enabled' ) {
                return true;
            } else {
                return apply_filters( 'better_messages_wc_vendors_store_default', true, $store_id );
            }
        }

        public function store_settings_save( $user_id ){
            if( isset( $_POST['bpbm_wc_vendors'] ) && $_POST['bpbm_wc_vendors'] === 'enabled' ){
                update_user_meta( $user_id, 'bpbm_wc_vendors', 'enabled' );
            } else {
                update_user_meta( $user_id, 'bpbm_wc_vendors', 'disabled' );
            }
        }

        public function store_settings_output(){
            $vendor_id = get_current_user_id();
            $enable_livechat = $this->is_livechat_enabled( $vendor_id );
            ?>
            <div class="pv_shop_bpbm_messages">
                <p>
                    <b><?php _ex( 'Live Chats', 'Marketplace Integrations', 'bp-better-messages' ); ?></b>
                </p>
                <p>
                    <label>
                        <input type="hidden" name="bpbm_wc_vendors" value="disabled">
                        <input type="checkbox" name="bpbm_wc_vendors" value="enabled" <?php checked( $enable_livechat ); ?>>
                        <?php echo esc_html_x( 'Enable live chat in store', 'Marketplace Integrations', 'bp-better-messages' ); ?>
                    </label>
                </p>
            </div>
            <?php
        }

        function vendor_user_meta( $item, $user_id, $include_personal ){
            if( ! class_exists('WCV_Vendors') ) return $item;
            if( ! WCV_Vendors::is_vendor( $user_id ) ) return $item;

            $shop_name = get_user_meta( $user_id, 'pv_shop_name', true );
            $shop_url = WCV_Vendors::get_vendor_shop_page( $user_id );

            if( ! empty( $shop_name ) ){
                $item['name'] = esc_attr( $shop_name );
            }

            if( ! empty( $shop_url ) ){
                $item['url'] = esc_url( $shop_url );
            }

            return $item;
        }

        public function thread_item( $thread_item, $thread_id, $thread_type, $include_personal, $user_id ){
            if( $thread_type !== 'thread' ){
                return $thread_item;
            }

            $unique_tag = Better_Messages()->functions->get_thread_meta( $thread_id, 'unique_tag' );

            if( ! empty( $unique_tag ) ){
                if( str_starts_with( $unique_tag, 'wc_vendors_product_chat_' ) ){
                    $parts = explode( '|', $unique_tag );
                    if( isset( $parts[0] ) ){
                        $product_id = str_replace( 'wc_vendors_product_chat_', '', $parts[0] );
                        $thread_info = '';
                        if( isset( $thread_item['threadInfo'] ) ) $thread_info = $thread_item['threadInfo'];
                        $thread_info .= $this->thread_info( $product_id );
                        $thread_item['threadInfo'] = $thread_info;
                    }
                }
            }

            return $thread_item;
        }

        public function thread_info( $product_id ){
            if( ! function_exists( 'wc_get_product' ) ) return '';

            $product = wc_get_product( $product_id );
            if( ! $product ) return '';

            $image_id = $product->get_image_id();
            $image_src = wp_get_attachment_image_src( $image_id, [100, 100] );

            $image = false;
            $title = $product->get_title();
            $url   = $product->get_permalink();
            $price = $product->get_price_html();

            if( $image_src ){
                $image = $image_src[0];
            }

            $html = '<div class="bm-product-info">';

            if( $image ){
                $html .= '<div class="bm-product-image">';
                $html .= '<a href="' . $url . '" target="_blank"><img src="' . $image . '" alt="' . $title . '" /></a>';
                $html .= '</div>';
            }

            $html .= '<div class="bm-product-details">';
            $html .= '<div class="bm-product-title"><a href="' . $url . '" target="_blank">' . $title . '</a></div>';
            $html .= '<div class="bm-product-price">' . $price . '</div>';
            $html .= '</div>';

            $html .= '</div>';

            return $html;
        }
    }
}
