<?php
if( ! class_exists( 'BM_Messages_Message' ) ):
    /**
     * Single message class.
     */
    class BM_Messages_Message {
        /**
         * ID of the message.
         *
         * @var int
         */
        public $id;

        /**
         * ID of the message thread.
         *
         * @var int
         */
        public $thread_id;

        /**
         * ID of the sender.
         *
         * @var int
         */
        public $sender_id;

        /**
         * Subject line of the message.
         *
         * @var string
         */
        public $subject;

        /**
         * Content of the message.
         *
         * @var string
         */
        public $message;

        /**
         * Date the message was sent.
         *
         * @var string
         */
        public $date_sent;

        public $created_at;

        public $updated_at;

        public $temp_id;

        /**
         * Message recipients.
         *
         * @var bool|array
         */
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

        /**
         * Constructor.
         *
         * @param int|null $id Optional. ID of the message.
         */
        public function __construct( $id = null ) {
            $this->date_sent = bp_core_current_time();
            $this->sender_id = Better_Messages()->functions->get_current_user_id();

            if ( ! empty( $id ) ) {
                $this->populate( $id );
            }
        }

        /**
         * Set up data related to a specific message object.
         *
         * @param int $id ID of the message.
         */
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

        /**
         * Send a message.
         *
         * @return int|bool ID of the newly created message on success, false on failure.
         */
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

            /**
             * Fires before the current message item gets saved.
             *
             * Please use this hook to filter the properties above. Each part will be passed in.
             *
             * @since 1.0.0
             *
             * @param BP_Messages_Message $this Current instance of the message item being saved. Passed by reference.
             */
            do_action_ref_array( 'better_messages_message_before_save', array( &$this ) );

            // Make sure we have at least one recipient before sending.
            if ( empty( $this->recipients ) ) {
                return false;
            }

            if( ! $wpdb->has_cap( 'utf8mb4' ) ){
                $this->subject = wp_encode_emoji( $this->subject );
                $this->message = wp_encode_emoji( $this->message );
            }

            $new_thread = false;

            // If we have no thread_id then this is the first message of a new thread.
            if ( empty( $this->thread_id ) ) {
                if( ! $wpdb->insert( bm_get_table('threads'), [ 'subject' => $this->subject ], [ '%s' ] ) ){
                    return false;
                }

                $this->thread_id = $wpdb->insert_id;
                $new_thread      = true;
            }

            // First insert the message into the messages table.
            if ( ! $wpdb->query( $wpdb->prepare( "INSERT INTO " . bm_get_table('messages') . " ( thread_id, sender_id, message, date_sent, created_at, updated_at, temp_id ) VALUES ( %d, %d, %s, %s, %d, %d, %s )", $this->thread_id, $this->sender_id, $this->message, $this->date_sent, $this->created_at, $this->updated_at, $this->temp_id ) ) ) {
                return false;
            }

            $this->id = $wpdb->insert_id;

            $recipient_ids = array();

            $time = Better_Messages()->functions->get_microtime();


            if ( $new_thread ) {
                $unread_count = ( $this->count_unread ) ? 1 : 0;
                // Add an recipient entry for all recipients.
                foreach ( (array) $this->recipients as $recipient ) {
                    $wpdb->query( $wpdb->prepare( "
                    INSERT INTO 
                    " . bm_get_table('recipients') . " 
                    ( user_id, thread_id, unread_count, last_update ) 
                    VALUES ( %d, %d, %d, %d )", $recipient->user_id, $this->thread_id, $unread_count, $time ) );
                    $recipient_ids[] = $recipient->user_id;
                }

                // Add a sender recipient entry if the sender is not in the list of recipients.
                if ( ! in_array( $this->sender_id, $recipient_ids ) ) {
                    $wpdb->query( $wpdb->prepare(
                        "INSERT INTO " . bm_get_table('recipients') . " 
                    ( user_id, thread_id, last_update ) 
                    VALUES ( %d, %d, %d )", $this->sender_id, $this->thread_id, $time ) );
                }
            } else {
                if( $this->count_unread ) {
                    $wpdb->query(
                        $wpdb->prepare(
                            "UPDATE " . bm_get_table('recipients') . " 
                            SET unread_count = unread_count + 1, is_deleted = 0, last_update = %d
                            WHERE thread_id = %d AND user_id != %d",
                            $time,
                            $this->thread_id,
                            $this->sender_id
                        )
                    );
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

            //wp_cache_delete('thread_' . $this->thread_id, 'bm_messages');
            //Better_Messages()->hooks->clean_thread_cache( $this->thread_id );

            /**
             * Fires after the current message item has been saved.
             *
             * @since 1.0.0
             *
             * @param BP_Messages_Message $this Current instance of the message item being saved. Passed by reference.
             */
            do_action_ref_array( 'better_messages_message_after_save', array( &$this ) );

            return $this->id;
        }

        /**
         * Get a list of recipients for a message.
         *
         * @return object $value List of recipients for a message.
         */
        public function get_recipients() {
            global $wpdb;

            return $wpdb->get_results( $wpdb->prepare( "SELECT user_id FROM " . bm_get_table('recipients') . " WHERE thread_id = %d", $this->thread_id ) );
        }

        /** Static Functions **************************************************/

        /**
         * Get list of recipient IDs from their usernames.
         *
         * @param array $recipient_usernames Usernames of recipients.
         *
         * @return bool|array $recipient_ids Array of Recepient IDs.
         */
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

            /**
             * Filters the array of recipients IDs.
             *
             * @since 2.8.0
             *
             * @param array $recipient_ids Array of recipients IDs that were retrieved based on submitted usernames.
             * @param array $recipient_usernames Array of recipients usernames that were submitted by a user.
             */
            return apply_filters( 'better_messages_message_get_recipient_ids', $recipient_ids, $recipient_usernames );
        }

        /**
         * Get the ID of the message last sent by the logged-in user for a given thread.
         *
         * @param int $thread_id ID of the thread.
         *
         * @return int|null ID of the message if found, otherwise null.
         */
        public static function get_last_sent_for_user( $thread_id ) {
            global $wpdb;

            $query = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM " . bm_get_table('messages') . " WHERE sender_id = %d AND thread_id = %d ORDER BY created_at DESC LIMIT 1", Better_Messages()->functions->get_current_user_id(), $thread_id ) );

            return is_numeric( $query ) ? (int) $query : $query;
        }

        /**
         * Check whether a user is the sender of a message.
         *
         * @param int $user_id ID of the user.
         * @param int $message_id ID of the message.
         *
         * @return int|null Returns the ID of the message if the user is the
         *                  sender, otherwise null.
         */
        public static function is_user_sender( $user_id, $message_id ) {
            global $wpdb;

            $query = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM " . bm_get_table('messages') . " WHERE sender_id = %d AND id = %d", $user_id, $message_id ) );

            return is_numeric( $query ) ? (int) $query : $query;
        }

        /**
         * Get the ID of the sender of a message.
         *
         * @param int $message_id ID of the message.
         *
         * @return int|null The ID of the sender if found, otherwise null.
         */
        public static function get_message_sender( $message_id ) {
            global $wpdb;

            $query = $wpdb->get_var( $wpdb->prepare( "SELECT sender_id FROM " . bm_get_table('messages') . " WHERE id = %d", $message_id ) );

            return is_numeric( $query ) ? (int) $query : $query;
        }
    }

endif;
