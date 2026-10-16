<?php
defined( 'ABSPATH' ) || exit;

if ( !class_exists( 'Better_Messages_Reactions' ) ){

    class Better_Messages_Reactions
    {

        public static function instance()
        {

            static $instance = null;

            if (null === $instance) {
                $instance = new Better_Messages_Reactions();
            }

            return $instance;
        }

        public function __construct(){
            add_filter('bp_better_messages_script_variables', array( $this, 'add_reactions_to_js' ) );

            add_action( 'rest_api_init',  array( $this, 'rest_api_init' ) );

            add_filter( 'better_messages_rest_message_meta', array( $this, 'reactions_message_meta'), 10, 4 );
            add_filter( 'better_messages_rest_api_update_data', array( $this, 'add_new_reactions_to_update' ), 11, 3 );
        }

        public function rest_api_init(){
            register_rest_route('better-messages/v1', '/reactions/save', array(
                'methods' => 'POST',
                'callback' => array( $this, 'save_reaction' ),
                'permission_callback' => array(Better_Messages_Rest_Api(), 'is_user_authorized')
            ));

            register_rest_route('better-messages/v1', '/thread/(?P<id>\d+)/reactionsSeen', array(
                'methods' => 'POST',
                'callback' => array( $this, 'reactions_seen' ),
                'permission_callback' => array( Better_Messages_Rest_Api(), 'check_thread_access' ),
                'args' => array(
                    'id' => array(
                        'validate_callback' => function ( $param ) {
                            return is_numeric( $param );
                        }
                    ),
                ),
            ));
        }

        public function reactions_message_meta( $meta, $message_id, $thread_id, $content ){
            $reactions = $this->get_reactions();

            $message_reactions = $this->get_message_reactions( $message_id );

            if( empty( $message_reactions ) ){
                $meta['reactions'] = [];
                return $meta;
            }

            $result_reactions = [];

            foreach( $message_reactions as $user_id => $reaction ){
                if( ! isset($reactions[$reaction]) ) {
                    continue;
                }

                if( ! isset( $result_reactions[ $reaction ] ) ){
                    $result_reactions[ $reaction ] = [];
                }

                $result_reactions[ $reaction ][] = (int) $user_id;
            }

            if( count( $result_reactions ) === 0 ){
                $meta['reactions'] = [];
                return $meta;
            }

            $_result_reactions = [];

            foreach( $result_reactions as $reaction => $users ){
                $_result_reactions[] = [
                    'reaction' => $reaction,
                    'users'    => $users
                ];
            }

            $meta['reactions'] = $_result_reactions;

            return $meta;
        }

        public function save_reaction( WP_REST_Request $request ){
            global $wpdb;
            $user_id             = Better_Messages()->functions->get_current_user_id();
            $emoji               = sanitize_text_field( $request->get_param('emoji') );
            $available_reactions = $this->get_reactions();


            if( ! isset( $available_reactions[ $emoji ] ) ){
                return new WP_Error(
                    'rest_forbidden',
                    _x( 'No such reaction available', 'Reactions', 'bp-better-messages' ),
                    array( 'status' => rest_authorization_required_code() )
                );
            }

            $message_id = intval( $request->get_param('message_id') );

            $message   = $wpdb->get_row($wpdb->prepare( "SELECT * FROM " . bm_get_table('messages') . " WHERE `id` = %d", $message_id ));

            if( ! $message ) {
                return new WP_Error(
                    'rest_forbidden',
                    __( 'Message not found', 'bp-better-messages' ),
                    array( 'status' => rest_authorization_required_code() )
                );
            }

            $sender_id = intval( $message->sender_id );
            $thread_id = intval( $message->thread_id );

            if( $sender_id === $user_id ){
                return new WP_Error(
                    'rest_forbidden',
                    _x( 'You can only put reactions to other members messages', 'Reactions', 'bp-better-messages' ),
                    array( 'status' => rest_authorization_required_code() )
                );
            }

            if( ! Better_Messages()->functions->check_access( $thread_id ) ){
                return new WP_Error(
                    'rest_forbidden',
                    _x( 'Sorry, you have no access to this conversation', 'Rest API Error', 'bp-better-messages' ),
                    array( 'status' => rest_authorization_required_code() )
                );
            }

            $lock = Better_Messages()->functions->lock( 'reactions|' . (int) $message_id );

            wp_cache_delete( (int) $message_id, 'bm_message_meta' );

            $reactions = $this->get_message_reactions( $message_id );

            if( isset( $reactions[ $user_id ] ) && $reactions[ $user_id ] === $emoji ){
                unset( $reactions[$user_id] );
            } else {
                $reactions[$user_id] = $emoji;
            }

            Better_Messages()->functions->update_message_meta( $message_id, 'bm_reactions', $reactions );
            Better_Messages()->functions->update_message_update_time( $message_id );

            $this->sync_reaction_indicator( $thread_id, $message_id, $sender_id, $reactions, isset( $reactions[ $user_id ] ) );

            Better_Messages()->functions->unlock( $lock );

            do_action('better_messages_message_meta_updated', $thread_id, $message_id, 'bm_reactions', $reactions );

            return Better_Messages_Rest_Api()->get_messages( $thread_id, [ $message_id ] );
        }

        public function sync_reaction_indicator( $thread_id, $message_id, $author_id, $reactions, $added )
        {
            global $wpdb;

            $author_id = (int) $author_id;

            if ( $author_id === 0 || Better_Messages()->functions->is_ai_bot_user( $author_id ) ) {
                return;
            }

            $table   = bm_get_table( 'mentions' );
            $others  = array_diff( array_map( 'intval', array_keys( (array) $reactions ) ), array( $author_id ) );
            $changed = false;

            $existing = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM `{$table}` WHERE `message_id` = %d AND `user_id` = %d AND `type` = 'reaction'",
                $message_id, $author_id
            ) );

            if ( $added && count( $others ) > 0 ) {
                if ( $existing === 0 ) {
                    $changed = (bool) $wpdb->query( $wpdb->prepare(
                        "INSERT INTO `{$table}` (`thread_id`, `message_id`, `user_id`, `type`)
                        SELECT `thread_id`, %d, `user_id`, 'reaction' FROM `" . bm_get_table( 'recipients' ) . "`
                        WHERE `thread_id` = %d AND `user_id` = %d LIMIT 1",
                        $message_id, $thread_id, $author_id
                    ) );
                }
            } else if ( count( $others ) === 0 && $existing > 0 ) {
                $changed = (bool) $wpdb->query( $wpdb->prepare(
                    "DELETE FROM `{$table}` WHERE `message_id` = %d AND `user_id` = %d AND `type` = 'reaction'",
                    $message_id, $author_id
                ) );
            }

            if ( $changed ) {
                $this->touch_member( $thread_id, $author_id );
            }
        }

        private function touch_member( $thread_id, $user_id )
        {
            global $wpdb;

            $wpdb->query( $wpdb->prepare(
                "UPDATE `" . bm_get_table( 'recipients' ) . "` SET `last_update` = %d WHERE `thread_id` = %d AND `user_id` = %d",
                Better_Messages()->functions->get_microtime(), $thread_id, $user_id
            ) );

            do_action( 'better_messages_thread_self_update', $thread_id, $user_id );
        }

        public function get_new_reactions( $user_id, $thread_ids )
        {
            global $wpdb;

            $thread_ids = array_values( array_filter( array_map( 'intval', (array) $thread_ids ) ) );
            $result     = array();

            foreach ( $thread_ids as $thread_id ) {
                $result[ $thread_id ] = array();
            }

            if ( empty( $thread_ids ) || (int) $user_id === 0 ) {
                return $result;
            }

            $rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT `thread_id`, `message_id` FROM `" . bm_get_table( 'mentions' ) . "`
                WHERE `user_id` = %d AND `type` = 'reaction' AND `thread_id` IN (" . implode( ',', $thread_ids ) . ")
                ORDER BY `message_id` DESC",
                $user_id
            ) );

            foreach ( (array) $rows as $row ) {
                $thread_id = (int) $row->thread_id;

                if ( count( $result[ $thread_id ] ) < 50 ) {
                    $result[ $thread_id ][] = (int) $row->message_id;
                }
            }

            return $result;
        }

        public function add_new_reactions_to_update( $data, $user_id, $last_time )
        {
            if ( empty( $data['threads'] ) || ! is_array( $data['threads'] ) ) {
                return $data;
            }

            $map = array();

            foreach ( $data['threads'] as $index => $thread ) {
                if ( is_array( $thread ) && array_key_exists( 'mentions', $thread ) ) {
                    $map[ (int) $thread['thread_id'] ] = $index;
                }
            }

            if ( empty( $map ) ) {
                return $data;
            }

            foreach ( $this->get_new_reactions( $user_id, array_keys( $map ) ) as $thread_id => $message_ids ) {
                $data['threads'][ $map[ $thread_id ] ]['newReactions'] = $message_ids;
            }

            return $data;
        }

        public function reactions_seen( WP_REST_Request $request )
        {
            global $wpdb;

            $user_id   = Better_Messages()->functions->get_current_user_id();
            $thread_id = intval( $request->get_param( 'id' ) );
            $ids       = array_slice( array_values( array_filter( array_map( 'intval', (array) $request->get_param( 'message_ids' ) ) ) ), 0, 100 );

            if ( ! empty( $ids ) ) {
                $deleted = $wpdb->query( $wpdb->prepare(
                    "DELETE FROM `" . bm_get_table( 'mentions' ) . "`
                    WHERE `user_id` = %d AND `thread_id` = %d AND `type` = 'reaction' AND `message_id` IN (" . implode( ',', $ids ) . ")",
                    $user_id, $thread_id
                ) );

                if ( $deleted ) {
                    $this->touch_member( $thread_id, $user_id );
                }
            }

            $remaining = $this->get_new_reactions( $user_id, array( $thread_id ) );

            return array( 'newReactions' => $remaining[ $thread_id ] );
        }

        public static function get_default_reactions(){
            return [
                '1f914' => 'Thinking',
                '2b50'  => 'Star',
                '1f632' => 'WOW',
                '1f60d' => 'Love',
                '1f44c' => 'Okay',
                '1f44d' => 'Thumbs up',
            ];
        }

        public function get_reactions(){
            $reactions = Better_Messages()->settings['reactionsEmojies'];
            return apply_filters( 'bp_better_messages_reactions_list', $reactions );
        }

        public function add_reactions_to_js( $variables ){
            if ( Better_Messages()->settings['enableReactions'] !== '1' ) {
                return $variables;
            }

            $variables['reactions'] = $this->get_reactions();
            return $variables;
        }

        public function get_message_reactions( $message_id ){
            $reactions = Better_Messages()->functions->get_message_meta( $message_id, 'bm_reactions', true );

            if( empty( $reactions ) || ! is_array( $reactions ) ){
                $reactions = [];
            }

            return $reactions;
        }
    }
}

