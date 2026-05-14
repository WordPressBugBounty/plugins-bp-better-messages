<?php
defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Better_Messages_Motors' ) ) {

    class Better_Messages_Motors
    {
        public static function instance()
        {
            static $instance = null;

            if ( null === $instance ) {
                $instance = new Better_Messages_Motors();
            }

            return $instance;
        }

        public function __construct()
        {
            add_shortcode( 'better_messages_motors_listing_button', array( $this, 'listing_button_shortcode' ) );
            add_shortcode( 'better_messages_motors_dealer_button',  array( $this, 'dealer_button_shortcode' ) );

            if ( Better_Messages()->settings['motorsIntegration'] !== '1' ) return;

            add_action( 'wp_head', array( $this, 'print_styles' ), 100 );

            if ( Better_Messages()->settings['motorsSingleListingButton'] === '1' ) {
                add_action( 'wp_footer', array( $this, 'render_single_listing_button_auto' ), 20 );
            }

            if ( Better_Messages()->settings['motorsDealerProfileButton'] === '1' ) {
                add_action( 'wp_footer', array( $this, 'render_dealer_profile_button_auto' ), 20 );
            }

            add_filter( 'stm_listings_locate_template', array( $this, 'locate_template' ), 10, 2 );

            if ( Better_Messages()->settings['motorsDashboardTab'] === '1' || Better_Messages()->settings['chatPage'] === 'motors-dashboard' ) {
                add_action( 'saved_search_navigation', array( $this, 'render_dashboard_tab_link' ), 20, 1 );
                add_filter( 'stm_account_current_page', array( $this, 'detect_dashboard_tab' ) );
            }

            add_filter( 'better_messages_rest_thread_item', array( $this, 'thread_item' ), 10, 5 );
            add_filter( 'better_messages_rest_user_item', array( $this, 'user_item' ), 20, 3 );
        }

        public function get_listing_post_types()
        {
            $types = (array) apply_filters( 'stm_listings_post_type', 'listings' );

            if ( function_exists( 'stm_is_multilisting' ) && stm_is_multilisting()
                 && class_exists( 'STMMultiListing' )
                 && method_exists( 'STMMultiListing', 'stm_get_listing_type_slugs' ) ) {
                $extra = STMMultiListing::stm_get_listing_type_slugs();
                if ( is_array( $extra ) ) {
                    $types = array_unique( array_merge( $types, $extra ) );
                }
            }

            return array_values( array_filter( $types ) );
        }

        private function can_render_message_button( $target_user_id )
        {
            $target_user_id = (int) $target_user_id;
            if ( $target_user_id <= 0 ) return false;
            if ( $target_user_id === (int) Better_Messages()->functions->get_current_user_id() ) return false;

            return true;
        }

        private function render_live_chat_button( array $args )
        {
            $defaults = array(
                'type'       => 'button',
                'class'      => 'bm-motors-btn bm-motors-btn-primary',
                'text'       => '',
                'user_id'    => 0,
                'unique_tag' => '',
                'subject'    => '',
                'alt'        => '',
            );
            $args = array_merge( $defaults, $args );

            $shortcode  = '[better_messages_live_chat_button';
            $shortcode .= ' type="' . esc_attr( $args['type'] ) . '"';
            $shortcode .= ' class="' . esc_attr( $args['class'] ) . '"';
            $shortcode .= ' text="' . Better_Messages()->shortcodes->esc_brackets( $args['text'] ) . '"';
            $shortcode .= ' user_id="' . (int) $args['user_id'] . '"';
            $shortcode .= ' unique_tag="' . esc_attr( $args['unique_tag'] ) . '"';
            if ( ! empty( $args['subject'] ) ) {
                $shortcode .= ' subject="' . Better_Messages()->shortcodes->esc_brackets( $args['subject'] ) . '"';
            }
            if ( ! empty( $args['alt'] ) ) {
                $shortcode .= ' alt="' . Better_Messages()->shortcodes->esc_brackets( $args['alt'] ) . '"';
            }
            $shortcode .= ']';

            return do_shortcode( $shortcode );
        }

        public function locate_template( $located, $templates )
        {
            $names = array_map( static function ( $t ) {
                $t = (string) $t;
                if ( substr( $t, -4 ) !== '.php' ) $t .= '.php';
                return $t;
            }, (array) $templates );

            $addon_dir = trailingslashit( __DIR__ );

            $map = array();
            $dashboard_enabled = Better_Messages()->settings['motorsDashboardTab'] === '1'
                              || Better_Messages()->settings['chatPage'] === 'motors-dashboard';
            if ( $dashboard_enabled ) {
                $map['user/private/better-messages.php'] = 'templates/dashboard.php';
            }
            if ( Better_Messages()->settings['motorsListingCardButton'] === '1' ) {
                $map['loop/list/features.php'] = 'templates/loop-list-features.php';
            }

            foreach ( $map as $needle => $relative ) {
                if ( in_array( $needle, $names, true ) ) {
                    $candidate = $addon_dir . $relative;
                    if ( file_exists( $candidate ) ) {
                        return $candidate;
                    }
                }
            }

            return $located;
        }

        public function render_card_button( $listing_id )
        {
            $listing_id = (int) $listing_id;
            if ( ! $listing_id ) return '';
            if ( ! in_array( get_post_type( $listing_id ), $this->get_listing_post_types(), true ) ) return '';
            if ( get_post_status( $listing_id ) !== 'publish' ) return '';

            $author_id = (int) get_post_field( 'post_author', $listing_id );
            if ( ! $this->can_render_message_button( $author_id ) ) return '';

            $subject = sprintf(
                _x( 'Question about listing "%s"', 'Motors Integration (Archive card)', 'bp-better-messages' ),
                get_the_title( $listing_id )
            );

            $label = esc_attr_x( 'Live Chat', 'Motors Integration (Archive card)', 'bp-better-messages' );

            $html = $this->render_live_chat_button( array(
                'type'       => 'link',
                'class'      => 'bm-motors-card-btn',
                'alt'        => $label,
                'text'       => $label,
                'user_id'    => $author_id,
                'unique_tag' => 'motors_listing_chat_' . $listing_id,
                'subject'    => esc_attr( $subject ),
            ) );

            if ( empty( $html ) ) return '';

            return '<div class="bm-motors-card-button-wrap">' . $html . '</div>';
        }

        public function print_styles()
        {
            ?>
<style id="bm-motors-button-styles">
.bm-motors-auto-anchor,
.bm-motors-listing-button-wrap,
.bm-motors-dealer-button-wrap {
    display: block;
    width: 100%;
    box-sizing: border-box;
    margin: 0 0 12px 0;
}
.elementor-widget-wrap > .bm-motors-auto-anchor,
.elementor-widget-wrap > .bm-motors-listing-button-wrap,
.elementor-widget-wrap > .bm-motors-dealer-button-wrap {
    align-self: stretch;
    flex-basis: 100%;
}
.bm-motors-auto-anchor > .bm-motors-listing-button-wrap,
.bm-motors-auto-anchor > .bm-motors-dealer-button-wrap {
    margin: 0;
}
.bm-motors-listing-button-wrap .bm-lc-button,
.bm-motors-dealer-button-wrap .bm-lc-button {
    display: flex;
    align-items: center;
    justify-content: flex-start;
    gap: 14px;
    width: 100%;
    padding: 13px 17px;
    background: transparent;
    color: var(--motors-text-color, #010101);
    border: 1px solid var(--motors-border-color, rgba(1, 1, 1, 0.15));
    border-radius: 0;
    font-family: "Montserrat", Arial, sans-serif;
    font-size: 14px;
    font-weight: 600;
    text-transform: none;
    letter-spacing: 0;
    text-align: left;
    text-decoration: none;
    cursor: pointer;
    line-height: 1;
    box-sizing: border-box;
    transition: border-color 0.15s ease, background-color 0.15s ease;
}
.bm-motors-listing-button-wrap .bm-lc-button:hover,
.bm-motors-dealer-button-wrap .bm-lc-button:hover {
    border-color: var(--motors-accent-color, #1280DF);
    background: var(--motors-accent-color, #1280DF);
    color: var(--motors-contrast-text-color, #FFFFFF);
    text-decoration: none;
}
.bm-motors-listing-button-wrap .bm-lc-button::before,
.bm-motors-dealer-button-wrap .bm-lc-button::before {
    content: "";
    display: inline-block;
    width: 23px;
    height: 23px;
    background-color: var(--motors-accent-color, #1280DF);
    -webkit-mask: url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24'><path d='M20 2H4c-1.1 0-2 .9-2 2v18l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2zM6 9h12v2H6V9zm8 5H6v-2h8v2zm4-6H6V6h12v2z'/></svg>") no-repeat center / contain;
            mask: url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24'><path d='M20 2H4c-1.1 0-2 .9-2 2v18l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2zM6 9h12v2H6V9zm8 5H6v-2h8v2zm4-6H6V6h12v2z'/></svg>") no-repeat center / contain;
    flex-shrink: 0;
    transition: background-color 0.15s ease;
}
.bm-motors-listing-button-wrap .bm-lc-button:hover::before,
.bm-motors-dealer-button-wrap .bm-lc-button:hover::before {
    background-color: var(--motors-contrast-text-color, #FFFFFF);
}
.bm-motors-listing-button-wrap .bm-lc-button .bm-button-text,
.bm-motors-dealer-button-wrap .bm-lc-button .bm-button-text {
    color: inherit;
    font: inherit;
    font-weight: 600;
}
.stm-user-data-right .bm-motors-auto-anchor,
.stm-user-data-right .bm-motors-dealer-button-wrap {
    width: 100%;
    margin: 0;
    flex-basis: auto;
    align-self: auto;
}
.stm-user-data-right .bm-motors-dealer-button-wrap .bm-lc-button {
    display: flex;
    align-items: center;
    justify-content: flex-start;
    gap: 12px;
    width: 100%;
    padding: 14px 20px;
    background: transparent;
    color: var(--motors-contrast-text-color, #FFFFFF);
    border: 0;
    border-bottom: 1px solid rgba(1, 1, 1, 0.15);
    border-radius: 0;
    font-family: "Montserrat", Arial, sans-serif;
    font-size: 14px;
    font-weight: 600;
    text-transform: none;
    text-decoration: none;
    cursor: pointer;
    line-height: 1.2;
    box-sizing: border-box;
    transition: background-color 0.15s ease;
}
.stm-user-data-right .bm-motors-dealer-button-wrap .bm-lc-button:hover {
    background: rgba(255, 255, 255, 0.06);
    color: var(--motors-contrast-text-color, #FFFFFF);
    border-color: rgba(1, 1, 1, 0.15);
    text-decoration: none;
}
.stm-user-data-right .bm-motors-dealer-button-wrap .bm-lc-button::before,
.stm-user-data-right .bm-motors-dealer-button-wrap .bm-lc-button::after {
    width: 20px;
    height: 20px;
    background-color: var(--motors-accent-color, #1280DF);
}
.stm-user-data-right .bm-motors-dealer-button-wrap .bm-lc-button::after {
    content: none;
}
.stm-user-data-right .bm-motors-dealer-button-wrap .bm-lc-button::before {
    content: "";
    display: inline-block;
    -webkit-mask: url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24'><path d='M20 2H4c-1.1 0-2 .9-2 2v18l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2zM6 9h12v2H6V9zm8 5H6v-2h8v2zm4-6H6V6h12v2z'/></svg>") no-repeat center / contain;
            mask: url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24'><path d='M20 2H4c-1.1 0-2 .9-2 2v18l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2zM6 9h12v2H6V9zm8 5H6v-2h8v2zm4-6H6V6h12v2z'/></svg>") no-repeat center / contain;
    flex-shrink: 0;
}
.stm-user-data-right .bm-motors-dealer-button-wrap .bm-lc-button:hover::before {
    background-color: var(--motors-accent-color, #1280DF);
}
.stm-user-data-right .bm-motors-dealer-button-wrap .bm-lc-button .bm-button-text {
    color: inherit;
    font: inherit;
    font-weight: 600;
}
.single-car-actions ul.list-unstyled > li.bm-motors-action-li {
    display: inline-block;
    list-style: none;
    margin: 0 6px 6px 0;
}
.single-car-actions ul.list-unstyled > li.bm-motors-action-li .bm-motors-card-button-wrap {
    display: inline-block;
}
.single-car-actions ul.list-unstyled > li.bm-motors-action-li .bm-lc-button {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    height: 29px;
    padding: 0 15px;
    background: transparent;
    color: var(--motors-text-color, #010101);
    border: 1px solid var(--motors-border-color, rgba(1, 1, 1, 0.15));
    border-radius: 15px;
    font-family: "Montserrat", Arial, sans-serif;
    font-size: 12px;
    font-weight: 500;
    line-height: 27px;
    text-transform: uppercase;
    text-decoration: none;
    cursor: pointer;
    transition: border-color 0.15s ease, color 0.15s ease;
    box-sizing: border-box;
}
.single-car-actions ul.list-unstyled > li.bm-motors-action-li .bm-lc-button:hover {
    color: var(--motors-accent-color, #1280DF);
    border-color: var(--motors-accent-color, #1280DF);
    text-decoration: none;
}
.single-car-actions ul.list-unstyled > li.bm-motors-action-li .bm-lc-button::before {
    content: "";
    display: inline-block;
    width: 17px;
    height: 17px;
    background-color: var(--motors-accent-color, #1280DF);
    -webkit-mask: url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24'><path d='M20 2H4c-1.1 0-2 .9-2 2v18l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2zM6 9h12v2H6V9zm8 5H6v-2h8v2zm4-6H6V6h12v2z'/></svg>") no-repeat center / contain;
            mask: url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24'><path d='M20 2H4c-1.1 0-2 .9-2 2v18l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2zM6 9h12v2H6V9zm8 5H6v-2h8v2zm4-6H6V6h12v2z'/></svg>") no-repeat center / contain;
    flex-shrink: 0;
}
.single-car-actions ul.list-unstyled > li.bm-motors-action-li .bm-lc-button .bm-button-text {
    color: inherit;
    font: inherit;
    font-weight: 500;
    text-transform: inherit;
}
</style>
            <?php
        }

        public function render_single_listing_button_auto()
        {
            if ( ! is_singular( $this->get_listing_post_types() ) ) return;

            $listing_id = (int) get_queried_object_id();
            if ( ! $listing_id ) return;

            $author_id = (int) get_post_field( 'post_author', $listing_id );
            if ( ! $this->can_render_message_button( $author_id ) ) return;

            $html = $this->listing_button_shortcode( array( 'listing_id' => $listing_id ) );
            if ( empty( $html ) ) return;

            echo '<div class="bm-motors-auto-anchor" data-bm-motors-auto="listing" hidden>' . $html . '</div>';
            ?>
            <script>
                document.addEventListener('DOMContentLoaded', function () {
                    var wrap = document.querySelector('[data-bm-motors-auto="listing"]');
                    if ( ! wrap ) return;
                    var target = document.querySelector('.elementor-widget-motors-single-listing-dealer-email')
                              || document.querySelector('.elementor-widget-motors-single-listing-dealer-phone')
                              || document.querySelector('.elementor-widget-motors-single-listing-user-data-simple')
                              || document.querySelector('.stm-listing-car-dealer-info');
                    if ( ! target ) return;
                    var elementorWidget = target.classList.contains('elementor-widget-motors-single-listing-dealer-email')
                                       || target.classList.contains('elementor-widget-motors-single-listing-dealer-phone')
                                       || target.classList.contains('elementor-widget-motors-single-listing-user-data-simple');
                    if ( elementorWidget ) {
                        target.parentNode.insertBefore(wrap, target.nextSibling);
                    } else {
                        target.appendChild(wrap);
                    }
                    wrap.removeAttribute('hidden');
                });
            </script>
            <?php
        }

        public function render_dealer_profile_button_auto()
        {
            if ( ! is_author() ) return;

            $user_id = (int) get_queried_object_id();
            if ( ! $this->can_render_message_button( $user_id ) ) return;

            $has_listings = get_posts( array(
                'post_type'      => $this->get_listing_post_types(),
                'author'         => $user_id,
                'posts_per_page' => 1,
                'fields'         => 'ids',
                'no_found_rows'  => true,
                'post_status'    => 'publish',
            ) );
            if ( empty( $has_listings ) ) return;

            $html = $this->dealer_button_shortcode( array( 'user_id' => $user_id ) );
            if ( empty( $html ) ) return;

            echo '<div class="bm-motors-auto-anchor" data-bm-motors-auto="dealer" hidden>' . $html . '</div>';
            ?>
            <script>
                document.addEventListener('DOMContentLoaded', function () {
                    var wrap = document.querySelector('[data-bm-motors-auto="dealer"]');
                    if ( ! wrap ) return;
                    var target = document.querySelector('.stm-user-data-right')
                              || document.querySelector('.stm-user-data')
                              || document.querySelector('.stm-author-public-info');
                    if ( ! target ) return;
                    target.appendChild(wrap);
                    wrap.removeAttribute('hidden');
                });
            </script>
            <?php
        }

        public function render_dashboard_tab_link( $current_page )
        {
            $is_active = ( $current_page === 'better-messages' );
            $url       = esc_url( add_query_arg( 'page', 'better-messages', remove_query_arg( array( 'page', 'become_dealer' ) ) ) );

            printf(
                '<a class="%s" href="%s"><i class="fas fa-comments"></i>%s</a>',
                $is_active ? 'active' : '',
                $url,
                esc_html_x( 'Messages', 'Motors Integration (Dashboard tab)', 'bp-better-messages' )
            );
        }

        public function detect_dashboard_tab( $value )
        {
            if ( ! is_author() ) return $value;
            if ( isset( $_GET['page'] ) && sanitize_key( wp_unslash( $_GET['page'] ) ) === 'better-messages' ) {
                return 'better-messages';
            }
            return $value;
        }

        public function listing_button_shortcode( $atts = array() )
        {
            $atts = shortcode_atts( array(
                'listing_id' => 0,
                'class'      => '',
                'text'       => '',
            ), $atts, 'better_messages_motors_listing_button' );

            $listing_id = (int) ( $atts['listing_id'] ?: get_queried_object_id() ?: get_the_ID() );
            if ( ! $listing_id ) return '';
            if ( ! in_array( get_post_type( $listing_id ), $this->get_listing_post_types(), true ) ) return '';

            $author_id = (int) get_post_field( 'post_author', $listing_id );
            if ( ! $this->can_render_message_button( $author_id ) ) return '';

            $subject = sprintf(
                _x( 'Question about listing "%s"', 'Motors Integration (Listing button)', 'bp-better-messages' ),
                get_the_title( $listing_id )
            );

            $html = $this->render_live_chat_button( array(
                'class'      => $atts['class'] ?: 'bm-motors-btn bm-motors-btn-primary bm-motors-listing-btn',
                'text'       => $atts['text'] ?: esc_attr_x( 'Live Chat', 'Motors Integration (Listing button)', 'bp-better-messages' ),
                'user_id'    => $author_id,
                'unique_tag' => 'motors_listing_chat_' . $listing_id,
                'subject'    => esc_attr( $subject ),
            ) );

            if ( empty( $html ) ) return '';

            return '<div class="bm-motors-listing-button-wrap">' . $html . '</div>';
        }

        public function dealer_button_shortcode( $atts = array() )
        {
            $atts = shortcode_atts( array(
                'user_id' => 0,
                'class'   => '',
                'text'    => '',
            ), $atts, 'better_messages_motors_dealer_button' );

            $user_id = (int) $atts['user_id'];
            if ( ! $user_id && is_author() ) {
                $user_id = (int) get_queried_object_id();
            }
            if ( ! $this->can_render_message_button( $user_id ) ) return '';

            $user = get_userdata( $user_id );
            $name = $user ? $user->display_name : '';

            $subject = $name
                ? sprintf( _x( 'Send a message to %s', 'Motors Integration (Dealer button)', 'bp-better-messages' ), $name )
                : _x( 'Send a message', 'Motors Integration (Dealer button)', 'bp-better-messages' );

            $html = $this->render_live_chat_button( array(
                'class'      => $atts['class'] ?: 'bm-motors-btn bm-motors-btn-primary bm-motors-dealer-btn',
                'text'       => $atts['text'] ?: esc_attr_x( 'Live Chat', 'Motors Integration (Dealer button)', 'bp-better-messages' ),
                'user_id'    => $user_id,
                'unique_tag' => 'motors_dealer_chat_' . $user_id,
                'subject'    => esc_attr( $subject ),
            ) );

            if ( empty( $html ) ) return '';

            return '<div class="bm-motors-dealer-button-wrap">' . $html . '</div>';
        }

        public function user_item( $item, $user_id, $include_personal )
        {
            if ( $user_id <= 0 ) return $item;

            $url = apply_filters( 'stm_get_author_link', $user_id );
            if ( ! empty( $url ) && is_string( $url ) ) {
                $item['url'] = esc_url( $url );
            }

            return $item;
        }

        public function thread_item( $thread_item, $thread_id, $thread_type, $include_personal, $user_id )
        {
            if ( $thread_type !== 'thread' ) return $thread_item;

            $unique_tag = Better_Messages()->functions->get_thread_meta( $thread_id, 'unique_tag' );
            if ( empty( $unique_tag ) || ! str_starts_with( $unique_tag, 'motors_listing_chat_' ) ) {
                return $thread_item;
            }

            $parts      = explode( '|', $unique_tag );
            $listing_id = (int) str_replace( 'motors_listing_chat_', '', $parts[0] );
            $info       = $this->listing_thread_info_html( $listing_id );

            if ( $info !== '' ) {
                $thread_item['threadInfo'] = ( $thread_item['threadInfo'] ?? '' ) . $info;
            }

            return $thread_item;
        }

        private function listing_thread_info_html( $listing_id )
        {
            $listing_id = (int) $listing_id;
            if ( ! $listing_id ) return '';

            $post = get_post( $listing_id );
            if ( ! $post ) return '';
            if ( ! in_array( $post->post_type, $this->get_listing_post_types(), true ) ) return '';

            $title = esc_html( get_the_title( $listing_id ) );
            $url   = get_permalink( $listing_id );

            $html = '<div class="bm-product-info">';

            $image_id = get_post_thumbnail_id( $listing_id );
            if ( $image_id ) {
                $image_src = wp_get_attachment_image_src( $image_id, array( 100, 100 ) );
                if ( $image_src ) {
                    $html .= '<div class="bm-product-image">';
                    $html .= '<a href="' . esc_url( $url ) . '" target="_blank"><img src="' . esc_url( $image_src[0] ) . '" alt="' . $title . '" /></a>';
                    $html .= '</div>';
                }
            }

            $html .= '<div class="bm-product-details">';
            $html .= '<div class="bm-product-title"><a href="' . esc_url( $url ) . '" target="_blank">' . $title . '</a></div>';

            $price_html = $this->get_listing_price_html( $listing_id );
            if ( $price_html !== '' ) {
                $html .= '<div class="bm-product-price">' . $price_html . '</div>';
            }

            $subtitle = $this->get_listing_subtitle( $listing_id );
            if ( $subtitle !== '' ) {
                $html .= '<div class="bm-product-subtitle">' . esc_html( $subtitle ) . '</div>';
            }

            $html .= '</div>';
            $html .= '</div>';

            return $html;
        }

        private function get_listing_price_html( $listing_id )
        {
            $price         = get_post_meta( $listing_id, 'price', true );
            $regular_price = get_post_meta( $listing_id, 'regular_price', true );

            $format = function ( $value ) {
                if ( $value === '' || $value === null ) return '';
                if ( function_exists( 'motors_vl_currency_position' ) ) {
                    $currency = motors_vl_currency_position( $value );
                    if ( ! empty( $currency ) ) return $currency;
                }
                if ( is_numeric( $value ) ) return '$' . number_format_i18n( (float) $value );
                return esc_html( (string) $value );
            };

            $sale = $format( $price );

            if ( $regular_price !== '' && $regular_price !== null && $regular_price !== $price ) {
                $regular = $format( $regular_price );
                if ( $sale !== '' && $regular !== '' ) {
                    return '<del>' . $regular . '</del> ' . $sale;
                }
            }

            return $sale;
        }

        private function get_listing_subtitle( $listing_id )
        {
            $parts = array();

            $year = wp_get_post_terms( $listing_id, 'ca-year', array( 'fields' => 'names' ) );
            if ( ! is_wp_error( $year ) && ! empty( $year ) ) {
                $parts[] = $year[0];
            }

            $make = wp_get_post_terms( $listing_id, 'make', array( 'fields' => 'names' ) );
            if ( ! is_wp_error( $make ) && ! empty( $make ) ) {
                $parts[] = $make[0];
            }

            $mileage = get_post_meta( $listing_id, 'mileage', true );
            if ( ! empty( $mileage ) ) {
                $parts[] = number_format_i18n( (float) $mileage ) . ' mi';
            }

            return implode( ' • ', $parts );
        }
    }
}
