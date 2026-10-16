<?php
defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Better_Messages_Storage_Direct_Upload' ) ):

    class Better_Messages_Storage_Direct_Upload
    {
        public static function instance()
        {
            static $instance = null;

            if ( null === $instance ) {
                $instance = new Better_Messages_Storage_Direct_Upload();
            }

            return $instance;
        }

        public function __construct()
        {
            add_action( 'rest_api_init', array( $this, 'rest_api_init' ) );
            add_filter( 'bp_better_messages_script_variable', array( $this, 'script_vars' ), 20 );
        }

        private function storage()
        {
            return Better_Messages_Storage();
        }

        private function direct()
        {
            $sdk = $this->storage()->sdk();

            return $sdk ? $sdk->direct() : null;
        }

        public function enabled_for( $location )
        {
            return $location && $this->direct() && $this->direct()->enabled_for( $location );
        }

        public function direct_groups()
        {
            $groups = array();

            if ( ! $this->storage()->has_cloud_routes() ) {
                return $groups;
            }

            foreach ( Better_Messages_Storage::groups() as $group ) {
                if ( $this->enabled_for( $this->storage()->route( $group ) ) ) {
                    $groups[] = $group;
                }
            }

            return $groups;
        }

        public function script_vars( $vars )
        {
            if ( isset( $vars['attachments'] ) && is_array( $vars['attachments'] ) ) {
                $vars['attachments']['direct'] = $this->direct_groups();
            }

            return $vars;
        }

        public function rest_api_init()
        {
            if ( ! function_exists( 'Better_Messages_Files' ) || ! Better_Messages_Files()->uploads_available() ) {
                return;
            }

            $thread_arg = array(
                'id' => array(
                    'validate_callback' => function ( $param ) {
                        return is_numeric( $param );
                    },
                ),
            );

            register_rest_route( 'better-messages/v1', '/thread/(?P<id>\d+)/upload/presign', array(
                'methods'             => 'POST',
                'callback'            => array( $this, 'presign' ),
                'permission_callback' => array( Better_Messages_Files(), 'user_can_upload_callback' ),
                'args'                => $thread_arg,
            ) );

            register_rest_route( 'better-messages/v1', '/thread/(?P<id>\d+)/upload/parts', array(
                'methods'             => 'POST',
                'callback'            => array( $this, 'parts' ),
                'permission_callback' => array( Better_Messages_Files(), 'user_can_upload_callback' ),
                'args'                => $thread_arg,
            ) );

            register_rest_route( 'better-messages/v1', '/thread/(?P<id>\d+)/upload/complete', array(
                'methods'             => 'POST',
                'callback'            => array( $this, 'complete' ),
                'permission_callback' => array( Better_Messages_Files(), 'user_can_upload_callback' ),
                'args'                => $thread_arg,
            ) );

            register_rest_route( 'better-messages/v1', '/thread/(?P<id>\d+)/upload/(?P<upload_id>\d+)', array(
                'methods'             => 'DELETE',
                'callback'            => array( $this, 'abort' ),
                'permission_callback' => array( Better_Messages_Files(), 'user_can_upload_callback' ),
                'args'                => $thread_arg,
            ) );
        }

        private function error( $code, $message, $status )
        {
            return new WP_Error( $code, $message, array( 'status' => $status ) );
        }

        private function rate_limited( $user_id )
        {
            $limit = (int) apply_filters( 'better_messages_storage_presign_rate_limit', 60, $user_id );

            if ( $limit <= 0 ) {
                return false;
            }

            $key   = 'bm_presign_' . md5( (string) $user_id . '|' . floor( time() / 60 ) );
            $count = (int) get_transient( $key );

            if ( $count >= $limit ) {
                return true;
            }

            set_transient( $key, $count + 1, 120 );

            return false;
        }

        private function is_e2e_upload( $thread_id, WP_REST_Request $request )
        {
            return class_exists( 'Better_Messages_E2E_Encryption' ) && (
                Better_Messages_E2E_Encryption::is_e2e_thread( $thread_id )
                || ( $thread_id === 0 && ! empty( $request->get_param( 'e2e_encrypted' ) ) )
            );
        }

        private function upload_mime( $name, $is_e2e, $user_id )
        {
            if ( $is_e2e ) {
                return 'application/octet-stream';
            }

            $check = wp_check_filetype( $name, Better_Messages_Files()->upload_mimes( array(), $user_id ) );

            return empty( $check['type'] ) ? '' : $check['type'];
        }

        public function presign( WP_REST_Request $request )
        {
            $storage = $this->storage();

            if ( ! $this->direct() || empty( $this->direct_groups() ) || ! $storage->table_exists() ) {
                return array( 'method' => 'server' );
            }

            $user_id   = (int) Better_Messages()->functions->get_current_user_id();
            $thread_id = (int) $request->get_param( 'id' );
            $name      = wp_basename( (string) $request->get_param( 'name' ) );
            $size      = (int) $request->get_param( 'size' );
            $is_e2e    = $this->is_e2e_upload( $thread_id, $request );
            $files     = Better_Messages_Files();

            if ( $name === '' || $size <= 0 ) {
                return $this->error( 'rest_upload_failed', _x( 'Your request is empty.', 'File Uploader Error', 'bp-better-messages' ), 406 );
            }

            $extension = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );

            if ( $is_e2e ) {
                if ( $extension !== 'enc' ) {
                    return $this->error( 'rest_forbidden', _x( 'Encrypted file uploads must use .enc extension', 'File Uploader Error', 'bp-better-messages' ), rest_authorization_required_code() );
                }

                if ( empty( $request->get_param( 'e2e_encrypted' ) ) ) {
                    return $this->error( 'rest_e2e_required', _x( 'Files in encrypted conversations must be encrypted before uploading.', 'E2E Encryption', 'bp-better-messages' ), 403 );
                }
            } else if ( $extension === '' || ! in_array( $extension, $files->allowed_upload_extensions( $thread_id, $user_id ), true ) ) {
                return $this->error( 'rest_forbidden', _x( 'Sorry, you are not allowed to upload this file type', 'File Uploader Error', 'bp-better-messages' ), rest_authorization_required_code() );
            }

            $max_size_mb = $files->max_upload_mb( $files->size_group( $name, $is_e2e, $request->get_param( 'e2e_original_mime' ) ), $thread_id, $user_id );

            if ( $size > $max_size_mb * 1024 * 1024 ) {
                return $this->error( 'rest_upload_failed', sprintf( _x( '%s is too large! Please upload file up to %d MB.', 'File Uploader Error', 'bp-better-messages' ), $name, $max_size_mb ), 413 );
            }

            $mime = $this->upload_mime( $name, $is_e2e, $user_id );

            if ( $mime === '' ) {
                return $this->error( 'rest_forbidden', _x( 'Sorry, you are not allowed to upload this file type', 'File Uploader Error', 'bp-better-messages' ), rest_authorization_required_code() );
            }

            $location = $storage->route( Better_Messages_Storage::file_group( $is_e2e ? 'e2e' : 'file', $mime ) );

            if ( ! $this->enabled_for( $location ) || ! $this->direct()->ready( $location ) ) {
                return array( 'method' => 'server' );
            }

            do_action( 'better_messages_post_before_upload', array(
                'filename' => $name,
                'filetype' => $mime,
            ) );

            if ( $this->rate_limited( $user_id ) || $this->direct()->open_uploads( Better_Messages_Storage::PLUGIN, $user_id ) >= (int) apply_filters( 'better_messages_storage_open_uploads_limit', 20, $user_id ) ) {
                return $this->error( 'rest_upload_rate_limited', _x( 'You are uploading too many files at once. Please wait a moment and try again.', 'File Uploader Error', 'bp-better-messages' ), 429 );
            }

            $key = gmdate( 'Y' ) . '/' . gmdate( 'm' ) . '/' . $thread_id . '/' . wp_generate_uuid4() . '/' . $files->limit_filename_bytes( sanitize_file_name( $name ) );

            return $this->direct()->begin( Better_Messages_Storage::PLUGIN, $location, array(
                'key'  => $key,
                'path' => $storage->base_dir_name() . '/' . $key,
                'name' => $name,
                'type' => $is_e2e ? 'application/octet-stream' : $mime,
                'size' => $size,
                'user' => $user_id,
            ) );
        }

        private function own_row( WP_REST_Request $request, $upload_id )
        {
            if ( ! $this->direct() ) {
                return $this->error( 'rest_not_found', _x( 'This upload is no longer available. Please try again.', 'File Uploader Error', 'bp-better-messages' ), 404 );
            }

            $row = $this->direct()->own( Better_Messages_Storage::PLUGIN, (int) $upload_id, (int) Better_Messages()->functions->get_current_user_id() );

            if ( is_wp_error( $row ) ) {
                return $row;
            }

            $segments = explode( '/', (string) $row->item_key );

            if ( ! isset( $segments[2] ) || (int) $segments[2] !== (int) $request->get_param( 'id' ) ) {
                return $this->error( 'rest_forbidden', _x( 'Sorry, you are not allowed to upload files', 'File Uploader Error', 'bp-better-messages' ), rest_authorization_required_code() );
            }

            return $row;
        }

        public function parts( WP_REST_Request $request )
        {
            $row = $this->own_row( $request, $request->get_param( 'upload_id' ) );

            if ( is_wp_error( $row ) ) {
                return $row;
            }

            $parts = $this->direct()->parts( $row, (int) $request->get_param( 'from' ) );

            return is_wp_error( $parts ) ? $parts : array( 'parts' => $parts );
        }

        public function complete( WP_REST_Request $request )
        {
            return Better_Messages()->functions->with_lock( 'direct_upload|' . (int) $request->get_param( 'upload_id' ), function () use ( $request ) {
                $completed = $this->completed_attachment( $request );

                return $completed ? array( 'result' => true, 'error' => '', 'id' => $completed ) : $this->complete_upload( $request );
            } );
        }

        private function completed_attachment( WP_REST_Request $request )
        {
            $sdk = $this->storage()->sdk();
            $row = $sdk ? $sdk->object( (int) $request->get_param( 'upload_id' ) ) : null;

            if ( ! $row || 'stored' !== $row->state || (int) $row->ref === 0 || (string) $row->plugin !== (string) Better_Messages_Storage::PLUGIN ) {
                return 0;
            }

            $attachment_id = (int) $row->ref;

            if ( (int) $row->user_id !== (int) Better_Messages()->functions->get_current_user_id()
                || (int) get_post_meta( $attachment_id, 'bp-better-messages-thread-id', true ) !== (int) $request->get_param( 'id' ) ) {
                return 0;
            }

            return $attachment_id;
        }

        private function complete_upload( WP_REST_Request $request )
        {
            $row = $this->own_row( $request, $request->get_param( 'upload_id' ) );

            if ( is_wp_error( $row ) ) {
                return $row;
            }

            $verified = $this->direct()->verify( $row );

            if ( is_wp_error( $verified ) ) {
                return $verified;
            }

            $storage   = $this->storage();
            $thread_id = (int) $request->get_param( 'id' );
            $user_id   = (int) $row->user_id;
            $is_e2e    = $row->mime === 'application/octet-stream' && strtolower( pathinfo( (string) $row->name, PATHINFO_EXTENSION ) ) === 'enc';
            $mime      = $this->upload_mime( (string) $row->name, $is_e2e, $user_id );
            $uploads   = wp_upload_dir( null, false );
            $title     = preg_replace( '/\.[^.]+$/', '', wp_basename( (string) $row->item_key ) );

            $attachment_id = wp_insert_attachment( array(
                'post_mime_type' => $mime !== '' ? $mime : (string) $row->mime,
                'post_title'     => sanitize_text_field( $title ),
                'post_content'   => '',
                'post_status'    => 'private',
                'guid'           => trailingslashit( $uploads['baseurl'] ) . WordPlus_SDK_Storage_S3::encode_key( (string) $row->path ),
            ), false, 0, true );

            if ( is_wp_error( $attachment_id ) || ! $attachment_id ) {
                $this->direct()->discard( $row );

                return $this->error( 'rest_upload_failed', _x( 'Upload failed', 'File Uploader', 'bp-better-messages' ), 500 );
            }

            update_post_meta( $attachment_id, '_wp_attached_file', (string) $row->path );
            add_post_meta( $attachment_id, 'bp-better-messages-attachment', true, true );
            add_post_meta( $attachment_id, 'bp-better-messages-thread-id', $thread_id, true );
            add_post_meta( $attachment_id, 'bp-better-messages-uploader-user-id', $user_id, true );
            add_post_meta( $attachment_id, 'bp-better-messages-upload-time', time(), true );
            add_post_meta( $attachment_id, 'bp-better-messages-original-name', (string) $row->name, true );
            add_post_meta( $attachment_id, 'better-messages-waiting-for-message', time(), true );

            if ( $is_e2e ) {
                $e2e_mime = sanitize_mime_type( (string) $request->get_param( 'e2e_original_mime' ) );

                if ( $e2e_mime !== '' ) {
                    add_post_meta( $attachment_id, 'bm-e2e-original-mime', $e2e_mime, true );
                }
            }

            $this->direct()->complete( $row, $attachment_id );

            do_action( 'better_messages_attachment_created', $attachment_id, 'direct' );

            return array( 'result' => true, 'error' => '', 'id' => $attachment_id );
        }

        public function abort( WP_REST_Request $request )
        {
            $row = $this->own_row( $request, $request->get_param( 'upload_id' ) );

            if ( ! is_wp_error( $row ) ) {
                $this->direct()->discard( $row, $request->get_param( 'reason' ) === 'fallback' ? 'fallback' : '' );
            }

            return array( 'result' => true );
        }
    }

endif;

function Better_Messages_Storage_Direct_Upload()
{
    return Better_Messages_Storage_Direct_Upload::instance();
}
