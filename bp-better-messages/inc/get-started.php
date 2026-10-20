<?php
defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Better_Messages_Get_Started' ) ) :

    class Better_Messages_Get_Started
    {
        const OPTION = 'better_messages_get_started';

        const DISMISSED_OPTION = 'better_messages_get_started_dismissed';

        const CARDS = array( 'visitors', 'room', 'plugins', 'appearance', 'files', 'notifications', 'restrictions', 'moderation' );

        public static function instance()
        {
            static $instance = null;

            if ( null === $instance ) {
                $instance = new Better_Messages_Get_Started();
            }

            return $instance;
        }

        public function __construct()
        {
            add_action( 'rest_api_init', array( $this, 'rest_api_init' ) );
        }

        public function rest_api_init()
        {
            register_rest_route( 'better-messages/v1/admin', '/getStarted/menus', array(
                'methods'             => 'GET',
                'callback'            => array( $this, 'rest_menus' ),
                'permission_callback' => array( $this, 'user_is_admin' ),
            ) );

            register_rest_route( 'better-messages/v1/admin', '/getStarted/menu', array(
                'methods'             => 'POST',
                'callback'            => array( $this, 'rest_add_to_menu' ),
                'permission_callback' => array( $this, 'user_is_admin' ),
                'args'                => array(
                    'menu' => array( 'required' => true, 'type' => 'string' ),
                ),
            ) );

            register_rest_route( 'better-messages/v1/admin', '/getStarted/chatRoom', array(
                'methods'             => 'POST',
                'callback'            => array( $this, 'rest_create_chat_room' ),
                'permission_callback' => array( $this, 'user_is_admin' ),
                'args'                => array(
                    'title'  => array( 'required' => true, 'type' => 'string' ),
                    'guests' => array( 'type' => 'boolean', 'default' => false ),
                ),
            ) );

            register_rest_route( 'better-messages/v1/admin', '/getStarted/dismiss', array(
                'methods'             => 'POST',
                'callback'            => array( $this, 'rest_dismiss' ),
                'permission_callback' => array( $this, 'user_is_admin' ),
                'args'                => array(
                    'card'      => array( 'required' => true, 'type' => 'string', 'enum' => self::CARDS ),
                    'dismissed' => array( 'type' => 'boolean', 'default' => true ),
                ),
            ) );

            register_rest_route( 'better-messages/v1/admin', '/getStarted/finish', array(
                'methods'             => 'POST',
                'callback'            => array( $this, 'rest_finish' ),
                'permission_callback' => array( $this, 'user_is_admin' ),
            ) );
        }

        public function user_is_admin()
        {
            return current_user_can( 'manage_options' );
        }

        public function first_install()
        {
            update_option( self::OPTION, 'show', false );
        }

        public function is_pending()
        {
            return get_option( self::OPTION ) === 'show';
        }

        public function profile_location()
        {
            if ( class_exists( 'BuddyPress' ) ) {
                return defined( 'BP_PLATFORM_VERSION' ) ? 'BuddyBoss' : 'BuddyPress';
            }

            if ( defined( 'ultimatemember_version' ) ) {
                return 'Ultimate Member';
            }

            if ( defined( 'FLUENT_COMMUNITY_PLUGIN_VERSION' ) ) {
                return 'FluentCommunity';
            }

            if ( defined( 'USERSWP_VERSION' ) ) {
                return 'UsersWP';
            }

            if ( defined( 'PROGRID_PLUGIN_VERSION' ) ) {
                return 'ProfileGrid';
            }

            if ( class_exists( 'WP_User_Manager' ) ) {
                return 'WP User Manager';
            }

            return '';
        }

        public function messages_page_missing()
        {
            $chat_page = (string) Better_Messages()->settings['chatPage'];

            if ( $chat_page === '0' ) {
                return $this->profile_location() === '';
            }

            if ( ! is_numeric( $chat_page ) ) {
                return false;
            }

            return get_post_status( (int) $chat_page ) !== 'publish';
        }

        public function init_data()
        {
            $user = Better_Messages()->functions->rest_user_item( get_current_user_id() );

            return array(
                'show'            => $this->is_pending(),
                'profileLocation' => $this->profile_location(),
                'menus'           => $this->menus(),
                'blockTheme'      => $this->is_block_theme(),
                'menusUrl'        => admin_url( 'nav-menus.php' ),
                'navigationUrl'   => admin_url( 'site-editor.php?' . ( version_compare( get_bloginfo( 'version' ), '6.6', '>=' ) ? 'p' : 'path' ) . '=%2Fnavigation' ),
                'contactUrl'      => admin_url( 'admin.php?page=better-messages-contact' ),
                'chatRoomsUrl'    => admin_url( 'admin.php?page=better-messages-chat-rooms' ),
                'chatRoom'        => $this->chat_room_page(),
                'dismissed'       => $this->dismissed(),
                'appearance'      => $this->appearance_changed(),
                'detected'        => array(
                    'hasWpForo'           => defined( 'WPFORO_VERSION' ),
                    'hasBpToolkit'        => class_exists( 'BP_Toolkit' ) || class_exists( 'BPTK_Block' ),
                    'hasGTranslate'       => defined( 'GTRANSLATE_VERSION' ),
                    'hasWeglot'           => defined( 'WEGLOT_VERSION' ),
                    'hasAutomatorWp'      => function_exists( 'automatorwp_register_integration' ),
                    'hasUncannyAutomator' => function_exists( 'automator_add_integration' ),
                ),
                'currentUser'     => array(
                    'value'  => (int) $user['user_id'],
                    'label'  => (string) $user['name'],
                    'avatar' => (string) $user['avatar'],
                ),
            );
        }

        public function usage()
        {
            $settings  = Better_Messages()->settings;
            $state     = get_option( self::OPTION, '' );
            $dismissed = $this->dismissed();
            $waiting   = 0;
            $connected = 0;

            foreach ( $this->switch_integrations() as $key => $detected ) {
                if ( ! $detected ) {
                    continue;
                }

                if ( isset( $settings[ $key ] ) && $settings[ $key ] === '1' ) {
                    $connected++;
                } else {
                    $waiting++;
                }
            }

            $done = array(
                'members'       => ! $this->messages_page_missing(),
                'visitors'      => $settings['guestChat'] === '1' && ( $this->published_page( $settings['chatPage'] ) || $this->published_page( isset( $settings['guestChatPage'] ) ? $settings['guestChatPage'] : 0 ) ),
                'room'          => null !== $this->chat_room_page(),
                'plugins'       => 0 === $waiting,
                'appearance'    => $this->appearance_changed(),
                'files'         => $settings['attachmentsEnable'] === '1',
                'notifications' => (int) $settings['notificationsInterval'] > 0,
                'restrictions'  => false,
                'moderation'    => $settings['allowReports'] === '1' && $settings['allowUsersBlock'] === '1',
            );

            $usage = array(
                'onboarding.state' => 'show' === $state ? 'open' : ( 'finished' === $state ? 'finished' : 'before' ),
            );

            foreach ( $done as $card => $is_done ) {
                if ( in_array( $card, $dismissed, true ) ) {
                    $usage[ 'onboarding.card.' . $card ] = 'dismissed';
                } else if ( $is_done ) {
                    $usage[ 'onboarding.card.' . $card ] = 'done';
                } else {
                    $usage[ 'onboarding.card.' . $card ] = 'restrictions' === $card ? 'shown' : 'todo';
                }
            }

            $usage['onboarding.integrations.connected'] = $connected;
            $usage['onboarding.integrations.waiting']   = $waiting;

            return $usage;
        }

        private function published_page( $page_id )
        {
            return is_numeric( $page_id ) && (int) $page_id > 0 && get_post_status( (int) $page_id ) === 'publish';
        }

        private function switch_integrations()
        {
            return array(
                'wooCommerceIntegration'       => class_exists( 'WooCommerce' ),
                'dokanIntegration'             => class_exists( 'WeDevs_Dokan' ),
                'wcVendorsIntegration'         => class_exists( 'WCV_Vendors' ),
                'wcfmIntegration'              => class_exists( 'WCFM' ),
                'MultiVendorXIntegration'      => defined( 'MVX_PLUGIN_VERSION' ) || ( defined( 'MULTIVENDORX_PLUGIN_VERSION' ) && version_compare( MULTIVENDORX_PLUGIN_VERSION, '5.0.0', '>=' ) ),
                'learnPressIntegration'        => class_exists( 'LearnPress' ),
                'tutorLmsIntegration'          => defined( 'TUTOR_VERSION' ),
                'learnDashIntegration'         => defined( 'LEARNDASH_VERSION' ),
                'masterStudyIntegration'       => defined( 'STM_LMS_VERSION' ),
                'hivepressIntegration'         => function_exists( 'hivepress' ),
                'directoristIntegration'       => defined( 'ATBDP_VERSION' ),
                'classifiedListingIntegration' => defined( 'RTCL_VERSION' ) || function_exists( 'rtcl' ),
                'geodirIntegration'            => defined( 'GEODIRECTORY_VERSION' ),
                'wpJobManagerIntegration'      => class_exists( 'WP_Job_Manager' ),
                'motorsIntegration'            => defined( 'STM_LISTINGS_V' ),
                'houzezIntegration'            => defined( 'HOUZEZ_THEME_VERSION' ),
                'realhomesIntegration'         => defined( 'RH_TEXT_DOMAIN' ),
            );
        }

        public function dismissed()
        {
            return array_values( array_intersect( self::CARDS, (array) get_option( self::DISMISSED_OPTION, array() ) ) );
        }

        public function appearance_changed()
        {
            $preset = get_option( 'bm_design_preset', '' );

            if ( is_string( $preset ) && $preset !== '' && $preset !== 'default' ) {
                return true;
            }

            foreach ( (array) get_option( 'bm_design_overrides', array() ) as $tokens ) {
                if ( ! empty( $tokens ) ) {
                    return true;
                }
            }

            return trim( (string) get_option( 'bm_design_custom_css', '' ) ) !== '';
        }

        public function rest_dismiss( WP_REST_Request $request )
        {
            $card      = (string) $request->get_param( 'card' );
            $dismissed = array_diff( $this->dismissed(), array( $card ) );

            if ( $request->get_param( 'dismissed' ) ) {
                $dismissed[] = $card;
            }

            update_option( self::DISMISSED_OPTION, array_values( $dismissed ), false );

            return rest_ensure_response( array( 'dismissed' => $this->dismissed() ) );
        }

        public function chat_room_page()
        {
            global $wpdb;

            $pages = $wpdb->get_results( $wpdb->prepare(
                "SELECT ID, post_content FROM {$wpdb->posts} WHERE post_type = 'page' AND post_status = 'publish' AND ( post_content LIKE %s OR post_content LIKE %s ) ORDER BY ID DESC LIMIT 20",
                '%' . $wpdb->esc_like( 'better_messages_chat_room' ) . '%',
                '%' . $wpdb->esc_like( 'wp:better-messages/chat-room' ) . '%'
            ) );

            foreach ( $pages as $page ) {
                if ( ! preg_match( '/better_messages_chat_room\s[^\]]*?\bid=["\']?(\d+)|"chatId":(\d+)/', $page->post_content, $match ) ) {
                    continue;
                }

                $room_id = (int) ( isset( $match[2] ) && $match[2] !== '' ? $match[2] : $match[1] );
                $room    = get_post( $room_id );

                if ( ! $room || $room->post_type !== 'bpbm-chat' || $room->post_status !== 'publish' ) {
                    continue;
                }

                return $this->chat_room_item( $room_id, (int) $page->ID );
            }

            return null;
        }

        private function chat_room_item( $room_id, $page_id )
        {
            return array(
                'title'   => get_post_field( 'post_title', $room_id ),
                'pageUrl' => get_permalink( $page_id ),
                'roomUrl' => admin_url( 'admin.php?page=better-messages-chat-rooms#room-' . (int) $room_id ),
            );
        }

        private function is_block_theme()
        {
            return function_exists( 'wp_is_block_theme' ) && wp_is_block_theme();
        }

        private function messages_page_id()
        {
            $chat_page = (string) Better_Messages()->settings['chatPage'];

            if ( ! is_numeric( $chat_page ) || (int) $chat_page <= 0 || get_post_status( (int) $chat_page ) !== 'publish' ) {
                return 0;
            }

            return (int) $chat_page;
        }

        public function menus()
        {
            $page_id = $this->messages_page_id();
            $menus   = array();

            if ( $this->is_block_theme() ) {
                $navigations = get_posts( array(
                    'post_type'      => 'wp_navigation',
                    'post_status'    => 'publish',
                    'posts_per_page' => 20,
                    'orderby'        => 'date',
                    'order'          => 'ASC',
                ) );

                foreach ( $navigations as $navigation ) {
                    $menus[] = array(
                        'id'      => 'navigation:' . $navigation->ID,
                        'name'    => $navigation->post_title !== '' ? $navigation->post_title : sprintf( _x( 'Navigation %d', 'Get started', 'bp-better-messages' ), $navigation->ID ),
                        'hasPage' => $page_id > 0 && $this->blocks_have_page( parse_blocks( $navigation->post_content ), $page_id ),
                    );
                }
            } else {
                foreach ( wp_get_nav_menus() as $menu ) {
                    $menus[] = array(
                        'id'      => 'menu:' . $menu->term_id,
                        'name'    => $menu->name,
                        'hasPage' => $page_id > 0 && $this->menu_has_page( $menu->term_id, $page_id ),
                    );
                }
            }

            return $menus;
        }

        private function blocks_have_page( $blocks, $page_id )
        {
            foreach ( $blocks as $block ) {
                if ( $block['blockName'] === 'core/page-list' ) {
                    return true;
                }

                if ( in_array( $block['blockName'], array( 'core/navigation-link', 'core/navigation-submenu' ), true ) && isset( $block['attrs']['id'] ) && (int) $block['attrs']['id'] === $page_id ) {
                    return true;
                }

                if ( ! empty( $block['innerBlocks'] ) && $this->blocks_have_page( $block['innerBlocks'], $page_id ) ) {
                    return true;
                }
            }

            return false;
        }

        private function menu_has_page( $menu_id, $page_id )
        {
            $items = wp_get_nav_menu_items( $menu_id );

            if ( ! is_array( $items ) ) {
                return false;
            }

            foreach ( $items as $item ) {
                if ( $item->object === 'page' && (int) $item->object_id === $page_id ) {
                    return true;
                }
            }

            return false;
        }

        public function rest_menus( WP_REST_Request $request )
        {
            return rest_ensure_response( array( 'menus' => $this->menus() ) );
        }

        public function rest_add_to_menu( WP_REST_Request $request )
        {
            $page_id = $this->messages_page_id();

            if ( $page_id === 0 ) {
                return new WP_Error( 'no_messages_page', _x( 'Create the Messages page first.', 'Get started', 'bp-better-messages' ), array( 'status' => 400 ) );
            }

            $parts = explode( ':', (string) $request->get_param( 'menu' ), 2 );
            $type  = $parts[0];
            $id    = isset( $parts[1] ) ? (int) $parts[1] : 0;
            $title = get_the_title( $page_id );

            if ( $type === 'menu' && $id > 0 && is_nav_menu( $id ) ) {
                if ( ! $this->menu_has_page( $id, $page_id ) ) {
                    $item_id = wp_update_nav_menu_item( $id, 0, array(
                        'menu-item-title'     => $title,
                        'menu-item-object-id' => $page_id,
                        'menu-item-object'    => 'page',
                        'menu-item-type'      => 'post_type',
                        'menu-item-status'    => 'publish',
                    ) );

                    if ( is_wp_error( $item_id ) ) {
                        return $item_id;
                    }
                }
            } else if ( $type === 'navigation' && $id > 0 && get_post_type( $id ) === 'wp_navigation' ) {
                $navigation = get_post( $id );

                if ( ! $this->blocks_have_page( parse_blocks( $navigation->post_content ), $page_id ) ) {
                    $link = serialize_block( array(
                        'blockName'    => 'core/navigation-link',
                        'attrs'        => array(
                            'label' => $title,
                            'type'  => 'page',
                            'id'    => $page_id,
                            'url'   => get_permalink( $page_id ),
                            'kind'  => 'post-type',
                        ),
                        'innerBlocks'  => array(),
                        'innerHTML'    => '',
                        'innerContent' => array(),
                    ) );

                    $updated = wp_update_post( wp_slash( array(
                        'ID'           => $id,
                        'post_content' => rtrim( $navigation->post_content ) . "\n" . $link,
                    ) ), true );

                    if ( is_wp_error( $updated ) ) {
                        return $updated;
                    }
                }
            } else {
                return new WP_Error( 'invalid_menu', _x( 'That menu no longer exists.', 'Get started', 'bp-better-messages' ), array( 'status' => 400 ) );
            }

            return rest_ensure_response( array( 'menus' => $this->menus() ) );
        }

        public function rest_create_chat_room( WP_REST_Request $request )
        {
            $title  = trim( sanitize_text_field( (string) $request->get_param( 'title' ) ) );
            $guests = (bool) $request->get_param( 'guests' );

            if ( $title === '' ) {
                return new WP_Error( 'missing_title', _x( 'Give the chat room a name.', 'Get started', 'bp-better-messages' ), array( 'status' => 400 ) );
            }

            $roles = array_keys( wp_roles()->get_names() );

            if ( $guests ) {
                $roles[] = 'bm-guest';
            }

            $room_id = Better_Messages()->chats->create_chat_room( $title, array(
                'can_join'     => $roles,
                'can_reply'    => $roles,
                'allow_guests' => $guests ? '1' : '0',
            ) );

            if ( is_wp_error( $room_id ) ) {
                return $room_id;
            }

            if ( function_exists( 'use_block_editor_for_post_type' ) && use_block_editor_for_post_type( 'page' ) ) {
                $content = '<!-- wp:better-messages/chat-room {"chatId":' . (int) $room_id . '} /-->';
            } else {
                $content = '[better_messages_chat_room id="' . (int) $room_id . '"]';
            }

            $page_id = wp_insert_post( array(
                'post_title'   => $title,
                'post_content' => $content,
                'post_status'  => 'publish',
                'post_type'    => 'page',
            ), true );

            if ( is_wp_error( $page_id ) ) {
                return $page_id;
            }

            if ( $guests && Better_Messages()->settings['guestChat'] !== '1' ) {
                $stored = Better_Messages_Options::instance()->get_stored_settings();
                $stored['guestChat'] = '1';
                Better_Messages_Options::instance()->update_settings( $stored );
            }

            return rest_ensure_response( array(
                'roomId'   => (int) $room_id,
                'pageId'   => (int) $page_id,
                'room'     => $this->chat_room_item( (int) $room_id, (int) $page_id ),
                'settings' => Better_Messages_Options::instance()->settings,
            ) );
        }

        public function rest_finish( WP_REST_Request $request )
        {
            update_option( self::OPTION, 'finished', false );

            return rest_ensure_response( array( 'success' => true ) );
        }
    }

    function Better_Messages_Get_Started()
    {
        return Better_Messages_Get_Started::instance();
    }

endif;
