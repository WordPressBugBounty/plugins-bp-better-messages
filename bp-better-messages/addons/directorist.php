<?php
defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Better_Messages_Directorist' ) ) {

    class Better_Messages_Directorist
    {
        public static function instance()
        {
            static $instance = null;

            if ( null === $instance ) {
                $instance = new Better_Messages_Directorist();
            }

            return $instance;
        }

        public function __construct()
        {
            if ( Better_Messages()->settings['directoristIntegration'] !== '1' ) return;

            if ( Better_Messages()->settings['directoristListingPageButton'] === '1' ) {
                add_action( 'wp_footer', array( $this, 'render_listing_page_button' ), 5 );
            }

            if ( Better_Messages()->settings['directoristListingCardButton'] === '1' ) {
                add_action( 'directorist_loop_grid_info_after_excerpt', array( $this, 'render_listing_card_button' ) );
                add_action( 'directorist_loop_list_info_after_excerpt', array( $this, 'render_listing_card_button' ) );
            }

            if ( Better_Messages()->settings['directoristAuthorProfileButton'] === '1' ) {
                add_action( 'directorist_before_author_profile_section', array( $this, 'render_author_profile_button' ) );
            }

            if ( Better_Messages()->settings['directoristDashboardTab'] === '1' || Better_Messages()->settings['chatPage'] === 'directorist-dashboard' ) {
                add_filter( 'directorist_dashboard_tabs', array( $this, 'add_dashboard_messages_tab' ), 20 );
            }

            add_filter( 'better_messages_rest_thread_item', array( $this, 'thread_item' ), 10, 5 );
            add_filter( 'better_messages_rest_user_item', array( $this, 'user_meta' ), 20, 3 );
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
                'class'      => 'directorist-btn directorist-btn-light directorist-btn-md',
                'text'       => '',
                'user_id'    => 0,
                'unique_tag' => '',
                'subject'    => '',
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

        public function render_listing_page_button()
        {
            if ( ! is_singular( ATBDP_POST_TYPE ) ) return;

            $listing_id = (int) get_queried_object_id();
            if ( ! $listing_id ) return;
            if ( get_post_status( $listing_id ) !== 'publish' ) return;

            $author_id = (int) get_post_field( 'post_author', $listing_id );
            if ( ! $this->can_render_message_button( $author_id ) ) return;

            $subject = sprintf(
                _x( 'Question about listing "%s"', 'Directorist Integration (Listing page)', 'bp-better-messages' ),
                get_the_title( $listing_id )
            );

            $html = $this->render_live_chat_button( array(
                'class'      => 'directorist-btn directorist-btn-primary directorist-btn-md bm-directorist-listing-btn',
                'text'       => esc_attr_x( 'Send Message', 'Directorist Integration (Listing page)', 'bp-better-messages' ),
                'user_id'    => $author_id,
                'unique_tag' => 'directorist_listing_chat_' . $listing_id,
                'subject'    => esc_attr( $subject ),
            ) );

            if ( empty( $html ) ) return;

            echo '<div class="bm-directorist-listing-button-wrap" data-bm-listing-relocate hidden style="margin-top:10px;">' . $html . '</div>';
            echo "<script>document.addEventListener('DOMContentLoaded',function(){var w=document.querySelector('[data-bm-listing-relocate]');if(!w)return;var view=document.querySelector('.directorist-card-author-info .diretorist-view-profile-btn');if(view&&view.parentNode){view.parentNode.insertBefore(w,view.nextSibling);w.removeAttribute('hidden');}});</script>";
        }

        public function render_listing_card_button( $listings = null )
        {
            $listing_id = 0;
            $author_id  = 0;

            if ( is_object( $listings ) && isset( $listings->loop ) && is_array( $listings->loop ) ) {
                $listing_id = (int) ( $listings->loop['id'] ?? 0 );
                $author_id  = (int) ( $listings->loop['author_id'] ?? 0 );
            }

            if ( ! $listing_id ) {
                $listing_id = (int) get_the_ID();
            }
            if ( ! $author_id ) {
                $author_id = (int) get_post_field( 'post_author', $listing_id );
            }

            if ( ! $listing_id || get_post_type( $listing_id ) !== ATBDP_POST_TYPE ) return;
            if ( get_post_status( $listing_id ) !== 'publish' ) return;
            if ( ! $this->can_render_message_button( $author_id ) ) return;

            $subject = sprintf(
                _x( 'Question about listing "%s"', 'Directorist Integration (Archive card)', 'bp-better-messages' ),
                get_the_title( $listing_id )
            );

            $btn_label = esc_attr_x( 'Send Message', 'Directorist Integration (Archive card)', 'bp-better-messages' );

            $html = $this->render_live_chat_button( array(
                'type'       => 'link',
                'class'      => 'directorist-btn directorist-btn-light directorist-btn-sm bm-directorist-card-btn',
                'alt'        => $btn_label,
                'text'       => $btn_label,
                'user_id'    => $author_id,
                'unique_tag' => 'directorist_listing_chat_' . $listing_id,
                'subject'    => esc_attr( $subject ),
            ) );

            if ( ! empty( $html ) ) {
                echo '<div class="bm-directorist-card-button-wrap">' . $html . '</div>';
            }
        }

        public function render_author_profile_button()
        {
            $author_id = (int) get_query_var( 'author_id' );

            if ( ! $author_id ) {
                $login = get_query_var( 'author_id' );
                if ( is_string( $login ) && $login !== '' ) {
                    $user = get_user_by( 'login', $login );
                    if ( $user ) {
                        $author_id = (int) $user->ID;
                    }
                }
            }

            if ( ! $this->can_render_message_button( $author_id ) ) return;

            $author = get_userdata( $author_id );
            $name   = $author ? $author->display_name : '';

            $subject = $name
                ? sprintf( _x( 'Send a message to %s', 'Directorist Integration (Author profile)', 'bp-better-messages' ), $name )
                : _x( 'Send a message', 'Directorist Integration (Author profile)', 'bp-better-messages' );

            $html = $this->render_live_chat_button( array(
                'class'      => 'directorist-btn directorist-btn-primary directorist-btn-sm bm-directorist-author-btn',
                'text'       => esc_attr_x( 'Send Message', 'Directorist Integration (Author profile)', 'bp-better-messages' ),
                'user_id'    => $author_id,
                'unique_tag' => 'directorist_author_chat_' . $author_id,
                'subject'    => esc_attr( $subject ),
            ) );

            if ( ! empty( $html ) ) {
                echo '<div class="bm-directorist-author-button-wrap" data-bm-author-relocate hidden style="margin-top:12px;">' . $html . '</div>';
                echo "<script>document.addEventListener('DOMContentLoaded',function(){var w=document.querySelector('[data-bm-author-relocate]');if(!w)return;var info=document.querySelector('.directorist-author-avatar__info');if(info){info.appendChild(w);w.removeAttribute('hidden');}});</script>";
            }
        }

        public function add_dashboard_messages_tab( $tabs )
        {
            if ( ! is_array( $tabs ) ) {
                return $tabs;
            }

            $tabs['bm_messages'] = array(
                'title'   => _x( 'Messages', 'Directorist Integration (Dashboard tab)', 'bp-better-messages' ),
                'icon'    => 'las la-comments',
                'content' => '<div class="bm-directorist-dashboard-messages">' . do_shortcode( '[better_messages]' ) . '</div>',
            );

            return $tabs;
        }

        public function user_meta( $item, $user_id, $include_personal )
        {
            if ( $user_id <= 0 ) return $item;

            if ( class_exists( 'ATBDP_Permalink' ) ) {
                $url = ATBDP_Permalink::get_user_profile_page_link( $user_id );
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
            if ( empty( $unique_tag ) || ! str_starts_with( $unique_tag, 'directorist_listing_chat_' ) ) {
                return $thread_item;
            }

            $parts      = explode( '|', $unique_tag );
            $listing_id = (int) str_replace( 'directorist_listing_chat_', '', $parts[0] );
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
            if ( ! $post || $post->post_type !== ATBDP_POST_TYPE ) return '';

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

            $address = get_post_meta( $listing_id, '_address', true );
            if ( ! empty( $address ) ) {
                $html .= '<div class="bm-product-subtitle">' . esc_html( $address ) . '</div>';
            }

            $html .= '</div>';
            $html .= '</div>';

            return $html;
        }

        private function get_listing_price_html( $listing_id )
        {
            $price       = get_post_meta( $listing_id, '_price', true );
            $price_range = get_post_meta( $listing_id, '_price_range', true );
            $pricing     = get_post_meta( $listing_id, '_atbd_listing_pricing', true );

            if ( $pricing === 'range' && ! empty( $price_range ) && function_exists( 'atbdp_display_price_range' ) ) {
                return (string) atbdp_display_price_range( $price_range );
            }

            if ( ! empty( $price ) && function_exists( 'atbdp_display_price' ) ) {
                return (string) atbdp_display_price( $price, false, '', '', '', false );
            }

            if ( ! empty( $price ) ) {
                return esc_html( $price );
            }

            return '';
        }
    }
}
