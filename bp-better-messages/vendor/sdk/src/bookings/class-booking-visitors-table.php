<?php

defined( 'ABSPATH' ) || exit;

if ( ! interface_exists( 'WordPlus_SDK_Booking_Visitors_Store' ) ) {
    interface WordPlus_SDK_Booking_Visitors_Store
    {
        public function find( $email_hash );

        public function insert( $email_hash, $number, $secret );

        public function forget( $email_hash );
    }
}

if ( ! class_exists( 'WordPlus_SDK_Booking_Visitors_Table' ) ) {
    final class WordPlus_SDK_Booking_Visitors_Table implements WordPlus_SDK_Booking_Visitors_Store
    {
        const SCHEMA = 1;

        const SCHEMA_OPTION = 'wordplus_booking_visitors_db';

        public function __construct()
        {
            global $wpdb;

            WordPlus_SDK::name_table( 'wordplus_booking_visitors', $wpdb->prefix . 'wordplus_booking_visitors' );
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

            $table = $wpdb->wordplus_booking_visitors;

            dbDelta( "CREATE TABLE {$table} (
  email_hash char(64) NOT NULL,
  number bigint(20) unsigned NOT NULL,
  secret varchar(128) NOT NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (email_hash),
  KEY number (number)
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

            $wpdb->query( "DROP TABLE IF EXISTS {$wpdb->wordplus_booking_visitors}" );
            delete_option( self::SCHEMA_OPTION );
        }

        public function find( $email_hash )
        {
            global $wpdb;

            if ( ! $this->exists() ) {
                return null;
            }

            $row = $wpdb->get_row( $wpdb->prepare(
                "SELECT number, secret FROM {$wpdb->wordplus_booking_visitors} WHERE email_hash = %s",
                (string) $email_hash
            ), ARRAY_A );

            return is_array( $row ) ? array( 'number' => (int) $row['number'], 'secret' => (string) $row['secret'] ) : null;
        }

        public function insert( $email_hash, $number, $secret )
        {
            global $wpdb;

            if ( ! $this->install() ) {
                return false;
            }

            return 1 === (int) $wpdb->query( $wpdb->prepare(
                "INSERT IGNORE INTO {$wpdb->wordplus_booking_visitors} (email_hash, number, secret, created_at) VALUES (%s, %d, %s, %s)",
                (string) $email_hash,
                (int) $number,
                (string) $secret,
                current_time( 'mysql', true )
            ) );
        }

        public function forget( $email_hash )
        {
            global $wpdb;

            if ( $this->exists() ) {
                $wpdb->delete( $wpdb->wordplus_booking_visitors, array( 'email_hash' => (string) $email_hash ) );
            }
        }
    }
}
