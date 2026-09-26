<?php
defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/design-schema.php';
require_once __DIR__ . '/design-presets.php';

if ( ! class_exists( 'Better_Messages_Design' ) ) :

class Better_Messages_Design {

    const OPTION_OVERRIDES         = 'bm_design_overrides';
    const OPTION_DENSITY           = 'bm_design_density';
    const OPTION_DARK_MODE         = 'bm_design_dark_mode';
    const OPTION_CUSTOM_CSS        = 'bm_design_custom_css';
    const OPTION_DESIGN_OPTIONS    = 'bm_design_options';
    const OPTION_DESIGN_PRESET     = 'bm_design_preset';
    const OPTION_DESIGN_PRESET_DARK = 'bm_design_preset_dark';
    const OPTION_MIGRATION_VERSION = 'bm_design_migration_version';

    private $fill_window_migrated = false;

    private static $renamed_options = array(
        'bm_v3_overrides'  => self::OPTION_OVERRIDES,
        'bm_v3_density'    => self::OPTION_DENSITY,
        'bm_v3_dark_mode'  => self::OPTION_DARK_MODE,
        'bm_v3_custom_css' => self::OPTION_CUSTOM_CSS,
        'bm_v3_preset'     => self::OPTION_DESIGN_PRESET,
    );

    private static $dropped_options = array(
        'bm_design_version',
    );

    const MIGRATION_TARGET_VERSION = 14;

    const DEFAULT_DARK_MODE      = 'never';



    public static function instance() {
        static $instance = null;
        if ( null === $instance ) {
            $instance = new self();
        }
        return $instance;
    }

    public function __construct() {
        add_filter( 'body_class', array( $this, 'body_class' ) );
        add_filter( 'admin_body_class', array( $this, 'admin_body_class' ) );
        add_action( 'rest_api_init', array( $this, 'rest_api_init' ) );
        add_action( 'wp_footer', array( $this, 'render_theme_armor' ), 1 );
        add_action( 'admin_init', array( $this, 'maybe_run_customizer_migration' ) );

        foreach ( array( self::OPTION_OVERRIDES, self::OPTION_DESIGN_OPTIONS ) as $option ) {
            add_action( 'add_option_' . $option, array( $this, 'flush_effective_vars' ) );
            add_action( 'update_option_' . $option, array( $this, 'flush_effective_vars' ) );
            add_action( 'delete_option_' . $option, array( $this, 'flush_effective_vars' ) );
        }
    }

    public function render_theme_armor() {
        if ( ! wp_style_is( 'better-messages', 'enqueued' ) && ! wp_style_is( 'better-messages', 'done' ) ) {
            return;
        }
        $scopes = ':where(.bm-wrap-main,.bm-wrap,.bm-chat-wrap,.bm-wrap-group,.bm-single-thread-wrap,.bm-mini-chat,.bm-modal-window,.bm-context-menu,.bm-emoji-popover,.bm-rail-tooltip,.bm-camera,.bm-lightbox,.bm-mobile,.bm-scope)';
        echo '<style id="bm-theme-armor">'
            . $scopes . ' button:hover,'
            . $scopes . ' button:focus,'
            . $scopes . ' button:active{background-color:transparent;color:inherit;text-decoration:none}'
            . '</style>';
    }

    public function maybe_run_customizer_migration() {
        $current = (int) get_option( self::OPTION_MIGRATION_VERSION, 0 );
        if ( $current >= self::MIGRATION_TARGET_VERSION ) {
            return;
        }
        if ( $current < 5 ) {
            $this->migrate_renamed_options();
        }
        if ( $current < 1 ) {
            $this->run_customizer_migration();
        }
        if ( $current < 2 ) {
            $this->migrate_dark_mode_default();
        }
        if ( $current < 3 ) {
            $this->migrate_messages_layout();
        }
        if ( $current < 4 ) {
            $this->migrate_bubble_fill();
        }
        if ( $current < 6 ) {
            $this->migrate_avatar_shape();
        }
        if ( $current < 7 ) {
            $this->migrate_list_background_key();
        }
        if ( $current < 8 ) {
            $this->migrate_density_to_token();
        }
        if ( $current < 9 ) {
            $this->migrate_size_settings_to_tokens();
        }
        if ( $current < 10 ) {
            $this->migrate_fill_window();
        }
        if ( $current < 11 ) {
            delete_option( self::OPTION_CUSTOM_CSS );
        }
        if ( $current < 13 ) {
            $this->migrate_fill_window_off();
        }
        if ( $current < 14 ) {
            $this->migrate_message_buttons();
        }
        update_option( self::OPTION_MIGRATION_VERSION, self::MIGRATION_TARGET_VERSION );
    }

    public function migrate_message_buttons() {
        $options = get_option( self::OPTION_DESIGN_OPTIONS, array() );
        if ( ! is_array( $options ) ) {
            return;
        }
        $map = array(
            'tapMessageActions'       => 'mobileMessageButtons',
            'miniChatsMessageActions' => 'miniChatsMessageButtons',
        );
        $changed = false;
        foreach ( $map as $old => $new ) {
            if ( ! array_key_exists( $old, $options ) ) {
                continue;
            }
            if ( in_array( $options[ $old ], array( false, 0, '0', 'false', 'off' ), true ) && ! array_key_exists( $new, $options ) ) {
                $options[ $new ] = 'none';
            }
            unset( $options[ $old ] );
            $changed = true;
        }
        if ( $changed ) {
            update_option( self::OPTION_DESIGN_OPTIONS, $options );
        }
    }

    public function migrate_list_background_key() {
        $overrides = get_option( self::OPTION_OVERRIDES, array() );
        if ( ! is_array( $overrides ) ) {
            return;
        }
        $changed = false;
        foreach ( array( 'light', 'dark' ) as $scope ) {
            if ( empty( $overrides[ $scope ] ) || ! is_array( $overrides[ $scope ] ) || ! array_key_exists( '--bm-color-bg-secondary', $overrides[ $scope ] ) ) {
                continue;
            }
            if ( ! isset( $overrides[ $scope ]['--bm-color-inbox-bg'] ) ) {
                $overrides[ $scope ]['--bm-color-inbox-bg'] = $overrides[ $scope ]['--bm-color-bg-secondary'];
            }
            unset( $overrides[ $scope ]['--bm-color-bg-secondary'] );
            $changed = true;
        }
        if ( $changed ) {
            update_option( self::OPTION_OVERRIDES, $overrides );
        }
    }

    public function migrate_renamed_options() {
        foreach ( self::$renamed_options as $old => $new ) {
            $value = get_option( $old, null );
            if ( null !== $value ) {
                add_option( $new, $value );
            }
            delete_option( $old );
        }

        foreach ( self::$dropped_options as $dead ) {
            delete_option( $dead );
        }
    }

    public function migrate_messages_layout() {
        $legacy_settings = get_option( 'bp-better-chat-settings', null );
        if ( ! is_array( $legacy_settings ) || ! isset( $legacy_settings['modernLayout'] ) ) {
            return;
        }

        $map = array(
            'left'    => 'reversed',
            'right'   => 'default',
            'leftAll' => 'single',
        );

        $legacy = $legacy_settings['modernLayout'];
        if ( ! isset( $map[ $legacy ] ) ) {
            return;
        }

        $options = $this->get_design_options();
        $options['messagesLayout'] = $map[ $legacy ];
        update_option( self::OPTION_DESIGN_OPTIONS, $this->sanitize_design_options( $options ) );
    }

    public function migrate_bubble_fill() {
        $legacy_settings = get_option( 'bp-better-chat-settings', null );
        if ( ! is_array( $legacy_settings ) || ! isset( $legacy_settings['template'] ) ) {
            return;
        }

        if ( 'standard' !== $legacy_settings['template'] ) {
            return;
        }

        $options = $this->get_design_options();
        $options['bubbleFill']     = 'none';
        $options['messagesLayout'] = 'single';
        update_option( self::OPTION_DESIGN_OPTIONS, $this->sanitize_design_options( $options ) );
    }

    public function migrate_avatar_shape() {
        $legacy = get_theme_mod( 'bm-avatar-radius', null );
        if ( null === $legacy || '' === $legacy ) {
            return;
        }

        $overrides = $this->get_user_overrides();
        if ( isset( $overrides['light']['--bm-radius-avatar'] ) ) {
            return;
        }

        $px      = max( 0, (int) $legacy );
        $percent = $px >= 18 ? 50 : (int) min( 50, round( $px / 36 * 100 ) );

        if ( ! isset( $overrides['light'] ) || ! is_array( $overrides['light'] ) ) {
            $overrides['light'] = array();
        }
        if ( ! isset( $overrides['dark'] ) || ! is_array( $overrides['dark'] ) ) {
            $overrides['dark'] = array();
        }
        $overrides['light']['--bm-radius-avatar'] = $percent . '%';
        update_option( self::OPTION_OVERRIDES, $overrides );
    }

    public function migrate_density_to_token() {
        $density = get_option( self::OPTION_DENSITY, null );
        if ( null === $density ) {
            return;
        }
        $map = array( 'compact' => '0.85', 'comfortable' => '1.15' );
        if ( isset( $map[ $density ] ) ) {
            $overrides = get_option( self::OPTION_OVERRIDES, array() );
            if ( ! is_array( $overrides ) ) {
                $overrides = array();
            }
            if ( empty( $overrides['light'] ) || ! is_array( $overrides['light'] ) ) {
                $overrides['light'] = array();
            }
            if ( ! isset( $overrides['light']['--bm-density'] ) ) {
                $overrides['light']['--bm-density'] = $map[ $density ];
                update_option( self::OPTION_OVERRIDES, $overrides );
            }
        }
        delete_option( self::OPTION_DENSITY );
    }

    public function migrate_size_settings_to_tokens() {
        $settings = Better_Messages()->settings;
        $map = array(
            'fixedHeaderHeight'         => array( '--bm-viewport-offset', 0 ),
            'messagesMinHeight'         => array( '--bm-min-height', 450 ),
            'messagesHeight'            => array( '--bm-max-height', 650 ),
            'mobilePopupLocationBottom' => array( '--bm-mobile-button-bottom', 20 ),
        );
        $overrides = get_option( self::OPTION_OVERRIDES, array() );
        if ( ! is_array( $overrides ) ) {
            $overrides = array();
        }
        if ( empty( $overrides['light'] ) || ! is_array( $overrides['light'] ) ) {
            $overrides['light'] = array();
        }
        $changed = false;
        foreach ( $map as $key => $spec ) {
            if ( ! isset( $settings[ $key ] ) || isset( $overrides['light'][ $spec[0] ] ) ) {
                continue;
            }
            $value = (int) $settings[ $key ];
            if ( $value === $spec[1] || ( $value <= 0 && 'fixedHeaderHeight' !== $key ) ) {
                continue;
            }
            $overrides['light'][ $spec[0] ] = $value . 'px';
            $changed = true;
        }
        if ( $changed ) {
            update_option( self::OPTION_OVERRIDES, $overrides );
        }
    }

