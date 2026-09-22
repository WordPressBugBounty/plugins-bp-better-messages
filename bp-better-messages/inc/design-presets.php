<?php
defined( 'ABSPATH' ) || exit;

function better_messages_design_shipped_presets() {
    static $presets = null;
    if ( null !== $presets ) {
        return apply_filters( 'better_messages_design_shipped_presets', $presets );
    }

    $presets = array(
        'graphite'  => array(
            'name'        => _x( 'Graphite', 'Settings page', 'bp-better-messages' ),
            'description' => _x( 'Ink on paper, with no color anywhere but the avatars', 'Settings page', 'bp-better-messages' ),
            'category'    => 'messenger-styles',
            'tokens'      => array(
                'light' => array(
                    '--bm-color-accent'             => '39, 39, 42',
                    '--bm-color-bg'                 => '255, 255, 255',
                    '--bm-color-text-primary'       => '24, 24, 27',
                    '--bm-color-border'             => '228, 228, 231',
                    '--bm-color-inbox-bg'           => '250, 250, 250',
                    '--bm-color-bubble-self-bg'     => '39, 39, 42',
                    '--bm-color-bubble-self-text'   => '250, 250, 250',
                    '--bm-color-bubble-other-bg'    => '244, 244, 245',
                    '--bm-color-bubble-other-text'  => '24, 24, 27',
                ),
                'dark'  => array(
                    '--bm-color-accent'             => '228, 228, 231',
                    '--bm-color-bg'                 => '24, 24, 27',
                    '--bm-color-text-primary'       => '240, 240, 243',
                    '--bm-color-border'             => '48, 48, 54',
                    '--bm-color-inbox-bg'           => '18, 18, 20',
                    '--bm-color-bubble-self-bg'     => '228, 228, 231',
                    '--bm-color-bubble-self-text'   => '24, 24, 27',
                    '--bm-color-bubble-other-bg'    => '45, 45, 50',
                    '--bm-color-bubble-other-text'  => '240, 240, 243',
                ),
            ),
        ),
        'evergreen' => array(
            'name'        => _x( 'Evergreen', 'Settings page', 'bp-better-messages' ),
            'description' => _x( 'Deep forest green over a softly tinted white', 'Settings page', 'bp-better-messages' ),
            'category'    => 'messenger-styles',
            'tokens'      => array(
                'light' => array(
                    '--bm-color-accent'             => '21, 115, 71',
                    '--bm-color-bg'                 => '255, 255, 255',
                    '--bm-color-text-primary'       => '19, 34, 26',
                    '--bm-color-border'             => '216, 229, 220',
                    '--bm-color-inbox-bg'           => '243, 248, 244',
                    '--bm-color-bubble-self-bg'     => '21, 115, 71',
                    '--bm-color-bubble-self-text'   => '255, 255, 255',
                    '--bm-color-bubble-other-bg'    => '237, 243, 238',
                    '--bm-color-bubble-other-text'  => '19, 34, 26',
                ),
                'dark'  => array(
                    '--bm-color-accent'             => '52, 183, 122',
                    '--bm-color-bg'                 => '13, 26, 20',
                    '--bm-color-text-primary'       => '226, 238, 230',
                    '--bm-color-border'             => '31, 54, 41',
                    '--bm-color-inbox-bg'           => '9, 19, 15',
                    '--bm-color-bubble-self-bg'     => '24, 100, 66',
                    '--bm-color-bubble-self-text'   => '233, 248, 239',
                    '--bm-color-bubble-other-bg'    => '27, 45, 35',
                    '--bm-color-bubble-other-text'  => '226, 238, 230',
                ),
            ),
        ),
        'sandstone' => array(
            'name'        => _x( 'Sandstone', 'Settings page', 'bp-better-messages' ),
            'description' => _x( 'Warm paper and clay, the one palette that leaves cool grays behind', 'Settings page', 'bp-better-messages' ),
            'category'    => 'messenger-styles',
            'tokens'      => array(
                'light' => array(
                    '--bm-color-accent'             => '176, 84, 42',
                    '--bm-color-bg'                 => '253, 250, 246',
                    '--bm-color-text-primary'       => '43, 34, 27',
                    '--bm-color-border'             => '232, 221, 208',
                    '--bm-color-inbox-bg'           => '247, 241, 232',
                    '--bm-color-bubble-self-bg'     => '176, 84, 42',
                    '--bm-color-bubble-self-text'   => '255, 248, 242',
                    '--bm-color-bubble-other-bg'    => '242, 235, 225',
                    '--bm-color-bubble-other-text'  => '43, 34, 27',
                ),
                'dark'  => array(
                    '--bm-color-accent'             => '226, 137, 84',
                    '--bm-color-bg'                 => '31, 26, 22',
                    '--bm-color-text-primary'       => '241, 232, 222',
                    '--bm-color-border'             => '59, 50, 42',
                    '--bm-color-inbox-bg'           => '24, 20, 17',
                    '--bm-color-bubble-self-bg'     => '138, 66, 33',
                    '--bm-color-bubble-self-text'   => '255, 242, 233',
                    '--bm-color-bubble-other-bg'    => '47, 40, 34',
                    '--bm-color-bubble-other-text'  => '241, 232, 222',
                ),
            ),
        ),
        'indigo'    => array(
            'name'        => _x( 'Indigo', 'Settings page', 'bp-better-messages' ),
            'description' => _x( 'A saturated indigo accent over cool near-white', 'Settings page', 'bp-better-messages' ),
            'category'    => 'messenger-styles',
            'tokens'      => array(
                'light' => array(
                    '--bm-color-accent'             => '84, 74, 232',
                    '--bm-color-bg'                 => '255, 255, 255',
                    '--bm-color-text-primary'       => '26, 26, 48',
                    '--bm-color-border'             => '226, 226, 240',
                    '--bm-color-inbox-bg'           => '246, 246, 252',
                    '--bm-color-bubble-self-bg'     => '84, 74, 232',
                    '--bm-color-bubble-self-text'   => '255, 255, 255',
                    '--bm-color-bubble-other-bg'    => '240, 240, 250',
                    '--bm-color-bubble-other-text'  => '26, 26, 48',
                ),
                'dark'  => array(
                    '--bm-color-accent'             => '124, 116, 248',
                    '--bm-color-text-on-accent'     => '17, 24, 39',
                    '--bm-color-bg'                 => '19, 19, 33',
                    '--bm-color-text-primary'       => '232, 232, 246',
                    '--bm-color-border'             => '44, 44, 70',
                    '--bm-color-inbox-bg'           => '13, 13, 25',
                    '--bm-color-bubble-self-bg'     => '80, 72, 206',
                    '--bm-color-bubble-self-text'   => '247, 246, 255',
                    '--bm-color-bubble-other-bg'    => '39, 39, 62',
                    '--bm-color-bubble-other-text'  => '232, 232, 246',
                ),
            ),
        ),
        'ocean'     => array(
            'name'        => _x( 'Ocean', 'Settings page', 'bp-better-messages' ),
            'description' => _x( 'Teal and sea glass, calm and cool', 'Settings page', 'bp-better-messages' ),
            'category'    => 'messenger-styles',
            'tokens'      => array(
                'light' => array(
                    '--bm-color-accent'             => '12, 124, 145',
                    '--bm-color-bg'                 => '255, 255, 255',
                    '--bm-color-text-primary'       => '15, 38, 45',
                    '--bm-color-border'             => '211, 228, 232',
                    '--bm-color-inbox-bg'           => '239, 247, 249',
                    '--bm-color-bubble-self-bg'     => '12, 124, 145',
                    '--bm-color-bubble-self-text'   => '255, 255, 255',
                    '--bm-color-bubble-other-bg'    => '234, 243, 246',
                    '--bm-color-bubble-other-text'  => '15, 38, 45',
                ),
                'dark'  => array(
                    '--bm-color-accent'             => '56, 188, 210',
                    '--bm-color-bg'                 => '10, 27, 32',
                    '--bm-color-text-primary'       => '223, 240, 244',
                    '--bm-color-border'             => '26, 53, 61',
                    '--bm-color-inbox-bg'           => '7, 20, 24',
                    '--bm-color-bubble-self-bg'     => '13, 100, 118',
                    '--bm-color-bubble-self-text'   => '232, 249, 252',
                    '--bm-color-bubble-other-bg'    => '21, 45, 53',
                    '--bm-color-bubble-other-text'  => '223, 240, 244',
                ),
            ),
        ),
        'rose'      => array(
            'name'        => _x( 'Rose', 'Settings page', 'bp-better-messages' ),
            'description' => _x( 'A deep rose accent with a blush-tinted conversation list', 'Settings page', 'bp-better-messages' ),
            'category'    => 'messenger-styles',
            'tokens'      => array(
                'light' => array(
                    '--bm-color-accent'             => '197, 45, 106',
                    '--bm-color-bg'                 => '255, 255, 255',
                    '--bm-color-text-primary'       => '47, 24, 34',
                    '--bm-color-border'             => '240, 222, 230',
                    '--bm-color-inbox-bg'           => '253, 244, 248',
                    '--bm-color-bubble-self-bg'     => '197, 45, 106',
                    '--bm-color-bubble-self-text'   => '255, 245, 250',
                    '--bm-color-bubble-other-bg'    => '248, 238, 243',
                    '--bm-color-bubble-other-text'  => '47, 24, 34',
                ),
                'dark'  => array(
                    '--bm-color-accent'             => '247, 130, 178',
                    '--bm-color-bg'                 => '31, 18, 25',
                    '--bm-color-text-primary'       => '246, 230, 238',
                    '--bm-color-border'             => '62, 40, 52',
                    '--bm-color-inbox-bg'           => '23, 13, 19',
                    '--bm-color-bubble-self-bg'     => '158, 39, 89',
                    '--bm-color-bubble-self-text'   => '255, 240, 247',
                    '--bm-color-bubble-other-bg'    => '50, 33, 43',
                    '--bm-color-bubble-other-text'  => '246, 230, 238',
                ),
            ),
        ),
    );

    return apply_filters( 'better_messages_design_shipped_presets', $presets );
}
