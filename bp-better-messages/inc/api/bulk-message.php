<?php
if ( !class_exists( 'Better_Messages_Rest_Api_Bulk_Message' ) ):

    class Better_Messages_Rest_Api_Bulk_Message
    {

        public static function instance()
        {

            static $instance = null;

            if (null === $instance) {
                $instance = new Better_Messages_Rest_Api_Bulk_Message();
            }

            return $instance;
        }

        public function __construct(){
            add_action( 'rest_api_init',  array( $this, 'rest_api_init' ) );
        }

        public function rest_api_init(){
            register_rest_route( 'better-messages/v1', '/bulkMessages', array(
                'methods' => 'GET',
                'callback' => array( $this, 'get_reports' ),
                'permission_callback' => array( $this, 'has_access' )
            ) );

            register_rest_route( 'better-messages/v1', '/bulkMessages/preview', array(
                'methods' => 'POST',
                'callback' => array( $this, 'preview' ),
                'permission_callback' => array( $this, 'has_access' )
            ) );

            register_rest_route( 'better-messages/v1', '/bulkMessages/create', array(
                'methods' => 'POST',
                'callback' => array( $this, 'create_job' ),
                'permission_callback' => array( $this, 'has_access' )
            ) );

            register_rest_route( 'better-messages/v1', '/bulkMessages/(?P<id>\d+)/pause', array(
                'methods' => 'POST',
                'callback' => array( $this, 'pause_job' ),
                'permission_callback' => array( $this, 'has_access' )
            ) );

            register_rest_route( 'better-messages/v1', '/bulkMessages/(?P<id>\d+)/resume', array(
                'methods' => 'POST',
                'callback' => array( $this, 'resume_job' ),
                'permission_callback' => array( $this, 'has_access' )
            ) );

            register_rest_route( 'better-messages/v1', '/bulkMessages/(?P<id>\d+)/cancel', array(
                'methods' => 'POST',
                'callback' => array( $this, 'cancel_job' ),
                'permission_callback' => array( $this, 'has_access' )
            ) );

            register_rest_route( 'better-messages/v1', '/bulkMessages/(?P<id>\d+)/status', array(
                'methods' => 'GET',
                'callback' => array( $this, 'get_job_status' ),
                'permission_callback' => array( $this, 'has_access' )
            ) );

            register_rest_route( 'better-messages/v1', '/bulkMessages/changeReport', array(
                'methods' => 'POST',
                'callback' => array( $this, 'change_report' ),
                'permission_callback' => array( $this, 'has_access' )
            ) );

            register_rest_route( 'better-messages/v1', '/bulkMessages/deleteReport', array(
                'methods' => 'POST',
                'callback' => array( $this, 'delete_report' ),
                'permission_callback' => array( $this, 'has_access' )
            ) );

            register_rest_route( 'better-messages/v1', '/bulkMessages/upload', array(
                'methods' => 'POST',
                'callback' => array( $this, 'upload_attachment' ),
                'permission_callback' => array( $this, 'has_access' )
            ) );

            register_rest_route( 'better-messages/v1', '/bulkMessages/sendTest', array(
                'methods' => 'POST',
                'callback' => array( $this, 'send_test' ),
                'permission_callback' => array( $this, 'has_access' )
            ) );

            register_rest_route( 'better-messages/v1', '/bulkMessages/previewMessage/(?P<id>\d+)', array(
                'methods' => 'GET',
                'callback' => array( $this, 'get_preview_message' ),
                'permission_callback' => array( $this, 'has_access' )
            ) );

            register_rest_route( 'better-messages/v1', '/bulkMessages/(?P<id>\d+)/threads', array(
                'methods' => 'GET',
                'callback' => array( $this, 'get_job_threads' ),
                'permission_callback' => array( $this, 'has_access' )
            ) );

            register_rest_route( 'better-messages/v1', '/bulkMessages/(?P<id>\d+)/followUp', array(
                'methods' => 'POST',
                'callback' => array( $this, 'create_follow_up' ),
                'permission_callback' => array( $this, 'has_access' )
            ) );

            register_rest_route( 'better-messages/v1', '/bulkMessages/(?P<id>\d+)/followUpCounts', array(
                'methods' => 'GET',
                'callback' => array( $this, 'get_follow_up_counts' ),
                'permission_callback' => array( $this, 'has_access' )
            ) );

            add_filter( 'better_messages_can_send_message', array( $this, 'disabled_thread_reply' ), 10, 3);
        }

        private array $disabled_thread_reply = [];

        public function disabled_thread_reply( $allowed, $user_id, $thread_id ){
            global $wpdb;

            if( isset( $this->disabled_thread_reply[$thread_id] ) ){
                if( $this->disabled_thread_reply[$thread_id] ){
                    global $bp_better_messages_restrict_send_message;
                    $bp_better_messages_restrict_send_message['disable_bulk_replies'] = __('Admin disabled replies to this conversation', 'bp-better-messages');

                    return false;
                } else {
                    return $allowed;
                }
            }

            $bulk_jobs_table = bm_get_table('bulk_jobs');
            $bulk_job_threads_table = bm_get_table('bulk_job_threads');

            $job = $wpdb->get_row( $wpdb->prepare("
                SELECT `job`.`id`, `job`.`disable_reply`
                FROM `{$bulk_jobs_table}` AS `job`
                INNER JOIN `{$bulk_job_threads_table}` AS `jt` ON `jt`.`job_id` = `job`.`id`
                WHERE `jt`.`thread_id` = %d
                ORDER BY `job`.`parent_job_id` ASC, `job`.`id` ASC
                LIMIT 1
            ", $thread_id) );

            if( $job && $job->disable_reply ){
                $this->disabled_thread_reply[$thread_id] = true;
                $allowed = false;
                global $bp_better_messages_restrict_send_message;
                $bp_better_messages_restrict_send_message['disable_bulk_replies'] = __('Admin disabled replies to this conversation', 'bp-better-messages');
            }

            if( ! isset( $this->disabled_thread_reply[$thread_id] ) ){
                $this->disabled_thread_reply[$thread_id] = false;
            }

            return $allowed;
        }

        /**
         * Create a new bulk messaging job (queued for WP Cron processing).
         */
        public function create_job( WP_REST_Request $request ){
            global $wpdb;

            $selectors      = $request->get_param( 'selectors' );
            $message        = $request->get_param( 'message' );
            $attachment_ids = $request->get_param( 'attachment_ids' );

            $content = Better_Messages()->functions->filter_message_content( $message );

            // Resolve sender first so we can exclude them from the user query
            $sender_id = get_current_user_id();
            $custom_sender = $request->get_param( 'sender_id' );
            if ( ! empty( $custom_sender ) ) {
                $custom_sender = (int) $custom_sender;
                if ( get_userdata( $custom_sender ) ) {
                    $sender_id = $custom_sender;
                }
            }

            $user_query = $this->get_user_query( $selectors, false, 1, 20, $sender_id );
            $users_count = $user_query ? (int) $user_query->total_users : 0;

            if( empty( trim( $content ) ) ){
                return new WP_Error(
                    'rest_forbidden',
                    _x('Message is empty', 'Bulk Messages Page', 'bp-better-messages'),
                    array( 'status' => rest_authorization_required_code() )
                );
            }

            if( $users_count == 0 ){
                return new WP_Error(
                    'rest_forbidden',
                    _x('No users was selected', 'Bulk Messages Page', 'bp-better-messages'),
                    array( 'status' => rest_authorization_required_code() )
                );
            }

            if ( ! is_array( $attachment_ids ) ) {
                $attachment_ids = [];
            }
            $attachment_ids = array_filter( $attachment_ids, 'is_numeric' );
            $attachment_ids = array_map( 'intval', $attachment_ids );

            $disable_reply = ! empty( $selectors['disableReply'] ) ? 1 : 0;
            $hide_thread   = ! empty( $selectors['hideThread'] ) ? 1 : 0;
            $single_thread = ! empty( $selectors['singleThread'] ) ? 1 : 0;

            $subject = isset( $selectors['subject'] ) ? sanitize_text_field( $selectors['subject'] ) : '';

            if ( $single_thread ) {
                $users_count = 1;
            }

            $scheduled_at = $request->get_param( 'scheduled_at' );
            $batch_size   = max( 0, intval( $request->get_param( 'batch_size' ) ) );

            $status = 'processing';
            $scheduled_at_value = null;
            if ( ! empty( $scheduled_at ) ) {
                $timestamp = strtotime( $scheduled_at );
                if ( $timestamp === false ) {
                    return new WP_Error( 'invalid_date', __('Invalid schedule date', 'bp-better-messages'), [ 'status' => 400 ] );
                }
                $scheduled_at_value = date_i18n( 'Y-m-d H:i:s', $timestamp );
                $status = 'pending';
            }

            $table = bm_get_table( 'bulk_jobs' );

            $insert_data = [
                'sender_id'           => $sender_id,
                'subject'             => $subject,
                'message'             => $content,
                'selectors'           => wp_json_encode( $selectors ),
                'attachment_ids'      => wp_json_encode( $attachment_ids ),
                'status'              => $status,
                'disable_reply'       => $disable_reply,
                'hide_thread'         => $hide_thread,
                'single_thread'       => $single_thread,
                'total_users'         => $users_count,
                'processed_count'     => 0,
                'error_count'         => 0,
                'current_page'        => 1,
                'batch_size'          => $batch_size,
                'created_at'          => current_time( 'mysql' ),
            ];

            if ( $scheduled_at_value ) {
                $insert_data['scheduled_at'] = $scheduled_at_value;
            }

            $wpdb->insert( $table, $insert_data );

            $job_id = $wpdb->insert_id;

            if ( ! $job_id ) {
                return new WP_Error(
                    'rest_error',
                    __('Failed to create bulk job', 'bp-better-messages'),
                    array( 'status' => 500 )
                );
            }

            // Mark attachments as claimed by a job so orphan cleanup won't delete them
            foreach ( $attachment_ids as $att_id ) {
                delete_post_meta( $att_id, 'bp-better-messages-bulk-attachment' );
                update_post_meta( $att_id, 'bp-better-messages-bulk-job-id', $job_id );
            }

            return [
                'job_id'      => (int) $job_id,
                'total_users' => (int) $users_count,
                'status'      => $status,
            ];
        }

        /**
         * Create a follow-up job that sends a new message into threads from a parent job.
         */
        public function create_follow_up( WP_REST_Request $request ){
            global $wpdb;

            $parent_job_id  = (int) $request->get_param('id');
            $message        = $request->get_param('message');
            $attachment_ids = $request->get_param('attachment_ids');

            $table         = bm_get_table('bulk_jobs');
            $threads_table = bm_get_table('bulk_job_threads');

            $parent_job = $wpdb->get_row( $wpdb->prepare(
                "SELECT * FROM `{$table}` WHERE `id` = %d", $parent_job_id
            ) );

            if ( ! $parent_job ) {
                return new WP_Error( 'not_found', __('Job not found', 'bp-better-messages'), [ 'status' => 404 ] );
            }

            $content = Better_Messages()->functions->filter_message_content( $message );
            if ( empty( trim( $content ) ) ) {
                return new WP_Error( 'rest_forbidden', _x('Message is empty', 'WP Admin', 'bp-better-messages'), [ 'status' => 400 ] );
            }

            $thread_count = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(DISTINCT `thread_id`) FROM `{$threads_table}` WHERE `job_id` = %d",
                $parent_job_id
            ) );

            if ( $thread_count === 0 ) {
                return new WP_Error( 'rest_forbidden', __('No conversations to follow up', 'bp-better-messages'), [ 'status' => 400 ] );
            }

            if ( ! is_array( $attachment_ids ) ) {
                $attachment_ids = [];
            }
            $attachment_ids = array_filter( $attachment_ids, 'is_numeric' );
            $attachment_ids = array_map( 'intval', $attachment_ids );

            $include_new_users = ! empty( $request->get_param('include_new_users') );
            $total_users       = $thread_count;
            $selectors_json    = '{}';

            if ( $include_new_users ) {
                $parent_selectors = json_decode( $parent_job->selectors, true );
                if ( is_array( $parent_selectors ) && ! empty( $parent_selectors ) ) {
                    $parent_selectors['include_new_users'] = true;
                    $selectors_json = wp_json_encode( $parent_selectors );

                    // Count new matching users (exclude sender + users already in parent job)
                    $sender_id     = (int) $parent_job->sender_id;
                    $current_query = $this->get_user_query( $parent_selectors, false, 1, 20, $sender_id, $parent_job_id );
                    $new_user_count = $current_query ? (int) $current_query->total_users : 0;
                    $total_users    = $thread_count + $new_user_count;
                }
            }

            $wpdb->insert( $table, [
                'sender_id'       => (int) $parent_job->sender_id,
                'subject'         => $parent_job->subject,
                'message'         => $content,
                'selectors'       => $selectors_json,
                'attachment_ids'  => wp_json_encode( $attachment_ids ),
                'status'          => 'processing',
                'disable_reply'   => (int) $parent_job->disable_reply,
                'hide_thread'     => (int) $parent_job->hide_thread,
                'single_thread'   => 0,
                'parent_job_id'   => $parent_job_id,
                'total_users'     => $total_users,
                'processed_count' => 0,
                'error_count'     => 0,
                'current_page'    => 1,
                'created_at'      => current_time('mysql'),
            ] );

            $job_id = $wpdb->insert_id;

            if ( ! $job_id ) {
                return new WP_Error( 'rest_error', __('Failed to create follow-up job', 'bp-better-messages'), [ 'status' => 500 ] );
            }

            foreach ( $attachment_ids as $att_id ) {
                delete_post_meta( $att_id, 'bp-better-messages-bulk-attachment' );
                update_post_meta( $att_id, 'bp-better-messages-bulk-job-id', $job_id );
            }

            return [
                'job_id'        => (int) $job_id,
                'total_threads' => $thread_count,
            ];
        }

        public function get_follow_up_counts( WP_REST_Request $request ){
            global $wpdb;

            $parent_job_id = (int) $request->get_param('id');
            $table         = bm_get_table('bulk_jobs');
            $threads_table = bm_get_table('bulk_job_threads');

            $parent_job = $wpdb->get_row( $wpdb->prepare(
                "SELECT * FROM `{$table}` WHERE `id` = %d", $parent_job_id
            ) );

            if ( ! $parent_job ) {
                return new WP_Error( 'not_found', __('Job not found', 'bp-better-messages'), [ 'status' => 404 ] );
            }

            $existing_threads = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(DISTINCT `thread_id`) FROM `{$threads_table}` WHERE `job_id` = %d",
                $parent_job_id
            ) );

            $new_users = 0;
            $parent_selectors = json_decode( $parent_job->selectors, true );
            if ( is_array( $parent_selectors ) && ! empty( $parent_selectors ) ) {
                $sender_id     = (int) $parent_job->sender_id;
                $current_query = $this->get_user_query( $parent_selectors, false, 1, 20, $sender_id, $parent_job_id );
                $new_users     = $current_query ? (int) $current_query->total_users : 0;
            }

            return [
                'existing_threads' => $existing_threads,
                'new_users'        => $new_users,
            ];
        }

        /**
         * Pause a processing bulk job.
         */
        public function pause_job( WP_REST_Request $request ){
            global $wpdb;
            $job_id = intval( $request->get_param( 'id' ) );
            $table = bm_get_table( 'bulk_jobs' );

            $job = $wpdb->get_row( $wpdb->prepare( "SELECT `status` FROM `{$table}` WHERE `id` = %d", $job_id ) );
            if ( ! $job ) {
                return new WP_Error( 'not_found', __('Job not found', 'bp-better-messages'), [ 'status' => 404 ] );
            }

            if ( $job->status !== 'processing' ) {
                return new WP_Error( 'invalid_status', __('Job is not processing', 'bp-better-messages'), [ 'status' => 400 ] );
            }

            $wpdb->update( $table, [ 'status' => 'paused' ], [ 'id' => $job_id ] );

            return [ 'success' => true, 'status' => 'paused' ];
        }

        /**
         * Resume a paused bulk job.
         */
        public function resume_job( WP_REST_Request $request ){
            global $wpdb;
            $job_id = intval( $request->get_param( 'id' ) );
            $table = bm_get_table( 'bulk_jobs' );

            $job = $wpdb->get_row( $wpdb->prepare( "SELECT `status` FROM `{$table}` WHERE `id` = %d", $job_id ) );
            if ( ! $job ) {
                return new WP_Error( 'not_found', __('Job not found', 'bp-better-messages'), [ 'status' => 404 ] );
            }

            if ( $job->status !== 'paused' ) {
                return new WP_Error( 'invalid_status', __('Job is not paused', 'bp-better-messages'), [ 'status' => 400 ] );
            }

            $wpdb->update( $table, [ 'status' => 'processing' ], [ 'id' => $job_id ] );

            return [ 'success' => true, 'status' => 'processing' ];
        }

        /**
         * Cancel a bulk job.
         */
        public function cancel_job( WP_REST_Request $request ){
            global $wpdb;
            $job_id = intval( $request->get_param( 'id' ) );
            $table = bm_get_table( 'bulk_jobs' );

            $job = $wpdb->get_row( $wpdb->prepare( "SELECT `status` FROM `{$table}` WHERE `id` = %d", $job_id ) );
            if ( ! $job ) {
                return new WP_Error( 'not_found', __('Job not found', 'bp-better-messages'), [ 'status' => 404 ] );
            }

            if ( ! in_array( $job->status, [ 'processing', 'paused', 'pending' ], true ) ) {
                return new WP_Error( 'invalid_status', __('Job cannot be cancelled', 'bp-better-messages'), [ 'status' => 400 ] );
            }

            $wpdb->update( $table, [
                'status'       => 'cancelled',
                'completed_at' => current_time( 'mysql' ),
            ], [ 'id' => $job_id ] );

            return [ 'success' => true, 'status' => 'cancelled' ];
        }

        /**
         * Get status of a single bulk job.
         */
        public function get_job_status( WP_REST_Request $request ){
            global $wpdb;
            $job_id = intval( $request->get_param( 'id' ) );
            $table = bm_get_table( 'bulk_jobs' );

            $job = $wpdb->get_row( $wpdb->prepare(
                "SELECT `status`, `total_users`, `processed_count`, `error_count`, `started_at`, `completed_at` FROM `{$table}` WHERE `id` = %d",
                $job_id
            ) );

            if ( ! $job ) {
                return new WP_Error( 'not_found', __('Job not found', 'bp-better-messages'), [ 'status' => 404 ] );
            }

            return [
                'status'          => $job->status,
                'total_users'     => (int) $job->total_users,
                'processed_count' => (int) $job->processed_count,
                'error_count'     => (int) $job->error_count,
                'started_at'      => $job->started_at,
                'completed_at'    => $job->completed_at,
            ];
        }

        /**
         * Upload an attachment for bulk messaging.
         */
        public function upload_attachment( WP_REST_Request $request ){
            if ( empty( $_FILES['file'] ) ) {
                return new WP_Error( 'no_file', __('No file uploaded', 'bp-better-messages'), [ 'status' => 400 ] );
            }

            require_once( ABSPATH . 'wp-admin/includes/image.php' );
            require_once( ABSPATH . 'wp-admin/includes/file.php' );
            require_once( ABSPATH . 'wp-admin/includes/media.php' );

            $attachment_id = media_handle_upload( 'file', 0 );

            if ( is_wp_error( $attachment_id ) ) {
                return $attachment_id;
            }

            update_post_meta( $attachment_id, 'bp-better-messages-attachment', true );
            update_post_meta( $attachment_id, 'bp-better-messages-thread-id', 0 );
            update_post_meta( $attachment_id, 'bp-better-messages-bulk-attachment', 1 );
            update_post_meta( $attachment_id, 'bp-better-messages-uploader-user-id', get_current_user_id() );
            update_post_meta( $attachment_id, 'bp-better-messages-upload-time', time() );

            $file_path = get_attached_file( $attachment_id );

            return [
                'id'   => (int) $attachment_id,
                'url'  => wp_get_attachment_url( $attachment_id ),
                'name' => $file_path ? basename( $file_path ) : '',
                'size' => $file_path && file_exists( $file_path ) ? (int) filesize( $file_path ) : 0,
                'type' => (string) get_post_mime_type( $attachment_id ),
            ];
        }

        public function delete_report( WP_REST_Request $request ){
            global $wpdb;
            $job_id = intval( $request->get_param( 'report_id' ) );

            $table = bm_get_table( 'bulk_jobs' );
            $threads_table = bm_get_table( 'bulk_job_threads' );

            $job = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `{$table}` WHERE `id` = %d", $job_id ) );
            if ( ! $job ) {
                return new WP_Error( 'not_found', __('Job not found', 'bp-better-messages'), [ 'status' => 404 ] );
            }

            if ( (int) $job->parent_job_id > 0 ) {
                // Follow-up: only delete this job's messages, not the shared threads
                $message_ids = $wpdb->get_col( $wpdb->prepare(
                    "SELECT `message_id` FROM `{$threads_table}` WHERE `job_id` = %d AND `message_id` > 0",
                    $job_id
                ) );

                foreach ( $message_ids as $message_id ) {
                    Better_Messages()->functions->delete_message( (int) $message_id, false, true, 'delete' );
                }
            } else {
                // Parent: erase entire threads
                $entries = $wpdb->get_results( $wpdb->prepare(
                    "SELECT `thread_id` FROM `{$threads_table}` WHERE `job_id` = %d",
                    $job_id
                ) );

                $thread_ids = array_unique( array_map( function( $entry ) {
                    return (int) $entry->thread_id;
                }, $entries ) );

                foreach ( $thread_ids as $thread_id ) {
                    Better_Messages()->functions->erase_thread( $thread_id );
                }

                // Delete all descendant follow-up jobs and their messages
                $descendant_ids = $this->get_descendant_job_ids( $job_id );
                foreach ( $descendant_ids as $desc_id ) {
                    $desc_message_ids = $wpdb->get_col( $wpdb->prepare(
                        "SELECT `message_id` FROM `{$threads_table}` WHERE `job_id` = %d AND `message_id` > 0",
                        $desc_id
                    ) );
                    foreach ( $desc_message_ids as $message_id ) {
                        Better_Messages()->functions->delete_message( (int) $message_id, false, true, 'delete' );
                    }
                    $wpdb->delete( $threads_table, [ 'job_id' => (int) $desc_id ] );
                    $wpdb->delete( $table, [ 'id' => (int) $desc_id ] );
                }
            }

            // Delete job threads and job record
            $wpdb->delete( $threads_table, [ 'job_id' => $job_id ] );
            $wpdb->delete( $table, [ 'id' => $job_id ] );

            return [ 'success' => true ];
        }

        public function change_report( WP_REST_Request $request ){
            global $wpdb;
            $job_id = intval( $request->get_param( 'report_id' ) );
            $table = bm_get_table( 'bulk_jobs' );

            $job = $wpdb->get_row( $wpdb->prepare( "SELECT `id` FROM `{$table}` WHERE `id` = %d", $job_id ) );
            if ( ! $job ) {
                return new WP_Error( 'not_found', __('Job not found', 'bp-better-messages'), [ 'status' => 404 ] );
            }

            $key   = sanitize_text_field( $request->get_param( 'property' ) );
            $value = sanitize_text_field( $request->get_param( 'value' ) );

            // Map old property names to column names
            $column_map = [
                'disableReply' => 'disable_reply',
            ];

            $column = isset( $column_map[ $key ] ) ? $column_map[ $key ] : null;

            if ( ! $column ) {
                return new WP_Error( 'invalid_property', __('Invalid property', 'bp-better-messages'), [ 'status' => 400 ] );
            }

            $value = intval( $value );

            $wpdb->update( $table, [ $column => $value ], [ 'id' => $job_id ] );

            // Propagate disable_reply to all descendant follow-up jobs
            if ( $column === 'disable_reply' ) {
                $descendant_ids = $this->get_descendant_job_ids( $job_id );
                foreach ( $descendant_ids as $desc_id ) {
                    $wpdb->update( $table, [ 'disable_reply' => $value ], [ 'id' => (int) $desc_id ] );
                }
            }

            return [ 'success' => true ];
        }

        private function get_descendant_job_ids( $job_id ) {
            global $wpdb;
            $table = bm_get_table('bulk_jobs');
            $all_ids = [];
            $parent_ids = [ (int) $job_id ];
            $max_depth = 20;

            while ( ! empty( $parent_ids ) && $max_depth-- > 0 ) {
                $placeholders = implode( ',', array_fill( 0, count( $parent_ids ), '%d' ) );
                $child_ids = $wpdb->get_col( $wpdb->prepare(
                    "SELECT `id` FROM `{$table}` WHERE `parent_job_id` IN ({$placeholders})",
                    ...$parent_ids
                ) );
                if ( empty( $child_ids ) ) break;
                $child_ids  = array_map( 'intval', $child_ids );
                $all_ids    = array_merge( $all_ids, $child_ids );
                $parent_ids = $child_ids;
            }

            return $all_ids;
        }

        private $cached_all_groups = null;

        private function build_target_label( $selectors ) {
            if ( ! is_array( $selectors ) || empty( $selectors ) ) {
                return _x( 'Follow-up', 'Bulk Messages Page', 'bp-better-messages' );
            }

            $is_follow_up_with_new = ! empty( $selectors['include_new_users'] );

            $sent_to = isset( $selectors['sent-to'] ) ? $selectors['sent-to'] : 'all';
            $parts   = [];

            if ( $is_follow_up_with_new ) {
                $parts[] = _x( 'Follow-up + new users', 'Bulk Messages Page', 'bp-better-messages' );
            }

            switch ( $sent_to ) {
                case 'all':
                    $parts[] = _x( 'All Users', 'Bulk Messages Page', 'bp-better-messages' );
                    break;

                case 'role':
                    $roles = isset( $selectors['roles'] ) ? (array) $selectors['roles'] : [];
                    if ( ! empty( $roles ) ) {
                        $wp_roles   = wp_roles()->roles;
                        $role_names = [];
                        foreach ( $roles as $slug ) {
                            $role_names[] = isset( $wp_roles[ $slug ] ) ? $wp_roles[ $slug ]['name'] : $slug;
                        }
                        $parts[] = sprintf(
                            _x( 'Role: %s', 'Bulk Messages Page', 'bp-better-messages' ),
                            implode( ', ', $role_names )
                        );
                    }
                    break;

                case 'group':
                    $group_type = isset( $selectors['group_type'] ) ? $selectors['group_type'] : '';
                    $group_id   = isset( $selectors['group'] ) ? (int) $selectors['group'] : 0;
                    $group_name = '';
                    if ( $this->cached_all_groups === null ) {
                        $this->cached_all_groups = apply_filters( 'better_messages_bulk_get_all_groups', [] );
                    }
                    $all_groups = $this->cached_all_groups;
                    foreach ( $all_groups as $g ) {
                        if ( (int) $g['id'] === $group_id && $g['type'] === $group_type ) {
                            $group_name = $g['name'];
                            break;
                        }
                    }
                    if ( $group_name ) {
                        $parts[] = sprintf(
                            _x( 'Group: %s', 'Bulk Messages Page', 'bp-better-messages' ),
                            $group_name
                        );
                    } else {
                        $parts[] = sprintf(
                            _x( 'Group #%d', 'Bulk Messages Page', 'bp-better-messages' ),
                            $group_id
                        );
                    }
                    break;

                case 'users':
                    $user_ids = isset( $selectors['userIds'] ) ? (array) $selectors['userIds'] : [];
                    $count    = count( $user_ids );
                    if ( $count > 0 ) {
                        $names = [];
                        // Show up to 5 names, then "+ N more"
                        $show_ids = array_slice( $user_ids, 0, 5 );
                        foreach ( $show_ids as $uid ) {
                            $user = get_userdata( (int) $uid );
                            $names[] = $user ? $user->display_name : '#' . $uid;
                        }
                        $label = implode( ', ', $names );
                        if ( $count > 5 ) {
                            $label .= sprintf( ' +%d', $count - 5 );
                        }
                        $parts[] = sprintf(
                            _x( 'Users: %s', 'Bulk Messages Page', 'bp-better-messages' ),
                            $label
                        );
                    }
                    break;
            }

            // Activity filter
            $activity_filter = isset( $selectors['activityFilter'] ) ? $selectors['activityFilter'] : 'any';
            $activity_days   = isset( $selectors['activityDays'] ) ? (int) $selectors['activityDays'] : 30;
            if ( $activity_filter === 'active_within' ) {
                $parts[] = sprintf(
                    _x( 'Active in last %d days', 'Bulk Messages Page', 'bp-better-messages' ),
                    $activity_days
                );
            } elseif ( $activity_filter === 'inactive_for' ) {
                $parts[] = sprintf(
                    _x( 'Inactive for %d days', 'Bulk Messages Page', 'bp-better-messages' ),
                    $activity_days
                );
            }

            return implode( ' · ', $parts );
        }

        public function has_access(){
            if( current_user_can('manage_options') ){
                return true;
            }

            return false;
        }

        public function preview( WP_REST_Request $request ) {
            $selectors = $request->get_param( 'selectors' );

            $exclude_id = null;
            $custom_sender = $request->get_param( 'sender_id' );
            if ( ! empty( $custom_sender ) ) {
                $exclude_id = (int) $custom_sender;
            }

            $user_query = $this->get_user_query( $selectors, false, 1, 20, $exclude_id );
            if( $user_query ) {
                return (int) $user_query->total_users;
            } else {
                return 0;
            }
        }

        public function get_user_query( $selectors, $real_query = false, $page = 1, $per_page = 20, $exclude_id = null, $exclude_job_id = 0 ){
            global $wpdb;

            $users_table   = bm_get_table('users');
            $roles_table   = bm_get_table('roles');
            $threads_table = bm_get_table('bulk_job_threads');
            if ( $exclude_id === null ) {
                $exclude_id = get_current_user_id();
            }

            $sentTo = isset( $selectors['sent-to'] ) ? $selectors['sent-to'] : 'all';

            // Activity filter
            $activity_filter = isset( $selectors['activityFilter'] ) ? $selectors['activityFilter'] : 'any';
            $activity_days   = max( 1, intval( isset( $selectors['activityDays'] ) ? $selectors['activityDays'] : 30 ) );
            $activity_sql    = '';
            if ( $activity_filter === 'active_within' ) {
                $activity_date = gmdate( 'Y-m-d H:i:s', strtotime( "-{$activity_days} days" ) );
                $activity_sql = $wpdb->prepare( " AND `last_activity` >= %s", $activity_date );
            } elseif ( $activity_filter === 'inactive_for' ) {
                $activity_date = gmdate( 'Y-m-d H:i:s', strtotime( "-{$activity_days} days" ) );
                $activity_sql = $wpdb->prepare( " AND `last_activity` < %s", $activity_date );
            }

            // Exclude users already sent to in parent job chain (covers chained follow-ups)
            $exclude_sql = '';
            if ( $exclude_job_id > 0 ) {
                $exclude_sql = $wpdb->prepare(
                    " AND `ID` NOT IN (SELECT DISTINCT `_bt2`.`user_id` FROM `{$threads_table}` AS `_bt1` INNER JOIN `{$threads_table}` AS `_bt2` ON `_bt2`.`thread_id` = `_bt1`.`thread_id` AND `_bt2`.`user_id` > 0 WHERE `_bt1`.`job_id` = %d)",
                    $exclude_job_id
                );
            }

            $result = (object) [
                'total_users' => 0,
                'results'     => [],
            ];

            if( $real_query ){
                if( isset($selectors['singleThread']) && $selectors['singleThread'] ) {
                    $per_page = -1;
                }
            }

            switch ($sentTo){
                case 'all':
                    $result->total_users = (int) $wpdb->get_var( $wpdb->prepare(
                        "SELECT COUNT(*) FROM `{$users_table}` WHERE `ID` > 0 AND `ID` != %d" . $activity_sql . $exclude_sql,
                        $exclude_id
                    ));

                    if( $real_query && $result->total_users > 0 ){
                        if( $per_page === -1 ){
                            $result->results = $wpdb->get_col( $wpdb->prepare(
                                "SELECT `ID` FROM `{$users_table}` WHERE `ID` > 0 AND `ID` != %d" . $activity_sql . $exclude_sql . " ORDER BY `ID`",
                                $exclude_id
                            ));
                        } else {
                            $offset = ( $page - 1 ) * $per_page;
                            $result->results = $wpdb->get_col( $wpdb->prepare(
                                "SELECT `ID` FROM `{$users_table}` WHERE `ID` > 0 AND `ID` != %d" . $activity_sql . $exclude_sql . " ORDER BY `ID` LIMIT %d OFFSET %d",
                                $exclude_id, $per_page, $offset
                            ));
                        }
                    }
                    break;

                case 'role':
                    $roles = isset( $selectors['roles'] ) ? $selectors['roles'] : [];
                    if( ! $roles ) $roles = [];
                    if( ! is_array( $roles ) ) $roles = [ $roles ];
                    if( empty( $roles ) ) break;

                    $placeholders = implode( ',', array_fill( 0, count( $roles ), '%s' ) );
                    $params = array_merge( $roles, [ $exclude_id ] );

                    if ( $activity_sql !== '' || $exclude_sql !== '' ) {
                        $role_join     = " INNER JOIN `{$users_table}` AS `u` ON `u`.`ID` = `{$roles_table}`.`user_id`";
                        $role_activity = str_replace( '`last_activity`', '`u`.`last_activity`', $activity_sql );
                        $role_exclude  = str_replace( '`ID`', '`u`.`ID`', $exclude_sql );
                    } else {
                        $role_join     = '';
                        $role_activity = '';
                        $role_exclude  = '';
                    }

                    $result->total_users = (int) $wpdb->get_var( $wpdb->prepare(
                        "SELECT COUNT(DISTINCT `user_id`) FROM `{$roles_table}`" . $role_join . " WHERE `role` IN ({$placeholders}) AND `user_id` != %d" . $role_activity . $role_exclude,
                        ...$params
                    ));

                    if( $real_query && $result->total_users > 0 ){
                        if( $per_page === -1 ){
                            $result->results = $wpdb->get_col( $wpdb->prepare(
                                "SELECT DISTINCT `user_id` FROM `{$roles_table}`" . $role_join . " WHERE `role` IN ({$placeholders}) AND `user_id` != %d" . $role_activity . $role_exclude . " ORDER BY `user_id`",
                                ...$params
                            ));
                        } else {
                            $offset = ( $page - 1 ) * $per_page;
                            $result->results = $wpdb->get_col( $wpdb->prepare(
                                "SELECT DISTINCT `user_id` FROM `{$roles_table}`" . $role_join . " WHERE `role` IN ({$placeholders}) AND `user_id` != %d" . $role_activity . $role_exclude . " ORDER BY `user_id` LIMIT %d OFFSET %d",
                                ...array_merge( $params, [ $per_page, $offset ] )
                            ));
                        }
                    }
                    break;

                case 'group':
                    $group_type = isset( $selectors['group_type'] ) ? sanitize_text_field( $selectors['group_type'] ) : 'bp';
                    $group_id   = isset( $selectors['group'] ) ? intval( $selectors['group'] ) : 0;

                    $usersIds = apply_filters( 'better_messages_bulk_get_group_members', [], $group_type, $group_id );

                    $usersIds = array_filter( $usersIds, function( $uid ) use ( $exclude_id ) {
                        return (int) $uid !== (int) $exclude_id;
                    });
                    $usersIds = array_values( $usersIds );

                    // Apply activity filter for group mode
                    if ( $activity_sql !== '' && ! empty( $usersIds ) ) {
                        $id_placeholders = implode( ',', array_fill( 0, count( $usersIds ), '%d' ) );
                        $usersIds = $wpdb->get_col( $wpdb->prepare(
                            "SELECT `ID` FROM `{$users_table}` WHERE `ID` IN ({$id_placeholders})" . $activity_sql,
                            ...array_map( 'intval', $usersIds )
                        ));
                    }

                    // Exclude users already in parent job chain
                    if ( $exclude_job_id > 0 && ! empty( $usersIds ) ) {
                        $already_sent = $wpdb->get_col( $wpdb->prepare(
                            "SELECT DISTINCT `bt2`.`user_id` FROM `{$threads_table}` AS `bt1` INNER JOIN `{$threads_table}` AS `bt2` ON `bt2`.`thread_id` = `bt1`.`thread_id` AND `bt2`.`user_id` > 0 WHERE `bt1`.`job_id` = %d",
                            $exclude_job_id
                        ) );
                        $already_sent = array_map( 'intval', $already_sent );
                        $usersIds = array_values( array_diff( array_map( 'intval', $usersIds ), $already_sent ) );
                    }

                    $result->total_users = count( $usersIds );

                    if( $real_query && $result->total_users > 0 ){
                        if( $per_page === -1 ){
                            $result->results = $usersIds;
                        } else {
                            $offset = ( $page - 1 ) * $per_page;
                            $result->results = array_slice( $usersIds, $offset, $per_page );
                        }
                    }
                    break;

                case 'users':
                    $usersIds = isset( $selectors['userIds'] ) ? array_map( 'intval', (array) $selectors['userIds'] ) : [];
                    $usersIds = array_filter( $usersIds, function( $uid ) use ( $exclude_id ) {
                        return $uid > 0 && $uid !== (int) $exclude_id;
                    });
                    $usersIds = array_values( $usersIds );

                    if ( $activity_sql !== '' && ! empty( $usersIds ) ) {
                        $id_placeholders = implode( ',', array_fill( 0, count( $usersIds ), '%d' ) );
                        $usersIds = $wpdb->get_col( $wpdb->prepare(
                            "SELECT `ID` FROM `{$users_table}` WHERE `ID` IN ({$id_placeholders})" . $activity_sql,
                            ...array_map( 'intval', $usersIds )
                        ));
                    }

                    // Exclude users already in parent job chain
                    if ( $exclude_job_id > 0 && ! empty( $usersIds ) ) {
                        $already_sent = $wpdb->get_col( $wpdb->prepare(
                            "SELECT DISTINCT `bt2`.`user_id` FROM `{$threads_table}` AS `bt1` INNER JOIN `{$threads_table}` AS `bt2` ON `bt2`.`thread_id` = `bt1`.`thread_id` AND `bt2`.`user_id` > 0 WHERE `bt1`.`job_id` = %d",
                            $exclude_job_id
                        ) );
                        $already_sent = array_map( 'intval', $already_sent );
                        $usersIds = array_values( array_diff( $usersIds, $already_sent ) );
                    }

                    $result->total_users = count( $usersIds );

                    if ( $real_query && $result->total_users > 0 ) {
                        if ( $per_page === -1 ) {
                            $result->results = $usersIds;
                        } else {
                            $offset = ( $page - 1 ) * $per_page;
                            $result->results = array_slice( $usersIds, $offset, $per_page );
                        }
                    }
                    break;
            }

            return $result;
        }

        public function get_job_threads( WP_REST_Request $request ){
            global $wpdb;

            $job_id   = (int) $request->get_param('id');
            $page     = max( 1, (int) $request->get_param('page') );
            $per_page = 20;
            $offset   = ( $page - 1 ) * $per_page;

            $table            = bm_get_table('bulk_jobs');
            $threads_table    = bm_get_table('bulk_job_threads');
            $recipients_table = bm_get_table('recipients');

            $job = $wpdb->get_row( $wpdb->prepare(
                "SELECT `sender_id`, `parent_job_id` FROM `{$table}` WHERE `id` = %d", $job_id
            ) );

            if ( ! $job ) {
                return new WP_Error( 'not_found', 'Job not found', [ 'status' => 404 ] );
            }

            $sender_id      = (int) $job->sender_id;
            $parent_job_id  = (int) $job->parent_job_id;

            $total = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM `{$threads_table}` WHERE `job_id` = %d", $job_id
            ) );

            $entries = $wpdb->get_results( $wpdb->prepare(
                "SELECT `jt`.`thread_id`, `jt`.`user_id`, `r`.`unread_count`
                 FROM `{$threads_table}` AS `jt`
                 LEFT JOIN `{$recipients_table}` AS `r`
                   ON `jt`.`thread_id` = `r`.`thread_id` AND `r`.`user_id` = `jt`.`user_id`
                 WHERE `jt`.`job_id` = %d
                 ORDER BY `jt`.`id` ASC
                 LIMIT %d OFFSET %d",
                $job_id, $per_page, $offset
            ) );

            // Follow-up entries have user_id=0; resolve from any ancestor job
            if ( $parent_job_id > 0 ) {
                $zero_thread_ids = [];
                foreach ( $entries as $entry ) {
                    if ( (int) $entry->user_id === 0 ) {
                        $zero_thread_ids[] = (int) $entry->thread_id;
                    }
                }

                if ( ! empty( $zero_thread_ids ) ) {
                    $placeholders = implode( ',', array_fill( 0, count( $zero_thread_ids ), '%d' ) );
                    $parent_rows = $wpdb->get_results( $wpdb->prepare(
                        "SELECT `thread_id`, `user_id` FROM `{$threads_table}` WHERE `thread_id` IN ({$placeholders}) AND `user_id` > 0",
                        ...$zero_thread_ids
                    ) );

                    $thread_user_map = [];
                    foreach ( $parent_rows as $row ) {
                        $thread_user_map[ (int) $row->thread_id ] = (int) $row->user_id;
                    }

                    foreach ( $entries as $entry ) {
                        if ( (int) $entry->user_id === 0 && isset( $thread_user_map[ (int) $entry->thread_id ] ) ) {
                            $entry->user_id = $thread_user_map[ (int) $entry->thread_id ];
                        }
                    }
                }
            }

            $threads = [];
            foreach ( $entries as $entry ) {
                $user = Better_Messages()->functions->rest_user_item( (int) $entry->user_id, false );

                $url = Better_Messages()->functions->get_user_thread_url( (int) $entry->thread_id, $sender_id );

                $threads[] = [
                    'thread_id'  => (int) $entry->thread_id,
                    'user'       => $user,
                    'is_read'    => $entry->unread_count !== null && (int) $entry->unread_count === 0,
                    'url'        => $url,
                ];
            }

            return [
                'threads'     => $threads,
                'total'       => $total,
                'page'        => $page,
                'total_pages' => (int) ceil( $total / $per_page ),
            ];
        }

        public function get_reports( WP_REST_Request $request ){
            global $wpdb;
            $return = [
                'reports' => [],
                'roles'   => [],
                'groups'  => []
            ];

            $table = bm_get_table( 'bulk_jobs' );
            $threads_table = bm_get_table( 'bulk_job_threads' );
            $recipients_table = bm_get_table( 'recipients' );

            $jobs = $wpdb->get_results( "SELECT * FROM `{$table}` ORDER BY `id` DESC" );

            if ( $jobs ) {
                foreach ( $jobs as $job ) {
                    $thread_count = (int) $wpdb->get_var( $wpdb->prepare(
                        "SELECT COUNT(*) FROM `{$threads_table}` WHERE `job_id` = %d",
                        $job->id
                    ) );

                    $read_count = 0;
                    if ( $thread_count > 0 ) {
                        $read_count = (int) $wpdb->get_var( $wpdb->prepare(
                            "SELECT COUNT(*)
                            FROM `{$recipients_table}` AS `r`
                            INNER JOIN `{$threads_table}` AS `jt` ON `jt`.`thread_id` = `r`.`thread_id`
                            WHERE `jt`.`job_id` = %d
                            AND `r`.`user_id` != %d
                            AND `r`.`unread_count` = 0",
                            $job->id,
                            (int) $job->sender_id
                        ) );
                    }

                    $attachment_ids = json_decode( $job->attachment_ids, true );

                    $sender_user = get_userdata( (int) $job->sender_id );

                    $selectors_data = json_decode( $job->selectors, true );
                    $target_label   = $this->build_target_label( $selectors_data );

                    $return['reports'][] = [
                        'id'               => (int) $job->id,
                        'subject'          => $job->subject,
                        'sender'           => (int) $job->sender_id,
                        'sender_name'      => $sender_user ? $sender_user->display_name : __( 'Unknown', 'bp-better-messages' ),
                        'status'           => $job->status,
                        'total_users'      => (int) $job->total_users,
                        'processed_count'  => (int) $job->processed_count,
                        'error_count'      => (int) $job->error_count,
                        'count'            => $thread_count,
                        'read'             => $read_count,
                        'disableReply'     => (bool) $job->disable_reply,
                        'attachment_count' => is_array( $attachment_ids ) ? count( $attachment_ids ) : 0,
                        'date'             => $job->created_at,
                        'started_at'       => $job->started_at,
                        'completed_at'     => $job->completed_at,
                        'parent_job_id'    => (int) $job->parent_job_id,
                        'scheduled_at'     => $job->scheduled_at,
                        'batch_size'       => (int) $job->batch_size,
                        'target_label'     => $target_label,
                    ];
                }
            }

            $roles = wp_roles()->roles;

            foreach( $roles as $slug => $role ){
                $return['roles'][] = [
                    'slug' => $slug,
                    'name' => $role['name']
                ];
            }

            $return['groups'] = apply_filters( 'better_messages_bulk_get_all_groups', [] );

            return $return;
        }

        public function send_test( WP_REST_Request $request ) {
            global $wpdb;

            $message = $request->get_param('message');
            $content = Better_Messages()->functions->filter_message_content( $message );

            if ( empty( trim( $content ) ) ) {
                return new WP_Error( 'rest_forbidden', _x( 'Message is empty', 'WP Admin', 'bp-better-messages' ), array( 'status' => 400 ) );
            }

            $current_user_id = get_current_user_id();

            // Clean up old preview messages (older than 24 hours)
            $wpdb->query( $wpdb->prepare(
                "DELETE FROM `" . bm_get_table('messages') . "` WHERE `thread_id` = 0 AND `sender_id` = %d AND `date_sent` < %s",
                $current_user_id,
                gmdate( 'Y-m-d H:i:s', time() - 86400 )
            ) );

            $microtime = Better_Messages()->functions->get_microtime();

            $wpdb->insert( bm_get_table('messages'), array(
                'thread_id'  => 0,
                'sender_id'  => $current_user_id,
                'message'    => $content,
                'date_sent'  => current_time('mysql'),
                'created_at' => $microtime,
                'updated_at' => $microtime,
                'temp_id'    => '',
                'is_pending' => 0,
            ) );

            $message_id = (int) $wpdb->insert_id;

            $subject = sanitize_text_field( $request->get_param('subject') );
            if ( ! empty( $subject ) ) {
                Better_Messages()->functions->update_message_meta( $message_id, 'bulk_preview_subject', $subject );
            }

            $attachment_ids = $request->get_param( 'attachment_ids' );
            if ( is_array( $attachment_ids ) && ! empty( $attachment_ids ) ) {
                $attachment_meta = array();
                foreach ( $attachment_ids as $attachment_id ) {
                    $attachment_id = (int) $attachment_id;
                    $url = wp_get_attachment_url( $attachment_id );
                    if ( $url ) {
                        $attachment_meta[ $attachment_id ] = $url;
                    }
                }
                if ( ! empty( $attachment_meta ) ) {
                    Better_Messages()->functions->update_message_meta( $message_id, 'attachments', $attachment_meta );
                }
            }

            $url = Better_Messages()->functions->add_hash_arg(
                'bulk-preview/' . $message_id, array(),
                Better_Messages()->functions->get_link( $current_user_id )
            );

            return array( 'url' => $url, 'message_id' => $message_id );
        }

        public function get_preview_message( WP_REST_Request $request ) {
            global $wpdb;

            $message_id      = (int) $request->get_param('id');
            $current_user_id = get_current_user_id();

            $message = $wpdb->get_row( $wpdb->prepare(
                "SELECT * FROM `" . bm_get_table('messages') . "` WHERE `id` = %d AND `thread_id` = 0",
                $message_id
            ) );

            if ( ! $message ) {
                return new WP_Error( 'not_found', _x( 'Preview message not found', 'WP Admin', 'bp-better-messages' ), array( 'status' => 404 ) );
            }

            $formatted              = new stdClass();
            $formatted->message_id  = (int) $message->id;
            $formatted->thread_id   = 0;
            $formatted->sender_id   = (int) $message->sender_id;
            $formatted->created_at  = (int) $message->created_at;
            $formatted->updated_at  = (int) $message->updated_at;
            $formatted->favorited   = 0;

            $meta = apply_filters( 'better_messages_rest_message_meta', array(), (int) $message->id, 0, $message->message );
            $formatted->meta = empty( $meta ) ? (object) array() : $meta;

            $formatted->message     = Better_Messages()->functions->format_message(
                $message->message, (int) $message->id, 'stack', $current_user_id
            );

            $subject = Better_Messages()->functions->get_message_meta( $message_id, 'bulk_preview_subject', true );
            $user    = Better_Messages()->functions->rest_user_item( (int) $message->sender_id );

            return array(
                'message' => $formatted,
                'subject' => $subject ? $subject : '',
                'user'    => $user,
            );
        }

    }


    function Better_Messages_Rest_Api_Bulk_Message(){
        return Better_Messages_Rest_Api_Bulk_Message::instance();
    }
endif;
