<?php
defined( 'ABSPATH' ) || exit;

if ( ! is_user_logged_in() && ! ( Better_Messages()->guests->guest_access_enabled() ) ) {
    if ( class_exists( 'STM_LMS_User' ) ) {
        wp_safe_redirect( STM_LMS_User::login_page_url() );
        exit;
    }
}

$lms_current_user = class_exists( 'STM_LMS_User' ) ? STM_LMS_User::get_current_user( '', true, true ) : array( 'id' => get_current_user_id() );

do_action( 'stm_lms_template_main' );
do_action( 'masterstudy_before_account', $lms_current_user );

if ( wp_style_is( 'masterstudy-account-main', 'registered' ) ) {
    wp_enqueue_style( 'masterstudy-account-main' );
}
?>
<div class="masterstudy-account">
    <?php do_action( 'stm_lms_admin_after_wrapper_start', $lms_current_user ); ?>

    <div class="masterstudy-account-sidebar">
        <div class="masterstudy-account-sidebar__wrapper">
            <?php do_action( 'masterstudy_account_sidebar', $lms_current_user ); ?>
        </div>
    </div>

    <div class="masterstudy-account-container">
        <div class="bm-masterstudy-account-messages">
            <?php echo Better_Messages()->functions->get_page(); ?>
        </div>
    </div>
</div>
<?php do_action( 'masterstudy_after_account', $lms_current_user );
