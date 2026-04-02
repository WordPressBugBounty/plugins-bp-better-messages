<?php
defined( 'ABSPATH' ) || exit;

if ( !class_exists( 'Better_Messages_Abilities_Moderation' ) ):

    class Better_Messages_Abilities_Moderation
    {
        public static function instance()
        {
            static $instance = null;

            if ( null === $instance ) {
                $instance = new Better_Messages_Abilities_Moderation();
            }

            return $instance;
        }

        public function register()
        {
            $this->register_get_pending_messages();
            $this->register_approve_message();
            $this->register_reject_message();
            $this->register_blacklist_user();
            $this->register_unblacklist_user();
            $this->register_whitelist_user();
            $this->register_unwhitelist_user();
        }

        private function register_get_pending_messages()
        {
            wp_register_ability( 'better-messages/get-pending-messages', array(
                'label'       => 'Get Pending Messages',
                'description' => 'Returns messages awaiting moderation approval.',
                'category'    => 'better-messages',
                'input_schema' => array(
                    'type'       => 'object',
                    'properties' => array(
                        'page' => array(
                            'type'        => 'integer',
                            'description' => 'Page number.',
                            'default'     => 1,
                        ),
                        'per_page' => array(
                            'type'        => 'integer',
                            'description' => 'Messages per page.',
                            'default'     => 20,
                        ),
                    ),
                ),
                'output_schema' => array(
                    'type'       => 'object',
                    'properties' => array(
                        'total'    => array( 'type' => 'integer' ),
                        'messages' => array(
                            'type'  => 'array',
                            'items' => array(
                                'type'       => 'object',
                                'properties' => array(
                                    'message_id' => array( 'type' => 'integer' ),
                                    'thread_id'  => array( 'type' => 'integer' ),
                                    'sender_id'  => array( 'type' => 'integer' ),
                                    'sender_name' => array( 'type' => 'string' ),
                                    'content'    => array( 'type' => 'string' ),
                                    'created_at' => array( 'type' => 'string' ),
                                ),
                            ),
                        ),
                    ),
                ),
                'execute_callback'    => array( $this, 'execute_get_pending_messages' ),
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

        public function execute_get_pending_messages( $input )
        {
            global $wpdb;

            $page     = max( 1, intval( $input['page'] ?? 1 ) );
            $per_page = min( 50, max( 1, intval( $input['per_page'] ?? 20 ) ) );
            $offset   = ( $page - 1 ) * $per_page;

            $messages_table = bm_get_table('messages');

            $total = (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM `{$messages_table}`
                WHERE `is_pending` = 1
                AND `created_at` > 0
                AND `message` != '<!-- BBPM START THREAD -->'"
            );

            $rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT id, thread_id, sender_id, message, created_at
                FROM `{$messages_table}`
                WHERE `is_pending` = 1
                AND `created_at` > 0
                AND `message` != '<!-- BBPM START THREAD -->'
                ORDER BY `created_at` DESC
                LIMIT %d, %d",
                $offset, $per_page
            ) );

            $messages = array();
            foreach ( $rows as $msg ) {
                $messages[] = array(
                    'message_id'  => intval( $msg->id ),
                    'thread_id'   => intval( $msg->thread_id ),
                    'sender_id'   => intval( $msg->sender_id ),
                    'sender_name' => Better_Messages()->functions->get_name( $msg->sender_id ),
                    'content'     => wp_strip_all_tags( $msg->message ),
                    'created_at'  => date( 'c', (int) ( (int) $msg->created_at / 10 / 1000 ) ),
                );
            }

            return array(
                'total'    => $total,
                'messages' => $messages,
            );
        }

        private function register_approve_message()
        {
            wp_register_ability( 'better-messages/approve-message', array(
                'label'       => 'Approve Message',
                'description' => 'Approves a pending message so it becomes visible to all participants.',
                'category'    => 'better-messages',
                'input_schema' => array(
                    'type'       => 'object',
                    'properties' => array(
                        'message_id' => array(
                            'type'        => 'integer',
                            'description' => 'The message ID to approve.',
                        ),
                    ),
                    'required' => array( 'message_id' ),
                ),
                'output_schema' => array(
                    'type'       => 'object',
                    'properties' => array(
                        'approved' => array( 'type' => 'boolean' ),
                    ),
                ),
                'execute_callback'    => array( $this, 'execute_approve_message' ),
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

        public function execute_approve_message( $input )
        {
            $message_id = intval( $input['message_id'] ?? 0 );

            if ( $message_id <= 0 ) {
                return new WP_Error( 'invalid_message', 'Invalid message ID.' );
            }

            $user_id = Better_Messages()->functions->get_current_user_id();
            $result  = Better_Messages()->moderation->approve_message( $message_id, $user_id );

            if ( ! $result ) {
                return new WP_Error( 'approve_failed', 'Failed to approve message. It may not be pending.' );
            }

            return array( 'approved' => true );
        }

        private function register_reject_message()
        {
            wp_register_ability( 'better-messages/reject-message', array(
                'label'       => 'Reject Message',
                'description' => 'Rejects and deletes a pending message.',
                'category'    => 'better-messages',
                'input_schema' => array(
                    'type'       => 'object',
                    'properties' => array(
                        'message_id' => array(
                            'type'        => 'integer',
                            'description' => 'The message ID to reject.',
                        ),
                    ),
                    'required' => array( 'message_id' ),
                ),
                'output_schema' => array(
                    'type'       => 'object',
                    'properties' => array(
                        'rejected' => array( 'type' => 'boolean' ),
                    ),
                ),
                'execute_callback'    => array( $this, 'execute_reject_message' ),
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

        public function execute_reject_message( $input )
        {
            $message_id = intval( $input['message_id'] ?? 0 );

            if ( $message_id <= 0 ) {
                return new WP_Error( 'invalid_message', 'Invalid message ID.' );
            }

            $msg = Better_Messages()->functions->get_message( $message_id );

            if ( ! $msg || ! $msg->is_pending ) {
                return new WP_Error( 'not_pending', 'Message is not pending or does not exist.' );
            }

            Better_Messages()->functions->delete_message( $message_id, intval( $msg->thread_id ) );

            return array( 'rejected' => true );
        }

        private function register_blacklist_user()
        {
            wp_register_ability( 'better-messages/blacklist-user', array(
                'label'       => 'Blacklist User',
                'description' => 'Adds a user to the messaging blacklist. Their messages will require moderation.',
                'category'    => 'better-messages',
                'input_schema' => array(
                    'type'       => 'object',
                    'properties' => array(
                        'user_id' => array(
                            'type'        => 'integer',
                            'description' => 'The user ID to blacklist.',
                        ),
                    ),
                    'required' => array( 'user_id' ),
                ),
                'output_schema' => array(
                    'type'       => 'object',
                    'properties' => array(
                        'blacklisted' => array( 'type' => 'boolean' ),
                    ),
                ),
                'execute_callback'    => array( $this, 'execute_blacklist_user' ),
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

        public function execute_blacklist_user( $input )
        {
            $user_id = intval( $input['user_id'] ?? 0 );

            if ( $user_id === 0 ) {
                return new WP_Error( 'invalid_user', 'Invalid user ID.' );
            }

            $result = Better_Messages()->moderation->blacklist_user( $user_id );

            return array( 'blacklisted' => (bool) $result );
        }

        private function register_unblacklist_user()
        {
            wp_register_ability( 'better-messages/unblacklist-user', array(
                'label'       => 'Remove User from Blacklist',
                'description' => 'Removes a user from the messaging blacklist.',
                'category'    => 'better-messages',
                'input_schema' => array(
                    'type'       => 'object',
                    'properties' => array(
                        'user_id' => array(
                            'type'        => 'integer',
                            'description' => 'The user ID to remove from blacklist.',
                        ),
                    ),
                    'required' => array( 'user_id' ),
                ),
                'output_schema' => array(
                    'type'       => 'object',
                    'properties' => array(
                        'removed' => array( 'type' => 'boolean' ),
                    ),
                ),
                'execute_callback'    => array( $this, 'execute_unblacklist_user' ),
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

        public function execute_unblacklist_user( $input )
        {
            $user_id = intval( $input['user_id'] ?? 0 );

            if ( $user_id === 0 ) {
                return new WP_Error( 'invalid_user', 'Invalid user ID.' );
            }

            $result = Better_Messages()->moderation->unblacklist_user( $user_id );

            return array( 'removed' => (bool) $result );
        }

        private function register_whitelist_user()
        {
            wp_register_ability( 'better-messages/whitelist-user', array(
                'label'       => 'Whitelist User',
                'description' => 'Adds a user to the messaging whitelist. Their messages will skip moderation.',
                'category'    => 'better-messages',
                'input_schema' => array(
                    'type'       => 'object',
                    'properties' => array(
                        'user_id' => array(
                            'type'        => 'integer',
                            'description' => 'The user ID to whitelist.',
                        ),
                    ),
                    'required' => array( 'user_id' ),
                ),
                'output_schema' => array(
                    'type'       => 'object',
                    'properties' => array(
                        'whitelisted' => array( 'type' => 'boolean' ),
                    ),
                ),
                'execute_callback'    => array( $this, 'execute_whitelist_user' ),
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

        public function execute_whitelist_user( $input )
        {
            $user_id = intval( $input['user_id'] ?? 0 );

            if ( $user_id === 0 ) {
                return new WP_Error( 'invalid_user', 'Invalid user ID.' );
            }

            $result = Better_Messages()->moderation->whitelist_user( $user_id );

            return array( 'whitelisted' => (bool) $result );
        }

        private function register_unwhitelist_user()
        {
            wp_register_ability( 'better-messages/unwhitelist-user', array(
                'label'       => 'Remove User from Whitelist',
                'description' => 'Removes a user from the messaging whitelist.',
                'category'    => 'better-messages',
                'input_schema' => array(
                    'type'       => 'object',
                    'properties' => array(
                        'user_id' => array(
                            'type'        => 'integer',
                            'description' => 'The user ID to remove from whitelist.',
                        ),
                    ),
                    'required' => array( 'user_id' ),
                ),
                'output_schema' => array(
                    'type'       => 'object',
                    'properties' => array(
                        'removed' => array( 'type' => 'boolean' ),
                    ),
                ),
                'execute_callback'    => array( $this, 'execute_unwhitelist_user' ),
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

        public function execute_unwhitelist_user( $input )
        {
            $user_id = intval( $input['user_id'] ?? 0 );

            if ( $user_id === 0 ) {
                return new WP_Error( 'invalid_user', 'Invalid user ID.' );
            }

            $result = Better_Messages()->moderation->unwhitelist_user( $user_id );

            return array( 'removed' => (bool) $result );
        }
    }

endif;

function Better_Messages_Abilities_Moderation()
{
    return Better_Messages_Abilities_Moderation::instance();
}
