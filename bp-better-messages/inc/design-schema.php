<?php
defined( 'ABSPATH' ) || exit;

function better_messages_design_zone_parts() {
    static $parts = null;
    if ( null !== $parts ) {
        return $parts;
    }
    $parts = array(
        'bg'         => array( 'slug' => 'bg',         'shared' => '--bm-color-bg',                     'label' => _x( 'Background', 'Settings page', 'bp-better-messages' ),               'base' => _x( 'conversation background', 'Settings page', 'bp-better-messages' ),      'default' => '255, 255, 255', 'dark' => '15, 23, 42' ),
        'text'       => array( 'slug' => 'text',       'shared' => '--bm-color-text-primary',           'label' => _x( 'Text', 'Settings page', 'bp-better-messages' ),                     'base' => _x( 'conversation text', 'Settings page', 'bp-better-messages' ),            'default' => '17, 24, 39',    'dark' => '241, 245, 249' ),
        'preview'    => array( 'slug' => 'preview',    'shared' => '--bm-color-preview',                'label' => _x( 'Preview', 'Settings page', 'bp-better-messages' ),                  'base' => _x( 'secondary text', 'Settings page', 'bp-better-messages' ),                   'default' => '107, 114, 128', 'dark' => '148, 163, 184' ),
        'muted'      => array( 'slug' => 'muted',      'shared' => '--bm-color-text-tertiary',          'label' => _x( 'Secondary text', 'Settings page', 'bp-better-messages' ),               'base' => _x( 'conversation secondary text', 'Settings page', 'bp-better-messages' ),      'default' => '107, 114, 128', 'dark' => '148, 163, 184' ),
        'icon'       => array( 'slug' => 'icon',       'shared' => '--bm-color-icon',                   'label' => _x( 'Icons', 'Settings page', 'bp-better-messages' ),                    'base' => _x( 'conversation icons', 'Settings page', 'bp-better-messages' ),           'default' => '107, 114, 128', 'dark' => '148, 163, 184' ),
        'border'     => array( 'slug' => 'border',     'shared' => '--bm-color-border',                 'label' => _x( 'Border', 'Settings page', 'bp-better-messages' ),                   'base' => _x( 'conversation border', 'Settings page', 'bp-better-messages' ),          'default' => '229, 231, 235', 'dark' => '51, 65, 95' ),
        'field'      => array( 'slug' => 'field',      'shared' => '--bm-color-bg-secondary',           'label' => _x( 'Fields', 'Settings page', 'bp-better-messages' ),                   'base' => _x( 'conversation fields', 'Settings page', 'bp-better-messages' ),          'default' => '249, 250, 251', 'dark' => '17, 25, 47' ),
        'accent'     => array( 'slug' => 'accent',     'shared' => '--bm-color-accent',                 'label' => _x( 'Accent', 'Settings page', 'bp-better-messages' ),                   'base' => _x( 'accent', 'Settings page', 'bp-better-messages' ),                       'default' => '37, 99, 235',   'dark' => '96, 165, 250' ),
        'accentText' => array( 'slug' => 'accent-text','shared' => '--bm-color-text-on-accent',         'label' => _x( 'Accent text', 'Settings page', 'bp-better-messages' ),              'base' => _x( 'text on accent', 'Settings page', 'bp-better-messages' ),               'default' => '255, 255, 255', 'dark' => '255, 255, 255' ),
        'selfBg'     => array( 'slug' => 'self-bg',    'shared' => '--bm-color-bubble-self-bg',         'label' => _x( 'Own message background', 'Settings page', 'bp-better-messages' ),   'base' => _x( 'own messages background', 'Settings page', 'bp-better-messages' ),      'default' => '37, 99, 235',   'dark' => '37, 99, 235' ),
        'selfText'   => array( 'slug' => 'self-text',  'shared' => '--bm-color-bubble-self-text',       'label' => _x( 'Own message text', 'Settings page', 'bp-better-messages' ),         'base' => _x( 'own messages text', 'Settings page', 'bp-better-messages' ),            'default' => '255, 255, 255', 'dark' => '255, 255, 255' ),
        'selfName'   => array( 'slug' => 'self-name',  'shared' => '--bm-color-bubble-self-nickname',   'label' => _x( 'Own message name', 'Settings page', 'bp-better-messages' ),         'base' => _x( 'own messages name', 'Settings page', 'bp-better-messages' ),            'default' => '55, 65, 81',    'dark' => '203, 213, 225' ),
        'otherBg'    => array( 'slug' => 'other-bg',   'shared' => '--bm-color-bubble-other-bg',        'label' => _x( 'Other message background', 'Settings page', 'bp-better-messages' ), 'base' => _x( 'other messages background', 'Settings page', 'bp-better-messages' ),    'default' => '243, 244, 246', 'dark' => '38, 50, 82' ),
        'otherText'  => array( 'slug' => 'other-text', 'shared' => '--bm-color-bubble-other-text',      'label' => _x( 'Other message text', 'Settings page', 'bp-better-messages' ),       'base' => _x( 'other messages text', 'Settings page', 'bp-better-messages' ),          'default' => '17, 24, 39',    'dark' => '241, 245, 249' ),
        'otherName'  => array( 'slug' => 'other-name', 'shared' => '--bm-color-bubble-other-nickname',  'label' => _x( 'Other message name', 'Settings page', 'bp-better-messages' ),       'base' => _x( 'other messages name', 'Settings page', 'bp-better-messages' ),          'default' => '55, 65, 81',    'dark' => '203, 213, 225' ),
    );
    return $parts;
}

function better_messages_design_zones() {
    static $zones = null;
    if ( null !== $zones ) {
        return $zones;
    }
    $head  = array( 'bg', 'text', 'muted', 'border' );
    $reply = array( 'bg', 'text', 'muted', 'border', 'accent', 'accentText' );
    $list  = array( 'bg', 'text', 'preview', 'muted', 'icon', 'border', 'field', 'accent', 'accentText' );
    $area  = array( 'bg', 'text', 'muted', 'border', 'selfBg', 'selfText', 'selfName', 'otherBg', 'otherText', 'otherName' );
    $tabs  = array( 'bg', 'icon', 'border', 'accent', 'accentText' );
    $zones = array(
        'header'    => array( 'prefix' => 'header',    'parts' => $head,  'solid' => true,  'follows' => '',         'label' => _x( 'Conversation header', 'Settings page', 'bp-better-messages' ) ),
        'reply'     => array( 'prefix' => 'reply',     'parts' => $reply, 'solid' => true,  'follows' => '',         'label' => _x( 'Reply area', 'Settings page', 'bp-better-messages' ) ),
        'secondary' => array( 'prefix' => 'inbox',     'parts' => $list,  'list'  => true,  'follows' => '',         'label' => _x( 'Side conversations list', 'Settings page', 'bp-better-messages' ) ),
        'list'      => array( 'prefix' => 'list',      'parts' => $list,  'list'  => true,  'follows' => '',         'label' => _x( 'Conversations list', 'Settings page', 'bp-better-messages' ) ),
        'mc-head'   => array( 'prefix' => 'mc-head',   'parts' => $head,  'solid' => true,  'follows' => 'header',   'label' => _x( 'Mini chat header', 'Settings page', 'bp-better-messages' ) ),
        'mc'        => array( 'prefix' => 'mc',        'parts' => $area,  'solid' => true,  'follows' => '',         'label' => _x( 'Mini chat messages', 'Settings page', 'bp-better-messages' ) ),
        'mc-reply'  => array( 'prefix' => 'mc-reply',  'parts' => $reply, 'solid' => true,  'follows' => 'reply',    'label' => _x( 'Mini chat reply area', 'Settings page', 'bp-better-messages' ) ),
        'dock'      => array( 'prefix' => 'dock',      'parts' => $reply, 'solid' => true,  'follows' => '',         'label' => _x( 'Widget bar', 'Settings page', 'bp-better-messages' ) ),
        'wlist'     => array( 'prefix' => 'wlist',     'parts' => $list,  'list'  => true,  'follows' => 'list',     'label' => _x( 'Widget list', 'Settings page', 'bp-better-messages' ) ),
        'mob-head'  => array( 'prefix' => 'mob-head',  'parts' => $head,  'solid' => true,  'follows' => 'header',   'label' => _x( 'Mobile header', 'Settings page', 'bp-better-messages' ) ),
        'mob'       => array( 'prefix' => 'mob',       'parts' => $area,  'solid' => true,  'follows' => '',         'label' => _x( 'Mobile messages', 'Settings page', 'bp-better-messages' ) ),
        'mob-reply' => array( 'prefix' => 'mob-reply', 'parts' => $reply, 'solid' => true,  'follows' => 'reply',    'label' => _x( 'Mobile reply area', 'Settings page', 'bp-better-messages' ) ),
        'mob-list'  => array( 'prefix' => 'mob-list',  'parts' => $list, 'list'  => true,  'follows' => 'list',     'label' => _x( 'Mobile list', 'Settings page', 'bp-better-messages' ) ),
        'mob-tabs'  => array( 'prefix' => 'mob-tabs',  'parts' => $tabs, 'list'  => true,  'follows' => 'mob-list', 'label' => _x( 'Mobile tab bar', 'Settings page', 'bp-better-messages' ) ),
    );
    return $zones;
}

function better_messages_design_zone_token( $zone_name, $part ) {
    $zones = better_messages_design_zones();
    $parts = better_messages_design_zone_parts();
    if ( ! isset( $zones[ $zone_name ], $parts[ $part ] ) ) {
        return '';
    }
    return '--bm-color-' . $zones[ $zone_name ]['prefix'] . '-' . $parts[ $part ]['slug'];
}

function better_messages_design_zone_controls( $zone_name, $preview = array() ) {
    $zones  = better_messages_design_zones();
    $parts  = better_messages_design_zone_parts();
    $zone   = $zones[ $zone_name ];
    $parent = ( ! empty( $zone['follows'] ) && isset( $zones[ $zone['follows'] ] ) ) ? $zones[ $zone['follows'] ] : null;
    $out    = array();
    foreach ( $zone['parts'] as $part ) {
        $spec  = $parts[ $part ];
        $id    = better_messages_design_zone_token( $zone_name, $part );
        $via   = ( $parent && in_array( $part, $parent['parts'], true ) ) ? better_messages_design_zone_token( $zone['follows'], $part ) : $spec['shared'];
        $what  = $parent ? strtolower( $parent['label'] ) . ' ' . strtolower( $spec['label'] ) : $spec['base'];
        $stock = array( 'default' => $spec['default'], 'dark' => $spec['dark'] );
        if ( 'field' === $part && ! empty( $zone['list'] ) ) {
            $stock = array( 'default' => '255, 255, 255', 'dark' => '15, 23, 42' );
        }
        if ( 'accentText' === $part ) {
            $accent = better_messages_design_zone_token( $zone_name, 'accent' );
            $out[] = array(
                'id'      => $id . '-auto',
                'hidden'  => true,
                'kind'    => 'color',
                'source'  => 'token',
                'label'   => $zone['label'] . ' accent text (automatic)',
                'default' => $stock['default'],
                'dark'    => $stock['dark'],
                'formula' => array( 'readable', $accent, '255, 255, 255', '17, 24, 39', 3 ),
            );
            $out[] = array_merge( array(
                'id'      => $id,
                'kind'    => 'color',
                'source'  => 'token',
                'label'   => $spec['label'],
                'default' => $stock['default'],
                'dark'    => $stock['dark'],
                'help'    => sprintf( _x( 'Follows the %s while it reads on this accent, else white or dark, until you set it', 'Settings page', 'bp-better-messages' ), $what ),
                'formula' => array( 'readable', $accent, $via, $id . '-auto', 3 ),
            ), $preview );
            continue;
        }
        $out[] = array_merge( array(
            'id'      => $id,
            'kind'    => 'color',
            'source'  => 'token',
            'label'   => $spec['label'],
            'default' => $stock['default'],
            'dark'    => $stock['dark'],
            'help'    => sprintf( _x( 'Follows the %s until you set it', 'Settings page', 'bp-better-messages' ), $what ),
            'formula' => array( 'same', $via ),
        ), $preview );
    }
    return $out;
}

function better_messages_design_widget_icon_controls() {
    $has_fluent_community = defined( 'FLUENT_COMMUNITY_PLUGIN_VERSION' );
    $widgets = array(
        array( 'key' => 'widgetIconMessages',  'placeholder' => 'conversations', 'label' => _x( 'Conversations', 'Settings page', 'bp-better-messages' ), 'available' => true ),
        array( 'key' => 'widgetIconFriends',   'placeholder' => 'friends',       'label' => _x( 'Friends', 'Settings page', 'bp-better-messages' ),       'available' => function_exists( 'friends_check_friendship' ) || class_exists( 'UM_Friends_API' ) || class_exists( 'PeepSoFriendsPlugin' ) ),
        array( 'key' => 'widgetIconGroups',    'placeholder' => 'groups',        'label' => _x( 'Groups', 'Settings page', 'bp-better-messages' ),        'available' => ( function_exists( 'bm_bp_is_active' ) && bm_bp_is_active( 'groups' ) ) || class_exists( 'UM_Groups' ) || class_exists( 'PeepSoGroupsPlugin' ) || class_exists( 'PeepSoGroup' ) || $has_fluent_community ),
        array( 'key' => 'widgetIconCourses',   'placeholder' => 'courses',       'label' => _x( 'Courses', 'Settings page', 'bp-better-messages' ),       'available' => class_exists( 'LearnPress' ) || defined( 'TUTOR_VERSION' ) || defined( 'LEARNDASH_VERSION' ) || defined( 'STM_LMS_VERSION' ) || ( $has_fluent_community && class_exists( 'FluentCommunity\\Modules\\Course\\Model\\Course' ) ) ),
        array( 'key' => 'widgetIconAIBots',    'placeholder' => 'ai-bots',       'label' => _x( 'AI Bots', 'Settings page', 'bp-better-messages' ),       'available' => post_type_exists( 'bm-ai-chat-bot' ) || class_exists( 'Better_Messages_AI' ) ),
        array( 'key' => 'widgetIconChatRooms', 'placeholder' => 'chat-rooms',    'label' => _x( 'Chat Rooms', 'Settings page', 'bp-better-messages' ),    'available' => true ),
        array( 'key' => 'widgetIconUsers',     'placeholder' => 'users',         'label' => _x( 'Users', 'Settings page', 'bp-better-messages' ),         'available' => true ),
    );
    $out = array();
    foreach ( $widgets as $widget ) {
        if ( empty( $widget['available'] ) ) {
            continue;
        }
        $out[] = array(
            'id'              => $widget['key'],
            'kind'            => 'icon',
            'source'          => 'setting',
            'scriptVar'       => $widget['key'],
            'label'           => $widget['label'],
            'default'         => '',
            'placeholderIcon' => $widget['placeholder'],
            'hidden'          => true,
        );
    }
    return $out;
}

