<?php
defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Better_Messages_MultiVendorX_V5' ) ) {

    class Better_Messages_MultiVendorX_V5
    {
        const META_KEY        = 'bm_livechat_enabled';
        const PRODUCT_TAG     = 'multivendorx_product_chat_';
        const STORE_TAG       = 'multivendorx_store_chat_';
        const ROUTE_SLUG      = 'bm-messages';
        const ASSET_HANDLE    = 'better-messages-multivendorx-v5';

        public static function instance()
        {
            static $instance = null;

            if ( null === $instance ) {
                $instance = new Better_Messages_MultiVendorX_V5();
            }

            return $instance;
        }

        public function __construct()
        {
            add_shortcode( 'better_messages_multivendorx_product_button', array( $this, 'product_page_contact_button_shortcode' ) );
            add_shortcode( 'better_messages_multivendorx_store_button',   array( $this, 'store_page_contact_button_shortcode' ) );

            if ( Better_Messages()->settings['MultiVendorXIntegration'] !== '1' ) return;

            add_action( 'multivendorx_after_vendor_information', array( $this, 'render_store_button_action' ), 10, 1 );
            add_action( 'woocommerce_single_product_summary',    array( $this, 'render_product_button_action' ), 35 );

            add_filter( 'better_messages_rest_thread_item', array( $this, 'thread_item' ), 10, 5 );
            add_filter( 'better_messages_rest_user_item',   array( $this, 'vendor_user_meta' ), 20, 3 );

            add_filter( 'bp_better_messages_page',   array( $this, 'vendor_messages_page' ), 20, 2 );
            add_filter( 'dashboard_other_endpoints', array( $this, 'dashboard_menu' ), 20, 1 );

            add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_dashboard_script' ), 99 );
            add_action( 'wp_print_styles',    array( $this, 'restore_dashboard_styles' ), 100 );
        }

        private function get_store_owner_id( $store_id )
        {
            if ( ! $store_id ) return 0;
            return (int) \MultiVendorX\Store\StoreUtil::get_primary_owner( (int) $store_id );
        }

        private function get_current_store()
        {
            $base = MultiVendorX()->setting->get_setting( 'store_url', 'store' );
            $slug = get_query_var( $base );
            if ( empty( $slug ) ) return null;

            $store = \MultiVendorX\Store\Store::get_store( $slug, 'slug' );
            return ( $store && $store->get_id() ) ? $store : null;
        }

        private function get_user_store_id( $user_id )
        {
            $user_id = (int) $user_id;
            if ( $user_id <= 0 ) return 0;

            $stores = \MultiVendorX\Store\Store::get_store( $user_id, 'user' );
            return ( is_array( $stores ) && ! empty( $stores ) ) ? (int) ( $stores[0]['id'] ?? 0 ) : 0;
        }

        public function is_livechat_enabled( $store_id, $user_id = 0 )
        {
            $store_id = (int) $store_id;
            $enabled  = true;

            if ( $store_id ) {
                $store = new \MultiVendorX\Store\Store( $store_id );
                if ( $store->get_id() ) {
                    $meta = $store->get_meta( self::META_KEY );
                    if ( is_array( $meta ) ) {
                        $enabled = in_array( self::META_KEY, $meta, true );
                    } elseif ( $meta !== '' && $meta !== null ) {
                        $enabled = (bool) $meta;
                    }
                }
            }

            return (bool) apply_filters( 'better_messages_multivendorx_store_default', $enabled, $store_id, $user_id );
        }

        public function store_page_contact_button_shortcode()
        {
            $store = $this->get_current_store();
            if ( ! $store ) return '';

            return $this->render_store_button( $store->get_id() );
        }

        public function product_page_contact_button_shortcode()
        {
            return $this->render_product_button();
        }

        public function render_store_button_action( $store_id )
        {
            echo $this->render_store_button( $store_id );
        }

        public function render_product_button_action()
        {
            echo $this->render_product_button();
        }

        private function render_store_button( $store_id )
        {
            $store_id = (int) $store_id;
            $owner_id = $this->get_store_owner_id( $store_id );

            if ( $owner_id <= 0 || $owner_id === get_current_user_id() ) return '';
            if ( ! $this->is_livechat_enabled( $store_id, $owner_id ) ) return '';

            return $this->live_chat_button(
                $owner_id,
                self::STORE_TAG . $owner_id,
                esc_attr_x( 'Live Chat', 'MultiVendorX Integration (Store page)', 'bp-better-messages' ),
                '',
                array( 'type' => 'button' )
            );
        }

        private function render_product_button()
        {
            if ( ! is_product() ) return '';

            $product_id = (int) get_the_ID();
            if ( ! $product_id ) return '';

            $store = \MultiVendorX\Store\Store::get_store( $product_id, 'product' );
            if ( ! $store || ! $store->get_id() ) return '';

            $owner_id = $this->get_store_owner_id( $store->get_id() );
            if ( $owner_id <= 0 || $owner_id === get_current_user_id() ) return '';
            if ( ! $this->is_livechat_enabled( $store->get_id(), $owner_id ) ) return '';

            $product = wc_get_product( $product_id );
            if ( ! $product ) return '';

            $subject = esc_attr( sprintf(
                _x( 'Question about your product %s', 'MultiVendorX Integration (Product page)', 'bp-better-messages' ),
                $product->get_title()
            ) );

            return $this->live_chat_button(
                $owner_id,
                self::PRODUCT_TAG . $product_id,
                esc_attr_x( 'Live Chat', 'MultiVendorX Integration (Product page)', 'bp-better-messages' ),
                $subject,
                array( 'class' => 'bm-style-btn' )
            );
        }

        private function live_chat_button( $user_id, $unique_tag, $text, $subject = '', $extra = array() )
        {
            $esc   = Better_Messages()->shortcodes;
            $attrs = '';

            foreach ( $extra as $k => $v ) {
                $attrs .= ' ' . $k . '="' . $esc->esc_brackets( $v ) . '"';
            }

            if ( $subject !== '' ) {
                $attrs .= ' subject="' . $esc->esc_brackets( $subject ) . '"';
            }

            return do_shortcode(
                '[better_messages_live_chat_button'
                . ' text="' . $esc->esc_brackets( $text ) . '"'
                . ' user_id="' . (int) $user_id . '"'
                . ' unique_tag="' . $unique_tag . '"'
                . $attrs
                . ']'
            );
        }

        public function vendor_user_meta( $item, $user_id, $include_personal )
        {
            $store_id = $this->get_user_store_id( $user_id );
            if ( ! $store_id ) return $item;
            if ( ! $this->is_livechat_enabled( $store_id, (int) $user_id ) ) return $item;

            $store = new \MultiVendorX\Store\Store( $store_id );
            if ( ! $store->get_id() ) return $item;

            $util      = new \MultiVendorX\Store\StoreUtil();
            $store_url = $util->get_store_url( $store_id );
            $name      = $store->get( 'name' );
            $image     = $store->get_meta( 'image' );

            $avatar = '';
            if ( ! empty( $image ) ) {
                if ( is_numeric( $image ) ) {
                    $avatar = (string) wp_get_attachment_url( (int) $image );
                } elseif ( is_string( $image ) && filter_var( $image, FILTER_VALIDATE_URL ) ) {
                    $avatar = $image;
                }
            }

            if ( ! empty( $store_url ) ) $item['url']    = esc_url( $store_url );
            if ( ! empty( $avatar ) )    $item['avatar'] = esc_url( $avatar );
            if ( ! empty( $name ) )      $item['name']   = esc_attr( $name );

            return $item;
        }

        public function thread_item( $thread_item, $thread_id, $thread_type, $include_personal, $user_id )
        {
            if ( $thread_type !== 'thread' ) return $thread_item;

            $unique_tag = Better_Messages()->functions->get_thread_meta( $thread_id, 'unique_tag' );
            if ( empty( $unique_tag ) || ! str_starts_with( $unique_tag, self::PRODUCT_TAG ) ) {
                return $thread_item;
            }

            $head       = explode( '|', $unique_tag, 2 )[0];
            $product_id = (int) substr( $head, strlen( self::PRODUCT_TAG ) );
            if ( $product_id <= 0 ) return $thread_item;

            $thread_item['threadInfo'] = ( $thread_item['threadInfo'] ?? '' ) . $this->thread_info( $product_id );

            return $thread_item;
        }

        private function thread_info( $product_id )
        {
            if ( ! function_exists( 'wc_get_product' ) ) return '';

            $product = wc_get_product( (int) $product_id );
            if ( ! $product ) return '';

            $image_id  = $product->get_image_id();
            $image_src = wp_get_attachment_image_src( $image_id, array( 100, 100 ) );
            $image     = $image_src ? $image_src[0] : false;
            $title     = $product->get_title();
            $url       = $product->get_permalink();
            $price     = $product->get_price_html();

            $html = '<div class="bm-product-info">';

            if ( $image ) {
                $html .= '<div class="bm-product-image">';
                $html .= '<a href="' . esc_url( $url ) . '" target="_blank"><img src="' . esc_url( $image ) . '" alt="' . esc_attr( $title ) . '" /></a>';
                $html .= '</div>';
            }

            $html .= '<div class="bm-product-details">';
            $html .= '<div class="bm-product-title"><a href="' . esc_url( $url ) . '" target="_blank">' . esc_html( $title ) . '</a></div>';
            $html .= '<div class="bm-product-price">' . $price . '</div>';
            $html .= '</div>';

            $html .= '</div>';

            return $html;
        }

        public function vendor_messages_page( $url, $user_id )
        {
            $store_id = $this->get_user_store_id( $user_id );
            if ( ! $store_id ) return $url;

            if (
                Better_Messages()->settings['MultiVendorXHideTabWhenDisabled'] === '1'
                && ! $this->is_livechat_enabled( $store_id, $user_id )
            ) {
                return $url;
            }

            return $this->dashboard_messages_url();
        }

        public function dashboard_messages_url()
        {
            return home_url( '/dashboard/' . self::ROUTE_SLUG . '/' );
        }

        public function dashboard_menu( $endpoints )
        {
            if ( Better_Messages()->settings['MultiVendorXHideTabWhenDisabled'] === '1' ) {
                $user_id  = get_current_user_id();
                $store_id = $this->get_user_store_id( $user_id );
                if ( $store_id && ! $this->is_livechat_enabled( $store_id, $user_id ) ) return $endpoints;
            }

            $endpoints[ self::ROUTE_SLUG ] = array(
                'name'       => _x( 'Messages', 'MultiVendorX dashboard menu', 'bp-better-messages' ),
                'slug'       => self::ROUTE_SLUG,
                'icon'       => 'live-chat',
                'submenu'    => array(),
                'capability' => array( 'create_stores' ),
            );

            return $endpoints;
        }

        public function enqueue_dashboard_script()
        {
            if ( ! class_exists( '\MultiVendorX\Utill' ) || ! \MultiVendorX\Utill::is_store_dashboard() ) return;

            Better_Messages()->load_scripts();
            Better_Messages()->enqueue_css( true );

            wp_register_script(
                self::ASSET_HANDLE,
                Better_Messages()->url . 'addons/multi-vendor-x-v5/multi-vendor-x-v5.js',
                array( 'wp-element', 'multivendorx-dashboard-script' ),
                Better_Messages()->version,
                true
            );

            wp_localize_script(
                self::ASSET_HANDLE,
                'bmMultivendorxV5',
                array(
                    'fullScreen'          => true,
                    'routeSlug'           => self::ROUTE_SLUG,
                    'metaKey'             => self::META_KEY,
                    'title'               => _x( 'Messages', 'MultiVendorX dashboard menu', 'bp-better-messages' ),
                    'settingsTitle'       => _x( 'Live Chat', 'MultiVendorX vendor settings', 'bp-better-messages' ),
                    'settingsDescription' => _x( 'Allow customers to message you about your store', 'MultiVendorX vendor settings', 'bp-better-messages' ),
                    'settingsLabel'       => _x( 'Enable live chat', 'MultiVendorX vendor settings', 'bp-better-messages' ),
                    'settingsHelp'        => _x( 'Show a Live Chat button on your store and product pages', 'MultiVendorX vendor settings', 'bp-better-messages' ),
                )
            );

            wp_enqueue_script( self::ASSET_HANDLE );
            wp_add_inline_script( self::ASSET_HANDLE, Better_Messages()->functions->minify_js( $this->unread_counter_js() ), 'after' );

            wp_register_style( self::ASSET_HANDLE, false, array( 'better-messages' ), Better_Messages()->version );
            wp_add_inline_style( self::ASSET_HANDLE, Better_Messages()->functions->minify_css( $this->dashboard_css() ) );
            wp_enqueue_style( self::ASSET_HANDLE );
        }

        private function unread_counter_js()
        {
            $selector = 'a.tab[href$="/dashboard/' . self::ROUTE_SLUG . '"], a.tab[href*="/dashboard/' . self::ROUTE_SLUG . '/"]';
            $selector_js = wp_json_encode( $selector );

            return '(function(){
                var current = 0;

                function ensureCounter(){
                    var links = document.querySelectorAll(' . $selector_js . ');
                    if( ! links.length ) return;
                    links.forEach(function( link ){
                        var counter = link.querySelector(".bp-better-messages-unread");
                        if( ! counter ){
                            counter = document.createElement("span");
                            counter.className = "bp-better-messages-unread bpbmuc bpbmuc-hide-when-null bm-mvx-unread";
                            link.appendChild( counter );
                        }
                        counter.dataset.count = current;
                        counter.textContent = current > 0 ? current : "";
                    });
                }

                if( window.wp && wp.hooks && wp.hooks.addAction ){
                    wp.hooks.addAction("better_messages_update_unread", "better_messages_mvx", function( unread ){
                        current = parseInt( unread, 10 ) || 0;
                        ensureCounter();
                    });
                }

                function init(){
                    ensureCounter();
                    var sidebar = document.querySelector(".mvx-dashboard, .dashboard-content, .vendor-dashboard, body");
                    if( ! sidebar || typeof MutationObserver === "undefined" ) return;
                    var rebindTimer = 0;
                    var observer = new MutationObserver(function(){
                        if( rebindTimer ) return;
                        rebindTimer = ( window.requestAnimationFrame || function( cb ){ return setTimeout( cb, 16 ); } )( function(){
                            rebindTimer = 0;
                            ensureCounter();
                        });
                    });
                    observer.observe( sidebar, { childList: true, subtree: true } );
                }

                if( document.readyState === "loading" ){
                    document.addEventListener("DOMContentLoaded", init);
                } else {
                    init();
                }
            })();';
        }

        private function dashboard_css()
        {
            return '
                .dashboard-content .content-wrapper:has(.bm-mvx-dashboard-root){
                    height: auto !important;
                    min-height: 0 !important;
                    padding: 0 !important;
                }
                .bm-mvx-dashboard-root{
                    margin-top: var(--bm-mvx-navbar-h, 57px);
                }
                .bm-mvx-dashboard-root,
                .bm-mvx-dashboard-root .bp-messages-wrap-main,
                .bm-mvx-dashboard-root .bp-messages-wrap-main .bp-messages-wrap,
                .bm-mvx-dashboard-root .bp-messages-wrap-main .bp-messages-threads-wrapper{
                    height: var(--bm-mvx-dashboard-h, calc(100vh - 120px)) !important;
                    min-height: 0 !important;
                    max-height: none !important;
                }
                .bm-mvx-dashboard-root .bp-messages-wrap-main{
                    display: block;
                }
                .bm-mvx-dashboard-root .bp-messages-wrap-main .bp-messages-wrap{
                    border: 0 !important;
                    border-radius: 0 !important;
                    box-shadow: none !important;
                }
                a.tab .bm-mvx-unread{
                    margin-left: auto;
                    display: inline-flex;
                    align-items: center;
                    justify-content: center;
                    min-width: 18px;
                    height: 18px;
                    padding: 0 6px;
                    border-radius: 9px;
                    background: var(--bm-primary-color, #2271b1);
                    color: #fff;
                    font-size: 11px;
                    line-height: 1;
                    font-weight: 600;
                }
            ';
        }

        public function restore_dashboard_styles()
        {
            if ( ! class_exists( '\MultiVendorX\Utill' ) || ! \MultiVendorX\Utill::is_store_dashboard() ) return;

            global $wp_styles;
            if ( ! $wp_styles instanceof WP_Styles ) return;

            foreach ( array( 'better-messages', self::ASSET_HANDLE ) as $handle ) {
                if ( isset( $wp_styles->registered[ $handle ] ) && ! in_array( $handle, $wp_styles->queue, true ) ) {
                    $wp_styles->queue[] = $handle;
                }
            }
        }
    }
}