    public function migrate_fill_window() {
        $overrides = get_option( self::OPTION_OVERRIDES, array() );
        if ( ! is_array( $overrides ) ) {
            $overrides = array();
        }
        $stored = isset( $overrides['light']['--bm-max-height'] ) ? (int) preg_replace( '/[^0-9]/', '', (string) $overrides['light']['--bm-max-height'] ) : 0;
        if ( $stored < 2000 ) {
            return;
        }
        $options = get_option( self::OPTION_DESIGN_OPTIONS, array() );
        if ( ! is_array( $options ) ) {
            $options = array();
        }
        $options['messengerFill'] = true;
        update_option( self::OPTION_DESIGN_OPTIONS, $this->sanitize_design_options( array_merge( $this->get_design_options(), $options ) ) );
        $this->fill_window_migrated = true;
        unset( $overrides['light']['--bm-max-height'] );
        if ( isset( $overrides['dark']['--bm-max-height'] ) ) {
            unset( $overrides['dark']['--bm-max-height'] );
        }
        update_option( self::OPTION_OVERRIDES, $overrides );
    }

    public function migrate_fill_window_off() {
        if ( $this->fill_window_migrated ) {
            return;
        }
        $options = get_option( self::OPTION_DESIGN_OPTIONS, array() );
        if ( ! is_array( $options ) || ! array_key_exists( 'messengerFill', $options ) ) {
            return;
        }
        unset( $options['messengerFill'] );
        update_option( self::OPTION_DESIGN_OPTIONS, $options );
    }

    public function migrate_dark_mode_default() {
        if ( null !== get_option( self::OPTION_DARK_MODE, null ) ) {
            return;
        }
        global $wpdb;
        $threads_table = bm_get_table( 'threads' );
        $has_data = (bool) $wpdb->get_var( "SELECT `id` FROM `{$threads_table}` LIMIT 1" );
        if ( $has_data ) {
            update_option( self::OPTION_DARK_MODE, 'never' );
        }
    }

    public function run_customizer_migration() {
        $color_map_light = array(
            'main-bm-color'                    => '--bm-color-accent',
            'bm-primary-bg'                    => '--bm-color-bg',
            'bm-secondary-bg'                  => '--bm-color-inbox-bg',
            'bm-hover-bg'                      => '--bm-color-bg-hover',
            'bm-primary-border'                => '--bm-color-border',
            'bm-secondary-border'              => '--bm-color-border-subtle',
            'bm-text-color'                    => '--bm-color-text-primary',
            'bm-modern-left-side-nickname'     => '--bm-color-bubble-other-nickname',
            'bm-modern-left-side-bg'           => '--bm-color-bubble-other-bg',
            'bm-modern-left-side-color'        => '--bm-color-bubble-other-text',
            'bm-modern-right-side-nickname'    => '--bm-color-bubble-self-nickname',
            'bm-modern-right-side-bg'          => '--bm-color-bubble-self-bg',
            'bm-modern-right-side-color'       => '--bm-color-bubble-self-text',
            'bm-sticky-date-bg'                => '--bm-color-sticky-date-bg',
            'bm-sticky-date-color'             => '--bm-color-sticky-date-text',
            'bm-tooltip-bg'                    => '--bm-color-tooltip-bg',
            'bm-tooltip-color'                 => '--bm-color-tooltip-text',
        );

        $color_map_dark = array(
            'main-bm-color-dark'                  => '--bm-color-accent',
            'bm-primary-bg-dark'                  => '--bm-color-bg',
            'bm-secondary-bg-dark'                => '--bm-color-inbox-bg',
            'bm-hover-bg-dark'                    => '--bm-color-bg-hover',
            'bm-primary-border-dark'              => '--bm-color-border',
            'bm-secondary-border-dark'            => '--bm-color-border-subtle',
            'bm-text-color-dark'                  => '--bm-color-text-primary',
            'bm-modern-left-side-nickname-dark'   => '--bm-color-bubble-other-nickname',
            'bm-modern-left-side-bg-dark'         => '--bm-color-bubble-other-bg',
            'bm-modern-left-side-color-dark'      => '--bm-color-bubble-other-text',
            'bm-modern-right-side-nickname-dark'  => '--bm-color-bubble-self-nickname',
            'bm-modern-right-side-bg-dark'        => '--bm-color-bubble-self-bg',
            'bm-modern-right-side-color-dark'     => '--bm-color-bubble-self-text',
            'bm-sticky-date-bg-dark'              => '--bm-color-sticky-date-bg',
            'bm-sticky-date-color-dark'           => '--bm-color-sticky-date-text',
            'bm-tooltip-bg-dark'                  => '--bm-color-tooltip-bg',
            'bm-tooltip-color-dark'               => '--bm-color-tooltip-text',
        );

        $radius_map_px = array(
            'bm-border-radius' => '--bm-radius-button',
            'bm-modern-radius' => '--bm-radius-bubble',
            'bm-date-radius'   => '--bm-radius-date-pill',
        );

        $mini_widget_map_px = array(
            'bm-widgets-border-radius'  => '--bm-radius-mini-widget',
            'bm-widgets-button-radius'  => '--bm-radius-mini-chat-button',
            'bm-mini-widgets-width'     => '--bm-mini-widget-width',
            'bm-mini-widgets-height'    => '--bm-mini-widget-height',
            'bm-mini-widgets-indent'    => '--bm-mini-widget-offset-x',
            'bm-mini-widgets-bottom'    => '--bm-mini-widget-offset-y',
            'bm-mini-chats-width'       => '--bm-mini-chat-width',
            'bm-mini-chats-height'      => '--bm-mini-chat-height',
            'bm-bubble-size'            => '--bm-bubble-size',
        );

        $mini_widget_map_pct = array(
            'bm-bubble-radius' => '--bm-radius-bubble-button',
        );

        $existing_overrides = get_option( self::OPTION_OVERRIDES, array() );
        if ( ! is_array( $existing_overrides ) ) {
            $existing_overrides = array();
        }
        $light = isset( $existing_overrides['light'] ) && is_array( $existing_overrides['light'] ) ? $existing_overrides['light'] : array();
        $dark  = isset( $existing_overrides['dark'] )  && is_array( $existing_overrides['dark'] )  ? $existing_overrides['dark']  : array();

        foreach ( $color_map_light as $mod => $token ) {
            $value = get_theme_mod( $mod, null );
            if ( null === $value || '' === $value ) {
                continue;
            }
            if ( isset( $light[ $token ] ) ) {
                continue;
            }
            $light[ $token ] = $this->hex_to_triple( (string) $value );
        }
        foreach ( $color_map_dark as $mod => $token ) {
            $value = get_theme_mod( $mod, null );
            if ( null === $value || '' === $value ) {
                continue;
            }
            if ( isset( $dark[ $token ] ) ) {
                continue;
            }
            $dark[ $token ] = $this->hex_to_triple( (string) $value );
        }
        foreach ( $radius_map_px as $mod => $token ) {
            $value = get_theme_mod( $mod, null );
            if ( null === $value || '' === $value ) {
                continue;
            }
            if ( isset( $light[ $token ] ) ) {
                continue;
            }
            $light[ $token ] = ( (int) $value ) . 'px';
        }
        foreach ( $mini_widget_map_px as $mod => $token ) {
            $value = get_theme_mod( $mod, null );
            if ( null === $value || '' === $value ) {
                continue;
            }
            if ( isset( $light[ $token ] ) ) {
                continue;
            }
            $light[ $token ] = ( (int) $value ) . 'px';
        }
        foreach ( $mini_widget_map_pct as $mod => $token ) {
            $value = get_theme_mod( $mod, null );
            if ( null === $value || '' === $value ) {
                continue;
            }
            if ( isset( $light[ $token ] ) ) {
                continue;
            }
            $light[ $token ] = ( (int) $value ) . '%';
        }

        update_option( self::OPTION_OVERRIDES, array(
            'light' => $light,
            'dark'  => $dark,
        ) );

        $legacy_theme = get_theme_mod( 'bm-theme', null );
        if ( null !== $legacy_theme && ! get_option( self::OPTION_DARK_MODE, null ) ) {
            if ( 'dark' === $legacy_theme ) {
                update_option( self::OPTION_DARK_MODE, 'always' );
            } elseif ( 'light' === $legacy_theme ) {
                update_option( self::OPTION_DARK_MODE, 'never' );
            }
        }

        $options = $this->get_design_options();
        $options['dateEnabled']     = (bool) get_theme_mod( 'bm-date-enabled', $options['dateEnabled'] );
        $options['showAvatarGroup'] = (bool) get_theme_mod( 'bm-show-avatar-group', $options['showAvatarGroup'] );
        $options['privateSubName']  = (string) get_theme_mod( 'bm-private-sub-name', $options['privateSubName'] );
        $options['avatarsList']     = (string) get_theme_mod( 'bm-avatars-list', $options['avatarsList'] );
        $options['datePosition']    = (string) get_theme_mod( 'bm-date-position', $options['datePosition'] );
        $options['timeFormat']      = (string) get_theme_mod( 'bm-time-format', $options['timeFormat'] );
        $options['widgetsPosition'] = (string) get_theme_mod( 'bm-widgets-position', $options['widgetsPosition'] );
        update_option( self::OPTION_DESIGN_OPTIONS, $this->sanitize_design_options( $options ) );
    }

    public function get_accent_color_hex( $scope = 'light' ) {
        $overrides = $this->get_user_overrides();
        $scope_key = ( 'dark' === $scope ) ? 'dark' : 'light';
        $triple = '';
        if ( isset( $overrides[ $scope_key ]['--bm-color-accent'] ) ) {
            $triple = (string) $overrides[ $scope_key ]['--bm-color-accent'];
        } else {
            $defaults = ( 'dark' === $scope_key ) ? $this->default_design_vars_dark() : $this->default_design_vars();
            if ( isset( $defaults['--bm-color-accent'] ) ) {
                $triple = (string) $defaults['--bm-color-accent'];
            }
        }
        if ( '' === $triple ) {
            $triple = ( 'dark' === $scope_key ) ? '255, 255, 255' : '33, 117, 155';
        }
        $parts = array_map( 'intval', preg_split( '/\s*,\s*/', $triple ) );
        if ( count( $parts ) < 3 ) {
            return ( 'dark' === $scope_key ) ? '#ffffff' : '#21759b';
        }
        return sprintf( '#%02x%02x%02x', max( 0, min( 255, $parts[0] ) ), max( 0, min( 255, $parts[1] ) ), max( 0, min( 255, $parts[2] ) ) );
    }

