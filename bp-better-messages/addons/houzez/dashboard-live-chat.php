<?php
if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! is_user_logged_in() ) {
	wp_redirect( home_url() );
	exit;
}

get_header( 'dashboard' );
?>

<?php get_template_part( 'template-parts/dashboard/sidebar' ); ?>

<div class="dashboard-right">

	<?php get_template_part( 'template-parts/dashboard/topbar' ); ?>

	<div class="dashboard-content bm-houzez-dashboard-live-chat">
		<?php echo do_shortcode( '[better_messages]' ); ?>
	</div>

</div>

<?php get_footer( 'dashboard' );
