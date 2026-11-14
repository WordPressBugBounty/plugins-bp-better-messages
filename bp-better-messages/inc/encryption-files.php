<?php
defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Better_Messages_Encryption_Files' ) ) :

    class Better_Messages_Encryption_Files
    {
        const KEY_META = 'bm-encrypted-key';

        const SIZE_META = 'bm-encrypted-size';

        private $keys = array();

        private $changed = false;

        public static function instance()
        {
            static $instance = null;

            if ( null === $instance ) {
                $instance = new Better_Messages_Encryption_Files();
            }

            return $instance;
        }

        public function __construct()
        {
            add_action( 'better_messages_attachment_created', array( $this, 'encrypt_attachment' ), 5, 2 );
            add_filter( 'better_messages_attachment_url', array( $this, 'attachment_url' ), 25, 4 );
            add_filter( 'better_messages_rest_message_meta', array( $this, 'message_meta' ), 40, 4 );
            add_action( 'wordplus_encryption_job_finished', array( $this, 'job_finished' ), 10, 3 );

            add_filter( 'get_post_metadata', array( $this, 'read_name' ), 10, 4 );
            add_filter( 'update_post_metadata', array( $this, 'update_name' ), 10, 5 );
            add_filter( 'add_post_metadata', array( $this, 'add_name' ), 10, 5 );
            add_filter( 'wp_unique_filename', array( $this, 'random_disk_name' ), 10, 2 );
            add_filter( 'wp_insert_attachment_data', array( $this, 'hide_attachment_title' ), 10, 4 );
            add_filter( 'wp_generate_attachment_metadata', array( $this, 'drop_image_meta' ), 10, 2 );
        }

        const NAME_META = 'bp-better-messages-original-name';

        private $writing = false;

        private $bulk_upload = false;

        public function bulk_upload( $active )
        {
            $this->bulk_upload = (bool) $active;
        }

        private function naming()
        {
            if ( ! Better_Messages()->encryption->on() ) {
                return false;
            }

            return $this->bulk_upload || ( function_exists( 'Better_Messages_Files' ) && false !== has_filter( 'upload_dir', array( Better_Messages_Files(), 'upload_dir' ) ) );
        }

        public function random_disk_name( $filename, $ext = '' )
        {
            if ( ! $this->naming() ) {
                return $filename;
            }

            return strtolower( wp_generate_password( 24, false, false ) ) . strtolower( (string) $ext );
        }

        public function hide_attachment_title( $data, $postarr = array(), $unsanitized = array(), $update = false )
        {
            if ( $update || ! is_array( $data ) || ! $this->naming() ) {
                return $data;
            }

            $base = pathinfo( wp_basename( (string) ( isset( $data['guid'] ) ? $data['guid'] : '' ) ), PATHINFO_FILENAME );

            if ( '' === $base ) {
                $base = strtolower( wp_generate_password( 24, false, false ) );
            }

            $data['post_title']   = $base;
            $data['post_name']    = sanitize_title( $base );
            $data['post_content'] = '';
            $data['post_excerpt'] = '';

            return $data;
        }

        public function drop_image_meta( $metadata, $attachment_id = 0 )
        {
            if ( is_array( $metadata ) && isset( $metadata['image_meta'] ) && $this->naming() ) {
                $metadata['image_meta'] = array();
            }

            return $metadata;
        }

        private static function name_context( $attachment_id )
        {
            return 'attachment:' . (int) $attachment_id . ':name';
        }

        public function seal_name( $attachment_id, $name )
        {
            if ( ! is_string( $name ) || '' === $name || Better_Messages_Encryption::is_sealed( $name ) || ! Better_Messages()->encryption->on() ) {
                return $name;
            }

            $sealed = $this->engine()->seal( Better_Messages_Encryption::OWNER, $name, self::name_context( $attachment_id ) );

            return null === $sealed ? $name : $sealed;
        }

        public function open_name( $attachment_id, $name )
        {
            if ( ! Better_Messages_Encryption::is_sealed( $name ) ) {
                return $name;
            }

            $engine = $this->engine();
            $plain  = $engine ? $engine->open( Better_Messages_Encryption::OWNER, $name, self::name_context( $attachment_id ) ) : null;

            return null === $plain ? '' : $plain;
        }

        public function read_name( $check, $object_id, $meta_key, $single )
        {
            if ( null !== $check || ( '' !== $meta_key && self::NAME_META !== $meta_key ) ) {
                return $check;
            }

            $object_id = absint( $object_id );

            if ( ! $object_id ) {
                return $check;
            }

            $cache = wp_cache_get( $object_id, 'post_meta' );

            if ( false === $cache ) {
                $caches = update_meta_cache( 'post', array( $object_id ) );
                $cache  = isset( $caches[ $object_id ] ) ? $caches[ $object_id ] : array();
            }

            if ( ! is_array( $cache ) || empty( $cache[ self::NAME_META ] ) ) {
                return $check;
            }

            $sealed = false;

            foreach ( (array) $cache[ self::NAME_META ] as $index => $raw ) {
                if ( Better_Messages_Encryption::is_sealed( $raw ) ) {
                    $cache[ self::NAME_META ][ $index ] = $this->open_name( $object_id, $raw );
                    $sealed = true;
                }
            }

            if ( ! $sealed ) {
                return $check;
            }

            if ( '' === $meta_key ) {
                return $cache;
            }

            $values = array_map( 'maybe_unserialize', (array) $cache[ self::NAME_META ] );

            return $single ? array( $values[0] ) : $values;
        }

        public function update_name( $check, $object_id, $meta_key, $meta_value, $prev_value )
        {
            if ( null !== $check || $this->writing || self::NAME_META !== $meta_key || ( '' !== $prev_value && null !== $prev_value ) ) {
                return $check;
            }

            $sealed = $this->seal_name( $object_id, $meta_value );

            if ( $sealed === $meta_value ) {
                return $check;
            }

            $this->writing = true;
            $result        = update_metadata( 'post', $object_id, $meta_key, $sealed );
            $this->writing = false;

            return $result;
        }

        public function add_name( $check, $object_id, $meta_key, $meta_value, $unique )
        {
            if ( null !== $check || $this->writing || self::NAME_META !== $meta_key ) {
                return $check;
            }

            $sealed = $this->seal_name( $object_id, $meta_value );

            if ( $sealed === $meta_value ) {
                return $check;
            }

            $this->writing = true;
            $result        = add_metadata( 'post', $object_id, $meta_key, $sealed, $unique );
            $this->writing = false;

            return $result;
        }

        public function seal_file_name( $attachment_id, $name )
        {
            if ( ! is_string( $name ) || '' === $name || Better_Messages_Encryption::is_sealed( $name ) || ! Better_Messages()->encryption->on() || ! Better_Messages_Encryption::widened() ) {
                return $name;
            }

            $sealed = $this->engine()->seal( Better_Messages_Encryption::OWNER, $name, 'file:' . (int) $attachment_id . ':name' );

            if ( null !== $sealed && Better_Messages()->encryption->index() ) {
                Better_Messages()->encryption->index()->put( Better_Messages_Encryption::OWNER, 'file', (int) $attachment_id, (int) get_post_meta( (int) $attachment_id, 'bp-better-messages-thread-id', true ), $name );
            }

            return null === $sealed ? $name : $sealed;
        }

        public function open_file_name( $attachment_id, $name )
        {
            if ( ! Better_Messages_Encryption::is_sealed( $name ) ) {
                return $name;
            }

            $engine = $this->engine();
            $plain  = $engine ? $engine->open( Better_Messages_Encryption::OWNER, $name, 'file:' . (int) $attachment_id . ':name' ) : null;

            return null === $plain ? '' : $plain;
        }

        public static function has_random_name( $path )
        {
            return 1 === preg_match( '/^[a-z0-9]{24}(\.[a-z0-9]+)?$/', wp_basename( (string) $path ) );
        }

        private static function random_base( $name )
        {
            $ext = strtolower( (string) pathinfo( (string) $name, PATHINFO_EXTENSION ) );

            return strtolower( wp_generate_password( 24, false, false ) ) . ( '' === $ext ? '' : '.' . $ext );
        }

        private function moved_name( $attachment_id, $relative, array $copies = array() )
        {
            global $wpdb;

            $before = (string) get_post_meta( $attachment_id, '_wp_attached_file', true );

            if ( '' === (string) get_post_meta( $attachment_id, self::NAME_META, true ) && '' !== $before ) {
                add_post_meta( $attachment_id, self::NAME_META, wp_basename( $before ), true );
            }

            update_post_meta( $attachment_id, '_wp_attached_file', $relative );

            $metadata = wp_get_attachment_metadata( $attachment_id, true );

            if ( is_array( $metadata ) ) {
                if ( isset( $metadata['file'] ) ) {
                    $metadata['file'] = $relative;
                }

                foreach ( $copies as $key => $base ) {
                    $metadata[ $key ] = $base;
                }

                if ( isset( $metadata['image_meta'] ) ) {
                    $metadata['image_meta'] = array();
                }

                update_post_meta( $attachment_id, '_wp_attachment_metadata', $metadata );
            }

            $uploads = wp_get_upload_dir();
            $base    = pathinfo( $relative, PATHINFO_FILENAME );

            $wpdb->update( $wpdb->posts, array(
                'guid'         => trailingslashit( $uploads['baseurl'] ) . ltrim( $relative, '/' ),
                'post_title'   => $base,
                'post_name'    => sanitize_title( $base ),
                'post_content' => '',
                'post_excerpt' => '',
            ), array( 'ID' => $attachment_id ) );

            clean_post_cache( $attachment_id );
        }

        private function rename_local( $attachment_id )
        {
            $path = get_attached_file( $attachment_id );

            if ( ! $path || ! file_exists( $path ) || self::has_random_name( $path ) ) {
                return true;
            }

            $dir    = trailingslashit( dirname( $path ) );
            $target = $dir . self::random_base( $path );

            if ( ! @rename( $path, $target ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
                return false;
            }

            $copies = array();

            foreach ( $this->extra_copies( $attachment_id, $path ) as $copy ) {
                $renamed = $dir . self::random_base( $copy );

                if ( @rename( $copy, $renamed ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
                    $copies['original_image'] = wp_basename( $renamed );
                }
            }

            $this->moved_name( $attachment_id, _wp_relative_upload_path( $target ), $copies );

            $object = $this->cloud_object( $attachment_id );

            if ( $object ) {
                Better_Messages()->storage->sync_local_path( $object );
            }

            return true;
        }

        private function seal_names( $attachment_id )
        {
            global $wpdb;

            $raw = $wpdb->get_var( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s ORDER BY meta_id ASC LIMIT 1", $attachment_id, self::NAME_META ) );

            if ( null !== $raw && '' !== $raw && ( ! Better_Messages_Encryption::is_sealed( $raw ) || $this->old_key( $raw ) ) ) {
                $name = $this->open_name( $attachment_id, $raw );

                if ( '' !== $name ) {
                    $this->store_name( $attachment_id, $this->seal_name( $attachment_id, $name ) );
                }
            }

            $storage = Better_Messages()->storage;

            if ( $storage && $storage->table_exists() ) {
                $stored = $wpdb->get_var( $wpdb->prepare( "SELECT `name` FROM `{$storage->table()}` WHERE `blog_id` = %d AND `attachment_id` = %d", get_current_blog_id(), $attachment_id ) );

                if ( null !== $stored && '' !== $stored && ( ! Better_Messages_Encryption::is_sealed( $stored ) || $this->old_key( $stored ) ) ) {
                    $plain = $this->open_file_name( $attachment_id, $stored );

                    if ( '' !== $plain ) {
                        $wpdb->update( $storage->table(), array( 'name' => $this->seal_file_name( $attachment_id, $plain ) ), array( 'blog_id' => get_current_blog_id(), 'attachment_id' => $attachment_id ) );
                    }
                }
            }

            $wrapped = (string) get_post_meta( $attachment_id, self::KEY_META, true );

            if ( '' !== $wrapped && $this->old_key( $wrapped ) ) {
                $next = $this->engine()->reseal( Better_Messages_Encryption::OWNER, $wrapped, 'file:attachment:' . $attachment_id );

                if ( null !== $next ) {
                    update_post_meta( $attachment_id, self::KEY_META, $next );
                }
            }

            $object = $this->cloud_object( $attachment_id );

            if ( $object && '' !== (string) $object->name ) {
                $storage->sdk()->update_row( $object->id, array( 'name' => '' ) );
                $storage->reset_caches();
            }
        }

        private function old_key( $value )
        {
            $engine = $this->engine();

            return $engine && Better_Messages_Encryption::is_sealed( $value ) && WordPlus_SDK_Encryption::sealed_with( $value ) !== $engine->key_id();
        }

        private function open_names( $attachment_id )
        {
            global $wpdb;

            $raw = $wpdb->get_var( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s ORDER BY meta_id ASC LIMIT 1", $attachment_id, self::NAME_META ) );
            $name = null === $raw ? '' : $this->open_name( $attachment_id, $raw );

            if ( Better_Messages_Encryption::is_sealed( $raw ) && '' !== $name ) {
                $this->store_name( $attachment_id, $name );
            }

            $storage = Better_Messages()->storage;

            if ( $storage && $storage->table_exists() ) {
                $stored = $wpdb->get_var( $wpdb->prepare( "SELECT `name` FROM `{$storage->table()}` WHERE `blog_id` = %d AND `attachment_id` = %d", get_current_blog_id(), $attachment_id ) );

                if ( Better_Messages_Encryption::is_sealed( $stored ) ) {
                    $wpdb->update( $storage->table(), array( 'name' => $this->open_file_name( $attachment_id, $stored ) ), array( 'blog_id' => get_current_blog_id(), 'attachment_id' => $attachment_id ) );
                }
            }

            if ( Better_Messages()->encryption->index() ) {
                Better_Messages()->encryption->index()->remove( Better_Messages_Encryption::OWNER, 'file', array( (int) $attachment_id ) );
            }

            $object = $this->cloud_object( $attachment_id );

            if ( $object && '' === (string) $object->name && '' !== $name ) {
                $storage->sdk()->update_row( $object->id, array( 'name' => $name ) );
                $storage->reset_caches();
            }
        }

        private function store_name( $attachment_id, $value )
        {
            global $wpdb;

            $wpdb->update( $wpdb->postmeta, array( 'meta_value' => $value ), array( 'post_id' => (int) $attachment_id, 'meta_key' => self::NAME_META ) );

            wp_cache_delete( (int) $attachment_id, 'post_meta' );
        }

        public function find_file_names( $query, $limit = 500 )
        {
            if ( ! Better_Messages()->encryption->searching() ) {
                return array();
            }

            return array_map( 'intval', (array) Better_Messages()->encryption->index()->search( Better_Messages_Encryption::OWNER, 'file', $query, array( 'limit' => (int) $limit ) ) );
        }

        private function engine()
        {
            return Better_Messages()->encryption ? Better_Messages()->encryption->engine() : null;
        }

        private function sdk_files()
        {
            $engine = $this->engine();

            return $engine && method_exists( $engine, 'files' ) ? $engine->files() : null;
        }

        public function available()
        {
            return null !== $this->sdk_files() && WordPlus_SDK_Encryption_Files::available();
        }

        public function is_encrypted( $attachment_id )
        {
            return '' !== (string) get_post_meta( (int) $attachment_id, self::KEY_META, true );
        }

        public function key( $attachment_id )
        {
            $attachment_id = (int) $attachment_id;

            if ( ! array_key_exists( $attachment_id, $this->keys ) ) {
                $wrapped = (string) get_post_meta( $attachment_id, self::KEY_META, true );
                $files   = $this->sdk_files();

                $this->keys[ $attachment_id ] = '' === $wrapped || ! $files ? null : $files->unwrap_key( Better_Messages_Encryption::OWNER, $wrapped, 'attachment:' . $attachment_id );
            }

            return $this->keys[ $attachment_id ];
        }

        public function encrypt_attachment( $attachment_id, $source = '' )
        {
            $attachment_id = (int) $attachment_id;

            if ( ! Better_Messages()->encryption->on() || ! $this->available() || $this->is_encrypted( $attachment_id ) || $this->is_e2e( $attachment_id ) ) {
                return;
            }

            $this->encrypt_local( $attachment_id );
        }

        private function new_wrapped_key( $attachment_id )
        {
            $raw    = WordPlus_SDK_Encryption_Files::new_key();
            $sealed = $this->sdk_files()->wrap_key( Better_Messages_Encryption::OWNER, $raw, 'attachment:' . $attachment_id );

            return null === $sealed ? null : array( $raw, $sealed );
        }

        private function encrypt_local( $attachment_id )
        {
            $path = get_attached_file( $attachment_id );

            if ( ! $path || ! file_exists( $path ) ) {
                return null;
            }

            $keys = $this->new_wrapped_key( $attachment_id );

            if ( null === $keys ) {
                return false;
            }

            list( $raw, $sealed ) = $keys;

            $plain_size = (int) filesize( $path );

            if ( true !== WordPlus_SDK_Encryption_Files::encrypt_in_place( $path, $raw ) ) {
                return false;
            }

            update_post_meta( $attachment_id, self::KEY_META, $sealed );
            update_post_meta( $attachment_id, self::SIZE_META, $plain_size );
            $this->keys[ $attachment_id ] = $raw;

            foreach ( $this->extra_copies( $attachment_id, $path ) as $copy ) {
                WordPlus_SDK_Encryption_Files::encrypt_in_place( $copy, $raw );
            }

            $this->drop_cover_art( $attachment_id, $path );
            $this->stored_type( $attachment_id, 'application/octet-stream' );

            return true;
        }

        private function extra_copies( $attachment_id, $path )
        {
            $metadata = wp_get_attachment_metadata( $attachment_id );

            if ( ! is_array( $metadata ) || empty( $metadata['original_image'] ) ) {
                return array();
            }

            $original = trailingslashit( dirname( $path ) ) . wp_basename( $metadata['original_image'] );

            return file_exists( $original ) ? array( $original ) : array();
        }

        private function stored_type( $attachment_id, $mime )
        {
            $storage = Better_Messages()->storage;
            $sdk     = $storage ? $storage->sdk() : null;
            $object  = $sdk ? $storage->object( $attachment_id, true ) : null;

            if ( $object && in_array( $object->state, array( 'local', 'failed' ), true ) ) {
                $sdk->update_row( $object->id, array( 'mime' => Better_Messages_Storage::stored_type( $mime ) ) );
                $storage->reset_caches();
            }
        }

        public function job_row( $mode, $row )
        {
            $attachment_id = (int) $row->ID;

            if ( ! $this->available() ) {
                return 'skipped';
            }

            if ( $this->is_e2e( $attachment_id ) ) {
                if ( 'open' === $mode ) {
                    $this->open_names( $attachment_id );
                } else {
                    $this->seal_names( $attachment_id );
                }

                return 'skipped';
            }

            $result = 'open' === $mode ? $this->decrypt_existing( $attachment_id ) : $this->encrypt_existing( $attachment_id );

            if ( 'changed' === $result ) {
                $this->changed = true;
            }

            return $result;
        }

        private function cloud_object( $attachment_id )
        {
            $storage = Better_Messages()->storage;
            $sdk     = $storage ? $storage->sdk() : null;

            return $sdk ? $storage->object( $attachment_id, true ) : null;
        }

        private function encrypt_existing( $attachment_id )
        {
            if ( $this->is_encrypted( $attachment_id ) ) {
                $this->seal_names( $attachment_id );

                return null;
            }

            $object = $this->cloud_object( $attachment_id );
            $sdk    = Better_Messages()->storage ? Better_Messages()->storage->sdk() : null;

            if ( $object && $sdk && $sdk->in_storage( $object ) ) {
                return $this->encrypt_cloud( $attachment_id, $object );
            }

            if ( $object && $sdk && $sdk->cloud_bound( $object ) ) {
                return 'failed';
            }

            $done = $this->encrypt_local( $attachment_id );

            if ( true === $done ) {
                $this->rename_local( $attachment_id );
                $this->seal_names( $attachment_id );
            }

            return null === $done ? 'skipped' : ( $done ? 'changed' : 'failed' );
        }

        private function encrypt_cloud( $attachment_id, $object )
        {
            $keys = $this->new_wrapped_key( $attachment_id );

            if ( null === $keys ) {
                return 'failed';
            }

            list( $raw, $sealed ) = $keys;

            $plain_size = (int) $object->size;
            $base       = self::has_random_name( $object->object_key ) ? null : self::random_base( $object->object_key );
            $done       = Better_Messages()->storage->sdk()->rewrite( $object, function ( $path ) use ( $raw ) {
                return WordPlus_SDK_Encryption_Files::encrypt_in_place( $path, $raw );
            }, 'application/octet-stream', HOUR_IN_SECONDS, $base );

            if ( is_wp_error( $done ) ) {
                return 'failed';
            }

            update_post_meta( $attachment_id, self::KEY_META, $sealed );
            update_post_meta( $attachment_id, self::SIZE_META, $plain_size );
            $this->keys[ $attachment_id ] = $raw;

            $path = get_attached_file( $attachment_id );

            if ( $path && file_exists( $path ) && ! WordPlus_SDK_Encryption_Files::is_encrypted( $path ) ) {
                WordPlus_SDK_Encryption_Files::encrypt_in_place( $path, $raw );
            }

            if ( null !== $base ) {
                $attached = (string) get_post_meta( $attachment_id, '_wp_attached_file', true );
                $relative = ( '.' === dirname( $attached ) ? '' : dirname( $attached ) . '/' ) . $base;

                if ( $path && file_exists( $path ) ) {
                    @rename( $path, trailingslashit( dirname( $path ) ) . $base ); // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
                }

                $this->moved_name( $attachment_id, $relative );

                Better_Messages()->storage->sdk()->update_row( $object->id, array(
                    'path'     => $relative,
                    'item_key' => ltrim( Better_Messages()->storage->relative_path( $attachment_id ), '/' ),
                ) );

                if ( '' !== (string) get_post_meta( $attachment_id, Better_Messages_Storage::META_KEY, true ) ) {
                    update_post_meta( $attachment_id, Better_Messages_Storage::META_KEY, $done['key'] );
                }
            }

            Better_Messages()->storage->reset_caches();
            $this->seal_names( $attachment_id );

            return 'changed';
        }

        private function decrypt_existing( $attachment_id )
        {
            if ( ! $this->is_encrypted( $attachment_id ) ) {
                $this->open_names( $attachment_id );

                return null;
            }

            $key = $this->key( $attachment_id );

            if ( null === $key ) {
                return 'failed';
            }

            $object = $this->cloud_object( $attachment_id );
            $sdk    = Better_Messages()->storage ? Better_Messages()->storage->sdk() : null;
            $mime   = (string) get_post_mime_type( $attachment_id );

            if ( $object && $sdk && $sdk->in_storage( $object ) ) {
                $done = $sdk->rewrite( $object, function ( $path ) use ( $key ) {
                    return WordPlus_SDK_Encryption_Files::decrypt_in_place( $path, $key );
                }, '' === $mime ? 'application/octet-stream' : $mime );

                if ( is_wp_error( $done ) ) {
                    return 'failed';
                }
            } elseif ( $object && $sdk && $sdk->cloud_bound( $object ) ) {
                return 'failed';
            }

            $path = get_attached_file( $attachment_id );

            if ( $path && file_exists( $path ) ) {
                foreach ( array_merge( array( $path ), $this->extra_copies( $attachment_id, $path ) ) as $copy ) {
                    if ( WordPlus_SDK_Encryption_Files::is_encrypted( $copy ) && true !== WordPlus_SDK_Encryption_Files::decrypt_in_place( $copy, $key ) ) {
                        return 'failed';
                    }
                }
            }

            delete_post_meta( $attachment_id, self::KEY_META );
            delete_post_meta( $attachment_id, self::SIZE_META );
            unset( $this->keys[ $attachment_id ] );

            $this->stored_type( $attachment_id, '' === $mime ? 'application/octet-stream' : $mime );
            Better_Messages()->storage->reset_caches();
            $this->open_names( $attachment_id );

            return 'changed';
        }

        public function job_finished( $owner, $mode, $job = array() )
        {
            if ( Better_Messages_Encryption::OWNER !== $owner ) {
                return;
            }

            $changed = $this->changed || ( is_array( $job ) && ! empty( $job['collections']['files']['changed'] ) );

            if ( $changed ) {
                update_option( 'bm_local_db_generation', (int) get_option( 'bm_local_db_generation', 1 ) + 1, false );
            }

            $this->changed = false;
        }

        public function attachment_url( $url, $attachment_id, $message_id = 0, $thread_id = 0 )
        {
            if ( ! $this->is_encrypted( $attachment_id ) || ! function_exists( 'Better_Messages_Files' ) ) {
                return $url;
            }

            return Better_Messages_Files()->get_proxy_url( (int) $attachment_id );
        }

        public function admin_link( $attachment_id )
        {
            if ( ! $this->is_encrypted( $attachment_id ) || ! function_exists( 'Better_Messages_Files' ) ) {
                return null;
            }

            return add_query_arg( 'token', rawurlencode( Better_Messages_Files()->access_token( get_current_user_id() ) ), Better_Messages_Files()->get_proxy_url( (int) $attachment_id ) );
        }

        public function message_meta( $meta, $message_id, $thread_id, $content )
        {
            if ( ! is_array( $meta ) || empty( $meta['files'] ) || ! is_array( $meta['files'] ) ) {
                return $meta;
            }

            foreach ( $meta['files'] as $index => $file ) {
                if ( ! is_array( $file ) || empty( $file['id'] ) || ! $this->is_encrypted( $file['id'] ) ) {
                    continue;
                }

                $meta['files'][ $index ] = $this->describe( $file, (int) $file['id'] );
            }

            return $meta;
        }

        public function describe( array $file, $attachment_id )
        {
            $key = $this->key( $attachment_id );

            unset( $file['link'] );

            $file['url']       = function_exists( 'Better_Messages_Files' ) ? Better_Messages_Files()->get_proxy_url( $attachment_id ) : $file['url'];
            $file['thumb']     = $file['url'];
            $file['encrypted'] = 1;

            if ( null !== $key ) {
                $file['fileKey'] = WordPlus_SDK_Encryption_Files::base64url( $key );
            }

            $size = $this->plain_size( $attachment_id );

            if ( null !== $size ) {
                $file['size'] = $size;
            }

            return $file;
        }

        public function plain_size( $attachment_id )
        {
            $path = get_attached_file( (int) $attachment_id );

            if ( $path && file_exists( $path ) && class_exists( 'WordPlus_SDK_Encryption_Files' ) ) {
                $size = WordPlus_SDK_Encryption_Files::plain_size( $path );

                if ( null !== $size ) {
                    return $size;
                }
            }

            $kept = get_post_meta( (int) $attachment_id, self::SIZE_META, true );

            return '' === $kept ? null : (int) $kept;
        }

        public function serve( $attachment_id, WP_REST_Request $request )
        {
            $attachment_id = (int) $attachment_id;
            $storage       = Better_Messages()->storage;
            $in_cloud      = $storage && $storage->is_cloud_file( $attachment_id );

            if ( '1' === (string) $request->get_param( 'raw' ) ) {
                if ( $in_cloud ) {
                    return $storage->serve_cloud_file( $attachment_id, $request );
                }

                return $this->send_raw( $attachment_id );
            }

            $key = $this->key( $attachment_id );

            if ( null === $key || ! $this->available() ) {
                return new WP_Error( 'rest_unreadable', _x( 'This file cannot be read: the encryption key of this site is missing.', 'Stored data encryption', 'bp-better-messages' ), array( 'status' => 503 ) );
            }

            $copy = $in_cloud ? $storage->get_stored_copy( $attachment_id ) : array( 'path' => get_attached_file( $attachment_id ), 'cleanup' => function () {} );

            if ( is_wp_error( $copy ) || empty( $copy['path'] ) || ! file_exists( $copy['path'] ) ) {
                return new WP_Error( 'rest_not_found', _x( 'File not found.', 'File Proxy Error', 'bp-better-messages' ), array( 'status' => 404 ) );
            }

            $mime        = (string) get_post_mime_type( $attachment_id );
            $disposition = preg_match( '#^(image|video|audio)/|^application/pdf$#', $mime ) ? 'inline' : 'attachment';
            $name        = (string) get_post_meta( $attachment_id, 'bp-better-messages-original-name', true );

            $done = WordPlus_SDK_Encryption_Files::serve( $copy['path'], $key, array(
                'type'        => '' === $mime ? 'application/octet-stream' : $mime,
                'name'        => '' === $name ? wp_basename( (string) get_attached_file( $attachment_id ) ) : $name,
                'disposition' => $disposition,
                'etag'        => 'bm' . $attachment_id . '-' . (int) @filemtime( $copy['path'] ),
            ) );

            call_user_func( $copy['cleanup'] );

            if ( is_wp_error( $done ) ) {
                return $done;
            }

            exit;
        }

        public function local_copy( $attachment_id, $copy )
        {
            if ( is_wp_error( $copy ) || empty( $copy['path'] ) || ! $this->is_encrypted( $attachment_id ) ) {
                return $copy;
            }

            $key = $this->key( $attachment_id );

            if ( null === $key || ! WordPlus_SDK_Encryption_Files::is_encrypted( $copy['path'] ) ) {
                return null === $key ? new WP_Error( 'bm_storage_unreadable', 'The encryption key of this site is missing' ) : $copy;
            }

            $plain     = wp_tempnam( 'bm-plain-' . $attachment_id );
            $extension = pathinfo( (string) get_post_meta( $attachment_id, '_wp_attached_file', true ), PATHINFO_EXTENSION );

            if ( '' !== $extension && WordPlus_SDK::files()->move( $plain, $plain . '.' . sanitize_file_name( $extension ), true ) ) {
                $plain .= '.' . sanitize_file_name( $extension );
            }

            $done  = WordPlus_SDK_Encryption_Files::decrypt_file( $copy['path'], $plain, $key );
            $inner = $copy['cleanup'];

            call_user_func( $inner );

            if ( true !== $done ) {
                @unlink( $plain ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink

                return $done;
            }

            return array(
                'path'    => $plain,
                'cleanup' => function () use ( $plain ) {
                    @unlink( $plain ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
                },
            );
        }

        private function send_raw( $attachment_id )
        {
            $path = get_attached_file( $attachment_id );

            if ( ! $path || ! file_exists( $path ) ) {
                return new WP_Error( 'rest_not_found', _x( 'File not found.', 'File Proxy Error', 'bp-better-messages' ), array( 'status' => 404 ) );
            }

            while ( ob_get_level() ) {
                ob_end_clean();
            }

            status_header( 200 );
            header( 'Content-Type: application/octet-stream' );
            header( 'Content-Length: ' . filesize( $path ) );
            header( 'Cache-Control: private, max-age=86400' );
            header( 'X-Content-Type-Options: nosniff' );
            header( 'Referrer-Policy: no-referrer' );

            readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile

            exit;
        }

        private function is_e2e( $attachment_id )
        {
            if ( Better_Messages()->storage && method_exists( Better_Messages()->storage, 'is_e2e_file' ) ) {
                return Better_Messages()->storage->is_e2e_file( $attachment_id );
            }

            return '' !== (string) get_post_meta( $attachment_id, 'bm-e2e-original-mime', true );
        }

        private function drop_cover_art( $attachment_id, $path )
        {
            $cover = (int) get_post_meta( $attachment_id, '_thumbnail_id', true );

            if ( $cover <= 0 || get_post_meta( $cover, 'bp-better-messages-attachment', true ) ) {
                return;
            }

            $cover_path = get_attached_file( $cover );

            if ( $cover_path && 0 === strpos( wp_normalize_path( dirname( $cover_path ) ), wp_normalize_path( dirname( $path ) ) ) ) {
                delete_post_meta( $attachment_id, '_thumbnail_id' );
                wp_delete_attachment( $cover, true );
            }
        }
    }

endif;