    private function hex_to_triple( $hex ) {
        $hex = trim( (string) $hex );
        if ( '' === $hex ) {
            return '0, 0, 0';
        }
        if ( strpos( $hex, '#' ) === 0 ) {
            $hex = substr( $hex, 1 );
        }
        if ( strlen( $hex ) === 3 ) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }
        if ( strlen( $hex ) !== 6 ) {
            return '0, 0, 0';
        }
        $r = hexdec( substr( $hex, 0, 2 ) );
        $g = hexdec( substr( $hex, 2, 2 ) );
        $b = hexdec( substr( $hex, 4, 2 ) );
        return $r . ', ' . $g . ', ' . $b;
    }

    private $manifest_surface = '';

    public function is_preview_mode() {
        return '' !== $this->manifest_surface;
    }

    const PREVIEW_THREAD_ID       = 999900001;
    const PREVIEW_GROUP_THREAD_ID = 999900003;

    const SIDE_LIST_RAIL_WIDTH    = 60;
    const SIDE_LIST_MIN_COLUMN    = 450;
    const SIDE_LIST_FALLBACK_WIDTH = 320;

    public function preview_threads() {
        return array(
            'default' => self::PREVIEW_THREAD_ID,
            'group'   => self::PREVIEW_GROUP_THREAD_ID,
        );
    }

    public function preview_side_list_min_width() {
        $settings = Better_Messages()->settings;

        if ( empty( $settings['combinedView'] ) ) {
            return 0;
        }

        $compact_mode = isset( $settings['sidebarCompactMode'] ) ? (string) $settings['sidebarCompactMode'] : 'auto';

        if ( 'always_expanded' === $compact_mode ) {
            $list_width = $this->get_design_var_int( '--bm-sidebar-width' );
            if ( $list_width < 1 ) {
                $list_width = self::SIDE_LIST_FALLBACK_WIDTH;
            }
            return $list_width * 2;
        }

        $hide_below = isset( $settings['sidebarHideBreakpoint'] ) ? (int) $settings['sidebarHideBreakpoint'] : 0;

        return $hide_below > 0 ? $hide_below : self::SIDE_LIST_RAIL_WIDTH + self::SIDE_LIST_MIN_COLUMN;
    }

    public function preview_surface() {
        return '' !== $this->manifest_surface ? $this->manifest_surface : 'desktop';
    }

    public function preview_script_variables( $vars ) {
        $surface  = $this->preview_surface();
        $original = isset( $vars['blogId'] ) ? (string) $vars['blogId'] : '';
        $vars['blogId'] = $original . '_bmdesignpreview_' . $surface;

        $vars['socket_server'] = '';

        $vars['designPreview']        = '1';
        $vars['designPreviewSurface'] = $surface;
        $vars['designPreviewThread']  = (string) self::PREVIEW_THREAD_ID;
        $vars['designPreviewGroup']   = (string) self::PREVIEW_GROUP_THREAD_ID;
        $vars['designPreviewAssets']  = Better_Messages()->url . 'assets/images/preview/';
        $vars['designPreviewLinkPreviews'] = ( isset( Better_Messages()->settings['enableNiceLinks'] ) && '1' === Better_Messages()->settings['enableNiceLinks'] ) ? '1' : '0';

        if ( 'mobile' === $surface ) {
            $vars['forceMobileView'] = '1';
            $vars['mobileFullScreen'] = '1';
        }

        if ( isset( Better_Messages()->websocket ) && is_object( Better_Messages()->websocket ) && method_exists( Better_Messages()->websocket, 'get_all_statuses' ) ) {
            $vars['designPreviewUserStatuses'] = Better_Messages()->websocket->get_all_statuses();
        }

        if ( 'widgets' === $surface ) {
            $vars['miniMessages'] = '1';
            $vars['miniChats']    = '1';
            $vars['miniSync']     = '0';
        } else {
            foreach ( array( 'miniMessages', 'miniChats', 'miniFriends', 'miniGroups', 'miniCourses', 'miniAIBots', 'miniChatRooms', 'miniUsers' ) as $key ) {
                $vars[ $key ] = '0';
            }
        }

        return $vars;
    }

    public function render_preview_styles() {
        ?>
        <style id="bm-preview-styles">
            /* Transparent around the messenger: the admin stage paints its
               own ground behind the frame and draws the shadow from what
               actually renders, so it hugs the messenger's corners whatever
               radius the admin picks. */
            html, body.bm-preview-mode {
                margin: 0 !important;
                padding: 0 !important;
                background: transparent !important;
            }
            body.bm-preview-mode #wpadminbar,
            body.bm-preview-mode > header,
            body.bm-preview-mode > nav,
            body.bm-preview-mode > footer,
            body.bm-preview-mode > aside,
            body.bm-preview-mode .site-header,
            body.bm-preview-mode #masthead,
            body.bm-preview-mode #colophon,
            body.bm-preview-mode .site-footer,
            body.bm-preview-mode .wp-block-template-part,
            body.bm-preview-mode .nav-menu,
            body.bm-preview-mode .widget-area {
                display: none !important;
            }
            body.bm-preview-mode #page,
            body.bm-preview-mode #content,
            body.bm-preview-mode main,
            body.bm-preview-mode .site-main,
            body.bm-preview-mode .site-content,
            body.bm-preview-mode .entry-content,
            body.bm-preview-mode #primary {
                width: 100% !important;
                max-width: 100% !important;
                margin: 0 !important;
                padding: 0 !important;
            }
            body.bm-preview-mode .bm-wrap,
            body.bm-preview-mode .bm-mini-widgets-wrap {
                margin: 0 !important;
            }
            html.bm-preview-mode-loading body { visibility: hidden; }

            /* The widget surfaces render no messenger of their own, so the
               theme's page would otherwise show through behind the floating
               panels. Everything the preview does not own is hidden — by
               exclusion rather than by naming theme markup, because the page
               under them is whatever theme the site runs. What the widgets
               themselves put on the body — a window's title menu, a message
               menu, tooltips, modals — carries the plugin's prefix and stays. */
            body.bm-preview-surface-widgets > *:not(.bm-preview-stage):not(.bm-mini-widgets-wrap):not(.bm-mini-chats-wrap):not(script):not(style):not(#wpadminbar):not([class^="bm-"]):not([class*=" bm-"]):not([id^="bm-"]):not([id^="better-messages-"]) {
                display: none !important;
            }
        </style>
        <?php
    }

    public function render_preview_listener() {
        ?>
        <script id="bm-preview-listener">
            (function () {
                var parentOrigin = '';
                try { parentOrigin = (document.referrer || '').split('/').slice(0, 3).join('/'); } catch (e) { parentOrigin = ''; }
                // Set on <body> as well as <html>. Dark mode is emitted as
                // `body.bm-messages-dark { --token: ... }`, and a body-level
                // RULE outranks an inline style on the ROOT — so every dark
                // colour pushed onto <html> was overridden by the stylesheet
                // and editing the dark scope showed nothing. An inline style
                // on <body> beats the rule, and both scopes inherit from it.
                function applyTokens(tokens) {
                    if (!tokens || typeof tokens !== 'object') return;
                    var targets = [document.documentElement, document.body].filter(Boolean);
                    Object.keys(tokens).forEach(function (k) {
                        if (!k || k.indexOf('--bm-') !== 0) return;
                        for (var t = 0; t < targets.length; t++) {
                            targets[t].style.setProperty(k, String(tokens[k]));
                        }
                    });
                }
                function applyBodyClass(add, remove) {
                    var body = document.body;
                    if (Array.isArray(remove)) remove.forEach(function (c) { if (c) body.classList.remove(c); });
                    if (Array.isArray(add)) add.forEach(function (c) { if (c) body.classList.add(c); });
                }
                window.addEventListener('message', function (event) {
                    if (parentOrigin && event.origin !== parentOrigin && event.origin !== window.location.origin) return;
                    var data = event.data;
                    if (!data || typeof data !== 'object') return;
                    if (data.type === 'bm_design_settings_update' && data.settings && data.settings.miniWidgetsStyle) {
                        // The style also decides a class PHP printed on the
                        // wrap, so the setting alone leaves the widget half
                        // switched: React reads bubble, the CSS reads classic.
                        var wrap = document.querySelector('.bm-mini-widgets-wrap');
                        if (wrap) { wrap.classList.toggle('bm-widget-bubble', data.settings.miniWidgetsStyle === 'bubble'); }
                    }
                    if (data.type === 'bm_design_token_update') {
                        applyTokens(data.tokens);
                        // PHP prints this class from the saved bottom offset
                        // and the stylesheet rounds the lifted widgets on it.
                        // The slider moves the token live, so the class has
                        // to follow here or the corners lag a save behind.
                        var tokens = data.tokens || {};
                        var offset = tokens['--bm-mini-widget-offset-y'] !== undefined ? tokens['--bm-mini-widget-offset-y'] : tokens['--bm-mini-widgets-offset-bottom'];
                        if (offset !== undefined) {
                            var lifted = parseFloat(String(offset)) > 0;
                            var wraps = document.querySelectorAll('.bm-mini-widgets-wrap, .bm-mini-chats-wrap');
                            for (var w = 0; w < wraps.length; w++) { wraps[w].classList.toggle('bm-widget-not-at-bottom', lifted); }
                        }
                    }
                    else if (data.type === 'bm_design_body_class_update') { applyBodyClass(data.add, data.remove); }
                    else if (data.type === 'bm_design_preview_route') {
                        // The mobile surface can show the page around the
                        // messenger too — the floating button, the tap
                        // prompt — which means leaving full screen.
                        // The widgets surface has no route to follow: its
                        // docked mini chat listens for the conversation here.
                        try { document.dispatchEvent(new CustomEvent('better-messages-design-view', { detail: { view: String(data.view || ''), thread: parseInt(data.thread, 10) || 0 } })); } catch (e) {}
                        var next = data.view === 'list' ? '' : '#/conversation/' + String(parseInt(data.thread, 10) || 0);
                        var current = window.location.hash === '#' ? '' : window.location.hash;
                        if (current !== next) {
                            // replaceState, never a fragment navigation: navigating
                            // an iframe to an empty fragment scrolls the admin page
                            // to the frame's top. The messenger listens for the
                            // hashchange this dispatches.
                            try {
                                window.history.replaceState(null, '', window.location.href.replace(/#.*$/, '') + next);
                                window.dispatchEvent(new HashChangeEvent('hashchange'));
                            } catch (e) {
                                window.location.hash = next || '#/';
                            }
                        }
                    }
                    else if (data.type === 'bm_design_reload') { window.location.reload(); }
                });
                // Nothing opens a new tab from the preview either: the media
                // list and profile buttons call window.open directly.
                window.open = function () { return null; };
                // A link that leaves the document — a member's profile, a
                // media file — would replace the preview with the real page.
                // Hash links (the messenger's own routes) still work.
                document.addEventListener('click', function (event) {
                    var target = event.target && event.target.closest ? event.target.closest('a[href]') : null;
                    if (!target) return;
                    var href = target.getAttribute('href') || '';
                    if (href.charAt(0) === '#') return;
                    event.preventDefault();
                    event.stopPropagation();
                }, true);
                var readySent = false;
                function postReady() {
                    if (readySent) return;
                    readySent = true;
                    try { window.parent.postMessage({ type: 'bm_design_preview_ready' }, parentOrigin || '*'); } catch (e) {}
                }
                // As soon as the scripts have run, not once every image has
                // loaded: the parent applies the current design on ready, and
                // the messenger is already mounting by then.
                if (document.readyState !== 'loading') postReady();
                else document.addEventListener('DOMContentLoaded', postReady);
            })();
        </script>
        <?php
    }

    private function preview_stage_styles() {
        return '<style id="bm-preview-stage-styles">'
            . 'html, body { margin: 0; padding: 0; height: 100%; }'
            . 'body.bm-preview-mode { overflow: hidden; }'
            . '.bm-preview-stage { display: flex; height: 100vh; height: 100dvh; padding: 0; box-sizing: border-box; }'
            . '.bm-preview-stage > .bm-wrap-main { flex: 1 1 auto; min-width: 0; min-height: 0; }'
            . '.bm-preview-stage .bm-composer, .bm-preview-stage .bm-composer * { pointer-events: none; user-select: none; }'
            . 'body.bm-preview-surface-widgets .bm-preview-stage { display: block; }'
            . '</style>';
    }

    public function drop_admin_bar_from_capture() {
        wp_dequeue_style( 'admin-bar' );
        wp_dequeue_style( 'bp-admin-bar' );
        remove_action( 'wp_head', '_admin_bar_bump_cb' );
        remove_action( 'wp_head', 'wp_admin_bar_header' );
        remove_action( 'wp_print_styles', 'print_emoji_styles' );
    }

    private function style_markup_only( $html ) {
        if ( ! preg_match_all( '/<link\b[^>]*>|<style\b[^>]*>.*?<\/style>/is', (string) $html, $matches ) ) {
            return '';
        }
        $kept = array();
        foreach ( $matches[0] as $tag ) {
            if ( 0 === stripos( $tag, '<link' ) && ! preg_match( '/\brel\s*=\s*["\']?stylesheet\b/i', $tag ) ) {
                continue;
            }
            $kept[] = $tag;
        }
        return implode( "\n", $kept );
    }

    public function preview_surfaces() {
        return apply_filters( 'better_messages_design_preview_surfaces', array( 'desktop', 'mobile', 'widgets' ) );
    }

    public function preview_manifest( $only = null ) {
        $scripts  = new WP_Scripts();
        $styles   = new WP_Styles();
        $queued   = null;
        $surfaces = array();
        $wanted   = null === $only ? null : array_map( 'strval', (array) $only );
        foreach ( $this->preview_surfaces() as $surface ) {
            if ( null !== $wanted && ! in_array( $surface, $wanted, true ) ) {
                continue;
            }
            $surfaces[ $surface ] = $this->build_surface_document( $surface, $scripts, $styles, $queued );
        }
        return array(
            'surfaces' => $surfaces,
            'lang'     => get_bloginfo( 'language' ),
            'dir'      => is_rtl() ? 'rtl' : 'ltr',
            'charset'  => get_bloginfo( 'charset' ),
        );
    }

    private function build_surface_document( $surface, $scripts, $styles, &$queued ) {
        global $wp_scripts, $wp_styles;

        $bm            = Better_Messages();
        $saved_scripts = $wp_scripts;
        $saved_styles  = $wp_styles;
        $saved_js      = $bm->js_loaded;
        $saved_css     = $bm->css_loaded;
        $saved_vars    = $bm->script_variables;

        foreach ( array( $scripts, $styles ) as $registry ) {
            $registry->queue = array();
            $registry->done  = array();
            $registry->to_do = array();
        }
        foreach ( array( 'better-messages', 'better-messages-i18n-inline' ) as $handle ) {
            if ( isset( $scripts->registered[ $handle ] ) ) {
                $scripts->registered[ $handle ]->extra = array();
            }
        }
        if ( isset( $styles->registered['better-messages'] ) ) {
            $styles->registered['better-messages']->extra = array();
        }

        $wp_scripts     = $scripts;
        $wp_styles      = $styles;
        $bm->js_loaded  = false;
        $bm->css_loaded = false;

        $this->manifest_surface = $surface;
        add_filter( 'bp_better_messages_script_variable', array( $this, 'preview_script_variables' ), 999 );

        $head = '';
        $body = '';
        try {
            if ( null === $queued ) {
                add_action( 'wp_head', array( $this, 'drop_admin_bar_from_capture' ), 2 );
                ob_start();
                try {
                    do_action( 'wp_head' );
                } catch ( \Throwable $e ) {
                }
                $head_html = ob_get_clean();
                remove_action( 'wp_head', array( $this, 'drop_admin_bar_from_capture' ), 2 );

                ob_start();
                try {
                    do_action( 'wp_footer' );
                } catch ( \Throwable $e ) {
                }
                $footer_html = ob_get_clean();

                $queued = array(
                    'head'   => $this->style_markup_only( $head_html ),
                    'footer' => $this->style_markup_only( $footer_html ),
                );
            }
            $scripts->done  = array();
            $scripts->to_do = array();
            $bm->enqueue_js();
            $bm->enqueue_css();

            ob_start();
            if ( function_exists( 'wp_print_font_faces' ) ) {
                wp_print_font_faces();
            }
            $this->render_preview_styles();
            $head = $queued['head'] . ob_get_clean() . $this->preview_stage_styles();

            ob_start();
            echo '<div class="bm-preview-stage">';
            if ( 'widgets' !== $surface ) {
                echo do_shortcode( '[better_messages]' );
            }
            echo '</div>';
            $this->render_theme_armor();
            if ( 'widgets' === $surface && ! function_exists( 'Better_Messages_Mini' ) && file_exists( __DIR__ . '/mini.php' ) && function_exists( 'bpbm_fs' ) && bpbm_fs()->is__premium_only() && isset( $bm->settings['mechanism'] ) && 'websocket' === $bm->settings['mechanism'] ) {
                require_once __DIR__ . '/mini.php';
            }
            if ( function_exists( 'Better_Messages_Mini_List' ) ) {
                Better_Messages_Mini_List()->html();
            }
            if ( function_exists( 'Better_Messages_Mini' ) ) {
                Better_Messages_Mini()->html();
            }
            if ( isset( $bm->hooks ) && is_object( $bm->hooks ) && method_exists( $bm->hooks, 'mobile_popup_button' ) ) {
                $bm->hooks->mobile_popup_button();
            }
            $wp_scripts->do_items( array( 'better-messages' ) );
            echo $queued['footer'];
            $this->render_preview_listener();
            $body = ob_get_clean();
        } finally {
            remove_filter( 'bp_better_messages_script_variable', array( $this, 'preview_script_variables' ), 999 );
            $this->manifest_surface = '';
            $bm->js_loaded        = $saved_js;
            $bm->css_loaded       = $saved_css;
            $bm->script_variables = $saved_vars;
            $wp_scripts           = $saved_scripts;
            $wp_styles            = $saved_styles;
        }

        $theme_classes = function_exists( 'get_body_class' ) ? (array) get_body_class() : array();
        $theme_classes = array_diff( $theme_classes, array( 'admin-bar', 'no-customize-support', 'customize-support' ) );
        $classes = array_merge( $theme_classes, array( 'bm-preview-mode', 'bm-preview-surface-' . $surface ), (array) $this->design_body_classes() );

        return array(
            'head'      => $head,
            'body'      => $body,
            'bodyClass' => implode( ' ', array_unique( $classes ) ),
        );
    }

    public function rest_api_init() {
        register_rest_route( 'better-messages/v1/admin', '/design/save-overrides', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'rest_save_overrides' ),
            'permission_callback' => array( $this, 'permission_can_manage' ),
        ) );
        register_rest_route( 'better-messages/v1/admin', '/design/save-design-settings', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'rest_save_design_settings' ),
            'permission_callback' => array( $this, 'permission_can_manage' ),
        ) );
        register_rest_route( 'better-messages/v1/admin', '/design/save-options', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'rest_save_design_options' ),
            'permission_callback' => array( $this, 'permission_can_manage' ),
        ) );
        register_rest_route( 'better-messages/v1/admin', '/design/apply-preset', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'rest_apply_preset' ),
            'permission_callback' => array( $this, 'permission_can_manage' ),
        ) );
    }

    public function option_controls() {
        static $cache = null;
        if ( null !== $cache ) {
            return $cache;
        }
        $cache = array();
        foreach ( better_messages_design_schema_controls() as $id => $control ) {
            if ( 'option' === $control['source'] ) {
                $cache[ $id ] = $control;
            }
        }
        return $cache;
    }

    public function setting_controls() {
        static $cache = null;
        if ( null !== $cache ) {
            return $cache;
        }
        $cache = array();
        foreach ( better_messages_design_schema_controls() as $id => $control ) {
            if ( 'setting' === $control['source'] && 'darkMode' !== $id && 'customCss' !== $id ) {
                $cache[ $id ] = $control;
            }
        }
        return $cache;
    }

    public function setting_control_value( $control ) {
        $key   = ! empty( $control['settingKey'] ) ? $control['settingKey'] : $control['id'];
        $value = isset( Better_Messages()->settings[ $key ] ) ? Better_Messages()->settings[ $key ] : $control['default'];
        if ( is_scalar( $value ) ) {
            return (string) $value;
        }
        return is_scalar( $control['default'] ) ? (string) $control['default'] : '';
    }

    public function default_design_options() {
        $defaults = array();
        foreach ( $this->option_controls() as $id => $control ) {
            $defaults[ $id ] = $control['default'];
        }
        return $defaults;
    }

    public function get_design_options() {
        $stored = get_option( self::OPTION_DESIGN_OPTIONS, array() );
        if ( ! is_array( $stored ) ) {
            $stored = array();
        }
        $merged = array_merge( $this->default_design_options(), $stored );
        return apply_filters( 'better_messages_design_options', $merged );
    }

    public function get_design_var( $token, $scope = 'light' ) {
        $vars = $this->resolve_effective_vars( $scope );
        return isset( $vars[ $token ] ) ? $vars[ $token ] : '';
    }

    public function get_design_var_int( $token, $scope = 'light' ) {
        return (int) preg_replace( '/[^0-9.-]/', '', (string) $this->get_design_var( $token, $scope ) );
    }

    public function avatar_radius_css() {
        $value = trim( (string) $this->get_design_var( '--bm-radius-avatar' ) );
        return preg_match( '/^\d{1,2}(\.\d+)?%$/', $value ) ? $value : '50%';
    }

    public function get_design_option( $key, $fallback = null ) {
        $all = $this->get_design_options();
        return isset( $all[ $key ] ) ? $all[ $key ] : $fallback;
    }

    public function rest_save_design_options( WP_REST_Request $request ) {
        $payload = $request->get_json_params();
        if ( ! is_array( $payload ) ) {
            return new WP_Error( 'invalid_data', 'Invalid options payload', array( 'status' => 400 ) );
        }
        $current = $this->get_design_options();
        $clean   = $this->sanitize_design_options( array_merge( $current, $payload ) );
        update_option( self::OPTION_DESIGN_OPTIONS, $clean );
        return rest_ensure_response( array(
            'success'       => true,
            'designOptions' => $this->get_design_options(),
        ) );
    }

    public function get_presets() {
        static $cache = null;
        if ( null !== $cache ) {
            return $cache;
        }

        $clean = array( $this->default_preset_summary() );

        foreach ( better_messages_design_shipped_presets() as $slug => $entry ) {
            $slug = (string) $slug;
            if ( ! is_array( $entry ) || 'default' === $slug || ! preg_match( '/^[a-z0-9][a-z0-9-]{0,40}$/', $slug ) ) {
                continue;
            }
            $tokens  = isset( $entry['tokens'] ) && is_array( $entry['tokens'] ) ? $entry['tokens'] : array();
            $light   = isset( $tokens['light'] ) && is_array( $tokens['light'] ) ? $tokens['light'] : array();
            $dark    = isset( $tokens['dark'] ) && is_array( $tokens['dark'] ) ? $tokens['dark'] : $light;
            $clean[] = array(
                'slug'        => $slug,
                'name'        => isset( $entry['name'] ) ? (string) $entry['name'] : $slug,
                'description' => isset( $entry['description'] ) ? (string) $entry['description'] : '',
                'category'    => isset( $entry['category'] ) ? (string) $entry['category'] : 'messenger-styles',
                'icon'        => isset( $entry['icon'] ) ? $this->sanitize_preset_icon( $entry['icon'] ) : '',
                'preview'     => $this->preset_swatches( $light, 'light' ),
                'previewDark' => $this->preset_swatches( $dark, 'dark' ),
            );
        }

        $cache = apply_filters( 'better_messages_design_presets', $clean );
        if ( ! is_array( $cache ) ) {
            $cache = $clean;
        }
        foreach ( $cache as $index => $entry ) {
            if ( isset( $entry['colors'] ) && is_array( $entry['colors'] ) ) {
                continue;
            }
            $cache[ $index ]['colors'] = $this->preset_colors( isset( $entry['slug'] ) ? $entry['slug'] : '' );
        }
        return $cache;
    }

    private function sanitize_preset_icon( $icon ) {
        $icon = trim( (string) $icon );
        if ( '' === $icon || ! class_exists( 'Better_Messages_Shortcodes' ) ) {
            return '';
        }
        return Better_Messages_Shortcodes::instance()->sanitize_icon_svg( $icon );
    }

    private function preset_swatches( $tokens, $scope = 'light' ) {
        $base = 'dark' === $scope ? $this->default_design_vars_dark() : $this->default_design_vars();
        $keys = array(
            'accent'      => '--bm-color-accent',
            'bubbleSelf'  => '--bm-color-bubble-self-bg',
            'bubbleOther' => '--bm-color-bubble-other-bg',
            'background'  => '--bm-color-bg',
        );

        $swatches = array();
        foreach ( $keys as $key => $token ) {
            if ( isset( $tokens[ $token ] ) ) {
                $swatches[ $key ] = (string) $tokens[ $token ];
            } else {
                $swatches[ $key ] = isset( $base[ $token ] ) ? (string) $base[ $token ] : '';
            }
        }
        return $swatches;
    }

    public function default_preset_summary() {
        return array(
            'slug'        => 'default',
            'name'        => _x( 'Default', 'Settings page', 'bp-better-messages' ),
            'description' => _x( 'The colors Better Messages ships with', 'Settings page', 'bp-better-messages' ),
            'category'    => 'messenger-styles',
            'icon'        => '',
            'preview'     => $this->preset_swatches( array(), 'light' ),
            'previewDark' => $this->preset_swatches( array(), 'dark' ),
        );
    }

    private function preset_lookup( $slug ) {
        static $cache = array();
        $slug = (string) $slug;
        if ( array_key_exists( $slug, $cache ) ) {
            return $cache[ $slug ];
        }
        $shipped = better_messages_design_shipped_presets();
        $data    = null;
        if ( isset( $shipped[ $slug ]['tokens'] ) && is_array( $shipped[ $slug ]['tokens'] ) ) {
            $data = array(
                'slug'   => $slug,
                'tokens' => $shipped[ $slug ]['tokens'],
            );
        }
        $preset         = apply_filters( 'better_messages_design_preset', $data, $slug );
        $cache[ $slug ] = is_array( $preset ) ? $preset : null;
        return $cache[ $slug ];
    }

    public function is_known_preset( $slug ) {
        $slug = (string) $slug;
        if ( '' === $slug ) {
            return false;
        }
        foreach ( $this->get_presets() as $entry ) {
            if ( isset( $entry['slug'] ) && $entry['slug'] === $slug ) {
                return true;
            }
        }
        return false;
    }

    public function get_preset( $slug ) {
        return $this->is_known_preset( $slug ) ? $this->preset_lookup( $slug ) : null;
    }

    public function preset_colors( $slug ) {
        $out  = array(
            'light' => array(),
            'dark'  => array(),
        );
        $slug = (string) $slug;
        if ( '' === $slug || 'default' === $slug ) {
            return $out;
        }
        $preset = $this->preset_lookup( $slug );
        $tokens = ( is_array( $preset ) && isset( $preset['tokens'] ) && is_array( $preset['tokens'] ) ) ? $preset['tokens'] : array();
        foreach ( array( 'light', 'dark' ) as $scope ) {
            if ( empty( $tokens[ $scope ] ) || ! is_array( $tokens[ $scope ] ) ) {
                continue;
            }
            foreach ( $tokens[ $scope ] as $token => $value ) {
                $token = (string) $token;
                if ( ! $this->is_safe_token( $token ) || ! $this->is_color_token( $token ) ) {
                    continue;
                }
                $out[ $scope ][ $token ] = $this->sanitize_value( $value );
            }
        }
        return $out;
    }

    public function get_active_preset( $scope = 'light' ) {
        if ( 'dark' === $scope ) {
            $stored = get_option( self::OPTION_DESIGN_PRESET_DARK, null );
            $value  = null === $stored ? (string) get_option( self::OPTION_DESIGN_PRESET, '' ) : (string) $stored;
        } else {
            $value = (string) get_option( self::OPTION_DESIGN_PRESET, '' );
        }
        return apply_filters( 'better_messages_design_active_preset', $value, $scope );
    }

    private function sanitize_preset_slug( $value ) {
        if ( null === $value ) {
            return null;
        }
        $slug = sanitize_text_field( (string) $value );
        if ( '' === $slug || 'default' === $slug ) {
            return 'default';
        }
        return $this->is_known_preset( $slug ) ? $slug : null;
    }

    private function pin_dark_preset() {
        if ( null === get_option( self::OPTION_DESIGN_PRESET_DARK, null ) ) {
            update_option( self::OPTION_DESIGN_PRESET_DARK, (string) get_option( self::OPTION_DESIGN_PRESET, '' ) );
        }
    }

    public function rest_apply_preset( WP_REST_Request $request ) {
        $slug = sanitize_text_field( (string) $request->get_param( 'slug' ) );
        if ( '' === $slug ) {
            return new WP_Error( 'invalid_slug', 'A preset slug is required', array( 'status' => 400 ) );
        }
        $preview = filter_var( $request->get_param( 'preview' ), FILTER_VALIDATE_BOOLEAN );

        $scope = sanitize_key( (string) $request->get_param( 'scope' ) );
        if ( ! in_array( $scope, array( 'light', 'dark' ), true ) ) {
            $scope = 'both';
        }
        $scopes = 'both' === $scope ? array( 'light', 'dark' ) : array( $scope );

        $colors = array();
        foreach ( $scopes as $painted ) {
            $colors[ $painted ] = array();
        }

        if ( 'default' !== $slug ) {
            if ( null === $this->get_preset( $slug ) ) {
                return new WP_Error( 'unknown_preset', 'Unknown preset', array( 'status' => 404 ) );
            }

            $palette = $this->preset_colors( $slug );
            foreach ( $scopes as $painted ) {
                $colors[ $painted ] = isset( $palette[ $painted ] ) ? $palette[ $painted ] : array();
            }
        }

        $overrides = $this->repaint_overrides( $this->get_user_overrides(), $colors );

        if ( ! $preview ) {
            update_option( self::OPTION_OVERRIDES, $overrides );
            if ( 'dark' === $scope ) {
                update_option( self::OPTION_DESIGN_PRESET_DARK, $slug );
            } elseif ( 'light' === $scope ) {
                $this->pin_dark_preset();
                update_option( self::OPTION_DESIGN_PRESET, $slug );
            } else {
                update_option( self::OPTION_DESIGN_PRESET, $slug );
                update_option( self::OPTION_DESIGN_PRESET_DARK, $slug );
            }
        }

        return rest_ensure_response( array(
            'success'           => true,
            'activePreset'      => 'dark' === $scope ? $this->get_active_preset( 'light' ) : $slug,
            'activePresetDark'  => 'light' === $scope ? $this->get_active_preset( 'dark' ) : $slug,
            'overrides'         => $overrides,
            'effectiveVars'     => $this->resolve_effective_vars( 'light', $overrides ),
            'effectiveVarsDark' => $this->resolve_effective_vars( 'dark', $overrides ),
            'designOptions'     => $this->get_design_options(),
        ) );
    }

    private function repaint_overrides( $overrides, $colors ) {
        $out = array(
            'light' => array(),
            'dark'  => array(),
        );
        foreach ( array( 'light', 'dark' ) as $scope ) {
            $stored = isset( $overrides[ $scope ] ) && is_array( $overrides[ $scope ] ) ? $overrides[ $scope ] : array();
            if ( ! array_key_exists( $scope, $colors ) ) {
                $out[ $scope ] = $stored;
                continue;
            }
            foreach ( $stored as $token => $value ) {
                if ( $this->is_color_token( (string) $token ) ) {
                    continue;
                }
                $out[ $scope ][ (string) $token ] = $value;
            }
            if ( ! empty( $colors[ $scope ] ) && is_array( $colors[ $scope ] ) ) {
                $out[ $scope ] = array_merge( $out[ $scope ], $colors[ $scope ] );
            }
        }
        return $out;
    }

    private function is_color_token( $token ) {
        $controls = better_messages_design_schema_controls();
        $token    = (string) $token;
        if ( isset( $controls[ $token ] ) ) {
            return ! empty( $controls[ $token ]['kind'] ) && 'color' === $controls[ $token ]['kind'];
        }
        return 0 === strpos( $token, '--bm-color-' );
    }

    private function sanitize_design_options( $values ) {
        $clean = array();
        foreach ( $this->option_controls() as $id => $control ) {
            $default = $control['default'];
            if ( ! array_key_exists( $id, $values ) ) {
                continue;
            }
            $raw = $values[ $id ];
            if ( is_bool( $default ) ) {
                $value = ( '0' === $raw || 'false' === $raw ) ? false : (bool) $raw;
            } else {
                $allowed = array();
                if ( ! empty( $control['choices'] ) ) {
                    foreach ( $control['choices'] as $choice ) {
                        $allowed[] = (string) $choice['value'];
                    }
                }
                $value = in_array( (string) $raw, $allowed, true ) ? (string) $raw : $default;
            }
            if ( $value === $default ) {
                continue;
            }
            $clean[ $id ] = $value;
        }
        return $clean;
    }

    public function permission_can_manage() {
        return current_user_can( 'manage_options' );
    }

    public function rest_save_overrides( WP_REST_Request $request ) {
        $payload = $request->get_json_params();
        if ( ! is_array( $payload ) ) {
            return new WP_Error( 'invalid_data', 'Invalid overrides payload', array( 'status' => 400 ) );
        }
        $clean = array(
            'light' => array(),
            'dark'  => array(),
        );
        foreach ( array( 'light', 'dark' ) as $scope ) {
            if ( empty( $payload[ $scope ] ) || ! is_array( $payload[ $scope ] ) ) {
                continue;
            }
            foreach ( $payload[ $scope ] as $token => $value ) {
                if ( ! $this->is_safe_token( $token ) ) {
                    continue;
                }
                $clean[ $scope ][ $token ] = $this->sanitize_value( $value );
            }
        }
        update_option( self::OPTION_OVERRIDES, $clean );

        $light = $this->sanitize_preset_slug( isset( $payload['preset'] ) ? $payload['preset'] : null );
        $dark  = $this->sanitize_preset_slug( isset( $payload['presetDark'] ) ? $payload['presetDark'] : null );
        if ( null !== $light && $light !== (string) get_option( self::OPTION_DESIGN_PRESET, '' ) ) {
            $this->pin_dark_preset();
            update_option( self::OPTION_DESIGN_PRESET, $light );
        }
        if ( null !== $dark ) {
            update_option( self::OPTION_DESIGN_PRESET_DARK, $dark );
        }

        return rest_ensure_response( array(
            'success'       => true,
            'overrides'         => $clean,
            'effectiveVars'     => $this->resolve_effective_vars( 'light' ),
            'effectiveVarsDark' => $this->resolve_effective_vars( 'dark' ),
            'activePreset'      => $this->get_active_preset( 'light' ),
            'activePresetDark'  => $this->get_active_preset( 'dark' ),
        ) );
    }

    public function rest_save_design_settings( WP_REST_Request $request ) {
        $dark_mode = sanitize_text_field( (string) $request->get_param( 'darkMode' ) );

        $changed = array();
        if ( '' !== $dark_mode && in_array( $dark_mode, array( 'always', 'never' ), true ) ) {
            update_option( self::OPTION_DARK_MODE, $dark_mode );
            $changed['darkMode'] = $dark_mode;
        }

        return rest_ensure_response( array(
            'success'  => true,
            'changed'  => $changed,
            'darkMode' => $this->get_dark_mode(),
        ) );
    }

    private $effective_vars_cache = array();

    public function flush_effective_vars() {
        $this->effective_vars_cache = array();
    }

    public function resolve_effective_vars( $scope = 'light', $overrides = null ) {
        $memoize = ! is_array( $overrides );

        if ( $memoize && isset( $this->effective_vars_cache[ $scope ] ) ) {
            return $this->effective_vars_cache[ $scope ];
        }

        $resolved = $this->resolve_effective_vars_uncached( $scope, $overrides );

        if ( $memoize ) {
            $this->effective_vars_cache[ $scope ] = $resolved;
        }

        return $resolved;
    }

    private function resolve_effective_vars_uncached( $scope = 'light', $overrides = null ) {
        if ( 'dark' === $scope ) {
            $vars = array_merge( $this->default_design_vars(), $this->default_design_vars_dark() );
        } else {
            $vars = $this->default_design_vars();
        }
        if ( ! is_array( $overrides ) ) {
            $overrides = $this->get_user_overrides();
        }

        if ( 'dark' === $scope && isset( $overrides['light'] ) && is_array( $overrides['light'] ) ) {
            foreach ( $overrides['light'] as $token => $value ) {
                if ( ! $this->is_color_token( $token ) ) {
                    $vars[ $token ] = $value;
                }
            }
        }

        if ( isset( $overrides[ $scope ] ) && is_array( $overrides[ $scope ] ) ) {
            $vars = array_merge( $vars, $overrides[ $scope ] );
        }
        return array_merge( $vars, $this->resolve_colors( $scope, $overrides ) );
    }

    public function color_controls() {
        static $cache = null;
        if ( null === $cache ) {
            $cache = array();
            foreach ( better_messages_design_schema_controls() as $id => $control ) {
                if ( 'token' === $control['source'] && 'color' === $control['kind'] ) {
                    $cache[ $id ] = $control;
                }
            }
        }
        return $cache;
    }

    private function parse_triple( $value ) {
        if ( is_array( $value ) ) {
            return $value;
        }
        $parts = array_map( 'trim', explode( ',', (string) $value ) );
        if ( count( $parts ) < 3 ) {
            return null;
        }
        $rgb = array();
        for ( $i = 0; $i < 3; $i++ ) {
            if ( ! is_numeric( $parts[ $i ] ) ) {
                return null;
            }
            $rgb[] = max( 0, min( 255, (int) round( (float) $parts[ $i ] ) ) );
        }
        return $rgb;
    }

    private function format_triple( $rgb ) {
        return implode( ', ', array_map( 'intval', $rgb ) );
    }

    private function luminance( $rgb ) {
        $sum = 0;
        $weights = array( 0.2126, 0.7152, 0.0722 );
        foreach ( $rgb as $i => $channel ) {
            $c = $channel / 255;
            $c = $c <= 0.03928 ? $c / 12.92 : pow( ( $c + 0.055 ) / 1.055, 2.4 );
            $sum += $weights[ $i ] * $c;
        }
        return $sum;
    }

    private function contrast( $a, $b ) {
        $la = $this->luminance( $a );
        $lb = $this->luminance( $b );
        return ( max( $la, $lb ) + 0.05 ) / ( min( $la, $lb ) + 0.05 );
    }

    private function mix_triples( $a, $b, $amount ) {
        $out = array();
        for ( $i = 0; $i < 3; $i++ ) {
            $out[] = max( 0, min( 255, (int) round( $a[ $i ] * ( 1 - $amount ) + $b[ $i ] * $amount ) ) );
        }
        return $out;
    }

    public function zones() {
        static $cache = null;
        if ( null !== $cache ) {
            return $cache;
        }
        $cache = array();
        foreach ( better_messages_design_zones() as $name => $zone ) {
            $tokens = array();
            foreach ( $zone['parts'] as $part ) {
                $tokens[ $part ] = better_messages_design_zone_token( $name, $part );
            }
            $cache[ $name ] = array(
                'name'   => $name,
                'prefix' => $zone['prefix'],
                'tokens' => $tokens,
                'solid'  => ! empty( $zone['solid'] ),
                'list'   => ! empty( $zone['list'] ),
            );
        }
        return $cache;
    }

    private function zone_pair( $zone ) {
        $parts = better_messages_design_zone_parts();
        $pair  = array();
        foreach ( $zone['tokens'] as $part => $token ) {
            $pair[ $parts[ $part ]['shared'] ] = $token;
        }
        return $pair;
    }

    private function apply_formula( $op, $operands, $stock ) {
        $rgb = array();
        foreach ( $operands as $operand ) {
            if ( is_string( $operand ) ) {
                $rgb[] = $this->parse_triple( $operand );
            } else {
                $rgb[] = $operand;
            }
        }
        switch ( $op ) {
            case 'same':
                return isset( $rgb[0] ) && is_array( $rgb[0] ) ? $this->format_triple( $rgb[0] ) : $stock;
            case 'mix':
                if ( isset( $rgb[0], $rgb[1], $rgb[2] ) && is_array( $rgb[0] ) && is_array( $rgb[1] ) ) {
                    return $this->format_triple( $this->mix_triples( $rgb[0], $rgb[1], (float) $rgb[2] ) );
                }
                return $stock;
            case 'shade':
                if ( isset( $rgb[0], $rgb[1] ) && is_array( $rgb[0] ) ) {
                    return $this->format_triple( $this->mix_triples( $rgb[0], array( 0, 0, 0 ), (float) $rgb[1] ) );
                }
                return $stock;
            case 'contrast':
                if ( isset( $rgb[0], $rgb[1], $rgb[2] ) && is_array( $rgb[0] ) && is_array( $rgb[1] ) && is_array( $rgb[2] ) ) {
                    $first = $this->contrast( $rgb[0], $rgb[1] ) >= $this->contrast( $rgb[0], $rgb[2] );
                    return $this->format_triple( $first ? $rgb[1] : $rgb[2] );
                }
                return $stock;
            case 'readable':
                if ( isset( $rgb[0], $rgb[1], $rgb[2], $rgb[3] ) && is_array( $rgb[0] ) && is_array( $rgb[1] ) && is_array( $rgb[2] ) ) {
                    return $this->format_triple( $this->contrast( $rgb[0], $rgb[1] ) >= (float) $rgb[3] ? $rgb[1] : $rgb[2] );
                }
                return $stock;
            case 'lift':
                if ( isset( $rgb[0], $rgb[1] ) && is_array( $rgb[0] ) ) {
                    $white = array( 255, 255, 255 );
                    $light = $this->contrast( $rgb[0], array( 0, 0, 0 ) ) >= $this->contrast( $rgb[0], $white );
                    return $this->format_triple( $this->mix_triples( $rgb[0], $white, $light ? (float) $rgb[1] : (float) $rgb[1] / 4 ) );
                }
                return $stock;
        }
        return $stock;
    }

    private function stock_color( $control, $scheme ) {
        return ( 'dark' === $scheme && isset( $control['dark'] ) ) ? (string) $control['dark'] : (string) $control['default'];
    }

    private function visible_colors( $scope, $overrides ) {
        $scoped = isset( $overrides[ $scope ] ) && is_array( $overrides[ $scope ] ) ? $overrides[ $scope ] : array();
        $out    = array( 'values' => array(), 'source' => array(), 'touched' => array() );
        foreach ( $this->color_controls() as $id => $control ) {
            if ( ! empty( $control['hidden'] ) ) {
                continue;
            }
            $set = isset( $scoped[ $id ] ) && '' !== $scoped[ $id ] && null !== $this->parse_triple( $scoped[ $id ] );
            $out['values'][ $id ]  = $set ? (string) $scoped[ $id ] : ( empty( $control['formula'] ) ? $this->stock_color( $control, $scope ) : null );
            $out['source'][ $id ]  = $scope;
            $out['touched'][ $id ] = $set;
        }
        return $out;
    }

    private function derive_colors( $visible, $zone = null ) {
        $scheme   = isset( $visible['source']['--bm-color-text-primary'] ) ? $visible['source']['--bm-color-text-primary'] : 'light';
        $controls = $this->color_controls();
        $pair     = $zone ? $this->zone_pair( $zone ) : array();
        $own      = array();
        foreach ( $this->zones() as $def ) {
            $own = array_merge( $own, array_values( $this->zone_pair( $def ) ) );
        }
        $memo     = array();
        $moved    = array();

        $resolve = function ( $id ) use ( &$resolve, &$memo, &$moved, $controls, $visible, $scheme, $pair, $own ) {
            if ( array_key_exists( $id, $memo ) ) {
                return $memo[ $id ];
            }
            if ( ! isset( $controls[ $id ] ) ) {
                $memo[ $id ]  = $id;
                $moved[ $id ] = false;
                return $id;
            }
            $control = $controls[ $id ];
            $stock   = $this->stock_color( $control, $scheme );
            $hidden  = ! empty( $control['hidden'] );
            if ( ! $hidden && ! empty( $visible['touched'][ $id ] ) && isset( $visible['values'][ $id ] ) ) {
                $memo[ $id ]  = $visible['values'][ $id ];
                $moved[ $id ] = ( $memo[ $id ] !== $stock );
                return $memo[ $id ];
            }
            if ( empty( $control['formula'] ) || ! is_array( $control['formula'] ) ) {
                $value        = ( ! $hidden && isset( $visible['values'][ $id ] ) ) ? $visible['values'][ $id ] : $stock;
                $memo[ $id ]  = $value;
                $moved[ $id ] = ( $value !== $stock );
                return $value;
            }
            $formula  = $control['formula'];
            $op       = array_shift( $formula );
            $is_pair  = in_array( $id, $own, true );
            $operands = array();
            $any      = false;
            foreach ( $formula as $arg ) {
                if ( ! is_string( $arg ) ) {
                    $operands[] = $arg;
                    continue;
                }
                $pinned = 0 === strpos( $arg, 'base:' );
                $ref    = $pinned ? substr( $arg, 5 ) : $arg;
                if ( $pair && ! $pinned && ! $is_pair && isset( $pair[ $ref ] ) ) {
                    $ref = $pair[ $ref ];
                }
                $operands[] = $resolve( $ref );
                if ( ! empty( $moved[ $ref ] ) ) {
                    $any = true;
                }
            }
            $memo[ $id ]  = $any ? $this->apply_formula( $op, $operands, $stock ) : $stock;
            $moved[ $id ] = $any;
            return $memo[ $id ];
        };

        $out = array();
        foreach ( $controls as $id => $control ) {
            $out[ $id ] = $resolve( $id );
        }
        return $out;
    }

    public function resolve_colors( $scope = 'light', $overrides = null ) {
        if ( ! is_array( $overrides ) ) {
            $overrides = $this->get_user_overrides();
        }
        return $this->derive_colors( $this->visible_colors( $scope, $overrides ) );
    }

    private function reads_better( $bg, $candidate, $incumbent ) {
        $bg = $this->parse_triple( $bg );
        $candidate = $this->parse_triple( $candidate );
        $incumbent = $this->parse_triple( $incumbent );
        if ( ! $bg || ! $candidate || ! $incumbent ) {
            return false;
        }
        return $this->contrast( $bg, $candidate ) > $this->contrast( $bg, $incumbent );
    }

    private function readable_text( $bg, $text ) {
        $bg_rgb   = $this->parse_triple( $bg );
        $text_rgb = $this->parse_triple( $text );
        if ( ! $bg_rgb || ! $text_rgb || $this->contrast( $bg_rgb, $text_rgb ) >= 3 ) {
            return null;
        }
        $light = $this->stock_text( 'light' );
        $dark  = $this->stock_text( 'dark' );
        $best  = $this->reads_better( $bg, $dark, $light ) ? $dark : $light;
        return $this->contrast( $bg_rgb, $this->parse_triple( $best ) ) > $this->contrast( $bg_rgb, $text_rgb ) ? $best : null;
    }

    private function stock_text( $scope ) {
        $controls = $this->color_controls();
        return isset( $controls['--bm-color-text-primary'] ) ? $this->stock_color( $controls['--bm-color-text-primary'], $scope ) : ( 'dark' === $scope ? '241, 245, 249' : '17, 24, 39' );
    }

    public function color_sets( $overrides = null ) {
        if ( ! is_array( $overrides ) ) {
            $overrides = $this->get_user_overrides();
        }
        $sets = array();
        foreach ( array( 'light', 'dark' ) as $scope ) {
            $visible = $this->visible_colors( $scope, $overrides );
            $base    = $this->derive_colors( $visible );
            if ( empty( $visible['touched']['--bm-color-text-primary'] ) ) {
                $rescued = $this->readable_text( $base['--bm-color-bg'], $base['--bm-color-text-primary'] );
                if ( null !== $rescued ) {
                    $visible['values']['--bm-color-text-primary']  = $rescued;
                    $visible['touched']['--bm-color-text-primary'] = true;
                    $base = $this->derive_colors( $visible );
                }
            }

            $derive_zone = function ( $zone_visible, $zone ) use ( $base ) {
                $set = $this->derive_colors( $zone_visible, $zone );
                foreach ( $this->color_controls() as $id => $control ) {
                    if ( ! empty( $control['global'] ) ) {
                        $set[ $id ] = $base[ $id ];
                    }
                }
                foreach ( $this->zone_pair( $zone ) as $shared => $token ) {
                    if ( '--bm-color-bg' === $shared && ! $zone['solid'] ) {
                        continue;
                    }
                    $set[ $shared ] = $set[ $token ];
                }
                $bg = $set[ $zone['tokens']['bg'] ];
                $set['--bm-color-surface'] = $bg;
                if ( $zone['list'] ) {
                    $set['--bm-color-inbox-bg'] = $bg;
                }
                if ( $zone['solid'] ) {
                    foreach ( array( '--bm-color-bg', '--bm-color-chat-bg', '--bm-color-composer-bg', '--bm-color-card-bg', '--bm-color-bg-elevated' ) as $id ) {
                        $set[ $id ] = $bg;
                    }
                }
                return $set;
            };

            $zones = array();
            foreach ( $this->zones() as $name => $zone ) {
                $zone_visible = $visible;
                $set          = $derive_zone( $zone_visible, $zone );
                if ( isset( $zone['tokens']['text'] ) && empty( $zone_visible['touched'][ $zone['tokens']['text'] ] ) ) {
                    $rescued = $this->readable_text( $set[ $zone['tokens']['bg'] ], $set[ $zone['tokens']['text'] ] );
                    if ( null !== $rescued ) {
                        $zone_visible['values'][ $zone['tokens']['text'] ]  = $rescued;
                        $zone_visible['touched'][ $zone['tokens']['text'] ] = true;
                        $set = $derive_zone( $zone_visible, $zone );
                    }
                }
                $zones[ $name ] = $set;
            }

            $sets[ $scope ] = array(
                'base'  => $base,
                'zone'  => $zones['secondary'],
                'list'  => $zones['list'],
                'zones' => $zones,
            );
        }
        return $sets;
    }

    public function zone_token( $id, $zone = 'secondary' ) {
        return '--bm-zone-' . $zone . '-' . substr( $id, strlen( '--bm-color-' ) );
    }

    public function get_init_data() {
        return array(
            'pluginVersion'     => Better_Messages()->version,
            'darkMode'          => $this->get_dark_mode(),
            'overrides'         => $this->get_user_overrides(),
            'effectiveVars'     => $this->resolve_effective_vars( 'light' ),
            'effectiveVarsDark' => $this->resolve_effective_vars( 'dark' ),
            'previewSurfaces'   => $this->preview_surfaces(),
            'previewManifest'   => $this->preview_manifest(),
            'designOptions'     => $this->get_design_options(),
            'presets'           => $this->get_presets(),
            'activePreset'      => $this->get_active_preset( 'light' ),
            'activePresetDark'  => $this->get_active_preset( 'dark' ),
            'schema'            => better_messages_design_schema(),
            'zones'             => array_values( $this->zones() ),
            'previewThreads'    => $this->preview_threads(),
            'bodyClassPrefixes' => $this->body_class_prefixes(),
        );
    }

    public function export_design() {
        $stored    = $this->get_user_overrides();
        $overrides = array(
            'light' => array(),
            'dark'  => array(),
        );
        foreach ( array( 'light', 'dark' ) as $scope ) {
            if ( ! empty( $stored[ $scope ] ) && is_array( $stored[ $scope ] ) ) {
                $overrides[ $scope ] = $stored[ $scope ];
            }
        }

        $light = $this->get_active_preset( 'light' );
        $dark  = $this->get_active_preset( 'dark' );

        return array(
            'darkMode'  => $this->get_dark_mode(),
            'preset'    => (object) array(
                'light' => '' === $light ? 'default' : $light,
                'dark'  => '' === $dark ? 'default' : $dark,
            ),
            'overrides' => (object) array(
                'light' => (object) $overrides['light'],
                'dark'  => (object) $overrides['dark'],
            ),
            'options'   => (object) $this->get_design_options(),
        );
    }

    public function import_design( $bundle ) {
        $report = array(
            'applied' => 0,
            'skipped' => 0,
        );

        if ( ! is_array( $bundle ) ) {
            return $report;
        }

        if ( isset( $bundle['overrides'] ) && is_array( $bundle['overrides'] ) ) {
            $clean = array(
                'light' => array(),
                'dark'  => array(),
            );
            foreach ( array( 'light', 'dark' ) as $scope ) {
                if ( empty( $bundle['overrides'][ $scope ] ) || ! is_array( $bundle['overrides'][ $scope ] ) ) {
                    continue;
                }
                foreach ( $bundle['overrides'][ $scope ] as $token => $value ) {
                    if ( ! $this->is_safe_token( $token ) || is_array( $value ) ) {
                        $report['skipped']++;
                        continue;
                    }
                    $clean[ $scope ][ (string) $token ] = $this->sanitize_value( $value );
                    $report['applied']++;
                }
            }
            update_option( self::OPTION_OVERRIDES, $clean );
        }

        if ( isset( $bundle['options'] ) && is_array( $bundle['options'] ) ) {
            $known = $this->option_controls();
            foreach ( $bundle['options'] as $id => $value ) {
                if ( isset( $known[ $id ] ) ) {
                    $report['applied']++;
                } else {
                    $report['skipped']++;
                }
            }
            update_option( self::OPTION_DESIGN_OPTIONS, $this->sanitize_design_options( $bundle['options'] ) );
        }

        if ( isset( $bundle['preset'] ) && is_array( $bundle['preset'] ) ) {
            $sides = array(
                'light' => self::OPTION_DESIGN_PRESET,
                'dark'  => self::OPTION_DESIGN_PRESET_DARK,
            );
            foreach ( $sides as $side => $option ) {
                if ( ! isset( $bundle['preset'][ $side ] ) ) {
                    continue;
                }
                $slug = $this->sanitize_preset_slug( $bundle['preset'][ $side ] );
                if ( null === $slug ) {
                    $slug = 'default';
                    $report['skipped']++;
                } else {
                    $report['applied']++;
                }
                update_option( $option, $slug );
            }
        }

        if ( isset( $bundle['darkMode'] ) ) {
            $mode = sanitize_text_field( (string) $bundle['darkMode'] );
            if ( in_array( $mode, array( 'always', 'never' ), true ) ) {
                update_option( self::OPTION_DARK_MODE, $mode );
                $report['applied']++;
            } else {
                $report['skipped']++;
            }
        }

        $this->flush_effective_vars();

        return $report;
    }

    private function is_safe_token( $token ) {
        return (bool) preg_match( '/^--bm-[a-z0-9-]+$/', (string) $token );
    }

    private function sanitize_value( $value ) {
        $value = (string) $value;
        $value = str_replace( array( '<', '>', '{', '}', ';' ), '', $value );
        $value = trim( $value );
        return $value;
    }

    public function get_dark_mode() {
        $value = get_option( self::OPTION_DARK_MODE, self::DEFAULT_DARK_MODE );
        if ( ! in_array( $value, array( 'always', 'never' ), true ) ) {
            $value = self::DEFAULT_DARK_MODE;
        }
        return apply_filters( 'better_messages_design_dark_mode', $value );
    }

    public function get_user_overrides() {
        $overrides = get_option( self::OPTION_OVERRIDES, array() );
        if ( ! is_array( $overrides ) ) {
            $overrides = array();
        }
        return apply_filters( 'better_messages_design_overrides', $overrides );
    }

    public function default_design_vars() {
        static $cache = null;
        if ( null === $cache ) {
            $cache = array();
            foreach ( better_messages_design_schema_controls() as $id => $control ) {
                if ( 'token' !== $control['source'] || '' === $control['default'] ) {
                    continue;
                }
                $cache[ $id ] = (string) $control['default'];
            }
        }
        return apply_filters( 'better_messages_default_design_vars', $cache );
    }

    public function default_design_vars_dark() {
        static $cache = null;
        if ( null === $cache ) {
            $cache = array();
            foreach ( better_messages_design_schema_controls() as $id => $control ) {
                if ( 'token' !== $control['source'] || ! isset( $control['dark'] ) || '' === $control['dark'] ) {
                    continue;
                }
                $cache[ $id ] = (string) $control['dark'];
            }
        }
        return apply_filters( 'better_messages_default_design_vars_dark', $cache );
    }

    public function design_body_classes() {
        $classes = array();

        $dark_mode = $this->get_dark_mode();
        if ( 'always' === $dark_mode ) {
            $classes[] = 'bm-messages-dark';
        } elseif ( 'never' === $dark_mode ) {
            $classes[] = 'bm-messages-light';
        }

        $options = $this->get_design_options();
        foreach ( $this->option_controls() as $id => $control ) {
            if ( empty( $control['bodyClass'] ) || ! array_key_exists( $id, $options ) ) {
                continue;
            }
            $classes[] = $control['bodyClass'] . $this->body_class_value( $control, $options[ $id ] );
        }

        foreach ( $this->setting_controls() as $control ) {
            if ( empty( $control['bodyClass'] ) ) {
                continue;
            }
            $value = $this->setting_control_value( $control );
            if ( 'switch' === $control['kind'] ) {
                $on    = ( '1' === $value ) !== ! empty( $control['invert'] );
                $value = $on ? 'on' : 'off';
            }
            $classes[] = $control['bodyClass'] . sanitize_html_class( $value );
        }

        return $classes;
    }

    public function body_class_value( $control, $value ) {
        if ( is_bool( $control['default'] ) ) {
            return $value ? 'on' : 'off';
        }
        return (string) $value;
    }

    public function print_body_classes_script( $exclude = array() ) {
        $classes = array_values( array_diff( (array) $this->design_body_classes(), (array) $exclude ) );

        if ( empty( $classes ) ) {
            return;
        }

        echo '<script type="text/javascript">(function(){var c=' . wp_json_encode( $classes ) . ';'
            . 'function a(){if(!document.body)return false;document.body.classList.add.apply(document.body.classList,c);return true;}'
            . 'if(!a()){document.addEventListener("DOMContentLoaded",a);}})();</script>';
    }

    public function body_class_prefixes() {
        $prefixes = array();
        foreach ( array_merge( $this->option_controls(), $this->setting_controls() ) as $control ) {
            if ( ! empty( $control['bodyClass'] ) ) {
                $prefixes[] = $control['bodyClass'];
            }
        }
        return array_values( array_unique( $prefixes ) );
    }

    public function body_class( $classes ) {
        if ( is_admin() ) {
            return $classes;
        }
        $classes = array_merge( $classes, $this->design_body_classes() );
        if ( ( Better_Messages()->settings['miniWidgetsStyle'] ?? 'classic' ) === 'bubble' ) {
            $has_mini_widget = false;

            if ( is_user_logged_in() || Better_Messages()->guests->guest_access_enabled() ) {
                $script_variables = Better_Messages()->script_variables;

                $threads    = isset( $script_variables['miniMessages'] ) && $script_variables['miniMessages'] === '1';
                $friends    = is_user_logged_in() && isset( $script_variables['miniFriends'] ) && $script_variables['miniFriends'] === '1';
                $groups     = is_user_logged_in() && isset( $script_variables['miniGroups'] ) && $script_variables['miniGroups'] === '1';
                $courses    = is_user_logged_in() && isset( $script_variables['miniCourses'] ) && $script_variables['miniCourses'] === '1';
                $ai_bots    = isset( $script_variables['miniAIBots'] ) && $script_variables['miniAIBots'] === '1';
                $chat_rooms = isset( $script_variables['miniChatRooms'] ) && $script_variables['miniChatRooms'] === '1';
                $users      = isset( $script_variables['miniUsers'] ) && $script_variables['miniUsers'] === '1';

                $has_mini_widget = ( $threads || $friends || $groups || $courses || $ai_bots || $chat_rooms || $users );
            }

            if ( $has_mini_widget ) {
                $classes[] = 'bm-bubble-mode';
                $classes[] = 'bm-bubble-position-' . ( 'left' === $this->get_design_option( 'widgetsPosition', 'right' ) ? 'left' : 'right' );
            }
        }
        return $classes;
    }

    public function admin_body_class( $classes ) {
        return $classes;
    }

    public function derived_vars() {
        static $cache = null;
        if ( null === $cache ) {
            $cache = array();
            foreach ( better_messages_design_schema_controls() as $id => $control ) {
                if ( 'token' === $control['source'] && ! empty( $control['derived'] ) ) {
                    $cache[ $id ] = (string) $control['derived'];
                }
            }
        }
        return $cache;
    }

    private function stylesheet_baseline( $scope ) {
        static $cache = array();

        if ( ! isset( $cache[ $scope ] ) ) {
            $vars = $this->default_design_vars();
            if ( 'dark' === $scope ) {
                $vars = array_merge( $vars, $this->default_design_vars_dark() );
            }
            foreach ( better_messages_design_schema_controls() as $id => $control ) {
                if ( empty( $control['sheet'] ) ) {
                    continue;
                }
                $vars[ $id ] = ( 'dark' === $scope && ! empty( $control['sheetDark'] ) ) ? $control['sheetDark'] : $control['sheet'];
            }
            $cache[ $scope ] = $vars;
        }

        return $cache[ $scope ];
    }

    private function minify_value( $value ) {
        $value = preg_replace( '/\s+/', ' ', (string) $value );
        $value = str_replace( ', ', ',', $value );
        return trim( $value );
    }

    private function zone_default_base( $zone, $id ) {
        $name = substr( $id, strlen( '--bm-color-' ) );

        $plain_background = array(
            'bg-secondary' => array( 'secondary', 'list', 'wlist', 'mob-list' ),
            'inbox-bg'     => array( 'list', 'wlist', 'mob-list', 'mob-tabs' ),
            'bg-elevated'  => array( 'header', 'reply', 'mc-head', 'mc', 'mc-reply', 'dock', 'mob-head', 'mob', 'mob-reply' ),
        );

        if ( isset( $plain_background[ $name ] ) && in_array( $zone, $plain_background[ $name ], true ) ) {
            return '--bm-color-bg';
        }

        if ( 'surface' === $name && 'secondary' === $zone ) {
            return '--bm-color-bg-secondary';
        }

        return $id;
    }

    private function scheme_declarations( $scope, array $base_vars, array $zone_entries ) {
        $derived   = $this->derived_vars();
        $overrides = $this->get_user_overrides();
        $scope_overrides = ( isset( $overrides[ $scope ] ) && is_array( $overrides[ $scope ] ) )
            ? $overrides[ $scope ]
            : array();
        $baseline = $this->stylesheet_baseline( $scope );

        $final = array();
        $lines = '';

        foreach ( $base_vars as $token => $value ) {
            if ( ! $this->is_safe_token( $token ) ) {
                continue;
            }
            $is_derived = isset( $derived[ $token ] ) && ! isset( $scope_overrides[ $token ] );

            if ( $is_derived ) {
                $value = $derived[ $token ];
            }

            $safe_value = $this->minify_value( $this->sanitize_value( $value ) );
            $final[ $token ] = $safe_value;

            $sheet_value = $is_derived
                ? $this->minify_value( $this->sanitize_value( $derived[ $token ] ) )
                : ( isset( $baseline[ $token ] ) ? $this->minify_value( $this->sanitize_value( $baseline[ $token ] ) ) : null );

            if ( null !== $sheet_value && $sheet_value === $safe_value ) {
                continue;
            }

            $lines .= $token . ':' . $safe_value . ';';
        }

        foreach ( $zone_entries as $token => $entry ) {
            if ( ! $this->is_safe_token( $token ) ) {
                continue;
            }

            $safe_value = $this->minify_value( $this->sanitize_value( $entry['value'] ) );
            $sheet_base = $this->zone_default_base( $entry['zone'], $entry['base'] );

            if ( isset( $final[ $sheet_base ] ) && $final[ $sheet_base ] === $safe_value ) {
                continue;
            }

            $lines .= $token . ':' . $safe_value . ';';
        }

        return $lines;
    }

    private function build_css() {
        $overrides = $this->get_user_overrides();

        $light_vars = $this->default_design_vars();
        $dark_vars  = $this->default_design_vars_dark();

        if ( ! empty( $overrides['light'] ) && is_array( $overrides['light'] ) ) {
            $light_vars = array_merge( $light_vars, array_intersect_key( $overrides['light'], $light_vars ) );
        }
        if ( ! empty( $overrides['dark'] ) && is_array( $overrides['dark'] ) ) {
            $dark_vars = array_merge( $dark_vars, array_intersect_key( $overrides['dark'], $dark_vars ) );
        }

        $sets       = $this->color_sets( $overrides );
        $light_vars = array_merge( $light_vars, $sets['light']['base'] );
        $dark_vars  = array_merge( $dark_vars, $sets['dark']['base'] );

        $light_zones = array();
        $dark_zones  = array();
        foreach ( $sets['light']['zones'] as $name => $set ) {
            foreach ( $set as $id => $value ) {
                $light_zones[ $this->zone_token( $id, $name ) ] = array( 'value' => $value, 'base' => $id, 'zone' => $name );
            }
        }
        foreach ( $sets['dark']['zones'] as $name => $set ) {
            foreach ( $set as $id => $value ) {
                $dark_zones[ $this->zone_token( $id, $name ) ] = array( 'value' => $value, 'base' => $id, 'zone' => $name );
            }
        }

        $output     = '';
        $light_body = $this->scheme_declarations( 'light', $light_vars, $light_zones );

        if ( '' !== $light_body ) {
            $output .= ':root{' . $light_body . '}';
        }

        $dark_body = $this->scheme_declarations( 'dark', $dark_vars, $dark_zones );

        if ( '' !== $dark_body ) {
            $output .= '@media(prefers-color-scheme:dark){body:not(.bm-messages-light){' . $dark_body . '}}';
            $output .= 'body.bm-messages-dark{' . $dark_body . '}';
        }

        return $output;
    }

    public function render_css() {
        static $memo = array();

        $key = md5( wp_json_encode( array(
            $this->get_user_overrides(),
            $this->get_design_options(),
            Better_Messages()->version,
        ) ) );

        if ( isset( $memo[ $key ] ) ) {
            return $memo[ $key ];
        }

        $transient = 'bm_design_css_' . $key;
        $cached    = get_transient( $transient );

        if ( is_string( $cached ) ) {
            $memo[ $key ] = $cached;
            return $cached;
        }

        $css = $this->build_css();

        set_transient( $transient, $css, WEEK_IN_SECONDS );
        $memo[ $key ] = $css;

        return $css;
    }
}

endif;

Better_Messages_Design::instance();
