<?php
defined( 'ABSPATH' ) || exit;

if ( !class_exists( 'Better_Messages_Abilities' ) ):

    class Better_Messages_Abilities
    {
        public static function instance()
        {
            static $instance = null;

            if ( null === $instance ) {
                $instance = new Better_Messages_Abilities();
            }

            return $instance;
        }

        public function __construct()
        {
            require_once __DIR__ . '/users.php';
            require_once __DIR__ . '/conversations.php';
            require_once __DIR__ . '/messaging.php';
            require_once __DIR__ . '/participants.php';
            require_once __DIR__ . '/moderation.php';

            add_action( 'wp_abilities_api_categories_init', array( $this, 'register_categories' ) );
            add_action( 'wp_abilities_api_init', array( $this, 'register_abilities' ) );
        }

        public function register_categories()
        {
            wp_register_ability_category( 'better-messages', array(
                'label'       => 'Better Messages',
                'description' => 'Messaging abilities for sending, receiving, and managing conversations.',
            ) );
        }

        public function register_abilities()
        {
            Better_Messages_Abilities_Users()->register();
            Better_Messages_Abilities_Conversations()->register();
            Better_Messages_Abilities_Messaging()->register();
            Better_Messages_Abilities_Participants()->register();
            Better_Messages_Abilities_Moderation()->register();
        }
    }

endif;

function Better_Messages_Abilities()
{
    return Better_Messages_Abilities::instance();
}
