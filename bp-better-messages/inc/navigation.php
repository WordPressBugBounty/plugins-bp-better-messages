<?php
defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Better_Messages_Navigation' ) ) {

    class Better_Messages_Navigation
    {
        const MAX_TABS = 10;

        const BARS = array( 'mini', 'side', 'mobile' );

        const TYPES = array( 'link', 'page', 'content' );

        const BUILT_IN = array( 'messages', 'friends', 'groups', 'courses', 'ai-bots', 'chat-rooms', 'users' );

        const STRINGS_CONTEXT = 'Better Messages';

        public static function instance()
        {
            static $instance = null;

            if ( null === $instance ) {
                $instance = new Better_Messages_Navigation();
            }

            return $instance;
        }

        public function __construct()
        {
            add_filter( 'bp_better_messages_script_variable', array( $this, 'script_variables' ), 20 );
            add_action( 'rest_api_init', array( $this, 'rest_api_init' ) );
            add_action( 'bp_better_chat_settings_updated', array( $this, 'register_wpml_strings' ) );
            add_action( 'admin_init', array( $this, 'register_polylang_strings' ) );
        }

        public function get_tabs( $user_id = null )
        {
            if ( $user_id === null ) {
                $user_id = Better_Messages()->functions->get_current_user_id();
            }

            $stored = isset( Better_Messages()->settings['customTabs'] ) ? Better_Messages()->settings['customTabs'] : array();
            $roles  = Better_Messages()->functions->get_user_roles( $user_id );
            $tabs   = array();

            foreach ( (array) $stored as $tab ) {
                if ( ! is_array( $tab ) || empty( $tab['id'] ) || empty( $tab['label'] ) ) {
                    continue;
                }

                $bars = array();

                foreach ( self::BARS as $bar ) {
                    if ( ! in_array( $bar, (array) ( $tab['bars'] ?? array() ), true ) ) {
                        continue;
                    }

                    $hidden = isset( $tab['hide'][ $bar ] ) ? (array) $tab['hide'][ $bar ] : array();

                    if ( count( array_intersect( $roles, $hidden ) ) > 0 ) {
                        continue;
                    }

                    $bars[] = $bar;
                }

                if ( empty( $bars ) ) {
                    continue;
                }

                $tabs[] = array(
                    'key'       => $tab['id'],
                    'label'     => $this->translate( $tab['label'], 'Navigation tab ' . $tab['id'] ),
                    'icon'      => (string) ( $tab['icon'] ?? '' ),
                    'type'      => $tab['type'],
                    'url'       => (string) ( $tab['url'] ?? '' ),
                    'newWindow' => ( $tab['newWindow'] ?? '0' ) === '1',
                    'bars'      => $bars,
                    'content'   => (string) ( $tab['content'] ?? '' ),
                );
            }

            $tabs = apply_filters( 'better_messages_custom_tabs', $tabs, $user_id );

            return is_array( $tabs ) ? array_values( $tabs ) : array();
        }

        public function get_labels()
        {
            $stored = isset( Better_Messages()->settings['tabLabels'] ) ? Better_Messages()->settings['tabLabels'] : array();
            $labels = array();

            foreach ( (array) $stored as $key => $label ) {
                if ( ! in_array( $key, self::BUILT_IN, true ) || ! is_string( $label ) || trim( $label ) === '' ) {
                    continue;
                }

                $labels[ $key ] = $this->translate( $label, 'Tab label ' . $key );
            }

            return $labels;
        }

        public function script_variables( $vars )
        {
            $published = array();

            foreach ( $this->get_tabs() as $tab ) {
                if ( ! is_array( $tab ) || empty( $tab['key'] ) || empty( $tab['label'] ) || ! in_array( $tab['type'] ?? '', self::TYPES, true ) ) {
                    continue;
                }

                $bars = array_values( array_intersect( self::BARS, (array) ( $tab['bars'] ?? array() ) ) );
                $url  = self::sanitize_url( (string) ( $tab['url'] ?? '' ), $tab['type'] );

                if ( empty( $bars ) || ( $tab['type'] !== 'content' && $url === '' ) ) {
                    continue;
                }

                $published[] = array(
                    'key'       => (string) $tab['key'],
                    'label'     => (string) $tab['label'],
                    'icon'      => (string) ( $tab['icon'] ?? '' ),
                    'type'      => $tab['type'],
                    'url'       => $url,
                    'newWindow' => ! empty( $tab['newWindow'] ),
                    'bars'      => $bars,
                );
            }

            if ( ! empty( $published ) ) {
                $vars['customTabs'] = $published;
            }

            $labels = $this->get_labels();

            if ( ! empty( $labels ) ) {
                $vars['tabLabels'] = $labels;
            }

            return $vars;
        }

        public function has_tabs_on( $bar )
        {
            $vars = Better_Messages()->script_variables;

            if ( empty( $vars['customTabs'] ) || ! is_array( $vars['customTabs'] ) ) {
                return 0;
            }

            $count = 0;

            foreach ( $vars['customTabs'] as $tab ) {
                if ( in_array( $bar, (array) ( $tab['bars'] ?? array() ), true ) ) {
                    $count++;
                }
            }

            return $count;
        }

        public function rest_api_init()
        {
            register_rest_route( 'better-messages/v1', '/navigation/tabs/(?P<key>[A-Za-z0-9_\-]+)/content', array(
                'methods'             => 'GET',
                'callback'            => array( $this, 'rest_tab_content' ),
                'permission_callback' => '__return_true',
            ) );
        }

        public function rest_tab_content( WP_REST_Request $request )
        {
            $key     = (string) $request->get_param( 'key' );
            $user_id = Better_Messages()->functions->get_current_user_id();

            if ( $user_id <= 0 && ! Better_Messages()->guests->guest_access_enabled() ) {
                return new WP_Error( 'rest_forbidden', _x( 'This tab is not available', 'Navigation tabs', 'bp-better-messages' ), array( 'status' => rest_authorization_required_code() ) );
            }

            foreach ( $this->get_tabs( $user_id ) as $tab ) {
                if ( ! is_array( $tab ) || ( $tab['key'] ?? '' ) !== $key || ( $tab['type'] ?? '' ) !== 'content' ) {
                    continue;
                }

                if ( isset( $tab['content_callback'] ) && is_callable( $tab['content_callback'] ) ) {
                    $html = (string) call_user_func( $tab['content_callback'], $tab, $user_id );
                } else {
                    $html = $this->render_content( (string) ( $tab['content'] ?? '' ) );
                }

                $html = apply_filters( 'better_messages_navigation_tab_content', $html, $tab, $user_id );

                $response = new WP_REST_Response( array( 'html' => (string) $html ) );
                $response->header( 'Cache-Control', 'no-cache, must-revalidate, max-age=0, private' );

                return $response;
            }

            return new WP_Error( 'rest_not_found', _x( 'This tab is not available', 'Navigation tabs', 'bp-better-messages' ), array( 'status' => 404 ) );
        }

        public function render_content( $content )
        {
            if ( $content === '' ) {
                return '';
            }

            if ( function_exists( 'has_blocks' ) && has_blocks( $content ) ) {
                return do_shortcode( do_blocks( $content ) );
            }

            return do_shortcode( shortcode_unautop( wpautop( $content ) ) );
        }

        public static function sanitize_custom_tabs( $value )
        {
            if ( is_string( $value ) ) {
                $value = json_decode( $value, true );
            }

            if ( ! is_array( $value ) ) {
                return array();
            }

            $tabs  = array();
            $ids   = array();

            foreach ( $value as $tab ) {
                if ( count( $tabs ) >= self::MAX_TABS ) {
                    break;
                }

                if ( ! is_array( $tab ) ) {
                    continue;
                }

                $label = sanitize_text_field( (string) ( $tab['label'] ?? '' ) );

                if ( $label === '' ) {
                    continue;
                }

                $type = in_array( $tab['type'] ?? '', self::TYPES, true ) ? $tab['type'] : 'link';
                $url  = '';

                if ( $type !== 'content' ) {
                    $url = self::sanitize_url( trim( (string) ( $tab['url'] ?? '' ) ), $type );

                    if ( $url === '' ) {
                        continue;
                    }
                }

                $id = (string) ( $tab['id'] ?? '' );

                if ( ! preg_match( '/^ct_[a-z0-9]{6}$/', $id ) || isset( $ids[ $id ] ) ) {
                    do {
                        $id = 'ct_' . strtolower( wp_generate_password( 6, false, false ) );
                    } while ( isset( $ids[ $id ] ) );
                }

                $ids[ $id ] = true;

                $hide = array();

                foreach ( self::BARS as $bar ) {
                    $hidden       = isset( $tab['hide'][ $bar ] ) ? (array) $tab['hide'][ $bar ] : array();
                    $hide[ $bar ] = array_values( array_unique( array_filter( array_map( 'sanitize_key', array_map( 'strval', $hidden ) ) ) ) );
                }

                $tabs[] = array(
                    'id'        => $id,
                    'label'     => $label,
                    'icon'      => self::sanitize_icon( $tab['icon'] ?? '' ),
                    'type'      => $type,
                    'url'       => $url,
                    'newWindow' => ( $type === 'link' && ! empty( $tab['newWindow'] ) && $tab['newWindow'] !== '0' ) ? '1' : '0',
                    'content'   => $type === 'content' ? wp_kses_post( (string) ( $tab['content'] ?? '' ) ) : '',
                    'bars'      => array_values( array_intersect( self::BARS, array_map( 'strval', (array) ( $tab['bars'] ?? array() ) ) ) ),
                    'hide'      => $hide,
                );
            }

            return $tabs;
        }

        public static function sanitize_tab_labels( $value )
        {
            if ( is_string( $value ) ) {
                $value = json_decode( $value, true );
            }

            if ( ! is_array( $value ) ) {
                return array();
            }

            $labels = array();

            foreach ( self::BUILT_IN as $key ) {
                if ( ! isset( $value[ $key ] ) || ! is_string( $value[ $key ] ) ) {
                    continue;
                }

                $label = sanitize_text_field( $value[ $key ] );

                if ( $label !== '' ) {
                    $labels[ $key ] = $label;
                }
            }

            return $labels;
        }

        public static function sanitize_url( $url, $type )
        {
            $protocols = $type === 'link' ? array( 'http', 'https', 'mailto', 'tel' ) : array( 'http', 'https' );

            return esc_url_raw( $url, $protocols );
        }

        private static function sanitize_icon( $icon )
        {
            if ( ! is_string( $icon ) || $icon === '' ) {
                return '';
            }

            if ( stripos( ltrim( $icon ), '<svg' ) === 0 ) {
                return Better_Messages()->options->sanitize_svg( $icon );
            }

            return sanitize_key( $icon );
        }

        private function translate( $label, $name )
        {
            if ( function_exists( 'pll__' ) ) {
                return pll__( $label );
            }

            return apply_filters( 'wpml_translate_single_string', $label, self::STRINGS_CONTEXT, $name );
        }

        private function strings()
        {
            $strings = array();

            foreach ( (array) ( Better_Messages()->settings['customTabs'] ?? array() ) as $tab ) {
                if ( is_array( $tab ) && ! empty( $tab['id'] ) && ! empty( $tab['label'] ) ) {
                    $strings[ 'Navigation tab ' . $tab['id'] ] = $tab['label'];
                }
            }

            foreach ( (array) ( Better_Messages()->settings['tabLabels'] ?? array() ) as $key => $label ) {
                if ( is_string( $label ) && $label !== '' ) {
                    $strings[ 'Tab label ' . $key ] = $label;
                }
            }

            return $strings;
        }

        public function register_wpml_strings()
        {
            foreach ( $this->strings() as $name => $label ) {
                do_action( 'wpml_register_single_string', self::STRINGS_CONTEXT, $name, $label );
            }
        }

        public function register_polylang_strings()
        {
            if ( ! function_exists( 'pll_register_string' ) ) {
                return;
            }

            foreach ( $this->strings() as $name => $label ) {
                pll_register_string( $name, $label, self::STRINGS_CONTEXT );
            }
        }
    }
}

function Better_Messages_Navigation()
{
    return Better_Messages_Navigation::instance();
}
