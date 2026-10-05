<?php
defined( 'ABSPATH' ) || exit;

/**
 * Block, Suspend, Report for BuddyPress (bp-toolkit) integration.
 *
 * The plugin keeps its own block list in the `bptk_block` user meta and blocks
 * BuddyPress messages both ways: the blocked member can't write to the member who
 * blocked them, and the member who blocked can't write to them either. This applies
 * the same rule to Better Messages conversations, for replies and for new ones.
 */
if ( !class_exists( 'Better_Messages_BP_Toolkit' ) ){

    class Better_Messages_BP_Toolkit
    {

        public static function instance()
        {

            static $instance = null;

            if (null === $instance) {
                $instance = new Better_Messages_BP_Toolkit();
            }

            return $instance;
        }

        public function __construct()
        {
            add_filter( 'better_messages_can_send_message', array( $this, 'disable_message_for_blocked_user' ), 10, 3 );
            add_action( 'better_messages_before_new_thread', array( $this, 'disable_start_thread_for_blocked_users' ), 10, 2 );
        }

        /**
         * BPTK_Block is defined on `init` and only when BuddyPress is active, so this
         * is the check that the plugin is really blocking anything.
         */
        public function is_active(){
            return class_exists( 'BPTK_Block' );
        }

        public function disable_message_for_blocked_user( $allowed, $user_id, $thread_id ){
            if( ! $this->is_active() ) return $allowed;

            $participants = Better_Messages()->functions->get_participants( $thread_id );

            if( ! isset( $participants['recipients'] ) ) {
                return $allowed;
            }

            /**
             * Not block in group thread
             */
            if( count( $participants['recipients'] ) > 1 ){
                return $allowed;
            }

            $sender_blocked = $this->get_blocked_users( $user_id );

            foreach( $participants['recipients'] as $recipient_user_id ){
                if( (int) $recipient_user_id === (int) $user_id ) continue;

                global $bp_better_messages_restrict_send_message;

                if( in_array( (int) $user_id, $this->get_blocked_users( $recipient_user_id ), true ) ){
                    $bp_better_messages_restrict_send_message['blocked_by_user'] = __('You were blocked by recipient', 'bp-better-messages');
                    $allowed = false;
                }

                if( in_array( (int) $recipient_user_id, $sender_blocked, true ) ){
                    $bp_better_messages_restrict_send_message['blocked_by_you'] = _x("You can't send message to user who was blocked by you", 'Message when user cant send message to user blocked by him' ,'bp-better-messages');
                    $allowed = false;
                }
            }

            return $allowed;
        }

        public function disable_start_thread_for_blocked_users( &$args, &$errors ){
            if( ! $this->is_active() ) return;

            $current_user_id = Better_Messages()->functions->get_current_user_id();

            $recipients = $args['recipients'];
            if( ! is_array( $recipients ) ) $recipients = [ $recipients ];

            $sender_blocked = $this->get_blocked_users( $current_user_id );

            foreach( $recipients as $recipient_user_id ){
                $recipient_user_id = (int) $recipient_user_id;
                if( $recipient_user_id === $current_user_id ) continue;

                if( in_array( $recipient_user_id, $sender_blocked, true ) ){
                    $errors[] = sprintf(_x('%s blocked by you', 'Error when starting new thread but user blocked', 'bp-better-messages'), Better_Messages()->functions->get_name( $recipient_user_id ));
                    continue;
                }

                if( in_array( $current_user_id, $this->get_blocked_users( $recipient_user_id ), true ) ){
                    $errors[] = sprintf(_x('%s blocked you', 'Error when starting new thread but user blocked', 'bp-better-messages'), Better_Messages()->functions->get_name( $recipient_user_id ));
                }
            }
        }

        /**
         * Members the given user blocked.
         *
         * @param int $user_id
         * @return int[]
         */
        public function get_blocked_users( $user_id ){
            if( (int) $user_id === 0 ) return [];

            $list = Better_Messages()->functions->get_user_meta( $user_id, 'bptk_block', true );
            if ( empty( $list ) ) {
                $list = array();
            }

            $list = apply_filters( 'get_blocked_users', $list, $user_id );
            $list = apply_filters( 'bptk_get_blocked_users', $list, $user_id );

            return array_map( 'intval', array_filter( (array) $list ) );
        }
    }
}
