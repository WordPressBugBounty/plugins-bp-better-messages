<?php
defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Better_Messages_GeoDirectory' ) ) {

    class Better_Messages_GeoDirectory
    {
        public static function instance()
        {
            static $instance = null;

            if ( null === $instance ) {
                $instance = new Better_Messages_GeoDirectory();
            }

            return $instance;
        }

        public function __construct()
        {
            add_shortcode( 'better_messages_geodirectory_listing_button', array( $this, 'listing_button_shortcode' ) );

            if ( Better_Messages()->settings['geodirIntegration'] !== '1' ) return;

            if ( Better_Messages()->settings['geodirSingleListingButton'] === '1' ) {
                add_action( 'wp_footer', array( $this, 'render_single_listing_button' ), 5 );
            }

            add_filter( 'better_messages_rest_thread_item', array( $this, 'thread_item' ), 10, 5 );
            add_filter( 'better_messages_rest_user_item', array( $this, 'user_meta' ), 20, 3 );
        }

        public function listing_button_shortcode( $atts = array() )
        {
            $atts = shortcode_atts( array(
                'listing_id' => 0,
                'user_id'    => 0,
                'class'      => '',
                'text'       => '',
            ), $atts, 'better_messages_geodirectory_listing_button' );

            $listing_id = (int) ( $atts['listing_id'] ?: get_queried_object_id() ?: get_the_ID() );
            if ( ! $listing_id || ! $this->is_geodir_post( $listing_id ) ) return '';

            $author_id = (int) ( $atts['user_id'] ?: get_post_field( 'post_author', $listing_id ) );
            if ( ! $this->can_render_message_button( $author_id ) ) return '';

            $subject = sprintf(
                _x( 'Question about listing "%s"', 'GeoDirectory Integration (Listing page)', 'bp-better-messages' ),
                get_the_title( $listing_id )
            );

            $html = $this->render_live_chat_button( array(
                'class'      => $atts['class'] ?: 'geodir-bm-btn geodir-bm-btn-listing btn btn-primary',
                'text'       => $atts['text'] ?: esc_attr_x( 'Send Message', 'GeoDirectory Integration (Listing page)', 'bp-better-messages' ),
                'user_id'    => $author_id,
                'unique_tag' => 'geodir_listing_chat_' . $listing_id,
                'subject'    => esc_attr( $subject ),
            ) );

            if ( empty( $html ) ) return '';

            return '<div class="bm-geodir-listing-button-wrap">' . $html . '</div>';
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
                'class'      => 'geodir-bm-btn',
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

        private function is_geodir_post( $post_id )
        {
            $post_id = (int) $post_id;
            if ( ! $post_id ) return false;
            if ( ! function_exists( 'geodir_get_posttypes' ) ) return false;

            $post_type = get_post_type( $post_id );
            return in_array( $post_type, geodir_get_posttypes(), true );
        }

        public function render_single_listing_button()
        {
            if ( ! function_exists( 'geodir_is_page' ) || ! geodir_is_page( 'single' ) ) return;

            $listing_id = (int) get_queried_object_id();
            if ( ! $listing_id ) return;
            if ( ! $this->is_geodir_post( $listing_id ) ) return;
            if ( get_post_status( $listing_id ) !== 'publish' ) return;

            $author_id = (int) get_post_field( 'post_author', $listing_id );
            if ( ! $this->can_render_message_button( $author_id ) ) return;

            $subject = sprintf(
                _x( 'Question about listing "%s"', 'GeoDirectory Integration (Listing page)', 'bp-better-messages' ),
                get_the_title( $listing_id )
            );

            $html = $this->render_live_chat_button( array(
                'class'      => 'geodir-bm-btn geodir-bm-btn-listing btn btn-primary',
                'text'       => esc_attr_x( 'Send Message', 'GeoDirectory Integration (Listing page)', 'bp-better-messages' ),
                'user_id'    => $author_id,
                'unique_tag' => 'geodir_listing_chat_' . $listing_id,
                'subject'    => esc_attr( $subject ),
            ) );

            if ( empty( $html ) ) return;

            echo '<div class="bm-geodir-listing-button-wrap" data-bm-geodir-relocate hidden style="margin-top:10px;">' . $html . '</div>';
            echo "<script>document.addEventListener('DOMContentLoaded',function(){var w=document.querySelector('[data-bm-geodir-relocate]');if(!w)return;var t=document.querySelector('.gd-author-actions')||document.querySelector('.geodir-detail-page-tools')||document.querySelector('.geodir_post_meta');if(t&&t.parentNode){t.parentNode.insertBefore(w,t.nextSibling);w.removeAttribute('hidden');return;}var title=document.querySelector('.entry-title, .geodir-page-title, h1');if(title&&title.parentNode){title.parentNode.insertBefore(w,title.nextSibling);w.removeAttribute('hidden');}});</script>";
        }

        public function user_meta( $item, $user_id, $include_personal )
        {
            if ( $user_id <= 0 ) return $item;

            $url = get_author_posts_url( $user_id );
            if ( ! empty( $url ) ) {
                $item['url'] = esc_url( $url );
            }

            return $item;
        }

        public function thread_item( $thread_item, $thread_id, $thread_type, $include_personal, $user_id )
        {
            if ( $thread_type !== 'thread' ) return $thread_item;

            $unique_tag = Better_Messages()->functions->get_thread_meta( $thread_id, 'unique_tag' );
            if ( empty( $unique_tag ) || ! str_starts_with( $unique_tag, 'geodir_listing_chat_' ) ) {
                return $thread_item;
            }

            $parts      = explode( '|', $unique_tag );
            $listing_id = (int) str_replace( 'geodir_listing_chat_', '', $parts[0] );
            $info       = $this->listing_thread_info_html( $listing_id );

            if ( $info !== '' ) {
                $thread_item['threadInfo'] = ( $thread_item['threadInfo'] ?? '' ) . $info;
            }

            return $thread_item;
        }

        private function listing_image_url( $listing_id )
        {
            $image_id = get_post_thumbnail_id( $listing_id );
            if ( $image_id ) {
                $src = wp_get_attachment_image_src( $image_id, array( 100, 100 ) );
                if ( $src ) return $src[0];
            }

            if ( class_exists( 'GeoDir_Media' ) ) {
                $images = GeoDir_Media::get_post_images( $listing_id, 1 );
                if ( ! empty( $images[0]->file ) ) {
                    $upload = wp_upload_dir();
                    return $upload['baseurl'] . $images[0]->file;
                }
            }

            return '';
        }

        private function listing_thread_info_html( $listing_id )
        {
            $listing_id = (int) $listing_id;
            if ( ! $listing_id ) return '';

            $post = get_post( $listing_id );
            if ( ! $post ) return '';
            if ( ! $this->is_geodir_post( $listing_id ) ) return '';

            $title = esc_html( get_the_title( $listing_id ) );
            $url   = get_permalink( $listing_id );

            $html = '<div class="bm-product-info">';

            $image_url = $this->listing_image_url( $listing_id );
            if ( $image_url !== '' ) {
                $html .= '<div class="bm-product-image">';
                $html .= '<a href="' . esc_url( $url ) . '" target="_blank"><img src="' . esc_url( $image_url ) . '" alt="' . $title . '" /></a>';
                $html .= '</div>';
            }

            $html .= '<div class="bm-product-details">';
            $html .= '<div class="bm-product-title"><a href="' . esc_url( $url ) . '" target="_blank">' . $title . '</a></div>';

            $price = get_post_meta( $listing_id, 'price', true );
            if ( ! empty( $price ) ) {
                $html .= '<div class="bm-product-price">' . esc_html( $price ) . '</div>';
            }

            $address = get_post_meta( $listing_id, 'address', true );
            if ( ! empty( $address ) ) {
                $html .= '<div class="bm-product-subtitle">' . esc_html( $address ) . '</div>';
            }

            $html .= '</div>';
            $html .= '</div>';

            return $html;
        }
    }
}
