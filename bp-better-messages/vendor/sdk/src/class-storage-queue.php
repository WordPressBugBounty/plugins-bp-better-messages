<?php
defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WordPlus_SDK_Storage_Queue' ) ) {
    final class WordPlus_SDK_Storage_Queue
    {
        const MIGRATION_OPTION = 'wordplus_storage_migration';

        const RETIRED_OPTION = 'wordplus_storage_retired';

        const LOCAL_GRACE = DAY_IN_SECONDS;

        const CLOUD_GRACE = 2 * DAY_IN_SECONDS;

        private $storage;

        private $after_response = array();
        private $shutdown_hooked = false;
        private $relocated       = array();

        public function __construct( WordPlus_SDK_Storage $storage )
        {
            $this->storage = $storage;
        }

        public function run( $budget = 25 )
        {
            global $wpdb;

            $storage = $this->storage;
            $started = microtime( true );

            if ( ! $storage->table_exists() ) {
                do_action( 'wordplus_storage_index', $budget );
                $this->reschedule( true );

                return;
            }

            $now     = time();
            $blog_id = get_current_blog_id();

            $this->relocated = array();

            foreach ( array_keys( $storage->get_settings()['locations'] ) as $id ) {
                $storage->folder_state( $storage->get_location( $id ) );
            }

            $wpdb->query( $wpdb->prepare(
                "UPDATE {$wpdb->wordplus_storage_objects} SET state = CASE WHEN object_key <> '' AND location <> '' THEN 'stored' WHEN location <> '' AND target IS NULL THEN 'pending_move' ELSE 'local' END, next_attempt_at = %d WHERE blog_id = %d AND state = 'moving' AND updated_at < %d",
                $now, $blog_id, $now - 1800
            ) );

            $storage->direct()->sweep_abandoned();

            do_action( 'wordplus_storage_index', min( 5, $budget ) );

            $finished = $this->run_rows( false, array( $this, 'move_row' ), $started, $budget )
                && $this->run_rows( true, array( $this, 'move_to_target' ), $started, $budget );

            $left = $budget - ( microtime( true ) - $started );

            if ( $left > 2 ) {
                $this->run_deletions( min( 20, $left ) );
            }

            $left = $budget - ( microtime( true ) - $started );

            if ( $finished && $left > 2 && $this->migration_running() ) {
                $this->run_migration( $left );
            }

            $left = $budget - ( microtime( true ) - $started );

            if ( $left > 2 ) {
                $storage->sweeper()->run( min( 20, $left ) );
            }

            $this->announce_relocated();

            $this->reschedule( ! $finished || $this->migration_running() );
        }

        private function reschedule( $busy )
        {
            global $wpdb;

            $storage = $this->storage;

            if ( $busy || ! apply_filters( 'wordplus_storage_indexed', true ) ) {
                $storage->schedule( 60 );

                return;
            }

            if ( $storage->table_exists() ) {
                $next = (int) $wpdb->get_var( $wpdb->prepare(
                    "SELECT MIN(next_attempt_at) FROM {$wpdb->wordplus_storage_objects} WHERE blog_id IN (%d, 0) AND ( state IN ('pending_move','deleting','delete_failed','removing','uploading') OR target IS NOT NULL ) AND next_attempt_at < %d",
                    get_current_blog_id(), WordPlus_SDK_Storage::NEVER
                ) );

                if ( $next > 0 ) {
                    $storage->schedule( min( HOUR_IN_SECONDS, max( 60, $next - time() ) ) );

                    return;
                }
            }

            if ( $storage->has_locations() ) {
                $storage->schedule( HOUR_IN_SECONDS );
            }
        }

        private function run_rows( $targets, $callback, $started, $budget )
        {
            global $wpdb;

            $now    = time();
            $cursor = 0;

            do {
                $rows = $targets
                    ? $wpdb->get_results( $wpdb->prepare(
                        "SELECT * FROM {$wpdb->wordplus_storage_objects} WHERE blog_id = %d AND target IS NOT NULL AND state IN ('local','failed','stored') AND next_attempt_at <= %d AND id > %d ORDER BY id ASC LIMIT 20",
                        get_current_blog_id(), $now, $cursor
                    ) )
                    : $wpdb->get_results( $wpdb->prepare(
                        "SELECT * FROM {$wpdb->wordplus_storage_objects} WHERE blog_id = %d AND state = 'pending_move' AND next_attempt_at <= %d AND id > %d ORDER BY id ASC LIMIT 20",
                        get_current_blog_id(), $now, $cursor
                    ) );

                foreach ( $rows as $row ) {
                    if ( microtime( true ) - $started > $budget ) {
                        return false;
                    }

                    $cursor = (int) $row->id;
                    call_user_func( $callback, $row );
                }
            } while ( 20 === count( $rows ) );

            return true;
        }

        public function run_after_response( $row_id )
        {
            $this->after_response[ (int) $row_id ] = true;

            if ( ! $this->shutdown_hooked ) {
                $this->shutdown_hooked = true;
                add_action( 'shutdown', array( $this, 'run_inline' ), 1000 );
            }
        }

        public function run_inline()
        {
            global $wpdb;

            if ( empty( $this->after_response ) ) {
                return;
            }

            $ids                  = array_keys( $this->after_response );
            $this->after_response = array();

            $can_finish = function_exists( 'fastcgi_finish_request' ) || function_exists( 'litespeed_finish_request' );

            if ( ! $can_finish || ! apply_filters( 'wordplus_storage_run_inline', true ) ) {
                $this->storage->schedule( 0 );

                return;
            }

            if ( function_exists( 'fastcgi_finish_request' ) ) {
                fastcgi_finish_request();
            } else {
                litespeed_finish_request();
            }

            ignore_user_abort( true );

            $rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->wordplus_storage_objects} WHERE id IN (" . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ')', $ids ) );

            $later = false;

            foreach ( $rows as $row ) {
                if ( 'pending_move' !== $row->state ) {
                    continue;
                }

                if ( (int) $row->size <= WordPlus_SDK_Storage::INLINE_MAX ) {
                    $this->move_row( $row );
                } else {
                    $later = true;
                }
            }

            if ( $later ) {
                $this->storage->schedule( 0 );
            }
        }

        public static function retry_at( $attempts )
        {
            $delays = array( 60, 300, 1800, 7200 );

            return time() + ( isset( $delays[ $attempts - 1 ] ) ? $delays[ $attempts - 1 ] : DAY_IN_SECONDS );
        }

        private function fail_row( $row, $error, $fail_state, $retry_state )
        {
            $attempts = (int) $row->attempts + 1;

            $this->storage->update_row( $row->id, array(
                'state'           => $attempts >= WordPlus_SDK_Storage::MAX_ATTEMPTS ? $fail_state : $retry_state,
                'attempts'        => $attempts,
                'next_attempt_at' => self::retry_at( $attempts ),
                'error'           => mb_substr( (string) $error, 0, 1000 ),
            ) );
        }

        public function move_row( $row )
        {
            global $wpdb;

            $storage = $this->storage;
            $target  = $storage->get_location( (string) $row->location );

            if ( $target ) {
                $access = $storage->folder_access( $target );

                if ( 'never' === $access ) {
                    $storage->update_row( $row->id, array( 'state' => 'local', 'location' => '', 'error' => null ) );

                    return false;
                }

                if ( 'wait' === $access ) {
                    $storage->update_row( $row->id, array( 'next_attempt_at' => time() + HOUR_IN_SECONDS ) );

                    return false;
                }
            }

            if ( ! $storage->claim( $row, 'pending_move', 'moving' ) ) {
                return false;
            }

            if ( null === $row->ref ) {
                $storage->delete_row( $row->id );

                return false;
            }

            if ( ! $target ) {
                $storage->update_row( $row->id, array( 'state' => 'failed', 'location' => '', 'error' => 'The storage no longer exists' ) );

                return false;
            }

            $path = $storage->local_path_for( $row->path );

            if ( ! $path || ! file_exists( $path ) ) {
                $storage->update_row( $row->id, array( 'state' => 'failed', 'location' => '', 'error' => 'The file on this server is missing' ) );

                return false;
            }

            $size   = (int) filesize( $path );
            $key    = $storage->object_key( $target, $row );
            $client = $storage->client( $target );
            $put    = $client->put_file( $key, $path, $storage->upload_headers( $target, $row ) );

            if ( empty( $put['ok'] ) ) {
                $this->fail_row( $row, $client->last_error ? $client->last_error : $put['error'], 'failed', 'pending_move' );

                return false;
            }

            $head = $client->head( $key );

            if ( empty( $head['ok'] ) || (int) $head['size'] !== $size ) {
                $client->delete( $key );
                $this->fail_row( $row, sprintf( 'Size check failed: %d stored, %d expected', (int) $head['size'], $size ), 'failed', 'pending_move' );

                return false;
            }

            $stored = $wpdb->query( $wpdb->prepare(
                "UPDATE {$wpdb->wordplus_storage_objects} SET state = 'stored', object_key = %s, size = %d, attempts = 0, error = NULL, updated_at = %d WHERE id = %d AND state = 'moving' AND ref IS NOT NULL",
                $key, $size, time(), (int) $row->id
            ) );

            $storage->reset_caches();

            if ( 1 !== (int) $stored ) {
                $storage->update_row( $row->id, array(
                    'state'           => 'deleting',
                    'object_key'      => $key,
                    'attempts'        => 0,
                    'next_attempt_at' => time(),
                    'error'           => null,
                ) );

                return false;
            }

            $storage->delete_local( $row->path );

            $row->state      = 'stored';
            $row->object_key = $key;
            $row->size       = $size;

            do_action( 'wordplus_storage_sent', $row, $target['id'] );

            return true;
        }

        public function move_to_target( $row )
        {
            $storage = $this->storage;
            $to      = (string) $row->target;
            $from    = 'stored' === $row->state ? (string) $row->location : '';

            if ( $to === $from ) {
                $storage->update_row( $row->id, array( 'target' => null ) );

                return;
            }

            if ( '' !== $to && ! $storage->get_location( $to ) ) {
                $storage->update_row( $row->id, array( 'target' => null, 'error' => 'The storage to move to no longer exists' ) );

                return;
            }

            $result = $this->migrate_row( $row, $to );

            if ( 'moved' === $result ) {
                $storage->update_row( $row->id, array( 'target' => null, 'attempts' => 0, 'error' => null ) );

                return;
            }

            $current = $storage->object( (int) $row->id );

            if ( ! $current || 'moving' === $current->state ) {
                return;
            }

            if ( 'skipped' === $result ) {
                $storage->update_row( $row->id, array( 'next_attempt_at' => time() + 5 * MINUTE_IN_SECONDS ) );

                return;
            }

            $attempts = (int) $current->attempts;

            $storage->update_row( $row->id, array(
                'next_attempt_at' => $attempts >= WordPlus_SDK_Storage::MAX_ATTEMPTS ? WordPlus_SDK_Storage::NEVER : self::retry_at( $attempts ),
            ) );
        }

        private static function same_account( array $a, array $b )
        {
            return strtolower( (string) wp_parse_url( $a['endpoint'], PHP_URL_HOST ) ) === strtolower( (string) wp_parse_url( $b['endpoint'], PHP_URL_HOST ) )
                && $a['access_key'] === $b['access_key'];
        }

        private static function same_bucket_name( array $a, array $b )
        {
            return strtolower( trim( (string) $a['bucket'] ) ) === strtolower( trim( (string) $b['bucket'] ) );
        }

        private static function authority( array $location )
        {
            $parts  = wp_parse_url( trim( (string) $location['endpoint'] ) );
            $scheme = isset( $parts['scheme'] ) ? strtolower( $parts['scheme'] ) : 'https';
            $host   = isset( $parts['host'] ) ? strtolower( $parts['host'] ) : '';

            return '' !== $host ? $host . ':' . ( isset( $parts['port'] ) ? (int) $parts['port'] : ( 'https' === $scheme ? 443 : 80 ) ) : '';
        }

        private static function same_bucket( array $a, array $b )
        {
            if ( ! self::same_bucket_name( $a, $b ) ) {
                return false;
            }

            return ( '' !== (string) $a['access_key'] && (string) $a['access_key'] === (string) $b['access_key'] )
                || ( '' !== self::authority( $a ) && self::authority( $a ) === self::authority( $b ) );
        }

        private static function last_modified( array $head )
        {
            return isset( $head['headers']['last-modified'] ) ? trim( (string) $head['headers']['last-modified'] ) : '';
        }

        private static function answered( array $head )
        {
            return (int) $head['code'] > 0 && (int) $head['code'] < 500;
        }

        private function same_object( array $a, array $b, $key, $b_head = null )
        {
            if ( '' === (string) $key || ! self::same_bucket_name( $a, $b ) ) {
                return false;
            }

            if ( self::same_bucket( $a, $b ) ) {
                return true;
            }

            $b_head = is_array( $b_head ) ? $b_head : $this->storage->client( $b )->head( $key );
            $a_head = $this->storage->client( $a )->head( $key );

            if ( ! empty( $a_head['ok'] ) && ! empty( $b_head['ok'] ) && '' !== $a_head['etag'] && '' !== $b_head['etag'] && '' !== self::last_modified( $a_head ) && '' !== self::last_modified( $b_head ) ) {
                return $a_head['etag'] === $b_head['etag'] && self::last_modified( $a_head ) === self::last_modified( $b_head ) && (int) $a_head['size'] === (int) $b_head['size'];
            }

            return self::answered( $a_head ) && self::answered( $b_head ) ? false : null;
        }

        private function key_in_use( $row, array $location )
        {
            global $wpdb;

            if ( null !== $row->ref || 0 === (int) $row->blog_id ) {
                return false;
            }

            $others = $wpdb->get_col( $wpdb->prepare(
                "SELECT DISTINCT location FROM {$wpdb->wordplus_storage_objects} WHERE blog_id = %d AND ref IS NOT NULL AND object_key = %s AND location <> %s",
                (int) $row->blog_id, (string) $row->object_key, (string) $row->location
            ) );

            $in_use = false;

            foreach ( (array) $others as $id ) {
                $other = $this->storage->get_location( (string) $id );

                if ( ! $other || ! self::same_bucket_name( $location, $other ) ) {
                    continue;
                }

                $same = $this->same_object( $location, $other, (string) $row->object_key );

                if ( true === $same ) {
                    return true;
                }

                if ( null === $same ) {
                    $in_use = null;
                }
            }

            return $in_use;
        }

        private function fail_move( $row, $original_state, $error )
        {
            $this->storage->update_row( $row->id, array(
                'state'    => $original_state,
                'attempts' => (int) $row->attempts + 1,
                'error'    => mb_substr( (string) $error, 0, 1000 ),
            ) );

            return '' === $error ? 'failed' : $error;
        }

        private function forgotten( $row )
        {
            $current = $this->storage->object( (int) $row->id );

            return ! $current || null === $current->ref;
        }

        public function migrate_row( $row, $to )
        {
            $storage  = $this->storage;
            $original = $row->state;
            $from     = 'stored' === $original ? (string) $row->location : '';

            if ( null === $row->ref ) {
                return 'skipped';
            }

            if ( ! apply_filters( 'wordplus_storage_can_move', true, $row ) ) {
                return 'skipped';
            }

            $target = '' !== $to ? $storage->get_location( $to ) : null;

            if ( $target && 'yes' !== $storage->folder_access( $target ) ) {
                return 'skipped';
            }

            if ( ! $storage->claim( $row, $original, 'moving' ) ) {
                return 'skipped';
            }

            $source = '' !== $from ? $storage->get_location( $from ) : null;

            if ( '' !== $from && ! $source ) {
                return $this->fail_move( $row, $original, 'The storage the file is in no longer exists' );
            }

            $source_key    = (string) $row->object_key;
            $expected_size = '' === $from ? 0 : (int) $row->size;

            if ( '' === $to ) {
                $path = $storage->local_path_for( $row->path );

                if ( ! $path ) {
                    return $this->fail_move( $row, $original, 'The file has no path on this server' );
                }

                if ( ! wp_mkdir_p( dirname( $path ) ) ) {
                    return $this->fail_move( $row, $original, 'Cannot create the folder on this server' );
                }

                $reuse = file_exists( $path ) && $expected_size > 0 && filesize( $path ) === $expected_size;

                if ( ! $reuse ) {
                    $part   = $path . '.wordplus-part';
                    $client = $storage->client( $source );
                    $result = $client->download( $source_key, $part );

                    clearstatcache( true, $part );

                    if ( empty( $result['ok'] ) || ! file_exists( $part ) || ( $expected_size > 0 && filesize( $part ) !== $expected_size ) ) {
                        wp_delete_file( $part );

                        return $this->fail_move( $row, $original, empty( $result['ok'] ) ? $client->last_error : 'The downloaded file has a different size' );
                    }

                    if ( ! WordPlus_SDK_Storage::rename( $part, $path ) ) {
                        wp_delete_file( $part );

                        return $this->fail_move( $row, $original, 'Cannot write the file on this server' );
                    }
                }

                if ( $this->forgotten( $row ) ) {
                    $storage->delete_local( $row->path );
                    $storage->update_row( $row->id, array( 'state' => 'deleting', 'next_attempt_at' => time() ) );

                    return 'skipped';
                }

                $this->cancel_source_deletion( '', (string) $row->path );

                $storage->update_row( $row->id, array(
                    'state'      => 'local',
                    'location'   => '',
                    'object_key' => '',
                    'size'       => (int) filesize( $path ),
                    'attempts'   => 0,
                    'error'      => null,
                ) );

                $this->schedule_source_deletion( $from, $source_key, self::CLOUD_GRACE );
                $this->relocated[ $row->plugin ] = true;

                return 'moved';
            }

            $destination = $storage->get_location( $to );

            if ( ! $destination ) {
                return $this->fail_move( $row, $original, 'The storage to move to no longer exists' );
            }

            $key      = $storage->object_key( $destination, $row );
            $client   = $storage->client( $destination );
            $in_place = false;
            $head     = null;

            if ( '' !== $from && '' !== (string) $source_key && (string) $source_key === (string) $key && self::same_bucket_name( $source, $destination ) ) {
                $head     = $client->head( $key );
                $in_place = ! empty( $head['ok'] ) && ( $expected_size <= 0 || (int) $head['size'] === $expected_size )
                    && true === $this->same_object( $source, $destination, $key, $head );
            }

            if ( $in_place ) {
                $put = $head;
            } elseif ( '' === $from ) {
                $path = $storage->local_path_for( $row->path );

                if ( ! $path || ! file_exists( $path ) ) {
                    return $this->fail_move( $row, $original, 'The file on this server is missing' );
                }

                $expected_size = (int) filesize( $path );
                $put           = $client->put_file( $key, $path, $storage->upload_headers( $destination, $row ) );
            } elseif ( self::same_account( $source, $destination ) ) {
                $put = $client->copy_object( $source['bucket'], $source_key, $key );
            } else {
                $temp = wp_tempnam( 'wordplus-move' );
                $got  = $storage->client( $source )->download( $source_key, $temp );

                if ( empty( $got['ok'] ) ) {
                    wp_delete_file( $temp );

                    return $this->fail_move( $row, $original, $got['error'] );
                }

                $put = $client->put_file( $key, $temp, $storage->upload_headers( $destination, $row ) );
                wp_delete_file( $temp );
            }

            if ( empty( $put['ok'] ) ) {
                return $this->fail_move( $row, $original, $client->last_error ? $client->last_error : $put['error'] );
            }

            if ( ! $in_place ) {
                $head = $client->head( $key );

                if ( empty( $head['ok'] ) || ( $expected_size > 0 && (int) $head['size'] !== $expected_size ) ) {
                    $client->delete( $key );

                    return $this->fail_move( $row, $original, 'The copied file has a different size' );
                }
            }

            if ( $this->forgotten( $row ) ) {
                if ( ! $in_place ) {
                    $this->schedule_source_deletion( $destination['id'], $key, 0 );
                }

                $storage->update_row( $row->id, array( 'state' => 'deleting', 'next_attempt_at' => time() ) );

                return 'skipped';
            }

            $this->cancel_source_deletion( $destination['id'], $key );

            $storage->update_row( $row->id, array(
                'state'      => 'stored',
                'location'   => $destination['id'],
                'object_key' => $key,
                'size'       => (int) $head['size'],
                'attempts'   => 0,
                'error'      => null,
            ) );

            if ( '' === $from ) {
                $this->schedule_source_deletion( '', (string) $row->path, self::LOCAL_GRACE );
            } elseif ( ! $in_place ) {
                $this->schedule_source_deletion( $from, $source_key, self::CLOUD_GRACE );
            }

            $this->relocated[ $row->plugin ] = true;

            return 'moved';
        }

        private function announce_relocated()
        {
            if ( ! empty( $this->relocated ) ) {
                do_action( 'wordplus_storage_relocated', array_keys( $this->relocated ) );
                $this->relocated = array();
            }
        }

        public function run_deletions( $budget = 20 )
        {
            global $wpdb;

            $storage = $this->storage;

            if ( ! $storage->table_exists() ) {
                return;
            }

            $started = microtime( true );
            $now     = time();
            $retired = get_site_option( self::RETIRED_OPTION, array() );
            $blog    = get_current_blog_id();
            $also    = ! empty( $retired ) ? 0 : $blog;

            $wpdb->query( $wpdb->prepare(
                "UPDATE {$wpdb->wordplus_storage_objects} SET state = 'deleting', next_attempt_at = %d WHERE blog_id IN (%d, %d) AND state = 'removing' AND updated_at < %d",
                $now, $blog, $also, $now - 600
            ) );

            do {
                $rows = $wpdb->get_results( $wpdb->prepare(
                    "SELECT * FROM {$wpdb->wordplus_storage_objects} WHERE blog_id IN (%d, %d) AND state IN ('deleting','delete_failed') AND next_attempt_at <= %d ORDER BY id ASC LIMIT 20",
                    $blog, $also, $now
                ) );

                foreach ( $rows as $row ) {
                    if ( microtime( true ) - $started > $budget ) {
                        return;
                    }

                    $this->delete_object_row( $row );
                }
            } while ( 20 === count( $rows ) && microtime( true ) - $started < $budget );

            if ( is_array( $retired ) && ! empty( $retired ) ) {
                $left = $retired;

                foreach ( array_keys( $retired ) as $location_id ) {
                    if ( ! $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$wpdb->wordplus_storage_objects} WHERE blog_id = 0 AND location = %s LIMIT 1", (string) $location_id ) ) ) {
                        unset( $left[ $location_id ] );
                    }
                }

                if ( count( $left ) !== count( $retired ) ) {
                    update_site_option( self::RETIRED_OPTION, $left );
                }
            }
        }

        public function delete_object_row( $row )
        {
            $storage = $this->storage;

            if ( '' !== (string) $row->location && '' !== (string) $row->object_key ) {
                $folder = $storage->row_location( $row );

                if ( $folder ) {
                    $access = $storage->folder_access( $folder );

                    if ( 'never' === $access ) {
                        $storage->delete_row( $row->id );

                        return true;
                    }

                    if ( 'wait' === $access ) {
                        $storage->update_row( $row->id, array( 'next_attempt_at' => time() + HOUR_IN_SECONDS ) );

                        return false;
                    }
                }
            }

            if ( ! $storage->claim( $row, (string) $row->state, 'removing' ) ) {
                return false;
            }

            if ( '' === (string) $row->object_key ) {
                $storage->delete_row( $row->id );

                return true;
            }

            if ( '' === (string) $row->location ) {
                $storage->delete_local( $row->object_key );
                $storage->delete_row( $row->id );

                return true;
            }

            $location = $storage->row_location( $row );

            if ( ! $location ) {
                $this->fail_row( $row, 'The storage no longer exists', 'deleting', 'deleting' );

                return false;
            }

            $in_use = $this->key_in_use( $row, $location );

            if ( null === $in_use ) {
                $this->fail_row( $row, 'Could not tell whether a file kept under this key in another storage over the same bucket is this one', 'deleting', 'deleting' );

                return false;
            }

            if ( $in_use ) {
                $storage->delete_row( $row->id );

                return true;
            }

            $client = $storage->client( $location );
            $result = $client->delete( $row->object_key );

            if ( empty( $result['ok'] ) ) {
                $this->fail_row( $row, $client->last_error, 'deleting', 'deleting' );

                return false;
            }

            $head = $client->head( $row->object_key );

            if ( 404 !== (int) $head['code'] ) {
                if ( ! empty( $head['ok'] ) ) {
                    $error = 'The file is still in the storage after it was deleted';
                } elseif ( 403 === (int) $head['code'] ) {
                    $error = 'The storage answers 403 instead of 404 for the deleted file, so it cannot be confirmed gone. Let the key list the bucket (s3:ListBucket)';
                } else {
                    $error = $client->last_error;
                }

                $this->fail_row( $row, $error, 'deleting', 'deleting' );

                return false;
            }

            $purged = $storage->purge_pull_zone( $location, $row->object_key );

            if ( true !== $purged ) {
                $this->fail_row( $row, $purged, 'deleting', 'deleting' );

                return false;
            }

            do_action( 'wordplus_storage_deleted', $row, $location['id'] );

            $storage->delete_row( $row->id );

            return true;
        }

        public function schedule_source_deletion( $location_id, $key, $delay )
        {
            global $wpdb;

            if ( '' === (string) $key || ! $this->storage->install() ) {
                return;
            }

            $now = time();

            $wpdb->insert( $this->storage->table(), array(
                'blog_id'         => get_current_blog_id(),
                'ref'             => null,
                'location'        => (string) $location_id,
                'object_key'      => (string) $key,
                'state'           => 'deleting',
                'attempts'        => 0,
                'next_attempt_at' => $now + max( 0, (int) $delay ),
                'created_at'      => $now,
                'updated_at'      => $now,
            ) );

            $this->storage->schedule( max( 60, (int) $delay ) );
        }

        public function cancel_source_deletion( $location_id, $key )
        {
            global $wpdb;

            $wpdb->query( $wpdb->prepare(
                "DELETE FROM {$wpdb->wordplus_storage_objects} WHERE blog_id = %d AND ref IS NULL AND plugin = '' AND state = 'deleting' AND location = %s AND object_key = %s",
                get_current_blog_id(), (string) $location_id, (string) $key
            ) );
        }

        public function migration_state()
        {
            wp_cache_delete( self::MIGRATION_OPTION, 'options' );

            $state = get_option( self::MIGRATION_OPTION, array() );

            return wp_parse_args( is_array( $state ) ? $state : array(), array(
                'status'      => 'idle',
                'from'        => '',
                'to'          => '',
                'total'       => 0,
                'total_bytes' => 0,
                'moved'       => 0,
                'moved_bytes' => 0,
                'failed'      => 0,
                'skipped'     => 0,
                'cursor'      => 0,
                'started_at'  => 0,
                'finished_at' => 0,
                'last_error'  => '',
            ) );
        }

        private function save_migration( array $state )
        {
            update_option( self::MIGRATION_OPTION, $state, false );
        }

        public function migration_running()
        {
            $status = $this->migration_state()['status'];

            return 'running' === $status || 'indexing' === $status;
        }

        private static function place( $id )
        {
            return ( 'local' === $id || '' === $id ) ? '' : (string) $id;
        }

        public function remaining( $from, $cursor = 0 )
        {
            global $wpdb;

            $row = '' === $from
                ? $wpdb->get_row( $wpdb->prepare(
                    "SELECT COUNT(*) AS files, COALESCE(SUM(size), 0) AS bytes FROM {$wpdb->wordplus_storage_objects} WHERE blog_id = %d AND ref IS NOT NULL AND state IN ('local','failed') AND id > %d",
                    get_current_blog_id(), (int) $cursor
                ) )
                : $wpdb->get_row( $wpdb->prepare(
                    "SELECT COUNT(*) AS files, COALESCE(SUM(size), 0) AS bytes FROM {$wpdb->wordplus_storage_objects} WHERE blog_id = %d AND ref IS NOT NULL AND state = 'stored' AND location = %s AND id > %d",
                    get_current_blog_id(), $from, (int) $cursor
                ) );

            return array( 'files' => (int) $row->files, 'bytes' => (int) $row->bytes );
        }

        public function start_migration( $from, $to )
        {
            $storage = $this->storage;
            $from    = self::place( $from );
            $to      = self::place( $to );

            if ( $this->migration_running() ) {
                return new WP_Error( 'wordplus_storage_busy', _x( 'Files are already being moved.', 'Storage', 'wordplus-sdk' ), array( 'status' => 409 ) );
            }

            if ( $from === $to ) {
                return new WP_Error( 'wordplus_storage_invalid', _x( 'Choose two different places.', 'Storage', 'wordplus-sdk' ), array( 'status' => 400 ) );
            }

            foreach ( array( $from, $to ) as $id ) {
                if ( '' !== $id && ! $storage->get_location( $id ) ) {
                    return new WP_Error( 'wordplus_storage_invalid', _x( 'That storage no longer exists.', 'Storage', 'wordplus-sdk' ), array( 'status' => 400 ) );
                }
            }

            if ( ! $storage->install() ) {
                return new WP_Error( 'wordplus_storage_invalid', _x( 'The storage table could not be made.', 'Storage', 'wordplus-sdk' ), array( 'status' => 500 ) );
            }

            $state = array(
                'status'      => apply_filters( 'wordplus_storage_indexed', true ) ? 'running' : 'indexing',
                'from'        => $from,
                'to'          => $to,
                'total'       => 0,
                'total_bytes' => 0,
                'moved'       => 0,
                'moved_bytes' => 0,
                'failed'      => 0,
                'skipped'     => 0,
                'cursor'      => 0,
                'started_at'  => time(),
                'finished_at' => 0,
                'last_error'  => '',
            );

            if ( 'running' === $state['status'] ) {
                $left                 = $this->remaining( $from );
                $state['total']       = $left['files'];
                $state['total_bytes'] = $left['bytes'];
            }

            $this->save_migration( $state );
            $storage->schedule( 0 );

            return $state;
        }

        public function pause_migration()
        {
            $state = $this->migration_state();

            if ( 'running' === $state['status'] || 'indexing' === $state['status'] ) {
                $state['status'] = 'paused';
                $this->save_migration( $state );
            }
        }

        public function resume_migration()
        {
            $state = $this->migration_state();

            if ( 'paused' === $state['status'] ) {
                $state['status'] = apply_filters( 'wordplus_storage_indexed', true ) ? 'running' : 'indexing';
                $this->save_migration( $state );
                $this->storage->schedule( 0 );
            }
        }

        public function cancel_migration()
        {
            $state = $this->migration_state();

            if ( in_array( $state['status'], array( 'running', 'indexing', 'paused' ), true ) ) {
                $state['status']      = 'cancelled';
                $state['finished_at'] = time();
                $this->save_migration( $state );

                if ( $state['moved'] > 0 ) {
                    $this->announce_all_relocated();
                }
            }
        }

        private function announce_all_relocated()
        {
            $this->relocated = array_fill_keys( array_keys( $this->storage->sdk()->products() ), true );
            $this->announce_relocated();
        }

        private function lock_name()
        {
            global $wpdb;

            return 'wordplus_mig_' . md5( DB_NAME . '|' . $wpdb->prefix . '|' . get_current_blog_id() );
        }

        private function lock()
        {
            global $wpdb;

            $got = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $this->lock_name() ) );

            return null === $got || 1 === (int) $got;
        }

        private function unlock()
        {
            global $wpdb;

            $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $this->lock_name() ) );
        }

        public function run_migration( $budget = 20 )
        {
            global $wpdb;

            if ( ! $this->migration_running() || ! $this->lock() ) {
                return $this->migration_state();
            }

            ignore_user_abort( true );

            $started = microtime( true );

            try {
                $state = $this->migration_state();

                if ( 'indexing' === $state['status'] ) {
                    if ( ! apply_filters( 'wordplus_storage_indexed', true ) ) {
                        return $this->migration_state();
                    }

                    $left = $this->remaining( $state['from'] );

                    $state['status']      = 'running';
                    $state['total']       = $left['files'];
                    $state['total_bytes'] = $left['bytes'];
                    $this->save_migration( $state );
                }

                while ( microtime( true ) - $started < $budget ) {
                    $state = $this->migration_state();

                    if ( 'running' !== $state['status'] ) {
                        break;
                    }

                    $row = '' === $state['from']
                        ? $wpdb->get_row( $wpdb->prepare(
                            "SELECT * FROM {$wpdb->wordplus_storage_objects} WHERE blog_id = %d AND ref IS NOT NULL AND state IN ('local','failed') AND id > %d ORDER BY id ASC LIMIT 1",
                            get_current_blog_id(), (int) $state['cursor']
                        ) )
                        : $wpdb->get_row( $wpdb->prepare(
                            "SELECT * FROM {$wpdb->wordplus_storage_objects} WHERE blog_id = %d AND ref IS NOT NULL AND state = 'stored' AND location = %s AND id > %d ORDER BY id ASC LIMIT 1",
                            get_current_blog_id(), (string) $state['from'], (int) $state['cursor']
                        ) );

                    if ( ! $row ) {
                        $state['status']      = 'done';
                        $state['finished_at'] = time();
                        $this->save_migration( $state );

                        if ( $state['moved'] > 0 ) {
                            $this->announce_all_relocated();
                        }

                        do_action( 'wordplus_storage_migration_done', $state );
                        break;
                    }

                    $result = $this->migrate_row( $row, $state['to'] );

                    $state           = $this->migration_state();
                    $state['cursor'] = (int) $row->id;

                    if ( 'moved' === $result ) {
                        $state['moved']++;
                        $state['moved_bytes'] += (int) $row->size;
                    } elseif ( 'skipped' === $result ) {
                        $state['skipped']++;
                    } else {
                        $state['failed']++;
                        $state['last_error'] = (string) $result;
                    }

                    $this->save_migration( $state );
                }

                $state = $this->migration_state();

                if ( 'running' === $state['status'] || 'paused' === $state['status'] ) {
                    $left                 = $this->remaining( $state['from'], $state['cursor'] );
                    $state['total']       = $state['moved'] + $state['failed'] + $state['skipped'] + $left['files'];
                    $state['total_bytes'] = $state['moved_bytes'] + $left['bytes'];
                    $this->save_migration( $state );
                }
            } finally {
                $this->unlock();
            }

            $this->relocated = array();

            return $this->migration_state();
        }

        public function places( $plugin = '' )
        {
            global $wpdb;

            $storage = $this->storage;
            $plugin  = (string) $plugin;
            $places  = array( array( 'id' => 'local', 'label' => _x( 'This server', 'Storage', 'wordplus-sdk' ) ) );

            foreach ( $storage->get_settings()['locations'] as $id => $location ) {
                $preset   = WordPlus_SDK_Storage::preset( $location['preset'] );
                $places[] = array(
                    'id'    => $id,
                    'label' => $preset['label'] . ': ' . $location['bucket'] . ( '' !== $location['prefix'] ? '/' . $location['prefix'] : '' ),
                );
            }

            $counts = array();

            if ( $storage->table_exists() ) {
                foreach ( $wpdb->get_results( $wpdb->prepare(
                    "SELECT IF(state = 'stored', location, '') AS place, COUNT(*) AS files, COALESCE(SUM(size), 0) AS bytes
                     FROM {$wpdb->wordplus_storage_objects} WHERE blog_id = %d AND ref IS NOT NULL AND state IN ('local','failed','stored','pending_move')
                     AND ( %s = '' OR plugin = %s )
                     GROUP BY place",
                    get_current_blog_id(),
                    $plugin,
                    $plugin
                ) ) as $item ) {
                    $counts[ '' === $item->place ? 'local' : $item->place ] = array( 'files' => (int) $item->files, 'bytes' => (int) $item->bytes );
                }
            }

            foreach ( $places as &$place ) {
                $place['files'] = isset( $counts[ $place['id'] ] ) ? $counts[ $place['id'] ]['files'] : 0;
                $place['bytes'] = isset( $counts[ $place['id'] ] ) ? $counts[ $place['id'] ]['bytes'] : 0;
            }

            return $places;
        }

        public function migration_payload()
        {
            return array(
                'migration' => $this->migration_state(),
                'places'    => $this->places(),
                'indexed'   => (bool) apply_filters( 'wordplus_storage_indexed', true ),
            );
        }

        public function retire_site( $site )
        {
            if ( ! is_multisite() || ! is_object( $site ) || empty( $site->blog_id ) || ! $this->storage->table_exists() ) {
                return;
            }

            $blog_id = (int) $site->blog_id;

            switch_to_blog( $blog_id );
            $this->storage->reset_caches();

            $locations = array();

            foreach ( array_keys( $this->storage->get_settings()['locations'] ) as $id ) {
                $locations[ $id ] = $this->storage->get_location( $id );
            }

            restore_current_blog();
            $this->storage->reset_caches();

            $this->retire_blog_files( $blog_id, $locations );
        }

        public function retire_blog_files( $blog_id, array $locations )
        {
            global $wpdb;

            $now     = time();
            $retired = get_site_option( self::RETIRED_OPTION, array() );
            $retired = is_array( $retired ) ? $retired : array();
            $rows    = $wpdb->get_results( $wpdb->prepare( "SELECT id, location, object_key, state FROM {$wpdb->wordplus_storage_objects} WHERE blog_id = %d", (int) $blog_id ) );

            foreach ( $rows as $row ) {
                $in_storage = '' !== $row->location && '' !== $row->object_key && isset( $locations[ $row->location ] )
                    && in_array( $row->state, WordPlus_SDK_Storage::CLOUD_STATES, true );

                if ( ! $in_storage ) {
                    $wpdb->delete( $this->storage->table(), array( 'id' => (int) $row->id ) );
                    continue;
                }

                $wpdb->update( $this->storage->table(), array(
                    'blog_id'         => 0,
                    'ref'             => null,
                    'state'           => 'deleting',
                    'target'          => null,
                    'attempts'        => 0,
                    'next_attempt_at' => $now,
                    'error'           => null,
                    'updated_at'      => $now,
                ), array( 'id' => (int) $row->id ) );

                $retired[ $row->location ] = array_merge( $locations[ $row->location ], array( 'retired_from' => (int) $blog_id ) );
            }

            update_site_option( self::RETIRED_OPTION, $retired );

            $this->storage->reset_caches();
        }

        public function retired_location( $location_id )
        {
            $retired = get_site_option( self::RETIRED_OPTION, array() );

            if ( ! is_array( $retired ) || ! isset( $retired[ $location_id ] ) || ! is_array( $retired[ $location_id ] ) ) {
                return null;
            }

            $location       = wp_parse_args( $retired[ $location_id ], WordPlus_SDK_Storage::location_defaults() );
            $location['id'] = (string) $location_id;

            return $location;
        }
    }
}
