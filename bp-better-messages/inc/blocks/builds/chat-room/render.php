<?php

$chat_id = intval( $attributes['chatId'] );
$full_screen = ! empty( $attributes['fullScreen'] ) ? '1' : '0';
if ( $chat_id > 0 ) : ?>
<div <?php echo wp_kses_data( get_block_wrapper_attributes() ); ?>>
    <?php echo do_shortcode( '[better_messages_chat_room id="' . $chat_id . '" full_screen="' . $full_screen . '"]' ); ?>
</div>
<?php endif; ?>
