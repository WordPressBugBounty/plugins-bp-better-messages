<?php

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/class-client.php';

if ( ! interface_exists( 'WordPlus_SDK_Visitors_Store' ) ) {
    interface WordPlus_SDK_Visitors_Store
    {
        public function insert( $kind, $secret_hash );

        public function insert_at( $number, $kind, $secret_hash );

        public function insert_many( array $rows );

        public function find( $number );

        public function seen( $number );

        public function link( $number, $user_id );
    }
}

if ( ! class_exists( 'WordPlus_SDK_Visitors' ) ) {
    final class WordPlus_SDK_Visitors
    {
        const START = 1000000000;

        const HEADER = 'X-WordPlus-Visitor';

        const STORAGE_KEY = 'wordplus-visitor';

        const HANDLE = 'wordplus-visitor';

        const KINDS = array( 'visitor', 'bot' );

        const SEEN_EVERY = 3600;

        const ADOPT_CHUNK = 500;

        const NEW_PER_ADDRESS = 100;

        const NEW_WINDOW = 3600;

        const NEW_COUNT_PREFIX = 'wordplus_visitors_new_';

        private $sdk;

        private $store = null;

        private $current = null;

        public function __construct( WordPlus_SDK $sdk )
        {
            $this->sdk = $sdk;
        }

        public function store()
        {
            if ( null === $this->store ) {
                $this->store = new WordPlus_SDK_Visitors_Table();
            }

            return $this->store;
        }

        public function use_store( WordPlus_SDK_Visitors_Store $store )
        {
            $this->store   = $store;
            $this->current = null;
        }

        public function current()
        {
            if ( null !== $this->current ) {
                return $this->current;
            }

            $this->current = 0;
            $row           = $this->check( $this->request_token() );

            if ( null === $row ) {
                return 0;
            }

            $this->current = -1 * (int) $row['number'];

            if ( time() - (int) $row['seen_at'] >= self::SEEN_EVERY ) {
                $this->store()->seen( (int) $row['number'] );

                do_action( 'wordplus_visitor_seen', $this->current );
            }

            if ( is_user_logged_in() && 0 === (int) $row['user_id'] ) {
                $user_id = (int) get_current_user_id();

                if ( $user_id > 0 && $this->store()->link( (int) $row['number'], $user_id ) ) {
                    do_action( 'wordplus_visitor_signed_in', $this->current, $user_id );
                }
            }

            return $this->current;
        }

        public function token()
        {
            return $this->current() < 0 ? $this->request_token() : '';
        }

        public function check( $token )
        {
            $parts = self::parse( $token );

            if ( null === $parts ) {
                return null;
            }

            $row = $this->store()->find( $parts['number'] );

            if ( null === $row || 'visitor' !== $row['kind'] || '' === (string) $row['secret_hash'] ) {
                return null;
            }

            return hash_equals( (string) $row['secret_hash'], self::hash( $parts['secret'] ) ) ? $row : null;
        }

        public function create( $kind = 'visitor' )
        {
            $kind   = in_array( $kind, self::KINDS, true ) ? $kind : 'visitor';
            $secret = 'visitor' === $kind ? self::secret() : '';
            $number = (int) $this->store()->insert( $kind, '' === $secret ? '' : self::hash( $secret ) );

            if ( $number < self::START ) {
                return new WP_Error( 'visitor_not_kept', 'The visitor could not be kept.', array( 'status' => 500 ) );
            }

            return array(
                'id'    => -1 * $number,
                'token' => '' === $secret ? '' : $number . '.' . $secret,
            );
        }

        public function skip_past( $id )
        {
            $number = abs( (int) $id );

            if ( $number < self::START ) {
                return true;
            }

            $store = $this->store();

            return method_exists( $store, 'skip_past' ) && (bool) $store->skip_past( $number );
        }

        public function adopt( $id, $secret, $kind = 'visitor' )
        {
            $number = abs( (int) $id );
            $kind   = in_array( $kind, self::KINDS, true ) ? $kind : 'visitor';
            $secret = (string) $secret;

            if ( $number < 1 || $number >= self::START ) {
                return false;
            }

            if ( 'visitor' === $kind && null === self::parse( $number . '.' . $secret ) ) {
                return false;
            }

            $hash = 'visitor' === $kind ? self::hash( $secret ) : '';
            $row  = $this->store()->find( $number );

            if ( null !== $row ) {
                return $kind === $row['kind'] && hash_equals( (string) $row['secret_hash'], $hash );
            }

            return (bool) $this->store()->insert_at( $number, $kind, $hash );
        }

        public function adopt_many( array $guests )
        {
            $rows = array();

            foreach ( $guests as $guest ) {
                $number = isset( $guest['id'] ) ? abs( (int) $guest['id'] ) : 0;
                $kind   = isset( $guest['kind'] ) && in_array( $guest['kind'], self::KINDS, true ) ? $guest['kind'] : 'visitor';
                $secret = isset( $guest['secret'] ) ? (string) $guest['secret'] : '';

                if ( $number < 1 || $number >= self::START ) {
                    continue;
                }

                if ( 'visitor' === $kind && null === self::parse( $number . '.' . $secret ) ) {
                    continue;
                }

                $rows[] = array( $number, $kind, 'visitor' === $kind ? self::hash( $secret ) : '' );
            }

            $kept = 0;

            foreach ( array_chunk( $rows, self::ADOPT_CHUNK ) as $chunk ) {
                $kept += (int) $this->store()->insert_many( $chunk );
            }

            return $kept;
        }

        public static function parse( $token )
        {
            if ( ! is_string( $token ) || ! preg_match( '/^([1-9][0-9]{0,18})\.([A-Za-z0-9_-]{16,128})$/', $token, $match ) ) {
                return null;
            }

            return array( 'number' => (int) $match[1], 'secret' => $match[2] );
        }

        public static function hash( $secret )
        {
            return hash( 'sha256', (string) $secret );
        }

        private static function secret()
        {
            return rtrim( strtr( base64_encode( random_bytes( 24 ) ), '+/', '-_' ), '=' );
        }

        private function request_token()
        {
            if ( ! isset( $_SERVER['HTTP_X_WORDPLUS_VISITOR'] ) ) {
                return '';
            }

            return sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_WORDPLUS_VISITOR'] ) );
        }

        public function routes()
        {
            register_rest_route( 'wordplus/v1', '/visitors', array(
                'methods'             => 'POST',
                'callback'            => array( $this, 'rest_create' ),
                'permission_callback' => array( $this, 'may_create' ),
            ) );
        }

        public function may_create( $request )
        {
            if ( $this->current() < 0 ) {
                return true;
            }

            if ( WordPlus_SDK_Client::crawler() ) {
                return new WP_Error( 'visitors_crawler', 'A crawler gets no visitor.', array( 'status' => 403, 'reason' => 'crawler' ) );
            }

            $allowed = apply_filters( 'wordplus_visitors_allow_new', true, $request );

            if ( true !== $allowed ) {
                return $allowed instanceof WP_Error ? $allowed : new WP_Error( 'visitors_closed', 'No new visitors are taken.', array( 'status' => 403, 'reason' => 'closed' ) );
            }

            return $this->throttled( WordPlus_SDK_Client::ip() );
        }

        private function throttled( $address )
        {
            $limit = $this->new_limit( $address );

            if ( null === $limit ) {
                return true;
            }

            if ( (int) get_transient( $limit['key'] ) < $limit['visitors'] ) {
                return true;
            }

            return new WP_Error( 'visitors_throttled', 'Too many new visitors from this address. Try again later.', array(
                'status'      => 429,
                'reason'      => 'too_many_new_visitors',
                'limit'       => $limit['visitors'],
                'window'      => $limit['window'],
                'retry_after' => $limit['retry_after'],
            ) );
        }

        private function new_limit( $address )
        {
            $network = self::network( (string) $address );

            if ( '' === $network ) {
                return null;
            }

            $given    = apply_filters( 'wordplus_visitors_new_limit', array( 'visitors' => self::NEW_PER_ADDRESS, 'window' => self::NEW_WINDOW ), (string) $address );
            $given    = is_array( $given ) ? $given : array();
            $visitors = isset( $given['visitors'] ) ? max( 0, (int) $given['visitors'] ) : self::NEW_PER_ADDRESS;
            $window   = isset( $given['window'] ) ? max( 60, (int) $given['window'] ) : self::NEW_WINDOW;

            if ( 0 === $visitors ) {
                return null;
            }

            $now = time();

            return array(
                'visitors'    => $visitors,
                'window'      => $window,
                'key'         => self::NEW_COUNT_PREFIX . md5( $network . '|' . $window . '|' . intdiv( $now, $window ) ),
                'retry_after' => $window - ( $now % $window ),
            );
        }

        private function count_new( $address )
        {
            $limit = $this->new_limit( $address );

            if ( null !== $limit ) {
                set_transient( $limit['key'], (int) get_transient( $limit['key'] ) + 1, $limit['retry_after'] );
            }
        }

        private static function network( $address )
        {
            $address = WordPlus_SDK_Client::address( $address );

            if ( '' === $address || false === strpos( $address, ':' ) ) {
                return $address;
            }

            return inet_ntop( substr( (string) inet_pton( $address ), 0, 8 ) . str_repeat( "\0", 8 ) ) . '/64';
        }

        public function rest_create( $request )
        {
            if ( $this->current() < 0 ) {
                return array( 'id' => $this->current(), 'token' => $this->request_token() );
            }

            $made = $this->create();

            if ( ! is_wp_error( $made ) ) {
                $this->count_new( WordPlus_SDK_Client::ip() );
            }

            return $made;
        }

        public function script()
        {
            if ( ! wp_script_is( self::HANDLE, 'registered' ) ) {
                wp_register_script( self::HANDLE, $this->sdk->url( 'visitors/visitor.min.js' ), array(), $this->sdk->asset_version( 'visitors/visitor.min.js' ), false );
                wp_add_inline_script( self::HANDLE, 'window.wordplusVisitorConfig = ' . wp_json_encode( array(
                    'endpoint' => rest_url( 'wordplus/v1/visitors' ),
                ) ) . ';', 'before' );
            }

            return self::HANDLE;
        }
    }
}

