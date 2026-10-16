<?php
defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Better_Messages_Storage' ) ):

    class Better_Messages_Storage
    {
        const PLUGIN          = 'better-messages';
        const OPTION          = 'better_messages_storage';
        const BACKFILL_OPTION = 'better_messages_storage_backfill';
        const META_LOCATION   = 'bm-storage-location';
        const META_KEY        = 'bm-storage-key';
        const META_SIZE       = 'bm-storage-size';
        const META_ETAG       = 'bm-storage-etag';
        const SITE_OPTION     = 'better_messages_storage_site_id';
        const MARKER_NAME     = '.better-messages-site.json';
        const SIGN_TTL        = 129600;

        private $groups_cache = null;
        private $row_cache    = array();
        private $table_ready  = null;

        public static function instance()
        {
            static $instance = null;

            if ( null === $instance ) {
                $instance = new Better_Messages_Storage();
            }

            return $instance;
        }

        public function __construct()
        {
            add_action( 'better_messages_attachment_created', array( $this, 'attachment_created' ), 10, 2 );
            add_action( 'added_post_meta', array( $this, 'maybe_attachment_linked' ), 10, 4 );
            add_action( 'delete_attachment', array( $this, 'attachment_deleted' ), 10, 1 );
            add_filter( 'better_messages_attachment_url', array( $this, 'filter_attachment_url' ), 20, 4 );
            add_filter( 'better_messages_rest_message_meta', array( $this, 'message_file_links' ), 30, 4 );
            add_action( 'rest_api_init', array( $this, 'rest_api_init' ) );

            add_action( 'wordplus_storage_sent', array( $this, 'storage_sent' ), 10, 2 );
            add_action( 'wordplus_storage_relocated', array( $this, 'storage_relocated' ) );
            add_action( 'wordplus_storage_deleted', array( $this, 'storage_deleted' ) );
            add_action( 'wordplus_storage_readonly', array( $this, 'storage_readonly' ) );
            add_action( 'wordplus_storage_index', array( $this, 'storage_index' ) );
            add_filter( 'wordplus_storage_indexed', array( $this, 'storage_indexed' ) );
            add_filter( 'wordplus_storage_uses', array( $this, 'storage_uses' ) );
            add_filter( 'wordplus_storage_can_move', array( $this, 'storage_can_move' ), 10, 2 );
            add_filter( 'wordplus_storage_cors_origins', array( $this, 'storage_origins' ) );
            add_filter( 'wordplus_storage_browser_reads', array( $this, 'storage_browser_reads' ) );
            add_filter( 'wordplus_storage_adopt', array( $this, 'storage_adopt' ) );
            add_filter( 'wordplus_storage_legacy_markers', array( $this, 'storage_legacy_markers' ) );
            add_filter( 'wordplus_storage_sweep_patterns', array( $this, 'storage_sweep_patterns' ) );
            add_filter( 'wordplus_storage_known_keys', array( $this, 'storage_known_keys' ), 10, 2 );
            add_filter( 'wordplus_storage_signed_url_window', array( $this, 'signed_url_window' ), 10, 2 );
            add_filter( 'wordplus_storage_signed_url_ttl', array( $this, 'signed_url_ttl' ), 10, 2 );
        }

        public function sdk()
        {
            return function_exists( 'wordplus_storage' ) ? wordplus_storage() : null;
        }

        public function reset_caches()
        {
            $this->groups_cache = null;
            $this->row_cache    = array();

            if ( $this->sdk() ) {
                $this->sdk()->reset_caches();
            }
        }

        public static function types()
        {
            return array( 'r2', 'bunny', 's3' );
        }

        public static function groups()
        {
            return array( 'images', 'videos', 'audio', 'voice_messages', 'video_messages', 'encrypted', 'documents' );
        }

        public static function group_label( $group )
        {
            switch ( $group ) {
                case 'images':
                    return _x( 'Images', 'Settings page', 'bp-better-messages' );
                case 'videos':
                    return _x( 'Videos', 'Settings page', 'bp-better-messages' );
                case 'audio':
                    return _x( 'Audio', 'Settings page', 'bp-better-messages' );
                case 'voice_messages':
                    return _x( 'Voice messages', 'Settings page', 'bp-better-messages' );
                case 'video_messages':
                    return _x( 'Video messages', 'Settings page', 'bp-better-messages' );
                case 'encrypted':
                    return _x( 'Encrypted files', 'Settings page', 'bp-better-messages' );
                default:
                    return _x( 'Documents and other files', 'Settings page', 'bp-better-messages' );
            }
        }

        public static function file_group( $kind, $mime )
        {
            switch ( $kind ) {
                case 'e2e':
                    return 'encrypted';
                case 'voice':
                    return 'voice_messages';
                case 'video':
                    return 'video_messages';
            }

            $mime = strtolower( (string) $mime );

            foreach ( array( 'image/' => 'images', 'video/' => 'videos', 'audio/' => 'audio' ) as $prefix => $group ) {
                if ( strpos( $mime, $prefix ) === 0 ) {
                    return $group;
                }
            }

            return 'documents';
        }

        public function connected_types()
        {
            $sdk = $this->sdk();

            if ( ! $sdk ) {
                return array();
            }

            $settings = $sdk->get_settings();

            return array_values( array_filter( self::types(), function ( $type ) use ( $settings ) {
                return isset( $settings['current'][ $type ] ) && $settings['current'][ $type ] !== '';
            } ) );
        }

        public function routes()
        {
            if ( $this->groups_cache !== null ) {
                return $this->groups_cache;
            }

            $option    = get_option( self::OPTION, array() );
            $option    = is_array( $option ) ? $option : array();
            $stored    = isset( $option['groups'] ) && is_array( $option['groups'] ) ? $option['groups'] : array();
            $connected = $this->connected_types();
            $routes    = array();

            if ( empty( $stored ) && ! empty( $option['active'] ) && is_string( $option['active'] ) && isset( $option['locations'][ $option['active'] ]['preset'] ) ) {
                $stored = array_fill_keys( self::groups(), (string) $option['locations'][ $option['active'] ]['preset'] );
            }

            foreach ( self::groups() as $group ) {
                $type = isset( $stored[ $group ] ) ? (string) $stored[ $group ] : 'local';

                $routes[ $group ] = in_array( $type, $connected, true ) ? $type : 'local';
            }

            return $this->groups_cache = $routes;
        }

        public function get_settings()
        {
            return array( 'groups' => $this->routes() );
        }

        public function save_routes( array $routes )
        {
            $stored = get_option( self::OPTION, array() );
            $stored = is_array( $stored ) ? $stored : array();

            $stored['groups'] = array_intersect_key( $routes, array_flip( self::groups() ) );

            if ( $this->sdk() ) {
                $this->sdk()->get_settings();
                unset( $stored['locations'], $stored['current'], $stored['active'], $stored['direct'] );
            }

            update_option( self::OPTION, $stored, false );

            $this->groups_cache = null;

            if ( $this->sdk() && $this->has_cloud_routes() ) {
                $this->sdk()->schedule( 60 );
            }
        }

        public function route( $group )
        {
            $routes = $this->routes();
            $type   = isset( $routes[ $group ] ) ? $routes[ $group ] : 'local';

            return $type === 'local' || ! $this->sdk() ? null : $this->sdk()->route( $type );
        }

        public function has_cloud_routes()
        {
            foreach ( $this->routes() as $type ) {
                if ( $type !== 'local' ) {
                    return true;
                }
            }

            return false;
        }

        public function has_locations()
        {
            return $this->sdk() && $this->sdk()->has_locations();
        }

        public function private_links_available()
        {
            return $this->sdk() && $this->sdk()->private_links_available();
        }

        public function resolver_needed()
        {
            return $this->has_locations() || false !== get_option( self::OPTION, false );
        }

        public function get_location( $id )
        {
            return $this->sdk() ? $this->sdk()->get_location( $id ) : null;
        }

        public function table()
        {
            return bm_get_table( 'files' );
        }

        public function table_exists()
        {
            global $wpdb;

            if ( $this->table_ready === null ) {
                $this->table_ready = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $this->table() ) ) === $this->table();
            }

            return $this->table_ready;
        }

        public function base_dir_name()
        {
            return apply_filters( 'bp_better_messages_upload_dir_name', 'bp-better-messages' );
        }

        public function relative_path( $attachment_id )
        {
            $file = (string) get_post_meta( $attachment_id, '_wp_attached_file', true );
            $base = trailingslashit( $this->base_dir_name() );

            if ( strpos( $file, $base ) === 0 ) {
                return substr( $file, strlen( $base ) );
            }

            return ltrim( $file, '/' );
        }

        public function is_e2e_file( $attachment_id )
        {
            if ( get_post_meta( $attachment_id, 'bm-e2e-original-mime', true ) ) {
                return true;
            }

            return strtolower( pathinfo( (string) get_post_meta( $attachment_id, '_wp_attached_file', true ), PATHINFO_EXTENSION ) ) === 'enc';
        }

        public function original_name( $attachment_id )
        {
            $name = (string) get_post_meta( $attachment_id, 'bp-better-messages-original-name', true );

            if ( $name === '' ) {
                $name = wp_basename( (string) get_post_meta( $attachment_id, '_wp_attached_file', true ) );
            }

            return $name;
        }

        public static function stored_type( $mime )
        {
            return class_exists( 'WordPlus_SDK_Storage' ) ? WordPlus_SDK_Storage::stored_type( $mime ) : (string) $mime;
        }

        public function content_type( $attachment_id )
        {
            if ( $this->is_e2e_file( $attachment_id ) ) {
                return 'application/octet-stream';
            }

            return self::stored_type( (string) get_post_mime_type( $attachment_id ) );
        }

        public function file_kind( $attachment_id, $message_id = 0 )
        {
            if ( $this->is_e2e_file( $attachment_id ) ) {
                return 'e2e';
            }

            if ( get_post_meta( $attachment_id, 'bp-better-messages-bulk-attachment', true ) || get_post_meta( $attachment_id, 'bp-better-messages-bulk-job-id', true ) ) {
                return 'bulk';
            }

            if ( $message_id > 0 ) {
                if ( (int) Better_Messages()->functions->get_message_meta( $message_id, 'bpbm_voice_messages', true ) === (int) $attachment_id ) {
                    return 'voice';
                }

                if ( (int) Better_Messages()->functions->get_message_meta( $message_id, 'bpbm_video_messages', true ) === (int) $attachment_id ) {
                    return 'video';
                }
            }

            return 'file';
        }

        public function object_args( $attachment_id )
        {
            $path = get_attached_file( $attachment_id );
            $size = (int) get_post_meta( $attachment_id, self::META_SIZE, true );

            if ( $size === 0 && $path && file_exists( $path ) ) {
                $size = (int) filesize( $path );
            }

            $post = get_post( $attachment_id );

            return array(
                'path'    => (string) get_post_meta( $attachment_id, '_wp_attached_file', true ),
                'key'     => $this->relative_path( $attachment_id ),
                'name'    => $this->original_name( $attachment_id ),
                'type'    => $this->content_type( $attachment_id ),
                'size'    => $size,
                'user'    => (int) get_post_meta( $attachment_id, 'bp-better-messages-uploader-user-id', true ),
                'created' => $post ? (int) strtotime( $post->post_date_gmt . ' UTC' ) : time(),
            );
        }

        public function object( $attachment_id, $fresh = false )
        {
            $sdk           = $this->sdk();
            $attachment_id = (int) $attachment_id;

            if ( ! $sdk || $attachment_id <= 0 ) {
                return null;
            }

            if ( ! $fresh && array_key_exists( $attachment_id, $this->row_cache ) ) {
                return $this->row_cache[ $attachment_id ];
            }

            $row = $sdk->get( self::PLUGIN, $attachment_id, '', $fresh );

            if ( ! $row ) {
                $row = $this->adopt_object( $attachment_id );
            }

            return $this->row_cache[ $attachment_id ] = $row;
        }

        private function adopt_object( $attachment_id )
        {
            $location = (string) get_post_meta( $attachment_id, self::META_LOCATION, true );

            if ( $location === '' || ! $this->sdk() ) {
                return null;
            }

            $args               = $this->object_args( $attachment_id );
            $args['location']   = $location;
            $args['object_key'] = (string) get_post_meta( $attachment_id, self::META_KEY, true );

            $row = $this->sdk()->add( self::PLUGIN, $attachment_id, $args );

            if ( is_wp_error( $row ) ) {
                return null;
            }

            $this->link_index( $attachment_id, (int) $row->id );

            return $row;
        }

        public function prime( array $attachment_ids )
        {
            $ids = array_values( array_filter( array_map( 'intval', $attachment_ids ) ) );

            if ( empty( $ids ) || ! $this->sdk() ) {
                return;
            }

            $this->sdk()->prime( self::PLUGIN, $ids );

            foreach ( $ids as $id ) {
                unset( $this->row_cache[ $id ] );
            }
        }

        public function get_row( $attachment_id )
        {
            global $wpdb;

            if ( ! $this->table_exists() ) {
                return null;
            }

            return $wpdb->get_row( $wpdb->prepare(
                "SELECT * FROM `{$this->table()}` WHERE `blog_id` = %d AND `attachment_id` = %d",
                get_current_blog_id(), (int) $attachment_id
            ) );
        }

        private function link_index( $attachment_id, $object_id )
        {
            global $wpdb;

            if ( $this->table_exists() ) {
                $wpdb->query( $wpdb->prepare(
                    "UPDATE `{$this->table()}` SET `object_id` = %d WHERE `blog_id` = %d AND `attachment_id` = %d",
                    $object_id, get_current_blog_id(), (int) $attachment_id
                ) );
            }
        }

        public function ensure_row( $attachment_id )
        {
            global $wpdb;

            $attachment_id = (int) $attachment_id;
            $object        = null;

            if ( $this->sdk() ) {
                $object = $this->object( $attachment_id, true );

                if ( ! $object ) {
                    $object = $this->sdk()->add( self::PLUGIN, $attachment_id, $this->object_args( $attachment_id ) );
                    $object = is_wp_error( $object ) ? null : $object;

                    $this->row_cache[ $attachment_id ] = $object;
                }
            }

            if ( ! $this->table_exists() ) {
                return null;
            }

            $row = $this->get_row( $attachment_id );

            if ( $row ) {
                if ( $object && (int) $row->object_id !== (int) $object->id ) {
                    $this->link_index( $attachment_id, (int) $object->id );
                    $row->object_id = (int) $object->id;
                }

                return $row;
            }

            $post       = get_post( $attachment_id );
            $message_id = (int) get_post_meta( $attachment_id, 'bp-better-messages-message-id', true );
            $now        = time();

            $wpdb->query( $wpdb->prepare(
                "INSERT IGNORE INTO `{$this->table()}`
                (`blog_id`, `attachment_id`, `object_id`, `thread_id`, `message_id`, `uploader_id`, `name`, `mime`, `size`, `kind`, `created_at`, `updated_at`)
                VALUES (%d, %d, %d, %d, %d, %d, %s, %s, %d, %s, %d, %d)",
                get_current_blog_id(),
                $attachment_id,
                $object ? (int) $object->id : 0,
                (int) get_post_meta( $attachment_id, 'bp-better-messages-thread-id', true ),
                $message_id,
                (int) get_post_meta( $attachment_id, 'bp-better-messages-uploader-user-id', true ),
                mb_substr( $this->original_name( $attachment_id ), 0, 255 ),
                $post ? mb_substr( (string) $post->post_mime_type, 0, 100 ) : '',
                $object ? (int) $object->size : 0,
                $this->file_kind( $attachment_id, $message_id ),
                $post ? (int) strtotime( $post->post_date_gmt . ' UTC' ) : $now,
                $now
            ) );

            return $this->get_row( $attachment_id );
        }

        public function update_row( $id, array $fields )
        {
            global $wpdb;

            $fields['updated_at'] = time();

            $wpdb->update( $this->table(), $fields, array( 'id' => (int) $id ) );
        }

        public function attachment_created( $attachment_id, $context = '' )
        {
            $this->ensure_row( $attachment_id );
        }

        public function maybe_attachment_linked( $meta_id, $post_id, $meta_key, $meta_value )
        {
            if ( $meta_key !== 'bp-better-messages-message-id' ) {
                return;
            }

            if ( empty( get_post_meta( $post_id, 'bp-better-messages-attachment', true ) ) ) {
                return;
            }

            $this->attachment_linked( (int) $post_id, (int) $meta_value );
        }

        public function sync_local_path( $object )
        {
            if ( ! $object || ! $this->sdk() || $object->ref === null || ! in_array( $object->state, array( 'local', 'failed' ), true ) ) {
                return $object;
            }

            $attachment_id = (int) $object->ref;
            $path          = ltrim( (string) get_post_meta( $attachment_id, '_wp_attached_file', true ), '/' );
            $key           = ltrim( $this->relative_path( $attachment_id ), '/' );

            if ( $path === '' || ( $path === (string) $object->path && $key === (string) $object->item_key ) ) {
                return $object;
            }

            $this->sdk()->update_row( $object->id, array( 'path' => $path, 'item_key' => $key ) );

            $object->path     = $path;
            $object->item_key = $key;

            unset( $this->row_cache[ $attachment_id ] );

            return $object;
        }

        public function attachment_linked( $attachment_id, $message_id )
        {
            $row = $this->ensure_row( $attachment_id );

            if ( ! $row ) {
                return;
            }

            $kind   = $row->kind;
            $fields = array();

            if ( (int) $row->message_id === 0 ) {
                $kind = $this->file_kind( $attachment_id, $message_id );

                $fields['message_id'] = $message_id;
                $fields['kind']       = $kind;
            }

            $thread_id = (int) get_post_meta( $attachment_id, 'bp-better-messages-thread-id', true );

            if ( $thread_id > 0 && (int) $row->thread_id !== $thread_id ) {
                $fields['thread_id'] = $thread_id;
            }

            if ( ! empty( $fields ) ) {
                $this->update_row( $row->id, $fields );
            }

            $location = $this->route( self::file_group( $kind, $row->mime ) );
            $object   = $this->sync_local_path( $this->object( $attachment_id, true ) );

            if ( $location && $object ) {
                $this->sdk()->send( $object, $location['id'] );
                unset( $this->row_cache[ $attachment_id ] );
            }
        }

        public function attachment_deleted( $post_id )
        {
            global $wpdb;

            if ( empty( get_post_meta( $post_id, 'bp-better-messages-attachment', true ) ) ) {
                return;
            }

            $object = $this->object( $post_id, true );
            $row    = $this->get_row( $post_id );

            if ( $object ) {
                $this->sdk()->forget( $object );
            }

            unset( $this->row_cache[ (int) $post_id ] );

            if ( ! $row ) {
                return;
            }

            $object_id = $object ? (int) $object->id : (int) $row->object_id;
            $pending   = $object_id > 0 && $this->sdk() ? $this->sdk()->object( $object_id ) : null;

            if ( ! $pending ) {
                $wpdb->delete( $this->table(), array( 'id' => (int) $row->id ) );
            }
        }

        public function storage_deleted( $object )
        {
            global $wpdb;

            if ( ! is_object( $object ) || ! $this->table_exists() ) {
                return;
            }

            $wpdb->query( $wpdb->prepare(
                "DELETE f FROM `{$this->table()}` f LEFT JOIN {$wpdb->posts} p ON p.ID = f.attachment_id WHERE f.`blog_id` = %d AND f.`object_id` = %d AND p.ID IS NULL",
                get_current_blog_id(), (int) $object->id
            ) );
        }

        public function is_cloud_file( $attachment_id )
        {
            $object = $this->object( $attachment_id );

            return $object && $this->sdk()->in_storage( $object );
        }

        public function is_cloud_bound( $attachment_id )
        {
            if ( ! $this->has_locations() ) {
                return false;
            }

            $object = $this->object( $attachment_id );

            return $object && $this->sdk()->cloud_bound( $object );
        }

        public function get_file_size( $attachment_id )
        {
            $object = $this->object( $attachment_id );

            if ( $object && $this->sdk()->in_storage( $object ) ) {
                return (int) $object->size;
            }

            $path = get_attached_file( $attachment_id );

            return $path && file_exists( $path ) ? (int) filesize( $path ) : 0;
        }

        public function get_file_url( $attachment_id, $context = 'message' )
        {
            if ( ! $this->has_locations() ) {
                return null;
            }

            $object = $this->object( $attachment_id );

            if ( $object && $this->sdk()->in_storage( $object ) ) {
                $public = $this->sdk()->public_link( $object );

                if ( $public ) {
                    return $public;
                }

                if ( in_array( $context, array( 'admin', 'email' ), true ) ) {
                    $signed = $this->signed_url( $attachment_id, 'inline', 604800 );

                    if ( $signed ) {
                        return $signed['url'];
                    }
                }

                return Better_Messages_Files()->get_proxy_url( (int) $attachment_id );
            }

            if ( $object && $this->sdk()->cloud_bound( $object ) ) {
                return Better_Messages_Files()->get_proxy_url( (int) $attachment_id );
            }

            return null;
        }

        public function filter_attachment_url( $url, $attachment_id, $message_id, $thread_id )
        {
            $cloud_url = $this->get_file_url( (int) $attachment_id );

            return $cloud_url ? $cloud_url : $url;
        }

        public function signed_url_window( $window, $object )
        {
            return is_object( $object ) && $object->plugin === self::PLUGIN ? apply_filters( 'better_messages_storage_signed_url_bucket', $window, (int) $object->ref ) : $window;
        }

        public function signed_url_ttl( $ttl, $object )
        {
            return is_object( $object ) && $object->plugin === self::PLUGIN ? apply_filters( 'better_messages_storage_signed_url_ttl', $ttl, (int) $object->ref ) : $ttl;
        }

        public function signed_url( $attachment_id, $variant = 'inline', $ttl = null )
        {
            $object = $this->object( $attachment_id );

            if ( ! $object || ! $this->sdk()->in_storage( $object ) ) {
                return null;
            }

            $custom = apply_filters( 'better_messages_storage_signed_url', null, $this->get_location( $object->location ), (string) $object->object_key, $variant, 0, $ttl, (int) $attachment_id );

            if ( $custom ) {
                return array( 'url' => $custom, 'expires' => time() + ( $ttl ? (int) $ttl : self::SIGN_TTL ) );
            }

            return $this->sdk()->signed_url( $object, $variant, $ttl );
        }

        public function file_link( $attachment_id )
        {
            $object = $this->object( $attachment_id );

            if ( ! $object || ! $this->sdk()->in_storage( $object ) ) {
                return null;
            }

            $location = $this->get_location( (string) $object->location );

            if ( ! $location || WordPlus_SDK_Storage::is_public( $location ) ) {
                return null;
            }

            $signed = $this->signed_url( $attachment_id, 'inline' );

            if ( ! $signed || empty( $signed['url'] ) ) {
                return null;
            }

            return array( 'url' => $signed['url'], 'expires' => (int) $signed['expires'] );
        }

        public function message_file_links( $meta, $message_id, $thread_id, $content )
        {
            if ( ! is_array( $meta ) || empty( $meta['files'] ) || ! is_array( $meta['files'] ) || ! $this->has_locations() ) {
                return $meta;
            }

            $ids = array();

            foreach ( $meta['files'] as $file ) {
                if ( is_array( $file ) && ! empty( $file['id'] ) && ! isset( $file['link'] ) && ! array_key_exists( (int) $file['id'], $this->row_cache ) ) {
                    $ids[] = (int) $file['id'];
                }
            }

            $this->prime( $ids );

            foreach ( $meta['files'] as $index => $file ) {
                if ( ! is_array( $file ) || empty( $file['id'] ) || isset( $file['link'] ) ) {
                    continue;
                }

                $link = $this->file_link( (int) $file['id'] );

                if ( $link ) {
                    $meta['files'][ $index ]['link'] = $link;
                }
            }

            return $meta;
        }

        public function rest_api_init()
        {
            if ( ! $this->has_locations() ) {
                return;
            }

            register_rest_route( 'better-messages/v1', '/files/links', array(
                'methods'             => 'POST',
                'callback'            => array( $this, 'rest_file_links' ),
                'permission_callback' => array( Better_Messages_Rest_Api(), 'is_user_authorized' ),
            ) );
        }

        public function rest_file_links( WP_REST_Request $request )
        {
            $user_id = (int) Better_Messages()->functions->get_current_user_id();
            $ids     = array_slice( array_values( array_unique( array_filter( array_map( 'intval', (array) $request->get_param( 'ids' ) ) ) ) ), 0, 100 );
            $links   = array();

            if ( $user_id !== 0 && ! empty( $ids ) ) {
                update_meta_cache( 'post', $ids );
                $this->prime( $ids );

                foreach ( $ids as $attachment_id ) {
                    if ( Better_Messages_Files()->access_error( $attachment_id, $user_id ) ) {
                        continue;
                    }

                    $link = $this->file_link( $attachment_id );

                    if ( $link ) {
                        $links[ $attachment_id ] = $link;
                    }
                }
            }

            return array(
                'now'   => time(),
                'links' => (object) $links,
            );
        }

        public function serve_cloud_file( $attachment_id, WP_REST_Request $request )
        {
            $variant = $request->get_param( 'download' ) ? 'download' : 'inline';
            $signed  = $this->signed_url( $attachment_id, $variant );

            if ( ! $signed ) {
                return new WP_Error(
                    'rest_not_found',
                    _x( 'File not found.', 'File Proxy Error', 'bp-better-messages' ),
                    array( 'status' => 404 )
                );
            }

            $max_age = ! empty( $signed['window_end'] ) ? max( 0, $signed['window_end'] - time() ) : 86400;

            if ( $request->get_param( 'format' ) === 'json' ) {
                $response = new WP_REST_Response( array(
                    'url'     => $signed['url'],
                    'expires' => $signed['expires'],
                ) );
                $response->header( 'Cache-Control', 'private, max-age=' . $max_age );

                return $response;
            }

            while ( ob_get_level() ) {
                ob_end_clean();
            }

            header( 'Cache-Control: private, max-age=' . $max_age );
            header( 'Referrer-Policy: no-referrer' );
            header( 'Location: ' . $signed['url'], true, 302 );
            exit;
        }

        public function get_local_copy( $attachment_id, $max_bytes = 0 )
        {
            $object = $this->object( $attachment_id );

            if ( $object && $this->sdk()->in_storage( $object ) ) {
                $copy = $this->sdk()->local_copy( $object, $max_bytes );

                return is_wp_error( $copy ) ? new WP_Error( str_replace( 'wordplus_storage', 'bm_storage', $copy->get_error_code() ), $copy->get_error_message() ) : $copy;
            }

            $path = get_attached_file( $attachment_id );

            if ( ! $path || ! file_exists( $path ) ) {
                return new WP_Error( 'bm_storage_missing', 'File not found' );
            }

            if ( $max_bytes > 0 && filesize( $path ) > $max_bytes ) {
                return new WP_Error( 'bm_storage_too_large', 'File is too large' );
            }

            return array( 'path' => $path, 'cleanup' => function () {} );
        }

        public function storage_sent( $object, $location_id )
        {
            global $wpdb;

            if ( ! is_object( $object ) || $object->plugin !== self::PLUGIN ) {
                return;
            }

            $attachment_id = (int) $object->ref;

            unset( $this->row_cache[ $attachment_id ] );
            clean_post_cache( $attachment_id );

            $message_ids = array_filter( array_map( 'intval', (array) get_post_meta( $attachment_id, 'bp-better-messages-message-id' ) ) );

            if ( empty( $message_ids ) || ! $this->file_link( $attachment_id ) ) {
                return;
            }

            $since = Better_Messages()->functions->get_microtime() - 900 * 10000;

            $messages = $wpdb->get_results( $wpdb->prepare(
                "SELECT `id`, `thread_id` FROM `" . bm_get_table('messages') . "` WHERE `id` IN (" . implode( ',', $message_ids ) . ") AND `created_at` > %d",
                $since
            ) );

            foreach ( $messages as $message ) {
                Better_Messages()->functions->update_message_update_time( (int) $message->id );
                do_action( 'better_messages_message_meta_updated', (int) $message->thread_id, (int) $message->id, 'files', null );
            }
        }

        public function storage_relocated( $plugins )
        {
            if ( in_array( self::PLUGIN, (array) $plugins, true ) ) {
                $this->row_cache = array();
                update_option( 'bm_local_db_generation', (int) get_option( 'bm_local_db_generation', 1 ) + 1, false );
            }
        }

        public function storage_readonly( $type )
        {
            $routes  = $this->routes();
            $changed = false;

            foreach ( $routes as $group => $route ) {
                if ( $route === $type ) {
                    $routes[ $group ] = 'local';
                    $changed          = true;
                }
            }

            if ( $changed ) {
                $this->save_routes( $routes );
            }
        }

        public function storage_uses( $uses )
        {
            $uses = is_array( $uses ) ? $uses : array();

            foreach ( $this->routes() as $group => $type ) {
                $uses[ $type ][ self::PLUGIN ][] = self::group_label( $group );
            }

            return $uses;
        }

        public function storage_can_move( $can, $object )
        {
            if ( ! $can || ! is_object( $object ) || $object->plugin !== self::PLUGIN ) {
                return $can;
            }

            $waiting = (int) get_post_meta( (int) $object->ref, 'better-messages-waiting-for-message', true );

            if ( $waiting > 0 && $waiting > time() - 2 * HOUR_IN_SECONDS ) {
                return false;
            }

            $this->sync_local_path( $object );

            return true;
        }

        public function storage_origins( $origins )
        {
            if ( Better_Messages()->mobile_app ) {
                $origins['capacitor://localhost'] = true;
                $origins['https://localhost']     = true;
            }

            return $origins;
        }

        public function storage_browser_reads( $reads )
        {
            return $reads || ( class_exists( 'Better_Messages_E2E_Encryption' ) && Better_Messages()->settings['e2eEncryption'] === '1' );
        }

        public function storage_adopt( $legacy )
        {
            $stored = get_option( self::OPTION, array() );

            if ( ! is_array( $stored ) || empty( $stored['locations'] ) || ! is_array( $stored['locations'] ) ) {
                return $legacy;
            }

            $current = isset( $stored['current'] ) && is_array( $stored['current'] ) ? $stored['current'] : array();

            if ( empty( $current ) ) {
                foreach ( array( 'current', 'active' ) as $key ) {
                    $id = isset( $stored[ $key ] ) && is_string( $stored[ $key ] ) ? $stored[ $key ] : '';

                    if ( $id !== '' && isset( $stored['locations'][ $id ]['preset'] ) ) {
                        $current[ $stored['locations'][ $id ]['preset'] ] = $id;
                    }
                }
            }

            $locations = $stored['locations'];

            foreach ( $locations as $id => $location ) {
                if ( is_array( $location ) && ! isset( $location['direct'] ) ) {
                    $locations[ $id ]['direct'] = isset( $stored['direct'] ) && $stored['direct'] === 'off' ? '0' : '1';
                }
            }

            return array(
                'current'   => $current,
                'locations' => $locations,
                'site_id'   => (string) get_option( self::SITE_OPTION, '' ),
            );
        }

        public function storage_legacy_markers( $names )
        {
            $names   = (array) $names;
            $names[] = self::MARKER_NAME;

            return $names;
        }

        public function storage_sweep_patterns( $patterns )
        {
            $patterns   = (array) $patterns;
            $patterns[] = '#^\d{4}/\d{2}/#';

            return $patterns;
        }

        public function storage_known_keys( $known, $keys )
        {
            global $wpdb;

            $keys = array_values( array_filter( array_map( 'strval', (array) $keys ) ) );

            if ( empty( $keys ) ) {
                return $known;
            }

            $placeholders = implode( ',', array_fill( 0, count( $keys ), '%s' ) );

            return array_merge( (array) $known, $wpdb->get_col( $wpdb->prepare(
                "SELECT DISTINCT `meta_value` FROM {$wpdb->postmeta} WHERE `meta_key` = %s AND `meta_value` IN ({$placeholders})",
                array_merge( array( self::META_KEY ), $keys )
            ) ) );
        }

        public function storage_index( $budget )
        {
            if ( ! $this->backfill_done() ) {
                $this->backfill( max( 1, (int) $budget ) );
            }
        }

        public function storage_indexed( $indexed )
        {
            return $indexed && $this->backfill_done();
        }

        public function backfill_state()
        {
            $state = get_option( self::BACKFILL_OPTION, array() );

            return wp_parse_args( is_array( $state ) ? $state : array(), array(
                'last_id' => 0,
                'indexed' => 0,
                'done'    => false,
            ) );
        }

        public function backfill_done()
        {
            return ! empty( $this->backfill_state()['done'] );
        }

        public function schedule_job()
        {
            if ( $this->sdk() && ( ! $this->backfill_done() || $this->has_locations() ) ) {
                $this->sdk()->schedule( 60 );
            }
        }

        public function backfill( $budget = 10, $batch = 500 )
        {
            global $wpdb;

            if ( ! $this->table_exists() ) {
                return false;
            }

            $state = $this->backfill_state();

            if ( $state['done'] ) {
                return true;
            }

            $started = microtime( true );

            do {
                $ids = array_map( 'intval', $wpdb->get_col( $wpdb->prepare(
                    "SELECT p.ID FROM {$wpdb->posts} p
                     INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = 'bp-better-messages-attachment'
                     WHERE p.post_type = 'attachment' AND p.ID > %d
                     ORDER BY p.ID ASC LIMIT %d",
                    (int) $state['last_id'], (int) $batch
                ) ) );

                if ( empty( $ids ) ) {
                    $state['done'] = true;
                    break;
                }

                $this->index_attachments( $ids );

                $state['last_id'] = max( $ids );
                $state['indexed'] = (int) $state['indexed'] + count( $ids );

                update_option( self::BACKFILL_OPTION, $state, false );
            } while ( microtime( true ) - $started < $budget );

            update_option( self::BACKFILL_OPTION, $state, false );

            $this->row_cache = array();

            return $state['done'];
        }

        public function index_attachments( array $ids )
        {
            global $wpdb;

            $ids = array_values( array_filter( array_map( 'intval', $ids ) ) );

            if ( empty( $ids ) ) {
                return 0;
            }

            $in    = implode( ',', $ids );
            $posts = $wpdb->get_results( "SELECT ID, post_mime_type, post_date_gmt FROM {$wpdb->posts} WHERE ID IN ({$in})", OBJECT_K );

            $keys = array(
                '_wp_attached_file', '_wp_attachment_metadata', 'bp-better-messages-thread-id', 'bp-better-messages-message-id',
                'bp-better-messages-uploader-user-id', 'bp-better-messages-original-name', 'bm-e2e-original-mime',
                'bp-better-messages-bulk-job-id', self::META_LOCATION, self::META_KEY, self::META_SIZE,
            );

            $meta = array();

            foreach ( $wpdb->get_results( "SELECT post_id, meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id IN ({$in}) AND meta_key IN ('" . implode( "','", array_map( 'esc_sql', $keys ) ) . "') ORDER BY meta_id ASC" ) as $item ) {
                if ( ! isset( $meta[ $item->post_id ][ $item->meta_key ] ) ) {
                    $meta[ $item->post_id ][ $item->meta_key ] = $item->meta_value;
                }
            }

            $media = array();

            foreach ( $wpdb->get_results( "SELECT meta_key, meta_value FROM " . bm_get_table( 'meta' ) . " WHERE meta_key IN ('bpbm_voice_messages','bpbm_video_messages') AND meta_value IN ('" . implode( "','", $ids ) . "')" ) as $item ) {
                $media[ (int) $item->meta_value ] = $item->meta_key === 'bpbm_video_messages' ? 'video' : 'voice';
            }

            $uploads = wp_upload_dir( null, false );
            $base    = trailingslashit( $this->base_dir_name() );
            $now     = time();
            $files   = array();
            $values  = array();

            foreach ( $ids as $id ) {
                if ( ! isset( $posts[ $id ] ) ) {
                    continue;
                }

                $m        = isset( $meta[ $id ] ) ? $meta[ $id ] : array();
                $file     = isset( $m['_wp_attached_file'] ) ? (string) $m['_wp_attached_file'] : '';
                $location = isset( $m[ self::META_LOCATION ] ) ? (string) $m[ self::META_LOCATION ] : '';
                $size     = isset( $m[ self::META_SIZE ] ) ? (int) $m[ self::META_SIZE ] : 0;

                if ( $size === 0 && ! empty( $m['_wp_attachment_metadata'] ) ) {
                    $data = maybe_unserialize( $m['_wp_attachment_metadata'] );

                    if ( is_array( $data ) && ! empty( $data['filesize'] ) ) {
                        $size = (int) $data['filesize'];
                    }
                }

                if ( $size === 0 && $location === '' && $file !== '' ) {
                    $path = trailingslashit( $uploads['basedir'] ) . $file;

                    if ( file_exists( $path ) ) {
                        $size = (int) filesize( $path );
                    }
                }

                $name = isset( $m['bp-better-messages-original-name'] ) && $m['bp-better-messages-original-name'] !== '' ? (string) $m['bp-better-messages-original-name'] : wp_basename( $file );
                $e2e  = ! empty( $m['bm-e2e-original-mime'] ) || strtolower( pathinfo( $file, PATHINFO_EXTENSION ) ) === 'enc';

                if ( $e2e ) {
                    $kind = 'e2e';
                } else if ( ! empty( $m['bp-better-messages-bulk-job-id'] ) ) {
                    $kind = 'bulk';
                } else if ( isset( $media[ $id ] ) ) {
                    $kind = $media[ $id ];
                } else {
                    $kind = 'file';
                }

                $mime    = (string) $posts[ $id ]->post_mime_type;
                $created = strtotime( $posts[ $id ]->post_date_gmt . ' UTC' );
                $created = $created ? $created : $now;

                $files[] = array(
                    'ref'        => $id,
                    'path'       => $file,
                    'key'        => strpos( $file, $base ) === 0 ? substr( $file, strlen( $base ) ) : ltrim( $file, '/' ),
                    'name'       => $name,
                    'type'       => $e2e ? 'application/octet-stream' : self::stored_type( $mime ),
                    'size'       => $size,
                    'user'       => isset( $m['bp-better-messages-uploader-user-id'] ) ? (int) $m['bp-better-messages-uploader-user-id'] : 0,
                    'created'    => $created,
                    'location'   => $location,
                    'object_key' => isset( $m[ self::META_KEY ] ) ? (string) $m[ self::META_KEY ] : '',
                );

                $values[] = $wpdb->prepare(
                    '(%d, %d, %d, %d, %d, %s, %s, %d, %s, %d, %d)',
                    get_current_blog_id(),
                    $id,
                    isset( $m['bp-better-messages-thread-id'] ) ? (int) $m['bp-better-messages-thread-id'] : 0,
                    isset( $m['bp-better-messages-message-id'] ) ? (int) $m['bp-better-messages-message-id'] : 0,
                    isset( $m['bp-better-messages-uploader-user-id'] ) ? (int) $m['bp-better-messages-uploader-user-id'] : 0,
                    mb_substr( $name, 0, 255 ),
                    mb_substr( $mime, 0, 100 ),
                    $size,
                    $kind,
                    $created,
                    $now
                );
            }

            if ( empty( $values ) ) {
                return 0;
            }

            if ( $this->sdk() ) {
                $this->sdk()->add_many( self::PLUGIN, $files );
            }

            $wpdb->query(
                "INSERT IGNORE INTO `{$this->table()}`
                (`blog_id`, `attachment_id`, `thread_id`, `message_id`, `uploader_id`, `name`, `mime`, `size`, `kind`, `created_at`, `updated_at`)
                VALUES " . implode( ',', $values )
            );

            if ( $this->sdk() && $this->sdk()->table_exists() ) {
                $wpdb->query( $wpdb->prepare(
                    "UPDATE `{$this->table()}` f INNER JOIN `{$this->sdk()->table()}` o ON o.`blog_id` = f.`blog_id` AND o.`plugin` = %s AND o.`ref` = f.`attachment_id` AND o.`variant` = ''
                     SET f.`object_id` = o.`id` WHERE f.`blog_id` = %d AND f.`attachment_id` IN ({$in})",
                    self::PLUGIN, get_current_blog_id()
                ) );
            }

            return count( $values );
        }
    }

endif;

function Better_Messages_Storage()
{
    return Better_Messages_Storage::instance();
}
