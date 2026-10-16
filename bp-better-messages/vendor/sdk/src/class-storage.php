<?php
defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WordPlus_SDK_Storage' ) ) {
    final class WordPlus_SDK_Storage
    {
        const OPTION = 'wordplus_storage';

        const SECRET_SET = '__WORDPLUS_SECRET_SET__';

        const TABLE = 'wordplus_storage_objects';

        const SCHEMA = 1;

        const SCHEMA_OPTION = 'wordplus_storage_db';

        const JOB = 'wordplus_storage_job';

        const INLINE_MAX = 67108864;

        const MAX_ATTEMPTS = 10;

        const NEVER = 4102444800;

        const DELETE_DELAY = 300;

        const SIGN_WINDOW = 43200;

        const SIGN_TTL = 129600;

        const SITE_OPTION = 'wordplus_storage_site_id';

        const MARKER_NAME = '.wordplus-site.json';

        const TEST_FOLDER = '.wordplus-test';

        const CLOUD_STATES = array( 'stored', 'moving', 'deleting', 'removing', 'delete_failed', 'uploading' );

        private $sdk;

        private $settings_cache = null;
        private $folder_states  = array();
        private $row_cache      = array();
        private $table_ready    = null;

        private $queue = null;

        private $direct = null;

        private $admin = null;

        private $sweeper = null;

        public function __construct( WordPlus_SDK $sdk )
        {
            global $wpdb;

            $this->sdk = $sdk;

            if ( is_object( $wpdb ) && isset( $wpdb->base_prefix ) ) {
                WordPlus_SDK::name_table( 'wordplus_storage_objects', $wpdb->base_prefix . self::TABLE );
            }

            require_once __DIR__ . '/class-storage-s3.php';
        }

        public function sdk()
        {
            return $this->sdk;
        }

        public function queue()
        {
            if ( null === $this->queue ) {
                require_once __DIR__ . '/class-storage-queue.php';
                $this->queue = new WordPlus_SDK_Storage_Queue( $this );
            }

            return $this->queue;
        }

        public function direct()
        {
            if ( null === $this->direct ) {
                require_once __DIR__ . '/class-storage-direct.php';
                $this->direct = new WordPlus_SDK_Storage_Direct( $this );
            }

            return $this->direct;
        }

        public function admin()
        {
            if ( null === $this->admin ) {
                require_once __DIR__ . '/class-storage-admin.php';
                $this->admin = new WordPlus_SDK_Storage_Admin( $this );
            }

            return $this->admin;
        }

        public function sweeper()
        {
            if ( null === $this->sweeper ) {
                require_once __DIR__ . '/class-storage-sweeper.php';
                $this->sweeper = new WordPlus_SDK_Storage_Sweeper( $this );
            }

            return $this->sweeper;
        }

        public function reset_caches()
        {
            $this->settings_cache = null;
            $this->row_cache      = array();
            $this->folder_states  = array();
        }

        public static function types()
        {
            return array( 'r2', 'bunny', 's3' );
        }

        public static function presets()
        {
            return array(
                'r2'    => array(
                    'label'       => 'Cloudflare R2',
                    'endpoint'    => 'https://{account_id}.r2.cloudflarestorage.com',
                    'region'      => 'auto',
                    'path_style'  => true,
                    'cors_api'    => true,
                    'disposition' => true,
                    'max_parts'   => 10000,
                ),
                'bunny' => array(
                    'label'       => 'Bunny.net',
                    'endpoint'    => 'https://{region}-s3.storage.bunnycdn.com',
                    'region'      => 'de',
                    'regions'     => array( 'de', 'uk', 'se', 'ny', 'la', 'sg', 'syd', 'jh' ),
                    'path_style'  => true,
                    'cors_api'    => false,
                    'disposition' => false,
                    'max_parts'   => 10000,
                    'preview'     => true,
                ),
                's3'    => array(
                    'label'        => 'Amazon S3 or S3-compatible',
                    'endpoint'     => '',
                    'aws_endpoint' => 'https://s3.{region}.amazonaws.com',
                    'region'       => 'us-east-1',
                    'path_style'   => false,
                    'cors_api'     => true,
                    'disposition'  => true,
                    'max_parts'    => 10000,
                ),
            );
        }

        public static function preset( $name )
        {
            $presets = self::presets();

            return isset( $presets[ $name ] ) ? $presets[ $name ] : $presets['s3'];
        }

        public static function location_defaults()
        {
            return array(
                'id'             => '',
                'preset'         => 's3',
                'endpoint'       => '',
                'region'         => '',
                'bucket'         => '',
                'path_style'     => '1',
                'prefix'         => self::default_prefix(),
                'access_key'     => '',
                'secret_key'     => '',
                'delivery'       => 'private',
                'public_base'    => '',
                'public_acl'     => '0',
                'account_id'     => '',
                'worker_url'     => '',
                'worker_secret'  => '',
                'pull_zone_host' => '',
                'token_key'      => '',
                'api_key'        => '',
                'direct'         => '1',
                'readonly'       => '0',
                'created_at'     => 0,
            );
        }

        public static function location_type( array $location )
        {
            return isset( $location['preset'] ) && in_array( $location['preset'], self::types(), true ) ? $location['preset'] : 's3';
        }

        public static function site_address()
        {
            $parts = wp_parse_url( home_url( '/' ) );
            $host  = strtolower( isset( $parts['host'] ) ? $parts['host'] : '' );

            if ( ! empty( $parts['port'] ) ) {
                $host .= ':' . $parts['port'];
            }

            $path = trim( isset( $parts['path'] ) ? $parts['path'] : '', '/' );

            return '' !== $path ? $host . '/' . $path : $host;
        }

        public static function default_prefix()
        {
            $folder = trim( (string) preg_replace( '/[^a-z0-9.\-]+/', '-', strtolower( self::site_address() ) ), '-' );

            return '' !== $folder ? 'wordplus/' . $folder : 'wordplus';
        }

        public static function secret_fields()
        {
            return array( 'secret_key', 'worker_secret', 'token_key', 'api_key' );
        }

        public static function constant_fields( $type )
        {
            switch ( $type ) {
                case 'r2':
                    return array(
                        'access_key'    => 'WORDPLUS_R2_ACCESS_KEY',
                        'secret_key'    => 'WORDPLUS_R2_SECRET_KEY',
                        'worker_secret' => 'WORDPLUS_R2_WORKER_SECRET',
                    );
                case 'bunny':
                    return array(
                        'secret_key' => 'WORDPLUS_BUNNY_SECRET_KEY',
                        'token_key'  => 'WORDPLUS_BUNNY_TOKEN_KEY',
                        'api_key'    => 'WORDPLUS_BUNNY_API_KEY',
                    );
                default:
                    return array(
                        'access_key' => 'WORDPLUS_S3_ACCESS_KEY',
                        'secret_key' => 'WORDPLUS_S3_SECRET_KEY',
                    );
            }
        }

        public static function locked_fields( $type )
        {
            $locked = array();

            foreach ( self::constant_fields( $type ) as $field => $constant ) {
                if ( defined( $constant ) && '' !== (string) constant( $constant ) ) {
                    $locked[] = $field;
                }
            }

            return $locked;
        }

        public static function with_constants( array $location, $only_empty = false )
        {
            foreach ( self::constant_fields( self::location_type( $location ) ) as $field => $constant ) {
                if ( $only_empty && isset( $location[ $field ] ) && '' !== (string) $location[ $field ] ) {
                    continue;
                }

                if ( defined( $constant ) && '' !== (string) constant( $constant ) ) {
                    $location[ $field ] = (string) constant( $constant );
                }
            }

            return $location;
        }

        public function get_settings()
        {
            if ( null !== $this->settings_cache ) {
                return $this->settings_cache;
            }

            $stored = get_option( self::OPTION, false );

            if ( false === $stored ) {
                $stored = $this->adopt();
            }

            $this->settings_cache = self::normalize_settings( is_array( $stored ) ? $stored : array() );

            return $this->settings_cache;
        }

        private function adopt()
        {
            $legacy   = apply_filters( 'wordplus_storage_adopt', array() );
            $settings = array( 'current' => array(), 'locations' => array() );

            if ( is_array( $legacy ) && ! empty( $legacy['locations'] ) && is_array( $legacy['locations'] ) ) {
                $settings = self::normalize_settings( $legacy );

                $settings = array( 'current' => $settings['current'], 'locations' => $settings['locations'] );
            }

            if ( is_array( $legacy ) && ! empty( $legacy['site_id'] ) && '' === (string) get_option( self::SITE_OPTION, '' ) ) {
                update_option( self::SITE_OPTION, (string) $legacy['site_id'], false );
            }

            update_option( self::OPTION, $settings, false );

            return $settings;
        }

        public static function normalize_settings( array $stored )
        {
            $locations = isset( $stored['locations'] ) && is_array( $stored['locations'] ) ? $stored['locations'] : array();

            foreach ( $locations as $id => $location ) {
                $location           = wp_parse_args( is_array( $location ) ? $location : array(), self::location_defaults() );
                $location['id']     = (string) $id;
                $location['preset'] = self::location_type( $location );
                $locations[ $id ]   = $location;
            }

            $current = array_fill_keys( self::types(), '' );

            if ( isset( $stored['current'] ) && is_array( $stored['current'] ) ) {
                foreach ( self::types() as $type ) {
                    $id = isset( $stored['current'][ $type ] ) ? (string) $stored['current'][ $type ] : '';

                    if ( '' !== $id && isset( $locations[ $id ] ) && $locations[ $id ]['preset'] === $type ) {
                        $current[ $type ] = $id;
                    }
                }
            }

            return array(
                'current'   => $current,
                'locations' => $locations,
            );
        }

        public function update_settings( array $settings )
        {
            $this->settings_cache = null;
            $this->row_cache      = array();

            update_option( self::OPTION, array(
                'current'   => isset( $settings['current'] ) ? $settings['current'] : array(),
                'locations' => isset( $settings['locations'] ) ? $settings['locations'] : array(),
            ), false );

            if ( ! empty( $settings['locations'] ) ) {
                $this->schedule( 60 );
            }

            do_action( 'wordplus_storage_settings_updated', $this->get_settings() );
        }

        public function get_location( $id )
        {
            if ( empty( $id ) || 'local' === $id ) {
                return null;
            }

            $settings = $this->get_settings();

            if ( ! isset( $settings['locations'][ $id ] ) ) {
                return null;
            }

            $location = $settings['locations'][ $id ];

            return self::with_constants( $location, $settings['current'][ $location['preset'] ] !== $id );
        }

        public function current_location( $type )
        {
            $settings = $this->get_settings();

            return isset( $settings['current'][ $type ] ) && '' !== $settings['current'][ $type ] ? $this->get_location( $settings['current'][ $type ] ) : null;
        }

        public function route( $type )
        {
            if ( 'local' === $type || '' === (string) $type ) {
                return null;
            }

            $location = $this->current_location( (string) $type );

            return $location && '1' !== $location['readonly'] ? $location : null;
        }

        public function has_locations()
        {
            return count( $this->get_settings()['locations'] ) > 0;
        }

        public function private_links_available()
        {
            foreach ( $this->get_settings()['locations'] as $location ) {
                if ( 'public' !== $location['delivery'] && '' !== $location['bucket'] ) {
                    return true;
                }
            }

            return false;
        }

        public function client( array $location )
        {
            return new WordPlus_SDK_Storage_S3( $location );
        }

        public function uses()
        {
            $uses = apply_filters( 'wordplus_storage_uses', array() );
            $out  = array_fill_keys( array_merge( array( 'local' ), self::types() ), array() );

            if ( ! is_array( $uses ) ) {
                return $out;
            }

            foreach ( $uses as $type => $plugins ) {
                if ( ! isset( $out[ $type ] ) || ! is_array( $plugins ) ) {
                    continue;
                }

                foreach ( $plugins as $plugin => $kinds ) {
                    $kinds = array_values( array_filter( array_map( 'strval', (array) $kinds ) ) );

                    if ( ! empty( $kinds ) ) {
                        $out[ $type ][ (string) $plugin ] = $kinds;
                    }
                }
            }

            return $out;
        }

        public function in_use( $type )
        {
            $uses = $this->uses();

            return ! empty( $uses[ $type ] );
        }

        public function site_id()
        {
            $id = (string) get_option( self::SITE_OPTION, '' );

            if ( '' === $id ) {
                $id = wp_generate_uuid4();
                update_option( self::SITE_OPTION, $id, false );
            }

            return $id;
        }

        public function marker_key( array $location, $name = self::MARKER_NAME )
        {
            $prefix = trim( (string) $location['prefix'], '/' );

            return ( '' !== $prefix ? $prefix . '/' : '' ) . $name;
        }

        public function write_marker( array $location )
        {
            $body = wp_json_encode( array(
                'site'    => self::site_address(),
                'id'      => $this->site_id(),
                'written' => gmdate( 'c' ),
            ) );

            $put = $this->client( $location )->put_string( $this->marker_key( $location ), $body, array( 'content-type' => 'application/json' ) );

            return ! empty( $put['ok'] );
        }

        public function folder_state( array $location, $fresh = false )
        {
            if ( '1' === $location['readonly'] ) {
                return array( 'state' => 'readonly', 'site' => '' );
            }

            $address  = self::site_address();
            $identity = md5( implode( '|', array( $location['endpoint'], $location['bucket'], trim( (string) $location['prefix'], '/' ), $this->site_id(), $address ) ) );
            $cache    = 'wordplus_storage_folder_' . $location['id'];

            if ( ! $fresh ) {
                if ( isset( $this->folder_states[ $identity ] ) ) {
                    return $this->folder_states[ $identity ];
                }

                $cached = '' !== $location['id'] ? get_transient( $cache ) : false;

                if ( is_array( $cached ) && isset( $cached['identity'] ) && $cached['identity'] === $identity ) {
                    $this->folder_states[ $identity ] = array( 'state' => $cached['state'], 'site' => $cached['site'] );

                    return $this->folder_states[ $identity ];
                }
            }

            $marker = $this->read_marker( $location );
            $site   = '';

            if ( null === $marker ) {
                $state = 'unknown';
            } elseif ( false === $marker ) {
                $state = $this->write_marker( $location ) ? 'own' : 'unknown';
                $site  = $address;
            } else {
                $site = isset( $marker['site'] ) ? (string) $marker['site'] : '';

                if ( empty( $marker['id'] ) || (string) $marker['id'] !== $this->site_id() ) {
                    $state = 'shared';
                } elseif ( $site !== $address ) {
                    $state = 'copy';
                } else {
                    $state = 'own';

                    if ( ! empty( $marker['legacy'] ) ) {
                        $this->write_marker( $location );
                    }
                }
            }

            $result = array( 'state' => $state, 'site' => $site );

            if ( '' !== $location['id'] ) {
                set_transient( $cache, $result + array( 'identity' => $identity ), 'unknown' === $state ? 5 * MINUTE_IN_SECONDS : HOUR_IN_SECONDS );
            }

            $this->folder_states[ $identity ] = $result;

            return $result;
        }

        private function read_marker( array $location )
        {
            $names = array_merge( array( self::MARKER_NAME ), array_map( 'strval', (array) apply_filters( 'wordplus_storage_legacy_markers', array() ) ) );

            foreach ( $names as $index => $name ) {
                $get  = $this->client( $location )->request( 'GET', $this->marker_key( $location, $name ), array( 'timeout' => 15 ) );
                $code = (int) $get['code'];

                if ( 404 === $code || 403 === $code ) {
                    continue;
                }

                if ( empty( $get['ok'] ) ) {
                    return null;
                }

                $marker = json_decode( (string) $get['body'], true );
                $marker = is_array( $marker ) ? $marker : array();

                if ( $index > 0 ) {
                    $marker['legacy'] = true;
                }

                return $marker;
            }

            return false;
        }

        public function folder_access( array $location )
        {
            $state = $this->folder_state( $location )['state'];

            if ( 'readonly' === $state ) {
                return 'never';
            }

            return in_array( $state, array( 'own', 'shared' ), true ) ? 'yes' : 'wait';
        }

        public function forget_folder_state( $location_id )
        {
            delete_transient( 'wordplus_storage_folder_' . $location_id );
            delete_transient( 'wordplus_storage_direct_' . $location_id );
            $this->folder_states = array();
        }

        public function claim_folder( array $location )
        {
            $written = $this->write_marker( $location );
            $this->forget_folder_state( $location['id'] );

            return $written;
        }

        public function set_location_readonly( $location_id, $readonly = true )
        {
            $settings = $this->get_settings();

            if ( ! isset( $settings['locations'][ $location_id ] ) ) {
                return false;
            }

            $type = self::location_type( $settings['locations'][ $location_id ] );

            $settings['locations'][ $location_id ]['readonly'] = $readonly ? '1' : '0';

            $this->update_settings( $settings );
            $this->forget_folder_state( $location_id );

            if ( $readonly && $settings['current'][ $type ] === $location_id ) {
                do_action( 'wordplus_storage_readonly', $type, $location_id );
            }

            return true;
        }

        public function plugin_folder( $plugin )
        {
            $product  = $this->sdk->product( (string) $plugin );
            $settings = $product ? $product->get( 'storage' ) : null;
            $folder   = is_array( $settings ) && ! empty( $settings['folder'] ) ? (string) $settings['folder'] : (string) $plugin;

            return trim( (string) preg_replace( '/[^A-Za-z0-9._\-]/', '', $folder ), '.' );
        }

        public function plugin_folders()
        {
            $folders = array();

            foreach ( $this->sdk->products() as $slug => $product ) {
                $folders[ $slug ] = $this->plugin_folder( $slug );
            }

            return array_filter( $folders );
        }

        public function plugin_name( $plugin )
        {
            $product = $this->sdk->product( (string) $plugin );

            return $product ? (string) $product->get( 'name' ) : (string) $plugin;
        }

        public function table()
        {
            global $wpdb;

            return $wpdb->wordplus_storage_objects;
        }

        public function table_exists()
        {
            if ( null === $this->table_ready ) {
                $this->table_ready = (int) get_site_option( self::SCHEMA_OPTION, 0 ) >= self::SCHEMA;
            }

            return $this->table_ready;
        }

        public function install()
        {
            global $wpdb;

            if ( $this->table_exists() ) {
                return true;
            }

            require_once ABSPATH . 'wp-admin/includes/upgrade.php';

            $table = $this->table();

            dbDelta( "CREATE TABLE {$table} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  blog_id bigint(20) NOT NULL DEFAULT 1,
  plugin varchar(64) NOT NULL DEFAULT '',
  ref bigint(20) DEFAULT NULL,
  variant varchar(32) NOT NULL DEFAULT '',
  path varchar(1024) NOT NULL DEFAULT '',
  item_key varchar(1024) NOT NULL DEFAULT '',
  name varchar(255) NOT NULL DEFAULT '',
  mime varchar(100) NOT NULL DEFAULT '',
  size bigint(20) unsigned NOT NULL DEFAULT 0,
  user_id bigint(20) NOT NULL DEFAULT 0,
  location varchar(32) NOT NULL DEFAULT '',
  target varchar(32) DEFAULT NULL,
  object_key varchar(1024) NOT NULL DEFAULT '',
  upload_id varchar(1024) NOT NULL DEFAULT '',
  state varchar(20) NOT NULL DEFAULT 'local',
  attempts smallint(5) unsigned NOT NULL DEFAULT 0,
  next_attempt_at bigint(20) NOT NULL DEFAULT 0,
  error text NULL,
  created_at bigint(20) NOT NULL DEFAULT 0,
  updated_at bigint(20) NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  UNIQUE KEY item (blog_id,plugin,ref,variant),
  KEY queue (blog_id,state,next_attempt_at),
  KEY place (blog_id,location),
  KEY target (blog_id,target),
  KEY uploads (blog_id,plugin,user_id,state),
  KEY object_key (object_key(191))
) " . $wpdb->get_charset_collate() . ';' );

            if ( $table !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) ) {
                return false;
            }

            update_site_option( self::SCHEMA_OPTION, self::SCHEMA );
            $this->table_ready = true;

            return true;
        }

        public function uninstall()
        {
            global $wpdb;

            $wpdb->query( "DROP TABLE IF EXISTS {$wpdb->wordplus_storage_objects}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange
            delete_site_option( self::SCHEMA_OPTION );
            $this->table_ready = null;
        }

        public function add( $plugin, $ref, array $args = array() )
        {
            global $wpdb;

            if ( ! $this->install() ) {
                return new WP_Error( 'wordplus_storage_table', 'The storage table could not be made' );
            }

            $variant = isset( $args['variant'] ) ? substr( (string) $args['variant'], 0, 32 ) : '';
            $row     = $this->get( $plugin, $ref, $variant, true );

            if ( $row ) {
                return $row;
            }

            $path     = isset( $args['path'] ) ? ltrim( (string) $args['path'], '/' ) : '';
            $location = isset( $args['location'] ) ? (string) $args['location'] : '';
            $now      = time();

            $wpdb->query( $wpdb->prepare(
                "INSERT IGNORE INTO {$wpdb->wordplus_storage_objects}
                (blog_id, plugin, ref, variant, path, item_key, name, mime, size, user_id, location, object_key, state, attempts, next_attempt_at, created_at, updated_at)
                VALUES (%d, %s, %d, %s, %s, %s, %s, %s, %d, %d, %s, %s, %s, 0, 0, %d, %d)",
                get_current_blog_id(),
                (string) $plugin,
                (int) $ref,
                $variant,
                $path,
                isset( $args['key'] ) && '' !== (string) $args['key'] ? ltrim( (string) $args['key'], '/' ) : $path,
                mb_substr( isset( $args['name'] ) ? (string) $args['name'] : wp_basename( $path ), 0, 255 ),
                mb_substr( isset( $args['type'] ) ? (string) $args['type'] : 'application/octet-stream', 0, 100 ),
                isset( $args['size'] ) ? max( 0, (int) $args['size'] ) : 0,
                isset( $args['user'] ) ? (int) $args['user'] : 0,
                $location,
                isset( $args['object_key'] ) ? (string) $args['object_key'] : '',
                '' !== $location ? 'stored' : 'local',
                ! empty( $args['created'] ) ? (int) $args['created'] : $now,
                $now
            ) );

            return $this->get( $plugin, $ref, $variant, true );
        }

        public function add_many( $plugin, array $files )
        {
            global $wpdb;

            if ( empty( $files ) || ! $this->install() ) {
                return 0;
            }

            $values = array();
            $now    = time();

            foreach ( $files as $args ) {
                $path     = isset( $args['path'] ) ? ltrim( (string) $args['path'], '/' ) : '';
                $location = isset( $args['location'] ) ? (string) $args['location'] : '';

                $values[] = $wpdb->prepare(
                    '(%d, %s, %d, %s, %s, %s, %s, %s, %d, %d, %s, %s, %s, 0, 0, %d, %d)',
                    get_current_blog_id(),
                    (string) $plugin,
                    (int) $args['ref'],
                    isset( $args['variant'] ) ? substr( (string) $args['variant'], 0, 32 ) : '',
                    $path,
                    isset( $args['key'] ) && '' !== (string) $args['key'] ? ltrim( (string) $args['key'], '/' ) : $path,
                    mb_substr( isset( $args['name'] ) ? (string) $args['name'] : wp_basename( $path ), 0, 255 ),
                    mb_substr( isset( $args['type'] ) ? (string) $args['type'] : 'application/octet-stream', 0, 100 ),
                    isset( $args['size'] ) ? max( 0, (int) $args['size'] ) : 0,
                    isset( $args['user'] ) ? (int) $args['user'] : 0,
                    $location,
                    isset( $args['object_key'] ) ? (string) $args['object_key'] : '',
                    '' !== $location ? 'stored' : 'local',
                    ! empty( $args['created'] ) ? (int) $args['created'] : $now,
                    $now
                );
            }

            $wpdb->query( "INSERT IGNORE INTO {$wpdb->wordplus_storage_objects}
                (blog_id, plugin, ref, variant, path, item_key, name, mime, size, user_id, location, object_key, state, attempts, next_attempt_at, created_at, updated_at)
                VALUES " . implode( ',', $values ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

            $this->row_cache = array();

            return count( $values );
        }

        public function get( $plugin, $ref, $variant = '', $fresh = false )
        {
            global $wpdb;

            if ( ! $this->table_exists() ) {
                return null;
            }

            $cache = $plugin . '|' . (int) $ref . '|' . $variant;

            if ( ! $fresh && array_key_exists( $cache, $this->row_cache ) ) {
                return $this->row_cache[ $cache ];
            }

            $row = $wpdb->get_row( $wpdb->prepare(
                "SELECT * FROM {$wpdb->wordplus_storage_objects} WHERE blog_id = %d AND plugin = %s AND ref = %d AND variant = %s",
                get_current_blog_id(), (string) $plugin, (int) $ref, (string) $variant
            ) );

            $this->row_cache[ $cache ] = $row ? $row : null;

            return $this->row_cache[ $cache ];
        }

        public function prime( $plugin, array $refs, $variant = '' )
        {
            global $wpdb;

            $refs = array_values( array_unique( array_filter( array_map( 'intval', $refs ) ) ) );

            if ( empty( $refs ) || ! $this->table_exists() ) {
                return;
            }

            foreach ( array_chunk( $refs, 500 ) as $chunk ) {
                $in   = implode( ',', array_fill( 0, count( $chunk ), '%d' ) );
                $rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->wordplus_storage_objects} WHERE blog_id = %d AND plugin = %s AND variant = %s AND ref IN ({$in})", array_merge( array( get_current_blog_id(), (string) $plugin, (string) $variant ), $chunk ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

                $found = array();

                foreach ( $rows as $row ) {
                    $found[ (int) $row->ref ] = $row;
                }

                foreach ( $chunk as $ref ) {
                    $this->row_cache[ $plugin . '|' . $ref . '|' . $variant ] = isset( $found[ $ref ] ) ? $found[ $ref ] : null;
                }
            }
        }

        public function object( $id )
        {
            global $wpdb;

            if ( ! $this->table_exists() ) {
                return null;
            }

            $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->wordplus_storage_objects} WHERE id = %d AND blog_id IN (%d, 0)", (int) $id, get_current_blog_id() ) );

            return $row ? $row : null;
        }

        public function update_row( $id, array $fields )
        {
            global $wpdb;

            $fields['updated_at'] = time();

            $wpdb->update( $this->table(), $fields, array( 'id' => (int) $id ) );

            $this->row_cache = array();
        }

        public function delete_row( $id )
        {
            global $wpdb;

            $wpdb->delete( $this->table(), array( 'id' => (int) $id ) );

            $this->row_cache = array();
        }

        public function claim( $row, $from, $to )
        {
            global $wpdb;

            $claimed = $wpdb->query( $wpdb->prepare(
                "UPDATE {$wpdb->wordplus_storage_objects} SET state = %s, updated_at = %d WHERE id = %d AND state = %s",
                $to, time(), (int) $row->id, $from
            ) );

            $this->row_cache = array();

            return 1 === (int) $claimed;
        }

        public function send( $row, $location_id )
        {
            if ( ! $row || ! in_array( $row->state, array( 'local', 'failed' ), true ) || ! $this->get_location( (string) $location_id ) ) {
                return false;
            }

            $this->update_row( $row->id, array(
                'state'           => 'pending_move',
                'location'        => (string) $location_id,
                'attempts'        => 0,
                'next_attempt_at' => time(),
                'error'           => null,
            ) );

            $this->queue()->run_after_response( (int) $row->id );

            return true;
        }

        public function move( $row, $to )
        {
            if ( ! $row ) {
                return false;
            }

            $this->update_row( $row->id, array(
                'target'          => (string) $to,
                'attempts'        => 0,
                'next_attempt_at' => time(),
                'error'           => null,
            ) );

            $this->schedule( 0 );

            return true;
        }

        public function forget( $row )
        {
            if ( ! $row ) {
                return;
            }

            switch ( $row->state ) {
                case 'moving':
                    $this->update_row( $row->id, array( 'ref' => null ) );
                    return;
                case 'uploading':
                    $this->direct()->discard( $row );
                    return;
                case 'deleting':
                case 'removing':
                case 'delete_failed':
                case 'orphan':
                    return;
            }

            if ( '' !== (string) $row->location && '' !== (string) $row->object_key && 'stored' === $row->state ) {
                $this->update_row( $row->id, array(
                    'ref'             => null,
                    'state'           => 'deleting',
                    'target'          => null,
                    'attempts'        => 0,
                    'next_attempt_at' => time(),
                    'error'           => null,
                ) );

                $this->schedule( self::DELETE_DELAY );

                return;
            }

            $this->delete_row( $row->id );
        }

        public function attach( $row, $ref, $variant = '' )
        {
            global $wpdb;

            $updated = $wpdb->update( $this->table(), array(
                'ref'        => (int) $ref,
                'variant'    => substr( (string) $variant, 0, 32 ),
                'updated_at' => time(),
            ), array( 'id' => (int) $row->id ) );

            $this->row_cache = array();

            return false !== $updated;
        }

        public function in_storage( $row )
        {
            return $row && 'stored' === $row->state && '' !== (string) $row->location;
        }

        public function cloud_bound( $row )
        {
            return $row && ( $this->in_storage( $row ) || in_array( $row->state, array( 'pending_move', 'moving' ), true ) );
        }

        public function row_location( $row )
        {
            return 0 === (int) $row->blog_id ? $this->queue()->retired_location( (string) $row->location ) : $this->get_location( (string) $row->location );
        }

        public function object_key( array $location, $row )
        {
            $prefix = trim( (string) $location['prefix'], '/' );
            $folder = $this->plugin_folder( $row->plugin );
            $key    = ltrim( '' !== (string) $row->item_key ? (string) $row->item_key : (string) $row->path, '/' );

            if ( '' === $key ) {
                $key = gmdate( 'Y/m' ) . '/' . wp_generate_uuid4() . '/file';
            }

            return implode( '/', array_filter( array( $prefix, $folder, $key ), 'strlen' ) );
        }

        public function upload_headers( array $location, $row )
        {
            $type    = self::stored_type( (string) $row->mime );
            $headers = array( 'content-type' => $type );

            if ( self::preset( $location['preset'] )['disposition'] ) {
                $headers['content-disposition'] = self::disposition( self::inline_mime( $type ) ? 'inline' : 'attachment', (string) $row->name );
            }

            if ( 'public' === $location['delivery'] && '1' === $location['public_acl'] ) {
                $headers['x-amz-acl'] = 'public-read';
            }

            return $headers;
        }

        public static function inline_mime( $mime )
        {
            if ( in_array( $mime, array( 'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif', 'application/pdf' ), true ) ) {
                return true;
            }

            return 0 === strpos( (string) $mime, 'video/' ) || 0 === strpos( (string) $mime, 'audio/' );
        }

        public static function unsafe_mime( $mime )
        {
            $mime = strtolower( (string) $mime );

            if ( '' === $mime ) {
                return true;
            }

            foreach ( array( 'html', 'svg', 'xml', 'javascript', 'ecmascript', 'x-httpd', 'x-sh', 'x-php' ) as $needle ) {
                if ( false !== strpos( $mime, $needle ) ) {
                    return true;
                }
            }

            return false;
        }

        public static function stored_type( $mime )
        {
            return self::unsafe_mime( $mime ) ? 'application/octet-stream' : (string) $mime;
        }

        public static function disposition( $type, $name )
        {
            $fallback = preg_replace( '/[^\x20-\x7E]|["\\\\]/u', '_', remove_accents( (string) $name ) );

            if ( null === $fallback ) {
                $fallback = 'file';
            }

            return $type . '; filename="' . $fallback . '"; filename*=UTF-8\'\'' . rawurlencode( (string) $name );
        }

        public function size( $row )
        {
            if ( ! $row ) {
                return 0;
            }

            if ( $this->in_storage( $row ) ) {
                return (int) $row->size;
            }

            $path = $this->local_path_for( $row->path );

            return $path && file_exists( $path ) ? (int) filesize( $path ) : (int) $row->size;
        }

        public function local_path_for( $relative )
        {
            $relative = ltrim( (string) $relative, '/' );

            if ( '' === $relative || false !== strpos( $relative, '..' ) || false !== strpos( $relative, "\0" ) ) {
                return null;
            }

            $uploads = wp_upload_dir( null, false );

            return trailingslashit( $uploads['basedir'] ) . $relative;
        }

        public function local_copy( $row, $max_bytes = 0 )
        {
            if ( ! $row ) {
                return new WP_Error( 'wordplus_storage_missing', 'File not found' );
            }

            if ( ! $this->in_storage( $row ) ) {
                $path = $this->local_path_for( $row->path );

                if ( ! $path || ! file_exists( $path ) ) {
                    return new WP_Error( 'wordplus_storage_missing', 'File not found' );
                }

                if ( $max_bytes > 0 && filesize( $path ) > $max_bytes ) {
                    return new WP_Error( 'wordplus_storage_too_large', 'File is too large' );
                }

                return array( 'path' => $path, 'cleanup' => function () {} );
            }

            if ( $max_bytes > 0 && (int) $row->size > $max_bytes ) {
                return new WP_Error( 'wordplus_storage_too_large', 'File is too large' );
            }

            $location = $this->get_location( (string) $row->location );

            if ( ! $location ) {
                return new WP_Error( 'wordplus_storage_missing', 'Storage not found' );
            }

            require_once ABSPATH . 'wp-admin/includes/file.php';

            $extension = pathinfo( (string) $row->name, PATHINFO_EXTENSION );
            $temp      = wp_tempnam( 'wordplus-file' );

            if ( '' !== $extension ) {
                $renamed = $temp . '.' . sanitize_file_name( $extension );

                if ( self::rename( $temp, $renamed ) ) {
                    $temp = $renamed;
                }
            }

            $client = $this->client( $location );
            $result = $client->download( (string) $row->object_key, $temp );

            if ( empty( $result['ok'] ) ) {
                wp_delete_file( $temp );

                return new WP_Error( 'wordplus_storage_download', $client->last_error ? $client->last_error : $result['error'] );
            }

            return array(
                'path'    => $temp,
                'cleanup' => function () use ( $temp ) {
                    wp_delete_file( $temp );
                },
            );
        }

        public static function rename( $from, $to )
        {
            return rename( $from, $to ); // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
        }

        public function delete_local( $relative )
        {
            $path = $this->local_path_for( $relative );

            if ( ! $path || ! file_exists( $path ) ) {
                return;
            }

            wp_delete_file( $path );

            $uploads = trailingslashit( wp_upload_dir( null, false )['basedir'] );
            $parts   = explode( '/', ltrim( (string) $relative, '/' ) );
            $stop_at = $uploads . $parts[0];
            $dir     = dirname( $path );

            while ( $dir !== $stop_at && strlen( $dir ) > strlen( $stop_at ) && is_dir( $dir ) ) {
                $entries = scandir( $dir );

                if ( false === $entries || count( array_diff( $entries, array( '.', '..', '.htaccess', 'index.php' ) ) ) > 0 ) {
                    break;
                }

                wp_delete_file( trailingslashit( $dir ) . '.htaccess' );
                wp_delete_file( trailingslashit( $dir ) . 'index.php' );

                if ( ! rmdir( $dir ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
                    break;
                }

                $dir = dirname( $dir );
            }
        }

        public function public_url( array $location, $key )
        {
            return untrailingslashit( $location['public_base'] ) . '/' . WordPlus_SDK_Storage_S3::encode_key( $key );
        }

        public static function is_public( array $location )
        {
            return 'public' === $location['delivery'] && '' !== $location['public_base'];
        }

        public function public_link( $row )
        {
            if ( ! $this->in_storage( $row ) ) {
                return null;
            }

            $location = $this->get_location( (string) $row->location );

            return $location && self::is_public( $location ) ? $this->public_url( $location, (string) $row->object_key ) : null;
        }

        public function signed_url( $row, $variant = 'inline', $ttl = null, $window = null )
        {
            if ( ! $this->in_storage( $row ) ) {
                return null;
            }

            $location = $this->get_location( (string) $row->location );

            if ( ! $location ) {
                return null;
            }

            $key = (string) $row->object_key;

            if ( self::is_public( $location ) && ( 'inline' === $variant || 'bunny' === $location['preset'] ) ) {
                return array( 'url' => $this->public_url( $location, $key ), 'expires' => 0 );
            }

            $window = max( 60, (int) ( null === $window ? apply_filters( 'wordplus_storage_signed_url_window', self::SIGN_WINDOW, $row ) : $window ) );
            $ttl    = null === $ttl ? (int) apply_filters( 'wordplus_storage_signed_url_ttl', self::SIGN_TTL, $row ) : (int) $ttl;
            $ttl    = max( $window + 60, min( 604800, $ttl ) );
            $start  = (int) ( floor( time() / $window ) * $window );
            $type   = self::stored_type( (string) $row->mime );
            $url    = null;

            if ( $this->uses_worker( $location ) ) {
                $inline = 'download' !== $variant && self::inline_mime( $type );
                $url    = self::worker_url( $location, $key, $start + $ttl, $inline ? 'inline' : 'attachment', (string) $row->name );
            } elseif ( $this->uses_pull_zone( $location ) ) {
                $url = self::pull_zone_url( $location, $key, $start + $ttl );
            } else {
                $query = array();

                if ( 'download' === $variant && self::preset( $location['preset'] )['disposition'] ) {
                    $query['response-content-disposition'] = self::disposition( 'attachment', (string) $row->name );
                }

                $url = $this->client( $location )->presign( 'GET', $key, $ttl, $query, array(), $start );
            }

            return array( 'url' => $url, 'expires' => $start + $ttl, 'window_end' => $start + $window );
        }

        public function link( $row )
        {
            if ( ! $this->in_storage( $row ) ) {
                return null;
            }

            $location = $this->get_location( (string) $row->location );

            if ( ! $location || self::is_public( $location ) ) {
                return null;
            }

            $signed = $this->signed_url( $row, 'inline' );

            return $signed && ! empty( $signed['url'] ) ? array( 'url' => $signed['url'], 'expires' => (int) $signed['expires'] ) : null;
        }

        public function uses_worker( array $location )
        {
            return 'r2' === $location['preset'] && 'public' !== $location['delivery'] && '' !== $location['worker_url'] && '' !== $location['worker_secret'];
        }

        public function uses_pull_zone( array $location )
        {
            return 'bunny' === $location['preset'] && 'public' !== $location['delivery'] && '' !== $location['pull_zone_host'] && '' !== $location['token_key'];
        }

        public static function pull_zone_url( array $location, $key, $expires )
        {
            $path  = '/' . ltrim( (string) $key, '/' );
            $token = 'HS256-' . rtrim( strtr( base64_encode( hash_hmac( 'sha256', $path . (int) $expires, $location['token_key'], true ) ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode

            return 'https://' . $location['pull_zone_host'] . '/' . WordPlus_SDK_Storage_S3::encode_key( $key ) . '?token=' . $token . '&expires=' . (int) $expires;
        }

        public static function worker_url( array $location, $key, $expires, $disposition, $name )
        {
            $signature = hash_hmac( 'sha256', 'v1|' . $key . '|' . (int) $expires . '|' . $disposition . '|' . $name, $location['worker_secret'], true );

            return untrailingslashit( $location['worker_url'] ) . '/' . WordPlus_SDK_Storage_S3::encode_key( $key ) . '?' . http_build_query( array(
                'exp' => (int) $expires,
                'd'   => $disposition,
                'n'   => $name,
                'sig' => rtrim( strtr( base64_encode( $signature ), '+/', '-_' ), '=' ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
            ), '', '&', PHP_QUERY_RFC3986 );
        }

        public function purge_pull_zone( array $location, $key )
        {
            if ( 'bunny' !== $location['preset'] || '' === $location['pull_zone_host'] || '' === $location['api_key'] ) {
                return true;
            }

            $folder = dirname( ltrim( (string) $key, '/' ) );
            $target = 'https://' . $location['pull_zone_host'] . '/' . ( '.' !== $folder ? WordPlus_SDK_Storage_S3::encode_key( $folder ) . '/' : '' ) . '*';

            $result = wp_remote_post( 'https://api.bunny.net/purge?' . http_build_query( array( 'url' => $target, 'async' => 'false' ), '', '&', PHP_QUERY_RFC3986 ), array(
                'timeout' => 30,
                'headers' => array( 'AccessKey' => $location['api_key'] ),
            ) );

            if ( is_wp_error( $result ) ) {
                return $result->get_error_message();
            }

            $code = (int) wp_remote_retrieve_response_code( $result );

            if ( $code < 200 || $code >= 300 ) {
                return 401 === $code ? 'Bunny refused the API key' : 'Bunny cache purge answered HTTP ' . $code;
            }

            $check = 'public' === $location['delivery'] ? $this->public_url( $location, $key ) : self::pull_zone_url( $location, $key, time() + 300 );
            $after = wp_remote_head( $check, array( 'timeout' => 20 ) );
            $after = is_wp_error( $after ) ? 0 : (int) wp_remote_retrieve_response_code( $after );

            return 404 === $after ? true : 'The file still opens from the Pull Zone after its cache was cleared (HTTP ' . $after . ')';
        }

        public function usage( $plugin = '' )
        {
            global $wpdb;

            if ( ! $this->table_exists() ) {
                return array();
            }

            $where = '' !== $plugin ? $wpdb->prepare( ' AND plugin = %s', (string) $plugin ) : '';

            $rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT location, state, plugin, COUNT(*) AS files, COALESCE(SUM(size), 0) AS bytes FROM {$wpdb->wordplus_storage_objects} WHERE blog_id = %d AND ref IS NOT NULL{$where} GROUP BY location, state, plugin", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                get_current_blog_id()
            ), ARRAY_A );

            return array_map( function ( $row ) {
                return array(
                    'location' => (string) $row['location'],
                    'state'    => (string) $row['state'],
                    'plugin'   => (string) $row['plugin'],
                    'files'    => (int) $row['files'],
                    'bytes'    => (int) $row['bytes'],
                );
            }, $rows );
        }

        public function location_file_count( $location_id )
        {
            global $wpdb;

            if ( ! $this->table_exists() ) {
                return 0;
            }

            return (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->wordplus_storage_objects} WHERE blog_id = %d AND location = %s AND state IN ('stored','moving','pending_move','deleting','removing','delete_failed','uploading')",
                get_current_blog_id(), (string) $location_id
            ) );
        }

        public function schedule( $delay = 60 )
        {
            $at   = time() + max( 0, (int) $delay );
            $next = wp_next_scheduled( self::JOB );

            if ( $next && $next <= $at ) {
                return;
            }

            if ( $next ) {
                wp_unschedule_event( $next, self::JOB );
            }

            wp_schedule_single_event( $at, self::JOB );

            if ( 0 === (int) $delay && function_exists( 'spawn_cron' ) && ! wp_doing_cron() ) {
                spawn_cron();
            }
        }
    }
}
