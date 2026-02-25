<?php
defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Better_Messages_WCFM' ) ) {

    class Better_Messages_WCFM
    {

        public static function instance()
        {

            static $instance = null;

            if (null === $instance) {
                $instance = new Better_Messages_WCFM();
            }

            return $instance;
        }

        public function __construct(){
            add_shortcode( 'better_messages_wcfm_product_button', array( &$this, 'product_page_contact_button_shortcode' ) );
            add_shortcode( 'better_messages_wcfm_store_button', array( &$this, 'store_page_contact_button_shortcode' ) );

            if( Better_Messages()->settings['wcfmIntegration'] !== '1' ) return;

            add_filter( 'wcfm_menus', array( $this, 'wcfm_menus' ), 10, 1 );
            add_filter( 'wcfm_query_vars', array( $this, 'wcfm_query_vars' ), 50 );

            add_action( 'wcfm_load_views', array( $this, 'wcfm_load_views' ), 50 );

            add_filter( 'bp_better_messages_page', array( $this, 'vendor_messages_page' ), 20, 2 );

            add_action( 'woocommerce_single_product_summary', array( &$this, 'product_page_contact_button' ), 35 );

            add_action( 'before_wcfmmp_store_header_actions', array( $this, 'display_store_button' ) );

            add_action( 'end_wcfm_vendor_settings', array( $this, 'store_settings_output' ), 10, 1 );
            add_action( 'wcfm_vendor_settings_update', array( $this, 'store_settings_save' ), 10, 2 );

            add_filter( 'better_messages_rest_thread_item', array( $this, 'thread_item' ), 10, 5 );
            add_filter( 'better_messages_rest_user_item', array( $this, 'vendor_user_meta' ), 20, 3 );

            add_action( 'wp_enqueue_scripts', array( $this, 'dashboard_scripts' ) );
            add_action( 'wp_head', array( $this, 'button_styles' ) );
        }

        public function vendor_messages_page( $url, $user_id ){
            if( ! is_user_logged_in() ) return $url;
            if( ! function_exists('wcfm_is_vendor') ) return $url;
            if( ! wcfm_is_vendor( $user_id ) && ! current_user_can('manage_options') ) return $url;

            $livechat_enabled = $this->is_livechat_enabled( $user_id );
            if( $livechat_enabled ){
                $wcfm_page = get_wcfm_page();
                return wcfm_get_endpoint_url( 'messaging', '', $wcfm_page );
            }

            return $url;
        }

        public function wcfm_menus( $wcfm_menus ){
            if( ! $this->is_livechat_enabled( get_current_user_id() ) ) return $wcfm_menus;

            $wcfm_page = get_wcfm_page();
            $wcfm_messages_url = wcfm_get_endpoint_url( 'messaging', '', $wcfm_page );

            $wcfm_menus['bpbm-messages'] = array(
                'label'    => _x( 'Messages', 'Marketplace Integrations', 'bp-better-messages' ),
                'url'      => $wcfm_messages_url,
                'icon'     => 'comments',
                'priority' => 50
            );

            return $wcfm_menus;
        }

        public function wcfm_query_vars( $query_vars ){
            $wcfm_modified_endpoints = (array) get_option( 'wcfm_endpoints' );

            $query_custom_menus_vars = array(
                'bpbm-messages' => ! empty( $wcfm_modified_endpoints['bpbm-messages'] ) ? $wcfm_modified_endpoints['bpbm-messages'] : 'messaging',
            );

            $query_vars = array_merge( $query_vars, $query_custom_menus_vars );

            return $query_vars;
        }

        public function wcfm_load_views( $end_point ){
            if( $end_point !== 'bpbm-messages' ) return;

            ?>
            <div class="collapse wcfm-collapse" id="wcfm_bpbm_messages">
                <div class="wcfm-page-headig">
                    <span class="wcfmfa fa-comments"></span>
                    <span class="wcfm-page-heading-text"><?php _ex( 'Messages', 'Marketplace Integrations', 'bp-better-messages' ); ?></span>
                    <?php do_action( 'wcfm_page_heading' ); ?>
                </div>
                <div class="wcfm-collapse-content" style="padding: 0">
                    <div id="wcfm_page_load"></div>
                    <?php do_action( 'before_wcfm_bpbm_messages' ); ?>
                    <div class="wcfm-clearfix"></div>
                    <div class="wcfm-container" style="padding: 0; margin: 0">
                        <div id="wcfm_bpbm_messages_expander" class="wcfm-content" style="margin: 0; padding: 0;">
                            <?php echo do_shortcode( '[better_messages]' ); ?>
                            <div class="wcfm-clearfix"></div>
                        </div>
                        <div class="wcfm-clearfix"></div>
                    </div>
                    <div class="wcfm-clearfix"></div>
                    <?php do_action( 'after_wcfm_bpbm_messages' ); ?>
                </div>
            </div>
            <script type="text/javascript">
                (function(){
                    function initWcfmLayout(){
                        var container = document.querySelector('#wcfm-main-content .wcfm-content-container');
                        if( ! container ) return;
                        var totalHeight = container.offsetHeight;
                        var header = container.querySelector('.wcfm-page-headig');
                        var headerHeight = header ? header.offsetHeight : 0;
                        var resultHeight = totalHeight - headerHeight;

                        if( typeof Better_Messages !== 'undefined' ) {
                            var wrap = container.querySelector('.bp-messages-wrap');
                            if( wrap ) wrap.style.height = resultHeight + 'px';
                            var threadsWrap = container.querySelector('.bp-messages-threads-wrapper');
                            if( threadsWrap ) threadsWrap.style.height = resultHeight + 'px';
                            Better_Messages['maxHeight'] = resultHeight;
                        }
                    }
                    if( document.readyState === 'loading' ){
                        document.addEventListener('DOMContentLoaded', initWcfmLayout);
                    } else {
                        initWcfmLayout();
                    }
                })();
            </script>
            <?php
        }

        public function dashboard_scripts(){
            if( ! function_exists('wcfm_is_vendor') ) return;
            if( ! wcfm_is_vendor( get_current_user_id() ) ) return;
            if( ! $this->is_livechat_enabled( get_current_user_id() ) ) return;

            Better_Messages()->enqueue_css( true );

            $js = '(function(){
                function initWcfmCounter(){
                    var link = document.querySelector(".wcfm_menu_bpbm-messages .wcfm_menu_item .text");
                    if( link ){
                        var tmp = document.createElement("div");
                        tmp.innerHTML = \'' . do_shortcode('[better_messages_unread_counter hide_when_no_messages="1" preserve_space="0"]') . '\';
                        while( tmp.firstChild ) link.appendChild( tmp.firstChild );
                    }
                }
                if( document.readyState === "loading" ){
                    document.addEventListener("DOMContentLoaded", initWcfmCounter);
                } else {
                    initWcfmCounter();
                }
            })();';

            wp_add_inline_script( 'better-messages', Better_Messages()->functions->minify_js( $js ) );

            $css = '.wcfm_menu_bpbm-messages .wcfm_menu_item .text .bp-better-messages-unread{ margin-left: 10px; }';

            wp_add_inline_style( 'better-messages', Better_Messages()->functions->minify_css( $css ) );
        }

        public function button_styles(){
            ?>
            <style type="text/css">
                .wcfm_store_bpbm_pm {
                    min-width: 50px;
                    width: auto;
                    padding: 0 15px;
                    height: 30px;
                    background: #fff;
                    color: #17A2BB !important;
                    border-radius: 5px;
                    display: inline-flex;
                    align-items: center;
                    justify-content: center;
                    gap: 5px;
                    cursor: pointer;
                    line-height: 30px;
                }
                .wcfm_store_bpbm_pm::before {
                    font-family: 'Font Awesome 5 Free', 'FontAwesome', wcfmfa;
                    content: '\f0e0';
                }
                .wcfm_store_bpbm_pm span {
                    color: #17A2BB !important;
                    font-size: 13px !important;
                }
                .wcfm-pm {
                    background: #1c2b36;
                    padding: 5px 10px;
                    border-radius: 3px;
                    border: #f0f0f0 1px solid;
                    border-bottom: 1px solid #17a2b8;
                    color: #b0bec1;
                    float: left;
                    text-align: center;
                    text-decoration: none;
                    margin-top: 10px;
                    box-shadow: 0 1px 0 #ccc;
                    display: inline-flex;
                    align-items: center;
                    gap: 5px;
                    cursor: pointer;
                }
                .wcfm-pm::before {
                    font-family: 'Font Awesome 5 Free', 'FontAwesome', wcfmfa;
                    content: '\f0e0';
                }
                .wcfm-pm:hover {
                    background: #17a2b8 !important;
                    border-bottom-color: #17a2b8 !important;
                    color: #ffffff !important;
                }
            </style>
            <?php
        }

        public function display_store_button( $vendor_id ){
            if( ! $this->is_livechat_enabled( $vendor_id ) ) return;

            echo '<div class="lft bd_icon_box">';
            echo do_shortcode('[better_messages_live_chat_button
            type="button"
            class="wcfm_store_bpbm_pm"
            text="' . Better_Messages()->shortcodes->esc_brackets( esc_attr_x( 'Live Chat', 'WCFM Integration (Store page)', 'bp-better-messages' ) ) . '"
            user_id="' . $vendor_id . '"
            unique_tag="wcfm_store_chat_' . $vendor_id . '"
            ]');
            echo '</div>';
        }

        public function store_page_contact_button_shortcode(){
            if( ! function_exists('wcfmmp_get_store') ) return '';

            $store = wcfmmp_get_store();
            if( ! $store ) return '';

            $vendor_id = $store->get_id();
            if( ! $vendor_id ) return '';
            if( ! $this->is_livechat_enabled( $vendor_id ) ) return '';

            return do_shortcode('[better_messages_live_chat_button
            type="button"
            class="wcfm_store_bpbm_pm"
            text="' . Better_Messages()->shortcodes->esc_brackets( esc_attr_x( 'Live Chat', 'WCFM Integration (Store page)', 'bp-better-messages' ) ) . '"
            user_id="' . $vendor_id . '"
            unique_tag="wcfm_store_chat_' . $vendor_id . '"
            ]');
        }

        public function product_page_contact_button_shortcode(){
            return $this->product_page_contact_button( true );
        }

        public function product_page_contact_button( $return = false ){
            global $post;

            if( is_product() && $post && is_object( $post ) ) {
                $product_id = $post->ID;

                if( ! function_exists('wcfm_get_vendor_id_by_post') ) return $return ? '' : null;

                $vendor_id = wcfm_get_vendor_id_by_post( $product_id );
                if( ! $vendor_id ) return $return ? '' : null;

                $livechat_enabled = $this->is_livechat_enabled( $vendor_id );
                if( $livechat_enabled ){
                    $product = wc_get_product( get_the_ID() );

                    $subject = esc_attr( sprintf( _x( 'Question about your product %s', 'WCFM Integration (Product page)', 'bp-better-messages' ), $product->get_title() ) );

                    $shortcode = do_shortcode('[better_messages_live_chat_button
                    type="button"
                    class="wcfm-pm"
                    text="' . Better_Messages()->shortcodes->esc_brackets( esc_attr_x( 'Live Chat', 'WCFM Integration (Product page)', 'bp-better-messages' ) ) . '"
                    user_id="' . $vendor_id . '"
                    subject="' . Better_Messages()->shortcodes->esc_brackets( $subject ) . '"
                    unique_tag="wcfm_product_chat_' . get_the_ID() . '"
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

        public function is_livechat_enabled( $vendor_id ){
            if( ! function_exists('wcfm_is_vendor') ) return false;
            if( ! wcfm_is_vendor( $vendor_id ) ) return false;

            $meta = get_user_meta( $vendor_id, '_vendor_bm_livechat', true );
            if( $meta === 'disable' ) {
                return false;
            } else if( $meta === 'enable' ) {
                return true;
            } else {
                return apply_filters( 'better_messages_wcfm_store_default', true, $vendor_id );
            }
        }

        public function store_settings_output( $user_id ){
            global $WCFM;

            $enable_livechat = $this->is_livechat_enabled( $user_id );
            ?>
            <!-- collapsible -->
            <div class="page_collapsible" id="wcfm_settings_form_bm_livechat_head">
                <label class="wcfmfa fa-comments"></label>
                <?php _ex( 'Live Chats', 'Marketplace Integrations', 'bp-better-messages' ); ?><span></span>
            </div>
            <div class="wcfm-container">
                <div id="wcfm_settings_form_bm_livechat_expander" class="wcfm-content">
                    <?php
                    $WCFM->wcfm_fields->wcfm_generate_form_field( array(
                        "vendor_bm_livechat" => array(
                            'label'       => esc_html_x( 'Enable live chat in store', 'Marketplace Integrations', 'bp-better-messages' ),
                            'type'        => 'checkbox',
                            'class'       => 'wcfm-checkbox wcfm_ele',
                            'label_class' => 'wcfm_title checkbox_title wcfm_ele',
                            'value'       => 'enable',
                            'dfvalue'     => $enable_livechat ? 'enable' : '',
                        ),
                    ) );
                    ?>
                </div>
            </div>
            <div class="wcfm_clearfix"></div>
            <!-- end collapsible -->
            <?php
        }

        public function store_settings_save( $user_id, $wcfm_settings_form ){
            $raw_form_data = array();
            if( isset( $_POST['wcfm_settings_form'] ) ) {
                parse_str( $_POST['wcfm_settings_form'], $raw_form_data );
            }

            if( isset( $raw_form_data['vendor_bm_livechat'] ) && $raw_form_data['vendor_bm_livechat'] === 'enable' ){
                update_user_meta( $user_id, '_vendor_bm_livechat', 'enable' );
            } else {
                update_user_meta( $user_id, '_vendor_bm_livechat', 'disable' );
            }
        }

        function vendor_user_meta( $item, $user_id, $include_personal ){
            if( ! function_exists('wcfm_is_vendor') ) return $item;
            if( ! wcfm_is_vendor( $user_id ) ) return $item;
            if( ! $this->is_livechat_enabled( $user_id ) ) return $item;

            if( function_exists('wcfmmp_get_store_url') ){
                $item['url'] = esc_url( wcfmmp_get_store_url( $user_id ) );
            }

            $store_name = get_user_meta( $user_id, 'wcfmmp_store_name', true );
            if( ! empty( $store_name ) ){
                $item['name'] = esc_attr( $store_name );
            } else {
                $store_name = get_user_meta( $user_id, 'store_name', true );
                if( ! empty( $store_name ) ){
                    $item['name'] = esc_attr( $store_name );
                }
            }

            $gravatar = get_user_meta( $user_id, '_wcfmmp_profile_image', true );
            if( ! empty( $gravatar ) ){
                $item['avatar'] = esc_url( wp_get_attachment_url( $gravatar ) );
            }

            return $item;
        }

        public function thread_item( $thread_item, $thread_id, $thread_type, $include_personal, $user_id ){
            if( $thread_type !== 'thread' ){
                return $thread_item;
            }

            $unique_tag = Better_Messages()->functions->get_thread_meta( $thread_id, 'unique_tag' );

            if( ! empty( $unique_tag ) ){
                if( str_starts_with( $unique_tag, 'wcfm_product_chat_' ) ){
                    $parts = explode( '|', $unique_tag );
                    if( isset( $parts[0] ) ){
                        $product_id = str_replace( 'wcfm_product_chat_', '', $parts[0] );
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
