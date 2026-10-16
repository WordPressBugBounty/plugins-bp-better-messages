<?php
defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WordPlus_SDK_Tracking' ) ) {
    final class WordPlus_SDK_Tracking
    {
        const OPTION = 'wordplus_sdk_tracking';

        const SYNC = 'wordplus_sdk_tracking_sync';

        const HEARTBEAT = 604800;

        const SHORT_TERM = 259200;

        const PERMISSIONS = array( 'profile', 'site', 'features', 'extensions', 'newsletter' );

        const LICENSED = array( 'site', 'features' );

        const MAX_FEATURES = 1000;

        const MAX_FEATURE_TEXT = 40;

        const IN = 'in';

        const SKIPPED = 'skipped';

        const OUT = 'out';

        const REASONS = array(
            'no_longer_needed', 'found_better', 'short_period', 'broke_site', 'stopped_working', 'cant_pay',
            'couldnt_make_work', 'need_feature', 'not_working', 'not_what_looking_for', 'didnt_work_as_expected',
            'didnt_work', 'dont_share_info', 'temporary', 'other',
        );

        const SNOOZES = array( 3600, 86400, 604800 );

        const PRIVACY_URL = 'https://www.wordplus.cloud/privacy';

        const TERMS_URL = 'https://www.wordplus.cloud/terms';

        private $sdk;

        private $stores = array();

        private $hooked = false;

        public function __construct( WordPlus_SDK $sdk )
        {
            $this->sdk = $sdk;
        }

        public function admin_hooks()
        {
            if ( $this->hooked ) {
                return;
            }

            $this->hooked = true;

            add_action( 'admin_init', array( $this, 'admin_init' ), 1 );
            add_action( 'admin_init', array( $this, 'maybe_redirect' ), 99 );
            add_action( 'admin_menu', array( $this, 'menu' ), 31 );
            add_action( 'network_admin_menu', array( $this, 'menu' ), 31 );
            add_action( 'admin_notices', array( $this, 'notices' ) );
            add_action( 'network_admin_notices', array( $this, 'notices' ) );
            add_filter( 'plugin_action_links', array( $this, 'action_links' ), 10, 2 );
            add_filter( 'network_admin_plugin_action_links', array( $this, 'action_links' ), 10, 2 );
            add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_survey' ) );
        }

        public function enabled( WordPlus_SDK_Product $product )
        {
            return false !== $product->get( 'tracking', true );
        }

        public function parent( WordPlus_SDK_Product $product )
        {
            foreach ( $this->sdk->products() as $other ) {
                if ( $other !== $product && '' !== $other->addon( $product->slug() ) && $this->enabled( $other ) ) {
                    return $other;
                }
            }

            return null;
        }

        public function followers( WordPlus_SDK_Product $product )
        {
            return array_values( array_filter( $this->sdk->products(), function ( WordPlus_SDK_Product $other ) use ( $product ) {
                return $other !== $product && '' !== $product->addon( $other->slug() ) && $this->enabled( $other );
            } ) );
        }

        public function asks( WordPlus_SDK_Product $product )
        {
            return $this->enabled( $product ) && null === $this->parent( $product );
        }

        private function store( $network )
        {
            $key = $network ? 'network' : 'site';

            if ( ! isset( $this->stores[ $key ] ) ) {
                $data                 = $network ? get_site_option( self::OPTION, array() ) : get_option( self::OPTION, array() );
                $this->stores[ $key ] = is_array( $data ) ? $data : array();
            }

            return $this->stores[ $key ];
        }

        private function write( $network, array $data )
        {
            $this->stores[ $network ? 'network' : 'site' ] = $data;

            if ( $network ) {
                update_site_option( self::OPTION, $data );
            } else {
                update_option( self::OPTION, $data, false );
            }
        }

        public function entry( WordPlus_SDK_Product $product, $network = null )
        {
            $store = $this->store( null === $network ? $product->is_network_active() : (bool) $network );
            $slug  = $product->slug();

            return isset( $store['plugins'][ $slug ] ) && is_array( $store['plugins'][ $slug ] ) ? $store['plugins'][ $slug ] : array();
        }

        private function save( WordPlus_SDK_Product $product, array $entry, $network = null )
        {
            $network = null === $network ? $product->is_network_active() : (bool) $network;
            $store   = $this->store( $network );

            $store['plugins'][ $product->slug() ] = $entry;
            $this->write( $network, $store );
        }

        private function uid( $network )
        {
            $store = $this->store( $network );

            if ( empty( $store['uid'] ) ) {
                $store['uid'] = wp_generate_password( 32, false );
                $this->write( $network, $store );
            }

            return (string) $store['uid'];
        }

        public function state( WordPlus_SDK_Product $product )
        {
            $entry = $this->entry( $product );

            return isset( $entry['state'] ) ? (string) $entry['state'] : '';
        }

        public function normalize( $permissions )
        {
            $permissions = is_array( $permissions ) ? $permissions : array();
            $normal      = array();

            foreach ( self::PERMISSIONS as $permission ) {
                $normal[ $permission ] = ! empty( $permissions[ $permission ] );
            }

            $normal['newsletter'] = $normal['newsletter'] && $normal['profile'];

            return $normal;
        }

        public function permissions( WordPlus_SDK_Product $product )
        {
            $entry = $this->entry( $product );

            if ( isset( $entry['permissions'] ) && is_array( $entry['permissions'] ) && self::IN === $this->state( $product ) ) {
                return $this->normalize( $entry['permissions'] );
            }

            return array( 'profile' => true, 'site' => true, 'features' => true, 'extensions' => true, 'newsletter' => false );
        }

        public function offers_features( WordPlus_SDK_Product $product )
        {
            return is_callable( $product->get( 'usage' ) );
        }

        public function licensed( WordPlus_SDK_Product $product )
        {
            return $product->is_paid_build() && '' !== $product->licence()->get_key() && $product->has_access();
        }

        private function with_licence( WordPlus_SDK_Product $product, array $permissions )
        {
            $permissions = $this->normalize( $permissions );

            if ( $this->licensed( $product ) ) {
                foreach ( self::LICENSED as $permission ) {
                    $permissions[ $permission ] = true;
                }
            }

            return $permissions;
        }

        private function profile( $user_id = 0 )
        {
            $user = $user_id ? get_userdata( $user_id ) : wp_get_current_user();

            if ( ! $user || empty( $user->user_email ) ) {
                return array();
            }

            $first = (string) get_user_meta( $user->ID, 'first_name', true );
            $last  = (string) get_user_meta( $user->ID, 'last_name', true );

            return array(
                'email'      => (string) $user->user_email,
                'first_name' => '' !== $first || '' !== $last ? $first : (string) $user->display_name,
                'last_name'  => $last,
            );
        }

        public function opt_in( WordPlus_SDK_Product $product, array $permissions, $source = 'screen', $profile = null, $freemius_install = 0, $timeout = 15 )
        {
            $permissions = $this->with_licence( $product, $permissions );

            if ( ! $permissions['profile'] && ! $permissions['site'] && ! $permissions['features'] && ! $permissions['extensions'] ) {
                $this->skip( $product );

                return true;
            }

            $entry = array_merge( $this->entry( $product ), array(
                'state'       => self::IN,
                'permissions' => $permissions,
                'source'      => (string) $source,
                'at'          => time(),
                'profile'     => $permissions['profile'] ? ( is_array( $profile ) ? $profile : $this->profile() ) : array(),
                'basename'    => $product->basename(),
                'build'       => $product->is_paid_build() ? 'paid' : 'free',
            ) );

            if ( $freemius_install ) {
                $entry['freemius_install'] = (int) $freemius_install;
            }

            if ( 'licence' === $source ) {
                $entry['by_licence'] = true;
            } else {
                unset( $entry['by_licence'] );
            }

            unset( $entry['redirect'], $entry['hash'] );
            $this->save( $product, $entry );

            $result = $timeout > 0 ? $this->send_opt_in( $product, $timeout ) : $this->send_soon();
            $this->schedule();

            foreach ( $this->followers( $product ) as $addon ) {
                $this->opt_in( $addon, $permissions, $source, $entry['profile'], 0, $timeout );
            }

            return $result;
        }

        public function skip( WordPlus_SDK_Product $product )
        {
            $entry = $this->entry( $product );

            if ( self::IN === $this->state( $product ) ) {
                $this->opt_out( $product );

                return;
            }

            $entry['state'] = self::SKIPPED;
            $entry['at']    = time();
            unset( $entry['redirect'], $entry['notice'] );
            $this->save( $product, $entry );

            foreach ( $this->followers( $product ) as $addon ) {
                $this->skip( $addon );
            }
        }

        public function update( WordPlus_SDK_Product $product, array $permissions )
        {
            $permissions = $this->with_licence( $product, $permissions );

            if ( self::IN !== $this->state( $product ) ) {
                return $this->opt_in( $product, $permissions );
            }

            if ( ! $permissions['profile'] && ! $permissions['site'] && ! $permissions['features'] ) {
                return $this->opt_out( $product );
            }

            $entry                = $this->entry( $product );
            $entry['permissions'] = $permissions;

            if ( $permissions['profile'] && empty( $entry['profile']['email'] ) ) {
                $entry['profile'] = $this->profile();
            }

            $this->save( $product, $entry );

            $result = $this->sync( $product, null, true );

            foreach ( $this->followers( $product ) as $addon ) {
                $this->update( $addon, $permissions );
            }

            return $result;
        }

        public function opt_out( WordPlus_SDK_Product $product )
        {
            if ( $this->licensed( $product ) ) {
                return new WP_Error( 'licensed', __( 'While the licence is active, the site shares its site and plugin info, the features in use and its events as a part of it.', 'wordplus-sdk' ), array( 'status' => 400 ) );
            }

            $entry          = $this->entry( $product );
            $entry['state'] = self::OUT;
            $entry['at']    = time();
            unset( $entry['profile'], $entry['notice'], $entry['confirm'], $entry['redirect'], $entry['hash'], $entry['events'] );
            $this->save( $product, $entry );

            $result = $this->send_opt_out( $product );

            foreach ( $this->followers( $product ) as $addon ) {
                $this->opt_out( $addon );
            }

            return $result;
        }

        public function payload( WordPlus_SDK_Product $product )
        {
            $entry       = $this->entry( $product );
            $permissions = $this->normalize( isset( $entry['permissions'] ) ? $entry['permissions'] : array() );
            $body        = array(
                'plugin'      => $product->slug(),
                'version'     => $product->version(),
                'build'       => $product->is_paid_build() ? 'paid' : 'free',
                'sdk'         => $this->sdk->version(),
                'permissions' => $permissions,
            );

            if ( $permissions['profile'] && ! empty( $entry['profile']['email'] ) ) {
                $body['profile'] = $entry['profile'];
            }

            if ( $permissions['site'] ) {
                $body['site'] = $this->site_details( $product );
            }

            if ( $permissions['features'] && $this->offers_features( $product ) ) {
                $body['features'] = $this->features( $product );
            }

            if ( $permissions['extensions'] ) {
                $body['extensions'] = $this->extensions();
            }

            return $body;
        }

        public function features( WordPlus_SDK_Product $product )
        {
            try {
                $reported = call_user_func( $product->get( 'usage' ) );
            } catch ( \Throwable $error ) {
                return array();
            }

            $features = array();

            foreach ( is_array( $reported ) ? $reported : array() as $name => $value ) {
                if ( count( $features ) >= self::MAX_FEATURES || ! is_string( $name ) || ! preg_match( '/^[A-Za-z0-9_.:-]{1,100}$/', $name ) ) {
                    continue;
                }

                if ( is_bool( $value ) || is_int( $value ) || ( is_float( $value ) && is_finite( $value ) ) ) {
                    $features[ $name ] = $value;
                } elseif ( is_string( $value ) && strlen( $value ) <= self::MAX_FEATURE_TEXT && preg_match( '/^[A-Za-z0-9_.:+-]*$/', $value ) ) {
                    $features[ $name ] = $value;
                }
            }

            ksort( $features );

            return $features;
        }

        private function site_details( WordPlus_SDK_Product $product )
        {
            return array(
                'url'       => $product->is_network_active() && is_multisite() ? get_home_url( get_main_site_id() ) : home_url(),
                'title'     => (string) get_bloginfo( 'name' ),
                'language'  => (string) get_locale(),
                'timezone'  => (string) get_option( 'timezone_string', '' ),
                'wp'        => (string) get_bloginfo( 'version' ),
                'php'       => PHP_VERSION,
                'multisite' => is_multisite(),
            );
        }

        private function extensions()
        {
            if ( ! function_exists( 'get_plugins' ) ) {
                require_once ABSPATH . 'wp-admin/includes/plugin.php';
            }

            $active  = (array) get_option( 'active_plugins', array() );
            $network = is_multisite() ? array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) : array();
            $plugins = array();

            foreach ( get_plugins() as $file => $plugin ) {
                $plugins[] = array(
                    'slug'    => false !== strpos( (string) $file, '/' ) ? dirname( (string) $file ) : basename( (string) $file, '.php' ),
                    'name'    => isset( $plugin['Name'] ) ? (string) $plugin['Name'] : '',
                    'version' => isset( $plugin['Version'] ) ? (string) $plugin['Version'] : '',
                    'active'  => in_array( $file, $active, true ) || in_array( $file, $network, true ),
                );
            }

            $themes  = array();
            $current = array( get_stylesheet(), get_template() );

            foreach ( wp_get_themes() as $stylesheet => $theme ) {
                $themes[] = array(
                    'slug'    => (string) $stylesheet,
                    'name'    => (string) $theme->get( 'Name' ),
                    'version' => (string) $theme->get( 'Version' ),
                    'active'  => in_array( (string) $stylesheet, $current, true ),
                );
            }

            return array( 'plugins' => array_slice( $plugins, 0, 500 ), 'themes' => array_slice( $themes, 0, 100 ) );
        }

        private function send_opt_in( WordPlus_SDK_Product $product, $timeout = 15 )
        {
            $entry = $this->entry( $product );
            $body  = array_merge( $this->payload( $product ), array(
                'uid'    => $this->uid( $product->is_network_active() ),
                'source' => isset( $entry['source'] ) ? (string) $entry['source'] : 'screen',
            ) );

            if ( ! empty( $entry['freemius_install'] ) ) {
                $body['freemius_install'] = (int) $entry['freemius_install'];
            }

            $answer = $this->request( 'api/v1/tracking/opt-in', $body, null, $timeout );
            $entry  = $this->entry( $product );

            if ( is_wp_error( $answer ) || empty( $answer['install']['id'] ) || empty( $answer['install']['secret'] ) ) {
                $entry['retry'] = time();
                $this->save( $product, $entry );

                return is_wp_error( $answer ) ? $answer : new WP_Error( 'unreachable' );
            }

            unset( $body['uid'], $body['source'], $body['freemius_install'], $entry['retry'] );
            $entry['install']   = array( 'id' => (int) $answer['install']['id'], 'secret' => (string) $answer['install']['secret'] );
            $entry['hash']      = md5( (string) wp_json_encode( $body ) );
            $entry['synced_at'] = time();
            $entry['version']   = $product->version();
            $this->save( $product, $this->keep_confirm( $entry, $answer ) );

            return true;
        }

        private function keep_confirm( array $entry, array $answer )
        {
            $confirm = isset( $answer['confirm'] ) && is_array( $answer['confirm'] ) ? $answer['confirm'] : array();

            if ( empty( $confirm['email'] ) || ! is_string( $confirm['email'] ) ) {
                unset( $entry['confirm'] );

                return $entry;
            }

            $was  = isset( $entry['confirm'] ) && is_array( $entry['confirm'] ) ? $entry['confirm'] : array();
            $same = isset( $was['email'] ) && $was['email'] === $confirm['email'];
            $sent = ! empty( $confirm['sent'] );

            $entry['confirm'] = array(
                'email'     => $confirm['email'],
                'sent'      => $sent ? time() : ( $same && ! empty( $was['sent'] ) ? (int) $was['sent'] : 0 ),
                'dismissed' => $same && ! $sent && ! empty( $was['dismissed'] ),
            );

            return $entry;
        }

        private function send_opt_out( WordPlus_SDK_Product $product )
        {
            $entry = $this->entry( $product );

            if ( empty( $entry['install'] ) ) {
                unset( $entry['retry'] );
                $this->save( $product, $entry );

                return true;
            }

            $answer = $this->request( 'api/v1/tracking/opt-out', array(), $entry['install'] );
            $entry  = $this->entry( $product );

            if ( is_wp_error( $answer ) && ! in_array( $answer->get_error_code(), array( 'unknown_install', 'bad_signature' ), true ) ) {
                $entry['retry'] = time();
                $this->save( $product, $entry );
                $this->keep_scheduled();

                return $answer;
            }

            unset( $entry['install'], $entry['retry'] );
            $this->save( $product, $entry );

            return true;
        }

        public function sync( WordPlus_SDK_Product $product, $event = null, $force = false, $timeout = 15 )
        {
            if ( self::IN !== $this->state( $product ) ) {
                return true;
            }

            $entry = $this->entry( $product );

            if ( empty( $entry['install'] ) ) {
                return $this->send_opt_in( $product, $timeout );
            }

            $body = $this->payload( $product );
            $hash = md5( (string) wp_json_encode( $body ) );

            if ( null === $event && ! $force && isset( $entry['hash'] ) && $hash === $entry['hash'] && (int) ( isset( $entry['synced_at'] ) ? $entry['synced_at'] : 0 ) > time() - self::HEARTBEAT ) {
                return true;
            }

            if ( null !== $event ) {
                $body['event'] = (string) $event;
            }

            $body['state'] = 'deactivated' === $event ? 'inactive' : 'active';
            $answer        = $this->request( 'api/v1/tracking/sync', $body, $entry['install'], $timeout );
            $entry         = $this->entry( $product );

            if ( is_wp_error( $answer ) ) {
                if ( null !== $event ) {
                    $entry['events'][] = array( 'type' => (string) $event, 'at' => time() );
                }

                if ( in_array( $answer->get_error_code(), array( 'unknown_install', 'bad_signature' ), true ) ) {
                    unset( $entry['install'] );
                    $entry['retry'] = time();
                }

                $this->save( $product, $entry );

                return $answer;
            }

            $entry['hash']      = $hash;
            $entry['synced_at'] = time();
            $entry['version']   = $product->version();
            $this->save( $product, $this->keep_confirm( $entry, $answer ) );

            return true;
        }

        public function run_sync()
        {
            $registered = array();

            foreach ( $this->sdk->products() as $product ) {
                if ( ! $this->enabled( $product ) ) {
                    continue;
                }

                $registered[ ( $product->is_network_active() ? 'network:' : 'site:' ) . $product->slug() ] = true;
                $this->apply_licence( $product );
                $entry = $this->entry( $product );

                if ( self::OUT === $this->state( $product ) && ! empty( $entry['install'] ) ) {
                    $this->send_opt_out( $product );
                    continue;
                }

                if ( self::IN !== $this->state( $product ) ) {
                    continue;
                }

                $events = isset( $entry['events'] ) && is_array( $entry['events'] ) ? $entry['events'] : array();

                if ( array() !== $events ) {
                    unset( $entry['events'] );
                    $this->save( $product, $entry );

                    foreach ( $events as $event ) {
                        $this->sync( $product, isset( $event['type'] ) ? (string) $event['type'] : null );
                    }
                }

                $this->sync( $product );
            }

            $this->keep_scheduled();

            foreach ( array( false, true ) as $network ) {
                if ( $network && ! is_multisite() ) {
                    continue;
                }

                $store = $this->store( $network );

                foreach ( isset( $store['plugins'] ) && is_array( $store['plugins'] ) ? $store['plugins'] : array() as $slug => $entry ) {
                    if ( ! isset( $registered[ ( $network ? 'network:' : 'site:' ) . $slug ] ) ) {
                        $this->send_waiting( (string) $slug, $network );
                    }
                }
            }
        }

        private function send_waiting( $slug, $network )
        {
            $store = $this->store( $network );
            $entry = isset( $store['plugins'][ $slug ] ) && is_array( $store['plugins'][ $slug ] ) ? $store['plugins'][ $slug ] : array();

            if ( empty( $entry['events'] ) || empty( $entry['install'] ) || self::IN !== ( isset( $entry['state'] ) ? $entry['state'] : '' ) ) {
                return;
            }

            $sent = array();

            foreach ( (array) $entry['events'] as $index => $event ) {
                $answer = $this->request( 'api/v1/tracking/sync', $this->last_known( $slug, $entry, isset( $event['type'] ) ? (string) $event['type'] : null ), $entry['install'] );

                if ( is_wp_error( $answer ) && ! in_array( $answer->get_error_code(), array( 'unknown_install', 'bad_signature' ), true ) ) {
                    break;
                }

                $sent[] = $index;
            }

            $store = $this->store( $network );
            $store['plugins'][ $slug ]['events'] = array_values( array_diff_key( (array) $entry['events'], array_flip( $sent ) ) );

            if ( array() === $store['plugins'][ $slug ]['events'] ) {
                unset( $store['plugins'][ $slug ]['events'] );
            }

            $this->write( $network, $store );
        }

        private function last_known( $slug, array $entry, $event )
        {
            $body = array(
                'plugin'      => (string) $slug,
                'version'     => isset( $entry['version'] ) ? (string) $entry['version'] : '0',
                'build'       => isset( $entry['build'] ) ? (string) $entry['build'] : 'free',
                'sdk'         => $this->sdk->version(),
                'permissions' => $this->normalize( isset( $entry['permissions'] ) ? $entry['permissions'] : array() ),
                'state'       => in_array( $event, array( 'deactivated', 'uninstalled' ), true ) ? 'inactive' : 'active',
            );

            if ( null !== $event ) {
                $body['event'] = $event;
            }

            return $body;
        }

        private function request( $path, array $body, $install = null, $timeout = 15 )
        {
            $json    = wp_json_encode( (object) $body );
            $headers = array(
                'Content-Type' => 'application/json',
                'Accept'       => 'application/json',
            );

            if ( is_array( $install ) && ! empty( $install['id'] ) && ! empty( $install['secret'] ) ) {
                $timestamp = (string) time();
                $uri       = (string) wp_parse_url( $this->sdk->server() . $path, PHP_URL_PATH );

                $headers['X-WordPlus-Install']   = (string) (int) $install['id'];
                $headers['X-WordPlus-Timestamp'] = $timestamp;
                $headers['X-WordPlus-Signature'] = hash_hmac( 'sha256', implode( "\n", array( $timestamp, 'POST', $uri, hash( 'sha256', (string) $json ) ) ), (string) $install['secret'] );
            }

            $response = wp_remote_post( $this->sdk->server() . $path, array(
                'timeout' => (int) $timeout,
                'headers' => $headers,
                'body'    => $json,
            ) );

            if ( is_wp_error( $response ) ) {
                return new WP_Error( 'unreachable', $response->get_error_message() );
            }

            $status = (int) wp_remote_retrieve_response_code( $response );
            $data   = json_decode( wp_remote_retrieve_body( $response ), true );

            if ( 200 === $status && is_array( $data ) ) {
                return $data;
            }

            $code = is_array( $data ) && ! empty( $data['error'] ) ? (string) $data['error'] : 'http_' . $status;

            return new WP_Error( $code, $code, is_array( $data ) ? $data : array() );
        }

        private function schedule()
        {
            if ( ! wp_next_scheduled( self::SYNC ) ) {
                wp_schedule_event( time() + DAY_IN_SECONDS, 'daily', self::SYNC );
            }
        }

        private function keep_scheduled()
        {
            foreach ( $this->sdk->products() as $product ) {
                if ( ! $this->enabled( $product ) || ( $product->is_network_active() && is_multisite() && ! is_main_site() ) ) {
                    continue;
                }

                $entry = $this->entry( $product );
                $state = $this->state( $product );

                if ( self::IN === $state || ( self::OUT === $state && ! empty( $entry['install'] ) ) ) {
                    $this->schedule();

                    return;
                }
            }
        }

        public function activated( WordPlus_SDK_Product $product, $network_wide = false )
        {
            if ( ! $this->enabled( $product ) ) {
                return;
            }

            $network = (bool) $network_wide;
            $entry   = $this->entry( $product, $network );

            if ( empty( $entry['first_activated'] ) ) {
                $entry['first_activated'] = time();
            }

            $entry['basename'] = $product->basename();
            $entry['version']  = $product->version();
            $entry['build']    = $product->is_paid_build() ? 'paid' : 'free';
            $this->save( $product, $entry, $network );

            $state  = isset( $entry['state'] ) ? (string) $entry['state'] : '';
            $parent = $this->parent( $product );

            if ( self::IN === $state ) {
                $entry['events'][] = array( 'type' => 'activated', 'at' => time() );
                $this->save( $product, $entry, $network );
                $this->send_soon();
            } elseif ( null !== $parent ) {
                if ( self::IN === $this->state( $parent ) ) {
                    $first = $this->entry( $parent );
                    $this->opt_in( $product, $this->permissions( $parent ), isset( $first['source'] ) ? (string) $first['source'] : 'screen', isset( $first['profile'] ) ? (array) $first['profile'] : array(), 0, 0 );
                }
            } elseif ( '' === $state && ! $product->is_paid_build() && get_current_user_id() ) {
                $entry             = $this->entry( $product, $network );
                $entry['redirect'] = get_current_user_id();
                $this->save( $product, $entry, $network );
            }
        }

        public function deactivated( WordPlus_SDK_Product $product, $network_wide = false )
        {
            if ( $this->enabled( $product ) && self::IN === $this->state( $product ) ) {
                $entry            = $this->entry( $product );
                $entry['version'] = $product->version();
                $this->save( $product, $entry );

                $this->sync( $product, 'deactivated', true, 3 );
            }
        }

        public function uninstalling( $basename )
        {
            foreach ( array( false, true ) as $network ) {
                $store = $this->store( $network );

                foreach ( isset( $store['plugins'] ) && is_array( $store['plugins'] ) ? $store['plugins'] : array() as $slug => $entry ) {
                    if ( isset( $entry['basename'] ) && $entry['basename'] === $basename && self::IN === ( isset( $entry['state'] ) ? $entry['state'] : '' ) && ! empty( $entry['install'] ) ) {
                        $answer = $this->request( 'api/v1/tracking/sync', $this->last_known( (string) $slug, $entry, 'uninstalled' ), $entry['install'], 3 );

                        if ( is_wp_error( $answer ) ) {
                            $store['plugins'][ $slug ]['events'][] = array( 'type' => 'uninstalled', 'at' => time() );
                            $this->write( $network, $store );
                        }
                    }
                }
            }
        }

        public function carry_over( WordPlus_SDK_Product $product )
        {
            $freemius = (array) $product->get( 'freemius', array() );
            $entry    = $this->entry( $product );

            if ( empty( $freemius['slug'] ) || ! empty( $entry['state'] ) || ! empty( $entry['freemius_checked'] ) || ! $this->asks( $product ) ) {
                return;
            }

            $found                     = $this->read_freemius( $product, (string) $freemius['slug'] );
            $entry['freemius_checked'] = time();

            if ( null === $found ) {
                $this->save( $product, $entry );

                return;
            }

            if ( ! empty( $found['installed_at'] ) && empty( $entry['first_activated'] ) ) {
                $entry['first_activated'] = (int) $found['installed_at'];
            }

            if ( self::IN !== $found['state'] ) {
                $entry['state']  = self::SKIPPED;
                $entry['source'] = 'freemius';
                $entry['at']     = time();
                $this->save( $product, $entry );

                return;
            }

            $entry = array_merge( $entry, array(
                'state'            => self::IN,
                'permissions'      => $this->normalize( $found['permissions'] ),
                'profile'          => $found['permissions']['profile'] ? $found['profile'] : array(),
                'source'           => 'freemius',
                'freemius_install' => (int) $found['install'],
                'at'               => time(),
                'basename'         => $product->basename(),
                'build'            => $product->is_paid_build() ? 'paid' : 'free',
                'notice'           => 'freemius',
            ) );
            $this->save( $product, $entry );

            foreach ( $this->followers( $product ) as $addon ) {
                $follower = array_merge( $this->entry( $addon ), array(
                    'state'       => self::IN,
                    'permissions' => $entry['permissions'],
                    'profile'     => $entry['profile'],
                    'source'      => 'freemius',
                    'at'          => time(),
                    'basename'    => $addon->basename(),
                    'build'       => $addon->is_paid_build() ? 'paid' : 'free',
                ) );
                $this->save( $addon, $follower );
            }

            $this->send_soon();
            $this->keep_scheduled();
        }

        public function read_freemius( WordPlus_SDK_Product $product, $slug )
        {
            $sources = array( get_option( 'fs_accounts', array() ) );

            if ( $product->is_network_active() ) {
                array_unshift( $sources, get_site_option( 'fs_accounts', array() ) );
            }

            foreach ( $sources as $accounts ) {
                if ( ! is_array( $accounts ) ) {
                    continue;
                }

                $data = isset( $accounts['plugin_data'][ $slug ] ) ? (array) $accounts['plugin_data'][ $slug ] : array();
                $site = isset( $accounts['sites'][ $slug ] ) ? (array) $accounts['sites'][ $slug ] : array();

                if ( ! empty( $site['id'] ) && ! empty( $site['user_id'] ) ) {
                    if ( ! empty( $site['is_disconnected'] ) ) {
                        return array( 'state' => self::SKIPPED );
                    }

                    $licensed = ! empty( $site['license_id'] );
                    $allowed  = function ( $key, $default ) use ( $data ) {
                        return isset( $data[ $key ] ) ? (bool) $data[ $key ] : $default;
                    };
                    $user     = isset( $accounts['users'][ $site['user_id'] ] ) ? (array) $accounts['users'][ $site['user_id'] ] : array();

                    $permissions = array(
                        'profile'    => ! $licensed && $allowed( 'is_user_tracking_allowed', true ) && ! empty( $user['email'] ),
                        'site'       => $licensed ? $allowed( 'is_diagnostic_tracking_allowed', true ) : $allowed( 'is_site_tracking_allowed', $allowed( 'is_diagnostic_tracking_allowed', true ) ),
                        'features'   => $product->is_paid_build(),
                        'extensions' => $allowed( 'is_extensions_tracking_allowed', ! $licensed ),
                        'newsletter' => false,
                    );

                    if ( ! $permissions['profile'] && ! $permissions['site'] ) {
                        return array( 'state' => self::SKIPPED );
                    }

                    return array(
                        'state'        => self::IN,
                        'permissions'  => $permissions,
                        'profile'      => array(
                            'email'      => isset( $user['email'] ) ? (string) $user['email'] : '',
                            'first_name' => isset( $user['first'] ) ? (string) $user['first'] : '',
                            'last_name'  => isset( $user['last'] ) ? (string) $user['last'] : '',
                        ),
                        'install'      => (int) $site['id'],
                        'installed_at' => isset( $data['install_timestamp'] ) ? (int) $data['install_timestamp'] : 0,
                    );
                }

                $anonymous = isset( $data['is_anonymous'] ) ? (array) $data['is_anonymous'] : array();

                if ( ! empty( $anonymous['is'] ) ) {
                    return array( 'state' => self::SKIPPED, 'installed_at' => isset( $data['install_timestamp'] ) ? (int) $data['install_timestamp'] : 0 );
                }
            }

            return null;
        }

        private function send_soon()
        {
            if ( ! wp_next_scheduled( WordPlus_SDK::TRACKING_SOON ) ) {
                wp_schedule_single_event( time(), WordPlus_SDK::TRACKING_SOON );
            }

            return true;
        }

        public function capability( WordPlus_SDK_Product $product )
        {
            return $product->is_network_active() ? 'manage_network_options' : 'manage_options';
        }

        public function can_choose( WordPlus_SDK_Product $product )
        {
            return current_user_can( $this->capability( $product ) );
        }

        public function page( WordPlus_SDK_Product $product )
        {
            return $product->page( 'optin' );
        }

        public function url( WordPlus_SDK_Product $product, array $args = array() )
        {
            $slug = $this->page( $product );

            if ( '' === $slug ) {
                return '';
            }

            $path = 'admin.php?page=' . $slug;
            $url  = $product->is_network_active() ? network_admin_url( $path ) : admin_url( $path );

            return array() === $args ? $url : add_query_arg( $args, $url );
        }

        public function admin_init()
        {
            foreach ( $this->sdk->products() as $product ) {
                if ( $this->asks( $product ) && $this->can_choose( $product ) ) {
                    $this->carry_over( $product );
                }

                if ( $this->apply_licence( $product ) ) {
                    $this->send_soon();
                }
            }

            $this->keep_scheduled();
            $this->handle_link();
        }

        private function handle_link()
        {
            // phpcs:disable WordPress.Security.NonceVerification.Recommended
            if ( ! isset( $_GET['wordplus_sdk_tracking'], $_GET['plugin'], $_GET['_wpnonce'] ) ) {
                return;
            }

            $action  = sanitize_key( wp_unslash( $_GET['wordplus_sdk_tracking'] ) );
            $product = $this->sdk->product( sanitize_key( wp_unslash( $_GET['plugin'] ) ) );
            $nonce   = sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) );
            // phpcs:enable WordPress.Security.NonceVerification.Recommended

            if ( null === $product || ! $this->asks( $product ) || ! $this->can_choose( $product ) || ! wp_verify_nonce( $nonce, 'wordplus_sdk_tracking_' . $product->slug() ) ) {
                return;
            }

            if ( 'skip' === $action && '' === $this->state( $product ) ) {
                $this->skip( $product );
            } elseif ( 'dismiss' === $action ) {
                $entry = $this->entry( $product );
                unset( $entry['notice'] );
                $this->save( $product, $entry );
            } elseif ( 'dismiss_confirm' === $action ) {
                $entry = $this->entry( $product );

                if ( isset( $entry['confirm'] ) && is_array( $entry['confirm'] ) ) {
                    $entry['confirm']['dismissed'] = true;
                    $this->save( $product, $entry );
                }
            }

            if ( wp_safe_redirect( remove_query_arg( array( 'wordplus_sdk_tracking', 'plugin', '_wpnonce' ) ) ) ) {
                exit;
            }
        }

        private function link( WordPlus_SDK_Product $product, $action )
        {
            return wp_nonce_url( add_query_arg( array( 'wordplus_sdk_tracking' => $action, 'plugin' => $product->slug() ) ), 'wordplus_sdk_tracking_' . $product->slug() );
        }

        public function maybe_redirect()
        {
            if ( wp_doing_ajax() || wp_doing_cron() || ! get_current_user_id() ) {
                return;
            }

            foreach ( $this->sdk->products() as $product ) {
                $entry = $this->entry( $product );

                if ( empty( $entry['redirect'] ) || (int) $entry['redirect'] !== get_current_user_id() || ! $this->asks( $product ) ) {
                    continue;
                }

                unset( $entry['redirect'] );
                $this->save( $product, $entry );

                // phpcs:ignore WordPress.Security.NonceVerification.Recommended
                if ( '' !== $this->state( $product ) || isset( $_GET['activate-multi'] ) || '' === $this->page( $product ) || ! $this->can_choose( $product ) ) {
                    continue;
                }

                if ( wp_safe_redirect( $this->url( $product ) ) ) {
                    exit;
                }
            }
        }

        public function menu()
        {
            foreach ( $this->sdk->products() as $product ) {
                if ( ! $this->asks( $product ) || '' === $this->page( $product ) || $product->is_network_active() !== is_network_admin() ) {
                    continue;
                }

                $title = _x( 'Usage data', 'Admin page title', 'wordplus-sdk' );
                $hook  = add_submenu_page( '', $title, $title, $this->capability( $product ), $this->page( $product ), array( $product->admin(), 'render' ) );

                if ( $hook ) {
                    add_action( 'load-' . $hook, function () use ( $product ) {
                        if ( empty( $GLOBALS['title'] ) ) {
                            /* translators: %s: the plugin's name */
                            $GLOBALS['title'] = sprintf( _x( '%s Usage Data', 'Admin page title', 'wordplus-sdk' ), $product->name() );
                        }

                        WordPlus_SDK_UI::enqueue_page( $this->page_data( $product ) );
                    } );
                }
            }
        }

        public function page_data( WordPlus_SDK_Product $product )
        {
            $data             = $product->admin()->page_data( 'optin' );
            $data['tracking'] = $this->summary( $product );

            return $data;
        }

        public function summary( WordPlus_SDK_Product $product )
        {
            $entry  = $this->entry( $product );
            $shared = array();

            foreach ( $this->sdk->products() as $other ) {
                if ( $other !== $product && $this->asks( $other ) && self::IN === $this->state( $other ) ) {
                    $shared[] = array( 'slug' => $other->slug(), 'name' => $other->name(), 'permissions' => $this->permissions( $other ) );
                }
            }

            $profile = $this->profile();
            $next    = (string) $product->get( 'urls.settings' );

            return array(
                'enabled'     => $this->asks( $product ),
                'state'       => $this->state( $product ),
                'permissions' => $this->permissions( $product ),
                'licensed'    => $this->licensed( $product ),
                'required'    => $this->licensed( $product ) ? self::LICENSED : array( 'profile', 'site' ),
                'features'    => $this->offers_features( $product ),
                'source'      => isset( $entry['source'] ) ? (string) $entry['source'] : '',
                'since'       => self::IN === $this->state( $product ) && ! empty( $entry['at'] ) ? (int) $entry['at'] : null,
                'email'       => self::IN === $this->state( $product ) && ! empty( $entry['profile']['email'] ) ? (string) $entry['profile']['email'] : ( isset( $profile['email'] ) ? $profile['email'] : '' ),
                'confirm'     => self::IN === $this->state( $product ) && ! empty( $entry['confirm']['email'] ) ? (string) $entry['confirm']['email'] : '',
                'firstName'   => isset( $profile['first_name'] ) ? $profile['first_name'] : '',
                'shared'      => $shared,
                'addons'      => array_map( function ( WordPlus_SDK_Product $addon ) {
                    return $addon->name();
                }, $this->followers( $product ) ),
                'url'         => $this->url( $product ),
                'next'        => '' !== $next ? $next : admin_url( 'admin.php?page=' . (string) $product->get( 'menu.parent' ) ),
                'privacy'     => self::PRIVACY_URL,
                'terms'       => self::TERMS_URL,
            );
        }

        public function notices()
        {
            foreach ( $this->sdk->products() as $product ) {
                if ( ! $this->asks( $product ) || ! $this->can_choose( $product ) || $product->is_network_active() !== is_network_admin() ) {
                    continue;
                }

                $entry = $this->entry( $product );

                if ( self::IN === $this->state( $product ) && isset( $entry['notice'] ) && 'freemius' === $entry['notice'] ) {
                    printf(
                        '<div class="notice notice-info"><p>%1$s</p><p><a class="button button-primary" href="%2$s">%3$s</a> <a class="button" href="%4$s">%5$s</a> <a href="%6$s">%7$s</a></p></div>',
                        esc_html( $this->permissions( $product )['features'] && $this->offers_features( $product ) ? sprintf(
                            /* translators: %s: the plugin's name */
                            __( '%s now sends the usage data you agreed to share to WordPlus Cloud, which runs its licences and updates, instead of Freemius. It also says which of its features and settings are in use, so the most used ones get the most care. You can change what is shared or opt out at any time.', 'wordplus-sdk' ),
                            $product->name()
                        ) : sprintf(
                            /* translators: %s: the plugin's name */
                            __( '%s now sends the usage data you agreed to share to WordPlus Cloud, which runs its licences and updates, instead of Freemius. You can change what is shared or opt out at any time.', 'wordplus-sdk' ),
                            $product->name()
                        ) ),
                        esc_url( $this->link( $product, 'dismiss' ) ),
                        esc_html__( 'Got it', 'wordplus-sdk' ),
                        esc_url( $this->url( $product ) ),
                        esc_html__( 'See what is shared', 'wordplus-sdk' ),
                        esc_url( self::PRIVACY_URL ),
                        esc_html__( 'Privacy policy', 'wordplus-sdk' )
                    );
                } elseif ( self::IN === $this->state( $product ) && ! empty( $entry['confirm']['email'] ) && empty( $entry['confirm']['dismissed'] ) && $this->on_own_screen( $product ) && ! $this->on_screen( $product ) ) {
                    printf(
                        '<div class="notice notice-info"><p><strong>%1$s</strong></p><p>%2$s</p><p><a class="button button-primary" href="%3$s">%4$s</a> <a class="button" href="%5$s">%6$s</a></p></div>',
                        esc_html( sprintf(
                            /* translators: %s: the plugin's name */
                            __( 'Confirm your email for %s', 'wordplus-sdk' ),
                            $product->name()
                        ) ),
                        esc_html( sprintf(
                            /* translators: %s: an email address */
                            __( 'WordPlus Cloud sent a link to %s. Click it to confirm the address is yours, and the emails you asked for start.', 'wordplus-sdk' ),
                            (string) $entry['confirm']['email']
                        ) ),
                        esc_url( $this->link( $product, 'dismiss_confirm' ) ),
                        esc_html__( 'Got it', 'wordplus-sdk' ),
                        esc_url( $this->url( $product ) ),
                        esc_html__( 'See what is shared', 'wordplus-sdk' )
                    );
                }

                if ( '' === $this->state( $product ) && ! $product->is_paid_build() && '' !== $this->page( $product ) && $this->on_own_screen( $product ) ) {
                    printf(
                        '<div class="notice notice-info"><p><strong>%1$s</strong></p><p>%2$s</p><p><a class="button button-primary" href="%3$s">%4$s</a> <a class="button" href="%5$s">%6$s</a></p></div>',
                        esc_html( sprintf(
                            /* translators: %s: the plugin's name */
                            __( 'Never miss an important update of %s', 'wordplus-sdk' ),
                            $product->name()
                        ) ),
                        esc_html__( 'Opt in to get emails about security and feature updates, and to share some basic WordPress environment info. It helps make the plugin more compatible with your site and better at what you need it to do.', 'wordplus-sdk' ),
                        esc_url( $this->url( $product ) ),
                        esc_html__( 'Allow & Continue', 'wordplus-sdk' ),
                        esc_url( $this->link( $product, 'skip' ) ),
                        esc_html__( 'Skip', 'wordplus-sdk' )
                    );
                }
            }
        }

        private function on_screen( WordPlus_SDK_Product $product )
        {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            return '' !== $this->page( $product ) && isset( $_GET['page'] ) && $this->page( $product ) === sanitize_text_field( wp_unslash( $_GET['page'] ) );
        }

        private function on_own_screen( WordPlus_SDK_Product $product )
        {
            $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
            $id     = $screen ? (string) $screen->id : '';
            $parent = (string) $product->get( 'menu.parent' );

            if ( in_array( $id, array( 'plugins', 'plugins-network' ), true ) ) {
                return true;
            }

            return '' !== $parent && ( false !== strpos( $id, '_page_' . $parent ) || 'toplevel_page_' . $parent === $id || $product->admin()->is_page_screen( $id ) );
        }

        public function action_links( $links, $file )
        {
            foreach ( $this->sdk->products() as $product ) {
                if ( $product->basename() !== $file || ! $this->asks( $product ) || '' === $this->page( $product ) || ! $this->can_choose( $product ) ) {
                    continue;
                }

                $label = $this->licensed( $product ) ? __( 'Usage data', 'wordplus-sdk' ) : ( self::IN === $this->state( $product ) ? __( 'Opt out', 'wordplus-sdk' ) : __( 'Opt in', 'wordplus-sdk' ) );
                $links = (array) $links;

                $links['wordplus-sdk-tracking'] = '<a href="' . esc_url( $this->url( $product ) ) . '">' . esc_html( $label ) . '</a>';
            }

            return $links;
        }

        public function enqueue_survey( $hook )
        {
            if ( 'plugins.php' !== $hook || ! current_user_can( 'activate_plugins' ) ) {
                return;
            }

            $plugins = array();

            foreach ( $this->sdk->products() as $product ) {
                if ( $this->enabled( $product ) && '' !== $product->file() && $product->is_network_active() === is_network_admin() ) {
                    $plugins[] = $this->survey( $product );
                }
            }

            if ( array() === $plugins ) {
                return;
            }

            WordPlus_SDK_UI::enqueue_survey( array( 'plugins' => $plugins ) );
        }

        public function survey( WordPlus_SDK_Product $product )
        {
            $entry   = $this->entry( $product );
            $first   = isset( $entry['first_activated'] ) ? (int) $entry['first_activated'] : 0;
            $contact = $product->page_url( 'contact' );

            return array(
                'slug'     => $product->slug(),
                'basename' => $product->basename(),
                'name'     => $product->name(),
                'rest'     => array(
                    'root'  => '/' . trim( (string) $product->get( 'rest.namespace' ), '/' ) . '/' . trim( (string) $product->get( 'rest.base' ), '/' ),
                    'nonce' => wp_create_nonce( 'wp_rest' ),
                ),
                'state'    => $this->state( $product ),
                'userType' => $first && $first > time() - self::SHORT_TERM ? 'short' : 'long',
                'paid'     => $product->is_paid_build(),
                'contact'  => '' === $contact ? '' : add_query_arg( 'topic', 'technical', $contact ),
                'snoozed'  => isset( $entry['snooze'] ) && (int) $entry['snooze'] > time(),
            );
        }

        public function feedback( WordPlus_SDK_Product $product, $reason, $details, $anonymous, $snooze = 0 )
        {
            $entry = $this->entry( $product );

            if ( 'temporary' === $reason && in_array( (int) $snooze, self::SNOOZES, true ) ) {
                $entry['snooze'] = time() + (int) $snooze;
                $this->save( $product, $entry );
            }

            $first   = isset( $entry['first_activated'] ) ? (int) $entry['first_activated'] : 0;
            $install = ! $anonymous && self::IN === $this->state( $product ) && ! empty( $entry['install'] ) ? $entry['install'] : null;
            $answer  = $this->request( 'api/v1/tracking/feedback', array(
                'plugin'    => $product->slug(),
                'version'   => $product->version(),
                'build'     => $product->is_paid_build() ? 'paid' : 'free',
                'reason'    => (string) $reason,
                'details'   => (string) $details,
                'user_type' => $first && $first > time() - self::SHORT_TERM ? 'short' : 'long',
            ), $install, 5 );

            return is_wp_error( $answer ) ? $answer : true;
        }

        public function licence_choice( WordPlus_SDK_Product $product, $extensions )
        {
            if ( ! $this->asks( $product ) || ! $this->licensed( $product ) ) {
                return;
            }

            if ( self::IN === $this->state( $product ) && empty( $this->entry( $product )['by_licence'] ) ) {
                $this->update( $product, $this->permissions( $product ) );

                return;
            }

            $this->opt_in( $product, array( 'profile' => false, 'site' => true, 'features' => true, 'extensions' => (bool) $extensions, 'newsletter' => false ), 'licence', array(), 0, 10 );
        }

        public function apply_licence( WordPlus_SDK_Product $product )
        {
            if ( ! $this->asks( $product ) ) {
                return false;
            }

            $entry   = $this->entry( $product );
            $state   = $this->state( $product );
            $current = $this->normalize( isset( $entry['permissions'] ) ? $entry['permissions'] : array() );

            if ( $this->licensed( $product ) ) {
                $required = $this->with_licence( $product, self::IN === $state ? $current : array() );
                $changed  = self::IN !== $state || $required !== $current;

                if ( $changed ) {
                    if ( self::IN !== $state ) {
                        $entry = array_merge( $entry, array( 'source' => 'licence', 'by_licence' => true, 'at' => time(), 'profile' => array(), 'basename' => $product->basename(), 'build' => 'paid' ) );
                    }

                    $entry['state']       = self::IN;
                    $entry['permissions'] = $required;
                    unset( $entry['hash'], $entry['redirect'] );
                    $this->save( $product, $entry );
                    $this->schedule();
                }

                foreach ( $this->followers( $product ) as $addon ) {
                    $changed = $this->follow_licence( $addon, $entry ) || $changed;
                }

                return $changed;
            }

            if ( self::IN === $state && ! empty( $entry['by_licence'] ) ) {
                $this->end_licence_share( $product );

                foreach ( $this->followers( $product ) as $addon ) {
                    if ( self::IN === $this->state( $addon ) && ! empty( $this->entry( $addon )['by_licence'] ) ) {
                        $this->end_licence_share( $addon );
                    }
                }

                return true;
            }

            return false;
        }

        private function follow_licence( WordPlus_SDK_Product $addon, array $parent )
        {
            $entry       = $this->entry( $addon );
            $permissions = $this->with_licence( $addon, $parent['permissions'] );

            if ( self::IN === $this->state( $addon ) && $this->normalize( isset( $entry['permissions'] ) ? $entry['permissions'] : array() ) === $permissions ) {
                return false;
            }

            if ( self::IN !== $this->state( $addon ) ) {
                $entry = array_merge( $entry, array(
                    'source'   => isset( $parent['source'] ) ? (string) $parent['source'] : 'licence',
                    'at'       => time(),
                    'profile'  => isset( $parent['profile'] ) ? (array) $parent['profile'] : array(),
                    'basename' => $addon->basename(),
                    'build'    => $addon->is_paid_build() ? 'paid' : 'free',
                ) );

                if ( ! empty( $parent['by_licence'] ) ) {
                    $entry['by_licence'] = true;
                }
            }

            $entry['state']       = self::IN;
            $entry['permissions'] = $permissions;
            unset( $entry['hash'], $entry['redirect'] );
            $this->save( $addon, $entry );

            return true;
        }

        private function end_licence_share( WordPlus_SDK_Product $product )
        {
            $entry          = $this->entry( $product );
            $entry['state'] = self::OUT;
            $entry['at']    = time();
            unset( $entry['profile'], $entry['hash'], $entry['events'], $entry['by_licence'] );
            $this->save( $product, $entry );
        }

        public function routes( WordPlus_SDK_Product $product )
        {
            if ( ! $this->enabled( $product ) ) {
                return;
            }

            $namespace = (string) $product->get( 'rest.namespace' );
            $base      = '/' . trim( (string) $product->get( 'rest.base' ), '/' );
            $choose    = function () use ( $product ) {
                return $this->asks( $product ) && $this->can_choose( $product );
            };

            register_rest_route( $namespace, $base . '/tracking', array(
                'methods'             => 'POST',
                'callback'            => function ( WP_REST_Request $request ) use ( $product ) {
                    return $this->rest_choose( $product, (string) $request->get_param( 'action' ), $request->get_param( 'permissions' ) );
                },
                'permission_callback' => $choose,
                'args'                => array(
                    'action'      => array( 'required' => true, 'type' => 'string', 'enum' => array( 'opt-in', 'update', 'skip', 'opt-out' ) ),
                    'permissions' => array( 'type' => 'object' ),
                ),
            ) );

            register_rest_route( $namespace, $base . '/tracking/feedback', array(
                'methods'             => 'POST',
                'callback'            => function ( WP_REST_Request $request ) use ( $product ) {
                    $reason = (string) $request->get_param( 'reason' );

                    $this->feedback(
                        $product,
                        $reason,
                        sanitize_textarea_field( (string) $request->get_param( 'details' ) ),
                        (bool) $request->get_param( 'anonymous' ),
                        (int) $request->get_param( 'snooze' )
                    );

                    return array( 'sent' => true );
                },
                'permission_callback' => function () {
                    return current_user_can( 'activate_plugins' );
                },
                'args'                => array(
                    'reason'    => array( 'required' => true, 'type' => 'string', 'enum' => self::REASONS ),
                    'details'   => array( 'type' => 'string', 'default' => '' ),
                    'anonymous' => array( 'type' => 'boolean', 'default' => false ),
                    'snooze'    => array( 'type' => 'integer', 'default' => 0 ),
                ),
            ) );
        }

        public function rest_choose( WordPlus_SDK_Product $product, $action, $permissions )
        {
            $permissions = is_array( $permissions ) ? $permissions : array();

            if ( 'skip' === $action ) {
                $this->skip( $product );
            } elseif ( 'opt-out' === $action ) {
                $result = $this->opt_out( $product );

                if ( is_wp_error( $result ) && 'licensed' === $result->get_error_code() ) {
                    return $result;
                }
            } elseif ( 'update' === $action ) {
                $this->update( $product, $permissions );
            } else {
                $this->opt_in( $product, $permissions );
            }

            $entry = $this->entry( $product );
            unset( $entry['notice'] );
            $this->save( $product, $entry );

            return $this->summary( $product );
        }
    }
}
