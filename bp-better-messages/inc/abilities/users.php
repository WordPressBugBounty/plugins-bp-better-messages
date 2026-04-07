<?php
defined( 'ABSPATH' ) || exit;

if ( !class_exists( 'Better_Messages_Abilities_Users' ) ):

    class Better_Messages_Abilities_Users
    {
        public static function instance()
        {
            static $instance = null;

            if ( null === $instance ) {
                $instance = new Better_Messages_Abilities_Users();
            }

            return $instance;
        }

        public function register()
        {
            $this->register_search_users();
            $this->register_get_unread_count();
        }

        private function register_search_users()
        {
            wp_register_ability( 'better-messages/search-users', array(
                'label'       => 'Search Users',
                'description' => 'Search for users by name or username to start a conversation with.',
                'category'    => 'better-messages',
                'input_schema' => array(
                    'type'       => 'object',
                    'properties' => array(
                        'query' => array(
                            'type'        => 'string',
                            'description' => 'Name or username to search for.',
                        ),
                        'limit' => array(
                            'type'        => 'integer',
                            'description' => 'Maximum number of results to return.',
                            'default'     => 10,
                        ),
                    ),
                    'required' => array( 'query' ),
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
                'execute_callback'    => array( $this, 'execute_search_users' ),
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

        public function execute_search_users( $input )
        {
            $user_id = Better_Messages()->functions->get_current_user_id();
            $query   = sanitize_text_field( $input['query'] ?? '' );
            $limit   = intval( $input['limit'] ?? 10 );

            if ( empty( $query ) ) {
                return new WP_Error( 'empty_query', 'Search query cannot be empty.' );
            }

            if ( $limit < 1 ) $limit = 1;
            if ( $limit > 50 ) $limit = 50;

            $user_ids = Better_Messages_Search::instance()->get_users_results( $query, $user_id, array(), true, $limit );

            $results = array();
            foreach ( $user_ids as $uid ) {
                $results[] = array(
                    'user_id' => intval( $uid ),
                    'name'    => Better_Messages()->functions->get_name( $uid ),
                    'avatar'  => Better_Messages()->functions->get_avatar( $uid, 100, array( 'html' => false ) ),
                );
            }

            return $results;
        }

        private function register_get_unread_count()
        {
            wp_register_ability( 'better-messages/get-unread-count', array(
                'label'       => 'Get Unread Count',
                'description' => 'Returns the total number of unread messages for a user.',
                'category'    => 'better-messages',
                'input_schema' => array(
                    'type'       => 'object',
                    'properties' => array(
                        'user_id' => array(
                            'type'        => 'integer',
                            'description' => 'User ID to get unread count for. Defaults to the current user.',
                        ),
                    ),
                ),
                'output_schema' => array(
                    'type'       => 'object',
                    'properties' => array(
                        'unread_count' => array(
                            'type'        => 'integer',
                            'description' => 'Total number of unread messages.',
                        ),
                    ),
                ),
                'execute_callback'    => array( $this, 'execute_get_unread_count' ),
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

        public function execute_get_unread_count( $input )
        {
            $user_id = intval( $input['user_id'] ?? 0 );
            if ( $user_id === 0 ) {
                $user_id = Better_Messages()->functions->get_current_user_id();
            }

            return array(
                'unread_count' => Better_Messages()->functions->get_user_unread_count( $user_id ),
            );
        }
    }

endif;

function Better_Messages_Abilities_Users()
{
    return Better_Messages_Abilities_Users::instance();
}
