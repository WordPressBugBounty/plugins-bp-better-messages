<?php
defined( 'ABSPATH' ) || exit;

if ( !class_exists( 'Better_Messages_Abilities_Conversations' ) ):

    class Better_Messages_Abilities_Conversations
    {
        public static function instance()
        {
            static $instance = null;

            if ( null === $instance ) {
                $instance = new Better_Messages_Abilities_Conversations();
            }

            return $instance;
        }

        public function register()
        {
            $this->register_list_conversations();
            $this->register_get_conversation();
            $this->register_create_conversation();
            $this->register_get_private_conversation();
            $this->register_delete_conversation();
            $this->register_mark_read();
            $this->register_change_subject();
            $this->register_make_moderator();
            $this->register_unmake_moderator();
            $this->register_get_stats();
        }

        private function register_list_conversations()
        {
            wp_register_ability( 'better-messages/list-conversations', array(
                'label'       => 'List Conversations',
                'description' => 'Returns the list of conversations for the current user with unread counts and last activity.',
                'category'    => 'better-messages',
                'input_schema' => array(
                    'type'       => 'object',
                    'properties' => array(
                        'user_id' => array(
                            'type'        => 'integer',
                            'description' => 'User ID to list conversations for. Defaults to the current user.',
                        ),
                        'exclude' => array(
                            'type'        => 'array',
                            'items'       => array( 'type' => 'integer' ),
                            'description' => 'Thread IDs to exclude from results.',
                            'default'     => array(),
                        ),
                    ),
                ),
                'output_schema' => array(
                    'type'  => 'array',
                    'items' => array(
                        'type'       => 'object',
                        'properties' => array(
                            'thread_id'          => array( 'type' => 'integer' ),
                            'type'               => array( 'type' => 'string' ),
                            'subject'            => array( 'type' => 'string' ),
                            'unread'             => array( 'type' => 'integer' ),
                            'participants_count' => array( 'type' => 'integer' ),
                        ),
                    ),
                ),
                'execute_callback'    => array( $this, 'execute_list_conversations' ),
                'permission_callback' => function() {
                    return current_user_can( 'manage_options' );
                },
                'meta' => array(
                    'show_in_rest' => true,
                    'mcp'          => array( 'public' => true ),
                    'annotations'  => array(
                        'readonly'    => true,
                        'destructive' => false,
                        'idempotent'  => true,
                    ),
                ),
            ) );
        }

        public function execute_list_conversations( $input )
        {
            $user_id = intval( $input['user_id'] ?? 0 );
            if ( $user_id === 0 ) {
                $user_id = Better_Messages()->functions->get_current_user_id();
            }
            $exclude = array_map( 'intval', $input['exclude'] ?? array() );

            $data = Better_Messages()->api->get_threads( $exclude, false, false, true, true, $user_id );

            $results = array();
            if ( ! empty( $data['threads'] ) ) {
                foreach ( $data['threads'] as $thread ) {
                    $tid        = intval( $thread['thread_id'] );
                    $recipients = Better_Messages()->functions->get_recipients( $tid );

                    $last_message = '';
                    $last_message_time = '';
                    $last_messages = Better_Messages()->functions->get_messages( $tid, false, 'last_messages', 1 );
                    if ( ! empty( $last_messages ) && is_array( $last_messages ) ) {
                        $msg = $last_messages[0];
                        $last_message = wp_strip_all_tags( $msg->message );
                        if ( strlen( $last_message ) > 100 ) {
                            $last_message = mb_substr( $last_message, 0, 100 ) . '...';
                        }
                        $last_message_time = date( 'c', (int) ( (int) $msg->created_at / 10 / 1000 ) );
                    }

                    $results[] = array(
                        'thread_id'          => $tid,
                        'type'               => $thread['type'] ?? 'thread',
                        'subject'            => $thread['subject'] ?? '',
                        'unread'             => intval( $thread['unread'] ?? 0 ),
                        'participants_count' => is_array( $recipients ) ? count( $recipients ) : 0,
                        'last_message'       => $last_message,
                        'last_message_time'  => $last_message_time,
                    );
                }
            }

            return $results;
        }

        private function register_get_conversation()
        {
            wp_register_ability( 'better-messages/get-conversation', array(
                'label'       => 'Get Conversation',
                'description' => 'Returns conversation details including participants and recent messages.',
                'category'    => 'better-messages',
                'input_schema' => array(
                    'type'       => 'object',
                    'properties' => array(
                        'thread_id' => array(
                            'type'        => 'integer',
                            'description' => 'The conversation ID to retrieve.',
                        ),
                        'messages_count' => array(
                            'type'        => 'integer',
                            'description' => 'Number of recent messages to include.',
                            'default'     => 20,
                        ),
                    ),
                    'required' => array( 'thread_id' ),
                ),
                'output_schema' => array(
                    'type'       => 'object',
                    'properties' => array(
                        'thread_id'    => array( 'type' => 'integer' ),
                        'type'         => array( 'type' => 'string' ),
                        'subject'      => array( 'type' => 'string' ),
                        'participants' => array(
                            'type'  => 'array',
                            'items' => array(
                                'type'       => 'object',
                                'properties' => array(
                                    'user_id' => array( 'type' => 'integer' ),
                                    'name'    => array( 'type' => 'string' ),
                                ),
                            ),
                        ),
                        'messages' => array(
                            'type'  => 'array',
                            'items' => array(
                                'type'       => 'object',
                                'properties' => array(
                                    'message_id' => array( 'type' => 'integer' ),
                                    'sender_id'  => array( 'type' => 'integer' ),
                                    'sender_name' => array( 'type' => 'string' ),
                                    'content'    => array( 'type' => 'string' ),
                                    'created_at' => array( 'type' => 'string' ),
                                ),
                            ),
                        ),
                    ),
                ),
                'execute_callback'    => array( $this, 'execute_get_conversation' ),
                'permission_callback' => function() {
                    return current_user_can( 'manage_options' );
                },
                'meta' => array(
                    'show_in_rest' => true,
                    'mcp'          => array( 'public' => true ),
                    'annotations'  => array(
                        'readonly'    => true,
                        'destructive' => false,
                        'idempotent'  => true,
                    ),
                ),
            ) );
        }

        public function execute_get_conversation( $input )
        {
            $thread_id = intval( $input['thread_id'] ?? 0 );
            $count     = intval( $input['messages_count'] ?? 20 );

            if ( $thread_id <= 0 ) {
                return new WP_Error( 'invalid_thread', 'Invalid conversation ID.' );
            }

            if ( $count < 1 ) $count = 1;
            if ( $count > 50 ) $count = 50;

            $thread = Better_Messages()->functions->get_thread( $thread_id );

            if ( ! $thread ) {
                return new WP_Error( 'not_found', 'Conversation not found.' );
            }

            $thread_type = Better_Messages()->functions->get_thread_type( $thread_id );

            $recipients    = Better_Messages()->functions->get_recipients( $thread_id );
            $participants  = array();
            if ( is_array( $recipients ) ) {
                foreach ( $recipients as $uid => $recipient ) {
                    $participants[] = array(
                        'user_id' => intval( $uid ),
                        'name'    => Better_Messages()->functions->get_name( $uid ),
                    );
                }
            }

            $raw_messages = Better_Messages()->functions->get_messages( $thread_id, false, 'last_messages', $count );
            $messages     = array();
            if ( is_array( $raw_messages ) ) {
                $raw_messages = array_reverse( $raw_messages );
                foreach ( $raw_messages as $msg ) {
                    $messages[] = array(
                        'message_id'  => intval( $msg->id ),
                        'sender_id'   => intval( $msg->sender_id ),
                        'sender_name' => Better_Messages()->functions->get_name( $msg->sender_id ),
                        'content'     => wp_strip_all_tags( $msg->message ),
                        'created_at'  => date( 'c', (int) ( (int) $msg->created_at / 10 / 1000 ) ),
                    );
                }
            }

            return array(
                'thread_id'    => intval( $thread_id ),
                'type'         => $thread_type,
                'subject'      => $thread->subject,
                'participants' => $participants,
                'messages'     => $messages,
            );
        }

        private function register_create_conversation()
        {
            wp_register_ability( 'better-messages/create-conversation', array(
                'label'       => 'Create Conversation',
                'description' => 'Creates a new conversation with specified recipients and optionally sends the first message.',
                'category'    => 'better-messages',
                'input_schema' => array(
                    'type'       => 'object',
                    'properties' => array(
                        'recipients' => array(
                            'type'        => 'array',
                            'items'       => array( 'type' => 'integer' ),
                            'description' => 'User IDs to include in the conversation.',
                        ),
                        'subject' => array(
                            'type'        => 'string',
                            'description' => 'Conversation subject.',
                            'default'     => '',
                        ),
                        'message' => array(
                            'type'        => 'string',
                            'description' => 'Initial message content.',
                        ),
                    ),
                    'required' => array( 'recipients', 'message' ),
                ),
                'output_schema' => array(
                    'type'       => 'object',
                    'properties' => array(
                        'thread_id'  => array( 'type' => 'integer' ),
                        'message_id' => array( 'type' => 'integer' ),
                        'created'    => array( 'type' => 'boolean' ),
                    ),
                ),
                'execute_callback'    => array( $this, 'execute_create_conversation' ),
                'permission_callback' => function() {
                    return current_user_can( 'manage_options' );
                },
                'meta' => array(
                    'show_in_rest' => true,
                    'mcp'          => array( 'public' => true ),
                    'annotations'  => array(
                        'readonly'    => false,
                        'destructive' => false,
                        'idempotent'  => false,
                    ),
                ),
            ) );
        }

        public function execute_create_conversation( $input )
        {
            $user_id    = Better_Messages()->functions->get_current_user_id();
            $recipients = array_map( 'intval', $input['recipients'] ?? array() );
            $subject    = sanitize_text_field( $input['subject'] ?? '' );
            $message    = $input['message'] ?? '';

            if ( empty( $recipients ) ) {
                return new WP_Error( 'no_recipients', 'At least one recipient is required.' );
            }

            if ( empty( $message ) ) {
                return new WP_Error( 'empty_message', 'Message content is required to create a conversation.' );
            }

            $content = Better_Messages()->functions->filter_message_content( $message );

            $args = array(
                'sender_id'  => $user_id,
                'recipients' => $recipients,
                'subject'    => $subject,
                'content'    => $content,
                'return'     => 'both',
                'error_type' => 'wp_error',
            );

            $result = Better_Messages()->functions->new_message( $args );

            if ( is_wp_error( $result ) ) {
                return $result;
            }

            return array(
                'thread_id'  => intval( $result['thread_id'] ?? 0 ),
                'message_id' => intval( $result['message_id'] ?? 0 ),
                'created'    => true,
            );
        }

        private function register_get_private_conversation()
        {
            wp_register_ability( 'better-messages/get-private-conversation', array(
                'label'       => 'Get Private Conversation',
                'description' => 'Gets or creates a private 1-on-1 conversation with a specific user.',
                'category'    => 'better-messages',
                'input_schema' => array(
                    'type'       => 'object',
                    'properties' => array(
                        'user_id' => array(
                            'type'        => 'integer',
                            'description' => 'The user ID to start a private conversation with.',
                        ),
                        'create' => array(
                            'type'        => 'boolean',
                            'description' => 'Whether to create the conversation if it does not exist.',
                            'default'     => true,
                        ),
                    ),
                    'required' => array( 'user_id' ),
                ),
                'output_schema' => array(
                    'type'       => 'object',
                    'properties' => array(
                        'thread_id' => array( 'type' => 'integer' ),
                        'result'    => array( 'type' => 'string' ),
                    ),
                ),
                'execute_callback'    => array( $this, 'execute_get_private_conversation' ),
                'permission_callback' => function() {
                    return current_user_can( 'manage_options' );
                },
                'meta' => array(
                    'show_in_rest' => true,
                    'mcp'          => array( 'public' => true ),
                    'annotations'  => array(
                        'readonly'    => false,
                        'destructive' => false,
                        'idempotent'  => true,
                    ),
                ),
            ) );
        }

        public function execute_get_private_conversation( $input )
        {
            $current_user_id = Better_Messages()->functions->get_current_user_id();
            $target_user_id  = intval( $input['user_id'] ?? 0 );
            $create          = $input['create'] ?? true;

            if ( $target_user_id === 0 ) {
                return new WP_Error( 'invalid_user', 'Invalid user ID.' );
            }

            $result = Better_Messages()->functions->get_pm_thread_id( $target_user_id, $current_user_id, (bool) $create );

            if ( is_wp_error( $result ) ) {
                return $result;
            }

            return array(
                'thread_id' => intval( $result['thread_id'] ?? 0 ),
                'result'    => $result['result'] ?? 'unknown',
            );
        }

        private function register_delete_conversation()
        {
            wp_register_ability( 'better-messages/delete-conversation', array(
                'label'       => 'Delete Conversation',
                'description' => 'Deletes (hides) a conversation for the current user.',
                'category'    => 'better-messages',
                'input_schema' => array(
                    'type'       => 'object',
                    'properties' => array(
                        'thread_id' => array(
                            'type'        => 'integer',
                            'description' => 'The conversation ID to delete.',
                        ),
                    ),
                    'required' => array( 'thread_id' ),
                ),
                'output_schema' => array(
                    'type'       => 'object',
                    'properties' => array(
                        'deleted' => array( 'type' => 'boolean' ),
                    ),
                ),
                'execute_callback'    => array( $this, 'execute_delete_conversation' ),
                'permission_callback' => function() {
                    return current_user_can( 'manage_options' );
                },
                'meta' => array(
                    'show_in_rest' => true,
                    'mcp'          => array( 'public' => true ),
                    'annotations'  => array(
                        'readonly'    => false,
                        'destructive' => true,
                        'idempotent'  => true,
                    ),
                ),
            ) );
        }

        public function execute_delete_conversation( $input )
        {
            $user_id   = Better_Messages()->functions->get_current_user_id();
            $thread_id = intval( $input['thread_id'] ?? 0 );

            if ( $thread_id <= 0 ) {
                return new WP_Error( 'invalid_thread', 'Invalid conversation ID.' );
            }

            Better_Messages()->functions->archive_thread( $user_id, $thread_id );

            return array( 'deleted' => true );
        }

        private function register_mark_read()
        {
            wp_register_ability( 'better-messages/mark-read', array(
                'label'       => 'Mark Conversations Read',
                'description' => 'Marks one or more conversations as read. If no thread IDs are provided, marks all conversations as read.',
                'category'    => 'better-messages',
                'input_schema' => array(
                    'type'       => 'object',
                    'properties' => array(
                        'thread_ids' => array(
                            'type'        => 'array',
                            'items'       => array( 'type' => 'integer' ),
                            'description' => 'Thread IDs to mark as read. Empty array marks all as read.',
                            'default'     => array(),
                        ),
                    ),
                ),
                'output_schema' => array(
                    'type'       => 'object',
                    'properties' => array(
                        'marked' => array( 'type' => 'boolean' ),
                    ),
                ),
                'execute_callback'    => array( $this, 'execute_mark_read' ),
                'permission_callback' => function() {
                    return current_user_can( 'manage_options' );
                },
                'meta' => array(
                    'show_in_rest' => true,
                    'mcp'          => array( 'public' => true ),
                    'annotations'  => array(
                        'readonly'    => false,
                        'destructive' => false,
                        'idempotent'  => true,
                    ),
                ),
            ) );
        }

        public function execute_mark_read( $input )
        {
            $user_id    = Better_Messages()->functions->get_current_user_id();
            $thread_ids = array_map( 'intval', $input['thread_ids'] ?? array() );

            if ( empty( $thread_ids ) ) {
                global $wpdb;
                $time = Better_Messages()->functions->get_microtime();
                $mysql_time = bp_core_current_time();

                $wpdb->query( $wpdb->prepare(
                    "UPDATE " . bm_get_table('recipients') . "
                    SET unread_count = 0, last_read = %s, last_delivered = %s, last_update = %d
                    WHERE user_id = %d", $mysql_time, $mysql_time, $time, $user_id
                ) );

                do_action( 'better_messages_mark_all_read', $user_id );
            } else {
                foreach ( $thread_ids as $tid ) {
                    Better_Messages()->functions->messages_mark_thread_read( $tid, $user_id );
                }
            }

            return array( 'marked' => true );
        }

        private function register_change_subject()
        {
            wp_register_ability( 'better-messages/change-subject', array(
                'label'       => 'Change Conversation Subject',
                'description' => 'Changes the subject line of a conversation.',
                'category'    => 'better-messages',
                'input_schema' => array(
                    'type'       => 'object',
                    'properties' => array(
                        'thread_id' => array(
                            'type'        => 'integer',
                            'description' => 'The conversation ID.',
                        ),
                        'subject' => array(
                            'type'        => 'string',
                            'description' => 'New subject line.',
                        ),
                    ),
                    'required' => array( 'thread_id', 'subject' ),
                ),
                'output_schema' => array(
                    'type'       => 'object',
                    'properties' => array(
                        'changed' => array( 'type' => 'boolean' ),
                    ),
                ),
                'execute_callback'    => array( $this, 'execute_change_subject' ),
                'permission_callback' => function() {
                    return current_user_can( 'manage_options' );
                },
                'meta' => array(
                    'show_in_rest' => true,
                    'mcp'          => array( 'public' => true ),
                    'annotations'  => array(
                        'readonly'    => false,
                        'destructive' => false,
                        'idempotent'  => true,
                    ),
                ),
            ) );
        }

        public function execute_change_subject( $input )
        {
            $thread_id = intval( $input['thread_id'] ?? 0 );
            $subject   = sanitize_text_field( $input['subject'] ?? '' );

            if ( $thread_id <= 0 ) {
                return new WP_Error( 'invalid_thread', 'Invalid conversation ID.' );
            }

            if ( empty( $subject ) ) {
                return new WP_Error( 'empty_subject', 'Subject cannot be empty.' );
            }

            Better_Messages()->functions->change_thread_subject( $thread_id, $subject );

            return array( 'changed' => true );
        }

        private function register_make_moderator()
        {
            wp_register_ability( 'better-messages/make-moderator', array(
                'label'       => 'Make User a Moderator',
                'description' => 'Grants moderator role to a user in a conversation.',
                'category'    => 'better-messages',
                'input_schema' => array(
                    'type'       => 'object',
                    'properties' => array(
                        'thread_id' => array(
                            'type'        => 'integer',
                            'description' => 'The conversation ID.',
                        ),
                        'user_id' => array(
                            'type'        => 'integer',
                            'description' => 'The user ID to make moderator.',
                        ),
                    ),
                    'required' => array( 'thread_id', 'user_id' ),
                ),
                'output_schema' => array(
                    'type'       => 'object',
                    'properties' => array(
                        'promoted' => array( 'type' => 'boolean' ),
                    ),
                ),
                'execute_callback'    => array( $this, 'execute_make_moderator' ),
                'permission_callback' => function() {
                    return current_user_can( 'manage_options' );
                },
                'meta' => array(
                    'show_in_rest' => true,
                    'mcp'          => array( 'public' => true ),
                    'annotations'  => array(
                        'readonly'    => false,
                        'destructive' => false,
                        'idempotent'  => true,
                    ),
                ),
            ) );
        }

        public function execute_make_moderator( $input )
        {
            $thread_id      = intval( $input['thread_id'] ?? 0 );
            $target_user_id = intval( $input['user_id'] ?? 0 );

            if ( $thread_id <= 0 || $target_user_id === 0 ) {
                return new WP_Error( 'invalid_params', 'Invalid conversation or user ID.' );
            }

            $is_participant = Better_Messages()->functions->is_thread_participant( $target_user_id, $thread_id, true );
            if ( ! $is_participant ) {
                return new WP_Error( 'not_participant', 'This user is not a participant of this conversation.' );
            }

            Better_Messages()->functions->add_moderator( $thread_id, $target_user_id );

            return array( 'promoted' => true );
        }

        private function register_unmake_moderator()
        {
            wp_register_ability( 'better-messages/unmake-moderator', array(
                'label'       => 'Remove Moderator Role',
                'description' => 'Removes moderator role from a user in a conversation.',
                'category'    => 'better-messages',
                'input_schema' => array(
                    'type'       => 'object',
                    'properties' => array(
                        'thread_id' => array(
                            'type'        => 'integer',
                            'description' => 'The conversation ID.',
                        ),
                        'user_id' => array(
                            'type'        => 'integer',
                            'description' => 'The user ID to remove moderator role from.',
                        ),
                    ),
                    'required' => array( 'thread_id', 'user_id' ),
                ),
                'output_schema' => array(
                    'type'       => 'object',
                    'properties' => array(
                        'demoted' => array( 'type' => 'boolean' ),
                    ),
                ),
                'execute_callback'    => array( $this, 'execute_unmake_moderator' ),
                'permission_callback' => function() {
                    return current_user_can( 'manage_options' );
                },
                'meta' => array(
                    'show_in_rest' => true,
                    'mcp'          => array( 'public' => true ),
                    'annotations'  => array(
                        'readonly'    => false,
                        'destructive' => false,
                        'idempotent'  => true,
                    ),
                ),
            ) );
        }

        public function execute_unmake_moderator( $input )
        {
            $thread_id      = intval( $input['thread_id'] ?? 0 );
            $target_user_id = intval( $input['user_id'] ?? 0 );

            if ( $thread_id <= 0 || $target_user_id === 0 ) {
                return new WP_Error( 'invalid_params', 'Invalid conversation or user ID.' );
            }

            Better_Messages()->functions->remove_moderator( $thread_id, $target_user_id );

            return array( 'demoted' => true );
        }

        private function register_get_stats()
        {
            wp_register_ability( 'better-messages/get-stats', array(
                'label'       => 'Get Messaging Statistics',
                'description' => 'Returns messaging statistics including total messages, conversations, and pending moderation count.',
                'category'    => 'better-messages',
                'output_schema' => array(
                    'type'       => 'object',
                    'properties' => array(
                        'total_threads'       => array( 'type' => 'integer' ),
                        'total_messages'      => array( 'type' => 'integer' ),
                        'pending_messages'    => array( 'type' => 'integer' ),
                        'total_chat_rooms'    => array( 'type' => 'integer' ),
                    ),
                ),
                'execute_callback'    => array( $this, 'execute_get_stats' ),
                'permission_callback' => function() {
                    return current_user_can( 'manage_options' );
                },
                'meta' => array(
                    'show_in_rest' => true,
                    'mcp'          => array( 'public' => true ),
                    'annotations'  => array(
                        'readonly'    => true,
                        'destructive' => false,
                        'idempotent'  => true,
                    ),
                ),
            ) );
        }

        public function execute_get_stats()
        {
            global $wpdb;

            $total_threads = (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM " . bm_get_table('threads')
            );

            $total_messages = (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM " . bm_get_table('messages')
            );

            $total_chat_rooms = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s",
                'bpbm-chat'
            ) );

            return array(
                'total_threads'    => $total_threads,
                'total_messages'   => $total_messages,
                'pending_messages' => Better_Messages()->functions->get_pending_messages_count(),
                'total_chat_rooms' => $total_chat_rooms,
            );
        }
    }

endif;

function Better_Messages_Abilities_Conversations()
{
    return Better_Messages_Abilities_Conversations::instance();
}
