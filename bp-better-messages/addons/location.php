<?php
defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Better_Messages_Location' ) ) {

    class Better_Messages_Location {

        public static function instance() {
            static $instance = null;
            if ( null === $instance ) {
                $instance = new Better_Messages_Location();
            }
            return $instance;
        }

        public function __construct() {
            add_filter( 'better_messages_rest_message_meta', array( $this, 'location_message_meta' ), 10, 4 );
            add_filter( 'bp_better_messages_script_variable', array( $this, 'location_script_vars' ), 10, 1 );
            add_filter( 'better_messages_style_dependencies', array( $this, 'style_dependencies' ), 10, 1 );
            add_filter( 'better_messages_mobile_app_style_handles', array( $this, 'mobile_app_style_handles' ), 10, 1 );
            add_action( 'better_messages_before_message_send', array( $this, 'before_message_send' ), 10, 2 );
        }

        public function is_enabled() {
            return Better_Messages()->settings['locationEnable'] === '1';
        }

        public function user_can_share( $user_id = null ) {
            if ( ! $this->is_enabled() ) {
                return false;
            }

            if ( $user_id === null ) {
                $user_id = Better_Messages()->functions->get_current_user_id();
            }

            $allowed    = true;
            $restricted = (array) Better_Messages()->settings['locationRestrictRoles'];

            if ( ! empty( $restricted ) ) {
                foreach ( Better_Messages()->functions->get_user_roles( $user_id ) as $role ) {
                    if ( in_array( $role, $restricted, true ) ) {
                        $allowed = false;
                        break;
                    }
                }
            }

            return apply_filters( 'better_messages_user_can_share_location', $allowed, $user_id );
        }

        public function mobile_app_style_handles( $handles ) {
            if ( ! $this->is_enabled() ) {
                return $handles;
            }

            $handles[] = 'better-messages-location';

            return $handles;
        }

        public function style_dependencies( $dependencies ) {
            if ( ! $this->is_enabled() ) {
                return $dependencies;
            }

            $file    = 'assets/css/location.min.css';
            $version = Better_Messages()->version;

            if ( defined( 'BM_DEV' ) && file_exists( Better_Messages()->path . 'assets/css/location.css' ) ) {
                $file     = 'assets/css/location.css';
                $version .= filemtime( Better_Messages()->path . $file );
            } elseif ( defined( 'BM_DEV_ASSETS' ) && file_exists( Better_Messages()->path . $file ) ) {
                $version .= filemtime( Better_Messages()->path . $file );
            }

            wp_register_style( 'better-messages-location', Better_Messages()->url . $file, array( 'better-messages' ), $version );
            wp_enqueue_style( 'better-messages-location' );

            return $dependencies;
        }

        public function get_precision() {
            $precision = (int) Better_Messages()->settings['locationPrecision'];

            return ( $precision >= 2 && $precision <= 6 ) ? $precision : 0;
        }

        public function location_script_vars( $vars ) {
            if ( ! $this->is_enabled() ) {
                return $vars;
            }

            $settings = Better_Messages()->settings;

            $vars['location'] = array(
                'share'        => $this->user_can_share(),
                'tileUrl'      => $settings['locationTileUrl'],
                'attribution'  => $settings['locationTileAttribution'],
                'maxZoom'      => (int) $settings['locationMaxZoom'],
                'geocoderUrl'  => $settings['locationGeocoderUrl'],
                'nearbyUrl'    => $settings['locationNearbyUrl'],
                'nearbyRadius' => (int) $settings['locationNearbyRadius'],
                'searchLimit'  => (int) $settings['locationSearchLimit'],
                'defaultZoom'  => (int) $settings['locationDefaultZoom'],
                'selectedZoom' => (int) $settings['locationSelectedZoom'],
                'center'       => array(
                    'lat' => (float) $settings['locationDefaultLat'],
                    'lng' => (float) $settings['locationDefaultLng'],
                ),
                'search'       => $settings['locationAllowSearch'] === '1',
                'nearby'       => $settings['locationAllowNearby'] === '1',
                'geolocate'    => $settings['locationAllowGeolocate'] === '1',
                'highAccuracy' => $settings['locationHighAccuracy'] === '1',
                'bubbleMap'    => $settings['locationBubbleMap'] === '1',
                'bubbleZoom'   => (int) $settings['locationBubbleZoom'],
                'openIn'       => $settings['locationOpenIn'],
            );

            return $vars;
        }

        public function before_message_send( &$args, &$errors ) {
            if ( ! isset( $args['meta_data'] ) || ! is_array( $args['meta_data'] ) ) {
                return;
            }

            if ( ! isset( $args['meta_data']['location'] ) ) {
                return;
            }

            $sender_id = isset( $args['sender_id'] ) ? $args['sender_id'] : null;

            if ( ! $this->user_can_share( $sender_id ) ) {
                unset( $args['meta_data']['location'] );
                return;
            }

            $location = $this->sanitize_location( $args['meta_data']['location'] );

            if ( $location === null ) {
                unset( $args['meta_data']['location'] );
                return;
            }

            $args['meta_data']['location'] = $location;
        }

        public function sanitize_location( $location ) {
            if ( ! is_array( $location ) ) {
                return null;
            }

            if ( ! isset( $location['lat'] ) || ! isset( $location['lng'] ) || ! is_numeric( $location['lat'] ) || ! is_numeric( $location['lng'] ) ) {
                return null;
            }

            $lat = (float) $location['lat'];
            $lng = (float) $location['lng'];

            if ( abs( $lat ) > 90 || abs( $lng ) > 180 ) {
                return null;
            }

            $precision = $this->get_precision();

            if ( $precision > 0 ) {
                $lat = round( $lat, $precision );
                $lng = round( $lng, $precision );
            }

            $kinds = array( 'place', 'food', 'park', 'transit', 'landmark', 'library', 'shop', 'money', 'health', 'lodging', 'default' );
            $kind  = isset( $location['kind'] ) ? (string) $location['kind'] : 'place';

            return array(
                'lat'     => $lat,
                'lng'     => $lng,
                'name'    => isset( $location['name'] ) ? mb_substr( (string) $location['name'], 0, 250 ) : '',
                'address' => isset( $location['address'] ) ? mb_substr( (string) $location['address'], 0, 500 ) : '',
                'kind'    => in_array( $kind, $kinds, true ) ? $kind : 'place',
            );
        }

        public function location_message_meta( $meta, $message_id, $thread_id, $content ) {
            if ( ! $this->is_enabled() ) {
                return $meta;
            }

            $location = Better_Messages()->functions->get_message_meta( $message_id, 'location', true );
            if ( empty( $location ) || ! is_array( $location ) ) {
                return $meta;
            }

            $lat = isset( $location['lat'] ) ? (float) $location['lat'] : null;
            $lng = isset( $location['lng'] ) ? (float) $location['lng'] : null;
            if ( $lat === null || $lng === null ) {
                return $meta;
            }

            $meta['location'] = array(
                'lat'     => $lat,
                'lng'     => $lng,
                'name'    => isset( $location['name'] ) ? (string) $location['name'] : '',
                'address' => isset( $location['address'] ) ? (string) $location['address'] : '',
                'kind'    => isset( $location['kind'] ) ? (string) $location['kind'] : 'place',
            );

            return $meta;
        }
    }

    Better_Messages_Location::instance();
}
