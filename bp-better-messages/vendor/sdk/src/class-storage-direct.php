<?php
defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WordPlus_SDK_Storage_Direct' ) ) {
    final class WordPlus_SDK_Storage_Direct
    {
        const SINGLE_MAX   = 67108864;
        const PART_BATCH   = 20;
        const PUT_TTL      = 900;
        const PART_TTL     = 3600;
        const ABANDON_TIME = 1800;

        const FALLBACKS_OPTION = 'wordplus_storage_fallbacks';

        private $storage;

        public function __construct( WordPlus_SDK_Storage $storage )
        {
            $this->storage = $storage;
        }

        public function enabled_for( $location )
        {
            if ( ! $location || '0' === $location['direct'] ) {
                return false;
            }

            return (bool) apply_filters( 'wordplus_storage_direct_uploads', true, $location );
        }

        public function ready( array $location )
        {
            if ( 'yes' !== $this->storage->folder_access( $location ) ) {
                return false;
            }

            return 'no' !== $this->state( $location );
        }

        public function conditional_put( array $location )
        {
            return 'yes' === $this->state( $location );
        }

        public function state( array $location, $probe = true )
        {
            $cache_key = 'wordplus_storage_direct_' . $location['id'];
            $cached    = get_transient( $cache_key );

            if ( in_array( $cached, array( 'yes', 'plain', 'no' ), true ) ) {
                return $cached;
            }

            if ( ! $probe ) {
                return 'unknown';
            }

            $admin   = $this->storage->admin();
            $origins = $admin->origins();
            $folder  = $admin->test_key( $location, '' );
            $client  = $this->storage->client( $location );
            $ready   = ! empty( $origins ) && $admin->preflight( $client, $folder . 'cors-check.txt', $origins[0] );
            $state   = 'no';

            if ( $ready ) {
                $state = $this->accepts_conditional_put( $client, $folder . 'conditional-' . wp_generate_uuid4() . '.txt' ) ? 'yes' : 'plain';
            }

            set_transient( $cache_key, $state, 'no' !== $state ? HOUR_IN_SECONDS : 10 * MINUTE_IN_SECONDS );

            return $state;
        }

        private function accepts_conditional_put( WordPlus_SDK_Storage_S3 $client, $key )
        {
            $put = $client->put_string( $key, '1', array( 'content-type' => 'text/plain', 'if-none-match' => '*' ) );

            if ( ! empty( $put['ok'] ) ) {
                $client->delete( $key );

                return true;
            }

            return 501 !== (int) $put['code'] && ( isset( $put['error_code'] ) ? $put['error_code'] : '' ) !== 'NotImplemented';
        }

        public function open_uploads( $plugin, $user_id )
        {
            global $wpdb;

            if ( ! $this->storage->table_exists() ) {
                return 0;
            }

            return (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->wordplus_storage_objects} WHERE blog_id = %d AND plugin = %s AND user_id = %d AND state = 'uploading'",
                get_current_blog_id(), (string) $plugin, (int) $user_id
            ) );
        }

        public function begin( $plugin, array $location, array $args )
        {
            global $wpdb;

            $storage = $this->storage;

            if ( ! $this->enabled_for( $location ) || ! $this->ready( $location ) || ! $storage->install() ) {
                return array( 'method' => 'server' );
            }

            $size = isset( $args['size'] ) ? (int) $args['size'] : 0;
            $name = isset( $args['name'] ) ? (string) $args['name'] : '';
            $type = WordPlus_SDK_Storage::stored_type( isset( $args['type'] ) ? (string) $args['type'] : '' );
            $path = isset( $args['path'] ) ? ltrim( (string) $args['path'], '/' ) : '';
            $now  = time();

            $wpdb->insert( $storage->table(), array(
                'blog_id'         => get_current_blog_id(),
                'plugin'          => (string) $plugin,
                'ref'             => null,
                'path'            => $path,
                'item_key'        => isset( $args['key'] ) && '' !== (string) $args['key'] ? ltrim( (string) $args['key'], '/' ) : $path,
                'name'            => mb_substr( $name, 0, 255 ),
                'mime'            => mb_substr( $type, 0, 100 ),
                'size'            => $size,
                'user_id'         => isset( $args['user'] ) ? (int) $args['user'] : 0,
                'location'        => $location['id'],
                'state'           => 'uploading',
                'attempts'        => 0,
                'next_attempt_at' => $now + self::ABANDON_TIME,
                'created_at'      => $now,
                'updated_at'      => $now,
            ) );

            $row = $storage->object( (int) $wpdb->insert_id );

            if ( ! $row ) {
                return array( 'method' => 'server' );
            }

            $key    = $storage->object_key( $location, $row );
            $preset = WordPlus_SDK_Storage::preset( $location['preset'] );
            $client = $storage->client( $location );

            $headers = array( 'content-type' => $type );

            if ( $preset['disposition'] ) {
                $headers['content-disposition'] = WordPlus_SDK_Storage::disposition( WordPlus_SDK_Storage::inline_mime( $type ) ? 'inline' : 'attachment', $name );
            }

            if ( 'public' === $location['delivery'] && '1' === $location['public_acl'] ) {
                $headers['x-amz-acl'] = 'public-read';
            }

            $multipart = $size > self::SINGLE_MAX;
            $upload_id = '';

            if ( $multipart ) {
                $upload_id = $client->create_multipart( $key, $headers );

                if ( '' === $upload_id ) {
                    $storage->delete_row( $row->id );

                    return array( 'method' => 'server' );
                }
            }

            $storage->update_row( $row->id, array( 'object_key' => $key, 'upload_id' => $upload_id ) );
            $storage->schedule( self::ABANDON_TIME + 60 );

            if ( ! $multipart ) {
                $signed                   = $headers;
                $signed['content-length'] = $size;
                $send                     = $headers;

                if ( $this->conditional_put( $location ) ) {
                    $signed['if-none-match'] = '*';
                    $send['if-none-match']   = '*';
                }

                return array(
                    'method'    => 'put',
                    'upload_id' => (int) $row->id,
                    'url'       => $client->presign( 'PUT', $key, self::PUT_TTL, array(), $signed ),
                    'headers'   => $send,
                    'expires'   => $now + self::PUT_TTL,
                );
            }

            $part_size = WordPlus_SDK_Storage_S3::part_size( $size, $preset['max_parts'] );

            return array(
                'method'      => 'multipart',
                'upload_id'   => (int) $row->id,
                'part_size'   => $part_size,
                'total_parts' => (int) ceil( $size / $part_size ),
                'parts'       => $this->part_urls( $client, $key, $upload_id, $size, $part_size, 1 ),
            );
        }

        private function part_urls( WordPlus_SDK_Storage_S3 $client, $key, $upload_id, $size, $part_size, $from )
        {
            $total = (int) ceil( $size / $part_size );
            $urls  = array();

            for ( $number = max( 1, (int) $from ); $number <= $total && count( $urls ) < self::PART_BATCH; $number++ ) {
                $length = $number < $total ? $part_size : $size - $part_size * ( $total - 1 );

                $urls[] = array(
                    'number' => $number,
                    'size'   => $length,
                    'url'    => $client->presign( 'PUT', $key, self::PART_TTL, array(
                        'partNumber' => (string) $number,
                        'uploadId'   => $upload_id,
                    ), array( 'content-length' => $length ) ),
                );
            }

            return $urls;
        }

        public function own( $plugin, $upload_id, $user_id )
        {
            $row = $this->storage->object( (int) $upload_id );

            if ( ! $row || 'uploading' !== $row->state || (string) $row->plugin !== (string) $plugin ) {
                return new WP_Error( 'rest_not_found', _x( 'This upload is no longer available. Please try again.', 'Storage', 'wordplus-sdk' ), array( 'status' => 404 ) );
            }

            if ( (int) $row->user_id !== (int) $user_id ) {
                return new WP_Error( 'rest_forbidden', _x( 'Sorry, you are not allowed to upload files.', 'Storage', 'wordplus-sdk' ), array( 'status' => rest_authorization_required_code() ) );
            }

            return $row;
        }

        public function keep_alive( $row )
        {
            if ( ! $row || 'uploading' !== $row->state ) {
                return;
            }

            $deadline = time() + self::ABANDON_TIME;

            if ( (int) $row->next_attempt_at < $deadline ) {
                $this->storage->update_row( $row->id, array( 'next_attempt_at' => $deadline ) );
                $row->next_attempt_at = $deadline;
            }
        }

        public function parts( $row, $from )
        {
            $location = $this->storage->get_location( (string) $row->location );

            if ( ! $location ) {
                return new WP_Error( 'rest_not_found', _x( 'This upload is no longer available. Please try again.', 'Storage', 'wordplus-sdk' ), array( 'status' => 404 ) );
            }

            $this->keep_alive( $row );

            if ( '' === (string) $row->upload_id ) {
                return array();
            }

            $part_size = WordPlus_SDK_Storage_S3::part_size( (int) $row->size, WordPlus_SDK_Storage::preset( $location['preset'] )['max_parts'] );

            return $this->part_urls( $this->storage->client( $location ), (string) $row->object_key, (string) $row->upload_id, (int) $row->size, $part_size, (int) $from );
        }

        public function verify( $row )
        {
            $storage  = $this->storage;
            $location = $storage->get_location( (string) $row->location );
            $failed   = new WP_Error( 'rest_upload_failed', _x( 'The file did not arrive complete. Please try again.', 'Storage', 'wordplus-sdk' ), array( 'status' => 400 ) );

            if ( ! $location ) {
                $storage->delete_row( $row->id );

                return new WP_Error( 'rest_upload_failed', _x( 'This upload is no longer available. Please try again.', 'Storage', 'wordplus-sdk' ), array( 'status' => 404 ) );
            }

            $client = $storage->client( $location );

            if ( '' !== (string) $row->upload_id ) {
                $part_size = WordPlus_SDK_Storage_S3::part_size( (int) $row->size, WordPlus_SDK_Storage::preset( $location['preset'] )['max_parts'] );
                $total     = (int) ceil( (int) $row->size / $part_size );
                $parts     = $client->list_parts( (string) $row->object_key, (string) $row->upload_id );

                if ( ! is_array( $parts ) || count( $parts ) !== $total ) {
                    $this->discard( $row );

                    return $failed;
                }

                $etags = array();
                $sum   = 0;

                foreach ( $parts as $number => $part ) {
                    $etags[ $number ] = $part['etag'];
                    $sum             += (int) $part['size'];
                }

                if ( $sum !== (int) $row->size ) {
                    $this->discard( $row );

                    return $failed;
                }

                $done = $client->complete_multipart( (string) $row->object_key, (string) $row->upload_id, $etags );

                if ( empty( $done['ok'] ) ) {
                    $this->discard( $row );

                    return $failed;
                }

                $storage->update_row( $row->id, array( 'upload_id' => '' ) );
                $row->upload_id = '';
            }

            $head = $client->head( (string) $row->object_key );

            if ( empty( $head['ok'] ) || (int) $head['size'] !== (int) $row->size ) {
                $this->discard( $row );

                return $failed;
            }

            $stored = strtolower( trim( explode( ';', (string) $head['type'] )[0] ) );

            if ( $stored !== strtolower( (string) $row->mime ) ) {
                $this->discard( $row );

                return $failed;
            }

            return true;
        }

        public function complete( $row, $ref, $variant = '' )
        {
            $this->storage->update_row( $row->id, array(
                'ref'             => (int) $ref,
                'variant'         => substr( (string) $variant, 0, 32 ),
                'state'           => 'stored',
                'upload_id'       => '',
                'next_attempt_at' => 0,
            ) );
        }

        public function discard( $row, $reason = '' )
        {
            $storage  = $this->storage;
            $location = $storage->get_location( (string) $row->location );
            $access   = $location ? $storage->folder_access( $location ) : 'never';

            if ( 'wait' === $access ) {
                $storage->update_row( $row->id, array( 'next_attempt_at' => time() + HOUR_IN_SECONDS ) );

                return;
            }

            if ( 'yes' === $access ) {
                if ( '' !== (string) $row->upload_id ) {
                    $storage->client( $location )->abort_multipart( (string) $row->object_key, (string) $row->upload_id );
                }

                $storage->queue()->schedule_source_deletion( $location['id'], (string) $row->object_key, 0 );
            }

            $storage->delete_row( $row->id );

            if ( 'fallback' === $reason ) {
                $this->count_fallback();
            }
        }

        private function count_fallback()
        {
            $counts = get_option( self::FALLBACKS_OPTION, array() );
            $counts = is_array( $counts ) ? $counts : array();
            $today  = gmdate( 'Y-m-d' );

            $counts[ $today ] = ( isset( $counts[ $today ] ) ? (int) $counts[ $today ] : 0 ) + 1;
            $counts           = array_slice( $counts, -7, 7, true );

            update_option( self::FALLBACKS_OPTION, $counts, false );
        }

        public function fallbacks_today()
        {
            $counts = get_option( self::FALLBACKS_OPTION, array() );

            return is_array( $counts ) && isset( $counts[ gmdate( 'Y-m-d' ) ] ) ? (int) $counts[ gmdate( 'Y-m-d' ) ] : 0;
        }

        public function sweep_abandoned( $limit = 20 )
        {
            global $wpdb;

            if ( ! $this->storage->table_exists() ) {
                return 0;
            }

            $rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT * FROM {$wpdb->wordplus_storage_objects} WHERE blog_id = %d AND state = 'uploading' AND next_attempt_at <= %d ORDER BY id ASC LIMIT %d",
                get_current_blog_id(), time(), (int) $limit
            ) );

            foreach ( $rows as $row ) {
                $this->discard( $row );
            }

            return count( $rows );
        }
    }
}
