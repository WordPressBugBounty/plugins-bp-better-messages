<?php

$full_screen =  $attributes['fullScreen'] ? '1' : '0';
?>
<div <?php echo wp_kses_data( get_block_wrapper_attributes() ); ?>>
    [better_messages full_screen="<?php echo $full_screen; ?>"]
</div>
