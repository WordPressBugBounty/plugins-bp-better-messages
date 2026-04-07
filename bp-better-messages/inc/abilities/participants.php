<?php
defined( 'ABSPATH' ) || exit;

if ( !class_exists( 'Better_Messages_Abilities_Participants' ) ):

    class Better_Messages_Abilities_Participants
    {
        public static function instance()
        {
            static $instance = null;

            if ( null === $instance ) {
                $instance = new Better_Messages_Abilities_Participants();
            }

            return $instance;
        }

        public function register()
        {
            $this->register_list_participants();
            $this->register_add_participant();
            $this->register_remove_participant();
        }

        private function register_list_participants()
        {
            wp_register_ability( 'better-messages/list-participants', array(
                'label'       => 'List Participants',
                'description' => 'Returns the list of participants in a conversation.',
                'category'    => 'better-messages',
                'input_schema' => array(
                    'type'       => 'object',
                    'properties' => array(
                        'thread_id' => array(
                            'type'        => 'integer',
                            'description' => 'The conversation ID.',
                        ),
                    ),
                    'required' => array( 'thread_id' ),
                ),
                'output_schema' => array(
                    'type'  => 'array',
                    'items' => array(
                        'type'       => 'object',
                        'properties' => array(
                            'user_id' => array( 'type' => 'integer' ),
                            'name'    => array( 'type' => 'string' ),
                            'avatar'  => array( 'type' => 'string' ),
                        ),
                    ),
                ),
                'execute_callback'    => array( $this, 'execute_list_participants' ),
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

        public function execute_list_participants( $input )
        {
            $thread_id = intval( $input['thread_id'] ?? 0 );

            if ( $thread_id <= 0 ) {
                return new WP_Error( 'invalid_thread', 'Invalid conversation ID.' );
            }

            $recipients   = Better_Messages()->functions->get_recipients( $thread_id );
            $participants = array();

            if ( is_array( $recipients ) ) {
                foreach ( $recipients as $uid => $recipient ) {
                    $participants[] = array(
                        'user_id' => intval( $uid ),
                        'name'    => Better_Messages()->functions->get_name( $uid ),
                        'avatar'  => Better_Messages()->functions->get_avatar( $uid, 100, array( 'html' => false ) ),
                    );
                }
            }

            return $participants;
        }

        private function register_add_participant()
        {
            wp_register_ability( 'better-messages/add-participant', array(
                'label'       => 'Add Participant',
                'description' => 'Adds one or more users to a conversation.',
                'category'    => 'better-messages',
                'input_schema' => array(
                    'type'       => 'object',
                    'properties' => array(
                        'thread_id' => array(
                            'type'        => 'integer',
                            'description' => 'The conversation ID.',
                        ),
                        'user_ids' => array(
                            'type'        => 'array',
                            'items'       => array( 'type' => 'integer' ),
                            'description' => 'User IDs to add to the conversation.',
                        ),
                    ),
                    'required' => array( 'thread_id', 'user_ids' ),
                ),
                'output_schema' => array(
                    'type'       => 'object',
                    'properties' => array(
                        'added' => array(
                            'type'  => 'array',
                            'items' => array( 'type' => 'integer' ),
                        ),
                    ),
                ),
                'execute_callback'    => array( $this, 'execute_add_participant' ),
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

        public function execute_add_participant( $input )
        {
            $thread_id = intval( $input['thread_id'] ?? 0 );
            $user_ids  = array_map( 'intval', $input['user_ids'] ?? array() );

            if ( $thread_id <= 0 || empty( $user_ids ) ) {
                return new WP_Error( 'invalid_params', 'Invalid conversation ID or user IDs.' );
            }

            $added = array();
            foreach ( $user_ids as $uid ) {
                $result = Better_Messages()->functions->add_participant_to_thread( $thread_id, $uid );
                if ( $result ) {
                    $added[] = $uid;
                }
            }

            return array( 'added' => $added );
        }

        private function register_remove_participant()
        {
            wp_register_ability( 'better-messages/remove-participant', array(
                'label'       => 'Remove Participant',
                'description' => 'Removes a user from a conversation.',
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
                            'description' => 'The user ID to remove.',
                        ),
                    ),
                    'required' => array( 'thread_id', 'user_id' ),
                ),
                'output_schema' => array(
                    'type'       => 'object',
                    'properties' => array(
                        'removed' => array( 'type' => 'boolean' ),
                    ),
                ),
                'execute_callback'    => array( $this, 'execute_remove_participant' ),
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

        public function execute_remove_participant( $input )
        {
            $thread_id      = intval( $input['thread_id'] ?? 0 );
            $target_user_id = intval( $input['user_id'] ?? 0 );

            if ( $thread_id <= 0 || $target_user_id === 0 ) {
                return new WP_Error( 'invalid_params', 'Invalid conversation or user ID.' );
            }

            $result = Better_Messages()->functions->remove_participant_from_thread( $thread_id, $target_user_id );

            return array( 'removed' => (bool) $result );
        }
    }

endif;

function Better_Messages_Abilities_Participants()
{
    return Better_Messages_Abilities_Participants::instance();
}
