<?php
defined( 'ABSPATH' ) || exit;

if ( !class_exists( 'Better_Messages_Abilities_Messaging' ) ):

    class Better_Messages_Abilities_Messaging
    {
        public static function instance()
        {
            static $instance = null;

            if ( null === $instance ) {
                $instance = new Better_Messages_Abilities_Messaging();
            }

            return $instance;
        }

        public function register()
        {
            $this->register_send_message();
            $this->register_get_messages();
            $this->register_search_messages();
            $this->register_edit_message();
            $this->register_delete_messages();
        }

        private function register_send_message()
        {
            wp_register_ability( 'better-messages/send-message', array(
                'label'       => 'Send Message',
                'description' => 'Sends a message to a conversation. Optionally send as a specific user.',
                'category'    => 'better-messages',
                'input_schema' => array(
                    'type'       => 'object',
                    'properties' => array(
                        'thread_id' => array(
                            'type'        => 'integer',
                            'description' => 'The conversation ID to send the message to.',
                        ),
                        'message' => array(
                            'type'        => 'string',
                            'description' => 'The message content.',
                        ),
                        'sender_id' => array(
                            'type'        => 'integer',
                            'description' => 'User ID to send the message as. Defaults to the current user.',
                        ),
                    ),
                    'required' => array( 'thread_id', 'message' ),
                ),
                'output_schema' => array(
                    'type'       => 'object',
                    'properties' => array(
                        'message_id' => array( 'type' => 'integer' ),
                        'thread_id'  => array( 'type' => 'integer' ),
                        'sent'       => array( 'type' => 'boolean' ),
                    ),
                ),
                'execute_callback'    => array( $this, 'execute_send_message' ),
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

        public function execute_send_message( $input )
        {
            $user_id   = intval( $input['sender_id'] ?? 0 );
            if ( $user_id === 0 ) {
                $user_id = Better_Messages()->functions->get_current_user_id();
            }
            $thread_id = intval( $input['thread_id'] ?? 0 );
            $message   = $input['message'] ?? '';

            if ( $thread_id <= 0 ) {
                return new WP_Error( 'invalid_thread', 'Invalid conversation ID.' );
            }

            if ( empty( $message ) ) {
                return new WP_Error( 'empty_message', 'Message content cannot be empty.' );
            }

            $content = Better_Messages()->functions->filter_message_content( $message );

            $result = Better_Messages()->functions->new_message( array(
                'sender_id'  => $user_id,
                'thread_id'  => $thread_id,
                'content'    => $content,
                'return'     => 'message_id',
                'error_type' => 'wp_error',
            ) );

            if ( is_wp_error( $result ) ) {
                return $result;
            }

            Better_Messages()->functions->messages_mark_thread_read( $thread_id, $user_id );

            return array(
                'message_id' => intval( $result ),
                'thread_id'  => $thread_id,
                'sent'       => true,
            );
        }

        private function register_get_messages()
        {
            wp_register_ability( 'better-messages/get-messages', array(
                'label'       => 'Get Messages',
                'description' => 'Returns messages from a conversation.',
                'category'    => 'better-messages',
                'input_schema' => array(
                    'type'       => 'object',
                    'properties' => array(
                        'thread_id' => array(
                            'type'        => 'integer',
                            'description' => 'The conversation ID.',
                        ),
                        'count' => array(
                            'type'        => 'integer',
                            'description' => 'Number of messages to return.',
                            'default'     => 20,
                        ),
                    ),
                    'required' => array( 'thread_id' ),
                ),
                'output_schema' => array(
                    'type'  => 'array',
                    'items' => array(
                        'type'       => 'object',
                        'properties' => array(
                            'message_id'  => array( 'type' => 'integer' ),
                            'sender_id'   => array( 'type' => 'integer' ),
                            'sender_name' => array( 'type' => 'string' ),
                            'content'     => array( 'type' => 'string' ),
                            'created_at'  => array( 'type' => 'string' ),
                        ),
                    ),
                ),
                'execute_callback'    => array( $this, 'execute_get_messages' ),
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

        public function execute_get_messages( $input )
        {
            $thread_id = intval( $input['thread_id'] ?? 0 );
            $count     = intval( $input['count'] ?? 20 );

            if ( $thread_id <= 0 ) {
                return new WP_Error( 'invalid_thread', 'Invalid conversation ID.' );
            }

            if ( $count < 1 ) $count = 1;
            if ( $count > 50 ) $count = 50;

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

            return $messages;
        }

        private function register_search_messages()
        {
            wp_register_ability( 'better-messages/search-messages', array(
                'label'       => 'Search Messages',
                'description' => 'Searches for messages by keyword across all conversations.',
                'category'    => 'better-messages',
                'input_schema' => array(
                    'type'       => 'object',
                    'properties' => array(
                        'query' => array(
                            'type'        => 'string',
                            'description' => 'Search keyword.',
                        ),
                    ),
                    'required' => array( 'query' ),
                ),
                'output_schema' => array(
                    'type'  => 'array',
                    'items' => array(
                        'type'       => 'object',
                        'properties' => array(
                            'thread_id'   => array( 'type' => 'integer' ),
                            'message_id'  => array( 'type' => 'integer' ),
                            'count'       => array( 'type' => 'integer' ),
                            'sender_name' => array( 'type' => 'string' ),
                            'content'     => array( 'type' => 'string' ),
                            'created_at'  => array( 'type' => 'string' ),
                        ),
                    ),
                ),
                'execute_callback'    => array( $this, 'execute_search_messages' ),
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

        public function execute_search_messages( $input )
        {
            $user_id = Better_Messages()->functions->get_current_user_id();
            $query   = sanitize_text_field( $input['query'] ?? '' );

            if ( strlen( $query ) < 2 ) {
                return new WP_Error( 'query_too_short', 'Search query must be at least 2 characters.' );
            }

            $results = Better_Messages_Search::instance()->get_messages_results( $query, $user_id );

            return array_map( function( $item ) {
                $mid = intval( $item['message_id'] );
                $msg = Better_Messages()->functions->get_message( $mid );

                return array(
                    'thread_id'   => intval( $item['thread_id'] ),
                    'message_id'  => $mid,
                    'count'       => intval( $item['count'] ),
                    'sender_name' => $msg ? Better_Messages()->functions->get_name( $msg->sender_id ) : '',
                    'content'     => $msg ? wp_strip_all_tags( $msg->message ) : '',
                    'created_at'  => $msg ? date( 'c', (int) ( (int) $msg->created_at / 10 / 1000 ) ) : '',
                );
            }, $results );
        }

        private function register_edit_message()
        {
            wp_register_ability( 'better-messages/edit-message', array(
                'label'       => 'Edit Message',
                'description' => 'Edits an existing message content.',
                'category'    => 'better-messages',
                'input_schema' => array(
                    'type'       => 'object',
                    'properties' => array(
                        'thread_id' => array(
                            'type'        => 'integer',
                            'description' => 'The conversation ID.',
                        ),
                        'message_id' => array(
                            'type'        => 'integer',
                            'description' => 'The message ID to edit.',
                        ),
                        'message' => array(
                            'type'        => 'string',
                            'description' => 'New message content.',
                        ),
                    ),
                    'required' => array( 'thread_id', 'message_id', 'message' ),
                ),
                'output_schema' => array(
                    'type'       => 'object',
                    'properties' => array(
                        'edited' => array( 'type' => 'boolean' ),
                    ),
                ),
                'execute_callback'    => array( $this, 'execute_edit_message' ),
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

        public function execute_edit_message( $input )
        {
            $thread_id  = intval( $input['thread_id'] ?? 0 );
            $message_id = intval( $input['message_id'] ?? 0 );
            $message    = $input['message'] ?? '';

            if ( $thread_id <= 0 || $message_id <= 0 ) {
                return new WP_Error( 'invalid_params', 'Invalid conversation or message ID.' );
            }

            if ( empty( $message ) ) {
                return new WP_Error( 'empty_message', 'Message content cannot be empty.' );
            }

            $existing = Better_Messages()->functions->get_message( $message_id );

            if ( ! $existing || intval( $existing->thread_id ) !== $thread_id ) {
                return new WP_Error( 'not_found', 'Message not found.' );
            }

            $content = Better_Messages()->functions->filter_message_content( $message );

            $result = Better_Messages()->functions->update_message( array(
                'sender_id'  => intval( $existing->sender_id ),
                'thread_id'  => $thread_id,
                'message_id' => $message_id,
                'content'    => $content,
            ) );

            if ( ! $result ) {
                return new WP_Error( 'edit_failed', 'Failed to edit message.' );
            }

            return array( 'edited' => true );
        }

        private function register_delete_messages()
        {
            wp_register_ability( 'better-messages/delete-messages', array(
                'label'       => 'Delete Messages',
                'description' => 'Deletes one or more messages from a conversation.',
                'category'    => 'better-messages',
                'input_schema' => array(
                    'type'       => 'object',
                    'properties' => array(
                        'thread_id' => array(
                            'type'        => 'integer',
                            'description' => 'The conversation ID.',
                        ),
                        'message_ids' => array(
                            'type'        => 'array',
                            'items'       => array( 'type' => 'integer' ),
                            'description' => 'Message IDs to delete.',
                        ),
                    ),
                    'required' => array( 'thread_id', 'message_ids' ),
                ),
                'output_schema' => array(
                    'type'       => 'object',
                    'properties' => array(
                        'deleted' => array(
                            'type'  => 'array',
                            'items' => array( 'type' => 'integer' ),
                        ),
                    ),
                ),
                'execute_callback'    => array( $this, 'execute_delete_messages' ),
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

        public function execute_delete_messages( $input )
        {
            $thread_id   = intval( $input['thread_id'] ?? 0 );
            $message_ids = array_map( 'intval', $input['message_ids'] ?? array() );

            if ( $thread_id <= 0 || empty( $message_ids ) ) {
                return new WP_Error( 'invalid_params', 'Invalid conversation ID or message IDs.' );
            }

            $deleted = array();

            foreach ( $message_ids as $mid ) {
                $message = Better_Messages()->functions->get_message( $mid );

                if ( ! $message || intval( $message->thread_id ) !== $thread_id ) continue;

                Better_Messages()->functions->delete_message( $mid, $thread_id );
                $deleted[] = $mid;
            }

            return array( 'deleted' => $deleted );
        }
    }

endif;

function Better_Messages_Abilities_Messaging()
{
    return Better_Messages_Abilities_Messaging::instance();
}