function better_messages_design_schema() {
    static $schema = null;

    if ( null !== $schema ) {
        return $schema;
    }

    $follows_master = _x( 'Follows the master value until you move it', 'Settings page', 'bp-better-messages' );

    $schema = array(

        array(
            'id'          => 'colors',
            'label'       => _x( 'Colors', 'Settings page', 'bp-better-messages' ),
            'icon'        => 'art',
            'scoped'      => true,
            'surface'     => 'desktop',
            'description' => _x( 'Every color has a light and a dark value. Switch the preview between light and dark to edit each set. Each background comes with the text drawn on it, and everything else is blended from that pair. Mini chats, the mini widgets and the mobile view follow the messenger until you set their own', 'Settings page', 'bp-better-messages' ),
            'groups'      => array(

                array(
                    'label'    => _x( 'Color scheme', 'Settings page', 'bp-better-messages' ),
                    'controls' => array(
                        array(
                            'id'      => 'darkMode',
                            'kind'    => 'radio',
                            'source'  => 'setting',
                            'label'   => _x( 'Color scheme', 'Settings page', 'bp-better-messages' ),
                            'help'    => _x( 'Which set of colors visitors see. The messenger does not follow the device on its own, since the rest of the page would not', 'Settings page', 'bp-better-messages' ),
                            'default' => 'never',
                            'choices' => array(
                                array( 'value' => 'never',  'label' => _x( 'Light', 'Settings page', 'bp-better-messages' ) ),
                                array( 'value' => 'always', 'label' => _x( 'Dark', 'Settings page', 'bp-better-messages' ) ),
                            ),
                        ),
                    ),
                ),
            ),
            'tabs'        => array(
                array(
                    'id'      => 'messenger',
                    'label'   => _x( 'Messenger', 'Settings page', 'bp-better-messages' ),
                    'surface' => 'desktop',
                    'groups'  => array(
                    array(
                        'label'    => _x( 'Accent', 'Settings page', 'bp-better-messages' ),
                        'controls' => array(
                            array(
                                'id'      => '--bm-color-accent',
                                'kind'    => 'color',
                                'source'  => 'token',
                                'label'   => _x( 'Accent', 'Settings page', 'bp-better-messages' ),
                                'default' => '37, 99, 235',
                                'dark'    => '96, 165, 250',
                                'help'    => _x( 'Buttons, links, active states, unread badges and the send button', 'Settings page', 'bp-better-messages' ),
                            ),
                            array(
                                'id'      => '--bm-color-text-on-accent',
                                'global'  => true,
                                'kind'    => 'color',
                                'source'  => 'token',
                                'label'   => _x( 'Text on accent', 'Settings page', 'bp-better-messages' ),
                                'default' => '255, 255, 255',
                                'dark'    => '17, 24, 39',
                                'help'    => _x( 'Digits and labels drawn on the accent: unread badges, the send button. White while white reads on the accent, dark otherwise, until you set it', 'Settings page', 'bp-better-messages' ),
                                'formula' => array( 'readable', '--bm-color-accent', '255, 255, 255', '17, 24, 39', 3 ),
                            ),
                        ),
                    ),

                    array(
                        'label'       => _x( 'Conversation', 'Settings page', 'bp-better-messages' ),
                        'description' => _x( 'Secondary text, hover fills, field fills and subtle borders are blended from these', 'Settings page', 'bp-better-messages' ),
                        'controls'    => array(
                            array(
                                'id'      => '--bm-color-bg',
                                'kind'    => 'color',
                                'source'  => 'token',
                                'label'   => _x( 'Background', 'Settings page', 'bp-better-messages' ),
                                'default' => '255, 255, 255',
                                'dark'    => '15, 23, 42',
                                'help'    => _x( 'The conversation, the message input and the messenger frame', 'Settings page', 'bp-better-messages' ),
                            ),
                            array(
                                'id'      => '--bm-color-text-primary',
                                'kind'    => 'color',
                                'source'  => 'token',
                                'label'   => _x( 'Text', 'Settings page', 'bp-better-messages' ),
                                'default' => '17, 24, 39',
                                'dark'    => '241, 245, 249',
                            ),
                            array(
                                'id'      => '--bm-color-border',
                                'kind'    => 'color',
                                'source'  => 'token',
                                'label'   => _x( 'Border', 'Settings page', 'bp-better-messages' ),
                                'default' => '229, 231, 235',
                                'dark'    => '51, 65, 95',
                            ),
                            array(
                                'id'      => '--bm-color-bg-secondary',
                                'kind'    => 'color',
                                'source'  => 'token',
                                'label'   => _x( 'Fields', 'Settings page', 'bp-better-messages' ),
                                'default' => '249, 250, 251',
                                'dark'    => '17, 25, 47',
                                'help'    => _x( 'Search fields, inputs and pickers in the conversation. A light tint of the background until you set it', 'Settings page', 'bp-better-messages' ),
                                'formula' => array( 'mix', '--bm-color-bg', '--bm-color-text-primary', 0.02 ),
                            ),
                            array(
                                'id'      => '--bm-color-text-tertiary',
                                'kind'    => 'color',
                                'source'  => 'token',
                                'label'   => _x( 'Secondary text', 'Settings page', 'bp-better-messages' ),
                                'default' => '107, 114, 128',
                                'dark'    => '148, 163, 184',
                                'help'    => _x( 'Timestamps, subtitles, icons and placeholders. Halfway from the text to the background until you set it', 'Settings page', 'bp-better-messages' ),
                                'formula' => array( 'mix', '--bm-color-text-primary', '--bm-color-bg', 0.5 ),
                            ),
                        ),
                    ),

                    array(
                        'label'       => _x( 'Conversation header', 'Settings page', 'bp-better-messages' ),
                        'description' => _x( 'The bar above the messages: name, status, actions. Follows the conversation until you set it; the action icons sit between its text and its secondary text', 'Settings page', 'bp-better-messages' ),
                        'controls'    => better_messages_design_zone_controls( 'header', array( 'previewSurface' => 'desktop' ) ),
                    ),

                    array(
                        'label'       => _x( 'Reply area', 'Settings page', 'bp-better-messages' ),
                        'description' => _x( 'The message input: typed text, placeholder, the send button. Follows the conversation until you set it; the attach and emoji icons sit between its text and its secondary text', 'Settings page', 'bp-better-messages' ),
                        'controls'    => better_messages_design_zone_controls( 'reply', array( 'previewSurface' => 'desktop' ) ),
                    ),

                    array(
                        'label'       => _x( 'Side conversations list', 'Settings page', 'bp-better-messages' ),
                        'description' => _x( 'The list beside a conversation, the collapsed rail, the footer row and the conversation info panel. Secondary text, hover rows and the active row are blended from this pair', 'Settings page', 'bp-better-messages' ),
                        'controls'    => array(
                            array(
                                'id'      => '--bm-color-inbox-bg',
                                'kind'    => 'color',
                                'source'  => 'token',
                                'label'   => _x( 'Background', 'Settings page', 'bp-better-messages' ),
                                'default' => '249, 250, 251',
                                'dark'    => '17, 25, 47',
                                'help'    => _x( 'A tint that sets the side list off from the conversation', 'Settings page', 'bp-better-messages' ),
                                'previewSurface' => 'desktop',
                            ),
                            array(
                                'id'      => '--bm-color-inbox-text',
                                'kind'    => 'color',
                                'source'  => 'token',
                                'label'   => _x( 'Text', 'Settings page', 'bp-better-messages' ),
                                'default' => '17, 24, 39',
                                'dark'    => '241, 245, 249',
                                'help'    => _x( 'Names and previews in the side list. Follows the conversation text until you set it', 'Settings page', 'bp-better-messages' ),
                                'formula' => array( 'same', '--bm-color-text-primary' ),
                                'previewSurface' => 'desktop',
                            ),
                            array(
                                'id'      => '--bm-color-inbox-preview',
                                'kind'    => 'color',
                                'source'  => 'token',
                                'label'   => _x( 'Preview', 'Settings page', 'bp-better-messages' ),
                                'default' => '107, 114, 128',
                                'dark'    => '148, 163, 184',
                                'help'    => _x( 'The last message under each name, and drafts. Follows the secondary text until you set it', 'Settings page', 'bp-better-messages' ),
                                'formula' => array( 'same', '--bm-color-inbox-muted' ),
                                'previewSurface' => 'desktop',
                            ),
                            array(
                                'id'      => '--bm-color-inbox-muted',
                                'kind'    => 'color',
                                'source'  => 'token',
                                'label'   => _x( 'Secondary text', 'Settings page', 'bp-better-messages' ),
                                'default' => '107, 114, 128',
                                'dark'    => '148, 163, 184',
                                'help'    => _x( 'Timestamps, labels and placeholders on the side list, and what the preview and icons follow. Halfway from the list text to its background until you set it', 'Settings page', 'bp-better-messages' ),
                                'formula' => array( 'mix', '--bm-color-inbox-text', '--bm-color-inbox-bg', 0.5 ),
                                'previewSurface' => 'desktop',
                            ),
                            array(
                                'id'      => '--bm-color-inbox-icon',
                                'kind'    => 'color',
                                'source'  => 'token',
                                'label'   => _x( 'Icons', 'Settings page', 'bp-better-messages' ),
                                'default' => '107, 114, 128',
                                'dark'    => '148, 163, 184',
                                'help'    => _x( 'Tab icons, status ticks, flags and small glyphs. Follows the secondary text until you set it', 'Settings page', 'bp-better-messages' ),
                                'formula' => array( 'same', '--bm-color-inbox-muted' ),
                                'previewSurface' => 'desktop',
                            ),
                            array(
                                'id'      => '--bm-color-inbox-border',
                                'previewSurface' => 'desktop',
                                'kind'    => 'color',
                                'source'  => 'token',
                                'label'   => _x( 'Border', 'Settings page', 'bp-better-messages' ),
                                'default' => '229, 231, 235',
                                'dark'    => '51, 65, 95',
                                'help'    => _x( 'Follows the conversation border until you set it', 'Settings page', 'bp-better-messages' ),
                                'formula' => array( 'same', '--bm-color-border' ),
                            ),
                            array(
                                'id'      => '--bm-color-inbox-field',
                                'kind'    => 'color',
                                'source'  => 'token',
                                'label'   => _x( 'Fields', 'Settings page', 'bp-better-messages' ),
                                'default' => '255, 255, 255',
                                'dark'    => '15, 23, 42',
                                'help'    => _x( 'Search fields and inputs on the side list. Set off from the list background until you set it', 'Settings page', 'bp-better-messages' ),
                                'formula' => array( 'lift', '--bm-color-inbox-bg', 0.6 ),
                                'previewSurface' => 'desktop',
                            ),
                            array(
                                'id'      => '--bm-color-inbox-accent',
                                'kind'    => 'color',
                                'source'  => 'token',
                                'label'   => _x( 'Accent', 'Settings page', 'bp-better-messages' ),
                                'default' => '37, 99, 235',
                                'dark'    => '96, 165, 250',
                                'help'    => _x( 'Unread badges and times, the verified mark, the active row and tab. Follows the accent until you set it', 'Settings page', 'bp-better-messages' ),
                                'formula' => array( 'same', '--bm-color-accent' ),
                                'previewSurface' => 'desktop',
                            ),
                            array(
                                'id'      => '--bm-color-inbox-accent-text',
                                'kind'    => 'color',
                                'source'  => 'token',
                                'label'   => _x( 'Accent text', 'Settings page', 'bp-better-messages' ),
                                'default' => '255, 255, 255',
                                'dark'    => '255, 255, 255',
                                'help'    => _x( 'Digits on the unread badges. Follows the text on accent while it reads on this accent, else white or dark, until you set it', 'Settings page', 'bp-better-messages' ),
                                'formula' => array( 'readable', '--bm-color-inbox-accent', '--bm-color-text-on-accent', '--bm-color-inbox-accent-text-auto', 3 ),
                                'previewSurface' => 'desktop',
                            ),
                        ),
                    ),

                    array(
                        'label'       => _x( 'Conversations list', 'Settings page', 'bp-better-messages' ),
                        'description' => _x( 'The list on its own: on mobile, in a single-column messenger and in the mini widgets. Follows the conversation colors until you set one', 'Settings page', 'bp-better-messages' ),
                        'controls'    => array(
                            array(
                                'id'             => '--bm-color-list-bg',
                                'kind'           => 'color',
                                'source'         => 'token',
                                'label'          => _x( 'Background', 'Settings page', 'bp-better-messages' ),
                                'default'        => '255, 255, 255',
                                'dark'           => '15, 23, 42',
                                'help'           => _x( 'Follows the conversation background until you set it', 'Settings page', 'bp-better-messages' ),
                                'formula'        => array( 'same', '--bm-color-bg' ),
                                'previewSurface' => 'mobile',
                                'previewView'    => 'list',
                            ),
                            array(
                                'id'             => '--bm-color-list-text',
                                'kind'           => 'color',
                                'source'         => 'token',
                                'label'          => _x( 'Text', 'Settings page', 'bp-better-messages' ),
                                'default'        => '17, 24, 39',
                                'dark'           => '241, 245, 249',
                                'help'           => _x( 'Follows the conversation text until you set it', 'Settings page', 'bp-better-messages' ),
                                'formula'        => array( 'same', '--bm-color-text-primary' ),
                                'previewSurface' => 'mobile',
                                'previewView'    => 'list',
                            ),
                            array(
                                'id'      => '--bm-color-list-preview',
                                'kind'    => 'color',
                                'source'  => 'token',
                                'label'   => _x( 'Preview', 'Settings page', 'bp-better-messages' ),
                                'default' => '107, 114, 128',
                                'dark'    => '148, 163, 184',
                                'help'    => _x( 'The last message under each name, and drafts. Follows the secondary text until you set it', 'Settings page', 'bp-better-messages' ),
                                'formula' => array( 'same', '--bm-color-list-muted' ),
                                'previewSurface' => 'mobile',
                                'previewView'    => 'list',
                            ),
                            array(
                                'id'             => '--bm-color-list-muted',
                                'kind'           => 'color',
                                'source'         => 'token',
                                'label'          => _x( 'Secondary text', 'Settings page', 'bp-better-messages' ),
                                'default'        => '107, 114, 128',
                                'dark'           => '148, 163, 184',
                                'help'           => _x( 'Timestamps, labels and placeholders on the list, and what the preview and icons follow. Halfway from the list text to its background until you set it', 'Settings page', 'bp-better-messages' ),
                                'formula'        => array( 'mix', '--bm-color-list-text', '--bm-color-list-bg', 0.5 ),
                                'previewSurface' => 'mobile',
                                'previewView'    => 'list',
                            ),
                            array(
                                'id'      => '--bm-color-list-icon',
                                'kind'    => 'color',
                                'source'  => 'token',
                                'label'   => _x( 'Icons', 'Settings page', 'bp-better-messages' ),
                                'default' => '107, 114, 128',
                                'dark'    => '148, 163, 184',
                                'help'    => _x( 'Tab icons, status ticks, flags and small glyphs. Follows the secondary text until you set it', 'Settings page', 'bp-better-messages' ),
                                'formula' => array( 'same', '--bm-color-list-muted' ),
                                'previewSurface' => 'mobile',
                                'previewView'    => 'list',
                            ),
                            array(
                                'id'             => '--bm-color-list-border',
                                'kind'           => 'color',
                                'source'         => 'token',
                                'label'          => _x( 'Border', 'Settings page', 'bp-better-messages' ),
                                'default'        => '229, 231, 235',
                                'dark'           => '51, 65, 95',
                                'help'           => _x( 'Follows the conversation border until you set it', 'Settings page', 'bp-better-messages' ),
                                'formula'        => array( 'same', '--bm-color-border' ),
                                'previewSurface' => 'mobile',
                                'previewView'    => 'list',
                            ),
                            array(
                                'id'             => '--bm-color-list-field',
                                'kind'           => 'color',
                                'source'         => 'token',
                                'label'          => _x( 'Fields', 'Settings page', 'bp-better-messages' ),
                                'default'        => '255, 255, 255',
                                'dark'           => '15, 23, 42',
                                'help'           => _x( 'Search fields and inputs on the list. Set off from the list background until you set it', 'Settings page', 'bp-better-messages' ),
                                'formula'        => array( 'lift', '--bm-color-list-bg', 0.6 ),
                                'previewSurface' => 'mobile',
                                'previewView'    => 'list',
                            ),
                            array(
                                'id'             => '--bm-color-list-accent',
                                'kind'           => 'color',
                                'source'         => 'token',
                                'label'          => _x( 'Accent', 'Settings page', 'bp-better-messages' ),
                                'default'        => '37, 99, 235',
                                'dark'           => '96, 165, 250',
                                'help'           => _x( 'Unread badges and times, the verified mark, the active row and tab. Follows the accent until you set it', 'Settings page', 'bp-better-messages' ),
                                'formula'        => array( 'same', '--bm-color-accent' ),
                                'previewSurface' => 'mobile',
                                'previewView'    => 'list',
                            ),
                            array(
                                'id'             => '--bm-color-list-accent-text',
                                'kind'           => 'color',
                                'source'         => 'token',
                                'label'          => _x( 'Accent text', 'Settings page', 'bp-better-messages' ),
                                'default'        => '255, 255, 255',
                                'dark'           => '255, 255, 255',
                                'help'           => _x( 'Digits on the unread badges. Follows the text on accent while it reads on this accent, else white or dark, until you set it', 'Settings page', 'bp-better-messages' ),
                                'formula'        => array( 'readable', '--bm-color-list-accent', '--bm-color-text-on-accent', '--bm-color-list-accent-text-auto', 3 ),
                                'previewSurface' => 'mobile',
                                'previewView'    => 'list',
                            ),
                        ),
                    ),

                    array(
                        'label'    => _x( 'Own messages', 'Settings page', 'bp-better-messages' ),
                        'controls' => array(
                            array(
                                'id'      => '--bm-color-bubble-self-bg',
                                'kind'    => 'color',
                                'source'  => 'token',
                                'label'   => _x( 'Background', 'Settings page', 'bp-better-messages' ),
                                'default' => '37, 99, 235',
                                'dark'    => '37, 99, 235',
                            ),
                            array(
                                'id'      => '--bm-color-bubble-self-text',
                                'kind'    => 'color',
                                'source'  => 'token',
                                'label'   => _x( 'Text', 'Settings page', 'bp-better-messages' ),
                                'default' => '255, 255, 255',
                                'dark'    => '255, 255, 255',
                            ),
                            array(
                                'id'            => '--bm-color-bubble-self-nickname',
                                'kind'          => 'color',
                                'source'        => 'token',
                                'label'         => _x( 'Name', 'Settings page', 'bp-better-messages' ),
                                'default'       => '55, 65, 81',
                                'dark'          => '203, 213, 225',
                                'help'          => _x( 'Your name above your messages in group conversations. Follows the secondary text until you set it', 'Settings page', 'bp-better-messages' ),
                                'formula'       => array( 'same', '--bm-color-text-secondary' ),
                                'previewThread' => 'group',
                            ),
                        ),
                    ),

                    array(
                        'label'    => _x( 'Other messages', 'Settings page', 'bp-better-messages' ),
                        'controls' => array(
                            array(
                                'id'      => '--bm-color-bubble-other-bg',
                                'kind'    => 'color',
                                'source'  => 'token',
                                'label'   => _x( 'Background', 'Settings page', 'bp-better-messages' ),
                                'default' => '243, 244, 246',
                                'dark'    => '38, 50, 82',
                            ),
                            array(
                                'id'      => '--bm-color-bubble-other-text',
                                'kind'    => 'color',
                                'source'  => 'token',
                                'label'   => _x( 'Text', 'Settings page', 'bp-better-messages' ),
                                'default' => '17, 24, 39',
                                'dark'    => '241, 245, 249',
                            ),
                            array(
                                'id'            => '--bm-color-bubble-other-nickname',
                                'kind'          => 'color',
                                'source'        => 'token',
                                'label'         => _x( 'Name', 'Settings page', 'bp-better-messages' ),
                                'default'       => '55, 65, 81',
                                'dark'          => '203, 213, 225',
                                'help'          => _x( 'Sender names above their messages in group conversations. Follows the secondary text until you set it', 'Settings page', 'bp-better-messages' ),
                                'formula'       => array( 'same', '--bm-color-text-secondary' ),
                                'previewThread' => 'group',
                            ),
                        ),
                    ),

                    array(
                        'label'    => _x( 'Tooltips', 'Settings page', 'bp-better-messages' ),
                        'controls' => array(
                            array(
                                'id'      => '--bm-color-tooltip-bg',
                                'global'  => true,
                                'kind'    => 'color',
                                'source'  => 'token',
                                'label'   => _x( 'Background', 'Settings page', 'bp-better-messages' ),
                                'default' => '17, 24, 39',
                                'dark'    => '0, 0, 0',
                                'help'    => _x( 'Follows the conversation text until you set it', 'Settings page', 'bp-better-messages' ),
                                'formula' => array( 'same', '--bm-color-text-primary' ),
                            ),
                            array(
                                'id'      => '--bm-color-tooltip-text',
                                'global'  => true,
                                'kind'    => 'color',
                                'source'  => 'token',
                                'label'   => _x( 'Text', 'Settings page', 'bp-better-messages' ),
                                'default' => '255, 255, 255',
                                'dark'    => '255, 255, 255',
                                'help'    => _x( 'White while white reads on the tooltip background, dark otherwise, until you set it', 'Settings page', 'bp-better-messages' ),
                                'formula' => array( 'readable', '--bm-color-tooltip-bg', '255, 255, 255', '17, 24, 39', 3 ),
                            ),
                        ),
                    ),

                    array(
                        'label'    => _x( 'Date labels', 'Settings page', 'bp-better-messages' ),
                        'controls' => array(
                            array(
                                'id'      => '--bm-color-sticky-date-bg',
                                'kind'    => 'color',
                                'source'  => 'token',
                                'label'   => _x( 'Background', 'Settings page', 'bp-better-messages' ),
                                'default' => '17, 24, 39',
                                'dark'    => '24, 33, 56',
                                'help'    => _x( 'The pill between days in a conversation. Follows the conversation text until you set it', 'Settings page', 'bp-better-messages' ),
                                'formula' => array( 'same', '--bm-color-text-primary' ),
                            ),
                            array(
                                'id'      => '--bm-color-sticky-date-text',
                                'kind'    => 'color',
                                'source'  => 'token',
                                'label'   => _x( 'Text', 'Settings page', 'bp-better-messages' ),
                                'default' => '255, 255, 255',
                                'dark'    => '241, 245, 249',
                                'help'    => _x( 'White while white reads on the label background, dark otherwise, until you set it', 'Settings page', 'bp-better-messages' ),
                                'formula' => array( 'readable', '--bm-color-sticky-date-bg', '255, 255, 255', '17, 24, 39', 3 ),
                            ),
                        ),
                    ),
                    ),
                ),
                array(
                    'id'      => 'mini-chats',
                    'label'   => _x( 'Mini chats', 'Settings page', 'bp-better-messages' ),
                    'surface' => 'widgets',
                    'groups'  => array(
                    array(
                        'label'    => _x( 'Header', 'Settings page', 'bp-better-messages' ),
                        'controls' => better_messages_design_zone_controls( 'mc-head', array( 'previewSurface' => 'widgets' ) ),
                    ),
                    array(
                        'label'    => _x( 'Messages', 'Settings page', 'bp-better-messages' ),
                        'controls' => better_messages_design_zone_controls( 'mc', array( 'previewSurface' => 'widgets' ) ),
                    ),
                    array(
                        'label'    => _x( 'Reply area', 'Settings page', 'bp-better-messages' ),
                        'controls' => better_messages_design_zone_controls( 'mc-reply', array( 'previewSurface' => 'widgets' ) ),
                    ),
                    ),
                ),
                array(
                    'id'      => 'widgets',
                    'label'   => _x( 'Mini widgets', 'Settings page', 'bp-better-messages' ),
                    'surface' => 'widgets',
                    'groups'  => array(
                    array(
                        'label'    => _x( 'Widget bar', 'Settings page', 'bp-better-messages' ),
                        'controls' => better_messages_design_zone_controls( 'dock', array( 'previewSurface' => 'widgets' ) ),
                    ),
                    array(
                        'label'    => _x( 'Widget list', 'Settings page', 'bp-better-messages' ),
                        'controls' => better_messages_design_zone_controls( 'wlist', array( 'previewSurface' => 'widgets' ) ),
                    ),
                    array(
                        'label'       => _x( 'Bubble launcher', 'Settings page', 'bp-better-messages' ),
                        'appliesWhen' => array( 'miniWidgetsStyle' => array( 'bubble' ) ),
                        'appliesNote' => _x( 'Only with the Floating bubble style', 'Settings page', 'bp-better-messages' ),
                        'controls'    => array(
                            array(
                                'id'             => '--bm-color-bubble-button-bg',
                                'kind'           => 'color',
                                'source'         => 'token',
                                'label'          => _x( 'Background', 'Settings page', 'bp-better-messages' ),
                                'default'        => '37, 99, 235',
                                'dark'           => '17, 25, 47',
                                'help'           => _x( 'The round button itself. Follows the widget bar accent until you set it', 'Settings page', 'bp-better-messages' ),
                                'formula'        => array( 'same', '--bm-color-dock-accent' ),
                                'previewSurface' => 'widgets',
                            ),
                            array(
                                'id'      => '--bm-color-bubble-button-icon-auto',
                                'hidden'  => true,
                                'kind'    => 'color',
                                'source'  => 'token',
                                'label'   => 'Bubble launcher icon (automatic)',
                                'default' => '255, 255, 255',
                                'dark'    => '241, 245, 249',
                                'formula' => array( 'readable', '--bm-color-bubble-button-bg', '255, 255, 255', '17, 24, 39', 3 ),
                            ),
                            array(
                                'id'             => '--bm-color-bubble-button-icon',
                                'kind'           => 'color',
                                'source'         => 'token',
                                'label'          => _x( 'Icon', 'Settings page', 'bp-better-messages' ),
                                'default'        => '255, 255, 255',
                                'dark'           => '241, 245, 249',
                                'help'           => _x( 'Follows the widget bar accent text while it reads on the button, else white or dark, until you set it', 'Settings page', 'bp-better-messages' ),
                                'formula'        => array( 'readable', '--bm-color-bubble-button-bg', '--bm-color-dock-accent-text', '--bm-color-bubble-button-icon-auto', 3 ),
                                'previewSurface' => 'widgets',
                            ),
                            array(
                                'id'             => '--bm-color-bubble-button-border',
                                'kind'           => 'color',
                                'source'         => 'token',
                                'label'          => _x( 'Border', 'Settings page', 'bp-better-messages' ),
                                'default'        => '37, 99, 235',
                                'dark'           => '38, 50, 82',
                                'help'           => _x( 'The ring around the button. Follows its background, which leaves no ring to see, until you set it', 'Settings page', 'bp-better-messages' ),
                                'formula'        => array( 'same', '--bm-color-bubble-button-bg' ),
                                'previewSurface' => 'widgets',
                            ),
                            array(
                                'id'             => '--bm-color-bubble-button-badge-bg',
                                'kind'           => 'color',
                                'source'         => 'token',
                                'label'          => _x( 'Unread badge', 'Settings page', 'bp-better-messages' ),
                                'default'        => '220, 53, 69',
                                'dark'           => '220, 53, 69',
                                'help'           => _x( 'The pill with the unread count, on the button and on the chat heads', 'Settings page', 'bp-better-messages' ),
                                'previewSurface' => 'widgets',
                            ),
                            array(
                                'id'             => '--bm-color-bubble-button-badge-text',
                                'kind'           => 'color',
                                'source'         => 'token',
                                'label'          => _x( 'Badge text', 'Settings page', 'bp-better-messages' ),
                                'default'        => '255, 255, 255',
                                'dark'           => '255, 255, 255',
                                'help'           => _x( 'White while white reads on the badge, dark otherwise, until you set it', 'Settings page', 'bp-better-messages' ),
                                'formula'        => array( 'readable', '--bm-color-bubble-button-badge-bg', '255, 255, 255', '17, 24, 39', 3 ),
                                'previewSurface' => 'widgets',
                            ),
                        ),
                    ),
                    ),
                ),
                array(
                    'id'      => 'mobile',
                    'label'   => _x( 'Mobile', 'Settings page', 'bp-better-messages' ),
                    'surface' => 'mobile',
                    'groups'  => array(
                    array(
                        'label'    => _x( 'Header', 'Settings page', 'bp-better-messages' ),
                        'controls' => better_messages_design_zone_controls( 'mob-head', array( 'previewSurface' => 'mobile' ) ),
                    ),
                    array(
                        'label'    => _x( 'Messages', 'Settings page', 'bp-better-messages' ),
                        'controls' => better_messages_design_zone_controls( 'mob', array( 'previewSurface' => 'mobile' ) ),
                    ),
                    array(
                        'label'    => _x( 'Reply area', 'Settings page', 'bp-better-messages' ),
                        'controls' => better_messages_design_zone_controls( 'mob-reply', array( 'previewSurface' => 'mobile' ) ),
                    ),
                    array(
                        'label'    => _x( 'List', 'Settings page', 'bp-better-messages' ),
                        'controls' => better_messages_design_zone_controls( 'mob-list', array( 'previewSurface' => 'mobile', 'previewView' => 'list' ) ),
                    ),
                    array(
                        'label'    => _x( 'Tab bar', 'Settings page', 'bp-better-messages' ),
                        'controls' => better_messages_design_zone_controls( 'mob-tabs', array( 'previewSurface' => 'mobile', 'previewView' => 'list' ) ),
                    ),
                    ),
                ),
            ),
        ),

        array(
            'id'          => 'messages',
            'label'       => _x( 'Conversation', 'Settings page', 'bp-better-messages' ),
            'icon'        => 'format-chat',
            'surface'     => 'desktop',
            'description' => _x( 'How messages, avatars, timestamps and the conversation header look', 'Settings page', 'bp-better-messages' ),
            'groups'      => array(

                array(
                    'label'    => _x( 'Messages', 'Settings page', 'bp-better-messages' ),
                    'controls' => array(
                        array(
                            'id'      => 'bubbleFill',
                            'kind'    => 'cards',
                            'source'  => 'option',
                            'label'   => _x( 'Message design', 'Settings page', 'bp-better-messages' ),
                            'help'    => _x( 'For the flat list 2.0 called Standard, pick No bubbles and put everyone on one side', 'Settings page', 'bp-better-messages' ),
                            'default' => 'filled',
                            'choices' => array(
                                array( 'value' => 'filled',  'label' => _x( 'Bubbles', 'Settings page', 'bp-better-messages' ) ),
                                array( 'value' => 'outline', 'label' => _x( 'Outlined', 'Settings page', 'bp-better-messages' ) ),
                                array( 'value' => 'none',    'label' => _x( 'No bubbles', 'Settings page', 'bp-better-messages' ) ),
                            ),
                            'bodyClass' => 'bm-bubble-',
                        ),
                        array(
                            'id'      => 'messagesLayout',
                            'kind'    => 'radio',
                            'source'  => 'option',
                            'label'   => _x( 'Message side', 'Settings page', 'bp-better-messages' ),
                            'default' => 'default',
                            'choices' => array(
                                array( 'value' => 'default',  'label' => _x( 'Mine on the right', 'Settings page', 'bp-better-messages' ) ),
                                array( 'value' => 'reversed', 'label' => _x( 'Mine on the left', 'Settings page', 'bp-better-messages' ) ),
                                array( 'value' => 'single',   'label' => _x( 'All on one side', 'Settings page', 'bp-better-messages' ) ),
                            ),
                            'bodyClass' => 'bm-msg-layout-',
                        ),
                        array(
                            'id'      => '--bm-bubble-tail',
                            'kind'    => 'switch',
                            'source'  => 'token',
                            'label'   => _x( 'Pointing tail toward the avatar', 'Settings page', 'bp-better-messages' ),
                            'default' => '0',
                            'on'      => '1',
                            'off'     => '0',
                            'appliesWhen' => array( 'bubbleFill' => array( 'filled' ) ),
                            'appliesNote' => _x( 'Not used by the selected message design. Chat rooms that pick a bubble design in their own settings still follow it', 'Settings page', 'bp-better-messages' ),
                        ),
                        array(
                            'id'      => '--bm-radius-bubble',
                            'kind'    => 'slider',
                            'source'  => 'token',
                            'label'   => _x( 'Bubble corners', 'Settings page', 'bp-better-messages' ),
                            'default' => '14px',
                            'unit'    => 'px',
                            'min'     => 0,
                            'max'     => 30,
                            'step'    => 1,
                            'appliesWhen' => array( 'bubbleFill' => array( 'filled', 'outline' ) ),
                            'appliesNote' => _x( 'Not used by the selected message design. Chat rooms that pick a bubble design in their own settings still follow it', 'Settings page', 'bp-better-messages' ),
                        ),
                        array(
                            'id'      => '--bm-bubble-max-width',
                            'kind'    => 'slider',
                            'source'  => 'token',
                            'label'   => _x( 'Maximum bubble width', 'Settings page', 'bp-better-messages' ),
                            'help'    => _x( 'Room is always kept beside the bubble for the message actions', 'Settings page', 'bp-better-messages' ),
                            'default' => '70%',
                            'unit'    => '%',
                            'min'     => 40,
                            'max'     => 100,
                            'step'    => 1,
                            'appliesWhen' => array( 'bubbleFill' => array( 'filled', 'outline' ) ),
                            'appliesNote' => _x( 'Not used by the selected message design. Chat rooms that pick a bubble design in their own settings still follow it', 'Settings page', 'bp-better-messages' ),
                        ),
                    ),
                ),

                array(
                    'label'    => _x( 'Avatars', 'Settings page', 'bp-better-messages' ),
                    'controls' => array(
                        array(
                            'id'      => 'avatarsList',
                            'scriptVar' => 'avatars',
                            'kind'    => 'select',
                            'source'  => 'option',
                            'label'   => _x( 'Avatars beside messages', 'Settings page', 'bp-better-messages' ),
                            'help'    => _x( 'The sender avatar next to each group of messages, not the conversations list', 'Settings page', 'bp-better-messages' ),
                            'default' => 'show',
                            'choices' => array(
                                array( 'value' => 'show',         'label' => _x( 'Always show', 'Settings page', 'bp-better-messages' ) ),
                                array( 'value' => 'hide_private', 'label' => _x( 'Hide in private conversations', 'Settings page', 'bp-better-messages' ) ),
                                array( 'value' => 'hide_groups',  'label' => _x( 'Hide in group conversations', 'Settings page', 'bp-better-messages' ) ),
                                array( 'value' => 'hide',         'label' => _x( 'Always hide', 'Settings page', 'bp-better-messages' ) ),
                            ),
                        ),
                        array(
                            'id'      => 'showAvatarSelf',
                            'scriptVar' => 'avatarsSelf',
                            'kind'    => 'switch',
                            'source'  => 'option',
                            'label'   => _x( 'Avatar next to your own messages', 'Settings page', 'bp-better-messages' ),
                            'default' => true,
                        ),
                    ),
                ),

                array(
                    'label'    => _x( 'Timestamps and date labels', 'Settings page', 'bp-better-messages' ),
                    'controls' => array(
                        array(
                            'id'      => 'datePosition',
                            'scriptVar' => 'datePosition',
                            'kind'    => 'radio',
                            'source'  => 'option',
                            'label'   => _x( 'Timestamps', 'Settings page', 'bp-better-messages' ),
                            'default' => 'message',
                            'choices' => array(
                                array( 'value' => 'message', 'label' => _x( 'On every message', 'Settings page', 'bp-better-messages' ) ),
                                array( 'value' => 'stack',   'label' => _x( 'Once per group', 'Settings page', 'bp-better-messages' ) ),
                            ),
                        ),
                        array(
                            'id'      => 'timeFormat',
                            'scriptVar' => 'timeFormat',
                            'kind'    => 'radio',
                            'source'  => 'option',
                            'label'   => _x( 'Time format', 'Settings page', 'bp-better-messages' ),
                            'default' => '24',
                            'choices' => array(
                                array( 'value' => '24', 'label' => _x( '24-hour', 'Settings page', 'bp-better-messages' ) ),
                                array( 'value' => '12', 'label' => _x( '12-hour', 'Settings page', 'bp-better-messages' ) ),
                            ),
                        ),
                        array(
                            'id'        => 'dateEnabled',
                            'kind'      => 'switch',
                            'source'    => 'option',
                            'label'     => _x( 'Date labels between messages', 'Settings page', 'bp-better-messages' ),
                            'default'   => true,
                            'bodyClass' => 'bm-dates-',
                        ),
                        array(
                            'id'        => 'dateSticky',
                            'kind'      => 'switch',
                            'source'    => 'option',
                            'label'     => _x( 'Sticky date labels', 'Settings page', 'bp-better-messages' ),
                            'help'      => _x( 'The label for the day in view stays at the top of the messages while scrolling', 'Settings page', 'bp-better-messages' ),
                            'default'   => true,
                            'bodyClass' => 'bm-dates-sticky-',
                        ),
                        array(
                            'id'      => '--bm-radius-date-pill',
                            'kind'    => 'slider',
                            'source'  => 'token',
                            'label'   => _x( 'Date label corners', 'Settings page', 'bp-better-messages' ),
                            'default' => '16px',
                            'unit'    => 'px',
                            'min'     => 0,
                            'max'     => 30,
                            'step'    => 1,
                        ),
                    ),
                ),

                array(
                    'label'    => _x( 'Header', 'Settings page', 'bp-better-messages' ),
                    'controls' => array(
                        array(
                            'id'      => 'privateSubName',
                            'scriptVar' => 'subName',
                            'kind'    => 'select',
                            'source'  => 'option',
                            'label'   => _x( 'Subtitle in private conversations', 'Settings page', 'bp-better-messages' ),
                            'default' => 'online',
                            'choices' => array(
                                array( 'value' => 'online',  'label' => _x( 'Last seen / online', 'Settings page', 'bp-better-messages' ) ),
                                array( 'value' => 'subject', 'label' => _x( 'Conversation subject', 'Settings page', 'bp-better-messages' ) ),
                                array( 'value' => 'hide',    'label' => _x( 'Nothing', 'Settings page', 'bp-better-messages' ) ),
                            ),
                        ),
                        array(
                            'id'        => 'showAvatarGroup',
                            'previewThread' => 'group',
                            'kind'      => 'switch',
                            'source'    => 'option',
                            'label'     => _x( 'Group avatar', 'Settings page', 'bp-better-messages' ),
                            'help'      => _x( 'The avatar in the header of a group conversation', 'Settings page', 'bp-better-messages' ),
                            'default'   => true,
                            'bodyClass' => 'bm-group-avatar-',
                        ),
                        array(
                            'id'        => 'typingPosition',
                            'scriptVar' => 'typingPosition',
                            'kind'      => 'radio',
                            'source'    => 'option',
                            'label'     => _x( 'Typing indicator', 'Settings page', 'bp-better-messages' ),
                            'help'      => _x( 'Where the typing, recording and file sending line shows. Under the last message is where 1.0 kept it, in the line of sight of someone who is chatting', 'Settings page', 'bp-better-messages' ),
                            'default'   => 'header',
                            'choices'   => array(
                                array( 'value' => 'header', 'label' => _x( 'In the header', 'Settings page', 'bp-better-messages' ) ),
                                array( 'value' => 'list',   'label' => _x( 'Under the last message', 'Settings page', 'bp-better-messages' ) ),
                            ),
                        ),
                    ),
                ),
            ),
        ),

        array(
            'id'          => 'layout',
            'label'       => _x( 'Layout', 'Settings page', 'bp-better-messages' ),
            'icon'        => 'layout',
            'surface'     => 'desktop',
            'description' => _x( 'Spacing, corners, the size of the messenger and what it shows', 'Settings page', 'bp-better-messages' ),
            'groups'      => array(

                array(
                    'label'    => _x( 'Spacing', 'Settings page', 'bp-better-messages' ),
                    'controls' => array(
                        array(
                            'id'      => '--bm-density',
                            'kind'    => 'slider',
                            'source'  => 'token',
                            'label'   => _x( 'Spacing', 'Settings page', 'bp-better-messages' ),
                            'help'    => _x( 'Scales padding and gaps everywhere at once', 'Settings page', 'bp-better-messages' ),
                            'default' => '1',
                            'unit'    => '%',
                            'scale'   => 100,
                            'min'     => 70,
                            'max'     => 140,
                            'step'    => 5,
                        ),
                    ),
                ),

                array(
                    'label'       => _x( 'Corners', 'Settings page', 'bp-better-messages' ),
                    'description' => _x( 'Every corner without a slider of its own follows the master value. Bubbles, date labels and mini widgets have their own sliders in their sections', 'Settings page', 'bp-better-messages' ),
                    'controls'    => array(
                        array(
                            'id'      => '--bm-radius',
                            'kind'    => 'slider',
                            'source'  => 'token',
                            'label'   => _x( 'Master roundness', 'Settings page', 'bp-better-messages' ),
                            'help'    => _x( 'Menus, tooltips, badges and anything without its own slider', 'Settings page', 'bp-better-messages' ),
                            'default' => '8px',
                            'unit'    => 'px',
                            'min'     => 0,
                            'max'     => 24,
                            'step'    => 1,
                        ),
                        array(
                            'id'      => '--bm-radius-button',
                            'kind'    => 'slider',
                            'source'  => 'token',
                            'label'   => _x( 'Buttons', 'Settings page', 'bp-better-messages' ),
                            'help'    => $follows_master,
                            'derived' => 'var(--bm-radius-sm)',
                            'default' => '4px',
                            'unit'    => 'px',
                            'min'     => 0,
                            'max'     => 24,
                            'step'    => 1,
                        ),
                        array(
                            'id'      => '--bm-radius-input',
                            'kind'    => 'slider',
                            'source'  => 'token',
                            'label'   => _x( 'Inputs', 'Settings page', 'bp-better-messages' ),
                            'help'    => $follows_master,
                            'derived' => 'var(--bm-radius-sm)',
                            'default' => '4px',
                            'unit'    => 'px',
                            'min'     => 0,
                            'max'     => 24,
                            'step'    => 1,
                        ),
                        array(
                            'id'      => '--bm-radius-card',
                            'kind'    => 'slider',
                            'source'  => 'token',
                            'label'   => _x( 'Messenger frame', 'Settings page', 'bp-better-messages' ),
                            'help'    => $follows_master,
                            'derived' => 'var(--bm-radius-lg)',
                            'default' => '12px',
                            'unit'    => 'px',
                            'min'     => 0,
                            'max'     => 32,
                            'step'    => 1,
                        ),
                        array(
                            'id'      => '--bm-radius-panel',
                            'kind'    => 'slider',
                            'source'  => 'token',
                            'label'   => _x( 'Panels and popups', 'Settings page', 'bp-better-messages' ),
                            'help'    => _x( 'Emoji picker, mention list, camera and editor panels', 'Settings page', 'bp-better-messages' ),
                            'derived' => 'var(--bm-radius-lg)',
                            'default' => '12px',
                            'unit'    => 'px',
                            'min'     => 0,
                            'max'     => 32,
                            'step'    => 1,
                        ),
                        array(
                            'id'      => '--bm-radius-avatar',
                            'kind'    => 'slider',
                            'source'  => 'token',
                            'label'   => _x( 'Avatars', 'Settings page', 'bp-better-messages' ),
                            'help'    => _x( 'Every avatar in the messenger. 50% is a circle, 0 a square', 'Settings page', 'bp-better-messages' ),
                            'default' => '50%',
                            'unit'    => '%',
                            'min'     => 0,
                            'max'     => 50,
                            'step'    => 1,
                        ),
                    ),
                ),

                array(
                    'label'       => _x( 'Messenger size', 'Settings page', 'bp-better-messages' ),
                    'description' => _x( 'How tall the messenger is on the messages page. It is never taller than the window less the offset, whatever the maximum, unless the minimum demands it', 'Settings page', 'bp-better-messages' ),
                    'controls'    => array(
                        array(
                            'id'      => '--bm-viewport-offset',
                            'kind'    => 'slider',
                            'source'  => 'token',
                            'label'   => _x( 'Window height offset', 'Settings page', 'bp-better-messages' ),
                            'help'    => _x( 'Space taken by other fixed elements on the page, such as a sticky header, that the messenger should not overlap', 'Settings page', 'bp-better-messages' ),
                            'default' => '0px',
                            'unit'    => 'px',
                            'min'     => 0,
                            'max'     => 400,
                            'step'    => 4,
                        ),
                        array(
                            'id'      => '--bm-min-height',
                            'kind'    => 'slider',
                            'source'  => 'token',
                            'label'   => _x( 'Minimum height', 'Settings page', 'bp-better-messages' ),
                            'help'    => _x( 'The messenger is never shorter than this, even on a window too short for it', 'Settings page', 'bp-better-messages' ),
                            'default' => '450px',
                            'unit'    => 'px',
                            'min'     => 100,
                            'max'     => 1200,
                            'step'    => 10,
                        ),
                        array(
                            'id'        => 'messengerFill',
                            'kind'      => 'switch',
                            'source'    => 'option',
                            'label'     => _x( 'Fill the window', 'Settings page', 'bp-better-messages' ),
                            'help'      => _x( 'Take all the height the window offers, less the offset. Off, the messenger stops at the maximum below', 'Settings page', 'bp-better-messages' ),
                            'default'   => false,
                            'bodyClass' => 'bm-fill-window-',
                        ),
                        array(
                            'id'      => '--bm-max-height',
                            'kind'    => 'slider',
                            'source'  => 'token',
                            'label'   => _x( 'Maximum height', 'Settings page', 'bp-better-messages' ),
                            'help'    => _x( 'The messenger never grows taller than this', 'Settings page', 'bp-better-messages' ),
                            'default' => '650px',
                            'unit'    => 'px',
                            'min'     => 100,
                            'max'     => 2000,
                            'step'    => 10,
                            'appliesWhen' => array( 'messengerFill' => array( 'false' ) ),
                            'appliesNote' => _x( 'Not while the messenger fills the window', 'Settings page', 'bp-better-messages' ),
                        ),
                    ),
                ),

                array(
                    'label'       => _x( 'Side conversations list', 'Settings page', 'bp-better-messages' ),
                    'description' => _x( 'A persistent conversations list beside the open conversation, so members switch conversations without going back', 'Settings page', 'bp-better-messages' ),
                    'controls'    => array(
                        array(
                            'id'        => 'combinedView',
                            'kind'      => 'switch',
                            'source'    => 'setting',
                            'scriptVar' => 'combinedView',
                            'label'     => _x( 'Side conversations list', 'Settings page', 'bp-better-messages' ),
                            'default'   => '1',
                            'previewSurface' => 'desktop',
                        ),
                        array(
                            'id'      => '--bm-sidebar-width',
                            'previewSurface' => 'desktop',
                            'kind'    => 'slider',
                            'source'  => 'token',
                            'label'   => _x( 'Width', 'Settings page', 'bp-better-messages' ),
                            'help'    => _x( 'Also decides when the list collapses to a rail, unless breakpoints of its own are set below', 'Settings page', 'bp-better-messages' ),
                            'default' => max( 200, min( 520, (int) ( Better_Messages()->settings['sideThreadsWidth'] ?? 320 ) ?: 320 ) ) . 'px',
                            'unit'    => 'px',
                            'min'     => 200,
                            'max'     => 520,
                            'step'    => 4,
                            'appliesWhen' => array( 'combinedView' => array( '1' ) ),
                            'appliesNote' => _x( 'Only with the side list on', 'Settings page', 'bp-better-messages' ),
                        ),
                        array(
                            'id'        => 'sidebarCompactMode',
                            'kind'      => 'select',
                            'source'    => 'setting',
                            'scriptVar' => 'sidebarCompactMode',
                            'label'     => _x( 'Compact mode', 'Settings page', 'bp-better-messages' ),
                            'help'      => _x( 'How the list behaves when the messenger is narrow', 'Settings page', 'bp-better-messages' ),
                            'default'   => 'auto',
                            'choices'   => array(
                                array( 'value' => 'auto',            'label' => _x( 'Automatic, by the messenger width', 'Settings page', 'bp-better-messages' ) ),
                                array( 'value' => 'always_expanded', 'label' => _x( 'Always expanded, hidden when too narrow', 'Settings page', 'bp-better-messages' ) ),
                                array( 'value' => 'always_compact',  'label' => _x( 'Always compact, icons only', 'Settings page', 'bp-better-messages' ) ),
                            ),
                            'previewSurface' => 'desktop',
                            'appliesWhen' => array( 'combinedView' => array( '1' ) ),
                            'appliesNote' => _x( 'Only with the side list on', 'Settings page', 'bp-better-messages' ),
                        ),
                        array(
                            'id'        => 'sidebarUserToggle',
                            'kind'      => 'switch',
                            'source'    => 'setting',
                            'scriptVar' => 'sidebarUserToggle',
                            'label'     => _x( 'Let members collapse it', 'Settings page', 'bp-better-messages' ),
                            'help'      => _x( 'A toggle on the list border to collapse or expand it', 'Settings page', 'bp-better-messages' ),
                            'default'   => '1',
                            'previewSurface' => 'desktop',
                            'appliesWhen' => array( 'combinedView' => array( '1' ), 'sidebarCompactMode' => array( 'auto' ) ),
                            'appliesNote' => _x( 'Only in automatic mode', 'Settings page', 'bp-better-messages' ),
                        ),
                        array(
                            'id'        => 'sidebarCompactBreakpoint',
                            'kind'      => 'number',
                            'source'    => 'setting',
                            'scriptVar' => 'sidebarCompactBreakpoint',
                            'label'     => _x( 'Compact below', 'Settings page', 'bp-better-messages' ),
                            'help'      => _x( 'Messenger width below which the list collapses to a rail. 0 picks it from the list width', 'Settings page', 'bp-better-messages' ),
                            'default'   => '0',
                            'unit'      => 'px',
                            'min'       => 0,
                            'max'       => 3000,
                            'step'      => 1,
                            'previewSurface' => 'desktop',
                            'appliesWhen' => array( 'combinedView' => array( '1' ), 'sidebarCompactMode' => array( 'auto' ) ),
                            'appliesNote' => _x( 'Only in automatic mode', 'Settings page', 'bp-better-messages' ),
                        ),
                        array(
                            'id'        => 'sidebarHideBreakpoint',
                            'kind'      => 'number',
                            'source'    => 'setting',
                            'scriptVar' => 'sidebarHideBreakpoint',
                            'label'     => _x( 'Hide below', 'Settings page', 'bp-better-messages' ),
                            'help'      => _x( 'Messenger width below which the list is hidden entirely. 0 picks it automatically', 'Settings page', 'bp-better-messages' ),
                            'default'   => '0',
                            'unit'      => 'px',
                            'min'       => 0,
                            'max'       => 3000,
                            'step'      => 1,
                            'previewSurface' => 'desktop',
                            'appliesWhen' => array( 'combinedView' => array( '1' ) ),
                            'appliesNote' => _x( 'Only with the side list on', 'Settings page', 'bp-better-messages' ),
                        ),
                    ),
                ),

                array(
                    'label'       => _x( 'Side panel', 'Settings page', 'bp-better-messages' ),
                    'description' => _x( 'The vertical tab strip beside the side list, shown when more than one widget is on it. Drag to reorder: the first tab is the one the messenger opens on. The gear opens the widget\'s own page', 'Settings page', 'bp-better-messages' ),
                    'controls'    => array(
                        array(
                            'id'        => 'sidePanelTabsOrder',
                            'kind'      => 'widgets',
                            'context'   => 'side',
                            'source'    => 'setting',
                            'label'     => _x( 'Widgets on the side panel', 'Settings page', 'bp-better-messages' ),
                            'default'   => array(),
                            'previewSurface' => 'desktop',
                            'appliesWhen' => array( 'combinedView' => array( '1' ) ),
                            'appliesNote' => _x( 'Only with the side list on', 'Settings page', 'bp-better-messages' ),
                        ),
                        array(
                            'id'        => 'sidePanelIconsOnly',
                            'kind'      => 'switch',
                            'source'    => 'setting',
                            'scriptVar' => 'sidePanelIconsOnly',
                            'label'     => _x( 'Show only icons', 'Settings page', 'bp-better-messages' ),
                            'help'      => _x( 'Hide the tab labels. They appear as tooltips instead', 'Settings page', 'bp-better-messages' ),
                            'default'   => '0',
                            'previewSurface' => 'desktop',
                            'appliesWhen' => array( 'combinedView' => array( '1' ) ),
                            'appliesNote' => _x( 'Only with the side list on', 'Settings page', 'bp-better-messages' ),
                        ),
                        array(
                            'id'        => 'rememberLastTab',
                            'kind'      => 'switch',
                            'source'    => 'setting',
                            'scriptVar' => 'rememberLastTab',
                            'label'     => _x( 'Remember the last tab', 'Settings page', 'bp-better-messages' ),
                            'help'      => _x( 'Open the messenger on the tab each member used last instead of the first tab, on the mobile tab bar as well. A link to a conversation always opens the tab that conversation belongs to', 'Settings page', 'bp-better-messages' ),
                            'default'   => '0',
                            'previewSurface' => 'desktop',
                            'appliesWhen' => array( 'combinedView' => array( '1' ) ),
                            'appliesNote' => _x( 'Only with the side list on', 'Settings page', 'bp-better-messages' ),
                        ),
                    ),
                ),

                array(
                    'label'       => _x( 'Interface elements', 'Settings page', 'bp-better-messages' ),
                    'description' => _x( 'Buttons and screens of the messenger. A switch off removes the element for everyone', 'Settings page', 'bp-better-messages' ),
                    'controls'    => array(
                        array(
                            'id'        => 'desktopFullScreen',
                            'kind'      => 'switch',
                            'source'    => 'setting',
                            'scriptVar' => 'fullScreen',
                            'label'     => _x( 'Full screen button', 'Settings page', 'bp-better-messages' ),
                            'help'      => _x( 'A button to open the messenger full screen on desktop browsers', 'Settings page', 'bp-better-messages' ),
                            'default'   => '1',
                            'previewSurface' => 'desktop',
                        ),
                        array(
                            'id'        => 'disableSearch',
                            'kind'      => 'switch',
                            'source'    => 'setting',
                            'scriptVar' => 'search',
                            'invert'    => true,
                            'label'     => _x( 'Search', 'Settings page', 'bp-better-messages' ),
                            'help'      => _x( 'Search across conversations and messages', 'Settings page', 'bp-better-messages' ),
                            'default'   => '0',
                            'previewSurface' => 'desktop',
                        ),
                        array(
                            'id'        => 'disableFavoriteMessages',
                            'kind'      => 'switch',
                            'source'    => 'setting',
                            'scriptVar' => 'favorite',
                            'invert'    => true,
                            'label'     => _x( 'Favorite messages', 'Settings page', 'bp-better-messages' ),
                            'help'      => _x( 'The star on messages and the favorites page', 'Settings page', 'bp-better-messages' ),
                            'default'   => '0',
                            'previewSurface' => 'desktop',
                        ),
                        array(
                            'id'        => 'enableUnreadFilter',
                            'kind'      => 'switch',
                            'source'    => 'setting',
                            'scriptVar' => 'unreadFilter',
                            'label'     => _x( 'Unread filter', 'Settings page', 'bp-better-messages' ),
                            'help'      => _x( 'A button that narrows the list to unread conversations', 'Settings page', 'bp-better-messages' ),
                            'default'   => '0',
                            'previewSurface' => 'desktop',
                        ),
                        array(
                            'id'        => 'disableUserSettings',
                            'kind'      => 'switch',
                            'source'    => 'setting',
                            'scriptVar' => 'userSettings',
                            'invert'    => true,
                            'label'     => _x( 'User settings', 'Settings page', 'bp-better-messages' ),
                            'help'      => _x( 'The settings button, where members set their notifications and preferences', 'Settings page', 'bp-better-messages' ),
                            'default'   => '0',
                            'previewSurface' => 'desktop',
                        ),
                        array(
                            'id'        => 'disableNewThread',
                            'kind'      => 'switch',
                            'source'    => 'setting',
                            'scriptVar' => 'newThread',
                            'invert'    => true,
                            'label'     => _x( 'New conversation button', 'Settings page', 'bp-better-messages' ),
                            'help'      => _x( 'The button and screen for starting a conversation. Administrators always see it', 'Settings page', 'bp-better-messages' ),
                            'default'   => '0',
                            'previewSurface' => 'desktop',
                        ),
                        array(
                            'id'        => 'myProfileButton',
                            'kind'      => 'switch',
                            'source'    => 'setting',
                            'scriptVar' => 'myProfile',
                            'label'     => _x( 'My profile in the user menu', 'Settings page', 'bp-better-messages' ),
                            'help'      => _x( 'A link to the member\'s own profile in the user menu', 'Settings page', 'bp-better-messages' ),
                            'default'   => '1',
                            'previewSurface' => 'desktop',
                        ),
                        array(
                            'id'        => 'userStatuses',
                            'kind'      => 'switch',
                            'source'    => 'setting',
                            'scriptVar' => 'userStatuses',
                            'requires'  => 'websocket',
                            'label'     => _x( 'User statuses', 'Settings page', 'bp-better-messages' ),
                            'help'      => _x( 'Members can set themselves Online, Away or Do not disturb', 'Settings page', 'bp-better-messages' ),
                            'default'   => '0',
                            'previewSurface' => 'desktop',
                        ),
                    ),
                ),
            ),
        ),

        array(
            'id'          => 'typography',
            'label'       => _x( 'Typography', 'Settings page', 'bp-better-messages' ),
            'icon'        => 'editor-textcolor',
            'surface'     => 'desktop',
            'description' => _x( 'The messenger sets its own font so it reads the same on any theme, and follows the theme font when you ask it to', 'Settings page', 'bp-better-messages' ),
            'groups'      => array(
                array(
                    'controls' => array(
                        array(
                            'id'      => '--bm-messenger-font',
                            'kind'    => 'font',
                            'source'  => 'token',
                            'label'   => _x( 'Font family', 'Settings page', 'bp-better-messages' ),
                            'help'    => _x( 'These render on every device. For a web font your theme loads, such as Roboto or Inter, choose Custom and type its name', 'Settings page', 'bp-better-messages' ),
                            'default' => '-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", sans-serif',
                            'choices' => array(
                                array( 'value' => '-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", sans-serif', 'label' => _x( 'System', 'Settings page', 'bp-better-messages' ) ),
                                array( 'value' => 'Helvetica, Arial, sans-serif',                                                  'label' => _x( 'Classic sans-serif', 'Settings page', 'bp-better-messages' ) ),
                                array( 'value' => 'Georgia, "Times New Roman", serif',                                             'label' => _x( 'Serif', 'Settings page', 'bp-better-messages' ) ),
                                array( 'value' => '"SF Mono", Menlo, Consolas, monospace',                                         'label' => _x( 'Monospace', 'Settings page', 'bp-better-messages' ) ),
                                array( 'value' => 'inherit',                                                                       'label' => _x( 'Inherit from the theme', 'Settings page', 'bp-better-messages' ) ),
                            ),
                        ),
                        array(
                            'id'      => '--bm-font-family-mono',
                            'kind'    => 'font',
                            'source'  => 'token',
                            'label'   => _x( 'Monospace font', 'Settings page', 'bp-better-messages' ),
                            'help'    => _x( 'Timestamps, timers, file sizes and counters, where every digit needs the same width', 'Settings page', 'bp-better-messages' ),
                            'default' => '"SF Mono", Menlo, Consolas, monospace',
                            'choices' => array(
                                array( 'value' => '"SF Mono", Menlo, Consolas, monospace',      'label' => _x( 'System monospace', 'Settings page', 'bp-better-messages' ) ),
                                array( 'value' => '"Courier New", Courier, monospace',          'label' => _x( 'Courier', 'Settings page', 'bp-better-messages' ) ),
                                array( 'value' => 'var(--bm-messenger-font, inherit)',           'label' => _x( 'Same as the message text', 'Settings page', 'bp-better-messages' ) ),
                            ),
                        ),
                        array(
                            'id'      => '--bm-font-size-base',
                            'kind'    => 'slider',
                            'source'  => 'token',
                            'label'   => _x( 'Message text size', 'Settings page', 'bp-better-messages' ),
                            'default' => '15px',
                            'unit'    => 'px',
                            'min'     => 11,
                            'max'     => 22,
                            'step'    => 1,
                        ),
                        array(
                            'id'            => '--bm-font-size-name',
                            'kind'          => 'slider',
                            'source'        => 'token',
                            'label'         => _x( 'Sender name size', 'Settings page', 'bp-better-messages' ),
                            'help'          => _x( 'The name above a message in group conversations', 'Settings page', 'bp-better-messages' ),
                            'default'       => '12px',
                            'unit'          => 'px',
                            'min'           => 10,
                            'max'           => 18,
                            'step'          => 1,
                            'previewThread' => 'group',
                        ),
                        array(
                            'id'      => '--bm-font-size-x',
                            'kind'    => 'slider',
                            'source'  => 'token',
                            'label'   => _x( 'Interface text size', 'Settings page', 'bp-better-messages' ),
                            'help'    => _x( 'Scales every label, button and timestamp, leaving message text alone', 'Settings page', 'bp-better-messages' ),
                            'default' => '1',
                            'unit'    => '%',
                            'scale'   => 100,
                            'min'     => 80,
                            'max'     => 140,
                            'step'    => 5,
                        ),
                        array(
                            'id'      => '--bm-line-height-normal',
                            'kind'    => 'slider',
                            'source'  => 'token',
                            'label'   => _x( 'Line height', 'Settings page', 'bp-better-messages' ),
                            'help'    => _x( 'As a multiple of the text size', 'Settings page', 'bp-better-messages' ),
                            'default' => '1.45',
                            'unit'    => '',
                            'min'     => 1.1,
                            'max'     => 2,
                            'step'    => 0.05,
                        ),
                    ),
                ),
            ),
        ),

        array(
            'id'          => 'widgets',
            'label'       => _x( 'Mini widgets', 'Settings page', 'bp-better-messages' ),
            'icon'        => 'align-right',
            'surface'     => 'widgets',
            'description' => _x( 'The floating panels shown on every page of the site', 'Settings page', 'bp-better-messages' ),
            'groups'      => array(

                array(
                    'label'    => _x( 'Placement', 'Settings page', 'bp-better-messages' ),
                    'controls' => array(
                        array(
                            'id'        => 'miniWidgetsStyle',
                            'kind'      => 'cards',
                            'source'    => 'setting',
                            'scriptVar' => 'miniWidgetsStyle',
                            'label'     => _x( 'Style', 'Settings page', 'bp-better-messages' ),
                            'help'      => _x( 'A tab bar fixed to the bottom of the screen, or a round button that expands into a panel', 'Settings page', 'bp-better-messages' ),
                            'default'   => 'classic',
                            'choices'   => array(
                                array( 'value' => 'classic', 'label' => _x( 'Classic bar', 'Settings page', 'bp-better-messages' ) ),
                                array( 'value' => 'bubble',  'label' => _x( 'Floating bubble', 'Settings page', 'bp-better-messages' ) ),
                            ),
                        ),
                        array(
                            'id'        => 'widgetsPosition',
                            'kind'      => 'radio',
                            'source'    => 'option',
                            'label'     => _x( 'Screen corner', 'Settings page', 'bp-better-messages' ),
                            'default'   => 'right',
                            'choices'   => array(
                                array( 'value' => 'left',  'label' => _x( 'Left', 'Settings page', 'bp-better-messages' ) ),
                                array( 'value' => 'right', 'label' => _x( 'Right', 'Settings page', 'bp-better-messages' ) ),
                            ),
                            'bodyClass' => 'bm-widgets-',
                        ),
                        array(
                            'id'      => '--bm-mini-widget-offset-x',
                            'kind'    => 'slider',
                            'source'  => 'token',
                            'label'   => _x( 'Distance from the side', 'Settings page', 'bp-better-messages' ),
                            'default' => '20px',
                            'unit'    => 'px',
                            'min'     => 0,
                            'max'     => 200,
                            'step'    => 1,
                        ),
                        array(
                            'id'      => '--bm-mini-widget-offset-y',
                            'kind'    => 'slider',
                            'source'  => 'token',
                            'label'   => _x( 'Distance from the bottom', 'Settings page', 'bp-better-messages' ),
                            'default' => '0px',
                            'unit'    => 'px',
                            'min'     => 0,
                            'max'     => 200,
                            'step'    => 1,
                        ),
                    ),
                ),

                array(
                    'label'       => _x( 'Widget bar', 'Settings page', 'bp-better-messages' ),
                    'description' => _x( 'Drag to reorder: the first tab is the one the bubble opens on. The gear opens the widget\'s own page. An icon picked here is used on the side panel and the mobile tab bar too', 'Settings page', 'bp-better-messages' ),
                    'controls'    => array_merge( array(
                        array(
                            'id'      => 'miniWidgetsOrder',
                            'kind'    => 'widgets',
                            'context' => 'mini',
                            'source'  => 'setting',
                            'label'   => _x( 'Widgets on the bar', 'Settings page', 'bp-better-messages' ),
                            'default' => array(),
                        ),
                    ), better_messages_design_widget_icon_controls(), array(
                        array(
                            'id'      => '--bm-mini-widget-width',
                            'kind'    => 'slider',
                            'source'  => 'token',
                            'label'   => _x( 'Width', 'Settings page', 'bp-better-messages' ),
                            'default' => '320px',
                            'unit'    => 'px',
                            'min'     => 240,
                            'max'     => 520,
                            'step'    => 4,
                        ),
                        array(
                            'id'      => '--bm-mini-widget-height',
                            'kind'    => 'slider',
                            'source'  => 'token',
                            'label'   => _x( 'Height', 'Settings page', 'bp-better-messages' ),
                            'help'    => _x( 'When a tab is open', 'Settings page', 'bp-better-messages' ),
                            'default' => '450px',
                            'unit'    => 'px',
                            'min'     => 300,
                            'max'     => 800,
                            'step'    => 10,
                        ),
                        array(
                            'id'      => '--bm-radius-mini-chat-button',
                            'kind'    => 'slider',
                            'source'  => 'token',
                            'label'   => _x( 'Bar and tab corners', 'Settings page', 'bp-better-messages' ),
                            'help'    => $follows_master,
                            'derived' => 'var(--bm-radius-md)',
                            'default' => '8px',
                            'unit'    => 'px',
                            'min'     => 0,
                            'max'     => 30,
                            'step'    => 1,
                        ),
                        array(
                            'id'        => 'miniWidgetsIconsOnly',
                            'kind'      => 'switch',
                            'source'    => 'setting',
                            'scriptVar' => 'miniWidgetsIconsOnly',
                            'bodyClass' => 'bm-widget-icons-',
                            'label'     => _x( 'Show only icons', 'Settings page', 'bp-better-messages' ),
                            'help'      => _x( 'Hide the tab labels. They appear as tooltips instead', 'Settings page', 'bp-better-messages' ),
                            'default'   => '0',
                        ),
                        array(
                            'id'        => 'enableMiniCloseButton',
                            'kind'      => 'switch',
                            'source'    => 'setting',
                            'scriptVar' => 'miniClose',
                            'label'     => _x( 'Close button', 'Settings page', 'bp-better-messages' ),
                            'help'      => _x( 'A close button on an expanded widget', 'Settings page', 'bp-better-messages' ),
                            'default'   => '0',
                        ),
                        array(
                            'id'        => 'miniWidgetsAnimation',
                            'kind'      => 'switch',
                            'source'    => 'setting',
                            'scriptVar' => 'miniWidgetsAnimation',
                            'label'     => _x( 'Animation', 'Settings page', 'bp-better-messages' ),
                            'help'      => _x( 'Animate widgets as they open and close', 'Settings page', 'bp-better-messages' ),
                            'default'   => '1',
                        ),
                        array(
                            'id'        => 'miniChatDisableSync',
                            'kind'      => 'switch',
                            'source'    => 'setting',
                            'scriptVar' => 'miniSync',
                            'invert'    => true,
                            'label'     => _x( 'Sync between browser tabs', 'Settings page', 'bp-better-messages' ),
                            'help'      => _x( 'Keep the widgets and mini chats in the same state in every tab of the browser', 'Settings page', 'bp-better-messages' ),
                            'default'   => '0',
                        ),
                    ) ),
                ),

                array(
                    'label'    => _x( 'Mini chat windows', 'Settings page', 'bp-better-messages' ),
                    'controls' => array(
                        array(
                            'id'        => 'miniChatsEnable',
                            'kind'      => 'switch',
                            'source'    => 'setting',
                            'scriptVar' => 'miniChats',
                            'boot'      => true,
                            'requires'  => 'websocket',
                            'label'     => _x( 'Mini chats', 'Settings page', 'bp-better-messages' ),
                            'help'      => _x( 'Small chat windows fixed to the bottom of the browser window when a member opens a conversation', 'Settings page', 'bp-better-messages' ),
                            'default'   => '0',
                        ),
                        array(
                            'id'        => 'combinedChatsEnable',
                            'kind'      => 'switch',
                            'source'    => 'setting',
                            'scriptVar' => 'combinedChats',
                            'boot'      => true,
                            'requires'  => 'websocket',
                            'label'     => _x( 'Combined mode', 'Settings page', 'bp-better-messages' ),
                            'help'      => _x( 'Open conversations inside the Conversations widget instead of separate windows', 'Settings page', 'bp-better-messages' ),
                            'default'   => '0',
                            'appliesWhen' => array( 'miniChatsEnable' => array( '1' ), 'miniThreadsEnable' => array( '1' ) ),
                            'appliesNote' => _x( 'Needs Mini chats and the Conversations widget on', 'Settings page', 'bp-better-messages' ),
                        ),
                        array(
                            'id'      => '--bm-mini-chat-width',
                            'kind'    => 'slider',
                            'source'  => 'token',
                            'label'   => _x( 'Width', 'Settings page', 'bp-better-messages' ),
                            'default' => '300px',
                            'unit'    => 'px',
                            'min'     => 240,
                            'max'     => 520,
                            'step'    => 4,
                            'appliesWhen' => array( 'combinedChatsEnable' => array( '0' ) ),
                            'appliesNote' => _x( 'In combined mode the window takes the mini widgets width and height', 'Settings page', 'bp-better-messages' ),
                        ),
                        array(
                            'id'      => '--bm-mini-chat-height',
                            'kind'    => 'slider',
                            'source'  => 'token',
                            'label'   => _x( 'Height', 'Settings page', 'bp-better-messages' ),
                            'default' => '450px',
                            'unit'    => 'px',
                            'min'     => 300,
                            'max'     => 800,
                            'step'    => 10,
                            'appliesWhen' => array( 'combinedChatsEnable' => array( '0' ) ),
                            'appliesNote' => _x( 'In combined mode the window takes the mini widgets width and height', 'Settings page', 'bp-better-messages' ),
                        ),
                        array(
                            'id'      => '--bm-radius-mini-widget',
                            'kind'    => 'slider',
                            'source'  => 'token',
                            'label'   => _x( 'Corners', 'Settings page', 'bp-better-messages' ),
                            'help'    => _x( 'The top corners while the window sits on the screen edge, all four once a distance from the bottom lifts it off', 'Settings page', 'bp-better-messages' ),
                            'default' => '12px',
                            'unit'    => 'px',
                            'min'     => 0,
                            'max'     => 30,
                            'step'    => 1,
                            'appliesWhen' => array( 'combinedChatsEnable' => array( '0' ) ),
                            'appliesNote' => _x( 'In combined mode the window takes the mini widgets corners', 'Settings page', 'bp-better-messages' ),
                        ),
                        array(
                            'id'        => 'miniChatsAvatars',
                            'scriptVar' => 'miniChatsAvatars',
                            'kind'      => 'switch',
                            'source'    => 'option',
                            'label'     => _x( 'Avatars in mini chats', 'Settings page', 'bp-better-messages' ),
                            'help'      => _x( 'The compact sender avatars beside messages inside mini chat windows', 'Settings page', 'bp-better-messages' ),
                            'default'   => true,
                        ),
                        array(
                            'id'        => 'miniChatAudioCall',
                            'kind'      => 'switch',
                            'source'    => 'setting',
                            'scriptVar' => 'miniAudio',
                            'requires'  => 'premium',
                            'label'     => _x( 'Audio call button', 'Settings page', 'bp-better-messages' ),
                            'help'      => _x( 'In the header of a private mini chat', 'Settings page', 'bp-better-messages' ),
                            'default'   => '0',
                        ),
                        array(
                            'id'        => 'miniChatVideoCall',
                            'kind'      => 'switch',
                            'source'    => 'setting',
                            'scriptVar' => 'miniVideo',
                            'requires'  => 'premium',
                            'label'     => _x( 'Video call button', 'Settings page', 'bp-better-messages' ),
                            'help'      => _x( 'In the header of a private mini chat', 'Settings page', 'bp-better-messages' ),
                            'default'   => '0',
                        ),
                        array(
                            'id'        => 'miniChatGroupAudioCall',
                            'kind'      => 'switch',
                            'source'    => 'setting',
                            'scriptVar' => 'miniGroupAudio',
                            'requires'  => 'premium',
                            'previewThread' => 'group',
                            'label'     => _x( 'Group audio call button', 'Settings page', 'bp-better-messages' ),
                            'help'      => _x( 'In the header of a group mini chat', 'Settings page', 'bp-better-messages' ),
                            'default'   => '0',
                        ),
                        array(
                            'id'        => 'miniChatGroupVideoCall',
                            'kind'      => 'switch',
                            'source'    => 'setting',
                            'scriptVar' => 'miniGroupVideo',
                            'requires'  => 'premium',
                            'previewThread' => 'group',
                            'label'     => _x( 'Group video call button', 'Settings page', 'bp-better-messages' ),
                            'help'      => _x( 'In the header of a group mini chat', 'Settings page', 'bp-better-messages' ),
                            'default'   => '0',
                        ),
                    ),
                ),

                array(
                    'label'       => _x( 'Bubble launcher', 'Settings page', 'bp-better-messages' ),
                    'description' => _x( 'The round button used when the style is Floating bubble. Its background, icon, border and unread badge have colors of their own, apart from the widget bar\'s.', 'Settings page', 'bp-better-messages' ),
                    'descriptionLink' => array(
                        'label' => _x( 'Set them in Colors → Mini widgets → Bubble launcher', 'Settings page', 'bp-better-messages' ),
                        'route' => '/appearance/colors/widgets?scrollTo=' . rawurlencode( '--bm-color-bubble-button-bg' ),
                    ),
                    'appliesWhen' => array( 'miniWidgetsStyle' => array( 'bubble' ) ),
                    'appliesNote' => _x( 'Only with the Floating bubble style', 'Settings page', 'bp-better-messages' ),
                    'controls'    => array(
                        array(
                            'id'        => 'bubbleIcon',
                            'kind'      => 'icon',
                            'source'    => 'setting',
                            'scriptVar' => 'bubbleIcon',
                            'label'     => _x( 'Icon', 'Settings page', 'bp-better-messages' ),
                            'default'   => 'comment',
                            'placeholderIcon' => 'comment',
                        ),
                        array(
                            'id'      => '--bm-bubble-size',
                            'kind'    => 'slider',
                            'source'  => 'token',
                            'label'   => _x( 'Size', 'Settings page', 'bp-better-messages' ),
                            'default' => '56px',
                            'unit'    => 'px',
                            'min'     => 36,
                            'max'     => 96,
                            'step'    => 1,
                        ),
                        array(
                            'id'      => '--bm-radius-bubble-button',
                            'kind'    => 'slider',
                            'source'  => 'token',
                            'label'   => _x( 'Roundness', 'Settings page', 'bp-better-messages' ),
                            'default' => '50%',
                            'unit'    => '%',
                            'min'     => 0,
                            'max'     => 50,
                            'step'    => 1,
                        ),
                        array(
                            'id'        => 'bubbleCloseOnOutside',
                            'kind'      => 'switch',
                            'source'    => 'setting',
                            'scriptVar' => 'bubbleCloseOnOutside',
                            'label'     => _x( 'Close on outside click', 'Settings page', 'bp-better-messages' ),
                            'help'      => _x( 'Close the panel when the page around it is clicked', 'Settings page', 'bp-better-messages' ),
                            'default'   => '0',
                        ),
                        array(
                            'id'        => 'bubbleChatHeads',
                            'kind'      => 'switch',
                            'source'    => 'setting',
                            'scriptVar' => 'bubbleChatHeads',
                            'requires'  => 'websocket',
                            'label'     => _x( 'Chat heads', 'Settings page', 'bp-better-messages' ),
                            'help'      => _x( 'Recently closed conversations as avatar bubbles above the button', 'Settings page', 'bp-better-messages' ),
                            'default'   => '0',
                            'appliesWhen' => array( 'miniChatsEnable' => array( '1' ) ),
                            'appliesNote' => _x( 'Needs Mini chats on', 'Settings page', 'bp-better-messages' ),
                        ),
                        array(
                            'id'        => 'bubbleChatHeadsLimit',
                            'kind'      => 'number',
                            'source'    => 'setting',
                            'scriptVar' => 'bubbleChatHeadsLimit',
                            'label'     => _x( 'Chat heads limit', 'Settings page', 'bp-better-messages' ),
                            'help'      => _x( 'How many chat heads to show at most', 'Settings page', 'bp-better-messages' ),
                            'default'   => '5',
                            'min'       => 1,
                            'max'       => 10,
                            'step'      => 1,
                            'appliesWhen' => array( 'bubbleChatHeads' => array( '1' ) ),
                            'appliesNote' => _x( 'Only with chat heads on', 'Settings page', 'bp-better-messages' ),
                        ),
                        array(
                            'id'      => '--bm-bubble-head-size',
                            'kind'    => 'slider',
                            'source'  => 'token',
                            'label'   => _x( 'Chat heads size', 'Settings page', 'bp-better-messages' ),
                            'default' => '50px',
                            'unit'    => 'px',
                            'min'     => 28,
                            'max'     => 72,
                            'step'    => 1,
                            'appliesWhen' => array( 'bubbleChatHeads' => array( '1' ) ),
                            'appliesNote' => _x( 'Only with chat heads on', 'Settings page', 'bp-better-messages' ),
                        ),
                    ),
                ),
            ),
        ),

        array(
            'id'          => 'mobile',
            'label'       => _x( 'Mobile', 'Settings page', 'bp-better-messages' ),
            'icon'        => 'smartphone',
            'surface'     => 'mobile',
            'description' => _x( 'How the messenger opens and what surrounds it on phones', 'Settings page', 'bp-better-messages' ),
            'groups'      => array(

                array(
                    'label'    => _x( 'Full screen', 'Settings page', 'bp-better-messages' ),
                    'controls' => array(
                        array(
                            'id'        => 'mobileFullScreen',
                            'kind'      => 'switch',
                            'source'    => 'setting',
                            'scriptVar' => 'mobileFullScreen',
                            'boot'      => true,
                            'label'     => _x( 'Full screen mode', 'Settings page', 'bp-better-messages' ),
                            'help'      => _x( 'Open the messages page in full screen on mobile devices', 'Settings page', 'bp-better-messages' ),
                            'default'   => '1',
                        ),
                        array(
                            'id'        => 'autoFullScreen',
                            'kind'      => 'switch',
                            'source'    => 'setting',
                            'scriptVar' => 'autoFullScreen',
                            'label'     => _x( 'Open full screen automatically', 'Settings page', 'bp-better-messages' ),
                            'help'      => _x( 'Enter full screen as soon as the messages page opens', 'Settings page', 'bp-better-messages' ),
                            'default'   => '1',
                            'appliesWhen' => array( 'mobileFullScreen' => array( '1' ) ),
                            'appliesNote' => _x( 'Only with full screen mode on', 'Settings page', 'bp-better-messages' ),
                        ),
                        array(
                            'id'        => 'tapToOpenMsg',
                            'kind'      => 'switch',
                            'source'    => 'setting',
                            'scriptVar' => 'tapToOpen',
                            'previewView' => 'page',
                            'label'     => _x( 'Tap to open prompt', 'Settings page', 'bp-better-messages' ),
                            'help'      => _x( 'A "Tap to open" prompt over the messenger while it sits in the page', 'Settings page', 'bp-better-messages' ),
                            'default'   => '1',
                            'appliesWhen' => array( 'mobileFullScreen' => array( '1' ) ),
                            'appliesNote' => _x( 'Only with full screen mode on', 'Settings page', 'bp-better-messages' ),
                        ),
                        array(
                            'id'        => 'mobileSwipeBack',
                            'kind'      => 'switch',
                            'source'    => 'setting',
                            'scriptVar' => 'mobileSwipeBack',
                            'label'     => _x( 'Swipe to go back', 'Settings page', 'bp-better-messages' ),
                            'help'      => _x( 'Swipe right to return to the conversations list', 'Settings page', 'bp-better-messages' ),
                            'default'   => '1',
                            'appliesWhen' => array( 'mobileFullScreen' => array( '1' ) ),
                            'appliesNote' => _x( 'Only with full screen mode on', 'Settings page', 'bp-better-messages' ),
                        ),
                        array(
                            'id'        => 'hidePossibleBreakingElements',
                            'kind'      => 'switch',
                            'source'    => 'setting',
                            'scriptVar' => 'hPBE',
                            'label'     => _x( 'Hide overlapping theme elements', 'Settings page', 'bp-better-messages' ),
                            'help'      => _x( 'Hide theme elements that may overlap the messenger in full screen', 'Settings page', 'bp-better-messages' ),
                            'default'   => '0',
                            'appliesWhen' => array( 'mobileFullScreen' => array( '1' ) ),
                            'appliesNote' => _x( 'Only with full screen mode on', 'Settings page', 'bp-better-messages' ),
                        ),
                    ),
                ),

                array(
                    'label'       => _x( 'Floating chat button', 'Settings page', 'bp-better-messages' ),
                    'description' => _x( 'A button on every page of the site that opens the messenger full screen', 'Settings page', 'bp-better-messages' ),
                    'controls'    => array(
                        array(
                            'id'        => 'mobilePopup',
                            'kind'      => 'switch',
                            'source'    => 'setting',
                            'bodyClass' => 'bm-mobile-button-',
                            'previewView' => 'page',
                            'label'     => _x( 'Floating chat button', 'Settings page', 'bp-better-messages' ),
                            'default'   => '0',
                        ),
                        array(
                            'id'        => 'mobilePopupLocation',
                            'kind'      => 'radio',
                            'source'    => 'setting',
                            'bodyClass' => 'bm-mobile-button-at-',
                            'previewView' => 'page',
                            'label'     => _x( 'Side', 'Settings page', 'bp-better-messages' ),
                            'default'   => 'right',
                            'choices'   => array(
                                array( 'value' => 'left',  'label' => _x( 'Left', 'Settings page', 'bp-better-messages' ) ),
                                array( 'value' => 'right', 'label' => _x( 'Right', 'Settings page', 'bp-better-messages' ) ),
                            ),
                            'appliesWhen' => array( 'mobilePopup' => array( '1' ) ),
                            'appliesNote' => _x( 'Only with the button on', 'Settings page', 'bp-better-messages' ),
                        ),
                        array(
                            'id'      => '--bm-mobile-button-bottom',
                            'kind'    => 'slider',
                            'source'  => 'token',
                            'previewView' => 'page',
                            'label'   => _x( 'Distance from the bottom', 'Settings page', 'bp-better-messages' ),
                            'default' => '20px',
                            'unit'    => 'px',
                            'min'     => 0,
                            'max'     => 200,
                            'step'    => 1,
                            'appliesWhen' => array( 'mobilePopup' => array( '1' ) ),
                            'appliesNote' => _x( 'Only with the button on', 'Settings page', 'bp-better-messages' ),
                        ),
                        array(
                            'id'        => 'mobileButtonIcon',
                            'kind'      => 'icon',
                            'source'    => 'setting',
                            'scriptVar' => 'mobileButtonIcon',
                            'previewView' => 'page',
                            'label'     => _x( 'Icon', 'Settings page', 'bp-better-messages' ),
                            'default'   => '',
                            'placeholderIcon' => 'comment',
                            'appliesWhen' => array( 'mobilePopup' => array( '1' ) ),
                            'appliesNote' => _x( 'Only with the button on', 'Settings page', 'bp-better-messages' ),
                        ),
                    ),
                ),

                array(
                    'label'       => _x( 'Tab bar', 'Settings page', 'bp-better-messages' ),
                    'description' => _x( 'The bottom bar of the full screen messenger. Drag to reorder: the first tab is the one the messenger opens on. The gear opens the widget\'s own page', 'Settings page', 'bp-better-messages' ),
                    'controls'    => array(
                        array(
                            'id'        => 'mobileTabsOrder',
                            'kind'      => 'widgets',
                            'context'   => 'mobile',
                            'source'    => 'setting',
                            'previewView' => 'list',
                            'label'     => _x( 'Tabs on the bar', 'Settings page', 'bp-better-messages' ),
                            'default'   => array(),
                        ),
                        array(
                            'id'        => 'mobileTabsIconsOnly',
                            'kind'      => 'switch',
                            'source'    => 'setting',
                            'scriptVar' => 'mobileTabsIconsOnly',
                            'previewView' => 'list',
                            'label'     => _x( 'Show only icons', 'Settings page', 'bp-better-messages' ),
                            'help'      => _x( 'Hide the tab labels of the bottom bar. They appear as tooltips instead', 'Settings page', 'bp-better-messages' ),
                            'default'   => '0',
                        ),
                    ),
                ),
            ),
        ),

        array(
            'id'          => 'derived',
            'label'       => 'Derived colors',
            'icon'        => 'art',
            'scoped'      => true,
            'hidden'      => true,
            'groups'      => array(
                array(
                    'controls' => array(
                        array(
                            'id'      => '--bm-color-accent-hover',
                            'hidden'  => true,
                            'kind'    => 'color',
                            'source'  => 'token',
                            'label'   => 'Accent hover',
                            'default' => '29, 78, 216',
                            'dark'    => '59, 130, 246',
                            'formula' => array( 'shade', '--bm-color-accent', 0.1 ),
                        ),
                        array(
                            'id'      => '--bm-color-accent-pressed',
                            'hidden'  => true,
                            'kind'    => 'color',
                            'source'  => 'token',
                            'label'   => 'Accent pressed',
                            'default' => '30, 64, 175',
                            'dark'    => '37, 99, 235',
                            'formula' => array( 'shade', '--bm-color-accent', 0.2 ),
                        ),
                        array(
                            'id'      => '--bm-color-inbox-accent-text-auto',
                            'hidden'  => true,
                            'kind'    => 'color',
                            'source'  => 'token',
                            'label'   => 'Side list accent text (automatic)',
                            'default' => '255, 255, 255',
                            'dark'    => '255, 255, 255',
                            'formula' => array( 'readable', '--bm-color-inbox-accent', '255, 255, 255', '17, 24, 39', 3 ),
                        ),
                        array(
                            'id'      => '--bm-color-list-accent-text-auto',
                            'hidden'  => true,
                            'kind'    => 'color',
                            'source'  => 'token',
                            'label'   => 'List accent text (automatic)',
                            'default' => '255, 255, 255',
                            'dark'    => '255, 255, 255',
                            'formula' => array( 'readable', '--bm-color-list-accent', '255, 255, 255', '17, 24, 39', 3 ),
                        ),
                        array(
                            'id'      => '--bm-color-icon',
                            'hidden'  => true,
                            'kind'    => 'color',
                            'source'  => 'token',
                            'label'   => 'Icons',
                            'default' => '107, 114, 128',
                            'dark'    => '148, 163, 184',
                            'formula' => array( 'same', '--bm-color-text-tertiary' ),
                        ),
                        array(
                            'id'      => '--bm-color-preview',
                            'hidden'  => true,
                            'kind'    => 'color',
                            'source'  => 'token',
                            'label'   => 'Preview',
                            'default' => '107, 114, 128',
                            'dark'    => '148, 163, 184',
                            'formula' => array( 'same', '--bm-color-text-tertiary' ),
                        ),
                        array(
                            'id'      => '--bm-color-preview-unread',
                            'hidden'  => true,
                            'kind'    => 'color',
                            'source'  => 'token',
                            'label'   => 'Unread preview',
                            'default' => '55, 65, 81',
                            'dark'    => '203, 213, 225',
                            'formula' => array( 'mix', '--bm-color-text-primary', '--bm-color-preview', 0.5 ),
                        ),
                        array(
                            'id'      => '--bm-color-bg-elevated',
                            'hidden'  => true,
                            'kind'    => 'color',
                            'source'  => 'token',
                            'label'   => 'Popups and menus',
                            'default' => '255, 255, 255',
                            'dark'    => '24, 33, 56',
                            'formula' => array( 'same', 'base:--bm-color-bg' ),
                        ),
                        array(
                            'id'      => '--bm-color-card-bg',
                            'hidden'  => true,
                            'kind'    => 'color',
                            'source'  => 'token',
                            'label'   => 'Messenger frame',
                            'default' => '255, 255, 255',
                            'dark'    => '15, 23, 42',
                            'formula' => array( 'same', 'base:--bm-color-bg' ),
                        ),
                        array(
                            'id'      => '--bm-color-card-border',
                            'hidden'  => true,
                            'kind'    => 'color',
                            'source'  => 'token',
                            'label'   => 'Frame border',
                            'default' => '229, 231, 235',
                            'dark'    => '51, 65, 95',
                            'formula' => array( 'same', '--bm-color-border' ),
                        ),
                        array(
                            'id'      => '--bm-color-surface',
                            'hidden'  => true,
                            'kind'    => 'color',
                            'source'  => 'token',
                            'label'   => 'Surface',
                            'default' => '255, 255, 255',
                            'dark'    => '15, 23, 42',
                            'formula' => array( 'same', '--bm-color-bg' ),
                        ),
                        array(
                            'id'      => '--bm-color-field-text-fallback',
                            'hidden'  => true,
                            'kind'    => 'color',
                            'source'  => 'token',
                            'label'   => 'Field text fallback',
                            'default' => '17, 24, 39',
                            'dark'    => '241, 245, 249',
                            'formula' => array( 'contrast', '--bm-color-bg-secondary', '17, 24, 39', '241, 245, 249' ),
                        ),
                        array(
                            'id'      => '--bm-color-field-text',
                            'hidden'  => true,
                            'kind'    => 'color',
                            'source'  => 'token',
                            'label'   => 'Field text',
                            'default' => '17, 24, 39',
                            'dark'    => '241, 245, 249',
                            'formula' => array( 'contrast', '--bm-color-bg-secondary', '--bm-color-text-primary', '--bm-color-field-text-fallback' ),
                        ),
                        array(
                            'id'      => '--bm-color-field-placeholder',
                            'hidden'  => true,
                            'kind'    => 'color',
                            'source'  => 'token',
                            'label'   => 'Field placeholder',
                            'default' => '107, 114, 128',
                            'dark'    => '148, 163, 184',
                            'formula' => array( 'mix', '--bm-color-field-text', '--bm-color-bg-secondary', 0.45 ),
                        ),
                        array(
                            'id'      => '--bm-color-chat-bg',
                            'hidden'  => true,
                            'kind'    => 'color',
                            'source'  => 'token',
                            'label'   => 'Messages area',
                            'default' => '255, 255, 255',
                            'dark'    => '15, 23, 42',
                            'formula' => array( 'same', 'base:--bm-color-bg' ),
                        ),
                        array(
                            'id'      => '--bm-color-composer-bg',
                            'hidden'  => true,
                            'kind'    => 'color',
                            'source'  => 'token',
                            'label'   => 'Message input',
                            'default' => '255, 255, 255',
                            'dark'    => '15, 23, 42',
                            'formula' => array( 'same', 'base:--bm-color-bg' ),
                        ),
                        array(
                            'id'      => '--bm-color-text-secondary',
                            'hidden'  => true,
                            'kind'    => 'color',
                            'source'  => 'token',
                            'label'   => 'Text blend',
                            'default' => '55, 65, 81',
                            'dark'    => '203, 213, 225',
                            'formula' => array( 'mix', '--bm-color-text-primary', '--bm-color-text-tertiary', 0.5 ),
                        ),
                        array(
                            'id'      => '--bm-color-border-subtle',
                            'hidden'  => true,
                            'kind'    => 'color',
                            'source'  => 'token',
                            'label'   => 'Border (subtle)',
                            'default' => '243, 244, 246',
                            'dark'    => '38, 50, 82',
                            'formula' => array( 'mix', '--bm-color-border', '--bm-color-bg', 0.5 ),
                        ),
                        array(
                            'id'      => '--bm-color-border-strong',
                            'hidden'  => true,
                            'kind'    => 'color',
                            'source'  => 'token',
                            'label'   => 'Focused input border',
                            'default' => '156, 163, 175',
                            'dark'    => '100, 116, 139',
                            'formula' => array( 'mix', '--bm-color-text-primary', '--bm-color-bg', 0.45 ),
                        ),
                        array(
                            'id'      => '--bm-color-bg-hover',
                            'hidden'  => true,
                            'kind'    => 'color',
                            'source'  => 'token',
                            'label'   => 'Hover background',
                            'default' => '243, 244, 246',
                            'dark'    => '30, 41, 70',
                            'formula' => array( 'mix', '--bm-color-bg', '--bm-color-text-primary', 0.05 ),
                        ),
                        array(
                            'id'      => '--bm-color-bg-active',
                            'hidden'  => true,
                            'kind'    => 'color',
                            'source'  => 'token',
                            'label'   => 'Pressed background',
                            'default' => '235, 240, 246',
                            'dark'    => '38, 46, 70',
                            'formula' => array( 'mix', '--bm-color-bg', '--bm-color-accent', 0.08 ),
                        ),
                        array(
                            'id'      => '--bm-color-mention-bg',
                            'hidden'  => true,
                            'kind'    => 'color',
                            'source'  => 'token',
                            'label'   => 'Mention background',
                            'default' => '219, 234, 254',
                            'dark'    => '63, 72, 95',
                            'formula' => array( 'mix', '--bm-color-bg', '--bm-color-accent', 0.15 ),
                        ),
                        array(
                            'id'      => '--bm-color-mention-text',
                            'hidden'  => true,
                            'kind'    => 'color',
                            'source'  => 'token',
                            'label'   => 'Mention text',
                            'default' => '30, 64, 175',
                            'dark'    => '255, 255, 255',
                            'formula' => array( 'contrast', '--bm-color-mention-bg', '--bm-color-accent-pressed', '255, 255, 255' ),
                        ),
                        array(
                            'id'      => '--bm-color-accent-subtle',
                            'hidden'  => true,
                            'kind'    => 'color',
                            'source'  => 'token',
                            'label'   => 'Active row',
                            'default' => '241, 245, 249',
                            'dark'    => '19, 37, 77',
                            'formula' => array( 'mix', '--bm-color-bg', '--bm-color-accent', 0.06 ),
                        ),
                        array(
                            'id'      => '--bm-color-text-disabled',
                            'hidden'  => true,
                            'kind'    => 'color',
                            'source'  => 'token',
                            'label'   => 'Disabled text',
                            'default' => '165, 171, 182',
                            'dark'    => '95, 105, 125',
                            'formula' => array( 'mix', '--bm-color-icon', '--bm-color-bg', 0.4 ),
                        ),
                        array(
                            'id'      => '--bm-color-text-link',
                            'hidden'  => true,
                            'kind'    => 'color',
                            'source'  => 'token',
                            'label'   => 'Links',
                            'default' => '37, 99, 235',
                            'dark'    => '96, 165, 250',
                            'formula' => array( 'same', '--bm-color-accent' ),
                        ),
                        array(
                            'id'      => '--bm-color-online',
                            'hidden'  => true,
                            'kind'    => 'color',
                            'source'  => 'token',
                            'label'   => 'Online',
                            'default' => '61, 165, 18',
                            'dark'    => '61, 165, 18',
                        ),
                        array(
                            'id'      => '--bm-color-away',
                            'hidden'  => true,
                            'kind'    => 'color',
                            'source'  => 'token',
                            'label'   => 'Away',
                            'default' => '245, 158, 11',
                            'dark'    => '245, 158, 11',
                        ),
                        array(
                            'id'      => '--bm-color-busy',
                            'hidden'  => true,
                            'kind'    => 'color',
                            'source'  => 'token',
                            'label'   => 'Do not disturb',
                            'default' => '220, 53, 69',
                            'dark'    => '220, 53, 69',
                        ),
                        array(
                            'id'      => '--bm-color-offline',
                            'hidden'  => true,
                            'kind'    => 'color',
                            'source'  => 'token',
                            'label'   => 'Offline',
                            'default' => '156, 163, 175',
                            'dark'    => '156, 163, 175',
                        ),
                        array(
                            'id'      => '--bm-color-recording',
                            'hidden'  => true,
                            'kind'    => 'color',
                            'source'  => 'token',
                            'label'   => 'Recording',
                            'default' => '232, 65, 65',
                            'dark'    => '232, 65, 65',
                        ),
                    ),
                ),
            ),
        ),
    );

    $schema = better_messages_design_schema_gate_sections( apply_filters( 'better_messages_design_schema', $schema ) );

    return $schema;
}

