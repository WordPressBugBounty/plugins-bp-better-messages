<?php
/**
 * All of the parameters passed to the function where this file is being required are accessible in this scope:
 *
 * @var array    $attributes The array of attributes for this block.
 * @var string   $content    Rendered block output. ie. <InnerBlocks.Content />.
 * @var WP_Block $block      The instance of the WP_Block class that represents the block being rendered.
 */

$full_screen =  $attributes['fullScreen'] ? '1' : '0';
?>
<div <?php echo wp_kses_data( get_block_wrapper_attributes() ); ?>>
    [better_messages full_screen="<?php echo $full_screen; ?>"]
</div>
