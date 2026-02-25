<?php
defined( 'ABSPATH' ) || exit;

if ( !class_exists( 'Better_Messages_Bulk_Sender' ) ):

    class Better_Messages_Bulk_Sender
    {
        public static function instance()
        {
            static $instance = null;

            if ( null === $instance ) {
                $instance = new Better_Messages_Bulk_Sender();
            }

            return $instance;
        }

        public function __construct()
        {
            add_action( 'admin_init', array( $this, 'register_event' ) );
            add_action( 'better_messages_bulk_sender_job', array( $this, 'process_queue' ) );
        }

        public function register_event()
        {
            if ( ! wp_next_scheduled( 'better_messages_bulk_sender_job' ) ) {
                wp_schedule_event( time(), 'one_minute', 'better_messages_bulk_sender_job' );
            }
        }

        /**
         * Process the bulk message queue.
         * Called by WP Cron every minute.
         */
        public function process_queue()
        {
            global $wpdb;

            // Prevent overlapping runs
            if ( get_transient( 'better_messages_bulk_processing_lock' ) ) {
                return;
            }

            // Set lock immediately to minimize race window
            set_transient( 'better_messages_bulk_processing_lock', 1, 300 );

            $table = bm_get_table( 'bulk_jobs' );

            // Activate scheduled jobs that are due
            $now = current_time( 'mysql' );
            $wpdb->query( $wpdb->prepare(
                "UPDATE `{$table}` SET `status` = 'processing', `started_at` = %s
                 WHERE `status` = 'pending' AND `scheduled_at` IS NOT NULL AND `scheduled_at` <= %s",
                $now, $now
            ) );

            // Find the oldest processing job
            $job = $wpdb->get_row( "SELECT * FROM `{$table}` WHERE `status` = 'processing' ORDER BY `id` ASC LIMIT 1" );

            if ( ! $job ) {
                delete_transient( 'better_messages_bulk_processing_lock' );
                return;
            }

            set_time_limit( 0 );
            ignore_user_abort( true );

            try {
                $this->process_job( $job );
            } catch ( \Throwable $e ) {
                $this->log_error( $job->id, 0, $e->getMessage() );
            } finally {
                delete_transient( 'better_messages_bulk_processing_lock' );
            }
        }

        /**
         * Process a single bulk job batch.
         */
        private function process_job( $job )
        {
            global $wpdb;

            $table = bm_get_table( 'bulk_jobs' );
            $threads_table = bm_get_table( 'bulk_job_threads' );
            $batch_size = (int) $job->batch_size > 0
                ? (int) $job->batch_size
                : (int) apply_filters( 'better_messages_bulk_batch_size', 20 );

            $selectors = json_decode( $job->selectors, true );
            if ( ! is_array( $selectors ) ) {
                $this->log_error( $job->id, 0, 'Invalid selectors JSON' );
                $wpdb->update( $table, [
                    'status'       => 'failed',
                    'completed_at' => current_time( 'mysql' ),
                ], [ 'id' => $job->id ] );
                return;
            }

            $attachment_ids = json_decode( $job->attachment_ids, true );
            if ( ! is_array( $attachment_ids ) ) {
                $attachment_ids = [];
            }

            // Set started_at on first run
            if ( empty( $job->started_at ) ) {
                $wpdb->update( $table, [ 'started_at' => current_time( 'mysql' ) ], [ 'id' => $job->id ] );
            }

            // Follow-up mode: send to threads from parent job
            if ( (int) $job->parent_job_id > 0 ) {
                $this->process_follow_up( $job, $attachment_ids );
                return;
            }

            // Single thread mode: send all recipients in one batch
            if ( $job->single_thread ) {
                $this->process_single_thread( $job, $selectors, $attachment_ids );
                return;
            }

            // Per-user thread mode: paginated processing
            $current_page = (int) $job->current_page;

            $user_query = $this->get_user_query( $selectors, $job->sender_id, true, $current_page, $batch_size );

            if ( ! $user_query || empty( $user_query->results ) ) {
                // No more users to process — mark as completed
                $wpdb->update( $table, [
                    'status'       => 'completed',
                    'completed_at' => current_time( 'mysql' ),
                ], [ 'id' => $job->id ] );
                return;
            }

            // Disable attachment thread_id validation for bulk sending
            $disable_check = ! empty( $attachment_ids );
            if ( $disable_check ) {
                add_filter( 'better_messages_ensure_file_is_not_from_other_gallery', '__return_false' );
            }

            $processed_in_batch = 0;

            foreach ( $user_query->results as $user_id ) {
                // Re-check job status (might have been paused/cancelled)
                $current_status = $wpdb->get_var( $wpdb->prepare(
                    "SELECT `status` FROM `{$table}` WHERE `id` = %d", $job->id
                ) );

                if ( $current_status !== 'processing' ) {
                    break;
                }

                try {
                    $result = $this->send_to_user( $job, $selectors, $user_id, $attachment_ids );

                    if ( $result ) {
                        $insert_data = [
                            'job_id'     => $job->id,
                            'thread_id'  => $result['thread_id'],
                            'message_id' => $result['message_id'],
                            'user_id'    => $user_id,
                        ];

                        $wpdb->insert( $threads_table, $insert_data );
                    }

                    $wpdb->query( $wpdb->prepare(
                        "UPDATE `{$table}` SET `processed_count` = `processed_count` + 1 WHERE `id` = %d",
                        $job->id
                    ) );

                } catch ( \Throwable $e ) {
                    $this->log_error( $job->id, $user_id, $e->getMessage() );

                    $wpdb->query( $wpdb->prepare(
                        "UPDATE `{$table}` SET `processed_count` = `processed_count` + 1, `error_count` = `error_count` + 1 WHERE `id` = %d",
                        $job->id
                    ) );
                }

                $processed_in_batch++;

                // Flush cache periodically
                if ( $processed_in_batch % 50 === 0 ) {
                    wp_cache_flush();
                }
            }

            if ( $disable_check ) {
                remove_filter( 'better_messages_ensure_file_is_not_from_other_gallery', '__return_false' );
            }

            // Move to next page
            $new_page = $current_page + 1;

            // Check if we're done
            $job_state = $wpdb->get_row( $wpdb->prepare(
                "SELECT `processed_count`, `total_users`, `status` FROM `{$table}` WHERE `id` = %d", $job->id
            ) );

            if ( $job_state && $job_state->status === 'processing' ) {
                if ( (int) $job_state->processed_count >= (int) $job_state->total_users ) {
                    $wpdb->update( $table, [
                        'status'       => 'completed',
                        'completed_at' => current_time( 'mysql' ),
                        'current_page' => $new_page,
                    ], [ 'id' => $job->id ] );
                } else {
                    $wpdb->update( $table, [
                        'current_page' => $new_page,
                    ], [ 'id' => $job->id ] );
                }
            }
        }

        /**
         * Handle single-thread mode (all recipients in one conversation).
         */
        private function process_single_thread( $job, $selectors, $attachment_ids )
        {
            global $wpdb;

            $table = bm_get_table( 'bulk_jobs' );
            $threads_table = bm_get_table( 'bulk_job_threads' );

            $user_query = $this->get_user_query( $selectors, $job->sender_id, true, 1, -1 );

            if ( ! $user_query || empty( $user_query->results ) ) {
                $wpdb->update( $table, [
                    'status'       => 'completed',
                    'completed_at' => current_time( 'mysql' ),
                ], [ 'id' => $job->id ] );
                return;
            }

            $user_ids = array_map( 'intval', $user_query->results );

            $disable_check = ! empty( $attachment_ids );
            if ( $disable_check ) {
                add_filter( 'better_messages_ensure_file_is_not_from_other_gallery', '__return_false' );
            }

            $args = array(
                'sender_id'     => (int) $job->sender_id,
                'subject'       => sanitize_text_field( $job->subject ),
                'content'       => $job->message,
                'error_type'    => 'wp_error',
                'bulk_hide'     => (bool) $job->hide_thread,
                'recipients'    => $user_ids,
                'attachments'   => $attachment_ids,
            );

            $thread_id = Better_Messages()->functions->new_message( $args );

            if ( $disable_check ) {
                remove_filter( 'better_messages_ensure_file_is_not_from_other_gallery', '__return_false' );
            }

            if ( is_wp_error( $thread_id ) ) {
                $this->log_error( $job->id, 0, $thread_id->get_error_message() );
                $wpdb->update( $table, [
                    'status'       => 'failed',
                    'error_count'  => 1,
                ], [ 'id' => $job->id ] );
                return;
            }

            $thread_id = (int) $thread_id;

            $wpdb->insert( $threads_table, [
                'job_id'     => $job->id,
                'thread_id'  => $thread_id,
                'message_id' => 0,
                'user_id'    => 0,
            ] );

            if ( $job->hide_thread ) {
                Better_Messages()->functions->archive_thread( (int) $job->sender_id, $thread_id );
            }

            $wpdb->update( $table, [
                'status'          => 'completed',
                'processed_count' => (int) $job->total_users,
                'completed_at'    => current_time( 'mysql' ),
            ], [ 'id' => $job->id ] );
        }

        /**
         * Handle follow-up mode: send a new message into threads from parent job.
         */
        private function process_follow_up( $job, $attachment_ids )
        {
            global $wpdb;

            $table         = bm_get_table('bulk_jobs');
            $threads_table = bm_get_table('bulk_job_threads');
            $batch_size    = (int) $job->batch_size > 0
                ? (int) $job->batch_size
                : (int) apply_filters( 'better_messages_bulk_batch_size', 20 );
            $current_page  = (int) $job->current_page;
            $offset        = ( $current_page - 1 ) * $batch_size;

            $selectors         = json_decode( $job->selectors, true );
            $include_new_users = is_array( $selectors ) && ! empty( $selectors['include_new_users'] );

            // Phase 1: Process existing parent threads
            $thread_ids = $wpdb->get_col( $wpdb->prepare(
                "SELECT DISTINCT `thread_id` FROM `{$threads_table}` WHERE `job_id` = %d ORDER BY `id` ASC LIMIT %d OFFSET %d",
                (int) $job->parent_job_id, $batch_size, $offset
            ) );

            if ( empty( $thread_ids ) && ! $include_new_users ) {
                $wpdb->update( $table, [
                    'status'       => 'completed',
                    'completed_at' => current_time('mysql'),
                ], [ 'id' => $job->id ] );
                return;
            }

            $disable_check = ! empty( $attachment_ids );
            if ( $disable_check ) {
                add_filter( 'better_messages_ensure_file_is_not_from_other_gallery', '__return_false' );
            }

            $processed_in_batch = 0;

            if ( ! empty( $thread_ids ) ) {
                // Phase 1: send into existing threads
                foreach ( $thread_ids as $thread_id ) {
                    $thread_id = (int) $thread_id;

                    $current_status = $wpdb->get_var( $wpdb->prepare(
                        "SELECT `status` FROM `{$table}` WHERE `id` = %d", $job->id
                    ) );

                    if ( $current_status !== 'processing' ) {
                        break;
                    }

                    try {
                        $args = array(
                            'sender_id'     => (int) $job->sender_id,
                            'thread_id'     => $thread_id,
                            'content'       => $job->message,
                            'return'        => 'message_id',
                            'error_type'    => 'wp_error',
                            'bulk_hide'     => (bool) $job->hide_thread,
                            'attachments'   => $attachment_ids,
                        );

                        $message_id = Better_Messages()->functions->new_message( $args );

                        if ( is_wp_error( $message_id ) ) {
                            throw new \RuntimeException( $message_id->get_error_message() );
                        }

                        $wpdb->insert( $threads_table, [
                            'job_id'     => $job->id,
                            'thread_id'  => $thread_id,
                            'message_id' => (int) $message_id,
                            'user_id'    => 0,
                        ] );

                        $wpdb->query( $wpdb->prepare(
                            "UPDATE `{$table}` SET `processed_count` = `processed_count` + 1 WHERE `id` = %d",
                            $job->id
                        ) );
                    } catch ( \Throwable $e ) {
                        $this->log_error( $job->id, 0, $e->getMessage() );

                        $wpdb->query( $wpdb->prepare(
                            "UPDATE `{$table}` SET `processed_count` = `processed_count` + 1, `error_count` = `error_count` + 1 WHERE `id` = %d",
                            $job->id
                        ) );
                    }

                    $processed_in_batch++;

                    if ( $processed_in_batch % 50 === 0 ) {
                        wp_cache_flush();
                    }
                }
            } else {
                // Phase 2: send to new users not in parent job
                $new_users_sent = (int) $wpdb->get_var( $wpdb->prepare(
                    "SELECT COUNT(*) FROM `{$threads_table}` WHERE `job_id` = %d AND `user_id` > 0",
                    $job->id
                ) );

                $new_user_page = (int) floor( $new_users_sent / $batch_size ) + 1;
                $user_query    = $this->get_user_query( $selectors, $job->sender_id, true, $new_user_page, $batch_size, (int) $job->parent_job_id );

                if ( ! $user_query || empty( $user_query->results ) ) {
                    $wpdb->update( $table, [
                        'status'       => 'completed',
                        'completed_at' => current_time('mysql'),
                    ], [ 'id' => $job->id ] );

                    if ( $disable_check ) {
                        remove_filter( 'better_messages_ensure_file_is_not_from_other_gallery', '__return_false' );
                    }
                    return;
                }

                foreach ( $user_query->results as $user_id ) {
                    $user_id = (int) $user_id;

                    $current_status = $wpdb->get_var( $wpdb->prepare(
                        "SELECT `status` FROM `{$table}` WHERE `id` = %d", $job->id
                    ) );

                    if ( $current_status !== 'processing' ) {
                        break;
                    }

                    try {
                        $result = $this->send_to_user( $job, $selectors, $user_id, $attachment_ids );

                        if ( $result ) {
                            $wpdb->insert( $threads_table, [
                                'job_id'     => $job->id,
                                'thread_id'  => $result['thread_id'],
                                'message_id' => $result['message_id'],
                                'user_id'    => $user_id,
                            ] );
                        }

                        $wpdb->query( $wpdb->prepare(
                            "UPDATE `{$table}` SET `processed_count` = `processed_count` + 1 WHERE `id` = %d",
                            $job->id
                        ) );
                    } catch ( \Throwable $e ) {
                        $this->log_error( $job->id, $user_id, $e->getMessage() );

                        $wpdb->query( $wpdb->prepare(
                            "UPDATE `{$table}` SET `processed_count` = `processed_count` + 1, `error_count` = `error_count` + 1 WHERE `id` = %d",
                            $job->id
                        ) );
                    }

                    $processed_in_batch++;

                    if ( $processed_in_batch % 50 === 0 ) {
                        wp_cache_flush();
                    }
                }
            }

            if ( $disable_check ) {
                remove_filter( 'better_messages_ensure_file_is_not_from_other_gallery', '__return_false' );
            }

            $new_page  = $current_page + 1;
            $job_state = $wpdb->get_row( $wpdb->prepare(
                "SELECT `processed_count`, `total_users`, `status` FROM `{$table}` WHERE `id` = %d", $job->id
            ) );

            if ( $job_state && $job_state->status === 'processing' ) {
                if ( (int) $job_state->processed_count >= (int) $job_state->total_users ) {
                    $wpdb->update( $table, [
                        'status'       => 'completed',
                        'completed_at' => current_time('mysql'),
                        'current_page' => $new_page,
                    ], [ 'id' => $job->id ] );
                } else {
                    $wpdb->update( $table, [
                        'current_page' => $new_page,
                    ], [ 'id' => $job->id ] );
                }
            }
        }

        private function send_to_user( $job, $selectors, $user_id, $attachment_ids )
        {
            $args = array(
                'sender_id'     => (int) $job->sender_id,
                'subject'       => sanitize_text_field( $job->subject ),
                'content'       => $job->message,
                'return'        => 'both',
                'error_type'    => 'wp_error',
                'bulk_hide'     => (bool) $job->hide_thread,
                'recipients'    => array( $user_id ),
                'attachments'   => $attachment_ids,
            );

            $result = Better_Messages()->functions->new_message( $args );

            if ( is_wp_error( $result ) ) {
                throw new \RuntimeException( $result->get_error_message() );
            }

            $thread_id  = $result['thread_id'];
            $message_id = $result['message_id'];

            if ( $job->hide_thread ) {
                Better_Messages()->functions->archive_thread( (int) $job->sender_id, $thread_id );
            }

            return [
                'thread_id'  => (int) $thread_id,
                'message_id' => (int) $message_id,
            ];
        }

        /**
         * Log an error for a bulk job.
         */
        private function log_error( $job_id, $user_id, $error_message )
        {
            global $wpdb;
            $table = bm_get_table( 'bulk_jobs' );

            $job = $wpdb->get_row( $wpdb->prepare(
                "SELECT `error_log` FROM `{$table}` WHERE `id` = %d", $job_id
            ) );

            $error_log = [];
            if ( $job && ! empty( $job->error_log ) ) {
                $error_log = json_decode( $job->error_log, true );
                if ( ! is_array( $error_log ) ) {
                    $error_log = [];
                }
            }

            $error_log[] = [
                'user_id' => $user_id,
                'error'   => $error_message,
                'time'    => current_time( 'mysql' ),
            ];

            // Keep only the last 100 errors to prevent unbounded growth
            if ( count( $error_log ) > 100 ) {
                $error_log = array_slice( $error_log, -100 );
            }

            $wpdb->update( $table, [
                'error_log' => wp_json_encode( $error_log ),
            ], [ 'id' => $job_id ] );
        }

        /**
         * Get user query for recipient selection.
         * Uses plugin tables (bm_user_index, bm_user_roles_index) for fast lookups.
         */
        public function get_user_query( $selectors, $sender_id, $real_query = false, $page = 1, $per_page = 20, $exclude_job_id = 0 )
        {
            global $wpdb;

            $users_table   = bm_get_table('users');
            $roles_table   = bm_get_table('roles');
            $threads_table = bm_get_table('bulk_job_threads');

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

            switch ( $sentTo ) {
                case 'all':
                    $where = $wpdb->prepare( "`ID` > 0 AND `ID` != %d", $sender_id ) . $activity_sql . $exclude_sql;

                    $result->total_users = (int) $wpdb->get_var(
                        "SELECT COUNT(*) FROM `{$users_table}` WHERE {$where}"
                    );

                    if( $real_query && $result->total_users > 0 ){
                        if( $per_page === -1 ){
                            $result->results = $wpdb->get_col(
                                "SELECT `ID` FROM `{$users_table}` WHERE {$where} ORDER BY `ID`"
                            );
                        } else {
                            $offset = ( $page - 1 ) * $per_page;
                            $result->results = $wpdb->get_col( $wpdb->prepare(
                                "SELECT `ID` FROM `{$users_table}` WHERE {$where} ORDER BY `ID` LIMIT %d OFFSET %d",
                                $per_page, $offset
                            ));
                        }
                    }
                    break;

                case 'role':
                    $roles = isset( $selectors['roles'] ) ? $selectors['roles'] : [];
                    if ( ! $roles ) $roles = [];
                    if ( ! is_array( $roles ) ) $roles = [ $roles ];
                    if ( empty( $roles ) ) break;

                    $placeholders = implode( ',', array_fill( 0, count( $roles ), '%s' ) );
                    $params = array_merge( $roles, [ $sender_id ] );

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

                    $usersIds = array_filter( $usersIds, function( $uid ) use ( $sender_id ) {
                        return (int) $uid !== (int) $sender_id;
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
                    $usersIds = array_filter( $usersIds, function( $uid ) use ( $sender_id ) {
                        return $uid > 0 && $uid !== (int) $sender_id;
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
    }

endif;

function Better_Messages_Bulk_Sender()
{
    return Better_Messages_Bulk_Sender::instance();
}
