<?php
if ( !class_exists( 'Better_Messages_Rest_Api_Favorited' ) ):

    class Better_Messages_Rest_Api_Favorited
    {

        public static function instance()
        {

            static $instance = null;

            if (null === $instance) {
                $instance = new Better_Messages_Rest_Api_Favorited();
            }

            return $instance;
        }

        public function __construct()
        {
            add_action('rest_api_init', array($this, 'rest_api_init'));
        }

        public function rest_api_init(){
            if( Better_Messages()->settings['disableFavoriteMessages'] !== '1' ) {
                register_rest_route('better-messages/v1', '/getFavorited', array(
                    'methods' => 'GET',
                    'callback' => array($this, 'get_favorited'),
                    'permission_callback' => array(Better_Messages_Rest_Api(), 'is_user_authorized'),
                ));


                register_rest_route('better-messages/v1', '/thread/(?P<id>\d+)/favorite', array(
                    'methods' => 'POST',
                    'callback' => array($this, 'favorite'),
                    'permission_callback' => array(Better_Messages_Rest_Api(), 'check_thread_access'),
                    'args' => array(
                        'id' => array(
                            'validate_callback' => function ($param, $request, $key) {
                                return is_numeric($param);
                            }
                        ),
                    ),
                ));

            }
        }

        public function get_favorited( WP_REST_Request $request ){
            $current_user_id = Better_Messages()->functions->get_current_user_id();

            global $wpdb;

            $query = $wpdb->prepare( "
                SELECT
                  " . bm_get_table('messages') . ".id
                FROM " . bm_get_table('meta') . "
                  INNER JOIN " . bm_get_table('messages') . "
                    ON " . bm_get_table('meta') . ".bm_message_id = " . bm_get_table('messages') . ".id
                  INNER JOIN " . bm_get_table('recipients') . "
                    ON " . bm_get_table('recipients') . ".thread_id = " . bm_get_table('messages') . ".thread_id
                WHERE " . bm_get_table('meta') . ".meta_key = 'starred_by_user'
                AND " . bm_get_table('meta') . ".meta_value = %d
                AND " . bm_get_table('recipients') . ".is_deleted = 0
                AND " . bm_get_table('recipients') . ".user_id = %d
            ", $current_user_id, $current_user_id );

            $messages_ids = $wpdb->get_col( $query );

            if ( isset( Better_Messages()->chats ) ) {
                $rooms_query = $wpdb->prepare( "
                    SELECT `messages`.`id`, `messages`.`thread_id`
                    FROM " . bm_get_table('meta') . " `meta`
                      INNER JOIN " . bm_get_table('messages') . " `messages`
                        ON `meta`.`bm_message_id` = `messages`.`id`
                      INNER JOIN " . bm_get_table('threads') . " `threads`
                        ON `threads`.`id` = `messages`.`thread_id`
                        AND `threads`.`type` = 'chat-room'
                      LEFT JOIN " . bm_get_table('recipients') . " `recipients`
                        ON `recipients`.`thread_id` = `messages`.`thread_id`
                        AND `recipients`.`user_id` = %d
                    WHERE `meta`.`meta_key` = 'starred_by_user'
                    AND `meta`.`meta_value` = %d
                    AND `recipients`.`thread_id` IS NULL
                ", $current_user_id, $current_user_id );

                $readable = array();

                foreach ( $wpdb->get_results( $rooms_query ) as $row ) {
                    $thread_id = (int) $row->thread_id;

                    if ( ! isset( $readable[ $thread_id ] ) ) {
                        $readable[ $thread_id ] = ( user_can( $current_user_id, 'bm_can_administrate' ) || Better_Messages()->functions->check_access( $thread_id, $current_user_id ) )
                            && Better_Messages()->functions->can_read_chat_messages( $thread_id, $current_user_id );
                    }

                    if ( $readable[ $thread_id ] ) {
                        $messages_ids[] = $row->id;
                    }
                }
            }

            if ( empty( $messages_ids ) ) {
                return array( 'users' => array(), 'messages' => array() );
            }

            $return = Better_Messages_Rest_Api()->get_messages( null, $messages_ids );

            return $return;
        }

        public function favorite( WP_REST_Request $request ){
            $thread_id  = intval( $request->get_param( 'id' ) );
            $message_id = absint( $request->get_param( 'messageId') );
            $type       = sanitize_text_field( $request->get_param('type') );
            $user_id    = Better_Messages()->functions->get_current_user_id();

            $message = Better_Messages()->functions->get_message( $message_id );

            $hidden_pending = $message
                && (int) $message->is_pending === 1
                && (int) $message->sender_id !== $user_id
                && ! user_can( $user_id, 'bm_can_administrate' );

            if ( ! $message || (int) $message->thread_id !== $thread_id || $hidden_pending ) {
                return new WP_Error(
                    'rest_not_found',
                    _x('Message not found', 'Rest API Error', 'bp-better-messages'),
                    array('status' => 404)
                );
            }

            $args = array(
                'action'     => $type,
                'message_id' => $message_id,
                'user_id'    => $user_id,
            );

            $is_starred = Better_Messages()->functions->is_message_starred( $args['message_id'], $args['user_id'] );

            // Star.
            if ( 'star' == $args['action'] ) {
                if ( true === $is_starred ) {
                    return true;
                } else {
                    Better_Messages()->functions->add_message_meta( $args['message_id'], 'starred_by_user', $args['user_id'], false );
                    return true;
                }
                // Unstar.
            } else {
                if ( false === $is_starred ) {
                    return true;
                } else {
                    Better_Messages()->functions->delete_message_meta( $args['message_id'], 'starred_by_user', $args['user_id'] );
                    return true;
                }
            }
        }
    }

    function Better_Messages_Rest_Api_Favorited(){
        return Better_Messages_Rest_Api_Favorited::instance();
    }

endif;
