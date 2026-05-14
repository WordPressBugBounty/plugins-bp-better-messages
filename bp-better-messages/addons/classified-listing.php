<?php
defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Better_Messages_Classified_Listing' ) ) {

    class Better_Messages_Classified_Listing
    {
        public static function instance()
        {
            static $instance = null;

            if ( null === $instance ) {
                $instance = new Better_Messages_Classified_Listing();
            }

            return $instance;
        }

        public function __construct()
        {
            add_shortcode( 'better_messages_classified_listing_button',        array( $this, 'listing_button_shortcode' ) );
            add_shortcode( 'better_messages_classified_listing_author_button', array( $this, 'author_button_shortcode' ) );
            add_shortcode( 'better_messages_classified_listing_header_button', array( $this, 'header_button_shortcode' ) );

            if ( Better_Messages()->settings['classifiedListingIntegration'] !== '1' ) return;

            if ( Better_Messages()->settings['classifiedListingListingPageButton'] === '1' ) {
                add_action( 'wp_footer', array( $this, 'render_listing_page_button' ), 5 );
            }

            if ( Better_Messages()->settings['classifiedListingListingCardButton'] === '1' ) {
                add_action( 'rtcl_listing_loop_item_end', array( $this, 'render_listing_card_button' ) );
            }

            if ( Better_Messages()->settings['classifiedListingAuthorProfileButton'] === '1' ) {
                add_action( 'rtcl_author_after_info',              array( $this, 'render_author_profile_button' ) );
                add_action( 'rtcl_store_after_seller_information', array( $this, 'render_author_profile_button' ) );
            }

            if ( Better_Messages()->settings['classifiedListingHeaderButton'] === '1' ) {
                add_action( 'wp_footer', array( $this, 'render_header_button' ), 5 );
            }

            if ( Better_Messages()->settings['classifiedListingDashboardTab'] === '1' || Better_Messages()->settings['chatPage'] === 'classified-listing-dashboard' ) {
                add_filter( 'rtcl_my_account_endpoint',              array( $this, 'register_messages_endpoint' ), 20 );
                add_filter( 'rtcl_account_default_menu_items',       array( $this, 'add_dashboard_messages_tab' ), 20 );
                add_action( 'rtcl_account_better-messages_endpoint', array( $this, 'render_dashboard_messages_content' ) );
            }

            add_action( 'wp_head', array( $this, 'inline_styles' ), 99 );
            add_action( 'wp_footer', array( $this, 'unread_counter_javascript' ), 99 );

            add_filter( 'better_messages_rest_thread_item', array( $this, 'thread_item' ), 10, 5 );
            add_filter( 'better_messages_rest_user_item',   array( $this, 'user_meta' ), 20, 3 );
        }

        private function listing_post_type()
        {
            if ( function_exists( 'rtcl' ) && isset( rtcl()->post_type ) ) {
                return rtcl()->post_type;
            }

            return 'rtcl_listing';
        }

        private function can_render_message_button( $target_user_id )
        {
            $target_user_id = (int) $target_user_id;
            if ( $target_user_id <= 0 ) return false;
            if ( $target_user_id === (int) Better_Messages()->functions->get_current_user_id() ) return false;

            return true;
        }

        private function render_native_chat_anchor( $user_id, $unique_tag, $subject, $text )
        {
            $user_id = (int) $user_id;
            if ( $user_id <= 0 ) return '';
            if ( ! Better_Messages()->functions->is_user_exists( $user_id ) ) return '';

            $link = '#';
            if ( ! is_user_logged_in() && ! Better_Messages()->guests->guest_access_enabled() ) {
                $link = Better_Messages()->functions->get_link( Better_Messages()->functions->get_current_user_id() );
            }

            Better_Messages()->enqueue_css();

            $attrs  = ' data-user-id="' . $user_id . '"';
            $attrs .= ' data-bm-unique-key="' . esc_attr( $unique_tag ) . '"';
            if ( ! empty( $subject ) ) {
                $attrs .= ' data-bm-subject="' . esc_attr( $subject ) . '"';
            }

            return '<a class="rtcl-chat-link bm-lc-button bm-rtcl-listing-btn" href="' . esc_url( $link ) . '"' . $attrs . '><i class="fa fa-comments" aria-hidden="true"></i> ' . esc_html( $text ) . '</a>';
        }

        private function render_live_chat_button( array $args )
        {
            $defaults = array(
                'class'      => 'rtcl-btn rtcl-btn-primary',
                'text'       => '',
                'user_id'    => 0,
                'unique_tag' => '',
                'subject'    => '',
                'alt'        => '',
            );
            $args = array_merge( $defaults, $args );

            $shortcode  = '[better_messages_live_chat_button';
            $shortcode .= ' type="link"';
            $shortcode .= ' class="' . esc_attr( $args['class'] ) . '"';
            $shortcode .= ' text="' . esc_attr( Better_Messages()->shortcodes->esc_brackets( $args['text'] ) ) . '"';
            $shortcode .= ' user_id="' . (int) $args['user_id'] . '"';
            $shortcode .= ' unique_tag="' . esc_attr( $args['unique_tag'] ) . '"';
            if ( ! empty( $args['subject'] ) ) {
                $shortcode .= ' subject="' . esc_attr( Better_Messages()->shortcodes->esc_brackets( $args['subject'] ) ) . '"';
            }
            if ( ! empty( $args['alt'] ) ) {
                $shortcode .= ' alt="' . esc_attr( Better_Messages()->shortcodes->esc_brackets( $args['alt'] ) ) . '"';
            }
            $shortcode .= ']';

            return do_shortcode( $shortcode );
        }

        private function resolve_author_id_from_query( $fallback = 0 )
        {
            $author_id = (int) get_query_var( 'author' );

            if ( ! $author_id ) {
                $maybe = (int) get_query_var( 'author_id' );
                if ( $maybe > 0 ) $author_id = $maybe;
            }

            if ( ! $author_id && is_author() ) {
                $queried = get_queried_object();
                if ( $queried && isset( $queried->ID ) ) {
                    $author_id = (int) $queried->ID;
                }
            }

            return $author_id ?: (int) $fallback;
        }

        public function render_listing_page_button()
        {
            if ( ! is_singular( $this->listing_post_type() ) ) return;

            $listing_id = (int) get_queried_object_id();
            if ( ! $listing_id ) return;
            if ( get_post_status( $listing_id ) !== 'publish' ) return;

            $author_id = (int) get_post_field( 'post_author', $listing_id );
            if ( ! $this->can_render_message_button( $author_id ) ) return;

            $subject = sprintf(
                _x( 'Question about listing "%s"', 'Classified Listing Integration (Listing page)', 'bp-better-messages' ),
                get_the_title( $listing_id )
            );

            $btn_label = _x( 'Live Chat', 'Classified Listing Integration (Listing page)', 'bp-better-messages' );

            $html = $this->render_native_chat_anchor( $author_id, 'rtcl_listing_chat_' . $listing_id, $subject, $btn_label );

            if ( empty( $html ) ) return;

            echo '<template data-bm-rtcl-template>' . '<div class="media rtin-chat bm-rtcl-chat">' . $html . '</div>' . '</template>';
            echo "<script>document.addEventListener('DOMContentLoaded',function(){var tpl=document.querySelector('[data-bm-rtcl-template]');if(!tpl)return;var html=tpl.innerHTML;var proChats=document.querySelectorAll('.rtin-chat:not(.bm-rtcl-chat)');var inserted=false;proChats.forEach(function(pc){if(!pc.parentNode)return;var clone=document.createElement('div');clone.innerHTML=html;var node=clone.firstElementChild;pc.parentNode.insertBefore(node,pc.nextSibling);inserted=true;});if(!inserted){var boxes=document.querySelectorAll('.classified-seller-info .rtin-box, .rtcl-seller-information .rtin-box');boxes.forEach(function(b){var clone=document.createElement('div');clone.innerHTML=html;b.appendChild(clone.firstElementChild);});}});</script>";
        }

        public function render_listing_card_button()
        {
            $listing_id = (int) get_the_ID();
            if ( ! $listing_id || get_post_type( $listing_id ) !== $this->listing_post_type() ) return;
            if ( get_post_status( $listing_id ) !== 'publish' ) return;

            $author_id = (int) get_post_field( 'post_author', $listing_id );
            if ( ! $this->can_render_message_button( $author_id ) ) return;

            $subject = sprintf(
                _x( 'Question about listing "%s"', 'Classified Listing Integration (Archive card)', 'bp-better-messages' ),
                get_the_title( $listing_id )
            );

            $btn_label = _x( 'Live Chat', 'Classified Listing Integration (Archive card)', 'bp-better-messages' );

            $html = $this->render_live_chat_button( array(
                'class'      => 'rtcl-btn rtcl-btn-light bm-rtcl-card-btn',
                'alt'        => $btn_label,
                'text'       => $btn_label,
                'user_id'    => $author_id,
                'unique_tag' => 'rtcl_listing_chat_' . $listing_id,
                'subject'    => $subject,
            ) );

            if ( ! empty( $html ) ) {
                echo '<div class="bm-rtcl-card-button-wrap">' . $html . '</div>';
            }
        }

        public function render_author_profile_button()
        {
            $author_id = $this->resolve_author_id_from_query();
            if ( ! $this->can_render_message_button( $author_id ) ) return;

            $html = $this->build_author_button( $author_id, 'rtcl-btn rtcl-btn-primary bm-rtcl-author-btn' );

            if ( ! empty( $html ) ) {
                echo '<div class="bm-rtcl-author-button-wrap">' . $html . '</div>';
            }
        }

        private function build_author_button( $author_id, $class, $text_override = '' )
        {
            $author = get_userdata( $author_id );
            $name   = $author ? $author->display_name : '';

            $subject = $name
                ? sprintf( _x( 'Send a message to %s', 'Classified Listing Integration (Author profile)', 'bp-better-messages' ), $name )
                : _x( 'Send a message', 'Classified Listing Integration (Author profile)', 'bp-better-messages' );

            $btn_label = $text_override ?: _x( 'Live Chat', 'Classified Listing Integration (Author profile)', 'bp-better-messages' );

            return $this->render_live_chat_button( array(
                'class'      => $class,
                'text'       => $btn_label,
                'user_id'    => $author_id,
                'unique_tag' => 'rtcl_author_chat_' . $author_id,
                'subject'    => $subject,
            ) );
        }

        public function render_header_button()
        {
            $html = $this->build_header_button();
            if ( empty( $html ) ) return;

            echo '<template data-bm-rtcl-header-template>' . $html . '</template>';
            echo "<script>document.addEventListener('DOMContentLoaded',function(){var tpl=document.querySelector('[data-bm-rtcl-header-template]');if(!tpl)return;var html=tpl.innerHTML;var containers=document.querySelectorAll('.main-navigation-area, .header-mobile-icons');containers.forEach(function(c){if(c.dataset.bmRtclHeaderPlaced)return;var target=c.querySelector('.header-chat-icon:not(.bm-rtcl-header-chat)')||c.querySelector('.header-login-icon');if(!target)return;var clone=document.createElement('div');clone.innerHTML=html;var node=clone.firstElementChild;if(!node)return;if(c.classList.contains('header-mobile-icons'))node.classList.add('header-chat-icon-mobile');target.parentNode.insertBefore(node,target);c.dataset.bmRtclHeaderPlaced='1';});});</script>";
        }

        private function build_header_button()
        {
            if ( ! is_user_logged_in() && ! Better_Messages()->guests->guest_access_enabled() ) {
                return '';
            }

            $url = '';
            if ( is_user_logged_in() ) {
                $url = Better_Messages()->functions->get_link( Better_Messages()->functions->get_current_user_id() );
            }

            if ( empty( $url ) && Better_Messages()->settings['classifiedListingDashboardTab'] === '1' && class_exists( '\\Rtcl\\Helpers\\Link' ) ) {
                $url = \Rtcl\Helpers\Link::get_account_endpoint_url( 'better-messages' );
            }

            if ( empty( $url ) ) $url = '#';

            $aria = _x( 'Live Chat', 'Classified Listing Integration (Header button)', 'bp-better-messages' );

            Better_Messages()->enqueue_css();

            $html  = '<a class="header-chat-icon bm-rtcl-header-chat" title="' . esc_attr( $aria ) . '" href="' . esc_url( $url ) . '">';
            $html .= '<i class="far fa-comment" aria-hidden="true"></i>';
            $html .= '<span class="rtcl-unread-badge bm-unread-badge" style="display:none;"></span>';
            $html .= '</a>';

            return $html;
        }

        public function listing_button_shortcode( $atts = array() )
        {
            $atts = shortcode_atts( array(
                'listing_id' => 0,
                'user_id'    => 0,
                'class'      => '',
                'text'       => '',
            ), $atts, 'better_messages_classified_listing_button' );

            $listing_id = (int) ( $atts['listing_id'] ?: get_queried_object_id() ?: get_the_ID() );
            if ( ! $listing_id || get_post_type( $listing_id ) !== $this->listing_post_type() ) return '';

            $author_id = (int) ( $atts['user_id'] ?: get_post_field( 'post_author', $listing_id ) );
            if ( ! $this->can_render_message_button( $author_id ) ) return '';

            $subject = sprintf(
                _x( 'Question about listing "%s"', 'Classified Listing Integration (Listing page)', 'bp-better-messages' ),
                get_the_title( $listing_id )
            );

            $html = $this->render_live_chat_button( array(
                'class'      => $atts['class'] ?: 'rtcl-btn rtcl-btn-primary bm-rtcl-listing-btn',
                'text'       => $atts['text'] ?: _x( 'Live Chat', 'Classified Listing Integration (Listing page)', 'bp-better-messages' ),
                'user_id'    => $author_id,
                'unique_tag' => 'rtcl_listing_chat_' . $listing_id,
                'subject'    => $subject,
            ) );

            if ( empty( $html ) ) return '';

            return '<div class="bm-rtcl-listing-button-wrap">' . $html . '</div>';
        }

        public function author_button_shortcode( $atts = array() )
        {
            $atts = shortcode_atts( array(
                'user_id' => 0,
                'class'   => '',
                'text'    => '',
            ), $atts, 'better_messages_classified_listing_author_button' );

            $author_id = (int) $atts['user_id'] ?: $this->resolve_author_id_from_query();
            if ( ! $this->can_render_message_button( $author_id ) ) return '';

            $html = $this->build_author_button(
                $author_id,
                $atts['class'] ?: 'rtcl-btn rtcl-btn-primary bm-rtcl-author-btn',
                $atts['text']
            );

            if ( empty( $html ) ) return '';

            return '<div class="bm-rtcl-author-button-wrap">' . $html . '</div>';
        }

        public function header_button_shortcode( $atts = array() )
        {
            return $this->build_header_button();
        }

        public function unread_counter_javascript()
        {
            if ( ! is_user_logged_in() ) return;

            $initial = 0;
            if ( Better_Messages()->settings['mechanism'] !== 'websocket' ) {
                $initial = (int) Better_Messages()->functions->get_total_threads_for_user( Better_Messages()->functions->get_current_user_id(), 'unread' );
            }
            ?>
<script>
(function(){
    function applyUnread(count){
        var n = parseInt(count, 10) || 0;
        var display = n > 0 ? String(n) : '';
        // Header chat icon badge
        document.querySelectorAll('.bm-rtcl-header-chat .bm-unread-badge').forEach(function(b){
            b.textContent = display;
            b.style.display = n > 0 ? '' : 'none';
        });
        // Sidebar Messages tab badge (inject if missing)
        document.querySelectorAll('.rtcl-MyAccount-navigation-link--better-messages').forEach(function(li){
            li.classList.toggle('rtcl-chat-unread-count', n > 0);
            var badge = li.querySelector('.rtcl-unread-badge.bm-rtcl-sidebar-badge');
            if ( ! badge ) {
                badge = document.createElement('span');
                badge.className = 'rtcl-unread-badge bm-rtcl-sidebar-badge';
                li.appendChild(badge);
            }
            badge.textContent = display;
            badge.style.display = n > 0 ? '' : 'none';
        });
    }
    applyUnread(<?php echo (int) $initial; ?>);
    if (window.wp && wp.hooks && wp.hooks.addAction) {
        wp.hooks.addAction('better_messages_update_unread', 'better_messages_classified_listing', applyUnread);
    }
})();
</script>
            <?php
        }

        public function inline_styles()
        {
            $svg = "data:image/svg+xml,%3Csvg width='20' height='20' viewBox='0 0 24 24' fill='none' xmlns='http://www.w3.org/2000/svg'%3E%3Cpath fill-rule='evenodd' clip-rule='evenodd' d='M20 2H4c-1.1 0-1.99.9-1.99 2L2 22l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2zm0 14H5.17L4 17.17V4h16v12z' fill='%23BEBEBE'/%3E%3C/svg%3E";
            ?>
<style id="bm-rtcl-inline">
.rtcl-MyAccount-wrap .rtcl-MyAccount-navigation ul li.rtcl-MyAccount-navigation-link.rtcl-MyAccount-navigation-link--better-messages a:before{
  -webkit-mask-image:url("<?php echo $svg; ?>");
  mask-image:url("<?php echo $svg; ?>");
  height:20px;
}
a.bm-rtcl-header-chat .bm-unread-badge:empty{ display:none !important; }
.bm-rtcl-listing-button-wrap,
.bm-rtcl-author-button-wrap,
.bm-rtcl-card-button-wrap{
  margin-top:8px;
}
.media.bm-rtcl-chat{
  display:flex;
  align-items:center;
  margin-top:8px;
}
.bm-product-info .bm-product-price,
.bm-product-info .bm-product-price *{
  font-size:inherit !important;
  font-weight:inherit !important;
  line-height:inherit !important;
  color:inherit !important;
  margin:0 !important;
  padding:0 !important;
}
.bm-product-info .bm-product-price .rtcl-price-meta::before{ content:" "; white-space:pre; }
</style>
            <?php
        }

        public function register_messages_endpoint( $endpoints )
        {
            if ( ! is_array( $endpoints ) ) return $endpoints;
            if ( isset( $endpoints['better-messages'] ) ) return $endpoints;

            $endpoints['better-messages'] = 'better-messages';

            return $endpoints;
        }

        public function add_dashboard_messages_tab( $items )
        {
            if ( ! is_array( $items ) ) return $items;

            $new_items = array();
            $inserted  = false;

            foreach ( $items as $key => $label ) {
                $new_items[ $key ] = $label;
                if ( ! $inserted && in_array( $key, array( 'listings', 'favourites' ), true ) ) {
                    $new_items['better-messages'] = _x( 'Messages', 'Classified Listing Integration (Dashboard tab)', 'bp-better-messages' );
                    $inserted = true;
                }
            }

            if ( ! $inserted ) {
                $new_items['better-messages'] = _x( 'Messages', 'Classified Listing Integration (Dashboard tab)', 'bp-better-messages' );
            }

            return $new_items;
        }

        public function render_dashboard_messages_content()
        {
            echo '<div class="bm-rtcl-dashboard-messages">' . do_shortcode( '[better_messages]' ) . '</div>';
        }

        public function user_meta( $item, $user_id, $include_personal )
        {
            if ( $user_id <= 0 ) return $item;

            if ( function_exists( 'rtcl' ) ) {
                $url = get_author_posts_url( $user_id );
                if ( ! empty( $url ) ) {
                    $item['url'] = esc_url( $url );
                }
            }

            return $item;
        }

        public function thread_item( $thread_item, $thread_id, $thread_type, $include_personal, $user_id )
        {
            if ( $thread_type !== 'thread' ) return $thread_item;

            $unique_tag = Better_Messages()->functions->get_thread_meta( $thread_id, 'unique_tag' );
            if ( empty( $unique_tag ) || strpos( $unique_tag, 'rtcl_listing_chat_' ) !== 0 ) {
                return $thread_item;
            }

            $parts      = explode( '|', $unique_tag );
            $listing_id = (int) str_replace( 'rtcl_listing_chat_', '', $parts[0] );
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
            if ( ! $post || $post->post_type !== $this->listing_post_type() ) return '';

            $title = esc_html( get_the_title( $listing_id ) );
            $url   = get_permalink( $listing_id );

            $html = '<div class="bm-product-info">';

            $image_url = $this->get_listing_thumbnail_url( $listing_id );
            if ( $image_url ) {
                $html .= '<div class="bm-product-image">';
                $html .= '<a href="' . esc_url( $url ) . '" target="_blank"><img src="' . esc_url( $image_url ) . '" alt="' . $title . '" /></a>';
                $html .= '</div>';
            }

            $html .= '<div class="bm-product-details">';
            $html .= '<div class="bm-product-title"><a href="' . esc_url( $url ) . '" target="_blank">' . $title . '</a></div>';

            $price_html = $this->get_listing_price_html( $listing_id );
            if ( $price_html !== '' ) {
                $html .= '<div class="bm-product-price">' . $price_html . '</div>';
            }

            $location = $this->get_listing_location_string( $listing_id );
            if ( $location !== '' ) {
                $html .= '<div class="bm-product-subtitle">' . esc_html( $location ) . '</div>';
            }

            $html .= '</div>';
            $html .= '</div>';

            return $html;
        }

        private function get_listing_thumbnail_url( $listing_id )
        {
            if ( function_exists( 'rtcl' ) && class_exists( '\Rtcl\Models\Listing' ) ) {
                try {
                    $listing = new \Rtcl\Models\Listing( $listing_id );
                    if ( method_exists( $listing, 'get_the_thumbnail_url' ) ) {
                        $maybe = $listing->get_the_thumbnail_url( array( 100, 100 ) );
                        if ( ! empty( $maybe ) ) return $maybe;
                    }
                } catch ( \Throwable $e ) {}
            }

            $image_id = get_post_thumbnail_id( $listing_id );
            if ( $image_id ) {
                $image_src = wp_get_attachment_image_src( $image_id, array( 100, 100 ) );
                if ( $image_src ) return $image_src[0];
            }

            return '';
        }

        private function get_listing_price_html( $listing_id )
        {
            $price       = get_post_meta( $listing_id, 'price', true );
            $max_price   = get_post_meta( $listing_id, '_rtcl_max_price', true );
            $pricing     = get_post_meta( $listing_id, '_rtcl_listing_pricing', true );

            if ( function_exists( 'rtcl' ) && class_exists( '\Rtcl\Models\Listing' ) ) {
                try {
                    $listing = new \Rtcl\Models\Listing( $listing_id );
                    if ( method_exists( $listing, 'get_price_html' ) ) {
                        $maybe = $listing->get_price_html();
                        if ( ! empty( $maybe ) ) return $maybe;
                    }
                } catch ( \Throwable $e ) {}
            }

            if ( ! empty( $price ) && $pricing === 'range' && ! empty( $max_price ) ) {
                return esc_html( $price . ' - ' . $max_price );
            }

            if ( ! empty( $price ) ) {
                return esc_html( $price );
            }

            return '';
        }

        private function get_listing_location_string( $listing_id )
        {
            $address = get_post_meta( $listing_id, '_rtcl_geo_address', true );
            if ( ! empty( $address ) ) return $address;

            $terms = wp_get_object_terms( $listing_id, 'rtcl_location', array( 'orderby' => 'parent', 'order' => 'DESC' ) );
            if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) {
                $names = wp_list_pluck( $terms, 'name' );
                return implode( ', ', array_slice( $names, 0, 2 ) );
            }

            return '';
        }
    }
}
