<?php
defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Better_Messages_Encryption' ) ) :

    class Better_Messages_Encryption
    {
        const OWNER = 'better-messages';

        const SEALED_META = array(
            'edit_history',
            'bm_translations',
            'system_data',
            'user_reports',
            'ai_moderation_result',
            'ai_moderation_categories',
            'ai_provider_meta',
            'pending_args',
            'bm_review_copy',
        );

        const SEALED_META_PREFIXES = array( 'url_info_' );

        const BULK_FIELDS = array( 'subject', 'message', 'selectors' );

        const WIDE_DB_VERSION = 3.01;

        const SUBJECT_ROOM = 1024;

        public static function widened()
        {
            return (float) get_option( 'better_messages_2_db_version', 0 ) >= self::WIDE_DB_VERSION;
        }

        private $engine = false;

        private $leaks = array();

        private $writing = false;

        public static function instance()
        {
            static $instance = null;

            if ( null === $instance ) {
                $instance = new Better_Messages_Encryption();
            }

            return $instance;
        }

        public function __construct()
        {
            add_filter( 'get_bm_message_metadata', array( $this, 'read_meta' ), 10, 4 );
            add_filter( 'update_bm_message_metadata', array( $this, 'update_meta' ), 10, 5 );
            add_filter( 'add_bm_message_metadata', array( $this, 'add_meta' ), 10, 5 );

            add_action( 'better_messages_message_after_save', array( $this, 'index_saved_message' ) );
            add_action( 'better_messages_message_edited', array( $this, 'index_edited_message' ), 10, 2 );
            add_action( 'bp_better_messages_message_deleted', array( $this, 'unindex_message' ) );
            add_action( 'better_messages_thread_erased', array( $this, 'unindex_thread' ) );
            add_action( 'wordplus_encryption_register', array( $this, 'register_with' ) );

            if ( defined( 'BM_DEV' ) && BM_DEV ) {
                add_filter( 'rest_post_dispatch', array( $this, 'report_leaks' ), 9999, 3 );
            }
        }

        public function engine()
        {
            if ( false === $this->engine ) {
                $this->engine = function_exists( 'wordplus_encryption' ) ? wordplus_encryption() : null;

                if ( $this->engine ) {
                    $this->register_with( $this->engine );
                }
            }

            return $this->engine;
        }

        public function register_with( $engine )
        {
            global $wpdb;

            if ( ! is_object( $engine ) || ! method_exists( $engine, 'register' ) ) {
                return;
            }

            $meta_keys = "'" . implode( "','", array_map( 'esc_sql', self::SEALED_META ) ) . "'";

            $engine->register( self::OWNER, array(
                'name'        => 'Better Messages',
                'can_turn_on' => array( $this, 'can_turn_on' ),
                'collections' => array_filter( array(
                    'messages' => array(
                        'table'   => bm_get_table( 'messages' ),
                        'key'     => 'id',
                        'column'  => 'message',
                        'select'  => array( 'thread_id' ),
                        'where'   => '`thread_id` > 0',
                        'context' => function ( $row ) {
                            return 'message:' . (int) $row->thread_id;
                        },
                        'skip'    => function ( $value ) {
                            return Better_Messages_Encryption::stays_readable( $value );
                        },
                        'index'   => array( 'kind' => 'message', 'scope' => 'thread_id' ),
                    ),
                    'subjects' => ! self::widened() ? null : array(
                        'table'   => bm_get_table( 'threads' ),
                        'key'     => 'id',
                        'column'  => 'subject',
                        'select'  => array( 'type' ),
                        'where'   => "`subject` != ''",
                        'context' => function ( $row ) {
                            return 'subject:' . (int) $row->id;
                        },
                        'skip'    => function ( $value, $row ) {
                            return ! in_array( $row->type, array( 'thread', 'booking' ), true );
                        },
                        'index'   => array( 'kind' => 'subject', 'scope' => 'id' ),
                    ),
                    'meta' => array(
                        'table'   => bm_get_table( 'meta' ),
                        'key'     => 'meta_id',
                        'column'  => 'meta_value',
                        'select'  => array( 'bm_message_id', 'meta_key' ),
                        'where'   => "`meta_key` IN ({$meta_keys}) OR `meta_key` LIKE 'url\\_info\\_%'",
                        'context' => function ( $row ) {
                            return 'meta:' . (int) $row->bm_message_id . ':' . $row->meta_key;
                        },
                    ),
                    'bulk_subjects' => $this->bulk_collection( 'subject' ),
                    'bulk_messages' => $this->bulk_collection( 'message' ),
                    'bulk_selectors' => $this->bulk_collection( 'selectors' ),
                    'guests' => ! self::widened() ? null : array(
                        'table'  => bm_get_table( 'guests' ),
                        'key'    => 'id',
                        'select' => array( 'bot_id', 'email', 'email_hash', 'ip', 'ip_hash', 'meta' ),
                        'batch'  => 100,
                        'handle' => array( $this, 'guest_job_row' ),
                    ),
                    'files' => array(
                        'table'  => $wpdb->posts,
                        'key'    => 'ID',
                        'where'  => "`post_type` = 'attachment' AND `ID` IN ( SELECT `post_id` FROM `{$wpdb->postmeta}` WHERE `meta_key` = 'bp-better-messages-attachment' )",
                        'batch'  => 20,
                        'handle' => array( $this->files(), 'job_row' ),
                    ),
                ) ),
            ) );
        }

        private function bulk_collection( $field )
        {
            if ( ! self::widened() ) {
                return null;
            }

            return array(
                'table'   => bm_get_table( 'bulk_jobs' ),
                'key'     => 'id',
                'column'  => $field,
                'select'  => array( 'sender_id' ),
                'context' => function ( $row ) use ( $field ) {
                    return 'bulk:' . (int) $row->sender_id . ':' . $field;
                },
            );
        }

        public function can_turn_on()
        {
            if ( ! Better_Messages()->functions->can_use_premium_code_premium_only() ) {
                return new WP_Error( 'better_messages_encryption_websocket', _x( 'Encryption of stored data is part of the WebSocket version of Better Messages and needs its licence to be active.', 'Stored data encryption', 'bp-better-messages' ), array( 'status' => 403 ) );
            }

            return true;
        }

        public function available()
        {
            return null !== $this->engine() && Better_Messages()->functions->can_use_premium_code_premium_only();
        }

        public function on()
        {
            return $this->available() && $this->engine()->enabled( self::OWNER );
        }

        public function wanted()
        {
            return null !== $this->engine() && $this->engine()->wants( self::OWNER );
        }

        public function ever_used()
        {
            return null !== $this->engine() && array() !== $this->engine()->used_ids();
        }

        public function files()
        {
            return Better_Messages_Encryption_Files::instance();
        }

        public function index()
        {
            return $this->engine() ? $this->engine()->index() : null;
        }

        public function searching()
        {
            return $this->wanted() && $this->engine()->has_key() && $this->engine()->index()->installed();
        }

        public function index_message( $message_id, $thread_id, $text )
        {
            if ( ! $this->on() || (int) $message_id <= 0 || (int) $thread_id <= 0 ) {
                return;
            }

            if ( self::stays_readable( $text ) ) {
                $this->index()->remove( self::OWNER, 'message', array( (int) $message_id ) );

                return;
            }

            $this->index()->put( self::OWNER, 'message', (int) $message_id, (int) $thread_id, (string) $text );
        }

        public function index_saved_message( $message )
        {
            if ( is_object( $message ) && ! empty( $message->id ) ) {
                $this->index_message( $message->id, $message->thread_id, $message->message );
            }
        }

        public function index_edited_message( $message_id, $thread_id )
        {
            if ( ! $this->on() ) {
                return;
            }

            global $wpdb;

            $stored = $wpdb->get_var( $wpdb->prepare( 'SELECT message FROM ' . bm_get_table( 'messages' ) . ' WHERE id = %d', $message_id ) );
            $plain  = null === $stored ? null : $this->open_or_null( $stored, $thread_id );

            if ( null !== $plain ) {
                $this->index_message( $message_id, $thread_id, $plain );
            }
        }

        public function unindex_message( $message_id )
        {
            if ( null !== $this->engine() ) {
                $this->index()->remove( self::OWNER, 'message', array( (int) $message_id ) );
            }
        }

        public function unindex_thread( $thread_id )
        {
            if ( null !== $this->engine() ) {
                $this->index()->remove_scope( self::OWNER, 'message', (int) $thread_id );
                $this->index()->remove( self::OWNER, 'subject', array( (int) $thread_id ) );
            }
        }

        public function index_subject( $thread_id, $subject )
        {
            if ( $this->on() && (int) $thread_id > 0 ) {
                $this->index()->put( self::OWNER, 'subject', (int) $thread_id, (int) $thread_id, (string) $subject );
            }
        }

        public function find_messages( $query, $thread_ids = null, $limit = 20, $where = '' )
        {
            global $wpdb;

            if ( ! $this->searching() || ( is_array( $thread_ids ) && empty( $thread_ids ) ) ) {
                return array();
            }

            $found  = array();
            $before = 0;
            $batch  = max( 100, (int) $limit * 5 );

            for ( $round = 0; $round < 5 && count( $found ) < $limit; $round++ ) {
                $candidates = $this->index()->search( self::OWNER, 'message', $query, array( 'scopes' => $thread_ids, 'limit' => $batch, 'before' => $before ) );

                if ( empty( $candidates ) ) {
                    break;
                }

                $before = min( $candidates );
                $rows   = $wpdb->get_results( 'SELECT id, thread_id, message FROM ' . bm_get_table( 'messages' ) . ' WHERE id IN (' . implode( ',', $candidates ) . ') ' . $where . ' ORDER BY id DESC' );

                foreach ( $rows as $row ) {
                    $plain = $this->open_or_null( $row->message, $row->thread_id );

                    if ( null !== $plain && WordPlus_SDK_Encryption_Index::matches( $plain, $query ) ) {
                        $found[ (int) $row->id ] = (int) $row->thread_id;

                        if ( count( $found ) >= $limit ) {
                            break;
                        }
                    }
                }

                if ( count( $candidates ) < $batch ) {
                    break;
                }
            }

            return $found;
        }

        public function find_subjects( $query, $limit = 50 )
        {
            global $wpdb;

            if ( ! $this->searching() ) {
                return array();
            }

            $candidates = $this->index()->search( self::OWNER, 'subject', $query, array( 'limit' => max( 100, (int) $limit * 3 ) ) );

            if ( empty( $candidates ) ) {
                return array();
            }

            $found = array();
            $rows  = $wpdb->get_results( 'SELECT id, subject FROM ' . bm_get_table( 'threads' ) . ' WHERE id IN (' . implode( ',', $candidates ) . ') ORDER BY id DESC' );

            foreach ( $rows as $row ) {
                $plain = $this->open_subject( $row->subject, $row->id );

                if ( '' !== (string) $plain && WordPlus_SDK_Encryption_Index::matches( $plain, $query ) ) {
                    $found[] = (int) $row->id;

                    if ( count( $found ) >= $limit ) {
                        break;
                    }
                }
            }

            return $found;
        }

        public static function is_sealed( $value )
        {
            if ( ! is_string( $value ) || 0 !== strncmp( $value, 'wpe1.', 5 ) || 1 !== preg_match( '/^wpe1\.([0-9a-f]{8})\.[A-Za-z0-9_-]{54,}$/', $value, $match ) ) {
                return false;
            }

            $engine = function_exists( 'wordplus_encryption' ) ? wordplus_encryption() : null;

            if ( ! is_object( $engine ) || ! method_exists( $engine, 'used_ids' ) ) {
                return true;
            }

            return in_array( $match[1], array_map( 'strval', array_merge( $engine->key_ids(), $engine->used_ids() ) ), true );
        }

        public static function stays_readable( $text )
        {
            if ( ! is_string( $text ) || '' === $text ) {
                return true;
            }

            if ( 0 === strpos( $text, '<!-- BM-SYSTEM-MESSAGE:' ) || 0 === strpos( $text, '<!-- BM-PRIVACY-REMOVED -->' ) ) {
                return true;
            }

            if ( 1 === preg_match( '/^<!-- [A-Z0-9][A-Z0-9 _:-]* -->$/', $text ) ) {
                return true;
            }

            if ( false !== strpos( $text, '<span class="bm-call ' ) || false !== strpos( $text, '<span class="bpbm-call ' ) ) {
                return true;
            }

            if ( class_exists( 'Better_Messages_E2E_Encryption' ) && Better_Messages_E2E_Encryption::is_valid_e2e_ciphertext( $text ) ) {
                return true;
            }

            return false;
        }

        public function seal_message( $text, $thread_id )
        {
            if ( (int) $thread_id <= 0 || self::is_sealed( $text ) || self::stays_readable( $text ) || ! $this->on() ) {
                return $text;
            }

            $sealed = $this->engine()->seal( self::OWNER, (string) $text, 'message:' . (int) $thread_id );

            return null === $sealed ? $text : $sealed;
        }

        public function open_message( $text, $thread_id )
        {
            if ( ! self::is_sealed( $text ) ) {
                return $text;
            }

            return $this->open( $text, 'message:' . (int) $thread_id );
        }

        public function open_rows( $rows, $field = 'message', $thread_field = 'thread_id' )
        {
            if ( ! is_array( $rows ) ) {
                return $rows;
            }

            foreach ( $rows as $index => $row ) {
                $rows[ $index ] = $this->open_row( $row, $field, $thread_field );
            }

            return $rows;
        }

        public function open_or_null( $text, $thread_id )
        {
            if ( ! self::is_sealed( $text ) ) {
                return $text;
            }

            $engine = $this->engine();

            return $engine ? $engine->open( self::OWNER, $text, 'message:' . (int) $thread_id ) : null;
        }

        public function open_thread_rows( $rows, $thread_id, $field = 'message' )
        {
            if ( ! is_array( $rows ) ) {
                return $rows;
            }

            foreach ( $rows as $index => $row ) {
                if ( is_object( $row ) && isset( $row->$field ) && self::is_sealed( $row->$field ) ) {
                    $row->$field = $this->open_message( $row->$field, $thread_id );
                } elseif ( is_array( $row ) && isset( $row[ $field ] ) && self::is_sealed( $row[ $field ] ) ) {
                    $rows[ $index ][ $field ] = $this->open_message( $row[ $field ], $thread_id );
                }
            }

            return $rows;
        }

        public function open_row( $row, $field = 'message', $thread_field = 'thread_id' )
        {
            if ( is_object( $row ) && isset( $row->$field ) && self::is_sealed( $row->$field ) ) {
                $row->$field = $this->open_message( $row->$field, isset( $row->$thread_field ) ? $row->$thread_field : 0 );
            } elseif ( is_array( $row ) && isset( $row[ $field ] ) && self::is_sealed( $row[ $field ] ) ) {
                $row[ $field ] = $this->open_message( $row[ $field ], isset( $row[ $thread_field ] ) ? $row[ $thread_field ] : 0 );
            }

            return $row;
        }

        public function seal_subject( $subject, $thread_id )
        {
            if ( ! is_string( $subject ) || '' === $subject || self::is_sealed( $subject ) || (int) $thread_id <= 0 || ! $this->on() || ! self::widened() ) {
                return $subject;
            }

            $sealed = $this->engine()->seal( self::OWNER, $subject, 'subject:' . (int) $thread_id );

            return null === $sealed || strlen( $sealed ) > self::SUBJECT_ROOM ? $subject : $sealed;
        }

        public function open_subject( $subject, $thread_id )
        {
            if ( ! self::is_sealed( $subject ) ) {
                return $subject;
            }

            return $this->open( $subject, 'subject:' . (int) $thread_id, '' );
        }

        public function seal_guest( $guest_id, $field, $value )
        {
            if ( ! is_string( $value ) || '' === $value || self::is_sealed( $value ) || ! $this->on() || ! self::widened() ) {
                return $value;
            }

            $sealed = $this->engine()->seal( self::OWNER, $value, 'guest:' . abs( (int) $guest_id ) . ':' . $field );

            return null === $sealed ? $value : $sealed;
        }

        public function open_guest( $guest_id, $field, $value )
        {
            if ( ! self::is_sealed( $value ) ) {
                return $value;
            }

            return $this->open( $value, 'guest:' . abs( (int) $guest_id ) . ':' . $field, '' );
        }

        public function guest_hash( $field, $value )
        {
            if ( ! is_string( $value ) || '' === trim( $value ) || ! $this->on() ) {
                return null;
            }

            $hash = $this->engine()->exact( self::OWNER, strtolower( trim( $value ) ), 'guest-' . $field );

            return '' === $hash ? null : $hash;
        }

        public function seal_guest_row( array $row, $guest_id )
        {
            if ( ! self::widened() ) {
                return $row;
            }

            foreach ( array( 'email', 'ip' ) as $field ) {
                if ( array_key_exists( $field, $row ) ) {
                    $row[ $field . '_hash' ] = $this->guest_hash( $field, (string) $row[ $field ] );
                    $row[ $field ]           = $this->seal_guest( $guest_id, $field, $row[ $field ] );
                }
            }

            if ( array_key_exists( 'meta', $row ) ) {
                $row['meta'] = $this->seal_guest( $guest_id, 'meta', $row['meta'] );
            }

            return $row;
        }

        public function open_guest_row( $row )
        {
            if ( ! is_object( $row ) && ! is_array( $row ) ) {
                return $row;
            }

            $guest_id = is_object( $row ) ? ( isset( $row->id ) ? $row->id : 0 ) : ( isset( $row['id'] ) ? $row['id'] : 0 );

            foreach ( array( 'email', 'ip', 'meta' ) as $field ) {
                if ( is_object( $row ) && isset( $row->$field ) && self::is_sealed( $row->$field ) ) {
                    $row->$field = $this->open_guest( $guest_id, $field, $row->$field );
                } elseif ( is_array( $row ) && isset( $row[ $field ] ) && self::is_sealed( $row[ $field ] ) ) {
                    $row[ $field ] = $this->open_guest( $guest_id, $field, $row[ $field ] );
                }
            }

            return $row;
        }

        public function seal_bulk_row( array $row )
        {
            if ( ! $this->on() || empty( $row['sender_id'] ) || ! self::widened() ) {
                return $row;
            }

            foreach ( self::BULK_FIELDS as $field ) {
                if ( isset( $row[ $field ] ) && is_string( $row[ $field ] ) && '' !== $row[ $field ] && ! self::is_sealed( $row[ $field ] ) ) {
                    $sealed = $this->engine()->seal( self::OWNER, $row[ $field ], 'bulk:' . (int) $row['sender_id'] . ':' . $field );

                    if ( null !== $sealed && ( 'subject' !== $field || strlen( $sealed ) <= self::SUBJECT_ROOM ) ) {
                        $row[ $field ] = $sealed;
                    }
                }
            }

            return $row;
        }

        public function open_bulk_row( $row, $strict = false )
        {
            if ( ! is_object( $row ) || ! isset( $row->sender_id ) ) {
                return $row;
            }

            foreach ( self::BULK_FIELDS as $field ) {
                if ( isset( $row->$field ) && self::is_sealed( $row->$field ) ) {
                    $engine = $this->engine();
                    $plain  = $engine ? $engine->open( self::OWNER, $row->$field, 'bulk:' . (int) $row->sender_id . ':' . $field ) : null;

                    if ( null === $plain && $strict ) {
                        return null;
                    }

                    $row->$field = null === $plain ? '' : $plain;
                }
            }

            return $row;
        }

        public function guest_search_sql( $search, $alias = '' )
        {
            global $wpdb;

            $column = '' === $alias ? '' : '`' . $alias . '`.';
            $like   = '%' . $wpdb->esc_like( $search ) . '%';
            $parts  = array(
                $wpdb->prepare( "{$column}`name` LIKE %s", $like ),
                $wpdb->prepare( "( {$column}`email` NOT LIKE 'wpe1.%%' AND {$column}`email` LIKE %s )", $like ),
                $wpdb->prepare( "( {$column}`ip` NOT LIKE 'wpe1.%%' AND {$column}`ip` LIKE %s )", $like ),
            );

            foreach ( self::widened() ? array( 'email', 'ip' ) : array() as $field ) {
                $hash = $this->guest_hash( $field, $search );

                if ( null !== $hash ) {
                    $parts[] = $wpdb->prepare( "{$column}`{$field}_hash` = %s", $hash );
                }
            }

            return '( ' . implode( ' OR ', $parts ) . ' )';
        }

        public function guest_email_sql( $email, $alias = '' )
        {
            global $wpdb;

            $column = '' === $alias ? '' : '`' . $alias . '`.';
            $email  = strtolower( trim( (string) $email ) );
            $parts  = array( $wpdb->prepare( "{$column}`email` = %s", $email ) );
            $engine = '' !== $email && self::widened() ? $this->engine() : null;
            $hash   = $engine ? $engine->exact( self::OWNER, $email, 'guest-email' ) : '';

            if ( is_string( $hash ) && '' !== $hash ) {
                $parts[] = $wpdb->prepare( "{$column}`email_hash` = %s", $hash );
            }

            return '( ' . implode( ' OR ', $parts ) . ' )';
        }

        public function guest_job_row( $mode, $row )
        {
            global $wpdb;

            $guest_id = (int) $row->id;

            if ( (int) $row->bot_id > 0 ) {
                return 'skipped';
            }

            $engine = $this->engine();

            if ( ! $engine ) {
                return 'failed';
            }

            $data = array();

            foreach ( array( 'email', 'ip', 'meta' ) as $field ) {
                $value = isset( $row->$field ) ? $row->$field : null;

                if ( null === $value || '' === (string) $value ) {
                    continue;
                }

                $context = 'guest:' . $guest_id . ':' . $field;

                if ( 'seal' === $mode ) {
                    $plain = self::is_sealed( $value ) ? $engine->open( self::OWNER, $value, $context ) : $value;

                    if ( null === $plain ) {
                        return 'failed';
                    }

                    if ( 'meta' !== $field ) {
                        $hash = $this->guest_hash( $field, (string) $plain );

                        if ( $hash !== ( isset( $row->{$field . '_hash'} ) ? $row->{$field . '_hash'} : null ) ) {
                            $data[ $field . '_hash' ] = $hash;
                        }
                    }

                    if ( ! self::is_sealed( $value ) || WordPlus_SDK_Encryption::sealed_with( $value ) !== $engine->key_id() ) {
                        $sealed = $engine->seal( self::OWNER, (string) $plain, $context );

                        if ( null === $sealed ) {
                            return 'failed';
                        }

                        $data[ $field ] = $sealed;
                    }
                } else {
                    if ( 'meta' !== $field && null !== ( isset( $row->{$field . '_hash'} ) ? $row->{$field . '_hash'} : null ) ) {
                        $data[ $field . '_hash' ] = null;
                    }

                    if ( self::is_sealed( $value ) ) {
                        $plain = $engine->open( self::OWNER, $value, $context );

                        if ( null === $plain ) {
                            return 'failed';
                        }

                        $data[ $field ] = $plain;
                    }
                }
            }

            if ( empty( $data ) ) {
                return null;
            }

            $where = array( 'id' => $guest_id );

            foreach ( array( 'email', 'ip', 'meta' ) as $field ) {
                if ( isset( $data[ $field ] ) ) {
                    $where[ $field ] = $row->$field;
                }
            }

            $done = $wpdb->update( bm_get_table( 'guests' ), $data, $where );

            wp_cache_delete( 'guest_user_' . $guest_id, 'bm_messages' );

            if ( false === $done ) {
                return 'failed';
            }

            return 1 === (int) $done ? 'changed' : 'skipped';
        }

        public static function is_sealed_meta( $meta_key )
        {
            if ( in_array( $meta_key, self::SEALED_META, true ) ) {
                return true;
            }

            foreach ( self::SEALED_META_PREFIXES as $prefix ) {
                if ( is_string( $meta_key ) && 0 === strpos( $meta_key, $prefix ) ) {
                    return true;
                }
            }

            return false;
        }

        public function seal_meta( $message_id, $meta_key, $value )
        {
            if ( ! self::is_sealed_meta( $meta_key ) || null === $value || ! $this->on() ) {
                return $value;
            }

            if ( is_string( $value ) && self::is_sealed( $value ) ) {
                return $value;
            }

            $sealed = $this->engine()->seal( self::OWNER, maybe_serialize( $value ), 'meta:' . (int) $message_id . ':' . $meta_key );

            return null === $sealed ? $value : $sealed;
        }

        public function open_meta( $message_id, $meta_key, $value )
        {
            if ( ! is_string( $value ) || ! self::is_sealed( $value ) ) {
                return $value;
            }

            $plain = $this->open( $value, 'meta:' . (int) $message_id . ':' . $meta_key, null );

            return null === $plain ? '' : maybe_unserialize( $plain );
        }

        public function read_meta( $check, $object_id, $meta_key, $single )
        {
            $object_id = absint( $object_id );

            if ( null !== $check || ! $object_id ) {
                return $check;
            }

            $cache = wp_cache_get( $object_id, 'bm_message_meta' );

            if ( false === $cache ) {
                $caches = update_meta_cache( 'bm_message', array( $object_id ) );
                $cache  = isset( $caches[ $object_id ] ) ? $caches[ $object_id ] : array();
            }

            if ( ! is_array( $cache ) ) {
                return null;
            }

            if ( is_string( $meta_key ) && '' !== $meta_key ) {
                if ( ! isset( $cache[ $meta_key ] ) || ! self::any_sealed( $cache[ $meta_key ] ) ) {
                    return null;
                }

                $values = array();

                foreach ( $cache[ $meta_key ] as $raw ) {
                    $values[] = self::is_sealed( $raw ) ? $this->open_meta( $object_id, $meta_key, $raw ) : maybe_unserialize( $raw );
                }

                return $single ? array( $values[0] ) : $values;
            }

            $sealed = false;

            foreach ( $cache as $values ) {
                if ( self::any_sealed( $values ) ) {
                    $sealed = true;
                    break;
                }
            }

            if ( ! $sealed ) {
                return null;
            }

            foreach ( $cache as $key => $values ) {
                foreach ( (array) $values as $index => $raw ) {
                    if ( self::is_sealed( $raw ) ) {
                        $plain = $this->open( $raw, 'meta:' . $object_id . ':' . $key, null );

                        $cache[ $key ][ $index ] = null === $plain ? '' : $plain;
                    }
                }
            }

            return $cache;
        }

        public function update_meta( $check, $object_id, $meta_key, $meta_value, $prev_value )
        {
            if ( null !== $check || $this->writing || ! self::is_sealed_meta( $meta_key ) ) {
                return $check;
            }

            if ( ! self::rebuilt_meta( $meta_key ) && $this->unreadable_meta( absint( $object_id ), $meta_key ) ) {
                return false;
            }

            if ( '' !== $prev_value && null !== $prev_value ) {
                return $check;
            }

            $sealed = $this->seal_meta( $object_id, $meta_key, $meta_value );

            if ( $sealed === $meta_value ) {
                return $check;
            }

            $this->writing = true;
            $result        = update_metadata( 'bm_message', $object_id, $meta_key, $sealed );
            $this->writing = false;

            return $result;
        }

        public function add_meta( $check, $object_id, $meta_key, $meta_value, $unique )
        {
            if ( null !== $check || $this->writing || ! self::is_sealed_meta( $meta_key ) ) {
                return $check;
            }

            $sealed = $this->seal_meta( $object_id, $meta_key, $meta_value );

            if ( $sealed === $meta_value ) {
                return $check;
            }

            $this->writing = true;
            $result        = add_metadata( 'bm_message', $object_id, $meta_key, $sealed, $unique );
            $this->writing = false;

            return $result;
        }

        public static function rebuilt_meta( $meta_key )
        {
            return 'bm_translations' === $meta_key || ( is_string( $meta_key ) && 0 === strpos( $meta_key, 'url_info_' ) );
        }

        public function unreadable_meta( $message_id, $meta_key )
        {
            if ( ! $message_id ) {
                return false;
            }

            $cache = wp_cache_get( $message_id, 'bm_message_meta' );

            if ( false === $cache ) {
                $caches = update_meta_cache( 'bm_message', array( $message_id ) );
                $cache  = isset( $caches[ $message_id ] ) ? $caches[ $message_id ] : array();
            }

            if ( ! is_array( $cache ) || empty( $cache[ $meta_key ] ) ) {
                return false;
            }

            foreach ( (array) $cache[ $meta_key ] as $raw ) {
                if ( self::is_sealed( $raw ) && null === $this->open( $raw, 'meta:' . (int) $message_id . ':' . $meta_key, null ) ) {
                    return true;
                }
            }

            return false;
        }

        private static function any_sealed( $values )
        {
            foreach ( (array) $values as $value ) {
                if ( self::is_sealed( $value ) ) {
                    return true;
                }
            }

            return false;
        }

        public static function unreadable()
        {
            return _x( 'This message cannot be read: the encryption key of this site is missing.', 'Stored data encryption', 'bp-better-messages' );
        }

        private function open( $value, $context, $fallback = false )
        {
            $engine = $this->engine();
            $plain  = $engine ? $engine->open( self::OWNER, $value, $context ) : null;

            if ( null === $plain ) {
                return false === $fallback ? self::unreadable() : $fallback;
            }

            return $plain;
        }

        public function report_leaks( $response, $server, $request )
        {
            if ( ! ( $response instanceof WP_REST_Response ) ) {
                return $response;
            }

            $route = $request->get_route();

            if ( 0 !== strpos( $route, '/better-messages/' ) ) {
                return $response;
            }

            $data = wp_json_encode( $response->get_data() );

            if ( is_string( $data ) && false !== strpos( $data, 'wpe1.' ) && preg_match( '/wpe1\.[0-9a-f]{8}\.[A-Za-z0-9_-]{20}/', $data ) ) {
                $this->leaks[] = $route;
                $response->header( 'X-BM-Sealed-Leak', $route );
                error_log( 'Better Messages: a sealed value reached the response of ' . $route ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            }

            return $response;
        }
    }

endif;

function Better_Messages_Encryption()
{
    return Better_Messages_Encryption::instance();
}
