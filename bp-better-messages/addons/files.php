<?php
defined( 'ABSPATH' ) || exit;

if ( !class_exists( 'Better_Messages_Files' ) ):

    class Better_Messages_Files
    {

        public static function instance()
        {
            static $instance = null;

            if ( null === $instance ) {
                $instance = new Better_Messages_Files();
            }

            return $instance;
        }

        public function __construct()
        {
            /**
             * Modify message before save
             */
            add_filter( 'bp_better_messages_pre_format_message', array( $this, 'nice_files' ), 90, 4 );
            add_action( 'better_messages_cleaner_job', array($this, 'remove_old_attachments') );
            add_filter( 'better_messages_rest_message_meta', array( $this, 'files_message_meta'), 10, 4 );

            if ( Better_Messages()->settings['attachmentsEnable'] === '1' ) {
                add_action( 'rest_api_init',  array( $this, 'rest_api_init' ) );
                add_action( 'wp_enqueue_scripts', array( $this, 'load_scripts' ), 9 );
                add_action( 'better_messages_register_script_dependencies', array($this, 'load_scripts'), 10, 1);
                add_filter( 'bp_better_messages_script_variable', array( $this, 'attachments_script_vars' ), 10, 1 );

                add_filter( 'rest_pre_dispatch', array( $this, 'intercept_tus_requests' ), 10, 3 );
                add_filter( 'rest_pre_serve_request', array( $this, 'add_tus_headers' ), 10, 4 );
                add_action( 'better_messages_cleaner_job', array( $this, 'cleanup_stale_uploads' ) );

                if ( Better_Messages()->settings['attachmentsProxy'] === '1' ) {
                    add_filter( 'better_messages_attachment_url', array( $this, 'proxy_attachment_url' ), 10, 4 );
                }
            }

            add_action( 'bp_better_chat_settings_updated', array($this, 'create_index_file') );
            add_action( 'bp_better_chat_settings_updated', array( $this, 'update_htaccess_protection' ) );
        }

        private string $subfolder = '';
        private string $tus_version = '1.0.0';

        const HTACCESS_CONTENT = "Options -Indexes\n";
        const INDEX_CONTENT = "<?php\n// Silence is golden.";

        public bool $scripts_loaded = false;

        public function load_scripts( $context ){
            if( $this->scripts_loaded ) return;
            $this->scripts_loaded = true;

            $is_dev = defined( 'BM_DEV' );

            $version = Better_Messages()->version;
            $suffix = ( $is_dev ? '' : '.min' );

            $deps = [
                'better-messages-files-image-editor',
                'better-messages-files-react'
            ];

            if( Better_Messages()->settings['attachmentsAllowPhoto'] == '1' ) {
                wp_register_script(
                    'better-messages-files-webcam',
                    Better_Messages()->url . "assets/js/addons/files/webcam{$suffix}.js",
                    [],
                    $version
                );

                $deps[] = 'better-messages-files-webcam';
            }

            wp_register_script(
                'better-messages-files-image-editor',
                Better_Messages()->url . "assets/js/addons/files/image-editor{$suffix}.js",
                [],
                $version
            );

            wp_register_script(
                'better-messages-files-react',
                Better_Messages()->url . "assets/js/addons/files/react{$suffix}.js",
                [],
                $version
            );

            wp_register_script(
                'better-messages-files-tus',
                Better_Messages()->url . "assets/js/addons/files/tus{$suffix}.js",
                [],
                $version
            );

            $deps[] = 'better-messages-files-tus';

            wp_register_script(
                'better-messages-files-core',
                Better_Messages()->url . "assets/js/addons/files/core{$suffix}.js",
                $deps,
                $version
            );

            add_filter('better_messages_script_dependencies', function( $deps ) {
                $deps[] = 'better-messages-files-core';
                return $deps;
            } );
        }

        public function files_message_meta( $meta, $message_id, $thread_id, $content ){
            if( $content === '<!-- BM-DELETED-MESSAGE -->' ){
                return $meta;
            }

            $attachments = Better_Messages()->functions->get_message_meta( $message_id, 'attachments', true );

            $files = [];

            if( is_array( $attachments) && count( $attachments ) > 0 ){
                foreach ( $attachments as $attachment_id => $url ) {
                    $attachment = get_post( $attachment_id );
                    if( ! $attachment ) continue;

                    $url = apply_filters('better_messages_attachment_url', $url, $attachment_id, $message_id, $thread_id );

                    $thumb_url = wp_get_attachment_image_url($attachment->ID, array(200, 200));
                    $local_path = get_attached_file( $attachment_id );
                    $file_exists_locally = $local_path && file_exists( $local_path );

                    if ( $file_exists_locally && Better_Messages()->settings['attachmentsProxy'] === '1' ) {
                        $thumb_url = $this->get_proxy_url( $attachment->ID );
                    }

                    $file = [
                        'id'       => $attachment->ID,
                        'thumb'    => $thumb_url,
                        'url'      => $url,
                        'mimeType' => $attachment->post_mime_type
                    ];

                    $size = $file_exists_locally ? filesize( $local_path ) : 0;
                    $original_url = wp_get_attachment_url( $attachment_id );
                    $ext = pathinfo( $original_url, PATHINFO_EXTENSION );
                    $name = get_post_meta($attachment_id, 'bp-better-messages-original-name', true);
                    if( empty($name) ) $name = wp_basename( $original_url );

                    $file['name']  = $name;
                    $file['size'] = $size;
                    $file['ext']  = $ext;

                    $files[] = $file;
                }
            }

            if( count( $files ) > 0 ){
                $meta['files'] = $files;
            }

            return $meta;
        }

        public function attachments_script_vars( $vars ){
            $attachments = [
                'maxSize'      => intval(Better_Messages()->settings['attachmentsMaxSize']),
                'maxItems'     => intval(Better_Messages()->settings['attachmentsMaxNumber']),
                'formats'      => array_map(function ($str) { return ".$str"; }, Better_Messages()->settings['attachmentsFormats']),
                'allowPhoto'   => (int) ( Better_Messages()->settings['attachmentsAllowPhoto'] == '1' ? '1' : '0' ),
                'tusEndpoint'  => esc_url_raw( get_rest_url( null, '/better-messages/v1/tus/' ) ),
            ];

            $vars['attachments'] = $attachments;

            return $vars;
        }

        public function rest_api_init(){
            register_rest_route('better-messages/v1', '/thread/(?P<id>\d+)/upload', array(
                'methods' => 'POST',
                'callback' => array( $this, 'handle_upload' ),
                'permission_callback' => array( $this, 'user_can_upload_callback' ),
                'args' => array(
                    'id' => array(
                        'validate_callback' => function ($param, $request, $key) {
                            return is_numeric($param);
                        }
                    ),
                ),
            ));

            if ( Better_Messages()->settings['attachmentsProxy'] === '1' ) {
                register_rest_route( 'better-messages/v1', '/file/(?P<id>\d+)', array(
                    'methods'             => 'GET',
                    'callback'            => array( $this, 'serve_proxy_file' ),
                    'permission_callback' => '__return_true',
                    'args'                => array(
                        'id' => array(
                            'validate_callback' => function ( $param ) {
                                return is_numeric( $param );
                            },
                        ),
                    ),
                ) );
            }

            // TUS protocol routes
            register_rest_route( 'better-messages/v1', '/tus/(?P<thread_id>\d+)', array(
                array(
                    'methods'             => 'POST',
                    'callback'            => array( $this, 'handle_tus_creation' ),
                    'permission_callback' => array( $this, 'check_tus_upload_permission' ),
                    'args' => array(
                        'thread_id' => array(
                            'validate_callback' => function ( $param ) {
                                return is_numeric( $param );
                            }
                        ),
                    ),
                ),
            ));

            register_rest_route( 'better-messages/v1', '/tus(?:/(?P<thread_id>\d+))?(?:/(?P<upload_id>[a-f0-9-]+))?', array(
                array(
                    'methods'             => 'OPTIONS',
                    'callback'            => array( $this, 'handle_tus_options' ),
                    'permission_callback' => '__return_true',
                ),
            ));

            register_rest_route( 'better-messages/v1', '/tus/(?P<thread_id>\d+)/(?P<upload_id>[a-f0-9-]+)', array(
                array(
                    'methods'             => 'DELETE',
                    'callback'            => array( $this, 'handle_tus_delete_upload' ),
                    'permission_callback' => array( $this, 'check_tus_upload_permission' ),
                ),
            ));

            register_rest_route( 'better-messages/v1/admin', '/testProxyMethod', array(
                'methods'             => 'POST',
                'callback'            => array( $this, 'test_proxy_method' ),
                'permission_callback' => function() {
                    return current_user_can( 'manage_options' );
                },
            ) );
        }

        public function remove_old_attachments(){
            // Removing attachments which were uploaded, but not attached to message
            global $wpdb;

            $sql = $wpdb->prepare( "SELECT `posts`.ID
            FROM {$wpdb->posts} `posts`
            INNER JOIN {$wpdb->postmeta} `meta`
                ON ( `posts`.ID = `meta`.post_id )
            WHERE  `meta`.meta_key = 'better-messages-waiting-for-message'
            AND `meta`.meta_value <= %d
            AND `posts`.`post_type` = 'attachment'
            LIMIT 0, 50", strtotime("-2 hours") );

            $expired_attachments = $wpdb->get_col( $sql );
            if( count( $expired_attachments ) > 0 ){
                foreach ( $expired_attachments as $attachment_id ){
                    $file_path = get_attached_file( $attachment_id );
                    wp_delete_attachment($attachment_id, true);
                    if ( $file_path ) {
                        $this->cleanup_empty_directories( $file_path );
                    }
                }
            }

            // Removing old uploaded attachments
            $delete_after_days = (int) Better_Messages()->settings['attachmentsRetention'];
            if( $delete_after_days < 1 ) {
                return;
            }

            $delete_after = $delete_after_days * 24 * 60 * 60;
            $delete_after_time = time() - $delete_after;

            $sql = $wpdb->prepare("SELECT {$wpdb->posts}.ID
            FROM {$wpdb->posts}
            INNER JOIN {$wpdb->postmeta}
            ON ( {$wpdb->posts}.ID = {$wpdb->postmeta}.post_id )
            INNER JOIN {$wpdb->postmeta} AS mt1
            ON ( {$wpdb->posts}.ID = mt1.post_id )
            WHERE 1=1
            AND ( ( {$wpdb->postmeta}.meta_key = 'bp-better-messages-attachment'
            AND {$wpdb->postmeta}.meta_value = '1' )
            AND ( mt1.meta_key = 'bp-better-messages-upload-time'
            AND mt1.meta_value < %d ) )
            AND {$wpdb->posts}.post_type = 'attachment'
            AND (({$wpdb->posts}.post_status = 'inherit'))
            GROUP BY {$wpdb->posts}.ID
            ORDER BY {$wpdb->posts}.post_date DESC
            LIMIT 0, 50", $delete_after_time);

            $old_attachments = $wpdb->get_col( $sql );

            foreach($old_attachments as $attachment){
                $this->remove_attachment($attachment);
            }
        }

        public function remove_attachment($attachment_id){
            global $wpdb;
            $message_id = get_post_meta($attachment_id, 'bp-better-messages-message-id', true);
            if( ! $message_id ) return false;

            // Get Message
            $table = bm_get_table('messages');
            $message_attachments = Better_Messages()->functions->get_message_meta($message_id, 'attachments', true);

            $file_path = get_attached_file( $attachment_id );
            wp_delete_attachment($attachment_id, true);
            if ( $file_path ) {
                $this->cleanup_empty_directories( $file_path );
            }

            /**
             * Deleting attachment from message
             */
            if( isset( $message_attachments[$attachment_id] ) ) {
                $message = $wpdb->get_row( $wpdb->prepare("SELECT * FROM `{$table}` WHERE `id` = %d", $message_id) );

                if( ! $message ){
                    Better_Messages()->functions->delete_all_message_meta($message_id);
                    return true;
                }

                $content = str_replace( $message_attachments[$attachment_id], '', $message->message );

                if( empty( trim( $content ) ) ){
                    Better_Messages()->functions->delete_all_message_meta($message_id);
                    $wpdb->delete($table, array('id' => $message_id));
                } else {
                    unset($message_attachments[$attachment_id]);
                    Better_Messages()->functions->update_message_meta($message_id, 'attachments', $message_attachments);
                    $wpdb->update($table, array('message' => $content), array('id' => $message_id));
                }
            }

            return true;

        }

        public function nice_files( $message, $message_id, $context, $user_id )
        {
            if( $context === 'email' || $context === 'mobile_app' ) {
                if( class_exists('Better_Messages_Voice_Messages') ){
                    $is_voice_message = Better_Messages()->functions->get_message_meta( $message_id, 'bpbm_voice_messages', true );

                    if ( ! empty( $is_voice_message ) ) {
                        return __('Voice Message', 'bp-better-messages');
                    }
                }
            }

            $attachments = Better_Messages()->functions->get_message_meta( $message_id, 'attachments', true );

            $desc = false;
            if( is_array($attachments) ) {
                if (count($attachments) > 0) {
                    $desc = '';

                    if( $context !== 'mobile_app' ){
                        $desc .= "<i class=\"fas fa-file\"></i> ";
                    } else {
                        $desc .= "\n";
                        $message = str_replace("<!-- BM-ONLY-FILES -->", "", $message);
                    }

                    $desc .= count($attachments) . " " . __('attachments', 'bp-better-messages');
                }
            }

            if ( $context !== 'stack' ) {
                if( $desc !== false ){
                    foreach ( $attachments as $attachment ){
                        $message = str_replace($attachment, '', $message);
                    }

                    if( ! empty( trim($message) ) ){
                        $message .= "";
                    }

                    $message .= $desc;
                }

                return $message;
            }

            if ( !empty( $attachments ) ) {
                foreach ( $attachments as $attachment_id => $url ) {
                    $message = str_replace( array( $url . "\n", "" . $url, $url ), '', $message );
                }

            }

            return $message;
        }

        public function get_archive_extensions(){
            return array(
                "7z",
                "a",
                "apk",
                "ar",
                "cab",
                "cpio",
                "deb",
                "dmg",
                "egg",
                "epub",
                "iso",
                "jar",
                "mar",
                "pea",
                "rar",
                "s7z",
                "shar",
                "tar",
                "tbz2",
                "tgz",
                "tlz",
                "war",
                "whl",
                "xpi",
                "zip",
                "zipx"
            );
        }

        public function get_text_extensions(){
            return array(
                "txt", "rtf"
            );
        }

        public function random_string($length) {
            $key = '';
            $keys = array_merge(range(0, 9), range('a', 'z'));

            for ($i = 0; $i < $length; $i++) {
                $key .= $keys[array_rand($keys)];
            }

            return $key;
        }

        public function handle_delete()
        {
            $user_id       = (int) Better_Messages()->functions->get_current_user_id();
            $attachment_id = intval( $_POST[ 'file_id' ] );
            $thread_id     = intval( $_POST[ 'thread_id' ] );
            $attachment    = get_post( $attachment_id );

            $has_access = Better_Messages()->functions->check_access( $thread_id, $user_id );

            if( $thread_id === 0 ){
                $has_access = true;
            }
            // Security verify 1
            if ( ( ! $has_access && ! current_user_can('manage_options') ) ||
                ! wp_verify_nonce( $_POST[ 'nonce' ], 'file-delete-' . $thread_id ) ||
                ( (int) $attachment->post_author !== $user_id ) || ! $attachment
            ) {
                wp_send_json( false );
                exit;
            }

            // Security verify 2
            if ( (int) get_post_meta( $attachment->ID, 'bp-better-messages-thread-id', true ) !== $thread_id ) {
                wp_send_json( false );
                exit;
            }

            // Looks like we can delete it now!
            $file_path = get_attached_file( $attachment->ID );
            $result = wp_delete_attachment( $attachment->ID, true );
            if ( $result ) {
                if ( $file_path ) {
                    $this->cleanup_empty_directories( $file_path );
                }
                wp_send_json( true );
            } else {
                wp_send_json( false );
            }

            exit;
        }

        public function create_index_file()
        {
            $upload_dir = wp_upload_dir();
            $base_dir_name = apply_filters( 'bp_better_messages_upload_dir_name', 'bp-better-messages' );
            $base_path = trailingslashit( $upload_dir['basedir'] ) . $base_dir_name;

            if ( ! is_dir( $base_path ) ) {
                wp_mkdir_p( $base_path );
            }

            $this->protect_root_directory( $base_path );
        }

        /**
         * Ensures the root upload directory has .htaccess and index.php protection.
         */
        public function protect_root_directory( string $dir_path ): void {
            $dir_path = trailingslashit( $dir_path );

            $htaccess_content = self::HTACCESS_CONTENT;
            if ( Better_Messages()->settings['attachmentsProxy'] === '1' ) {
                $htaccess_content = "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nOrder deny,allow\nDeny from all\n</IfModule>\n";
            }

            @file_put_contents( $dir_path . '.htaccess', $htaccess_content );

            if ( ! file_exists( $dir_path . 'index.php' ) ) {
                @file_put_contents( $dir_path . 'index.php', self::INDEX_CONTENT );
            }
        }

        /**
         * Protects the root directory with .htaccess and adds directory listing protection to subdirectories.
         */
        public function protect_upload_hierarchy(): void {
            $upload_dir = wp_upload_dir();
            $base_dir_name = apply_filters( 'bp_better_messages_upload_dir_name', 'bp-better-messages' );
            $base_path = trailingslashit( $upload_dir['basedir'] ) . $base_dir_name;

            $this->protect_root_directory( $base_path );

            if ( $this->subfolder !== '' ) {
                $parts = array_filter( explode( '/', $this->subfolder ) );
                $current = $base_path;
                foreach ( $parts as $part ) {
                    $current = trailingslashit( $current ) . $part;
                    if ( is_dir( $current ) ) {
                        $dir = trailingslashit( $current );
                        if ( ! file_exists( $dir . '.htaccess' ) ) {
                            @file_put_contents( $dir . '.htaccess', self::HTACCESS_CONTENT );
                        }
                        if ( ! file_exists( $dir . 'index.php' ) ) {
                            @file_put_contents( $dir . 'index.php', self::INDEX_CONTENT );
                        }
                    }
                }
            }
        }

        /**
         * After a file is deleted, walk up the directory tree removing empty directories.
         * Stops at the bp-better-messages root directory.
         */
        public function cleanup_empty_directories( string $file_path ): void {
            $upload_dir = wp_upload_dir();
            $base_dir_name = apply_filters( 'bp_better_messages_upload_dir_name', 'bp-better-messages' );
            $stop_at = trailingslashit( $upload_dir['basedir'] ) . $base_dir_name;

            $dir = dirname( $file_path );

            while ( $dir !== $stop_at && strlen( $dir ) > strlen( $stop_at ) ) {
                if ( ! is_dir( $dir ) ) {
                    break;
                }

                $entries = @scandir( $dir );
                if ( $entries === false ) {
                    break;
                }

                // Filter out ., .., .htaccess, and index.php (protection files only)
                $real_entries = array_filter( $entries, function( $entry ) {
                    return ! in_array( $entry, [ '.', '..', '.htaccess', 'index.php' ], true );
                });

                if ( count( $real_entries ) > 0 ) {
                    break;
                }

                @unlink( trailingslashit( $dir ) . '.htaccess' );
                @unlink( trailingslashit( $dir ) . 'index.php' );

                if ( ! @rmdir( $dir ) ) {
                    break;
                }

                $dir = dirname( $dir );
            }
        }

        /**
         * Move an attachment from the /{year}/{month}/0/ directory to /{year}/{month}/{thread_id}/.
         * Used when a file is uploaded before the thread exists.
         */
        public function relocate_attachment_to_thread( int $attachment_id, int $thread_id ): bool {
            $old_path = get_attached_file( $attachment_id );
            if ( ! $old_path || ! file_exists( $old_path ) ) {
                return false;
            }

            // Capture URL before modifying anything
            $old_url = wp_get_attachment_url( $attachment_id );

            $upload_dir    = wp_upload_dir();
            $base_dir_name = apply_filters( 'bp_better_messages_upload_dir_name', 'bp-better-messages' );
            $base_path     = trailingslashit( $upload_dir['basedir'] ) . $base_dir_name;

            // Get relative path after base: {year}/{month}/0/{uuid}/{filename}
            $base_path_slash = trailingslashit( $base_path );
            if ( strpos( $old_path, $base_path_slash ) !== 0 ) {
                return false;
            }
            $relative = substr( $old_path, strlen( $base_path_slash ) );

            // Verify path matches {year}/{month}/0/{uuid}/{filename}
            if ( ! preg_match( '#^(\d{4}/\d{2})/0/(.+)$#', $relative, $matches ) ) {
                return false;
            }

            $date_part = $matches[1]; // e.g. "2026/02"
            $after_zero = $matches[2]; // e.g. "{uuid}/{filename}"

            // Build new path: {year}/{month}/{thread_id}/{uuid}/{filename}
            $new_relative = $date_part . '/' . $thread_id . '/' . $after_zero;
            $new_path = $base_path_slash . $new_relative;
            $new_dir = dirname( $new_path );

            if ( ! wp_mkdir_p( $new_dir ) ) {
                return false;
            }

            // Move file, with copy+unlink fallback for cross-filesystem moves
            if ( ! @rename( $old_path, $new_path ) ) {
                if ( ! @copy( $old_path, $new_path ) || ! @unlink( $old_path ) ) {
                    @unlink( $new_path );
                    return false;
                }
            }

            // Update WordPress attachment metadata
            update_attached_file( $attachment_id, $new_path );

            // Update GUID using the old URL captured before path change
            if ( $old_url ) {
                $old_url_relative = $date_part . '/0/' . $after_zero;
                $new_url_relative = $date_part . '/' . $thread_id . '/' . $after_zero;

                $new_url = str_replace( $old_url_relative, $new_url_relative, $old_url );
                if ( $new_url !== $old_url ) {
                    wp_update_post( array(
                        'ID'   => $attachment_id,
                        'guid' => $new_url,
                    ) );
                }
            }

            clean_post_cache( $attachment_id );

            // Protect the new directory hierarchy
            $this->set_subfolder( '/' . $new_relative );
            $this->protect_upload_hierarchy();
            $this->reset_subfolder();

            // Clean up old empty directories
            $this->cleanup_empty_directories( $old_path );

            return true;
        }

        /**
         * Set the subfolder path for uploads.
         */
        public function set_subfolder( string $subfolder ): void {
            $this->subfolder = $subfolder;
        }

        /**
         * Reset the subfolder path.
         */
        public function reset_subfolder(): void {
            $this->subfolder = '';
        }

        public function upload_dir($dir){
            $dirName = apply_filters('bp_better_messages_upload_dir_name', 'bp-better-messages');

            if( $this->subfolder !== '' ){
                $dirName .= $this->subfolder;
            }

            return array(
                'path'   => $dir['basedir'] . '/' . $dirName,
                'url'    => $dir['baseurl'] . '/' . $dirName,
                'subdir' => '/' . $dirName
            ) + $dir;
        }

        public function upload_mimes($mimes, $user){
            $allowedExtensions = Better_Messages()->settings['attachmentsFormats'];
            $allowed = array();


            foreach( wp_get_mime_types() as $extensions => $mime_type ){
                $key = array();

                foreach(explode('|', $extensions) as $ext){
                    if( in_array($ext, $allowedExtensions) ) $key[] = $ext;
                }

                if( ! empty($key) ){
                    $key = implode('|', $key);
                    $allowed[$key] = $mime_type;

                    if( str_contains( $key, 'jpg' ) || str_contains( $key, 'jpe' ) ){
                        $allowed['webp'] = 'image/webp';
                    }
                }
            }

            return $allowed;
        }

        public function save_file( $file, $message_id, $user_id )
        {
            $message = Better_Messages()->functions->get_message( $message_id );

            if( ! $message ){
                return new WP_Error( 'better_messages_error_message', 'Message does not exist' );
            }

            $thread_id = $message->thread_id;

            $this->subfolder = '/' . date('Y') . '/' . date('m') . '/' . $thread_id . '/' . wp_generate_uuid4();

            add_filter( 'upload_dir', array( $this, 'upload_dir' ) );
            add_filter( 'upload_mimes', array( $this, 'upload_mimes' ), 10, 2 );

            try {
                // These files need to be included as dependencies when on the front end.
                require_once ABSPATH . 'wp-admin/includes/image.php';
                require_once ABSPATH . 'wp-admin/includes/file.php';
                require_once ABSPATH . 'wp-admin/includes/media.php';

                $name = wp_basename($file['name']);

                $file['name'] = sanitize_file_name( $name );

                add_filter('intermediate_image_sizes', '__return_empty_array');
                $attachment_id = media_handle_sideload($file, 0);
                remove_filter('intermediate_image_sizes', '__return_empty_array');

                if ( is_wp_error($attachment_id) ) {
                    return $attachment_id;
                }

                add_post_meta($attachment_id, 'bp-better-messages-message-id', $message_id, true);
                add_post_meta($attachment_id, 'bp-better-messages-attachment', true, true);
                add_post_meta($attachment_id, 'bp-better-messages-thread-id', $thread_id, true);
                add_post_meta($attachment_id, 'bp-better-messages-uploader-user-id', $user_id, true);
                add_post_meta($attachment_id, 'bp-better-messages-upload-time', time(), true);
                add_post_meta($attachment_id, 'bp-better-messages-original-name', $name, true);

                return $attachment_id;
            } finally {
                remove_filter( 'upload_dir', array( $this, 'upload_dir' ) );
                remove_filter( 'upload_mimes', array( $this, 'upload_mimes' ), 10 );

                $this->protect_upload_hierarchy();
                $this->subfolder = '';
            }
        }

        public function handle_upload( WP_REST_Request $request )
        {
            $user_id    = Better_Messages()->functions->get_current_user_id();
            $thread_id  = intval($request->get_param('id'));

            $this->subfolder = '/' . date('Y') . '/' . date('m') . '/' . $thread_id . '/' . wp_generate_uuid4();

            add_filter( 'upload_dir', array( $this, 'upload_dir' ) );
            add_filter( 'upload_mimes', array( $this, 'upload_mimes' ), 10, 2 );

            $result = array(
                'result' => false,
                'error'  => ''
            );

            $files = $request->get_file_params();

            if ( isset( $files['file']) && ! empty( $files[ 'file' ] ) ) {

                $file = $files['file'];

                $extensions = apply_filters( 'bp_better_messages_attachment_allowed_extensions', Better_Messages()->settings['attachmentsFormats'], $thread_id, $user_id );

                $extension = pathinfo( $file['name'], PATHINFO_EXTENSION );

                if ( empty( $extension ) ) {
                    return new WP_Error(
                        'rest_forbidden',
                        _x( 'Sorry, you are not allowed to upload this file type', 'File Uploader Error', 'bp-better-messages' ),
                        array( 'status' => rest_authorization_required_code() )
                    );
                }

                $name = wp_basename($file['name']);

                $_FILES['file']['name'] = sanitize_file_name( $name );

                if( ! in_array( strtolower($extension), $extensions ) ){
                    return new WP_Error(
                        'rest_forbidden',
                        _x( 'Sorry, you are not allowed to upload this file type', 'File Uploader Error', 'bp-better-messages' ),
                        array( 'status' => rest_authorization_required_code() )
                    );
                }

                $maxSizeMb = apply_filters( 'bp_better_messages_attachment_max_size', Better_Messages()->settings['attachmentsMaxSize'], $thread_id, $user_id );

                $maxSize = $maxSizeMb * 1024 * 1024;

                if ( $file['size'] > $maxSize ) {
                    return new WP_Error(
                        'rest_upload_failed',
                        sprintf( _x( '%s is too large! Please upload file up to %d MB.', 'File Uploader Error', 'bp-better-messages' ), $file['name'], $maxSizeMb ),
                        array( 'status' => 413 )
                    );
                }

                // These files need to be included as dependencies when on the front end.
                require_once( ABSPATH . 'wp-admin/includes/image.php' );
                require_once( ABSPATH . 'wp-admin/includes/file.php' );
                require_once( ABSPATH . 'wp-admin/includes/media.php' );

                add_filter( 'intermediate_image_sizes', '__return_empty_array' );
                $attachment_id = media_handle_upload( 'file', 0 );
                remove_filter( 'intermediate_image_sizes', '__return_empty_array' );

                if ( is_wp_error( $attachment_id ) ) {
                    // There was an error uploading the image.
                    status_header( 400 );
                    $result[ 'error' ] = $attachment_id->get_error_message();
                } else {
                    // The image was uploaded successfully!
                    add_post_meta( $attachment_id, 'bp-better-messages-attachment', true, true );
                    add_post_meta( $attachment_id, 'bp-better-messages-thread-id', $thread_id, true );
                    add_post_meta( $attachment_id, 'bp-better-messages-uploader-user-id', $user_id, true );
                    add_post_meta( $attachment_id, 'bp-better-messages-upload-time', time(), true );
                    add_post_meta( $attachment_id, 'bp-better-messages-original-name', $name, true );
                    add_post_meta( $attachment_id, 'better-messages-waiting-for-message', time(), true );

                    $result[ 'id' ] = $attachment_id;

                    status_header( 200 );
                }
            } else {
                status_header( 406 );
                $result[ 'error' ] = _x( 'Your request is empty.', 'File Uploader Error', 'bp-better-messages' );
            }

            remove_filter( 'upload_dir', array( $this, 'upload_dir' ) );
            remove_filter( 'upload_mimes', array( $this, 'upload_mimes' ), 10 );

            $this->protect_upload_hierarchy();
            $this->subfolder = '';

            if( $result['error'] ){
                return new WP_Error(
                    'rest_upload_failed',
                    $result['error'],
                    array( 'status' => 403 )
                );
            }

            return $result;
        }

        public function user_can_upload( $user_id, $thread_id ) {
            if ( Better_Messages()->settings['attachmentsEnable'] !== '1' ) return false;

            if( $thread_id === 0 ) return true;

            return apply_filters( 'bp_better_messages_user_can_upload_files', Better_Messages()->functions->check_access( $thread_id, $user_id, 'reply' ), $user_id, $thread_id );
        }

        public function user_can_upload_callback(WP_REST_Request $request) {
            if ( Better_Messages()->settings['attachmentsEnable'] !== '1' ) return false;

            if( ! Better_Messages_Rest_Api()->is_user_authorized( $request ) ){
                return false;
            }

            $user_id    = Better_Messages()->functions->get_current_user_id();

            $thread_id  = intval($request->get_param('id'));

            if( $thread_id === 0 ) return true;

            $can_upload = apply_filters( 'bp_better_messages_user_can_upload_files', Better_Messages()->functions->check_access( $thread_id, $user_id, 'reply' ), $user_id, $thread_id );

            if ( ! $can_upload ) {
                return new WP_Error(
                    'rest_forbidden',
                    _x( 'Sorry, you are not allowed to upload files', 'File Uploader Error', 'bp-better-messages' ),
                    array( 'status' => rest_authorization_required_code() )
                );
            }

            return $can_upload;
        }

        /**
         * File Proxy: Base64url encode.
         */
        private function base64url_encode( string $data ): string {
            return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' );
        }

        /**
         * File Proxy: Base64url decode.
         */
        private function base64url_decode( string $data ): string {
            return base64_decode( strtr( $data, '-_', '+/' ) );
        }

        /**
         * File Proxy: Validate a JWT-like file access token.
         *
         * @param string $token      The token from the request.
         * @param int    $attachment_id The attachment ID from the URL (needed for per-file guest tokens).
         * @return int|false User ID if valid, false otherwise.
         */
        public function validate_file_access_token( string $token, int $attachment_id = 0 ) {
            $parts = explode( '.', $token, 2 );
            if ( count( $parts ) !== 2 ) {
                return false;
            }

            $payload_b64 = $parts[0];
            $sig_b64     = $parts[1];

            $payload_json = $this->base64url_decode( $payload_b64 );
            $payload      = json_decode( $payload_json, true );

            if ( ! $payload || ! isset( $payload['uid'] ) ) {
                return false;
            }

            // Require token expiration
            if ( ! isset( $payload['iat'] ) ) {
                return false;
            }
            if ( time() - intval( $payload['iat'] ) > 86400 ) {
                return false;
            }

            $user_id = intval( $payload['uid'] );

            if ( $user_id === 0 ) {
                return false;
            }

            if ( $user_id < 0 ) {
                // Guest: per-file token — HMAC message includes attachment_id
                if ( $attachment_id <= 0 ) {
                    return false;
                }
                $guest = Better_Messages()->guests->get_guest_user( $user_id );
                if ( ! $guest || empty( $guest->secret ) ) {
                    return false;
                }
                $expected_sig = $this->base64url_encode(
                    hash_hmac( 'sha256', $payload_b64 . '.' . $attachment_id, $guest->secret, true )
                );
                if ( hash_equals( $expected_sig, $sig_b64 ) ) {
                    return $user_id;
                }
                return false;
            }

            // Regular user: per-user token — HMAC message is payload only
            $user = get_userdata( $user_id );
            if ( ! $user ) {
                return false;
            }

            $secret_key   = Better_Messages()->functions->get_user_secret_key( $user_id );
            $signing_key  = hash_hmac( 'sha256', $secret_key, wp_salt( 'auth' ) );
            $expected_sig = $this->base64url_encode(
                hash_hmac( 'sha256', $payload_b64, $signing_key, true )
            );

            if ( hash_equals( $expected_sig, $sig_b64 ) ) {
                return $user_id;
            }

            return false;
        }

        /**
         * File Proxy: Build the proxy URL for an attachment.
         */
        public function get_proxy_url( int $attachment_id ): string {
            return get_rest_url( null, 'better-messages/v1/file/' . $attachment_id );
        }

        /**
         * File Proxy: Filter callback to replace direct attachment URLs with proxy URLs.
         */
        public function proxy_attachment_url( $url, $attachment_id, $message_id, $thread_id ): string {
            $file_path = get_attached_file( $attachment_id );
            if ( ! $file_path || ! file_exists( $file_path ) ) {
                return $url;
            }
            return $this->get_proxy_url( (int) $attachment_id );
        }

        /**
         * File Proxy: Serve the file through the proxy with auth and access checks.
         */
        public function serve_proxy_file( WP_REST_Request $request ) {
            $attachment_id = intval( $request->get_param( 'id' ) );
            $token         = $request->get_param( 'token' );

            if ( empty( $token ) ) {
                return new WP_Error(
                    'rest_forbidden',
                    _x( 'Authentication required.', 'File Proxy Error', 'bp-better-messages' ),
                    array( 'status' => 401 )
                );
            }

            // Validate user token
            $user_id = $this->validate_file_access_token( $token, $attachment_id );
            if ( ! $user_id ) {
                return new WP_Error(
                    'rest_forbidden',
                    _x( 'Invalid file access token.', 'File Proxy Error', 'bp-better-messages' ),
                    array( 'status' => 401 )
                );
            }

            // Verify this is a Better Messages attachment
            $is_bm_attachment = get_post_meta( $attachment_id, 'bp-better-messages-attachment', true );
            if ( empty( $is_bm_attachment ) ) {
                return new WP_Error(
                    'rest_not_found',
                    _x( 'File not found.', 'File Proxy Error', 'bp-better-messages' ),
                    array( 'status' => 404 )
                );
            }

            if ( ! ( $user_id > 0 && user_can( $user_id, 'manage_options' ) ) ) {
                // Check thread access
                $thread_id = (int) get_post_meta( $attachment_id, 'bp-better-messages-thread-id', true );
                if ( $thread_id <= 0 ) {
                    return new WP_Error(
                        'rest_forbidden',
                        _x( 'File access denied.', 'File Proxy Error', 'bp-better-messages' ),
                        array( 'status' => 403 )
                    );
                }

                $has_access = Better_Messages()->functions->check_access( $thread_id, $user_id );
                if ( ! $has_access ) {
                    return new WP_Error(
                        'rest_forbidden',
                        _x( 'You do not have access to this conversation.', 'File Proxy Error', 'bp-better-messages' ),
                        array( 'status' => 403 )
                    );
                }
            }

            // Get local file path
            $file_path = get_attached_file( $attachment_id );
            if ( ! $file_path || ! file_exists( $file_path ) ) {
                return new WP_Error(
                    'rest_not_found',
                    _x( 'File not found on disk. It may have been moved to cloud storage.', 'File Proxy Error', 'bp-better-messages' ),
                    array( 'status' => 404 )
                );
            }

            $attachment = get_post( $attachment_id );
            if ( ! $attachment ) {
                return new WP_Error(
                    'rest_not_found',
                    _x( 'File not found.', 'File Proxy Error', 'bp-better-messages' ),
                    array( 'status' => 404 )
                );
            }

            $mime_type = $attachment->post_mime_type;
            $file_size = filesize( $file_path );
            $file_name = get_post_meta( $attachment_id, 'bp-better-messages-original-name', true );
            if ( empty( $file_name ) ) {
                $file_name = wp_basename( $file_path );
            }

            // ETag and Last-Modified for caching
            $last_modified = filemtime( $file_path );
            $etag          = '"' . md5( $file_path . $last_modified . $file_size ) . '"';

            // Handle 304 Not Modified
            $if_none_match     = isset( $_SERVER['HTTP_IF_NONE_MATCH'] ) ? trim( $_SERVER['HTTP_IF_NONE_MATCH'] ) : '';
            $if_modified_since = isset( $_SERVER['HTTP_IF_MODIFIED_SINCE'] ) ? strtotime( $_SERVER['HTTP_IF_MODIFIED_SINCE'] ) : 0;

            if ( ( $if_none_match && $if_none_match === $etag ) ||
                 ( $if_modified_since && $if_modified_since >= $last_modified ) ) {
                status_header( 304 );
                exit;
            }

            // Determine Content-Disposition: inline for media, attachment for others
            $inline_types = array( 'image/', 'video/', 'audio/', 'application/pdf' );
            $disposition  = 'attachment';
            foreach ( $inline_types as $type ) {
                if ( substr( $mime_type, 0, strlen( $type ) ) === $type ) {
                    $disposition = 'inline';
                    break;
                }
            }

            // Send headers
            header( 'Content-Type: ' . $mime_type );
            header( 'Content-Length: ' . $file_size );
            $safe_name = str_replace( array( '"', "\r", "\n" ), '', $file_name );
            header( 'Content-Disposition: ' . $disposition . '; filename="' . $safe_name . '"' );
            header( 'Last-Modified: ' . gmdate( 'D, d M Y H:i:s', $last_modified ) . ' GMT' );
            header( 'ETag: ' . $etag );
            header( 'Cache-Control: private, max-age=86400' );
            header( 'Accept-Ranges: none' );
            header( 'X-Content-Type-Options: nosniff' );
            header( 'Referrer-Policy: no-referrer' );

            // Clean output buffers
            while ( ob_get_level() ) {
                ob_end_clean();
            }

            set_time_limit( 0 );

            $method = Better_Messages()->settings['attachmentsProxyMethod'];

            switch ( $method ) {
                case 'xsendfile':
                    header( 'X-Sendfile: ' . $file_path );
                    exit;

                case 'xaccel':
                    $prefix = Better_Messages()->settings['attachmentsXAccelPrefix'];
                    if ( empty( $prefix ) ) {
                        $prefix = '/bm-files/';
                    }
                    $prefix = trailingslashit( $prefix );

                    $upload_dir = wp_upload_dir();
                    $base_dir = trailingslashit( $upload_dir['basedir'] );

                    if ( strpos( $file_path, $base_dir ) === 0 ) {
                        $relative_path = substr( $file_path, strlen( $base_dir ) );
                    } else {
                        $relative_path = wp_basename( dirname( $file_path ) ) . '/' . wp_basename( $file_path );
                    }

                    header_remove( 'Content-Length' );
                    header( 'X-Accel-Redirect: ' . $prefix . $relative_path );
                    exit;

                case 'litespeed':
                    header( 'X-LiteSpeed-Location: ' . $file_path );
                    exit;

                case 'php':
                default:
                    readfile( $file_path );
                    exit;
            }
        }

        /**
         * Test proxy file serving method from admin settings.
         */
        public function test_proxy_method( WP_REST_Request $request ) {
            $method = $request->get_param( 'method' );

            if ( ! in_array( $method, array( 'php', 'xsendfile', 'xaccel', 'litespeed' ), true ) ) {
                return new WP_Error( 'invalid_method', 'Invalid method', array( 'status' => 400 ) );
            }

            $upload_dir    = wp_upload_dir();
            $base_dir_name = apply_filters( 'bp_better_messages_upload_dir_name', 'bp-better-messages' );
            $temp_dir      = trailingslashit( $upload_dir['basedir'] ) . $base_dir_name . '/.tus-temp';

            if ( ! is_dir( $temp_dir ) ) {
                wp_mkdir_p( $temp_dir );
            }

            $test_file = trailingslashit( $temp_dir ) . 'proxy-test-' . wp_generate_uuid4() . '.txt';
            $test_content = 'BM_PROXY_TEST_OK';

            if ( file_put_contents( $test_file, $test_content ) === false ) {
                return new WP_Error( 'write_error', 'Failed to create test file', array( 'status' => 500 ) );
            }

            header( 'Content-Type: text/plain' );
            header( 'Content-Length: ' . strlen( $test_content ) );
            header( 'Cache-Control: no-store' );

            while ( ob_get_level() ) {
                ob_end_clean();
            }

            switch ( $method ) {
                case 'xsendfile':
                    header( 'X-Sendfile: ' . $test_file );
                    // Delay deletion so the web server can read the file
                    register_shutdown_function( function() use ( $test_file ) {
                        sleep( 1 );
                        @unlink( $test_file );
                    });
                    exit;

                case 'xaccel':
                    $prefix = $request->get_param( 'xaccel_prefix' );
                    if ( empty( $prefix ) ) {
                        $prefix = '/bm-files/';
                    }
                    $prefix = trailingslashit( $prefix );

                    $base_dir = trailingslashit( $upload_dir['basedir'] );
                    if ( strpos( $test_file, $base_dir ) === 0 ) {
                        $relative_path = substr( $test_file, strlen( $base_dir ) );
                    } else {
                        $relative_path = wp_basename( $test_file );
                    }

                    header_remove( 'Content-Length' );
                    header( 'X-Accel-Redirect: ' . $prefix . $relative_path );
                    register_shutdown_function( function() use ( $test_file ) {
                        sleep( 1 );
                        @unlink( $test_file );
                    });
                    exit;

                case 'litespeed':
                    header( 'X-LiteSpeed-Location: ' . $test_file );
                    register_shutdown_function( function() use ( $test_file ) {
                        sleep( 1 );
                        @unlink( $test_file );
                    });
                    exit;

                case 'php':
                default:
                    readfile( $test_file );
                    @unlink( $test_file );
                    exit;
            }
        }

        /**
         * File Proxy: Update .htaccess in the root upload directory when settings are saved.
         */
        public function update_htaccess_protection( $settings ) {
            $upload_dir    = wp_upload_dir();
            $base_dir_name = apply_filters( 'bp_better_messages_upload_dir_name', 'bp-better-messages' );
            $base_path     = trailingslashit( $upload_dir['basedir'] ) . $base_dir_name;

            if ( ! is_dir( $base_path ) ) {
                return;
            }

            if ( isset( $settings['attachmentsProxy'] ) && $settings['attachmentsProxy'] === '1' ) {
                $htaccess_content = "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nOrder deny,allow\nDeny from all\n</IfModule>\n";
            } else {
                $htaccess_content = self::HTACCESS_CONTENT;
            }

            @file_put_contents( trailingslashit( $base_path ) . '.htaccess', $htaccess_content );
        }

        /**
         * Get the temporary directory for in-progress TUS uploads.
         */
        private function get_tus_temp_dir(): string {
            $upload_dir = wp_upload_dir();
            $base_dir_name = apply_filters( 'bp_better_messages_upload_dir_name', 'bp-better-messages' );
            $temp = trailingslashit( $upload_dir['basedir'] ) . $base_dir_name . '/.tus-temp';

            if ( ! is_dir( $temp ) ) {
                wp_mkdir_p( $temp );

                @file_put_contents( trailingslashit( $temp ) . '.htaccess', "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nOrder deny,allow\nDeny from all\n</IfModule>\n" );
                @file_put_contents( trailingslashit( $temp ) . 'index.php', "<?php\n// Silence is golden." );
            }

            return $temp;
        }

        /**
         * Intercept HEAD and PATCH requests that WordPress REST API doesn't handle natively.
         */
        public function intercept_tus_requests( $result, $server, $request ) {
            $route = $request->get_route();
            $method = $request->get_method();

            if ( ! preg_match( '#^/better-messages/v1/tus/(\d+)/([a-f0-9-]+)$#', $route, $matches ) ) {
                return $result;
            }

            if ( $method !== 'HEAD' && $method !== 'PATCH' ) {
                return $result;
            }

            $permission = $this->check_tus_upload_permission( $request );
            if ( is_wp_error( $permission ) || $permission === false ) {
                return new WP_Error(
                    'rest_forbidden',
                    _x( 'Sorry, you are not allowed to upload files', 'File Uploader Error', 'bp-better-messages' ),
                    array( 'status' => rest_authorization_required_code() )
                );
            }

            $request->set_param( 'thread_id', $matches[1] );
            $request->set_param( 'upload_id', $matches[2] );

            if ( $method === 'HEAD' ) {
                return $this->handle_tus_head( $request );
            }

            if ( $method === 'PATCH' ) {
                return $this->handle_tus_patch( $request );
            }

            return $result;
        }

        /**
         * Add TUS-specific headers to responses.
         */
        public function add_tus_headers( $served, $result, $request, $server ) {
            $route = $request->get_route();

            if ( strpos( $route, '/better-messages/v1/tus' ) === false ) {
                return $served;
            }

            header( 'Tus-Resumable: ' . $this->tus_version );
            header( 'Access-Control-Expose-Headers: Upload-Offset, Upload-Length, Location, Tus-Resumable, Tus-Version, Tus-Extension, Tus-Max-Size, X-BM-Attachment-Id' );
            header( 'Access-Control-Allow-Headers: Content-Type, Upload-Offset, Upload-Length, Upload-Metadata, Tus-Resumable, X-WP-Nonce, X-Requested-With, Authorization, Cache-Control, Pragma, Expires, BM-Guest-ID, BM-Guest-Secret' );
            header( 'Access-Control-Allow-Methods: POST, GET, HEAD, PATCH, DELETE, OPTIONS' );

            return $served;
        }

        /**
         * Permission callback for TUS upload endpoints.
         */
        public function check_tus_upload_permission( WP_REST_Request $request ) {
            if ( ! Better_Messages_Rest_Api()->is_user_authorized( $request ) ) {
                return false;
            }

            $user_id   = Better_Messages()->functions->get_current_user_id();
            $thread_id = intval( $request->get_param( 'thread_id' ) );

            return $this->user_can_upload( $user_id, $thread_id );
        }

        /**
         * TUS OPTIONS handler - capability discovery.
         */
        public function handle_tus_options( WP_REST_Request $request ) {
            $response = new WP_REST_Response( null, 204 );
            $response->header( 'Tus-Resumable', $this->tus_version );
            $response->header( 'Tus-Version', $this->tus_version );
            $response->header( 'Tus-Extension', 'creation,termination' );

            $max_size = intval( Better_Messages()->settings['attachmentsMaxSize'] ) * 1024 * 1024;
            $response->header( 'Tus-Max-Size', (string) $max_size );

            return $response;
        }

        /**
         * TUS POST handler - Create a new upload.
         */
        public function handle_tus_creation( WP_REST_Request $request ) {
            $user_id   = Better_Messages()->functions->get_current_user_id();
            $thread_id = intval( $request->get_param( 'thread_id' ) );

            $upload_length = $request->get_header( 'upload_length' );
            if ( $upload_length === null || ! is_numeric( $upload_length ) ) {
                return new WP_Error(
                    'tus_missing_upload_length',
                    'Upload-Length header is required',
                    array( 'status' => 400 )
                );
            }

            $upload_length = intval( $upload_length );

            $maxSizeMb = apply_filters( 'bp_better_messages_attachment_max_size', Better_Messages()->settings['attachmentsMaxSize'], $thread_id, $user_id );
            $maxSize = $maxSizeMb * 1024 * 1024;

            if ( $upload_length > $maxSize ) {
                return new WP_Error(
                    'tus_file_too_large',
                    sprintf( _x( 'File is too large! Please upload file up to %d MB.', 'File Uploader Error', 'bp-better-messages' ), $maxSizeMb ),
                    array( 'status' => 413 )
                );
            }

            $metadata = $this->parse_tus_metadata( $request->get_header( 'upload_metadata' ) );

            $filename = isset( $metadata['filename'] ) ? $metadata['filename'] : '';
            $filetype = isset( $metadata['filetype'] ) ? $metadata['filetype'] : '';

            if ( empty( $filename ) ) {
                return new WP_Error(
                    'tus_missing_filename',
                    'filename is required in Upload-Metadata',
                    array( 'status' => 400 )
                );
            }

            $extension = strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) );
            if ( empty( $extension ) ) {
                return new WP_Error(
                    'rest_forbidden',
                    _x( 'Sorry, you are not allowed to upload this file type', 'File Uploader Error', 'bp-better-messages' ),
                    array( 'status' => rest_authorization_required_code() )
                );
            }

            $extensions = apply_filters( 'bp_better_messages_attachment_allowed_extensions', Better_Messages()->settings['attachmentsFormats'], $thread_id, $user_id );
            if ( ! in_array( $extension, $extensions, true ) ) {
                return new WP_Error(
                    'rest_forbidden',
                    _x( 'Sorry, you are not allowed to upload this file type', 'File Uploader Error', 'bp-better-messages' ),
                    array( 'status' => rest_authorization_required_code() )
                );
            }

            $upload_id = wp_generate_uuid4();

            $meta = array(
                'upload_id'  => $upload_id,
                'thread_id'  => $thread_id,
                'user_id'    => $user_id,
                'filename'   => sanitize_file_name( $filename ),
                'filetype'   => sanitize_mime_type( $filetype ),
                'filesize'   => $upload_length,
                'offset'     => 0,
                'created_at' => time(),
                'expires_at' => time() + DAY_IN_SECONDS,
            );

            $temp_dir = $this->get_tus_temp_dir();
            $meta_file = trailingslashit( $temp_dir ) . $upload_id . '.json';
            $part_file = trailingslashit( $temp_dir ) . $upload_id . '.part';

            if ( file_put_contents( $meta_file, wp_json_encode( $meta ) ) === false ) {
                return new WP_Error(
                    'tus_write_error',
                    'Failed to create upload metadata',
                    array( 'status' => 500 )
                );
            }

            if ( file_put_contents( $part_file, '' ) === false ) {
                @unlink( $meta_file );
                return new WP_Error(
                    'tus_write_error',
                    'Failed to create upload file',
                    array( 'status' => 500 )
                );
            }

            $location = get_rest_url( null, '/better-messages/v1/tus/' . $thread_id . '/' . $upload_id );

            $response = new WP_REST_Response( null, 201 );
            $response->header( 'Location', $location );
            $response->header( 'Tus-Resumable', $this->tus_version );
            $response->header( 'Upload-Offset', '0' );

            return $response;
        }

        /**
         * TUS HEAD handler - Get current upload offset for resume.
         */
        public function handle_tus_head( WP_REST_Request $request ) {
            $upload_id = sanitize_text_field( $request->get_param( 'upload_id' ) );
            $user_id   = Better_Messages()->functions->get_current_user_id();

            $meta = $this->get_tus_upload_meta( $upload_id );
            if ( ! $meta ) {
                return new WP_Error( 'tus_not_found', 'Upload not found', array( 'status' => 404 ) );
            }

            if ( (int) $meta['user_id'] !== $user_id ) {
                return new WP_Error( 'rest_forbidden', 'Unauthorized', array( 'status' => 403 ) );
            }

            $response = new WP_REST_Response( null, 200 );
            $response->header( 'Upload-Offset', (string) $meta['offset'] );
            $response->header( 'Upload-Length', (string) $meta['filesize'] );
            $response->header( 'Tus-Resumable', $this->tus_version );
            $response->header( 'Cache-Control', 'no-store' );

            return $response;
        }

        /**
         * TUS PATCH handler - Receive upload chunk data.
         */
        public function handle_tus_patch( WP_REST_Request $request ) {
            $upload_id = sanitize_text_field( $request->get_param( 'upload_id' ) );
            $user_id   = Better_Messages()->functions->get_current_user_id();
            $thread_id = intval( $request->get_param( 'thread_id' ) );

            $meta = $this->get_tus_upload_meta( $upload_id );
            if ( ! $meta ) {
                return new WP_Error( 'tus_not_found', 'Upload not found', array( 'status' => 404 ) );
            }

            if ( (int) $meta['user_id'] !== $user_id ) {
                return new WP_Error( 'rest_forbidden', 'Unauthorized', array( 'status' => 403 ) );
            }

            $content_type = $request->get_content_type();
            if ( ! $content_type || $content_type['value'] !== 'application/offset+octet-stream' ) {
                return new WP_Error(
                    'tus_invalid_content_type',
                    'Content-Type must be application/offset+octet-stream',
                    array( 'status' => 415 )
                );
            }

            $client_offset = $request->get_header( 'upload_offset' );
            if ( $client_offset === null || ! is_numeric( $client_offset ) ) {
                return new WP_Error(
                    'tus_missing_offset',
                    'Upload-Offset header is required',
                    array( 'status' => 400 )
                );
            }

            $client_offset = intval( $client_offset );
            if ( $client_offset !== (int) $meta['offset'] ) {
                return new WP_Error(
                    'tus_offset_mismatch',
                    'Upload-Offset does not match current offset',
                    array( 'status' => 409 )
                );
            }

            $temp_dir = $this->get_tus_temp_dir();
            $part_file = trailingslashit( $temp_dir ) . $upload_id . '.part';

            if ( ! file_exists( $part_file ) ) {
                return new WP_Error( 'tus_not_found', 'Upload file not found', array( 'status' => 404 ) );
            }

            $input = fopen( 'php://input', 'rb' );
            if ( ! $input ) {
                return new WP_Error( 'tus_read_error', 'Failed to read request body', array( 'status' => 500 ) );
            }

            $output = fopen( $part_file, 'ab' );
            if ( ! $output ) {
                fclose( $input );
                return new WP_Error( 'tus_write_error', 'Failed to open upload file', array( 'status' => 500 ) );
            }

            $bytes_written = 0;
            while ( ! feof( $input ) ) {
                $chunk = fread( $input, 8192 );
                if ( $chunk === false ) {
                    break;
                }
                $written = fwrite( $output, $chunk );
                if ( $written === false ) {
                    fclose( $input );
                    fclose( $output );
                    return new WP_Error( 'tus_write_error', 'Failed to write data', array( 'status' => 500 ) );
                }
                $bytes_written += $written;
            }

            fclose( $input );
            fclose( $output );

            $new_offset = $client_offset + $bytes_written;
            $meta['offset'] = $new_offset;

            $meta_file = trailingslashit( $temp_dir ) . $upload_id . '.json';
            file_put_contents( $meta_file, wp_json_encode( $meta ) );

            if ( $new_offset >= (int) $meta['filesize'] ) {
                $attachment_id = $this->finalize_tus_upload( $meta );

                @unlink( $meta_file );
                if ( file_exists( $part_file ) ) {
                    @unlink( $part_file );
                }

                if ( is_wp_error( $attachment_id ) ) {
                    return $attachment_id;
                }

                $response = new WP_REST_Response( null, 204 );
                $response->header( 'Upload-Offset', (string) $new_offset );
                $response->header( 'Tus-Resumable', $this->tus_version );
                $response->header( 'X-BM-Attachment-Id', (string) $attachment_id );

                return $response;
            }

            $response = new WP_REST_Response( null, 204 );
            $response->header( 'Upload-Offset', (string) $new_offset );
            $response->header( 'Tus-Resumable', $this->tus_version );

            return $response;
        }

        /**
         * TUS DELETE handler - Cancel and remove an in-progress upload.
         */
        public function handle_tus_delete_upload( WP_REST_Request $request ) {
            $upload_id = sanitize_text_field( $request->get_param( 'upload_id' ) );
            $user_id   = Better_Messages()->functions->get_current_user_id();

            $meta = $this->get_tus_upload_meta( $upload_id );
            if ( ! $meta ) {
                return new WP_Error( 'tus_not_found', 'Upload not found', array( 'status' => 404 ) );
            }

            if ( (int) $meta['user_id'] !== $user_id ) {
                return new WP_Error( 'rest_forbidden', 'Unauthorized', array( 'status' => 403 ) );
            }

            $temp_dir = $this->get_tus_temp_dir();
            @unlink( trailingslashit( $temp_dir ) . $upload_id . '.json' );
            @unlink( trailingslashit( $temp_dir ) . $upload_id . '.part' );

            return new WP_REST_Response( null, 204 );
        }

        /**
         * Finalize a completed TUS upload — create WordPress attachment.
         */
        private function finalize_tus_upload( array $meta ) {
            $thread_id = (int) $meta['thread_id'];
            $user_id   = (int) $meta['user_id'];
            $filename  = $meta['filename'];
            $filetype  = $meta['filetype'];

            $temp_dir  = $this->get_tus_temp_dir();
            $part_file = trailingslashit( $temp_dir ) . $meta['upload_id'] . '.part';

            $extension = strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) );
            $extensions = apply_filters( 'bp_better_messages_attachment_allowed_extensions', Better_Messages()->settings['attachmentsFormats'], $thread_id, $user_id );

            if ( ! in_array( $extension, $extensions, true ) ) {
                return new WP_Error(
                    'rest_forbidden',
                    _x( 'Sorry, you are not allowed to upload this file type', 'File Uploader Error', 'bp-better-messages' ),
                    array( 'status' => rest_authorization_required_code() )
                );
            }

            $uuid = wp_generate_uuid4();
            $this->set_subfolder( '/' . date('Y') . '/' . date('m') . '/' . $thread_id . '/' . $uuid );

            do_action( 'better_messages_tus_before_finalize', $meta );

            add_filter( 'upload_dir', array( $this, 'upload_dir' ) );
            add_filter( 'upload_mimes', array( $this, 'upload_mimes' ), 10, 2 );

            try {
                require_once ABSPATH . 'wp-admin/includes/image.php';
                require_once ABSPATH . 'wp-admin/includes/file.php';
                require_once ABSPATH . 'wp-admin/includes/media.php';

                $file_array = array(
                    'name'     => sanitize_file_name( $filename ),
                    'type'     => $filetype,
                    'tmp_name' => $part_file,
                    'error'    => 0,
                    'size'     => filesize( $part_file ),
                );

                add_filter( 'intermediate_image_sizes', '__return_empty_array' );
                $attachment_id = media_handle_sideload( $file_array, 0 );
                remove_filter( 'intermediate_image_sizes', '__return_empty_array' );

                if ( is_wp_error( $attachment_id ) ) {
                    return $attachment_id;
                }

                add_post_meta( $attachment_id, 'bp-better-messages-attachment', true, true );
                add_post_meta( $attachment_id, 'bp-better-messages-thread-id', $thread_id, true );
                add_post_meta( $attachment_id, 'bp-better-messages-uploader-user-id', $user_id, true );
                add_post_meta( $attachment_id, 'bp-better-messages-upload-time', time(), true );
                add_post_meta( $attachment_id, 'bp-better-messages-original-name', $meta['filename'], true );
                add_post_meta( $attachment_id, 'better-messages-waiting-for-message', time(), true );

                return $attachment_id;
            } finally {
                remove_filter( 'upload_dir', array( $this, 'upload_dir' ) );
                remove_filter( 'upload_mimes', array( $this, 'upload_mimes' ), 10 );

                $this->protect_upload_hierarchy();
                $this->reset_subfolder();
            }
        }

        /**
         * Parse TUS Upload-Metadata header.
         * Format: key1 base64value1,key2 base64value2,...
         */
        private function parse_tus_metadata( ?string $header ): array {
            $result = array();

            if ( empty( $header ) ) {
                return $result;
            }

            $pairs = explode( ',', $header );
            foreach ( $pairs as $pair ) {
                $pair = trim( $pair );
                $parts = explode( ' ', $pair, 2 );

                $key = trim( $parts[0] );
                $value = isset( $parts[1] ) ? base64_decode( trim( $parts[1] ) ) : '';

                if ( ! empty( $key ) ) {
                    $result[ $key ] = $value;
                }
            }

            return $result;
        }

        /**
         * Get TUS upload metadata from the temp directory.
         */
        private function get_tus_upload_meta( string $upload_id ): ?array {
            if ( ! preg_match( '/^[a-f0-9-]+$/', $upload_id ) ) {
                return null;
            }

            $temp_dir = $this->get_tus_temp_dir();
            $meta_file = trailingslashit( $temp_dir ) . $upload_id . '.json';

            if ( ! file_exists( $meta_file ) ) {
                return null;
            }

            $contents = file_get_contents( $meta_file );
            if ( $contents === false ) {
                return null;
            }

            $meta = json_decode( $contents, true );
            if ( ! is_array( $meta ) ) {
                return null;
            }

            return $meta;
        }

        /**
         * Cleanup stale TUS uploads (older than 24 hours).
         */
        public function cleanup_stale_uploads() {
            $temp_dir = $this->get_tus_temp_dir();

            if ( ! is_dir( $temp_dir ) ) {
                return;
            }

            $files = @scandir( $temp_dir );
            if ( ! $files ) {
                return;
            }

            foreach ( $files as $file ) {
                if ( ! str_ends_with( $file, '.json' ) ) {
                    continue;
                }

                $meta_file = trailingslashit( $temp_dir ) . $file;
                $contents = @file_get_contents( $meta_file );
                if ( $contents === false ) {
                    continue;
                }

                $meta = json_decode( $contents, true );
                if ( ! is_array( $meta ) || ! isset( $meta['expires_at'] ) ) {
                    continue;
                }

                if ( time() > (int) $meta['expires_at'] ) {
                    $upload_id = pathinfo( $file, PATHINFO_FILENAME );
                    @unlink( $meta_file );
                    @unlink( trailingslashit( $temp_dir ) . $upload_id . '.part' );
                }
            }
        }

        /**
         * Detect available web server file serving optimizations.
         */
        public static function detect_server_capabilities(): array {
            $server_software = isset( $_SERVER['SERVER_SOFTWARE'] ) ? $_SERVER['SERVER_SOFTWARE'] : '';
            $result = array(
                'server'    => 'unknown',
                'available' => array( 'php' ),
            );

            if ( stripos( $server_software, 'LiteSpeed' ) !== false || defined( 'LSCWP_V' ) ) {
                $result['server'] = 'litespeed';
                $result['available'][] = 'litespeed';
                $result['available'][] = 'xsendfile';
                return $result;
            }

            if ( stripos( $server_software, 'Apache' ) !== false || function_exists( 'apache_get_modules' ) ) {
                $result['server'] = 'apache';
                if ( function_exists( 'apache_get_modules' ) ) {
                    $modules = apache_get_modules();
                    if ( in_array( 'mod_xsendfile', $modules, true ) ) {
                        $result['available'][] = 'xsendfile';
                    }
                } else {
                    $result['available'][] = 'xsendfile';
                }
                return $result;
            }

            if ( stripos( $server_software, 'nginx' ) !== false ) {
                $result['server'] = 'nginx';
                $result['available'][] = 'xaccel';
                return $result;
            }

            return $result;
        }

    }

endif;


function Better_Messages_Files()
{
    return Better_Messages_Files::instance();
}
