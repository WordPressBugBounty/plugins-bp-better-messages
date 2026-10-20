<?php
defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'better_messages_is_websocket_build' ) ) {
    function better_messages_is_websocket_build()
    {
        static $is_websocket = null;

        if ( null === $is_websocket ) {
            $is_websocket = file_exists( dirname( __DIR__ ) . '/websocket.php' );
        }

        return $is_websocket;
    }
}

require_once dirname( __DIR__, 2 ) . '/vendor/sdk/start.php';
require_once __DIR__ . '/usage.php';

wordplus_sdk_register( array(
    'slug'         => 'better-messages',
    'file'         => dirname( __DIR__, 2 ) . '/bp-better-messages.php',
    'version'      => (string) get_class_vars( 'Better_Messages' )['version'],
    'name'         => 'Better Messages',
    'icon'         => plugins_url( 'assets/images/icon.svg', dirname( __DIR__, 2 ) . '/bp-better-messages.php' ),
    'licence'      => 'cloud',
    'old_option'   => 'better_messages_license',
    'beta_option'  => 'better_messages_beta_updates',
    'paid_build'   => better_messages_is_websocket_build(),
    'paid_folder'  => 'bp-better-messages-websocket',
    'addons'       => array( 'voice-messages' => 'bp-better-messages-voice-messages/bp-better-messages-voice-messages.php' ),
    'equivalent_plans' => array( 'websocket' => 'cloud' ),
    'menu'         => array(
        'parent' => 'better-messages',
        'pages'  => array( 'account' => 'better-messages-account', 'pricing' => 'better-messages-pricing', 'contact' => 'better-messages-contact' ),
    ),
    'rest'         => array( 'namespace' => 'better-messages/v1', 'base' => 'admin/license', 'install' => 'better-messages/v1/wordplus/install' ),
    'force_key'    => 'BP_BETTER_MESSAGES_FORCE_LICENSE_KEY',
    'force_domain' => 'BP_BETTER_MESSAGES_FORCE_DOMAIN',
    'freemius'     => array( 'product' => '1557', 'slug' => 'bp-better-messages', 'plans' => array( '12309' => 'self_hosted', '*' => 'websocket' ) ),
    'support'      => array( 'better-messages' => 'Better Messages', 'voice-messages' => 'Voice and Video Messages' ),
    'urls'         => array(
        'homepage' => 'https://www.better-messages.com/',
        'docs'     => 'https://www.better-messages.com/docs/',
    ),
    'words'        => 'better_messages_licensing_words',
    'site_info'    => 'better_messages_licensing_site_info',
    'usage'        => 'better_messages_usage_data',
    'assistant'    => array(
        'name'     => 'Better Messages Assistant',
        'context'  => 'better_messages_assistant_context',
        'examples' => 'better_messages_assistant_examples',
        'renderer' => 'better_messages_assistant_renderer',
        'links'    => array(
            'setting' => 'admin.php?page=bp-better-messages#{route}?scrollTo={key}',
            'stage'   => 'admin.php?page=bp-better-messages#{route}?scrollTo={key}&stage={stage}',
        ),
    ),
    'trial'        => array(
        'days'    => 3,
        'card'    => true,
        'ready'   => 'better_messages_trial_ready',
        'preview' => 'better_messages_trial_preview',
    ),
) );

function better_messages_trial_preview()
{
    return defined( 'BM_DEV' ) && defined( 'BM_TRIAL_PREVIEW' ) && BM_TRIAL_PREVIEW;
}

function better_messages_trial_ready()
{
    global $wpdb;

    $table = bm_get_table( 'messages' );

    return null !== $wpdb->get_var( "SELECT 1 FROM `{$table}` `first` INNER JOIN `{$table}` `second` ON `second`.`thread_id` = `first`.`thread_id` AND `second`.`sender_id` <> `first`.`sender_id` AND `second`.`sender_id` <> 0 WHERE `first`.`sender_id` <> 0 LIMIT 1" );
}

function better_messages_licensing_words()
{
    return array(
        'paid_title'         => _x( 'Better Messages WebSocket', 'Licensing (WP Admin)', 'bp-better-messages' ),
        'paid_pitch'         => _x( 'The WebSocket version adds realtime messaging, calls, push notifications, your own mobile app and more.', 'Licensing (WP Admin)', 'bp-better-messages' ),
        'paid_ready'         => _x( 'Your licence is ready. Download the WebSocket version from your account, then upload it under Plugins → Add New. It replaces this free version and keeps all your settings and messages.', 'Licensing (WP Admin)', 'bp-better-messages' ),
        'paid_installed'     => _x( 'WordPlus Cloud installed the WebSocket version and switched it on. Your licence is active on this site.', 'Licensing (WP Admin)', 'bp-better-messages' ),
        'paid_saved'         => _x( 'Your licence key is saved on this site. Download the WebSocket version and upload it under Plugins → Add New → Upload Plugin. It replaces this free version and keeps your settings and messages.', 'Licensing (WP Admin)', 'bp-better-messages' ),
        'paid_download'      => _x( 'Download the WebSocket version', 'Licensing (WP Admin)', 'bp-better-messages' ),
        'paid_download_note' => _x( 'The download link works for an hour. After that, the WebSocket version is in your WordPlus account.', 'Licensing (WP Admin)', 'bp-better-messages' ),
        'continue_free'      => _x( 'Use the free version for now', 'Licensing (WP Admin)', 'bp-better-messages' ),
        'plans'              => array(
            'websocket'   => _x( 'WebSocket', 'Licensing (WP Admin)', 'bp-better-messages' ),
            'self_hosted' => _x( 'Self-Hosted', 'Licensing (WP Admin)', 'bp-better-messages' ),
            'cloud'       => _x( 'WordPlus Cloud for WordPress', 'Licensing (WP Admin)', 'bp-better-messages' ),
        ),
        'plan_titles'        => array(
            'cloud' => _x( 'WebSocket', 'Licensing (WP Admin)', 'bp-better-messages' ),
        ),
    );
}

function better_messages_licensing_site_info()
{
    $voice = Better_Messages_License_Admin::instance()->voice_addon();

    return array(
        'Better Messages'          => get_class_vars( 'Better_Messages' )['version'] . ', ' . ( better_messages_is_websocket_build() ? 'WebSocket build' : 'free build' ) . ( function_exists( 'Better_Messages' ) && isset( Better_Messages()->settings['mechanism'] ) ? ', ' . (string) Better_Messages()->settings['mechanism'] : '' ),
        'Voice and Video Messages' => $voice['state'] . ( '' !== $voice['version'] ? ' ' . $voice['version'] : '' ),
    );
}

if ( ! function_exists( 'better_messages_wordplus_sdk_server' ) ) {
    function better_messages_wordplus_sdk_server( $server )
    {
        return apply_filters( 'better_messages_license_server', $server );
    }
}

add_filter( 'wordplus_sdk_server', 'better_messages_wordplus_sdk_server' );

require_once __DIR__ . '/license.php';

require_once __DIR__ . '/admin.php';
Better_Messages_License_Admin::instance();

require_once __DIR__ . '/legacy-voice-addon.php';
