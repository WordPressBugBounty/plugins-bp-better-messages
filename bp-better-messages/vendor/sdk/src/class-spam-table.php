<?php
defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WordPlus_SDK_Spam_Table' ) ) {
    final class WordPlus_SDK_Spam_Table implements WordPlus_SDK_Spam_Store
    {
        const SCHEMA = 1;

        const SCHEMA_OPTION = 'wordplus_spam_db';

        private $prune_checked = false;

        public function __construct()
        {
            global $wpdb;

            WordPlus_SDK::name_table( 'wordplus_spam', $wpdb->prefix . 'wordplus_spam' );
        }

        private function exists()
        {
            return (int) get_option( self::SCHEMA_OPTION, 0 ) >= self::SCHEMA;
        }

        public function install()
        {
            global $wpdb;

            if ( $this->exists() ) {
                $this->schedule_prune();

                return true;
            }

            require_once ABSPATH . 'wp-admin/includes/upgrade.php';

            $table   = $wpdb->wordplus_spam;
            $collate = $wpdb->get_charset_collate();

            dbDelta( "CREATE TABLE {$table} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  plugin varchar(40) NOT NULL,
  actor bigint(20) NOT NULL,
  action varchar(20) NOT NULL,
  scope varchar(64) NOT NULL DEFAULT '',
  contacts int(10) unsigned NOT NULL DEFAULT 0,
  fingerprint char(32) NOT NULL DEFAULT '',
  rules varchar(255) NOT NULL DEFAULT '',
  strikes tinyint(3) unsigned NOT NULL DEFAULT 0,
  monitored tinyint(3) unsigned NOT NULL DEFAULT 0,
  decision varchar(8) NOT NULL DEFAULT 'deliver',
  would varchar(8) NOT NULL DEFAULT 'deliver',
  trust varchar(12) NOT NULL DEFAULT '',
  created_at bigint(20) unsigned NOT NULL,
  PRIMARY KEY  (id),
  KEY actor_action (plugin,actor,action,created_at),
  KEY fingerprint (fingerprint,created_at),
  KEY created_at (created_at)
) {$collate};" );

            if ( $table !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) ) {
                return false;
            }

            update_option( self::SCHEMA_OPTION, self::SCHEMA, false );

            $this->schedule_prune();

            return true;
        }

        private function schedule_prune()
        {
            if ( $this->prune_checked ) {
                return;
            }

            $this->prune_checked = true;

            if ( ! wp_next_scheduled( WordPlus_SDK_Spam::PRUNE ) ) {
                wp_schedule_event( time() + DAY_IN_SECONDS, 'daily', WordPlus_SDK_Spam::PRUNE );
            }
        }

        public function uninstall()
        {
            global $wpdb;

            $wpdb->query( "DROP TABLE IF EXISTS {$wpdb->wordplus_spam}" );
            delete_option( self::SCHEMA_OPTION );
            wp_clear_scheduled_hook( WordPlus_SDK_Spam::PRUNE );
        }

        public function insert( array $row )
        {
            global $wpdb;

            if ( ! $this->install() ) {
                return 0;
            }

            $done = $wpdb->insert( $wpdb->wordplus_spam, array(
                'plugin'      => substr( (string) $row['plugin'], 0, 40 ),
                'actor'       => (int) $row['actor'],
                'action'      => substr( (string) $row['action'], 0, 20 ),
                'scope'       => substr( (string) $row['scope'], 0, 64 ),
                'contacts'    => max( 0, (int) $row['contacts'] ),
                'fingerprint' => substr( (string) $row['fingerprint'], 0, 32 ),
                'rules'       => substr( (string) $row['rules'], 0, 255 ),
                'strikes'     => min( 255, max( 0, (int) $row['strikes'] ) ),
                'monitored'   => min( 255, max( 0, (int) $row['monitored'] ) ),
                'decision'    => substr( (string) $row['decision'], 0, 8 ),
                'would'       => substr( (string) $row['would'], 0, 8 ),
                'trust'       => substr( (string) $row['trust'], 0, 12 ),
                'created_at'  => (int) $row['created_at'],
            ), array( '%s', '%d', '%s', '%s', '%d', '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%d' ) );

            return $done ? (int) $wpdb->insert_id : 0;
        }

        private function actions_in( array $actions )
        {
            global $wpdb;

            $actions = array_values( array_unique( array_map( 'strval', $actions ) ) );

            if ( empty( $actions ) ) {
                return '1=0';
            }

            return $wpdb->prepare( 'action IN (' . implode( ',', array_fill( 0, count( $actions ), '%s' ) ) . ')', $actions );
        }

        public function count( $plugin, $actor, array $actions, $since )
        {
            global $wpdb;

            if ( ! $this->exists() ) {
                return 0;
            }

            return (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->wordplus_spam} WHERE plugin = %s AND actor = %d AND " . $this->actions_in( $actions ) . ' AND created_at > %d',
                $plugin, $actor, $since
            ) );
        }

        public function contacts( $plugin, $actor, array $actions, $since )
        {
            global $wpdb;

            if ( ! $this->exists() ) {
                return 0;
            }

            return (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COALESCE(SUM(contacts), 0) FROM {$wpdb->wordplus_spam} WHERE plugin = %s AND actor = %d AND " . $this->actions_in( $actions ) . ' AND created_at > %d',
                $plugin, $actor, $since
            ) );
        }

        public function scopes( $plugin, $actor, $fingerprint, $since )
        {
            global $wpdb;

            if ( ! $this->exists() || '' === (string) $fingerprint ) {
                return array();
            }

            return array_map( 'strval', (array) $wpdb->get_col( $wpdb->prepare(
                "SELECT DISTINCT scope FROM {$wpdb->wordplus_spam} WHERE fingerprint = %s AND created_at > %d AND plugin = %s AND actor = %d",
                $fingerprint, $since, $plugin, $actor
            ) ) );
        }

        public function actors( $plugin, $fingerprint, $since, $trust )
        {
            global $wpdb;

            if ( ! $this->exists() || '' === (string) $fingerprint ) {
                return array();
            }

            return array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare(
                "SELECT DISTINCT actor FROM {$wpdb->wordplus_spam} WHERE fingerprint = %s AND created_at > %d AND plugin = %s AND trust = %s",
                $fingerprint, $since, $plugin, $trust
            ) ) );
        }

        public function times( $plugin, $actor, array $actions, $since, $limit )
        {
            global $wpdb;

            if ( ! $this->exists() || $limit < 1 ) {
                return array();
            }

            return array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare(
                "SELECT created_at FROM {$wpdb->wordplus_spam} WHERE plugin = %s AND actor = %d AND " . $this->actions_in( $actions ) . ' AND created_at > %d ORDER BY created_at ASC, id ASC LIMIT %d',
                $plugin, $actor, $since, (int) $limit
            ) ) );
        }

        public function strikes( $plugin, $actor, $since )
        {
            global $wpdb;

            if ( ! $this->exists() ) {
                return array( 'strikes' => 0, 'monitored' => 0 );
            }

            $row = $wpdb->get_row( $wpdb->prepare(
                "SELECT COALESCE(SUM(strikes), 0) AS strikes, COALESCE(SUM(monitored), 0) AS monitored FROM {$wpdb->wordplus_spam} WHERE plugin = %s AND actor = %d AND created_at > %d",
                $plugin, $actor, $since
            ), ARRAY_A );

            return array(
                'strikes'   => (int) ( $row['strikes'] ?? 0 ),
                'monitored' => (int) ( $row['monitored'] ?? 0 ),
            );
        }

        public function distinct( $plugin, $actor, $action, $since )
        {
            global $wpdb;

            if ( ! $this->exists() ) {
                return 0;
            }

            return (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(DISTINCT scope) FROM {$wpdb->wordplus_spam} WHERE plugin = %s AND actor = %d AND action = %s AND created_at > %d",
                $plugin, $actor, $action, $since
            ) );
        }

        public function recent( $plugin, $actor, $since, $limit )
        {
            global $wpdb;

            if ( ! $this->exists() ) {
                return array();
            }

            return (array) $wpdb->get_results( $wpdb->prepare(
                "SELECT * FROM {$wpdb->wordplus_spam} WHERE plugin = %s AND actor = %d AND created_at > %d ORDER BY created_at DESC, id DESC LIMIT %d",
                $plugin, $actor, $since, (int) $limit
            ), ARRAY_A );
        }

        public function flagged( $plugin, $since, $limit )
        {
            global $wpdb;

            if ( ! $this->exists() ) {
                return array();
            }

            $rows = (array) $wpdb->get_results( $wpdb->prepare(
                "SELECT actor, SUM(strikes) AS strikes, SUM(monitored) AS monitored, MAX(created_at) AS last
                FROM {$wpdb->wordplus_spam}
                WHERE plugin = %s AND created_at > %d AND ( strikes > 0 OR monitored > 0 OR rules <> '' )
                GROUP BY actor
                ORDER BY last DESC
                LIMIT %d",
                $plugin, $since, (int) $limit
            ), ARRAY_A );

            return array_map( function ( $row ) {
                return array(
                    'actor'     => (int) $row['actor'],
                    'strikes'   => (int) $row['strikes'],
                    'monitored' => (int) $row['monitored'],
                    'last'      => (int) $row['last'],
                );
            }, $rows );
        }

        public function forget( $plugin, $actor )
        {
            global $wpdb;

            if ( ! $this->exists() ) {
                return 0;
            }

            return (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->wordplus_spam} WHERE plugin = %s AND actor = %d", $plugin, $actor ) );
        }

        public function prune( $before )
        {
            global $wpdb;

            if ( ! $this->exists() ) {
                return 0;
            }

            $removed = 0;

            do {
                $done     = (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->wordplus_spam} WHERE created_at < %d LIMIT 5000", $before ) );
                $removed += $done;
            } while ( 5000 === $done );

            return $removed;
        }
    }
}
