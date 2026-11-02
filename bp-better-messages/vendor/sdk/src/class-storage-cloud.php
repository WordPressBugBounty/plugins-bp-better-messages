<?php
defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WordPlus_SDK_Storage_Cloud' ) ) {
    final class WordPlus_SDK_Storage_Cloud
    {
        const REST_NAMESPACE = 'wordplus/v1';
        const ROUTE          = '/storage/cloud';

        const OPTION_PREFIX = 'wordplus_storage_cloud_';

        const TTL        = 172800;
        const TTL_MAX    = 604800;
        const PART_TTL   = 3600;
        const PART_BATCH = 100;

        const FINISH_BATCH = 100;

        const FILE = '/^[a-z0-9][a-z0-9._-]{0,31}$/';

        private $storage;

        public function __construct( WordPlus_SDK_Storage $storage )
        {
            $this->storage = $storage;
        }

        public function issue( $plugin, array $args )
        {
            $storage = $this->storage;
            $ref     = isset( $args['ref'] ) ? (int) $args['ref'] : 0;
            $path    = isset( $args['path'] ) ? trim( (string) $args['path'], '/' ) : '';

            if ( $ref <= 0 || '' === $path || false !== strpos( $path, '..' ) || ! $storage->install() ) {
                return null;
            }

            $location = ! empty( $args['location'] ) ? $storage->get_location( (string) $args['location'] ) : $storage->route( isset( $args['type'] ) ? (string) $args['type'] : '' );

            if ( ! $location || '1' === $location['readonly'] || 'yes' !== $storage->folder_access( $location ) ) {
                return null;
            }

            $ttl     = isset( $args['ttl'] ) ? min( self::TTL_MAX, max( 60, (int) $args['ttl'] ) ) : self::TTL;
            $id      = strtolower( wp_generate_password( 16, false, false ) );
            $secret  = wp_generate_password( 40, false, false );
            $expires = time() + $ttl;

            update_option( self::OPTION_PREFIX . $id, array(
                'plugin'   => (string) $plugin,
                'ref'      => $ref,
                'path'     => $path,
                'location' => (string) $location['id'],
                'hash'     => hash( 'sha256', $secret ),
                'expires'  => $expires,
            ), false );

            return array(
                'url'      => rest_url( self::REST_NAMESPACE . self::ROUTE ),
                'token'    => $id . '.' . $secret,
                'location' => (string) $location['id'],
                'expires'  => $expires,
            );
        }

        public function routes()
        {
            register_rest_route( self::REST_NAMESPACE, self::ROUTE, array(
                'methods'             => 'POST',
                'callback'            => array( $this, 'rest' ),
                'permission_callback' => array( $this, 'can_call' ),
            ) );
        }

        public function can_call( WP_REST_Request $request )
        {
            return $this->grant_for( $request ) ? true : new WP_Error( 'rest_forbidden', 'Not a grant of this site\'s, or one that lapsed', array( 'status' => 403 ) );
        }

        private function grant_for( WP_REST_Request $request )
        {
            $token = (string) $request->get_header( 'x_wordplus_grant' );

            if ( '' === $token ) {
                $auth  = (string) $request->get_header( 'authorization' );
                $token = 0 === stripos( $auth, 'Bearer ' ) ? substr( $auth, 7 ) : '';
            }

            return '' !== $token ? $this->grant_of( $token ) : null;
        }

        public function grant_of( $token )
        {
            $parts = explode( '.', trim( (string) $token ), 2 );

            if ( 2 !== count( $parts ) || ! preg_match( '/^[a-z0-9]{16}$/', $parts[0] ) ) {
                return null;
            }

            $grant = get_option( self::OPTION_PREFIX . $parts[0] );

            if ( ! is_array( $grant ) || empty( $grant['hash'] ) || ! hash_equals( (string) $grant['hash'], hash( 'sha256', $parts[1] ) ) ) {
                return null;
            }

            if ( (int) $grant['expires'] < time() ) {
                delete_option( self::OPTION_PREFIX . $parts[0] );

                return null;
            }

            $grant['id'] = $parts[0];

            return $grant;
        }

        public function rest( WP_REST_Request $request )
        {
            $grant = $this->grant_for( $request );

            if ( ! $grant ) {
                return new WP_Error( 'rest_forbidden', 'Not a grant of this site\'s, or one that lapsed', array( 'status' => 403 ) );
            }

            $location = $this->storage->get_location( $grant['location'] );

            if ( ! $location ) {
                return new WP_Error( 'wordplus_storage_gone', 'The grant\'s storage is gone', array( 'status' => 410 ) );
            }

            $body = $request->get_json_params();
            $body = is_array( $body ) ? $body : array();
            $op   = isset( $body['op'] ) ? (string) $body['op'] : '';

            switch ( $op ) {
                case 'begin':
                case 'put':
                    $file = isset( $body['file'] ) ? (string) $body['file'] : '';

                    if ( ! preg_match( self::FILE, $file ) ) {
                        return new WP_Error( 'rest_invalid_param', 'A file\'s name is 1 to 32 of a-z, 0-9, ".", "_" and "-"', array( 'status' => 400 ) );
                    }

                    return 'begin' === $op
                        ? $this->begin( $grant, $location, $file, isset( $body['type'] ) ? (string) $body['type'] : '' )
                        : $this->put( $grant, $location, $file, isset( $body['type'] ) ? (string) $body['type'] : '' );

                case 'parts':
                case 'complete':
                case 'abort':
                    $row = $this->upload_of( $grant, isset( $body['upload'] ) ? $body['upload'] : 0 );

                    if ( is_wp_error( $row ) ) {
                        return $row;
                    }

                    if ( 'parts' === $op ) {
                        return $this->parts( $location, $row, isset( $body['parts'] ) && is_array( $body['parts'] ) ? $body['parts'] : array() );
                    }

                    return 'complete' === $op ? $this->complete( $location, $row ) : $this->abort( $location, $row );

                case 'finish':
                    return $this->finish( $grant, $location, isset( $body['files'] ) && is_array( $body['files'] ) ? $body['files'] : array() );
            }

            return new WP_Error( 'rest_invalid_param', 'Unknown op', array( 'status' => 400 ) );
        }

        private function row_for( array $grant, array $location, $file, $type )
        {
            global $wpdb;

            $storage = $this->storage;
            $old     = $storage->get( $grant['plugin'], (int) $grant['ref'], $file, true );

            if ( $old && 'uploading' !== $old->state ) {
                return new WP_Error( 'wordplus_storage_stored', 'That file is stored already', array( 'status' => 409 ) );
            }

            if ( $old ) {
                if ( '' !== (string) $old->upload_id ) {
                    $storage->client( $location )->abort_multipart( (string) $old->object_key, (string) $old->upload_id );
                }

                $storage->delete_row( $old->id );
            }

            $now = time();
            $wpdb->insert( $storage->table(), array(
                'blog_id'         => get_current_blog_id(),
                'plugin'          => $grant['plugin'],
                'ref'             => (int) $grant['ref'],
                'variant'         => $file,
                'path'            => $grant['path'] . '/' . $file,
                'item_key'        => $grant['path'] . '/' . $file,
                'name'            => $file,
                'mime'            => WordPlus_SDK_Storage::stored_type( $type ),
                'size'            => 0,
                'user_id'         => 0,
                'location'        => $location['id'],
                'state'           => 'uploading',
                'attempts'        => 0,
                'next_attempt_at' => (int) $grant['expires'],
                'created_at'      => $now,
                'updated_at'      => $now,
            ) );

            $row = $storage->object( (int) $wpdb->insert_id );

            if ( ! $row ) {
                return new WP_Error( 'wordplus_storage_table', 'The file\'s row could not be made', array( 'status' => 500 ) );
            }

            $key = $storage->object_key( $location, $row );
            $storage->update_row( $row->id, array( 'object_key' => $key ) );
            $row->object_key = $key;

            return $row;
        }

        private function begin( array $grant, array $location, $file, $type )
        {
            $row = $this->row_for( $grant, $location, $file, $type );

            if ( is_wp_error( $row ) ) {
                return $row;
            }

            $upload_id = $this->storage->client( $location )->create_multipart( (string) $row->object_key, $this->storage->upload_headers( $location, $row ) );

            if ( '' === $upload_id ) {
                $this->storage->delete_row( $row->id );

                return new WP_Error( 'wordplus_storage_refused', 'The storage refused the upload', array( 'status' => 502 ) );
            }

            $this->storage->update_row( $row->id, array( 'upload_id' => $upload_id ) );

            return array( 'ok' => true, 'upload' => (int) $row->id, 'key' => (string) $row->object_key );
        }

        private function put( array $grant, array $location, $file, $type )
        {
            $row = $this->row_for( $grant, $location, $file, $type );

            if ( is_wp_error( $row ) ) {
                return $row;
            }

            $headers = $this->storage->upload_headers( $location, $row );

            return array(
                'ok'      => true,
                'upload'  => (int) $row->id,
                'url'     => $this->storage->client( $location )->presign( 'PUT', (string) $row->object_key, self::PART_TTL, array(), $headers ),
                'headers' => $headers,
            );
        }

        private function upload_of( array $grant, $id )
        {
            $row = $this->storage->object( (int) $id );

            if ( ! $row || (string) $row->plugin !== $grant['plugin'] || (int) $row->ref !== (int) $grant['ref'] || 'uploading' !== $row->state ) {
                return new WP_Error( 'rest_invalid_param', 'Not an upload of this grant\'s', array( 'status' => 404 ) );
            }

            return $row;
        }

        private function parts( array $location, $row, array $numbers )
        {
            if ( '' === (string) $row->upload_id ) {
                return new WP_Error( 'rest_invalid_param', 'A file sent in one PUT has no parts', array( 'status' => 400 ) );
            }

            $client = $this->storage->client( $location );
            $urls   = array();

            foreach ( array_slice( $numbers, 0, self::PART_BATCH ) as $number ) {
                $number = (int) $number;

                if ( $number >= 1 && $number <= 10000 ) {
                    $urls[ $number ] = $client->presign( 'PUT', (string) $row->object_key, self::PART_TTL, array(
                        'partNumber' => (string) $number,
                        'uploadId'   => (string) $row->upload_id,
                    ) );
                }
            }

            return array( 'ok' => true, 'urls' => (object) $urls );
        }

        private function complete( array $location, $row )
        {
            if ( '' === (string) $row->upload_id ) {
                return array( 'ok' => true );
            }

            $client = $this->storage->client( $location );
            $parts  = $client->list_parts( (string) $row->object_key, (string) $row->upload_id );

            if ( ! is_array( $parts ) || ! $parts ) {
                return new WP_Error( 'wordplus_storage_refused', 'The storage lists no parts for the upload', array( 'status' => 502 ) );
            }

            $etags = array();

            foreach ( $parts as $number => $part ) {
                $etags[ $number ] = $part['etag'];
            }

            $done = $client->complete_multipart( (string) $row->object_key, (string) $row->upload_id, $etags );

            if ( empty( $done['ok'] ) ) {
                return new WP_Error( 'wordplus_storage_refused', 'The storage refused to complete the upload', array( 'status' => 502 ) );
            }

            $this->storage->update_row( $row->id, array( 'upload_id' => '' ) );

            return array( 'ok' => true );
        }

        private function abort( array $location, $row )
        {
            if ( '' !== (string) $row->upload_id ) {
                $this->storage->client( $location )->abort_multipart( (string) $row->object_key, (string) $row->upload_id );
            }

            $this->storage->delete_row( $row->id );

            return array( 'ok' => true );
        }

        private function finish( array $grant, array $location, array $files )
        {
            $storage = $this->storage;
            $client  = $storage->client( $location );
            $stored  = array();
            $missing = array();
            $rest    = array();

            foreach ( array_slice( $files, self::FINISH_BATCH ) as $file ) {
                if ( is_array( $file ) && isset( $file['name'] ) ) {
                    $rest[] = array( 'name' => (string) $file['name'], 'size' => isset( $file['size'] ) ? (int) $file['size'] : 0 );
                }
            }

            foreach ( array_slice( $files, 0, self::FINISH_BATCH ) as $file ) {
                $name = is_array( $file ) && isset( $file['name'] ) ? (string) $file['name'] : '';
                $row  = preg_match( self::FILE, $name ) ? $storage->get( $grant['plugin'], (int) $grant['ref'], $name, true ) : null;

                if ( ! $row || '' !== (string) $row->upload_id ) {
                    $missing[] = $name;
                    continue;
                }

                if ( 'stored' !== $row->state ) {
                    $head = $client->head( (string) $row->object_key );

                    if ( empty( $head['ok'] ) || ( isset( $file['size'] ) && (int) $file['size'] > 0 && (int) $head['size'] !== (int) $file['size'] ) ) {
                        $missing[] = $name;
                        continue;
                    }

                    $storage->update_row( $row->id, array( 'state' => 'stored', 'size' => (int) $head['size'], 'next_attempt_at' => 0, 'updated_at' => time() ) );
                    $row->size = (int) $head['size'];
                }

                $stored[] = array( 'name' => $name, 'id' => (int) $row->id, 'size' => (int) $row->size, 'key' => (string) $row->object_key );
            }

            $all = array();

            foreach ( array_merge( isset( $grant['stored'] ) && is_array( $grant['stored'] ) ? $grant['stored'] : array(), $stored ) as $file ) {
                $all[ $file['name'] ] = $file;
            }

            if ( $rest ) {
                $kept = get_option( self::OPTION_PREFIX . $grant['id'] );

                if ( is_array( $kept ) ) {
                    $kept['stored'] = array_values( $all );
                    update_option( self::OPTION_PREFIX . $grant['id'], $kept, false );
                }

                return array( 'ok' => true, 'files' => $stored, 'missing' => $missing, 'rest' => $rest );
            }

            delete_option( self::OPTION_PREFIX . $grant['id'] );

            do_action( 'wordplus_storage_cloud_finished', $grant['plugin'], (int) $grant['ref'], array_values( $all ), array( 'path' => $grant['path'], 'location' => $grant['location'], 'expires' => (int) $grant['expires'] ) );

            return array( 'ok' => true, 'files' => $stored, 'missing' => $missing );
        }
    }
}