function better_messages_design_schema_gate_groups( $groups ) {
    foreach ( $groups as $g => $group ) {
        if ( empty( $group['appliesWhen'] ) || empty( $group['controls'] ) || ! is_array( $group['controls'] ) ) {
            continue;
        }
        foreach ( $group['controls'] as $c => $control ) {
            $groups[ $g ]['controls'][ $c ]['gateWhen'] = $group['appliesWhen'];
        }
    }

    return $groups;
}

function better_messages_design_schema_gate_sections( $schema ) {
    foreach ( $schema as $s => $section ) {
        if ( ! empty( $section['groups'] ) && is_array( $section['groups'] ) ) {
            $schema[ $s ]['groups'] = better_messages_design_schema_gate_groups( $section['groups'] );
        }
        if ( empty( $section['tabs'] ) || ! is_array( $section['tabs'] ) ) {
            continue;
        }
        foreach ( $section['tabs'] as $t => $tab ) {
            if ( ! empty( $tab['groups'] ) && is_array( $tab['groups'] ) ) {
                $schema[ $s ]['tabs'][ $t ]['groups'] = better_messages_design_schema_gate_groups( $tab['groups'] );
            }
        }
    }

    return $schema;
}

function better_messages_design_schema_controls() {
    static $flat = null;

    if ( null !== $flat ) {
        return $flat;
    }

    $flat = array();

    foreach ( better_messages_design_schema() as $section ) {
        $bundles = array();
        if ( ! empty( $section['groups'] ) && is_array( $section['groups'] ) ) {
            $bundles[] = array( 'tab' => '', 'groups' => $section['groups'] );
        }
        if ( ! empty( $section['tabs'] ) && is_array( $section['tabs'] ) ) {
            foreach ( $section['tabs'] as $tab ) {
                if ( ! empty( $tab['groups'] ) && is_array( $tab['groups'] ) ) {
                    $bundles[] = array( 'tab' => $tab['id'], 'groups' => $tab['groups'] );
                }
            }
        }
        foreach ( $bundles as $bundle ) {
            foreach ( $bundle['groups'] as $group ) {
                if ( empty( $group['controls'] ) || ! is_array( $group['controls'] ) ) {
                    continue;
                }
                foreach ( $group['controls'] as $control ) {
                    if ( empty( $control['id'] ) ) {
                        continue;
                    }
                    $control['section'] = $section['id'];
                    $control['tab']     = $bundle['tab'];
                    $flat[ $control['id'] ] = $control;
                }
            }
        }
    }

    return $flat;
}
