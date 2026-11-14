<?php
defined( 'ABSPATH' ) || exit;

if ( ! interface_exists( 'WordPlus_SDK_Profiles_Store' ) ) {
    interface WordPlus_SDK_Profiles_Store
    {
        public function get( $visitor );

        public function put( $visitor, array $fields );

        public function forget( $visitor );
    }
}

if ( ! class_exists( 'WordPlus_SDK_Profiles_Table' ) ) {
    final class WordPlus_SDK_Profiles_Table implements WordPlus_SDK_Profiles_Store
    {
        const SCHEMA_OPTION = 'wordplus_profiles_schema';

        const SCHEMA = 1;

        private function table()
        {
            global $wpdb;

            return $wpdb->prefix . 'wordplus_profiles';
        }

        private function exists()
        {
            global $wpdb;

            return is_object( $wpdb ) && (int) get_option( self::SCHEMA_OPTION, 0 ) >= self::SCHEMA;
        }

        private function ensure()
        {
            global $wpdb;

            if ( ! is_object( $wpdb ) ) {
                return false;
            }

            if ( $this->exists() ) {
                return true;
            }

            if ( ! function_exists( 'dbDelta' ) ) {
                if ( ! is_readable( ABSPATH . 'wp-admin/includes/upgrade.php' ) ) {
                    return false;
                }

                require_once ABSPATH . 'wp-admin/includes/upgrade.php';
            }

            $table = $this->table();

            dbDelta( "CREATE TABLE {$table} (
  person bigint(20) NOT NULL,
  name varchar(100) NOT NULL DEFAULT '',
  photo varchar(80) NOT NULL DEFAULT '',
  updated_at datetime NOT NULL,
  PRIMARY KEY  (person)
) " . $wpdb->get_charset_collate() . ';' );

            if ( $table !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) ) {
                return false;
            }

            update_option( self::SCHEMA_OPTION, self::SCHEMA, false );

            return true;
        }

        public function get( $visitor )
        {
            global $wpdb;

            if ( ! $this->exists() ) {
                return null;
            }

            $row = $wpdb->get_row( $wpdb->prepare( "SELECT name, photo FROM {$this->table()} WHERE person = %d", (int) $visitor ), ARRAY_A );

            return is_array( $row ) ? array( 'name' => (string) $row['name'], 'photo' => (string) $row['photo'] ) : null;
        }

        public function put( $visitor, array $fields )
        {
            global $wpdb;

            if ( ! $this->ensure() ) {
                return false;
            }

            $now  = gmdate( 'Y-m-d H:i:s' );
            $row  = $this->get( $visitor );
            $name = array_key_exists( 'name', $fields ) ? (string) $fields['name'] : ( $row ? $row['name'] : '' );
            $pic  = array_key_exists( 'photo', $fields ) ? (string) $fields['photo'] : ( $row ? $row['photo'] : '' );

            return false !== $wpdb->query( $wpdb->prepare(
                "INSERT INTO {$this->table()} ( person, name, photo, updated_at ) VALUES ( %d, %s, %s, %s ) ON DUPLICATE KEY UPDATE name = VALUES( name ), photo = VALUES( photo ), updated_at = VALUES( updated_at )",
                (int) $visitor,
                $name,
                $pic,
                $now
            ) );
        }

        public function forget( $visitor )
        {
            global $wpdb;

            if ( $this->exists() ) {
                $wpdb->query( $wpdb->prepare( "DELETE FROM {$this->table()} WHERE person = %d", (int) $visitor ) );
            }
        }
    }
}

if ( ! class_exists( 'WordPlus_SDK_Profiles_Memory' ) ) {
    final class WordPlus_SDK_Profiles_Memory implements WordPlus_SDK_Profiles_Store
    {
        public $rows = array();

        public function get( $visitor )
        {
            return isset( $this->rows[ (int) $visitor ] ) ? $this->rows[ (int) $visitor ] : null;
        }

        public function put( $visitor, array $fields )
        {
            $row = isset( $this->rows[ (int) $visitor ] ) ? $this->rows[ (int) $visitor ] : array( 'name' => '', 'photo' => '' );

            $this->rows[ (int) $visitor ] = array_merge( $row, array_intersect_key( $fields, array( 'name' => 1, 'photo' => 1 ) ) );

            return true;
        }

        public function forget( $visitor )
        {
            unset( $this->rows[ (int) $visitor ] );
        }
    }
}

