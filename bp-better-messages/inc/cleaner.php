<?php
defined( 'ABSPATH' ) || exit;

if ( !class_exists( 'Better_Messages_Cleaner' ) ):

    class Better_Messages_Cleaner
    {   public static function instance()
        {

            static $instance = null;

            if ( null === $instance ) {
                $instance = new Better_Messages_Cleaner();
            }

            return $instance;
        }

        public function __construct()
        {
            add_action( 'admin_init', array( $this, 'register_event' ) );
            add_action( 'better_messages_cleaner_job', array( $this, 'clean_deleted_messages_meta' ) );
            add_action( 'better_messages_cleaner_job', array( $this, 'clean_old_messages' ) );
            add_action( 'better_messages_cleaner_job', array( $this, 'clean_preview_messages' ) );
            add_action( 'better_messages_cleaner_job', array( $this, 'clean_orphaned_bulk_attachments' ) );
            add_action( 'better_messages_cleaner_job', array( $this, 'clean_old_voice_messages' ) );
            add_action( 'better_messages_cleaner_job', array( $this, 'clean_inactive_chat_users' ) );
        }

        public function register_event(){
            if ( ! wp_next_scheduled( 'better_messages_cleaner_job' ) ) {
                wp_schedule_event( time(), 'better_messages_cleaner_job', 'better_messages_cleaner_job' );
            }
        }

        public function clean_deleted_messages_meta(){
            $time = Better_Messages()->functions->to_microtime(strtotime('-1 month'));
            global $wpdb;
            $sql = $wpdb->prepare("DELETE FROM `" . bm_get_table('meta') . "` WHERE `meta_key` = 'bm_deleted_time' AND `meta_value` <= %d", $time );
            $wpdb->query( $sql );
        }

        public function clean_old_messages()
        {
            global $wpdb;

            $old_days = (int) Better_Messages()->settings['deleteOldMessages'];

            if( $old_days > 0 ){
                $old_time = strtotime("-$old_days days");

                if ($old_time === false) {
                    return;
                }

                $batch_size = apply_filters( 'better_messages_delete_old_messages_batch_size', 100 );

                $table = bm_get_table('messages');

                $sql = $wpdb->prepare("
                SELECT `id`
                FROM `{$table}`
                WHERE LEFT(`created_at`, 10) <= %d
                ORDER BY `{$table}`.`created_at` ASC
                LIMIT 0, %d", $old_time, $batch_size);

                $old_messages = array_map('intval', $wpdb->get_col( $sql ));

                if( !empty( $old_messages ) ) {
                    foreach( $old_messages as $message_id ) {
                        Better_Messages()->functions->delete_message( $message_id, false, true, 'delete');
                    }
                }
            }
        }

        public function clean_orphaned_bulk_attachments(){
            global $wpdb;

            $two_hours_ago = strtotime('-2 hours');

            $sql = $wpdb->prepare("
                SELECT `posts`.ID
                FROM {$wpdb->posts} `posts`
                INNER JOIN {$wpdb->postmeta} `bulk_meta`
                    ON `posts`.ID = `bulk_meta`.post_id
                    AND `bulk_meta`.meta_key = 'bp-better-messages-bulk-attachment'
                INNER JOIN {$wpdb->postmeta} `time_meta`
                    ON `posts`.ID = `time_meta`.post_id
                    AND `time_meta`.meta_key = 'bp-better-messages-upload-time'
                    AND `time_meta`.meta_value <= %d
                WHERE `posts`.post_type = 'attachment'
                LIMIT 50
            ", $two_hours_ago );

            $orphaned = $wpdb->get_col( $sql );

            if ( ! empty( $orphaned ) ) {
                foreach ( $orphaned as $attachment_id ) {
                    wp_delete_attachment( (int) $attachment_id, true );
                }
            }
        }

        public function clean_old_voice_messages()
        {
            $days = (int) Better_Messages()->settings['voiceMessagesAutoDelete'];

            if ( $days <= 0 ) {
                return;
            }

            $mode = Better_Messages()->settings['voiceMessagesAutoDeleteMode'];
            $old_time = strtotime( "-$days days" );

            if ( $old_time === false ) {
                return;
            }

            global $wpdb;

            $batch_size   = apply_filters( 'better_messages_delete_old_voice_messages_batch_size', 100 );
            $table        = bm_get_table( 'messages' );
            $meta_table   = bm_get_table( 'meta' );

            $sql = $wpdb->prepare(
                "SELECT m.`id`
                 FROM `{$table}` m
                 INNER JOIN `{$meta_table}` mm
                    ON m.`id` = mm.`bm_message_id`
                    AND mm.`meta_key` = 'bpbm_voice_messages'
                 WHERE LEFT(m.`created_at`, 10) <= %d
                 ORDER BY m.`created_at` ASC
                 LIMIT 0, %d",
                $old_time,
                $batch_size
            );

            $message_ids = array_map( 'intval', $wpdb->get_col( $sql ) );

            if ( empty( $message_ids ) ) {
                return;
            }

            if ( $mode === 'complete' ) {
                foreach ( $message_ids as $message_id ) {
                    Better_Messages()->functions->delete_message( $message_id, false, true, 'delete' );
                }
            } else {
                // Replace mode: remove audio file, keep message with expired marker
                foreach ( $message_ids as $message_id ) {
                    $message = Better_Messages()->functions->get_message( $message_id );

                    if ( ! $message ) {
                        continue;
                    }

                    $attachment_id = Better_Messages()->functions->get_message_meta( $message_id, 'bpbm_voice_messages', true );

                    if ( $attachment_id ) {
                        $file_path = get_attached_file( (int) $attachment_id );
                        wp_delete_attachment( (int) $attachment_id, true );
                        if ( $file_path && function_exists( 'Better_Messages_Files' ) ) {
                            Better_Messages()->files->cleanup_empty_directories( $file_path );
                        }
                    }

                    Better_Messages()->functions->delete_message_meta( $message_id, 'bpbm_voice_messages' );

                    Better_Messages()->functions->update_message( array(
                        'sender_id'    => $message->sender_id,
                        'thread_id'    => $message->thread_id,
                        'message_id'   => $message_id,
                        'content'      => '<!-- BM-VOICE-MESSAGE-EXPIRED -->',
                        'send_push'    => false,
                        'mobile_push'  => false,
                        'count_unread' => false,
                    ) );
                }
            }
        }

        public function clean_inactive_chat_users()
        {
            $chat_ids = get_posts( array(
                'post_type'      => 'bpbm-chat',
                'post_status'    => array( 'publish', 'draft' ),
                'posts_per_page' => -1,
                'fields'         => 'ids',
                'no_found_rows'  => true,
            ) );

            if ( empty( $chat_ids ) ) {
                return;
            }

            global $wpdb;

            $batch_size  = (int) apply_filters( 'better_messages_clean_inactive_chat_users_batch_size', 100 );
            $exclude_ids = (array) apply_filters( 'better_messages_clean_inactive_chat_users_exclude_user_ids', array() );
            $exclude_ids = array_map( 'intval', $exclude_ids );

            if ( $batch_size <= 0 ) {
                return;
            }

            $recipients_table = bm_get_table( 'recipients' );
            $bm_users_table   = bm_get_table( 'users' );
            $messages_table   = bm_get_table( 'messages' );

            foreach ( $chat_ids as $chat_id ) {
                $settings = Better_Messages()->chats->get_chat_settings( (int) $chat_id );

                if ( $settings['auto_remove_inactive'] !== '1' ) {
                    continue;
                }

                $days = (int) $settings['auto_remove_inactive_days'];
                if ( $days <= 0 ) {
                    continue;
                }

                $mode = $settings['auto_remove_inactive_mode'];
                if ( ! in_array( $mode, Better_Messages_Chats::AUTO_REMOVE_INACTIVE_MODES, true ) ) {
                    $mode = 'site';
                }

                $thread_id = Better_Messages()->chats->get_chat_thread_id( (int) $chat_id );
                if ( ! $thread_id ) {
                    continue;
                }

                $cutoff_unix = strtotime( "-{$days} days" );
                if ( $cutoff_unix === false ) {
                    continue;
                }
                $cutoff_datetime = gmdate( 'Y-m-d H:i:s', $cutoff_unix );

                if ( $mode === 'site' ) {
                    $sql = $wpdb->prepare(
                        "SELECT r.user_id
                         FROM `{$recipients_table}` r
                         LEFT JOIN {$wpdb->users} u ON u.ID = r.user_id
                         LEFT JOIN `{$bm_users_table}` bmu ON bmu.ID = r.user_id
                         WHERE r.thread_id = %d
                           AND ( ( r.user_id >= 0 AND u.ID IS NOT NULL ) OR ( r.user_id < 0 ) )
                           AND ( bmu.last_activity IS NULL OR bmu.last_activity < %s )
                         LIMIT %d",
                        $thread_id,
                        $cutoff_datetime,
                        $batch_size
                    );
                } elseif ( $mode === 'no-message' ) {
                    $sql = $wpdb->prepare(
                        "SELECT r.user_id
                         FROM `{$recipients_table}` r
                         LEFT JOIN {$wpdb->users} u ON u.ID = r.user_id
                         LEFT JOIN (
                             SELECT sender_id, MAX( LEFT(created_at, 10) ) AS last_msg_unix
                             FROM `{$messages_table}`
                             WHERE thread_id = %d
                             GROUP BY sender_id
                         ) lm ON lm.sender_id = r.user_id
                         WHERE r.thread_id = %d
                           AND ( ( r.user_id >= 0 AND u.ID IS NOT NULL ) OR ( r.user_id < 0 ) )
                           AND ( lm.last_msg_unix IS NULL OR lm.last_msg_unix < %d )
                         LIMIT %d",
                        $thread_id,
                        $thread_id,
                        $cutoff_unix,
                        $batch_size
                    );
                } else {
                    $sql = $wpdb->prepare(
                        "SELECT r.user_id
                         FROM `{$recipients_table}` r
                         LEFT JOIN {$wpdb->users} u ON u.ID = r.user_id
                         WHERE r.thread_id = %d
                           AND ( ( r.user_id >= 0 AND u.ID IS NOT NULL ) OR ( r.user_id < 0 ) )
                           AND r.last_read < %s
                         LIMIT %d",
                        $thread_id,
                        $cutoff_datetime,
                        $batch_size
                    );
                }

                $user_ids = array_map( 'intval', $wpdb->get_col( $sql ) );

                foreach ( $user_ids as $user_id ) {
                    if ( in_array( $user_id, $exclude_ids, true ) ) {
                        continue;
                    }

                    if ( Better_Messages()->functions->is_ai_bot_user( $user_id ) ) {
                        continue;
                    }

                    Better_Messages()->functions->remove_participant_from_thread( $thread_id, $user_id );
                }
            }
        }

        public function clean_preview_messages(){
            global $wpdb;

            $one_hour_ago = Better_Messages()->functions->to_microtime( strtotime('-1 hour') );
            $table        = bm_get_table('messages');
            $meta_table   = bm_get_table('meta');

            $ids = $wpdb->get_col( $wpdb->prepare(
                "SELECT `id` FROM `{$table}` WHERE `thread_id` = 0 AND `created_at` <= %d",
                $one_hour_ago
            ) );

            if ( ! empty( $ids ) ) {
                $placeholders = implode( ',', array_map( 'intval', $ids ) );
                $wpdb->query( "DELETE FROM `{$meta_table}` WHERE `bm_message_id` IN ({$placeholders})" );
                $wpdb->query( "DELETE FROM `{$table}` WHERE `id` IN ({$placeholders})" );
            }
        }
    }

endif;

function Better_Messages_Cleaner()
{
    return Better_Messages_Cleaner::instance();
}