if ( ! class_exists( 'WordPlus_SDK_Visitors_Table' ) ) {
    final class WordPlus_SDK_Visitors_Table implements WordPlus_SDK_Visitors_Store
    {
        const SCHEMA = 1;

        const SCHEMA_OPTION = 'wordplus_visitors_db';

        public function __construct()
        {
            global $wpdb;

            WordPlus_SDK::name_table( 'wordplus_visitors', $wpdb->prefix . 'wordplus_visitors' );
        }

        private function exists()
        {
            return (int) get_option( self::SCHEMA_OPTION, 0 ) >= self::SCHEMA;
        }

        public function install()
        {
            global $wpdb;

            if ( $this->exists() ) {
                return true;
            }

            require_once ABSPATH . 'wp-admin/includes/upgrade.php';

            $table = $wpdb->wordplus_visitors;

            dbDelta( "CREATE TABLE {$table} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  number bigint(20) unsigned DEFAULT NULL,
  kind varchar(20) NOT NULL DEFAULT 'visitor',
  secret_hash char(64) NOT NULL DEFAULT '',
  user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  seen_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY number (number),
  KEY user_id (user_id)
) " . $wpdb->get_charset_collate() . ';' );

            if ( $table !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) ) {
                return false;
            }

            update_option( self::SCHEMA_OPTION, self::SCHEMA, false );

            return true;
        }

        public function uninstall()
        {
            global $wpdb;

            $wpdb->query( "DROP TABLE IF EXISTS {$wpdb->wordplus_visitors}" );
            delete_option( self::SCHEMA_OPTION );
        }

        public function insert( $kind, $secret_hash )
        {
            global $wpdb;

            if ( ! $this->install() ) {
                return 0;
            }

            $now = current_time( 'mysql', true );

            if ( ! $wpdb->insert( $wpdb->wordplus_visitors, array(
                'kind'        => (string) $kind,
                'secret_hash' => (string) $secret_hash,
                'created_at'  => $now,
                'seen_at'     => $now,
            ) ) ) {
                return 0;
            }

            $number = WordPlus_SDK_Visitors::START + (int) $wpdb->insert_id;

            if ( ! $wpdb->update( $wpdb->wordplus_visitors, array( 'number' => $number ), array( 'id' => (int) $wpdb->insert_id ) ) ) {
                return 0;
            }

            return $number;
        }

        public function skip_past( $number )
        {
            global $wpdb;

            $id = (int) $number - WordPlus_SDK_Visitors::START;

            if ( $id < 1 || ! $this->install() ) {
                return false;
            }

            $highest = "SELECT COALESCE( MAX(id), 0 ) FROM {$wpdb->wordplus_visitors}";

            if ( (int) $wpdb->get_var( $highest ) < $id ) {
                $now = current_time( 'mysql', true );

                $wpdb->query( $wpdb->prepare(
                    "INSERT IGNORE INTO {$wpdb->wordplus_visitors} (id, number, kind, secret_hash, created_at, seen_at) VALUES (%d, %d, 'visitor', '', %s, %s)",
                    $id,
                    (int) $number,
                    $now,
                    $now
                ) );
            }

            return (int) $wpdb->get_var( $highest ) >= $id;
        }

        public function insert_at( $number, $kind, $secret_hash )
        {
            global $wpdb;

            if ( ! $this->install() ) {
                return false;
            }

            $now = current_time( 'mysql', true );

            return 1 === (int) $wpdb->query( $wpdb->prepare(
                "INSERT IGNORE INTO {$wpdb->wordplus_visitors} (number, kind, secret_hash, created_at, seen_at) VALUES (%d, %s, %s, %s, %s)",
                (int) $number,
                (string) $kind,
                (string) $secret_hash,
                $now,
                $now
            ) );
        }

        public function insert_many( array $rows )
        {
            global $wpdb;

            if ( empty( $rows ) || ! $this->install() ) {
                return 0;
            }

            $now  = current_time( 'mysql', true );
            $args = array();

            foreach ( $rows as $row ) {
                array_push( $args, (int) $row[0], (string) $row[1], (string) $row[2], $now, $now );
            }

            // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
            return (int) $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->wordplus_visitors} (number, kind, secret_hash, created_at, seen_at) VALUES " . implode( ', ', array_fill( 0, count( $rows ), '(%d, %s, %s, %s, %s)' ) ), ...$args ) );
        }

        public function find( $number )
        {
            global $wpdb;

            if ( ! $this->exists() ) {
                return null;
            }

            $row = $wpdb->get_row( $wpdb->prepare(
                "SELECT number, kind, secret_hash, user_id, seen_at FROM {$wpdb->wordplus_visitors} WHERE number = %d",
                (int) $number
            ), ARRAY_A );

            if ( ! is_array( $row ) ) {
                return null;
            }

            return array(
                'number'      => (int) $row['number'],
                'kind'        => (string) $row['kind'],
                'secret_hash' => (string) $row['secret_hash'],
                'user_id'     => (int) $row['user_id'],
                'seen_at'     => (int) strtotime( $row['seen_at'] . ' UTC' ),
            );
        }

        public function seen( $number )
        {
            global $wpdb;

            $wpdb->update( $wpdb->wordplus_visitors, array( 'seen_at' => current_time( 'mysql', true ) ), array( 'number' => (int) $number ) );
        }

        public function link( $number, $user_id )
        {
            global $wpdb;

            return 1 === (int) $wpdb->query( $wpdb->prepare(
                "UPDATE {$wpdb->wordplus_visitors} SET user_id = %d WHERE number = %d AND user_id = 0",
                (int) $user_id,
                (int) $number
            ) );
        }
    }
}