if ( ! class_exists( 'WordPlus_SDK_Profiles' ) ) {
    final class WordPlus_SDK_Profiles
    {
        const META = 'wordplus_photo';

        const FLAG = 'wordplus_profiles_photos';

        const FOLDER = 'wordplus/profiles';

        const SIZES = array( 512, 128 );

        const NAME_MAX = 100;

        const UPLOAD_MAX = 2097152;

        const PER_HOUR = 10;

        const REST_NAMESPACE = 'wordplus/v1';

        const HANDLE = 'wordplus-profiles';

        private $sdk;

        private $store = null;

        public function __construct( WordPlus_SDK $sdk )
        {
            $this->sdk = $sdk;
        }

        public function script()
        {
            if ( ! wp_script_is( self::HANDLE, 'registered' ) ) {
                wp_register_script( self::HANDLE, $this->sdk->url( 'profiles/profiles.min.js' ), $this->sdk->i18n()->deps( self::HANDLE, array( 'wp-element', 'wp-i18n' ) ), $this->sdk->asset_version( 'profiles/profiles.min.js' ), true );
            }

            if ( ! wp_style_is( self::HANDLE, 'registered' ) ) {
                wp_register_style( self::HANDLE, $this->sdk->url( 'profiles/profiles.min.css' ), array(), $this->sdk->asset_version( 'profiles/profiles.min.css' ) );
            }

            return self::HANDLE;
        }

        public function use_store( WordPlus_SDK_Profiles_Store $store )
        {
            $this->store = $store;
        }

        private function store()
        {
            if ( null === $this->store ) {
                $this->store = new WordPlus_SDK_Profiles_Table();
            }

            return $this->store;
        }

        public static function avatar_plugin()
        {
            $found = function_exists( 'get_wp_user_avatar' )
                || defined( 'SUA_PLUGIN_VERSION' )
                || defined( 'PPRESS_VERSION_NUMBER' )
                || class_exists( 'basic_user_avatars', false );

            return (bool) apply_filters( 'wordplus_profile_avatar_plugin', $found );
        }

        public function editable( $id )
        {
            $id = (int) $id;

            if ( 0 === $id ) {
                return array( 'name' => false, 'photo' => false );
            }

            $editable = array( 'name' => true, 'photo' => $id < 0 || ! self::avatar_plugin() );

            $editable = (array) apply_filters( 'wordplus_profile_editable', $editable, $id );

            return array( 'name' => ! empty( $editable['name'] ), 'photo' => ! empty( $editable['photo'] ) );
        }

        public function name( $id )
        {
            $id = (int) $id;

            if ( $id > 0 ) {
                $user = get_userdata( $id );

                return $user ? (string) $user->display_name : '';
            }

            $row = $id < 0 ? $this->store()->get( $id ) : null;

            return $row ? (string) $row['name'] : '';
        }

        public function photo( $id )
        {
            $kept = $this->kept_photo( (int) $id );

            if ( null === $kept ) {
                return null;
            }

            $base = $this->base_url();

            return array(
                'small' => $base . $kept['base'] . '-128.' . $kept['ext'],
                'large' => $base . $kept['base'] . '-512.' . $kept['ext'],
            );
        }

        private function kept_photo( $id )
        {
            if ( $id > 0 ) {
                $meta = get_user_meta( $id, self::META, true );

                return is_array( $meta ) && ! empty( $meta['base'] ) && ! empty( $meta['ext'] ) ? array( 'base' => (string) $meta['base'], 'ext' => (string) $meta['ext'] ) : null;
            }

            $row = $id < 0 ? $this->store()->get( $id ) : null;

            if ( ! $row || '' === $row['photo'] || false === strpos( $row['photo'], '.' ) ) {
                return null;
            }

            list( $base, $ext ) = explode( '.', $row['photo'], 2 );

            return array( 'base' => $base, 'ext' => $ext );
        }

        public function folder()
        {
            $uploads = wp_upload_dir( null, false );

            return empty( $uploads['basedir'] ) ? '' : trailingslashit( $uploads['basedir'] ) . self::FOLDER;
        }

        private function base_url()
        {
            $uploads = wp_upload_dir( null, false );

            return set_url_scheme( trailingslashit( (string) $uploads['baseurl'] ) . self::FOLDER . '/' );
        }

        public function present( $id )
        {
            return array(
                'id'       => (int) $id,
                'name'     => $this->name( $id ),
                'photo'    => $this->photo( $id ),
                'editable' => $this->editable( $id ),
            );
        }

        public function check_name( $name, $id )
        {
            $name = (string) $name;
            $name = wp_strip_all_tags( html_entity_decode( $name, ENT_QUOTES, 'UTF-8' ) );
            $name = trim( (string) preg_replace( '/\s+/u', ' ', sanitize_text_field( $name ) ) );

            if ( '' === $name ) {
                return new WP_Error( 'wordplus_profile_name_required', __( 'Please type a name.', 'wordplus-sdk' ), array( 'status' => 400 ) );
            }

            if ( mb_strlen( $name ) > self::NAME_MAX ) {
                /* translators: %d: the most characters a name may have */
                return new WP_Error( 'wordplus_profile_name_too_long', sprintf( __( 'The name can be up to %d characters long.', 'wordplus-sdk' ), self::NAME_MAX ), array( 'status' => 400 ) );
            }

            $allowed = apply_filters( 'wordplus_profile_name_allowed', true, $name, (int) $id );

            return is_wp_error( $allowed ) ? $allowed : $name;
        }

        public function set_name( $id, $name )
        {
            $id = (int) $id;

            if ( ! $this->editable( $id )['name'] ) {
                return new WP_Error( 'wordplus_profile_name_locked', __( 'This name cannot be changed here. It is managed elsewhere on this site.', 'wordplus-sdk' ), array( 'status' => 403 ) );
            }

            $name = $this->check_name( $name, $id );

            if ( is_wp_error( $name ) ) {
                return $name;
            }

            if ( $this->name( $id ) === $name ) {
                return true;
            }

            if ( $id > 0 ) {
                $result = wp_update_user( array( 'ID' => $id, 'display_name' => $name, 'nickname' => $name ) );

                if ( is_wp_error( $result ) ) {
                    return $result;
                }
            } else if ( ! $this->store()->put( $id, array( 'name' => $name ) ) ) {
                return new WP_Error( 'wordplus_profile_not_kept', __( 'The name could not be saved.', 'wordplus-sdk' ), array( 'status' => 500 ) );
            }

            $this->updated( $id, array( 'name' ) );

            return true;
        }

        public function set_photo( $id, $file )
        {
            $id = (int) $id;

            if ( ! $this->editable( $id )['photo'] ) {
                return new WP_Error( 'wordplus_profile_photo_locked', __( 'This photo cannot be changed here. It is managed elsewhere on this site.', 'wordplus-sdk' ), array( 'status' => 403 ) );
            }

            $limit = 'wordplus_photos_' . ( $id < 0 ? 'v' . abs( $id ) : $id );
            $count = (int) get_transient( $limit );

            if ( $count >= self::PER_HOUR ) {
                return new WP_Error( 'wordplus_profile_photo_limit', __( 'You changed your photo too many times. Please try again later.', 'wordplus-sdk' ), array( 'status' => 429 ) );
            }

            $made = $this->make_squares( (string) $file );

            if ( is_wp_error( $made ) ) {
                return $made;
            }

            set_transient( $limit, $count + 1, HOUR_IN_SECONDS );

            $old = $this->kept_photo( $id );

            if ( $id > 0 ) {
                update_user_meta( $id, self::META, array( 'base' => $made['base'], 'ext' => $made['ext'], 'at' => time() ) );
            } else if ( ! $this->store()->put( $id, array( 'photo' => $made['base'] . '.' . $made['ext'] ) ) ) {
                $this->remove_files( $made );

                return new WP_Error( 'wordplus_profile_not_kept', __( 'The photo could not be saved.', 'wordplus-sdk' ), array( 'status' => 500 ) );
            }

            if ( null !== $old ) {
                $this->remove_files( $old );
            }

            if ( ! get_option( self::FLAG ) ) {
                update_option( self::FLAG, 1, true );
            }

            $this->updated( $id, array( 'photo' ) );

            return true;
        }

        public function remove_photo( $id )
        {
            $id  = (int) $id;
            $old = $this->kept_photo( $id );

            if ( null === $old ) {
                return true;
            }

            if ( $id > 0 ) {
                delete_user_meta( $id, self::META );
            } else {
                $this->store()->put( $id, array( 'photo' => '' ) );
            }

            $this->remove_files( $old );
            $this->updated( $id, array( 'photo' ) );

            return true;
        }

        public function forget( $id )
        {
            $id  = (int) $id;
            $old = $this->kept_photo( $id );

            if ( null !== $old ) {
                $this->remove_files( $old );
            }

            if ( $id > 0 ) {
                delete_user_meta( $id, self::META );
            } else if ( $id < 0 ) {
                $this->store()->forget( $id );
            }
        }

        public function give_photo( $visitor, $user )
        {
            $visitor = (int) $visitor;
            $user    = (int) $user;
            $kept    = $this->kept_photo( $visitor );

            if ( $visitor >= 0 || $user <= 0 || null === $kept || null !== $this->kept_photo( $user ) || ! $this->editable( $user )['photo'] ) {
                return false;
            }

            update_user_meta( $user, self::META, array( 'base' => $kept['base'], 'ext' => $kept['ext'], 'at' => time() ) );
            $this->store()->put( $visitor, array( 'photo' => '' ) );

            if ( ! get_option( self::FLAG ) ) {
                update_option( self::FLAG, 1, true );
            }

            $this->updated( $user, array( 'photo' ) );

            return true;
        }

        private function updated( $id, array $fields )
        {
            do_action( 'wordplus_profile_updated', (int) $id, $fields );
        }

        private function make_squares( $file )
        {
            $bad = new WP_Error( 'wordplus_profile_photo_invalid', __( 'This file is not a supported image.', 'wordplus-sdk' ), array( 'status' => 400 ) );

            if ( '' === $file || ! is_readable( $file ) || filesize( $file ) > self::UPLOAD_MAX ) {
                return '' !== $file && is_readable( $file ) ? new WP_Error( 'wordplus_profile_photo_too_big', __( 'The photo is too big.', 'wordplus-sdk' ), array( 'status' => 413 ) ) : $bad;
            }

            $info = function_exists( 'wp_getimagesize' ) ? wp_getimagesize( $file ) : @getimagesize( $file ); // phpcs:ignore

            if ( ! is_array( $info ) || ! in_array( (int) $info[2], array( IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_GIF, IMAGETYPE_WEBP ), true ) ) {
                return $bad;
            }

            list( $width, $height ) = $info;

            if ( $width < 64 || $height < 64 || $width > 8000 || $height > 8000 || $width * $height > 40000000 ) {
                return $bad;
            }

            $folder = $this->folder();

            if ( '' === $folder || ! wp_mkdir_p( $folder ) ) {
                return new WP_Error( 'wordplus_profile_folder', __( 'The uploads folder is not writable.', 'wordplus-sdk' ), array( 'status' => 500 ) );
            }

            if ( ! file_exists( $folder . '/index.php' ) ) {
                file_put_contents( $folder . '/index.php', "<?php\n// Silence is golden.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
            }

            $prefer_gd = function ( $editors ) {
                return array_values( array_unique( array_merge( array( 'WP_Image_Editor_GD' ), (array) $editors ) ) );
            };

            add_filter( 'wp_image_editors', $prefer_gd );

            $made = $this->squares( $file, $folder, $bad );

            remove_filter( 'wp_image_editors', $prefer_gd );

            return $made;
        }

        private function squares( $file, $folder, WP_Error $bad )
        {
            $mime = function_exists( 'wp_image_editor_supports' ) && wp_image_editor_supports( array( 'mime_type' => 'image/webp' ) ) ? 'image/webp' : 'image/jpeg';
            $ext  = 'image/webp' === $mime ? 'webp' : 'jpg';
            $base = bin2hex( random_bytes( 10 ) );
            $made = array( 'base' => $base, 'ext' => $ext );

            foreach ( self::SIZES as $size ) {
                $editor = wp_get_image_editor( $file );

                if ( is_wp_error( $editor ) ) {
                    $this->remove_files( $made );

                    return $bad;
                }

                if ( method_exists( $editor, 'maybe_exif_rotate' ) ) {
                    $editor->maybe_exif_rotate();
                }

                $dimensions = $editor->get_size();
                $width      = (int) $dimensions['width'];
                $height     = (int) $dimensions['height'];
                $side       = min( $width, $height );
                $crop       = $editor->crop( (int) floor( ( $width - $side ) / 2 ), (int) floor( ( $height - $side ) / 2 ), $side, $side, $size, $size );

                if ( is_wp_error( $crop ) ) {
                    $this->remove_files( $made );

                    return $bad;
                }

                self::strip( $editor );

                $editor->set_quality( 82 );
                $saved = $editor->save( $folder . '/' . $base . '-' . $size . '.' . $ext, $mime );

                if ( is_wp_error( $saved ) ) {
                    $this->remove_files( $made );

                    return $bad;
                }
            }

            return $made;
        }

        private static function strip( $editor )
        {
            if ( ! is_object( $editor ) || ! method_exists( $editor, 'strip_meta' ) ) {
                return;
            }

            try {
                $method = new ReflectionMethod( $editor, 'strip_meta' );

                if ( PHP_VERSION_ID < 80100 ) {
                    $method->setAccessible( true );
                }

                $method->invoke( $editor );
            } catch ( \Exception $e ) {
            }
        }

        private function remove_files( array $kept )
        {
            $folder = $this->folder();

            if ( '' === $folder || 1 !== preg_match( '/^[a-f0-9]{8,40}$/', (string) $kept['base'] ) ) {
                return;
            }

            foreach ( self::SIZES as $size ) {
                $file = $folder . '/' . $kept['base'] . '-' . $size . '.' . preg_replace( '/[^a-z]/', '', (string) $kept['ext'] );

                if ( file_exists( $file ) ) {
                    @unlink( $file ); // phpcs:ignore
                }
            }
        }

        public function avatar_data( $args, $id_or_email )
        {
            $loaded = function_exists( 'wp_load_alloptions' ) ? wp_load_alloptions() : array();

            if ( empty( $loaded[ self::FLAG ] ) || ! empty( $args['force_default'] ) ) {
                return $args;
            }

            $user = self::user_of( $id_or_email );

            if ( $user <= 0 ) {
                return $args;
            }

            $photo = $this->photo( $user );

            if ( null === $photo ) {
                return $args;
            }

            $size               = isset( $args['size'] ) ? (int) $args['size'] : 96;
            $args['url']        = $size > 128 ? $photo['large'] : $photo['small'];
            $args['found_avatar'] = true;

            return $args;
        }

        private static function user_of( $id_or_email )
        {
            if ( is_numeric( $id_or_email ) ) {
                return (int) $id_or_email;
            }

            if ( $id_or_email instanceof WP_User ) {
                return (int) $id_or_email->ID;
            }

            if ( $id_or_email instanceof WP_Post ) {
                return (int) $id_or_email->post_author;
            }

            if ( $id_or_email instanceof WP_Comment ) {
                return (int) $id_or_email->user_id;
            }

            if ( is_string( $id_or_email ) && is_email( $id_or_email ) ) {
                $user = get_user_by( 'email', $id_or_email );

                return $user ? (int) $user->ID : 0;
            }

            return 0;
        }

        public function picture_description( $description, $user )
        {
            if ( ! is_object( $user ) || null === $this->photo( (int) $user->ID ) ) {
                return $description;
            }

            $url = wp_nonce_url( admin_url( 'admin-post.php?action=wordplus_profile_remove_photo&user=' . (int) $user->ID ), 'wordplus_profile_remove_photo_' . (int) $user->ID );

            return esc_html__( 'This photo was uploaded on this site.', 'wordplus-sdk' ) . ' <a href="' . esc_url( $url ) . '">' . esc_html__( 'Remove photo', 'wordplus-sdk' ) . '</a>';
        }

        public function admin_remove_photo()
        {
            $user = isset( $_GET['user'] ) ? (int) $_GET['user'] : 0; // phpcs:ignore WordPress.Security.NonceVerification

            if ( $user <= 0 || ! current_user_can( 'edit_user', $user ) || ! wp_verify_nonce( isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '', 'wordplus_profile_remove_photo_' . $user ) ) {
                wp_die( esc_html__( 'You are not allowed to remove this photo.', 'wordplus-sdk' ), 403 );
            }

            $this->remove_photo( $user );

            wp_safe_redirect( get_current_user_id() === $user ? admin_url( 'profile.php' ) : get_edit_user_link( $user ) );
            exit;
        }

        public function current()
        {
            $user = get_current_user_id();

            if ( $user > 0 ) {
                return $user;
            }

            $visitor = (int) apply_filters( 'wordplus_profile_person', 0 );

            if ( $visitor < 0 ) {
                return $visitor;
            }

            return (int) $this->sdk->visitors()->current();
        }

        public function routes()
        {
            register_rest_route( self::REST_NAMESPACE, '/profile', array(
                array(
                    'methods'             => 'GET',
                    'callback'            => array( $this, 'rest_get' ),
                    'permission_callback' => array( $this, 'is_someone' ),
                ),
                array(
                    'methods'             => 'POST',
                    'callback'            => array( $this, 'rest_name' ),
                    'permission_callback' => array( $this, 'is_someone' ),
                ),
            ) );

            register_rest_route( self::REST_NAMESPACE, '/profile/photo', array(
                array(
                    'methods'             => 'POST',
                    'callback'            => array( $this, 'rest_photo' ),
                    'permission_callback' => array( $this, 'is_someone' ),
                ),
                array(
                    'methods'             => 'DELETE',
                    'callback'            => array( $this, 'rest_remove_photo' ),
                    'permission_callback' => array( $this, 'is_someone' ),
                ),
            ) );
        }

        public function is_someone()
        {
            return 0 !== $this->current();
        }

        public function rest_get()
        {
            return $this->present( $this->current() );
        }

        public function rest_name( $request )
        {
            $id     = $this->current();
            $result = $this->set_name( $id, (string) $request->get_param( 'name' ) );

            return is_wp_error( $result ) ? $result : $this->present( $id );
        }

        public function rest_photo( $request )
        {
            $id    = $this->current();
            $files = $request->get_file_params();

            if ( empty( $files['photo']['tmp_name'] ) || ! empty( $files['photo']['error'] ) ) {
                return new WP_Error( 'wordplus_profile_photo_missing', __( 'No photo was uploaded.', 'wordplus-sdk' ), array( 'status' => 400 ) );
            }

            if ( ! is_uploaded_file( $files['photo']['tmp_name'] ) && ! apply_filters( 'wordplus_profile_photo_test_file', false ) ) {
                return new WP_Error( 'wordplus_profile_photo_missing', __( 'No photo was uploaded.', 'wordplus-sdk' ), array( 'status' => 400 ) );
            }

            $result = $this->set_photo( $id, $files['photo']['tmp_name'] );

            return is_wp_error( $result ) ? $result : $this->present( $id );
        }

        public function rest_remove_photo()
        {
            $id = $this->current();

            if ( ! $this->editable( $id )['photo'] ) {
                return new WP_Error( 'wordplus_profile_photo_locked', __( 'This photo cannot be changed here. It is managed elsewhere on this site.', 'wordplus-sdk' ), array( 'status' => 403 ) );
            }

            $this->remove_photo( $id );

            return $this->present( $id );
        }
    }
}
