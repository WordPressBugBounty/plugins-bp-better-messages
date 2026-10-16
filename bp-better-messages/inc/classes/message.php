<?php
if( ! class_exists( 'BM_Messages_Message' ) ):
    class BM_Messages_Message {
        public $id;

        public $thread_id;

        public $sender_id;

        public $subject;

        public $message;

        public $date_sent;

        public $created_at;

        public $updated_at;

        public $temp_id;

        public $is_pending;

        public $skip_moderation = false;

        public $recipients = false;

        public $count_unread = true;

        public $send_push = true;

        public $bulk_hide = false;

        public $send_global = true;

        public $show_on_site = true;

        public $mobile_push = true;

        public $meta = false;

        public $notification = false;

        public $is_update = false;

        public $new_thread = false;

        public $ai_moderation_result = null;

        public $ai_moderation_provider = null;

        public $ai_moderation_deferred = false;

        public $push_intent = null;

        public $mobile_push_intent = null;

        public $push_deferred = false;

        public $notify_everyone = false;

        public $notify_exclude = array();

        public $unread_exclude = array();

        public function __construct( $id = null ) {
            $this->date_sent = bp_core_current_time();
            $this->sender_id = Better_Messages()->functions->get_current_user_id();

            if ( ! empty( $id ) ) {
                $this->populate( $id );
            }
        }

        public function populate( $id ) {
            global $wpdb;

            if ( $message = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM " . bm_get_table('messages') . " WHERE id = %d", $id ) ) ) {
                $this->id        = (int) $message->id;
                $this->thread_id = (int) $message->thread_id;
                $this->sender_id = (int) $message->sender_id;
                $this->message   = $message->message;
                $this->date_sent = $message->date_sent;
                $this->created_at = $message->created_at;
                $this->updated_at = $message->updated_at;
            }
        }

        public function send() {
            global $wpdb;

            $this->sender_id = apply_filters( 'better_messages_message_sender_id_before_save', $this->sender_id, $this->id );
            $this->thread_id = apply_filters( 'better_messages_message_thread_id_before_save', $this->thread_id, $this->id );
            $this->subject   = apply_filters( 'better_messages_message_subject_before_save', $this->subject, $this->id );
            $this->message   = apply_filters( 'better_messages_message_content_before_save', $this->message, $this->id );
            $this->date_sent = apply_filters( 'better_messages_message_date_sent_before_save', $this->date_sent, $this->id );
            $this->created_at = apply_filters( 'better_messages_message_created_at_before_save', $this->created_at, $this->id );
            $this->updated_at = apply_filters( 'better_messages_message_updated_at_before_save', $this->updated_at, $this->id );
            $this->temp_id = apply_filters( 'better_messages_message_temp_id_before_save', $this->temp_id, $this->id );
            $this->is_pending = apply_filters( 'better_messages_message_is_pending_before_save', $this->is_pending, $this->id );

            do_action_ref_array( 'better_messages_message_before_save', array( &$this ) );

            if ( empty( $this->recipients ) && ! Better_Messages_Chats()->is_ephemeral_thread( $this->thread_id ) ) {
                return false;
            }

            if( ! $wpdb->has_cap( 'utf8mb4' ) ){
                $this->subject = wp_encode_emoji( $this->subject );
                $this->message = wp_encode_emoji( $this->message );
            }

            $new_thread = false;

            if ( empty( $this->thread_id ) ) {
                if( ! $wpdb->insert( bm_get_table('threads'), [ 'subject' => $this->subject ], [ '%s' ] ) ){
                    return false;
                }

                $this->thread_id = $wpdb->insert_id;
                $new_thread      = true;
            }

            if ( ! $wpdb->query( $wpdb->prepare( "INSERT INTO " . bm_get_table('messages') . " ( thread_id, sender_id, message, date_sent, created_at, updated_at, temp_id, is_pending ) VALUES ( %d, %d, %s, %s, %d, %d, %s, %d )", $this->thread_id, $this->sender_id, $this->message, $this->date_sent, $this->created_at, $this->updated_at, $this->temp_id, $this->is_pending ) ) ) {
                return false;
            }

            $this->id = $wpdb->insert_id;

            $recipient_ids = array();

            $time = Better_Messages()->functions->get_microtime();

            if ( $new_thread ) {
                $unread_count = ( $this->count_unread ) ? 1 : 0;
                foreach ( (array) $this->recipients as $recipient ) {
                    $wpdb->query( $wpdb->prepare( "
                    INSERT INTO 
                    " . bm_get_table('recipients') . " 
                    ( user_id, thread_id, unread_count, last_update ) 
                    VALUES ( %d, %d, %d, %d )", $recipient->user_id, $this->thread_id, $unread_count, $time ) );
                    $recipient_ids[] = $recipient->user_id;
                }

                if ( $this->sender_id != 0 && ! in_array( $this->sender_id, $recipient_ids ) ) {
                    $wpdb->query( $wpdb->prepare(
                        "INSERT INTO " . bm_get_table('recipients') . "
                    ( user_id, thread_id, last_update )
                    VALUES ( %d, %d, %d )", $this->sender_id, $this->thread_id, $time ) );
                }
            } else {
                if( $this->count_unread ) {
                    $unread_exclude = array_values( array_filter( array_map( 'intval', (array) $this->unread_exclude ) ) );

                    $wpdb->query(
                        $wpdb->prepare(
                            "UPDATE " . bm_get_table('recipients') . "
                            SET unread_count = unread_count + 1, is_deleted = 0, last_update = %d
                            WHERE thread_id = %d AND user_id != %d" . ( count( $unread_exclude ) > 0 ? " AND user_id NOT IN (" . implode( ',', $unread_exclude ) . ")" : "" ),
                            $time,
                            $this->thread_id,
                            $this->sender_id
                        )
                    );

                    if( count( $unread_exclude ) > 0 ){
                        $wpdb->query(
                            $wpdb->prepare(
                                "UPDATE " . bm_get_table('recipients') . "
                                SET is_deleted = 0, last_update = %d
                                WHERE thread_id = %d AND user_id IN (" . implode( ',', $unread_exclude ) . ")",
                                $time,
                                $this->thread_id
                            )
                        );
                    }
                } else {
                    $wpdb->query(
                        $wpdb->prepare(
                            "UPDATE " . bm_get_table('recipients') . " 
                            SET is_deleted = 0, last_update = %d
                            WHERE thread_id = %d AND user_id != %d",
                            $time,
                            $this->thread_id,
                            $this->sender_id
                        )
                    );
                }
            }

            do_action_ref_array( 'better_messages_message_after_save', array( &$this ) );

            return $this->id;
        }

        public function get_recipients() {
            global $wpdb;

            return $wpdb->get_results( $wpdb->prepare( "SELECT user_id FROM " . bm_get_table('recipients') . " WHERE thread_id = %d", $this->thread_id ) );
        }

        public static function get_recipient_ids( $recipient_usernames ) {
            $recipient_ids = false;

            if ( ! $recipient_usernames ) {
                return $recipient_ids;
            }

            if ( is_array( $recipient_usernames ) ) {
                $rec_un_count = count( $recipient_usernames );

                for ( $i = 0, $count = $rec_un_count; $i < $count; ++ $i ) {
                    if ( $rid = bp_core_get_userid( trim( $recipient_usernames[ $i ] ) ) ) {
                        $recipient_ids[] = $rid;
                    }
                }
            }

            return apply_filters( 'better_messages_message_get_recipient_ids', $recipient_ids, $recipient_usernames );
        }

        public static function get_last_sent_for_user( $thread_id ) {
            global $wpdb;

            $query = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM " . bm_get_table('messages') . " WHERE sender_id = %d AND thread_id = %d ORDER BY created_at DESC LIMIT 1", Better_Messages()->functions->get_current_user_id(), $thread_id ) );

            return is_numeric( $query ) ? (int) $query : $query;
        }

        public static function is_user_sender( $user_id, $message_id ) {
            global $wpdb;

            $query = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM " . bm_get_table('messages') . " WHERE sender_id = %d AND id = %d", $user_id, $message_id ) );

            return is_numeric( $query ) ? (int) $query : $query;
        }

        public static function get_message_sender( $message_id ) {
            global $wpdb;

            $query = $wpdb->get_var( $wpdb->prepare( "SELECT sender_id FROM " . bm_get_table('messages') . " WHERE id = %d", $message_id ) );

            return is_numeric( $query ) ? (int) $query : $query;
        }
    }

endif;
