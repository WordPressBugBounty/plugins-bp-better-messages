<?php
defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WordPlus_SDK_Storage_Admin' ) ) {
    final class WordPlus_SDK_Storage_Admin
    {
        const REST_NAMESPACE = 'wordplus/v1';

        const SCRIPT = 'wordplus-storage-admin';

        const CORS_RULE_ID = 'wordplus';

        const LEGACY_CORS_RULE_ID = 'better-messages';

        private $storage;

        public function __construct( WordPlus_SDK_Storage $storage )
        {
            $this->storage = $storage;
        }

        public function can_manage()
        {
            return current_user_can( 'manage_options' );
        }

        public function trusted_with_server()
        {
            return ! is_multisite() || current_user_can( 'manage_network_options' );
        }

        public function may_use_private_hosts()
        {
            return $this->trusted_with_server() || (bool) apply_filters( 'wordplus_storage_private_hosts', false );
        }

        public static function is_public_host( $host )
        {
            $host = strtolower( trim( (string) $host, "[] \t\n\r" ) );

            if ( '' === $host ) {
                return false;
            }

            if ( filter_var( $host, FILTER_VALIDATE_IP ) ) {
                $ips = array( $host );
            } else {
                $ips = gethostbynamel( $host );
                $ips = is_array( $ips ) ? $ips : array();

                if ( function_exists( 'dns_get_record' ) && ! empty( $ips ) ) {
                    $records = @dns_get_record( $host, DNS_AAAA ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

                    foreach ( is_array( $records ) ? $records : array() as $record ) {
                        if ( ! empty( $record['ipv6'] ) ) {
                            $ips[] = (string) $record['ipv6'];
                        }
                    }
                }
            }

            if ( empty( $ips ) ) {
                return false;
            }

            foreach ( $ips as $ip ) {
                if ( ! filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
                    return false;
                }

                if ( preg_match( '/^(0\.|100\.(6[4-9]|[7-9][0-9]|1[01][0-9]|12[0-7])\.|::ffff:)/i', $ip ) ) {
                    return false;
                }
            }

            return true;
        }

        public static function outbound_hosts( array $location )
        {
            $hosts = array();

            foreach ( array( 'endpoint', 'public_base', 'worker_url', 'pull_zone_host' ) as $field ) {
                $value = isset( $location[ $field ] ) ? trim( (string) $location[ $field ] ) : '';

                if ( '' === $value ) {
                    continue;
                }

                if ( 'endpoint' === $field ) {
                    $host = self::endpoint_host( $location );
                } else {
                    $host = 'pull_zone_host' === $field ? strtolower( $value ) : strtolower( (string) wp_parse_url( $value, PHP_URL_HOST ) );
                }

                $hosts[ $field ] = '' !== $host ? $host : $value;
            }

            return $hosts;
        }

        private static function endpoint_host( array $location )
        {
            $host       = strtolower( (string) wp_parse_url( isset( $location['endpoint'] ) ? trim( (string) $location['endpoint'] ) : '', PHP_URL_HOST ) );
            $path_style = ! empty( $location['path_style'] ) && '0' !== (string) $location['path_style'];

            if ( '' !== $host && ! $path_style && ! filter_var( $host, FILTER_VALIDATE_IP ) ) {
                $host = strtolower( isset( $location['bucket'] ) ? trim( (string) $location['bucket'] ) : '' ) . '.' . $host;
            }

            return $host;
        }

        private static function outbound_target( array $location, $field )
        {
            $value = isset( $location[ $field ] ) ? trim( (string) $location[ $field ] ) : '';

            if ( 'endpoint' === $field ) {
                $value = self::endpoint_host( $location ) . '|' . untrailingslashit( strtolower( $value ) ) . '|' . strtolower( isset( $location['bucket'] ) ? trim( (string) $location['bucket'] ) : '' );
            }

            return $value;
        }

        public function private_hosts( array $location )
        {
            if ( $this->may_use_private_hosts() ) {
                return array();
            }

            $saved = $this->storage->current_location( WordPlus_SDK_Storage::location_type( $location ) );
            $hosts = array();

            foreach ( self::outbound_hosts( $location ) as $field => $host ) {
                if ( $saved && self::outbound_target( $saved, $field ) === self::outbound_target( $location, $field ) ) {
                    continue;
                }

                if ( ! self::is_public_host( $host ) ) {
                    $hosts[] = $host;
                }
            }

            return array_values( array_unique( $hosts ) );
        }

        public function remote( $url, array $args = array() )
        {
            $method = isset( $args['method'] ) ? strtoupper( (string) $args['method'] ) : 'GET';

            if ( $this->may_use_private_hosts() ) {
                return 'GET' === $method ? wp_remote_get( $url, $args ) : wp_remote_request( $url, $args );
            }

            $origin              = self::authority( $url );
            $args['redirection'] = 0;

            for ( $hops = 0; ; $hops++ ) {
                $args['method'] = $method;

                $response = 'GET' === $method ? wp_remote_get( $url, $args ) : wp_remote_request( $url, $args );
                $code     = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );
                $next     = in_array( $code, array( 301, 302, 303, 307, 308 ), true ) ? wp_remote_retrieve_header( $response, 'location' ) : '';
                $next     = trim( (string) ( is_array( $next ) ? end( $next ) : $next ) );

                if ( '' === $next || $hops >= 5 ) {
                    return $response;
                }

                if ( ! preg_match( '#^[a-z][a-z0-9+.\-]*://#i', $next ) && class_exists( 'WP_Http' ) ) {
                    $next = WP_Http::make_absolute_url( $next, $url );
                }

                $host   = strtolower( (string) wp_parse_url( $next, PHP_URL_HOST ) );
                $scheme = strtolower( (string) wp_parse_url( $next, PHP_URL_SCHEME ) );

                if ( ! in_array( $scheme, array( 'http', 'https' ), true ) || '' === $host || ( self::authority( $next ) !== $origin && ! self::is_public_host( $host ) ) ) {
                    return $response;
                }

                if ( 303 === $code ) {
                    $method = 'GET';
                }

                $url = $next;
            }
        }

        private static function authority( $url )
        {
            $parts  = wp_parse_url( (string) $url );
            $scheme = isset( $parts['scheme'] ) ? strtolower( $parts['scheme'] ) : '';
            $port   = isset( $parts['port'] ) ? (int) $parts['port'] : ( 'https' === $scheme ? 443 : 80 );

            return ( isset( $parts['host'] ) ? strtolower( $parts['host'] ) : '' ) . ':' . $port;
        }

        public function enqueue()
        {
            $sdk = $this->storage->sdk();

            require_once $sdk->path( 'src/class-ui.php' );
            WordPlus_SDK_UI::enqueue();

            wp_register_script( self::SCRIPT, $sdk->url( 'storage/storage-admin.min.js' ), $sdk->i18n()->deps( self::SCRIPT, array( WordPlus_SDK_UI::UI, 'wp-element', 'wp-i18n', 'wp-api-fetch' ) ), WordPlus_SDK_UI::asset_version( 'storage/storage-admin.min.js' ), true );
            wp_register_style( self::SCRIPT, $sdk->url( 'storage/storage-admin.min.css' ), array( WordPlus_SDK_UI::UI ), WordPlus_SDK_UI::asset_version( 'storage/storage-admin.min.css' ) );

            wp_enqueue_script( self::SCRIPT );
            wp_enqueue_style( self::SCRIPT );
            wp_add_inline_script( self::SCRIPT, 'window.wordplusStorageData = ' . wp_json_encode( array(
                'rest'  => rest_url( self::REST_NAMESPACE . '/storage' ),
                'state' => $this->get_state( false ),
            ) ) . ';', 'before' );

            if ( $this->storage->has_locations() && ! wp_next_scheduled( WordPlus_SDK_Storage::JOB ) ) {
                $this->storage->schedule( 60 );
            }
        }

        public function routes()
        {
            $manage = array( $this, 'can_manage' );

            $routes = array(
                ''               => array( 'GET', 'rest_state' ),
                '/location'      => array( 'POST', 'rest_save_location' ),
                '/save'          => array( 'POST', 'rest_save' ),
                '/disconnect'    => array( 'POST', 'rest_disconnect' ),
                '/test'          => array( 'POST', 'rest_test' ),
                '/cors'          => array( 'POST', 'rest_configure_cors' ),
                '/worker-script' => array( 'GET', 'rest_worker_script' ),
                '/test-worker'   => array( 'POST', 'rest_test_worker' ),
                '/folder'        => array( 'POST', 'rest_resolve_folder' ),
                '/migration'     => array( 'GET', 'rest_migration' ),
            );

            foreach ( $routes as $path => $route ) {
                register_rest_route( self::REST_NAMESPACE, '/storage' . $path, array(
                    'methods'             => $route[0],
                    'callback'            => array( $this, $route[1] ),
                    'permission_callback' => $manage,
                ) );
            }

            foreach ( array( 'start', 'pause', 'resume', 'cancel' ) as $action ) {
                register_rest_route( self::REST_NAMESPACE, '/storage/migration/' . $action, array(
                    'methods'             => 'POST',
                    'callback'            => array( $this, 'rest_migration_' . $action ),
                    'permission_callback' => $manage,
                ) );
            }
        }

        public function rest_state()
        {
            return $this->get_state( true );
        }

        public function get_state( $probe = false )
        {
            return array(
                'form' => $this->form_state(),
                'info' => $this->info_state( $probe ),
            );
        }

        public function mask( array $location )
        {
            foreach ( WordPlus_SDK_Storage::secret_fields() as $field ) {
                if ( isset( $location[ $field ] ) && '' !== $location[ $field ] ) {
                    $location[ $field ] = WordPlus_SDK_Storage::SECRET_SET;
                }
            }

            foreach ( WordPlus_SDK_Storage::constant_fields( WordPlus_SDK_Storage::location_type( $location ) ) as $field => $constant ) {
                if ( defined( $constant ) && '' !== (string) constant( $constant ) ) {
                    $location[ $field ] = in_array( $field, WordPlus_SDK_Storage::secret_fields(), true ) ? WordPlus_SDK_Storage::SECRET_SET : (string) constant( $constant );
                }
            }

            return $location;
        }

        public function blank_location( $type )
        {
            $preset   = WordPlus_SDK_Storage::preset( $type );
            $location = WordPlus_SDK_Storage::location_defaults();

            $location['preset']     = $type;
            $location['region']     = $preset['region'];
            $location['path_style'] = $preset['path_style'] ? '1' : '0';

            return $location;
        }

        public function form_state()
        {
            $settings  = $this->storage->get_settings();
            $locations = array();
            $enabled   = array();

            foreach ( WordPlus_SDK_Storage::types() as $type ) {
                $id = $settings['current'][ $type ];

                $enabled[ $type ]   = '' !== $id;
                $locations[ $type ] = $this->mask( '' !== $id ? $settings['locations'][ $id ] : $this->blank_location( $type ) );
            }

            return array(
                'enabled'   => $enabled,
                'locations' => $locations,
            );
        }

        public function info_state( $probe = false )
        {
            $storage  = $this->storage;
            $settings = $storage->get_settings();
            $files    = array();
            $previous = array();
            $direct   = array();
            $locked   = array();
            $folders  = array();
            $plugins  = array();

            foreach ( $settings['locations'] as $id => $location ) {
                $files[ $id ]   = $storage->location_file_count( $id );
                $folders[ $id ] = $storage->folder_state( $storage->get_location( $id ) );

                if ( $settings['current'][ $location['preset'] ] === $id ) {
                    continue;
                }

                $previous[] = array(
                    'location' => $this->mask( $location ),
                    'files'    => $files[ $id ],
                );
            }

            foreach ( WordPlus_SDK_Storage::types() as $type ) {
                $direct[ $type ] = $this->direct_status( $type, $probe );
                $locked[ $type ] = WordPlus_SDK_Storage::locked_fields( $type );
            }

            $uses = $storage->uses();

            foreach ( $storage->sdk()->products() as $slug => $product ) {
                $sends = false;

                foreach ( $uses as $place ) {
                    $sends = $sends || isset( $place[ $slug ] );
                }

                if ( $sends || null !== $product->get( 'storage' ) ) {
                    $plugins[ $slug ] = $storage->plugin_name( $slug );
                }
            }

            return array(
                'ids'       => $settings['current'],
                'previous'  => $previous,
                'presets'   => WordPlus_SDK_Storage::presets(),
                'locked'    => $locked,
                'usage'     => $storage->usage(),
                'files'     => (object) $files,
                'origins'   => $this->origins(),
                'curl'      => WordPlus_SDK_Storage_S3::has_curl(),
                'direct'    => $direct,
                'fallbacks' => $storage->direct()->fallbacks_today(),
                'migration' => $storage->queue()->migration_payload(),
                'folders'   => (object) $folders,
                'address'   => WordPlus_SDK_Storage::site_address(),
                'sweeps'    => (object) array_intersect_key( $storage->sweeper()->states(), $settings['locations'] ),
                'uses'      => $uses,
                'plugins'   => (object) $plugins,
            );
        }

        public function direct_status( $type, $probe = false )
        {
            $storage  = $this->storage;
            $location = $storage->current_location( $type );

            if ( ! $location ) {
                return 'none';
            }

            if ( ! $storage->in_use( $type ) ) {
                return 'unused';
            }

            if ( '0' === $location['direct'] ) {
                return 'off';
            }

            $state = $storage->direct()->state( $location, $probe );

            if ( 'unknown' === $state ) {
                return 'unknown';
            }

            return $storage->direct()->ready( $location ) ? 'working' : 'fallback';
        }

        public function origins()
        {
            $origins = array();

            foreach ( array( home_url( '/' ), site_url( '/' ) ) as $url ) {
                $parts = wp_parse_url( $url );

                if ( empty( $parts['host'] ) ) {
                    continue;
                }

                $origin = ( isset( $parts['scheme'] ) ? $parts['scheme'] : 'https' ) . '://' . $parts['host'] . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' );

                $origins[ $origin ] = true;
            }

            return array_keys( apply_filters( 'wordplus_storage_cors_origins', $origins ) );
        }

        public static function is_web_origin( $origin )
        {
            return (bool) preg_match( '#^https?://#i', (string) $origin );
        }

        public function test_key( array $location, $name )
        {
            $prefix = trim( (string) $location['prefix'], '/' );

            return ( '' !== $prefix ? $prefix . '/' : '' ) . WordPlus_SDK_Storage::TEST_FOLDER . '/' . $name;
        }

        public function resolve_endpoint( array $location )
        {
            $preset   = WordPlus_SDK_Storage::preset( $location['preset'] );
            $endpoint = trim( (string) $location['endpoint'] );
            $template = $preset['endpoint'];

            if ( '' === $template && '' === $endpoint && ! empty( $preset['aws_endpoint'] ) ) {
                $template = $preset['aws_endpoint'];
            }

            if ( '' !== $template ) {
                $endpoint = str_replace(
                    array( '{account_id}', '{region}' ),
                    array( $location['account_id'], '' !== $location['region'] ? $location['region'] : $preset['region'] ),
                    $template
                );
            }

            if ( '' !== $endpoint && ! preg_match( '#^https?://#i', $endpoint ) ) {
                $endpoint = 'https://' . $endpoint;
            }

            return untrailingslashit( $endpoint );
        }

        public function sanitize_location( $input, array $existing = array() )
        {
            $input    = is_array( $input ) ? $input : array();
            $location = wp_parse_args( $existing, WordPlus_SDK_Storage::location_defaults() );
            $presets  = WordPlus_SDK_Storage::presets();

            $location['preset'] = isset( $input['preset'] ) && isset( $presets[ $input['preset'] ] ) ? $input['preset'] : $location['preset'];

            foreach ( array( 'endpoint', 'public_base', 'worker_url' ) as $field ) {
                if ( isset( $input[ $field ] ) ) {
                    $value = trim( (string) $input[ $field ] );

                    if ( '' !== $value && ! preg_match( '#^[a-z][a-z0-9+.\-]*://#i', $value ) ) {
                        $value = 'https://' . $value;
                    }

                    $location[ $field ] = esc_url_raw( $value, array( 'http', 'https' ) );
                }
            }

            foreach ( array( 'region', 'account_id', 'access_key' ) as $field ) {
                if ( isset( $input[ $field ] ) ) {
                    $location[ $field ] = preg_replace( '/[^A-Za-z0-9._\-\/+=]/', '', trim( (string) $input[ $field ] ) );
                }
            }

            if ( isset( $input['bucket'] ) ) {
                $location['bucket'] = preg_replace( '/[^A-Za-z0-9._\-]/', '', trim( (string) $input['bucket'] ) );
            }

            if ( isset( $input['prefix'] ) ) {
                $location['prefix'] = trim( (string) preg_replace( '/[^A-Za-z0-9._\-\/]/', '', (string) $input['prefix'] ), '/' );
            }

            if ( isset( $input['pull_zone_host'] ) ) {
                $host                       = preg_replace( '#^https?://#i', '', trim( (string) $input['pull_zone_host'] ) );
                $location['pull_zone_host'] = preg_replace( '/[^A-Za-z0-9.\-]/', '', untrailingslashit( $host ) );
            }

            if ( isset( $input['path_style'] ) ) {
                $location['path_style'] = in_array( (string) $input['path_style'], array( '1', 'true' ), true ) ? '1' : '0';
            }

            if ( isset( $input['delivery'] ) ) {
                $location['delivery'] = 'public' === $input['delivery'] ? 'public' : 'private';
            }

            foreach ( WordPlus_SDK_Storage::secret_fields() as $field ) {
                if ( isset( $input[ $field ] ) && WordPlus_SDK_Storage::SECRET_SET !== $input[ $field ] ) {
                    $location[ $field ] = trim( (string) $input[ $field ] );
                }
            }

            if ( isset( $input['public_acl'] ) ) {
                $location['public_acl'] = in_array( (string) $input['public_acl'], array( '1', 'true' ), true ) ? '1' : '0';
            }

            if ( isset( $input['direct'] ) ) {
                $location['direct'] = in_array( (string) $input['direct'], array( '0', 'false' ), true ) ? '0' : '1';
            }

            if ( '' === $location['region'] ) {
                $location['region'] = $presets[ $location['preset'] ]['region'];
            }

            if ( 'bunny' === $location['preset'] ) {
                if ( '' !== $location['bucket'] ) {
                    $location['access_key'] = $location['bucket'];
                }

                $location['public_base'] = '' !== $location['pull_zone_host'] ? 'https://' . $location['pull_zone_host'] : '';
            }

            $location['endpoint'] = $this->resolve_endpoint( $location );

            $host = strtolower( (string) wp_parse_url( $location['endpoint'], PHP_URL_HOST ) );

            if ( 's3' !== $location['preset'] ) {
                $location['path_style'] = $presets[ $location['preset'] ]['path_style'] ? '1' : '0';
            } elseif ( substr( $host, -strlen( '.amazonaws.com' ) ) === '.amazonaws.com' ) {
                $location['path_style'] = '0';
            }

            if ( 's3' !== $location['preset'] ) {
                $location['public_acl'] = '0';
            }

            return $location;
        }

        public function validate_location( array $location )
        {
            if ( 'r2' === $location['preset'] && '' === $location['account_id'] ) {
                return self::invalid( _x( 'Enter the account ID.', 'Storage', 'wordplus-sdk' ) );
            }

            if ( '' === $location['endpoint'] || ! wp_parse_url( $location['endpoint'], PHP_URL_HOST ) ) {
                return self::invalid( _x( 'Enter the endpoint of your storage provider.', 'Storage', 'wordplus-sdk' ) );
            }

            if ( '' === $location['bucket'] ) {
                return self::invalid( _x( 'Enter the bucket name.', 'Storage', 'wordplus-sdk' ) );
            }

            $resolved = WordPlus_SDK_Storage::with_constants( $location );

            if ( 'bunny' === $location['preset'] ) {
                if ( '' === $resolved['secret_key'] ) {
                    return self::invalid( _x( 'Enter the storage zone password.', 'Storage', 'wordplus-sdk' ) );
                }

                if ( '' === $location['pull_zone_host'] ) {
                    return self::invalid( _x( 'Enter the hostname of the Pull Zone connected to the storage zone.', 'Storage', 'wordplus-sdk' ) );
                }

                if ( 'public' !== $location['delivery'] && '' === $resolved['token_key'] ) {
                    return self::invalid( _x( 'Enter the Pull Zone\'s token authentication key, or choose Anyone with the link.', 'Storage', 'wordplus-sdk' ) );
                }
            }

            if ( '' === $resolved['access_key'] || '' === $resolved['secret_key'] ) {
                return self::invalid( _x( 'Enter the access key and the secret key.', 'Storage', 'wordplus-sdk' ) );
            }

            if ( 'public' === $location['delivery'] && '' === $location['public_base'] ) {
                return self::invalid( _x( 'Enter the public URL of the bucket, or choose private links.', 'Storage', 'wordplus-sdk' ) );
            }

            $private = $this->private_hosts( $location );

            if ( ! empty( $private ) ) {
                /* translators: %s: a host name or IP address */
                return self::invalid( sprintf( _x( '%s is not a public address. On a network, only a network administrator can connect a storage at a private or local address.', 'Storage', 'wordplus-sdk' ), $private[0] ) );
            }

            return true;
        }

        private static function invalid( $message )
        {
            return new WP_Error( 'wordplus_storage_invalid', $message, array( 'status' => 400 ) );
        }

        private static function identity( array $location )
        {
            return implode( '|', array(
                $location['preset'],
                untrailingslashit( strtolower( $location['endpoint'] ) ),
                $location['bucket'],
                trim( $location['prefix'], '/' ),
            ) );
        }

        private function type_location( $type, $input )
        {
            $settings = $this->storage->get_settings();
            $id       = $settings['current'][ $type ];
            $existing = '' !== $id ? $settings['locations'][ $id ] : $this->blank_location( $type );

            $input           = is_array( $input ) ? $input : array();
            $input['preset'] = $type;

            return $this->sanitize_location( $input, $existing );
        }

        public function form_location( WP_REST_Request $request )
        {
            $input = $request->get_param( 'location' );
            $type  = is_array( $input ) && isset( $input['preset'] ) && in_array( $input['preset'], WordPlus_SDK_Storage::types(), true ) ? $input['preset'] : 's3';

            return $this->type_location( $type, $input );
        }

        private function store_location( array &$settings, $type, array $location )
        {
            $current_id = $settings['current'][ $type ];
            $new_id     = $current_id;

            if ( '' === $current_id ) {
                $new_id = '';
            } elseif ( self::identity( $settings['locations'][ $current_id ] ) !== self::identity( $location ) && $this->storage->location_file_count( $current_id ) > 0 ) {
                $new_id = '';
            }

            if ( '' === $new_id ) {
                $new_id                 = 'loc' . strtolower( wp_generate_password( 8, false, false ) );
                $location['created_at'] = time();
            } elseif ( '1' === $settings['locations'][ $new_id ]['readonly'] ) {
                $location['readonly'] = '1';
            }

            $location['id'] = $new_id;

            $settings['locations'][ $new_id ] = $location;
            $settings['current'][ $type ]     = $new_id;
        }

        private function finish_save( array $settings )
        {
            $storage = $this->storage;

            foreach ( array_keys( $settings['locations'] ) as $id ) {
                if ( in_array( $id, $settings['current'], true ) ) {
                    continue;
                }

                if ( 0 === $storage->location_file_count( $id ) ) {
                    unset( $settings['locations'][ $id ] );
                }
            }

            foreach ( array_keys( $settings['locations'] ) as $id ) {
                delete_transient( 'wordplus_storage_direct_' . $id );
            }

            $storage->update_settings( $settings );

            foreach ( $storage->get_settings()['current'] as $id ) {
                $location = '' !== $id ? $storage->get_location( $id ) : null;

                if ( $location ) {
                    $storage->folder_state( $location, true );
                }
            }
        }

        public function rest_save_location( WP_REST_Request $request )
        {
            $type = (string) $request->get_param( 'type' );

            if ( ! in_array( $type, WordPlus_SDK_Storage::types(), true ) ) {
                return self::invalid( 'Unknown storage type' );
            }

            $location = $this->type_location( $type, $request->get_param( 'location' ) );
            $valid    = $this->validate_location( $location );

            if ( is_wp_error( $valid ) ) {
                return $valid;
            }

            $steps = array();

            if ( ! $request->get_param( 'force' ) ) {
                $test = $this->run_test( WordPlus_SDK_Storage::with_constants( $location ) );

                if ( ! $test['ok'] ) {
                    return array( 'saved' => false, 'steps' => $test['steps'] );
                }

                $steps = $test['steps'];
            }

            $settings = $this->storage->get_settings();

            $this->store_location( $settings, $type, $location );
            $this->finish_save( $settings );

            return array_merge( array( 'saved' => true, 'steps' => $steps ), $this->get_state() );
        }

        public function rest_save( WP_REST_Request $request )
        {
            $saved = $this->save_form( $request->get_param( 'storage' ) );

            return is_wp_error( $saved ) ? $saved : $this->get_state();
        }

        public function save_form( $form )
        {
            $settings = $this->storage->get_settings();
            $form     = is_array( $form ) ? $form : array();
            $presets  = WordPlus_SDK_Storage::presets();
            $enabled  = isset( $form['enabled'] ) && is_array( $form['enabled'] ) ? $form['enabled'] : array();
            $inputs   = isset( $form['locations'] ) && is_array( $form['locations'] ) ? $form['locations'] : array();

            foreach ( WordPlus_SDK_Storage::types() as $type ) {
                $on = isset( $enabled[ $type ] ) ? in_array( (string) $enabled[ $type ], array( '1', 'true' ), true ) : '' !== $settings['current'][ $type ];

                if ( ! $on ) {
                    if ( '' !== $settings['current'][ $type ] && $this->storage->in_use( $type ) ) {
                        /* translators: %s: a storage's name, such as Cloudflare R2 */
                        return self::invalid( sprintf( _x( '%s: files still go there. Choose another storage for them before you turn it off.', 'Storage', 'wordplus-sdk' ), $presets[ $type ]['label'] ) );
                    }

                    $settings['current'][ $type ] = '';
                    continue;
                }

                $location = $this->type_location( $type, isset( $inputs[ $type ] ) ? $inputs[ $type ] : array() );
                $valid    = $this->validate_location( $location );

                if ( is_wp_error( $valid ) ) {
                    return self::invalid( $presets[ $type ]['label'] . ': ' . $valid->get_error_message() );
                }

                $this->store_location( $settings, $type, $location );
            }

            $this->finish_save( $settings );

            return $this->form_state();
        }

        public function rest_disconnect( WP_REST_Request $request )
        {
            $type     = (string) $request->get_param( 'type' );
            $settings = $this->storage->get_settings();

            if ( ! in_array( $type, WordPlus_SDK_Storage::types(), true ) ) {
                return self::invalid( 'Unknown storage type' );
            }

            if ( $this->storage->in_use( $type ) ) {
                return new WP_Error( 'wordplus_storage_in_use', _x( 'Files still go to this storage. Choose another storage for them and save, then disconnect it.', 'Storage', 'wordplus-sdk' ), array( 'status' => 409 ) );
            }

            $settings['current'][ $type ] = '';

            $this->finish_save( $settings );

            return $this->get_state();
        }

        public function rest_resolve_folder( WP_REST_Request $request )
        {
            $storage  = $this->storage;
            $location = $storage->get_location( (string) $request->get_param( 'location' ) );
            $action   = (string) $request->get_param( 'action' );

            if ( ! $location || ! in_array( $action, array( 'moved', 'copy' ), true ) ) {
                return self::invalid( 'Unknown storage or action' );
            }

            if ( 'moved' === $action ) {
                if ( ! $storage->claim_folder( $location ) ) {
                    return new WP_Error( 'wordplus_storage_folder_failed', _x( 'Could not write to the storage. Run Test connection to see why.', 'Storage', 'wordplus-sdk' ), array( 'status' => 400 ) );
                }

                $storage->set_location_readonly( $location['id'], false );
            } else {
                $storage->set_location_readonly( $location['id'], true );
            }

            return $this->get_state();
        }

        public function rest_migration()
        {
            return $this->storage->queue()->migration_payload();
        }

        public function rest_migration_start( WP_REST_Request $request )
        {
            $started = $this->storage->queue()->start_migration( (string) $request->get_param( 'from' ), (string) $request->get_param( 'to' ) );

            return is_wp_error( $started ) ? $started : $this->rest_migration();
        }

        public function rest_migration_pause()
        {
            $this->storage->queue()->pause_migration();

            return $this->rest_migration();
        }

        public function rest_migration_resume()
        {
            $this->storage->queue()->resume_migration();

            return $this->rest_migration();
        }

        public function rest_migration_cancel()
        {
            $this->storage->queue()->cancel_migration();

            return $this->rest_migration();
        }

        public function explain( $result, $host, $preset = '' )
        {
            $code = isset( $result['error_code'] ) ? $result['error_code'] : '';

            if ( 'bunny' === $preset ) {
                switch ( $code ) {
                    case 'ServiceUnavailable':
                        return _x( 'This storage zone was created without S3 compatibility, and Bunny cannot turn it on later. Create a new storage zone with S3 Compatibility on.', 'Storage', 'wordplus-sdk' );
                    case 'AccessDenied':
                        return _x( 'Bunny refused the request. Choose the storage zone\'s main region as the Region.', 'Storage', 'wordplus-sdk' );
                    case 'SignatureDoesNotMatch':
                        return _x( 'The password does not match the storage zone. Copy it again from the S3 tab of the storage zone\'s Access page.', 'Storage', 'wordplus-sdk' );
                    case 'NoSuchBucket':
                        return _x( 'There is no storage zone with this name.', 'Storage', 'wordplus-sdk' );
                }
            }

            switch ( $code ) {
                case 'InvalidAccessKeyId':
                    return _x( 'The provider does not recognise this access key.', 'Storage', 'wordplus-sdk' );
                case 'SignatureDoesNotMatch':
                    return _x( 'The secret key does not match the access key.', 'Storage', 'wordplus-sdk' );
                case 'AccessDenied':
                    if ( false !== stripos( isset( $result['error'] ) ? (string) $result['error'] : '', 'signature' ) ) {
                        return _x( 'The secret key does not match the access key.', 'Storage', 'wordplus-sdk' );
                    }

                    return _x( 'The key is not allowed to do this in the bucket. Give it read and write access to the bucket.', 'Storage', 'wordplus-sdk' );
                case 'NoSuchBucket':
                    return _x( 'The bucket does not exist at this endpoint.', 'Storage', 'wordplus-sdk' );
                case 'RequestTimeTooSkewed':
                    return _x( 'The clock of this server is too far from the provider\'s clock. Fix the server time.', 'Storage', 'wordplus-sdk' );
                case 'PermanentRedirect':
                case 'AuthorizationHeaderMalformed':
                case 'IllegalLocationConstraintException':
                    return _x( 'The bucket is in another region. Check the region and the endpoint.', 'Storage', 'wordplus-sdk' );
            }

            if ( empty( $result['code'] ) ) {
                /* translators: 1: the storage's host name, 2: the connection error */
                return sprintf( _x( 'Could not connect to %1$s: %2$s', 'Storage', 'wordplus-sdk' ), $host, isset( $result['error'] ) ? $result['error'] : '' );
            }

            return trim( ( '' !== $code ? $code . ': ' : '' ) . ( isset( $result['error'] ) ? $result['error'] : '' ) );
        }

        public function rest_test( WP_REST_Request $request )
        {
            $location = WordPlus_SDK_Storage::with_constants( $this->form_location( $request ) );
            $valid    = $this->validate_location( $location );

            return is_wp_error( $valid ) ? $valid : $this->run_test( $location );
        }

        private static function step( $id, $status, $message )
        {
            return array( 'id' => $id, 'status' => $status, 'message' => $message );
        }

        public function run_test( array $location )
        {
            $storage = $this->storage;
            $client  = $storage->client( $location );
            $host    = (string) wp_parse_url( $location['endpoint'], PHP_URL_HOST );
            $key     = $this->test_key( $location, wp_generate_uuid4() . '.enc' );
            $body    = 'WordPlus connection test ' . gmdate( 'c' );
            $preset  = $location['preset'];
            $steps   = array();
            $ok      = true;

            $put_headers = array( 'content-type' => 'application/octet-stream' );

            if ( 'public' === $location['delivery'] && '1' === $location['public_acl'] ) {
                $put_headers['x-amz-acl'] = 'public-read';
            }

            $put = $client->put_string( $key, $body, $put_headers );

            if ( empty( $put['ok'] ) ) {
                return array( 'ok' => false, 'steps' => array( self::step( 'write', 'error', $this->explain( $put, $host, $preset ) ) ) );
            }

            $steps[] = self::step( 'write', 'ok', _x( 'A test file was uploaded.', 'Storage', 'wordplus-sdk' ) );

            $head = $client->head( $key );

            if ( empty( $head['ok'] ) || (int) $head['size'] !== strlen( $body ) ) {
                $ok      = false;
                $steps[] = self::step( 'read', 'error', empty( $head['ok'] ) ? $this->explain( $head, $host, $preset ) : _x( 'The stored file has a different size.', 'Storage', 'wordplus-sdk' ) );
            } else {
                $steps[] = self::step( 'read', 'ok', _x( 'The file was found with the right size.', 'Storage', 'wordplus-sdk' ) );
            }

            if ( 'bunny' === $preset ) {
                foreach ( $this->pull_zone_steps( $location, $key, $body ) as $step ) {
                    $ok      = $ok && 'error' !== $step['status'];
                    $steps[] = $step;
                }
            } else {
                $public = 'public' === $location['delivery'];
                $url    = $public ? $storage->public_url( $location, $key ) : $client->presign( 'GET', $key, 300 );
                $get    = $this->remote( $url, array( 'timeout' => 20 ) );

                if ( ! is_wp_error( $get ) && 200 === wp_remote_retrieve_response_code( $get ) && wp_remote_retrieve_body( $get ) === $body ) {
                    $steps[] = self::step( 'delivery', 'ok', $public ? _x( 'The file opens from its public URL.', 'Storage', 'wordplus-sdk' ) : _x( 'The file opens through a private link.', 'Storage', 'wordplus-sdk' ) );
                } else {
                    $ok     = false;
                    $status = is_wp_error( $get ) ? $get->get_error_message() : 'HTTP ' . wp_remote_retrieve_response_code( $get );

                    $steps[] = self::step( 'delivery', 'error', $public
                        /* translators: %s: an HTTP status or connection error */
                        ? sprintf( _x( 'The file does not open from its public URL (%s). Check the public URL and that the bucket allows public reads.', 'Storage', 'wordplus-sdk' ), $status )
                        /* translators: %s: an HTTP status or connection error */
                        : sprintf( _x( 'The file does not open through a private link (%s).', 'Storage', 'wordplus-sdk' ), $status ) );
                }

                if ( ! $public ) {
                    $raw = $this->remote( strtok( $client->presign( 'GET', $key, 60 ), '?' ), array( 'timeout' => 15 ) );

                    $steps[] = ! is_wp_error( $raw ) && 200 === wp_remote_retrieve_response_code( $raw )
                        ? self::step( 'private', 'warn', _x( 'Anyone can read files in this bucket without a link. Make the bucket private so private links mean something.', 'Storage', 'wordplus-sdk' ) )
                        : self::step( 'private', 'ok', _x( 'The bucket refuses anonymous reads.', 'Storage', 'wordplus-sdk' ) );
                }
            }

            $steps[] = $this->folder_step( $location );
            $steps[] = $this->preflight_step( $client, $key );

            if ( apply_filters( 'wordplus_storage_browser_reads', false ) ) {
                $steps[] = $this->download_cors_step( $location, $key );
            }

            if ( ! empty( $location['id'] ) ) {
                delete_transient( 'wordplus_storage_direct_' . $location['id'] );
            }

            $delete = $client->delete( $key );

            if ( empty( $delete['ok'] ) ) {
                $ok      = false;
                $steps[] = self::step( 'delete', 'error', $this->explain( $delete, $host, $preset ) );
            } else {
                $steps[] = self::step( 'delete', 'ok', _x( 'The test file was deleted.', 'Storage', 'wordplus-sdk' ) );

                $gone = $this->gone_step( $client->head( $key ), $host, $preset );

                if ( $gone ) {
                    $ok      = false;
                    $steps[] = $gone;
                }

                if ( 'bunny' === $preset ) {
                    $cache   = $this->pull_zone_cache_step( $location, $key );
                    $ok      = $ok && 'error' !== $cache['status'];
                    $steps[] = $cache;
                }
            }

            if ( $client->last_date ) {
                $skew = time() - $client->last_date;

                if ( abs( $skew ) > 300 ) {
                    /* translators: %d: seconds */
                    $steps[] = self::step( 'clock', 'warn', sprintf( _x( 'This server\'s clock is %d seconds off the provider\'s. Links fail beyond 15 minutes.', 'Storage', 'wordplus-sdk' ), $skew ) );
                }
            }

            return array( 'ok' => $ok, 'steps' => $steps );
        }

        public function cors_failure( array $result, $host, $preset = '' )
        {
            $code = isset( $result['error_code'] ) ? $result['error_code'] : '';

            if ( 'NotImplemented' === $code || 501 === (int) $result['code'] ) {
                return _x( 'This provider does not let applications set CORS. Add the CORS rule in its console: the setup guide has the rule to paste.', 'Storage', 'wordplus-sdk' );
            }

            if ( 'AccessDenied' === $code && false === stripos( isset( $result['error'] ) ? (string) $result['error'] : '', 'signature' ) ) {
                return _x( 'The key is not allowed to change the bucket\'s CORS. Add the CORS rule in the provider\'s console, or give the key that right: the setup guide has the rule to paste.', 'Storage', 'wordplus-sdk' );
            }

            return $this->explain( $result, $host, $preset );
        }

        public function gone_step( array $head, $host, $preset = '' )
        {
            $code = (int) $head['code'];

            if ( 404 === $code ) {
                return null;
            }

            if ( 403 === $code ) {
                return self::step( 'gone', 'error', _x( 'The key cannot tell a deleted file is gone: the bucket answers 403 for it instead of 404, so deleted files would stay pending. Let the key list the bucket (s3:ListBucket on Amazon S3).', 'Storage', 'wordplus-sdk' ) );
            }

            return self::step( 'gone', 'error', ! empty( $head['ok'] ) ? _x( 'The test file is still in the bucket after it was deleted.', 'Storage', 'wordplus-sdk' ) : $this->explain( $head, $host, $preset ) );
        }

        public function pull_zone_steps( array $location, $key, $body )
        {
            $storage = $this->storage;
            $public  = 'public' === $location['delivery'];
            $url     = $public ? $storage->public_url( $location, $key ) : WordPlus_SDK_Storage::pull_zone_url( $location, $key, time() + 300 );
            $get     = $this->remote( $url, array( 'timeout' => 20 ) );
            $code    = is_wp_error( $get ) ? 0 : (int) wp_remote_retrieve_response_code( $get );
            $steps   = array();

            if ( 200 === $code && wp_remote_retrieve_body( $get ) === $body ) {
                $steps[] = self::step( 'delivery', 'ok', $public ? _x( 'The file opens from the Pull Zone.', 'Storage', 'wordplus-sdk' ) : _x( 'The file opens through a signed Pull Zone link.', 'Storage', 'wordplus-sdk' ) );
            } elseif ( 403 === $code ) {
                $steps[] = self::step( 'delivery', 'error', $public
                    ? _x( 'The Pull Zone refuses the file without a token (HTTP 403). Turn off Token authentication in the Pull Zone\'s Security settings, or choose signed links.', 'Storage', 'wordplus-sdk' )
                    : _x( 'The Pull Zone refuses the signed link (HTTP 403). Check the token authentication key, and that Token IP validation is off.', 'Storage', 'wordplus-sdk' ) );
            } elseif ( 404 === $code ) {
                $steps[] = self::step( 'delivery', 'error', _x( 'The Pull Zone does not find the file (HTTP 404). Check that this storage zone is the Pull Zone\'s origin.', 'Storage', 'wordplus-sdk' ) );
            } else {
                /* translators: %s: an HTTP status or connection error */
                $steps[] = self::step( 'delivery', 'error', sprintf( _x( 'The file does not open from the Pull Zone (%s). Check the Pull Zone hostname.', 'Storage', 'wordplus-sdk' ), is_wp_error( $get ) ? $get->get_error_message() : 'HTTP ' . $code ) );
            }

            if ( ! $public ) {
                $raw  = $this->remote( $storage->public_url( array( 'public_base' => 'https://' . $location['pull_zone_host'] ), $key ), array( 'timeout' => 15 ) );
                $open = ! is_wp_error( $raw ) && 200 === (int) wp_remote_retrieve_response_code( $raw );

                $steps[] = $open
                    ? self::step( 'private', 'warn', _x( 'The Pull Zone opens files without a token, so anyone can open them. Turn on Token authentication in the Pull Zone\'s Security settings.', 'Storage', 'wordplus-sdk' ) )
                    : self::step( 'private', 'ok', _x( 'The Pull Zone refuses links without a token.', 'Storage', 'wordplus-sdk' ) );
            }

            return $steps;
        }

        public function pull_zone_cache_step( array $location, $key )
        {
            if ( '' === $location['api_key'] ) {
                return self::step( 'cache', 'warn', 'public' === $location['delivery']
                    ? _x( 'Without your Bunny API key, a deleted file keeps opening from Bunny\'s cache for anyone who has its link, until the cache expires.', 'Storage', 'wordplus-sdk' )
                    : _x( 'Without your Bunny API key, a deleted file keeps opening from Bunny\'s cache for anyone who already has its link, for up to a day and a half.', 'Storage', 'wordplus-sdk' ) );
            }

            $purged = $this->storage->purge_pull_zone( $location, $key );

            if ( true === $purged ) {
                return self::step( 'cache', 'ok', _x( 'A deleted file is cleared from Bunny\'s cache at once.', 'Storage', 'wordplus-sdk' ) );
            }

            return self::step( 'cache', 'error', false !== strpos( $purged, 'API key' )
                ? _x( 'Bunny refused the API key, so deleted files cannot be cleared from its cache. Copy the key again from Account, API key in Bunny.', 'Storage', 'wordplus-sdk' )
                /* translators: %s: Bunny's answer */
                : sprintf( _x( 'Could not clear the deleted test file from Bunny\'s cache: %s.', 'Storage', 'wordplus-sdk' ), $purged ) );
        }

        public function preflight( WordPlus_SDK_Storage_S3 $client, $key, $origin )
        {
            $url = $client->presign( 'PUT', $key, 300, array(), array( 'content-type' => 'image/jpeg' ) );

            $response = $this->remote( $url, array(
                'method'  => 'OPTIONS',
                'timeout' => 15,
                'headers' => array(
                    'Origin'                         => $origin,
                    'Access-Control-Request-Method'  => 'PUT',
                    'Access-Control-Request-Headers' => 'content-type',
                ),
            ) );

            if ( is_wp_error( $response ) ) {
                return false;
            }

            $allow = (string) wp_remote_retrieve_header( $response, 'access-control-allow-origin' );

            return wp_remote_retrieve_response_code( $response ) < 300 && ( '*' === $allow || $allow === $origin );
        }

        public function folder_step( array $location )
        {
            $folder = $this->storage->folder_state( $location, true );
            $site   = $folder['site'];

            switch ( $folder['state'] ) {
                case 'own':
                    return self::step( 'folder', 'ok', _x( 'The folder belongs to this site.', 'Storage', 'wordplus-sdk' ) );
                case 'shared':
                    return self::step( 'folder', 'warn', '' !== $site
                        /* translators: %s: another site's address */
                        ? sprintf( _x( 'Another site, %s, keeps files in this folder too. This site deletes only its own files there and does not clean up leftovers. Give each site its own folder.', 'Storage', 'wordplus-sdk' ), $site )
                        : _x( 'Another site keeps files in this folder too. This site deletes only its own files there and does not clean up leftovers. Give each site its own folder.', 'Storage', 'wordplus-sdk' ) );
                case 'copy':
                    /* translators: %s: another site's address */
                    return self::step( 'folder', 'warn', sprintf( _x( 'The folder belongs to %s. If this site is a copy of it, nothing is written to or deleted from the folder until you choose what to do in the storage settings.', 'Storage', 'wordplus-sdk' ), $site ) );
                default:
                    return self::step( 'folder', 'warn', _x( 'Could not read or write the folder\'s ownership file. Moves and deletes wait until it can.', 'Storage', 'wordplus-sdk' ) );
            }
        }

        public function download_cors_step( array $location, $key )
        {
            $storage = $this->storage;

            if ( 'public' === $location['delivery'] ) {
                $url = $storage->public_url( $location, $key );
            } elseif ( 'bunny' === $location['preset'] ) {
                $url = WordPlus_SDK_Storage::pull_zone_url( $location, $key, time() + 300 );
            } elseif ( $storage->uses_worker( $location ) ) {
                $url = WordPlus_SDK_Storage::worker_url( $location, $key, time() + 300, 'attachment', 'test.enc' );
            } else {
                $url = $storage->client( $location )->presign( 'GET', $key, 300 );
            }

            $missing = array();

            foreach ( array_filter( $this->origins(), array( __CLASS__, 'is_web_origin' ) ) as $origin ) {
                $get   = $this->remote( $url, array( 'timeout' => 15, 'headers' => array( 'Origin' => $origin ) ) );
                $allow = is_wp_error( $get ) ? '' : (string) wp_remote_retrieve_header( $get, 'access-control-allow-origin' );

                if ( '*' !== $allow && $allow !== $origin ) {
                    $missing[] = $origin;
                }
            }

            if ( empty( $missing ) ) {
                return self::step( 'download', 'ok', _x( 'Browsers can open encrypted files from the storage (CORS allows this site).', 'Storage', 'wordplus-sdk' ) );
            }

            if ( 'bunny' === $location['preset'] ) {
                return self::step( 'download', 'warn', _x( 'Encrypted files will not open in browsers: the Pull Zone sends them without a CORS header. In Bunny, open the Pull Zone\'s Headers settings and add enc to the Extension List under Add CORS headers.', 'Storage', 'wordplus-sdk' ) );
            }

            $endpoint_host = (string) wp_parse_url( $location['endpoint'], PHP_URL_HOST );
            $public_host   = (string) wp_parse_url( $location['public_base'], PHP_URL_HOST );

            if ( 'public' === $location['delivery'] && substr( $endpoint_host, -15 ) === 'backblazeb2.com' && substr( $public_host, -15 ) !== 'backblazeb2.com' ) {
                /* translators: %s: the site's addresses */
                return self::step( 'download', 'warn', sprintf( _x( 'Encrypted files will not open in browsers on %s: Backblaze serves files on your own domain from its download address, which the bucket\'s CORS rule does not cover. In Cloudflare, add a response header rule for that domain that sets Access-Control-Allow-Origin: the setup guide shows how.', 'Storage', 'wordplus-sdk' ), implode( ', ', $missing ) ) );
            }

            /* translators: %s: the site's addresses */
            return self::step( 'download', 'warn', sprintf( _x( 'Encrypted files will not open in browsers on %s: the bucket\'s CORS does not allow it. Use Configure CORS, or add the CORS rule in the provider\'s console: the setup guide has the rule to paste.', 'Storage', 'wordplus-sdk' ), implode( ', ', $missing ) ) );
        }

        private function preflight_step( WordPlus_SDK_Storage_S3 $client, $key )
        {
            $missing = array();

            foreach ( $this->origins() as $origin ) {
                if ( ! $this->preflight( $client, $key, $origin ) ) {
                    $missing[] = $origin;
                }
            }

            if ( empty( $missing ) ) {
                return self::step( 'cors', 'ok', _x( 'Browsers can upload straight to the bucket (CORS allows this site).', 'Storage', 'wordplus-sdk' ) );
            }

            if ( ! array_filter( $missing, array( __CLASS__, 'is_web_origin' ) ) ) {
                /* translators: %s: app addresses, such as capacitor://localhost */
                return self::step( 'cors', 'ok', sprintf( _x( 'Browsers can upload straight to the bucket (CORS allows this site). The iOS app uploads through this server, because the bucket\'s CORS does not allow its origin (%s): some providers, such as Backblaze B2, do not accept it.', 'Storage', 'wordplus-sdk' ), implode( ', ', $missing ) ) );
            }

            /* translators: %s: the site's addresses */
            return self::step( 'cors', 'warn', sprintf( _x( 'CORS does not allow uploads from %s yet. Files still upload through this server. Use Configure CORS to allow direct uploads.', 'Storage', 'wordplus-sdk' ), implode( ', ', $missing ) ) );
        }

        public function cors_rule_xml( array $origins )
        {
            $xml = '<CORSRule><ID>' . self::CORS_RULE_ID . '</ID>';

            foreach ( $origins as $origin ) {
                $xml .= '<AllowedOrigin>' . htmlspecialchars( $origin, ENT_XML1 ) . '</AllowedOrigin>';
            }

            foreach ( array( 'GET', 'HEAD', 'PUT' ) as $method ) {
                $xml .= '<AllowedMethod>' . $method . '</AllowedMethod>';
            }

            $xml .= '<AllowedHeader>*</AllowedHeader><ExposeHeader>ETag</ExposeHeader><MaxAgeSeconds>3600</MaxAgeSeconds></CORSRule>';

            return $xml;
        }

        public function rest_configure_cors( WP_REST_Request $request )
        {
            $location = null !== $request->get_param( 'location' )
                ? WordPlus_SDK_Storage::with_constants( $this->form_location( $request ) )
                : $this->storage->current_location( (string) $request->get_param( 'type' ) );

            if ( ! $location ) {
                return self::invalid( _x( 'Save a storage first.', 'Storage', 'wordplus-sdk' ) );
            }

            $valid = $this->validate_location( $location );

            if ( is_wp_error( $valid ) ) {
                return $valid;
            }

            if ( ! WordPlus_SDK_Storage::preset( $location['preset'] )['cors_api'] ) {
                return new WP_Error( 'wordplus_storage_cors_unsupported', _x( 'This provider does not let applications change CORS. Set it in the provider\'s console.', 'Storage', 'wordplus-sdk' ), array( 'status' => 400 ) );
            }

            $client  = $this->storage->client( $location );
            $host    = (string) wp_parse_url( $location['endpoint'], PHP_URL_HOST );
            $current = $client->get_cors();
            $rules   = array();
            $origins = $this->origins();
            $ours    = array( self::CORS_RULE_ID, self::LEGACY_CORS_RULE_ID );

            if ( ! empty( $current['ok'] ) && preg_match_all( '#<CORSRule>.*?</CORSRule>#s', $current['body'], $matches ) ) {
                foreach ( $matches[0] as $rule ) {
                    $id = preg_match( '#<ID>([^<]*)</ID>#', $rule, $m ) ? $m[1] : '';

                    if ( ! in_array( $id, $ours, true ) ) {
                        $rules[] = $rule;
                        continue;
                    }

                    if ( preg_match_all( '#<AllowedOrigin>([^<]*)</AllowedOrigin>#', $rule, $existing ) ) {
                        foreach ( $existing[1] as $origin ) {
                            $origin = html_entity_decode( $origin, ENT_QUOTES | ENT_XML1, 'UTF-8' );

                            if ( ! in_array( $origin, $origins, true ) ) {
                                $origins[] = $origin;
                            }
                        }
                    }
                }
            } elseif ( empty( $current['ok'] ) && 404 !== (int) $current['code'] && ( isset( $current['error_code'] ) ? $current['error_code'] : '' ) !== 'NoSuchCORSConfiguration' ) {
                return new WP_Error( 'wordplus_storage_cors_failed', $this->cors_failure( $current, $host, $location['preset'] ), array( 'status' => 400 ) );
            }

            $rules[] = $this->cors_rule_xml( $origins );

            $put = $client->put_cors( '<?xml version="1.0" encoding="UTF-8"?><CORSConfiguration>' . implode( '', $rules ) . '</CORSConfiguration>' );
            $web = array_values( array_filter( $origins, array( __CLASS__, 'is_web_origin' ) ) );

            if ( empty( $put['ok'] ) && 400 === (int) $put['code'] && ! empty( $web ) && count( $web ) < count( $origins ) ) {
                $rules[ count( $rules ) - 1 ] = $this->cors_rule_xml( $web );

                $put = $client->put_cors( '<?xml version="1.0" encoding="UTF-8"?><CORSConfiguration>' . implode( '', $rules ) . '</CORSConfiguration>' );
            }

            if ( empty( $put['ok'] ) ) {
                return new WP_Error( 'wordplus_storage_cors_failed', $this->cors_failure( $put, $host, $location['preset'] ), array( 'status' => 400 ) );
            }

            $probe = $this->test_key( $location, 'cors-check.txt' );
            $step  = null;

            for ( $attempt = 0; $attempt < 6; $attempt++ ) {
                if ( $attempt > 0 ) {
                    sleep( 2 );
                }

                $step = $this->preflight_step( $client, $probe );

                if ( 'ok' === $step['status'] ) {
                    break;
                }
            }

            if ( 'ok' !== $step['status'] ) {
                $step['message'] = _x( 'The CORS rule is saved in the bucket, but the provider does not apply it yet. Some providers take up to a minute. Run Test connection again shortly.', 'Storage', 'wordplus-sdk' );
            }

            if ( ! empty( $location['id'] ) ) {
                delete_transient( 'wordplus_storage_direct_' . $location['id'] );
            }

            return array( 'ok' => true, 'steps' => array( $step ) );
        }

        public function rest_worker_script()
        {
            $path = $this->storage->sdk()->path( 'storage/worker.js' );

            return array( 'script' => is_readable( $path ) ? (string) file_get_contents( $path ) : '' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
        }

        public function rest_test_worker( WP_REST_Request $request )
        {
            $location = WordPlus_SDK_Storage::with_constants( $this->form_location( $request ) );
            $valid    = $this->validate_location( $location );

            if ( is_wp_error( $valid ) ) {
                return $valid;
            }

            if ( '' === $location['worker_url'] || '' === $location['worker_secret'] ) {
                return self::invalid( _x( 'Enter the Worker URL and its secret first.', 'Storage', 'wordplus-sdk' ) );
            }

            $client = $this->storage->client( $location );
            $host   = (string) wp_parse_url( $location['endpoint'], PHP_URL_HOST );
            $key    = $this->test_key( $location, 'worker-' . wp_generate_uuid4() . '.txt' );
            $body   = 'WordPlus Worker test ' . gmdate( 'c' );
            $steps  = array();

            $put = $client->put_string( $key, $body, array( 'content-type' => 'text/plain' ) );

            if ( empty( $put['ok'] ) ) {
                return array( 'ok' => false, 'steps' => array( self::step( 'write', 'error', $this->explain( $put, $host ) ) ) );
            }

            $valid = WordPlus_SDK_Storage::worker_url( $location, $key, time() + 600, 'attachment', 'test.txt' );
            $get   = $this->remote( $valid, array( 'timeout' => 20 ) );
            $code  = is_wp_error( $get ) ? 0 : (int) wp_remote_retrieve_response_code( $get );

            if ( 200 === $code && wp_remote_retrieve_body( $get ) === $body ) {
                $steps[] = self::step( 'signed', 'ok', _x( 'A signed link opens the file through the Worker.', 'Storage', 'wordplus-sdk' ) );

                if ( 0 !== strpos( (string) wp_remote_retrieve_header( $get, 'content-disposition' ), 'attachment' ) ) {
                    $steps[] = self::step( 'headers', 'warn', _x( 'The Worker does not set the download name. Paste the current Worker script again.', 'Storage', 'wordplus-sdk' ) );
                }
            } else {
                $detail = is_wp_error( $get ) ? $get->get_error_message() : 'HTTP ' . $code;

                if ( ! is_wp_error( $get ) && $this->trusted_with_server() ) {
                    $detail = trim( $detail . ' ' . wp_strip_all_tags( substr( (string) wp_remote_retrieve_body( $get ), 0, 200 ) ) );
                }

                /* translators: %s: an HTTP status or connection error */
                $steps[] = self::step( 'signed', 'error', sprintf( _x( 'A signed link does not open the file through the Worker (%s). Check the Worker URL, the R2_BUCKET binding and the WORKER_SECRET secret.', 'Storage', 'wordplus-sdk' ), $detail ) );
            }

            $range = $this->remote( $valid, array( 'timeout' => 20, 'headers' => array( 'Range' => 'bytes=0-9' ) ) );

            $steps[] = ! is_wp_error( $range ) && 206 === (int) wp_remote_retrieve_response_code( $range ) && wp_remote_retrieve_body( $range ) === substr( $body, 0, 10 )
                ? self::step( 'range', 'ok', _x( 'Partial downloads work, so videos can seek.', 'Storage', 'wordplus-sdk' ) )
                : self::step( 'range', 'warn', _x( 'Partial downloads do not work, so videos may not seek on iPhone. Paste the current Worker script again.', 'Storage', 'wordplus-sdk' ) );

            $forged       = preg_replace( '/sig=[^&]+/', 'sig=' . rtrim( strtr( base64_encode( random_bytes( 32 ) ), '+/', '-_' ), '=' ), $valid ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
            $denied       = $this->remote( $forged, array( 'timeout' => 20 ) );
            $expired      = $this->remote( WordPlus_SDK_Storage::worker_url( $location, $key, time() - 60, 'attachment', 'test.txt' ), array( 'timeout' => 20 ) );
            $forged_code  = is_wp_error( $denied ) ? 0 : (int) wp_remote_retrieve_response_code( $denied );
            $expired_code = is_wp_error( $expired ) ? 0 : (int) wp_remote_retrieve_response_code( $expired );

            $steps[] = 403 === $forged_code && 403 === $expired_code
                ? self::step( 'forged', 'ok', _x( 'Forged and expired links are refused.', 'Storage', 'wordplus-sdk' ) )
                /* translators: 1: HTTP status of a forged link, 2: HTTP status of an expired link */
                : self::step( 'forged', 'error', sprintf( _x( 'The Worker does not refuse bad links (forged: HTTP %1$d, expired: HTTP %2$d). Do not use it until this passes.', 'Storage', 'wordplus-sdk' ), $forged_code, $expired_code ) );

            $client->delete( $key );

            $ok = true;

            foreach ( $steps as $step ) {
                $ok = $ok && 'error' !== $step['status'];
            }

            return array( 'ok' => $ok, 'steps' => $steps );
        }
    }
}
