<?php
defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WordPlus_SDK_Geo' ) ) {
    final class WordPlus_SDK_Geo
    {
        const OPTION = 'wordplus_geo';

        const DAILY = 'wordplus_geo_daily';

        const LOCK = 'wordplus_geo_updating';

        const SOURCE = 'https://download.db-ip.com/free/';

        const OLD_AFTER = 35;

        const FOLDER = 'wordplus/geo';

        const ATTRIBUTION = array( 'text' => 'IP Geolocation by DB-IP', 'url' => 'https://db-ip.com', 'licence' => 'https://creativecommons.org/licenses/by/4.0/' );

        const ADMIN = 'wordplus-geo-admin';

        const REST_NAMESPACE = 'wordplus/v1';

        const DAYS = 90;

        private $sdk;

        private $all = null;

        private $reader = null;

        private $found = array();

        public function __construct( WordPlus_SDK $sdk )
        {
            $this->sdk = $sdk;
        }

        public function settings()
        {
            if ( null === $this->all ) {
                $all = get_option( self::OPTION, array() );
                $all = is_array( $all ) ? $all : array();

                $keep = (bool) apply_filters( 'wordplus_geo_keep_default', true );

                $this->all = array_merge( array(
                    'database' => 'none',
                    'file'     => '',
                    'month'    => '',
                    'updated'  => 0,
                    'tried'    => 0,
                    'error'    => '',
                    'keep'     => $keep,
                    'days'     => self::DAYS,
                    'flags'    => false,
                    'imported' => false,
                ), $all );
            }

            return $this->all;
        }

        private function put( array $all )
        {
            $this->all = $all;
            update_option( self::OPTION, $all, false );
        }

        public function forget()
        {
            $this->all    = null;
            $this->reader = null;
            $this->found  = array();
        }

        public function keeps_addresses()
        {
            return (bool) $this->settings()['keep'];
        }

        public function days()
        {
            return max( 1, min( 3650, (int) $this->settings()['days'] ) );
        }

        public function flags_shown()
        {
            return (bool) $this->settings()['flags'];
        }

        public function flags_url()
        {
            return $this->sdk->url( 'geo/flags/' );
        }

        public function save( array $fields )
        {
            $all    = $this->settings();
            $before = $all['database'];

            if ( isset( $fields['database'] ) && in_array( $fields['database'], array( 'none', 'country', 'city' ), true ) ) {
                $all['database'] = $fields['database'];
            }

            if ( array_key_exists( 'keep', $fields ) ) {
                $all['keep'] = (bool) $fields['keep'];
            }

            if ( isset( $fields['days'] ) ) {
                $all['days'] = max( 1, min( 3650, (int) $fields['days'] ) );
            }

            if ( array_key_exists( 'flags', $fields ) ) {
                $all['flags'] = (bool) $fields['flags'];
            }

            $this->put( $all );

            if ( 'none' === $all['database'] && 'none' !== $before ) {
                $this->remove_files();
            }

            if ( ! $all['keep'] && $this->sdk->addresses() ) {
                $this->sdk->addresses()->empty_all();
            }

            $this->schedule();

            return $this->present();
        }

        public function settle( array $fields )
        {
            $stored = get_option( self::OPTION, array() );
            $stored = is_array( $stored ) ? $stored : array();
            $new    = array();

            if ( array_key_exists( 'keep', $fields ) && ! array_key_exists( 'keep', $stored ) ) {
                $new['keep'] = (bool) $fields['keep'];
            }

            if ( isset( $fields['days'] ) && ! array_key_exists( 'days', $stored ) ) {
                $new['days'] = max( 1, min( 3650, (int) $fields['days'] ) );
            }

            if ( array() === $new ) {
                return false;
            }

            update_option( self::OPTION, array_merge( $stored, $new ), false );
            $this->all = null;
            $this->schedule();

            return true;
        }

        public function schedule()
        {
            $all  = $this->settings();
            $need = 'none' !== $all['database'] || $all['keep'];

            if ( $need && ! wp_next_scheduled( self::DAILY ) ) {
                wp_schedule_event( time() + 60, 'daily', self::DAILY );
            }
        }

        public function downloaded()
        {
            $all  = $this->settings();
            $path = $this->database_path();

            return 'none' !== $all['database'] && '' !== $path && is_readable( $path ) && 0 === strpos( (string) $all['file'], 'dbip-' . $all['database'] . '-' );
        }

        public function daily()
        {
            $all = $this->settings();

            if ( 'none' !== $all['database'] && $this->due() ) {
                $this->download();
            }

            if ( $this->sdk->addresses() ) {
                $this->sdk->addresses()->prune();
            }
        }

        private function due()
        {
            $all = $this->settings();

            if ( ! $this->downloaded() ) {
                return false;
            }

            return (int) $all['updated'] < time() - self::OLD_AFTER * DAY_IN_SECONDS && (int) $all['tried'] < time() - DAY_IN_SECONDS;
        }

        public function locate( $ip, $server = null )
        {
            $none = array( 'country' => '', 'region' => '', 'city' => '', 'source' => '' );

            require_once $this->sdk->path( 'src/class-client.php' );

            $ip = WordPlus_SDK_Client::address( $ip );

            if ( '' === $ip ) {
                return $none;
            }

            if ( isset( $this->found[ $ip ] ) && null === $server ) {
                return $this->found[ $ip ];
            }

            $filtered = apply_filters( 'wordplus_geo_locate', null, $ip );

            if ( is_array( $filtered ) ) {
                return $this->keep( $ip, self::shape( $filtered, 'filter' ), $server );
            }

            $found = $this->from_cloudflare( $ip, $server );

            if ( '' === $found['city'] ) {
                $database = $this->from_database( $ip );

                if ( '' !== $database['country'] && ( '' === $found['country'] || $found['country'] === $database['country'] ) ) {
                    $found = array(
                        'country' => $database['country'],
                        'region'  => '' !== $found['region'] ? $found['region'] : $database['region'],
                        'city'    => $database['city'],
                        'source'  => '' !== $found['source'] ? $found['source'] : 'database',
                    );
                }
            }

            if ( '' === $found['country'] && class_exists( 'WC_Geolocation' ) && method_exists( 'WC_Geolocation', 'geolocate_ip' ) ) {
                $woo = WC_Geolocation::geolocate_ip( $ip, false, false );

                if ( ! empty( $woo['country'] ) ) {
                    $found = self::shape( array( 'country' => $woo['country'] ), 'woocommerce' );
                }
            }

            return $this->keep( $ip, $found, $server );
        }

        private function keep( $ip, array $found, $server )
        {
            if ( null === $server ) {
                $this->found[ $ip ] = $found;
            }

            return $found;
        }

        public static function shape( array $found, $source = '' )
        {
            $country = strtoupper( trim( isset( $found['country'] ) ? (string) $found['country'] : '' ) );

            if ( 1 !== preg_match( '/^[A-Z]{2}$/', $country ) || in_array( $country, array( 'XX', 'T1', 'EU', 'AP' ), true ) ) {
                $country = '';
            }

            $text = function ( $key ) use ( $found ) {
                return isset( $found[ $key ] ) ? mb_substr( sanitize_text_field( (string) $found[ $key ] ), 0, 80 ) : '';
            };

            return array(
                'country' => $country,
                'region'  => '' === $country ? '' : $text( 'region' ),
                'city'    => '' === $country ? '' : $text( 'city' ),
                'source'  => '' === $country ? '' : (string) $source,
            );
        }

        private function from_cloudflare( $ip, $server )
        {
            $server = is_array( $server ) ? $server : $_SERVER; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput

            if ( empty( $server['HTTP_CF_IPCOUNTRY'] ) || WordPlus_SDK_Client::ip( $server ) !== $ip || ! WordPlus_SDK_Client::from_cloudflare( $server ) ) {
                return self::shape( array() );
            }

            $city = isset( $server['HTTP_CF_IPCITY'] ) ? (string) $server['HTTP_CF_IPCITY'] : '';

            if ( '' !== $city && function_exists( 'mb_check_encoding' ) && ! mb_check_encoding( $city, 'UTF-8' ) ) {
                $city = '';
            }

            return self::shape( array(
                'country' => wp_unslash( (string) $server['HTTP_CF_IPCOUNTRY'] ),
                'region'  => isset( $server['HTTP_CF_REGION'] ) ? wp_unslash( (string) $server['HTTP_CF_REGION'] ) : '',
                'city'    => wp_unslash( $city ),
            ), 'cloudflare' );
        }

        private function from_database( $ip )
        {
            $reader = $this->reader();

            if ( ! $reader ) {
                return self::shape( array() );
            }

            try {
                $record = $reader->get( $ip );
            } catch ( \Throwable $e ) {
                return self::shape( array() );
            }

            if ( ! is_array( $record ) ) {
                return self::shape( array() );
            }

            $name = function ( $node ) {
                if ( ! is_array( $node ) || empty( $node['names'] ) || ! is_array( $node['names'] ) ) {
                    return '';
                }

                return isset( $node['names']['en'] ) ? (string) $node['names']['en'] : (string) reset( $node['names'] );
            };

            return self::shape( array(
                'country' => isset( $record['country']['iso_code'] ) ? (string) $record['country']['iso_code'] : '',
                'region'  => isset( $record['subdivisions'][0] ) ? $name( $record['subdivisions'][0] ) : '',
                'city'    => isset( $record['city'] ) ? $name( $record['city'] ) : '',
            ), 'database' );
        }

        private function reader()
        {
            if ( null !== $this->reader ) {
                return $this->reader;
            }

            $this->reader = false;
            $path         = $this->database_path();

            if ( '' === $path || ! is_readable( $path ) ) {
                return false;
            }

            $this->require_reader();

            try {
                $this->reader = new \WordPlusSDK\MaxMind\Db\Reader( $path );
            } catch ( \Throwable $e ) {
                $this->reader = false;
            }

            return $this->reader;
        }

        private function require_reader()
        {
            if ( ! class_exists( '\WordPlusSDK\MaxMind\Db\Reader', false ) ) {
                foreach ( array( 'Reader/InvalidDatabaseException.php', 'Reader/Util.php', 'Reader/Metadata.php', 'Reader/Decoder.php', 'Reader.php' ) as $file ) {
                    require_once __DIR__ . '/geo/reader/' . $file;
                }
            }
        }

        public function folder()
        {
            $uploads = wp_upload_dir( null, false );

            return empty( $uploads['basedir'] ) ? '' : trailingslashit( $uploads['basedir'] ) . self::FOLDER;
        }

        public function database_path()
        {
            $file = (string) $this->settings()['file'];

            return '' === $file || '' === $this->folder() ? '' : $this->folder() . '/' . basename( $file );
        }

        public function download()
        {
            $all  = $this->settings();
            $kind = (string) $all['database'];

            if ( ! in_array( $kind, array( 'country', 'city' ), true ) ) {
                return new WP_Error( 'wordplus_geo_none', __( 'No database is selected.', 'wordplus-sdk' ) );
            }

            if ( get_transient( self::LOCK ) ) {
                return new WP_Error( 'wordplus_geo_busy', __( 'The database is already being downloaded.', 'wordplus-sdk' ) );
            }

            set_transient( self::LOCK, 1, 15 * MINUTE_IN_SECONDS );

            $all['tried'] = time();
            $this->put( $all );

            $result = $this->fetch( $kind );

            delete_transient( self::LOCK );

            $all          = $this->settings();
            $all['error'] = is_wp_error( $result ) ? $result->get_error_message() : '';

            if ( ! is_wp_error( $result ) ) {
                $all['file']    = $result['file'];
                $all['month']   = $result['month'];
                $all['updated'] = time();
            }

            $this->put( $all );
            $this->reader = null;
            $this->found  = array();

            if ( ! is_wp_error( $result ) ) {
                $this->remove_files( $result['file'] );
            }

            return is_wp_error( $result ) ? $result : true;
        }

        private function fetch( $kind )
        {
            $folder = $this->folder();

            if ( '' === $folder || ! wp_mkdir_p( $folder ) ) {
                return new WP_Error( 'wordplus_geo_folder', __( 'The uploads folder is not writable.', 'wordplus-sdk' ) );
            }

            if ( ! file_exists( $folder . '/index.php' ) ) {
                file_put_contents( $folder . '/index.php', "<?php\n// Silence is golden.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
            }

            if ( ! function_exists( 'download_url' ) ) {
                require_once ABSPATH . 'wp-admin/includes/file.php';
            }

            if ( ! function_exists( 'gzopen' ) ) {
                return new WP_Error( 'wordplus_geo_zlib', __( 'The zlib extension of PHP is required to unpack the database.', 'wordplus-sdk' ) );
            }

            $last = null;

            foreach ( array( gmdate( 'Y-m' ), gmdate( 'Y-m', strtotime( 'first day of last month' ) ) ) as $month ) {
                $name = 'dbip-' . $kind . '-lite-' . $month . '.mmdb';
                $url  = self::SOURCE . $name . '.gz';

                $packed = download_url( $url, 300 );

                if ( is_wp_error( $packed ) ) {
                    $last = $packed;
                    continue;
                }

                $unpacked = $folder . '/' . $name . '.part';
                $error    = self::unpack( $packed, $unpacked );

                if ( function_exists( 'wp_delete_file' ) ) {
                    wp_delete_file( $packed );
                } else {
                    @unlink( $packed ); // phpcs:ignore
                }

                if ( is_wp_error( $error ) ) {
                    @unlink( $unpacked ); // phpcs:ignore
                    $last = $error;
                    continue;
                }

                if ( ! $this->answers( $unpacked, $kind ) ) {
                    @unlink( $unpacked ); // phpcs:ignore
                    $last = new WP_Error( 'wordplus_geo_broken', __( 'The downloaded database could not be read.', 'wordplus-sdk' ) );
                    continue;
                }

                if ( ! @rename( $unpacked, $folder . '/' . $name ) ) { // phpcs:ignore
                    @unlink( $unpacked ); // phpcs:ignore
                    return new WP_Error( 'wordplus_geo_folder', __( 'The uploads folder is not writable.', 'wordplus-sdk' ) );
                }

                return array( 'file' => $name, 'month' => $month );
            }

            return $last instanceof WP_Error ? $last : new WP_Error( 'wordplus_geo_download', __( 'The database could not be downloaded.', 'wordplus-sdk' ) );
        }

        private static function unpack( $from, $to )
        {
            $in  = @gzopen( $from, 'rb' ); // phpcs:ignore
            $out = @fopen( $to, 'wb' ); // phpcs:ignore

            if ( ! $in || ! $out ) {
                return new WP_Error( 'wordplus_geo_unpack', __( 'The database could not be unpacked.', 'wordplus-sdk' ) );
            }

            while ( ! gzeof( $in ) ) {
                $chunk = gzread( $in, 1048576 );

                if ( false === $chunk || false === fwrite( $out, $chunk ) ) { // phpcs:ignore
                    gzclose( $in );
                    fclose( $out ); // phpcs:ignore

                    return new WP_Error( 'wordplus_geo_unpack', __( 'The database could not be unpacked.', 'wordplus-sdk' ) );
                }
            }

            gzclose( $in );
            fclose( $out ); // phpcs:ignore

            return true;
        }

        private function answers( $path, $kind )
        {
            $this->require_reader();

            try {
                $reader = new \WordPlusSDK\MaxMind\Db\Reader( $path );
                $record = $reader->get( '8.8.8.8' );
                $reader->close();
            } catch ( \Throwable $e ) {
                return false;
            }

            if ( empty( $record['country']['iso_code'] ) ) {
                return false;
            }

            return 'country' === $kind || ! empty( $record['city'] );
        }

        private function remove_files( $keep = '' )
        {
            $folder = $this->folder();

            if ( '' === $folder || ! is_dir( $folder ) ) {
                return;
            }

            foreach ( (array) glob( $folder . '/dbip-*.mmdb*' ) as $file ) {
                if ( basename( (string) $file ) !== $keep ) {
                    @unlink( $file ); // phpcs:ignore
                }
            }

            if ( '' === $keep ) {
                $all         = $this->settings();
                $all['file'] = '';
                $all['month'] = '';
                $this->put( $all );
                $this->reader = null;
            }
        }

        public function import_live_support()
        {
            $all = $this->settings();

            if ( ! empty( $all['imported'] ) ) {
                return false;
            }

            $all['imported'] = true;

            $theirs = class_exists( 'WordPlus_Support_Settings' ) ? WordPlus_Support_Settings::get( 'geoDatabase' ) : null;

            if ( 'none' === $all['database'] && ! empty( $theirs ) ) {
                $all['database'] = 'country';
            }

            $this->put( $all );
            $this->schedule();

            return 'country' === $all['database'];
        }

        public function present()
        {
            require_once $this->sdk->path( 'src/class-client.php' );

            $all  = $this->settings();
            $path = $this->database_path();

            return array(
                'database'    => (string) $all['database'],
                'month'       => (string) $all['month'],
                'updated'     => (int) $all['updated'],
                'error'       => (string) $all['error'],
                'size'        => '' !== $path && is_readable( $path ) ? (int) filesize( $path ) : 0,
                'downloaded'  => $this->downloaded(),
                'busy'        => (bool) get_transient( self::LOCK ),
                'cloudflare'  => WordPlus_SDK_Client::from_cloudflare() && ! empty( $_SERVER['HTTP_CF_IPCOUNTRY'] ),
                'cloudflareCity' => WordPlus_SDK_Client::from_cloudflare() && ! empty( $_SERVER['HTTP_CF_IPCITY'] ),
                'woocommerce' => class_exists( 'WC_Geolocation' ),
                'keep'        => (bool) $all['keep'],
                'days'        => $this->days(),
                'flags'       => (bool) $all['flags'],
                'attribution' => self::ATTRIBUTION,
                'flagsUrl'    => $this->flags_url(),
                'here'        => $this->locate( WordPlus_SDK_Client::ip() ),
            );
        }

        public function enqueue_admin()
        {
            $this->import_live_support();

            require_once $this->sdk->path( 'src/class-ui.php' );
            WordPlus_SDK_UI::enqueue();

            wp_register_script( self::ADMIN, $this->sdk->url( 'geo/geo-admin.min.js' ), $this->sdk->i18n()->deps( self::ADMIN, array( WordPlus_SDK_UI::UI, 'wp-element', 'wp-i18n', 'wp-api-fetch' ) ), WordPlus_SDK_UI::asset_version( 'geo/geo-admin.min.js' ), true );
            wp_enqueue_script( self::ADMIN );
            wp_add_inline_script( self::ADMIN, 'window.wordplusGeoData = ' . wp_json_encode( array(
                'rest'  => rest_url( self::REST_NAMESPACE . '/geo' ),
                'state' => $this->present(),
            ) ) . ';', 'before' );
        }

        public function routes()
        {
            register_rest_route( self::REST_NAMESPACE, '/geo', array(
                array(
                    'methods'             => 'GET',
                    'callback'            => array( $this, 'present' ),
                    'permission_callback' => array( $this, 'can_manage' ),
                ),
                array(
                    'methods'             => 'POST',
                    'callback'            => array( $this, 'rest_save' ),
                    'permission_callback' => array( $this, 'can_manage' ),
                ),
            ) );

            register_rest_route( self::REST_NAMESPACE, '/geo/download', array(
                'methods'             => 'POST',
                'callback'            => array( $this, 'rest_download' ),
                'permission_callback' => array( $this, 'can_manage' ),
            ) );
        }

        public function can_manage()
        {
            return (bool) apply_filters( 'wordplus_geo_can_manage', current_user_can( 'manage_options' ) );
        }

        public function rest_save( $request )
        {
            $fields = array();

            foreach ( array( 'database', 'keep', 'days', 'flags' ) as $field ) {
                $value = $request->get_param( $field );

                if ( null !== $value ) {
                    $fields[ $field ] = 'keep' === $field || 'flags' === $field ? (bool) filter_var( $value, FILTER_VALIDATE_BOOLEAN ) : $value;
                }
            }

            return $this->save( $fields );
        }

        public function rest_download()
        {
            if ( function_exists( 'set_time_limit' ) ) {
                @set_time_limit( 600 ); // phpcs:ignore
            }

            $result = $this->download();

            if ( is_wp_error( $result ) ) {
                $result->add_data( array( 'status' => 'wordplus_geo_busy' === $result->get_error_code() ? 409 : 502 ) );

                return $result;
            }

            return $this->present();
        }
    }
}
