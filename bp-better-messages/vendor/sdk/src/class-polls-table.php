<?php
defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WordPlus_SDK_Polls_Table' ) ) {
    final class WordPlus_SDK_Polls_Table implements WordPlus_SDK_Polls_Store
    {
        const SCHEMA = 2;

        const SCHEMA_OPTION = 'wordplus_polls_db';

        const CHUNK = 500;

        public function __construct()
        {
            global $wpdb;

            WordPlus_SDK::name_table( 'wordplus_polls', $wpdb->prefix . 'wordplus_polls' );
            WordPlus_SDK::name_table( 'wordplus_poll_votes', $wpdb->prefix . 'wordplus_poll_votes' );
        }

        private function exists()
        {
            return (int) get_option( self::SCHEMA_OPTION, 0 ) >= 1;
        }

        public function install()
        {
            global $wpdb;

            if ( (int) get_option( self::SCHEMA_OPTION, 0 ) >= self::SCHEMA ) {
                return true;
            }

            require_once ABSPATH . 'wp-admin/includes/upgrade.php';

            $polls   = $wpdb->wordplus_polls;
            $votes   = $wpdb->wordplus_poll_votes;
            $collate = $wpdb->get_charset_collate();

            dbDelta( "CREATE TABLE {$polls} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  context varchar(64) NOT NULL,
  object_id bigint(20) unsigned NOT NULL,
  scope_id bigint(20) unsigned NOT NULL DEFAULT 0,
  creator_id bigint(20) NOT NULL,
  question text NOT NULL,
  options longtext NOT NULL,
  multiple tinyint(1) NOT NULL DEFAULT 0,
  anonymous tinyint(1) NOT NULL DEFAULT 1,
  add_options tinyint(1) NOT NULL DEFAULT 0,
  hide_results tinyint(1) NOT NULL DEFAULT 0,
  quiz_answer tinyint(3) unsigned NULL,
  explanation text NULL,
  closes_at bigint(20) NULL,
  closed_at bigint(20) NULL,
  closed_by bigint(20) NULL,
  results longtext NULL,
  created_at bigint(20) NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY context_object (context,object_id),
  KEY context_scope (context,scope_id),
  KEY open_deadline (closed_at,closes_at)
) {$collate};" );

            dbDelta( "CREATE TABLE {$votes} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  poll_id bigint(20) unsigned NOT NULL,
  user_id bigint(20) NOT NULL,
  option_id tinyint(3) unsigned NOT NULL,
  created_at bigint(20) NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY vote (poll_id,user_id,option_id),
  KEY option_voters (poll_id,option_id,created_at),
  KEY user_id (user_id)
) {$collate};" );

            foreach ( array( $polls, $votes ) as $table ) {
                if ( $table !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) ) {
                    return false;
                }
            }

            update_option( self::SCHEMA_OPTION, self::SCHEMA, false );

            return true;
        }

        public function uninstall()
        {
            global $wpdb;

            $wpdb->query( "DROP TABLE IF EXISTS {$wpdb->wordplus_poll_votes}" );
            $wpdb->query( "DROP TABLE IF EXISTS {$wpdb->wordplus_polls}" );
            delete_option( self::SCHEMA_OPTION );
        }

        public function insert( array $poll )
        {
            global $wpdb;

            if ( ! $this->install() ) {
                return 0;
            }

            $row = array(
                'context'     => (string) $poll['context'],
                'object_id'   => (int) $poll['object_id'],
                'scope_id'    => (int) $poll['scope_id'],
                'creator_id'  => (int) $poll['creator_id'],
                'question'    => (string) $poll['question'],
                'options'     => wp_json_encode( array_values( $poll['options'] ) ),
                'multiple'    => $poll['multiple'] ? 1 : 0,
                'anonymous'    => $poll['anonymous'] ? 1 : 0,
                'add_options'  => empty( $poll['addOptions'] ) ? 0 : 1,
                'hide_results' => empty( $poll['hideResults'] ) ? 0 : 1,
                'quiz_answer'  => empty( $poll['quiz'] ) ? null : (int) $poll['quiz']['answer'],
                'explanation' => empty( $poll['quiz'] ) ? null : (string) $poll['quiz']['explanation'],
                'closes_at'   => null === $poll['closesAt'] ? null : (int) $poll['closesAt'],
                'results'     => wp_json_encode( $poll['results'] ),
                'created_at'  => (int) $poll['created_at'],
            );

            $row = array_filter( $row, function ( $value ) {
                return null !== $value;
            } );

            $formats = array();

            foreach ( array_keys( $row ) as $column ) {
                $formats[] = in_array( $column, array( 'context', 'question', 'options', 'explanation', 'results' ), true ) ? '%s' : '%d';
            }

            return false === $wpdb->insert( $wpdb->wordplus_polls, $row, $formats ) ? 0 : (int) $wpdb->insert_id;
        }

        public function find( $context, $object_id )
        {
            global $wpdb;

            if ( ! $this->exists() ) {
                return null;
            }

            $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->wordplus_polls} WHERE context = %s AND object_id = %d", (string) $context, (int) $object_id ), ARRAY_A );

            return is_array( $row ) ? self::poll( $row ) : null;
        }

        public function transaction( callable $run )
        {
            global $wpdb;

            $wpdb->query( 'START TRANSACTION' );

            try {
                $result = $run();
            } catch ( \Throwable $e ) {
                $wpdb->query( 'ROLLBACK' );
                throw $e;
            }

            $wpdb->query( is_wp_error( $result ) ? 'ROLLBACK' : 'COMMIT' );

            return $result;
        }

        public function lock( $poll_id )
        {
            global $wpdb;

            if ( ! $this->exists() ) {
                return null;
            }

            $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->wordplus_polls} WHERE id = %d FOR UPDATE", (int) $poll_id ), ARRAY_A );

            return is_array( $row ) ? self::poll( $row ) : null;
        }

        public function picks( $poll_id, $user_id )
        {
            global $wpdb;

            if ( ! $this->exists() ) {
                return array();
            }

            $picks = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT option_id FROM {$wpdb->wordplus_poll_votes} WHERE poll_id = %d AND user_id = %d", (int) $poll_id, (int) $user_id ) ) );
            sort( $picks );

            return $picks;
        }

        public function replace( $poll_id, $user_id, array $option_ids, $now )
        {
            global $wpdb;

            $wpdb->delete( $wpdb->wordplus_poll_votes, array( 'poll_id' => (int) $poll_id, 'user_id' => (int) $user_id ), array( '%d', '%d' ) );

            foreach ( $option_ids as $option_id ) {
                $wpdb->insert( $wpdb->wordplus_poll_votes, array(
                    'poll_id'    => (int) $poll_id,
                    'user_id'    => (int) $user_id,
                    'option_id'  => (int) $option_id,
                    'created_at' => (int) $now,
                ), array( '%d', '%d', '%d', '%d' ) );
            }
        }

        public function results( array $poll )
        {
            global $wpdb;

            $counts = array();

            foreach ( $poll['options'] as $option ) {
                $counts[ (int) $option['id'] ] = 0;
            }

            if ( ! $this->exists() ) {
                return empty( $poll['anonymous'] )
                    ? array( 'counts' => $counts, 'voters' => 0, 'recent' => array_fill_keys( array_keys( $counts ), array() ) )
                    : array( 'counts' => $counts, 'voters' => 0 );
            }

            $known = array_keys( $counts );
            $in    = implode( ',', array_fill( 0, count( $known ), '%d' ) );
            $rows  = $wpdb->get_results( $wpdb->prepare( "SELECT option_id, COUNT(*) AS picks FROM {$wpdb->wordplus_poll_votes} WHERE poll_id = %d AND option_id IN ($in) GROUP BY option_id", array_merge( array( (int) $poll['id'] ), $known ) ), ARRAY_A );

            foreach ( (array) $rows as $row ) {
                $counts[ (int) $row['option_id'] ] = (int) $row['picks'];
            }

            $voters = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT user_id) FROM {$wpdb->wordplus_poll_votes} WHERE poll_id = %d AND option_id IN ($in)", array_merge( array( (int) $poll['id'] ), $known ) ) );

            if ( ! empty( $poll['anonymous'] ) ) {
                return array( 'counts' => $counts, 'voters' => $voters );
            }

            $recent = array();

            foreach ( $known as $option_id ) {
                $recent[ $option_id ] = $counts[ $option_id ] > 0 ? $this->voters( (int) $poll['id'], $option_id, 0, WordPlus_SDK_Polls::RECENT ) : array();
            }

            return array( 'counts' => $counts, 'voters' => $voters, 'recent' => $recent );
        }

        public function update( $poll_id, array $fields )
        {
            global $wpdb;

            $set    = array();
            $values = array();

            foreach ( array( 'results', 'options', 'closed_at', 'closed_by' ) as $column ) {
                if ( ! array_key_exists( $column, $fields ) ) {
                    continue;
                }

                if ( null === $fields[ $column ] ) {
                    $set[] = "$column = NULL";
                } elseif ( 'results' === $column || 'options' === $column ) {
                    $set[]    = "$column = %s";
                    $values[] = wp_json_encode( 'options' === $column ? array_values( $fields[ $column ] ) : $fields[ $column ] );
                } else {
                    $set[]    = "$column = %d";
                    $values[] = (int) $fields[ $column ];
                }
            }

            if ( empty( $set ) ) {
                return true;
            }

            $values[] = (int) $poll_id;

            return false !== $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->wordplus_polls} SET " . implode( ', ', $set ) . ' WHERE id = %d', $values ) );
        }

        public function voters( $poll_id, $option_id, $offset, $limit )
        {
            global $wpdb;

            if ( ! $this->exists() ) {
                return array();
            }

            return array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare(
                "SELECT user_id FROM {$wpdb->wordplus_poll_votes} WHERE poll_id = %d AND option_id = %d ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d",
                (int) $poll_id,
                (int) $option_id,
                max( 1, (int) $limit ),
                max( 0, (int) $offset )
            ) ) );
        }

        public function due( $context, $now, $limit )
        {
            global $wpdb;

            if ( ! $this->exists() ) {
                return array();
            }

            $rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT * FROM {$wpdb->wordplus_polls} WHERE closed_at IS NULL AND closes_at IS NOT NULL AND closes_at <= %d AND context = %s ORDER BY closes_at LIMIT %d",
                (int) $now,
                (string) $context,
                max( 1, (int) $limit )
            ), ARRAY_A );

            return array_map( array( __CLASS__, 'poll' ), (array) $rows );
        }

        public function remove( $context, array $object_ids )
        {
            global $wpdb;

            $removed = 0;

            if ( ! $this->exists() ) {
                return $removed;
            }

            foreach ( array_chunk( array_values( array_unique( array_map( 'intval', $object_ids ) ) ), self::CHUNK ) as $chunk ) {
                $ids      = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$wpdb->wordplus_polls} WHERE context = %s AND object_id IN (" . implode( ',', array_fill( 0, count( $chunk ), '%d' ) ) . ')', array_merge( array( (string) $context ), $chunk ) ) ) );
                $removed += $this->remove_ids( $ids );
            }

            return $removed;
        }

        public function remove_scope( $context, $scope_id )
        {
            global $wpdb;

            if ( ! $this->exists() ) {
                return 0;
            }

            $ids = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$wpdb->wordplus_polls} WHERE context = %s AND scope_id = %d", (string) $context, (int) $scope_id ) ) );

            $removed = 0;

            foreach ( array_chunk( $ids, self::CHUNK ) as $chunk ) {
                $removed += $this->remove_ids( $chunk );
            }

            return $removed;
        }

        private function remove_ids( array $ids )
        {
            global $wpdb;

            if ( empty( $ids ) ) {
                return 0;
            }

            $in = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
            $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->wordplus_poll_votes} WHERE poll_id IN ($in)", $ids ) );

            return (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->wordplus_polls} WHERE id IN ($in)", $ids ) );
        }

        public function erase_user( $user_id )
        {
            global $wpdb;

            if ( ! $this->exists() ) {
                return array();
            }

            $polls = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT poll_id FROM {$wpdb->wordplus_poll_votes} WHERE user_id = %d", (int) $user_id ) ) );

            if ( ! empty( $polls ) ) {
                $wpdb->delete( $wpdb->wordplus_poll_votes, array( 'user_id' => (int) $user_id ), array( '%d' ) );
            }

            return $polls;
        }

        public static function poll( array $row )
        {
            $options = json_decode( (string) $row['options'], true );
            $results = null === $row['results'] ? null : json_decode( (string) $row['results'], true );

            return array(
                'id'         => (int) $row['id'],
                'context'    => (string) $row['context'],
                'object_id'  => (int) $row['object_id'],
                'scope_id'   => (int) $row['scope_id'],
                'creator_id' => (int) $row['creator_id'],
                'created_at' => (int) $row['created_at'],
                'closed_by'  => null === $row['closed_by'] ? null : (int) $row['closed_by'],
                'question'   => (string) $row['question'],
                'options'    => is_array( $options ) ? $options : array(),
                'multiple'   => (bool) (int) $row['multiple'],
                'anonymous'  => (bool) (int) $row['anonymous'],
                'quiz'       => null === $row['quiz_answer'] ? null : array( 'answer' => (int) $row['quiz_answer'], 'explanation' => (string) $row['explanation'] ),
                'closesAt'   => null === $row['closes_at'] ? null : (int) $row['closes_at'],
                'closedAt'   => null === $row['closed_at'] ? null : (int) $row['closed_at'],
                'addOptions'  => ! empty( $row['add_options'] ),
                'hideResults' => ! empty( $row['hide_results'] ),
                'results'    => is_array( $results ) ? $results : null,
            );
        }
    }
}
