<?php

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'better_messages_assistant_context' ) ) {

    function better_messages_assistant_allowed_settings( $settings )
    {
        $allowed = array();

        foreach ( (array) $settings as $key => $value ) {
            $key = (string) $key;

            if ( preg_match( '/(key|secret|token|password|passwd|pass|credential|salt)$/i', $key ) || preg_match( '/(api_?key|private_?key|licen[cs]e)/i', $key ) ) {
                continue;
            }

            if ( ! is_scalar( $value ) && ! is_array( $value ) && null !== $value ) {
                continue;
            }

            if ( strlen( (string) wp_json_encode( $value ) ) > 300 ) {
                continue;
            }

            $allowed[ $key ] = $value;
        }

        return $allowed;
    }

    function better_messages_assistant_context()
    {
        return array(
            'settings' => better_messages_assistant_allowed_settings( Better_Messages()->settings ),
            'defaults' => better_messages_assistant_allowed_settings( Better_Messages_Options::instance()->default_settings() ),
        );
    }

    function better_messages_assistant_examples()
    {
        $examples = array();

        if ( class_exists( 'BuddyPress' ) ) {
            $examples[] = _x( 'How do members message each other from their BuddyPress profiles?', 'Assistant example', 'bp-better-messages' );
        }
        if ( class_exists( 'PeepSo' ) ) {
            $examples[] = _x( 'How do I turn on group chats for PeepSo groups?', 'Assistant example', 'bp-better-messages' );
        }
        if ( class_exists( 'WeDevs_Dokan' ) || class_exists( 'WooCommerce' ) ) {
            $examples[] = _x( 'How can buyers contact a seller from a product page?', 'Assistant example', 'bp-better-messages' );
        }
        if ( defined( 'FLUENT_COMMUNITY_PLUGIN_VERSION' ) ) {
            $examples[] = _x( 'How do I add chats to FluentCommunity spaces?', 'Assistant example', 'bp-better-messages' );
        }

        $examples[] = _x( 'How do I add a chat room to a page?', 'Assistant example', 'bp-better-messages' );
        $examples[] = _x( 'How can members get email notifications about new messages?', 'Assistant example', 'bp-better-messages' );

        if ( better_messages_is_websocket_build() ) {
            $examples[] = _x( 'How do I turn on video calls?', 'Assistant example', 'bp-better-messages' );
        } else {
            $examples[] = _x( 'What does the WebSocket version add?', 'Assistant example', 'bp-better-messages' );
        }

        $examples[] = _x( 'How do I change the messenger\'s colours?', 'Assistant example', 'bp-better-messages' );

        return array_slice( array_values( array_unique( $examples ) ), 0, 4 );
    }

    function better_messages_assistant_link()
    {
        $sdk = function_exists( 'wordplus_sdk' ) ? wordplus_sdk( 'better-messages' ) : null;

        if ( ! $sdk || ! method_exists( $sdk, 'admin' ) ) {
            return null;
        }

        $admin = $sdk->admin();

        return $admin && method_exists( $admin, 'assistant' ) ? $admin->assistant()->link() : null;
    }

    function better_messages_assistant_renderer()
    {
        Better_Messages_Assistant::instance()->on_contact_page();
    }
}

if ( ! class_exists( 'Better_Messages_Assistant' ) ) {

    class Better_Messages_Assistant
    {
        const THREAD_ID = 999999901;

        const BOT_ID = -999999901;

        public $on_page = false;

        public static function instance()
        {
            static $instance = null;

            if ( null === $instance ) {
                $instance = new self();
            }

            return $instance;
        }

        public function on_contact_page()
        {
            $this->on_page = true;
            add_filter( 'bp_better_messages_script_variable', array( $this, 'script_variables' ), 999 );
            add_filter( 'bp_better_messages_script_variables', array( $this, 'without_media' ), 999 );
            add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ), 20 );
            add_filter( 'admin_body_class', array( $this, 'light_body' ) );
        }

        public function light_body( $classes )
        {
            return $classes . ' bm-messages-light';
        }

        public function enqueue()
        {
            if ( ! wp_script_is( 'better-messages-admin', 'enqueued' ) ) {
                return;
            }

            $bm = Better_Messages();
            $bm->enqueue_js();
            $bm->enqueue_css();

            $layout = '<div class="bm-single-thread-wrap" style="height: 100%" data-thread-id="' . self::THREAD_ID . '" data-hide-header="1" data-message-menu="0">'
                . $bm->functions->container_placeholder()
                . '</div>';

            $data = array(
                'thread' => self::THREAD_ID,
                'bot'    => $this->bot(),
                'layout' => $layout,
            );

            wp_add_inline_script( 'better-messages-admin', 'window.Better_Messages_Assistant = ' . wp_json_encode( $data ) . ';', 'before' );
        }

        public function bot()
        {
            return array(
                'user_id' => self::BOT_ID,
                'name'    => 'Better Messages Assistant',
                'avatar'  => plugins_url( 'assets/images/icon.png', dirname( __DIR__ ) . '/bp-better-messages.php' ),
            );
        }

        public function script_variables( $vars )
        {
            $original       = isset( $vars['blogId'] ) ? (string) $vars['blogId'] : '';
            $vars['blogId'] = $original . '_bmassistant';

            $vars['socket_server']   = '';
            $vars['realtime']        = '0';
            $vars['assistantMode']   = '1';
            $vars['assistantThread'] = (string) self::THREAD_ID;
            $vars['assistantBot']    = $this->bot();

            unset( $vars['PNworker'], $vars['PNsdk'], $vars['vapidPublicKey'] );

            unset( $vars['attachments'], $vars['location'] );

            foreach ( array( 'replies', 'favorite', 'messagesStatus', 'mobileFullScreen', 'miniChats', 'miniMessages', 'miniFriends', 'miniGroups', 'miniCourses', 'miniAIBots', 'miniChatRooms', 'miniUsers' ) as $key ) {
                $vars[ $key ] = '0';
            }

            return $vars;
        }

        public function without_media( $vars )
        {
            $vars['reactions']           = array();
            $vars['gifs']                = '0';
            $vars['gifsProvider']        = '';
            $vars['gifsProviderName']    = '';
            $vars['stickers']            = '0';
            $vars['stickersProvider']    = 'disabled';
            $vars['allowedStickerPacks'] = array();
            $vars['stickerSuggestions']  = '0';
            unset( $vars['stickerManifestUrl'] );

            return $vars;
        }
    }
}
