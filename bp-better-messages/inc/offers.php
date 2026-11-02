<?php
defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Better_Messages_Offers' ) ) {

    class Better_Messages_Offers
    {
        const LIVE_SUPPORT = false;

        const DISMISSED_META = 'better_messages_dismissed_offers';

        public static function instance()
        {
            static $instance = null;

            if ( null === $instance ) {
                $instance = new Better_Messages_Offers();
            }

            return $instance;
        }

        public function __construct()
        {
            add_action( 'rest_api_init', array( $this, 'rest_api_init' ) );
        }

        public static function promotion()
        {
            if ( ! self::LIVE_SUPPORT ) {
                return null;
            }

            return null;
        }

        public function rest_api_init()
        {
            register_rest_route( 'better-messages/v1/admin', '/tabOffers', array(
                'methods'             => 'GET',
                'callback'            => array( $this, 'rest_offers' ),
                'permission_callback' => array( $this, 'user_is_admin' ),
            ) );

            register_rest_route( 'better-messages/v1/admin', '/tabOffers/dismiss', array(
                'methods'             => 'POST',
                'callback'            => array( $this, 'rest_dismiss' ),
                'permission_callback' => array( $this, 'user_is_admin' ),
                'args'                => array(
                    'key' => array( 'required' => true, 'type' => 'string', 'pattern' => '^[A-Za-z0-9_\-]+$' ),
                ),
            ) );
        }

        public function user_is_admin()
        {
            return current_user_can( 'manage_options' );
        }

        public function admin_offers( $user_id = null )
        {
            if ( $user_id === null ) {
                $user_id = get_current_user_id();
            }

            $offers = array();
            $keys   = array();

            foreach ( (array) apply_filters( 'better_messages_tab_offers', array() ) as $offer ) {
                $offer = self::sanitize_offer( $offer );

                if ( $offer === null || isset( $keys[ $offer['key'] ] ) ) {
                    continue;
                }

                $keys[ $offer['key'] ] = true;
                $offers[]              = $offer;
            }

            $promotion = self::sanitize_promotion( self::promotion() );

            if ( $promotion !== null && ! isset( $keys[ $promotion['key'] ] ) && ! in_array( $promotion['key'], $this->dismissed( $user_id ), true ) ) {
                $offers[] = $promotion;
            }

            return $offers;
        }

        public function rest_offers()
        {
            return rest_ensure_response( $this->admin_offers() );
        }

        public function rest_dismiss( WP_REST_Request $request )
        {
            $user_id   = get_current_user_id();
            $dismissed = $this->dismissed( $user_id );
            $key       = (string) $request->get_param( 'key' );

            if ( ! in_array( $key, $dismissed, true ) ) {
                $dismissed[] = $key;
                update_user_meta( $user_id, self::DISMISSED_META, array_slice( $dismissed, -20 ) );
            }

            return rest_ensure_response( $this->admin_offers( $user_id ) );
        }

        private function dismissed( $user_id )
        {
            $dismissed = get_user_meta( (int) $user_id, self::DISMISSED_META, true );

            return is_array( $dismissed ) ? array_values( array_filter( $dismissed, 'is_string' ) ) : array();
        }

        public static function sanitize_offer( $offer )
        {
            if ( ! is_array( $offer ) ) {
                return null;
            }

            $key    = (string) ( $offer['key'] ?? '' );
            $plugin = sanitize_text_field( (string) ( $offer['plugin'] ?? '' ) );
            $label  = sanitize_text_field( (string) ( $offer['label'] ?? '' ) );

            if ( ! preg_match( '/^[A-Za-z0-9_\-]+$/', $key ) || $plugin === '' || $label === '' ) {
                return null;
            }

            $on      = ! empty( $offer['on'] );
            $enable  = self::sanitize_call( $offer['enable'] ?? null );
            $dismiss = self::sanitize_call( $offer['dismiss'] ?? null );

            if ( ! $on && $enable === null ) {
                return null;
            }

            $bars = array_values( array_intersect( Better_Messages_Navigation::BARS, array_map( 'strval', (array) ( $offer['bars'] ?? Better_Messages_Navigation::BARS ) ) ) );

            return array(
                'key'          => $key,
                'state'        => $on ? 'on' : 'off',
                'plugin'       => $plugin,
                'label'        => $label,
                'icon'         => esc_url_raw( (string) ( $offer['icon'] ?? '' ), array( 'http', 'https' ) ),
                'text'         => sanitize_text_field( (string) ( $offer['text'] ?? '' ) ),
                'on'           => $on,
                'bars'         => $bars,
                'settings_url' => esc_url_raw( (string) ( $offer['settings_url'] ?? '' ), array( 'http', 'https' ) ),
                'enable'       => $enable,
                'dismiss'      => $dismiss,
            );
        }

        private static function sanitize_call( $call )
        {
            if ( ! is_array( $call ) ) {
                return null;
            }

            $url = esc_url_raw( (string) ( $call['url'] ?? '' ), array( 'http', 'https' ) );

            if ( $url === '' || strpos( $url, rest_url() ) !== 0 ) {
                return null;
            }

            return array(
                'url'  => $url,
                'data' => isset( $call['data'] ) && is_array( $call['data'] ) ? $call['data'] : array(),
            );
        }

        private static function sanitize_promotion( $promotion )
        {
            if ( ! is_array( $promotion ) ) {
                return null;
            }

            $key    = (string) ( $promotion['key'] ?? '' );
            $plugin = sanitize_text_field( (string) ( $promotion['plugin'] ?? '' ) );
            $title  = sanitize_text_field( (string) ( $promotion['title'] ?? '' ) );
            $button = sanitize_text_field( (string) ( $promotion['button'] ?? '' ) );
            $url    = esc_url_raw( (string) ( $promotion['url'] ?? '' ), array( 'http', 'https' ) );

            if ( ! preg_match( '/^[A-Za-z0-9_\-]+$/', $key ) || $plugin === '' || $title === '' || $button === '' || $url === '' ) {
                return null;
            }

            return array(
                'key'     => $key,
                'state'   => 'install',
                'plugin'  => $plugin,
                'title'   => $title,
                'text'    => sanitize_text_field( (string) ( $promotion['text'] ?? '' ) ),
                'button'  => $button,
                'url'     => $url,
                'icon'    => esc_url_raw( (string) ( $promotion['icon'] ?? '' ), array( 'http', 'https' ) ),
            );
        }
    }

    function Better_Messages_Offers()
    {
        return Better_Messages_Offers::instance();
    }
}
