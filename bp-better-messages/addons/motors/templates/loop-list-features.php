<?php
defined( 'ABSPATH' ) || exit;

$bm_motors_original = defined( 'STM_LISTINGS_PATH' )
    ? STM_LISTINGS_PATH . '/templates/loop/list/features.php'
    : '';

ob_start();
if ( $bm_motors_original && file_exists( $bm_motors_original ) ) {
    include $bm_motors_original;
}
$bm_motors_html = ob_get_clean();

if ( class_exists( 'Better_Messages_Motors' ) ) {
    $bm_motors_card_html = Better_Messages_Motors::instance()->render_card_button( (int) get_the_ID() );
    if ( ! empty( $bm_motors_card_html ) ) {
        $bm_motors_li = '<li class="bm-motors-action-li">' . $bm_motors_card_html . '</li>';
        $injected = preg_replace( '#(</ul>\s*</div>)#', $bm_motors_li . '$1', $bm_motors_html, 1 );
        if ( ! empty( $injected ) ) {
            $bm_motors_html = $injected;
        } else {
            $bm_motors_html .= '<div class="bm-motors-list-card-footer">' . $bm_motors_card_html . '</div>';
        }
    }
}

echo $bm_motors_html;
