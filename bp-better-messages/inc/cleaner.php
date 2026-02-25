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
                    error_log('Failed to calculate old time for message deletion.');
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
