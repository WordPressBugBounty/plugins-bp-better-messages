<?php

if ( ! defined( 'ABSPATH' ) ) exit;

if ( function_exists( 'wordplus_pickers' ) && wordplus_pickers() ) {
    add_filter( 'wordplus_pickers_settings', function ( $settings ) {
        $own = Better_Messages()->settings;

        foreach ( array_merge( WordPlus_SDK_Pickers::GIF_KEYS, array( 'emojiSet', 'emojiSpriteDelivery' ) ) as $key ) {
            if ( isset( $own[ $key ] ) ) {
                $settings[ $key ] = $own[ $key ];
            }
        }

        $settings['emojiCategories'] = (array) get_option( 'bm-emoji-set-2', array() );

        return $settings;
    } );

    add_action( 'bp_better_chat_settings_updated', function ( $settings ) {
        $keys = array_merge( WordPlus_SDK_Pickers::GIF_KEYS, array( 'emojiSet', 'emojiSpriteDelivery' ) );

        wordplus_pickers()->update_settings( array_intersect_key( (array) $settings, array_flip( $keys ) ) );
    } );

    $save_emoji = function ( $option_or_old_value, $value ) {
        wordplus_pickers()->update_settings( array( 'emojiCategories' => (array) $value ) );
    };
    add_action( 'add_option_bm-emoji-set-2', $save_emoji, 10, 2 );
    add_action( 'update_option_bm-emoji-set-2', $save_emoji, 10, 2 );

    add_filter( 'wordplus_pickers_user_id', function ( $user_id ) {
        return (int) Better_Messages()->functions->get_current_user_id();
    } );

    add_filter( 'wordplus_pickers_can_use', function ( $can ) {
        return $can || Better_Messages()->functions->get_current_user_id() !== 0;
    } );

    add_filter( 'wordplus_pickers_user_meta', function ( $value, $user_id, $key ) {
        return null !== $value ? $value : Better_Messages()->functions->get_user_meta( $user_id, $key, true );
    }, 10, 3 );

    add_filter( 'wordplus_pickers_set_user_meta', function ( $done, $user_id, $key, $value ) {
        if ( ! $done ) {
            Better_Messages()->functions->update_user_meta( $user_id, $key, $value );
        }

        return true;
    }, 10, 4 );
}
