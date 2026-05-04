<?php
defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Better_Messages_MasterStudy' ) ) {

    class Better_Messages_MasterStudy
    {

        public static function instance()
        {
            static $instance = null;

            if ( null === $instance ) {
                $instance = new Better_Messages_MasterStudy();
            }

            return $instance;
        }

        public function __construct()
        {
            if ( Better_Messages()->settings['masterStudyIntegration'] !== '1' ) return;

            if ( Better_Messages()->settings['masterStudyMessageButton'] === '1' ) {
                add_action( 'stm_lms_before_button_mixed', array( $this, 'course_message_button' ), 5, 1 );
            }

            if ( Better_Messages()->settings['masterStudyInstructorPMButton'] === '1' ) {
                add_action( 'wp_footer', array( $this, 'instructor_profile_button' ), 50 );
            }

            if ( Better_Messages()->settings['masterStudyStudentPMButton'] === '1' ) {
                add_action( 'wp_footer', array( $this, 'student_profile_button' ), 50 );
            }

            if ( Better_Messages()->settings['masterStudyDisableNativeMessages'] === '1' ) {
                add_action( 'wp_head', array( $this, 'disable_native_messages_styles' ), 99 );
            }

            if ( Better_Messages()->settings['masterStudyGroupChat'] === '1' ) {
                add_action( 'add_user_course', array( $this, 'on_user_enrolled' ), 10, 2 );
                add_action( 'masterstudy_lms_after_delete_user_course', array( $this, 'on_user_unenrolled' ), 10, 2 );
                add_action( 'masterstudy_plugin_student_course_completion', array( $this, 'on_user_enrolled' ), 10, 2 );

                add_filter( 'better_messages_courses_active', '__return_true' );
                add_filter( 'better_messages_get_courses', array( $this, 'get_courses' ), 10, 2 );
                add_filter( 'better_messages_user_has_courses', array( $this, 'user_has_courses' ), 10, 2 );
                add_filter( 'better_messages_bulk_get_all_groups', array( $this, 'bulk_get_all_groups' ), 10, 1 );
                add_filter( 'better_messages_bulk_get_group_members', array( $this, 'bulk_get_group_members' ), 10, 2 );
                add_filter( 'better_messages_is_valid_course', array( $this, 'is_valid_group' ), 10, 2 );
                add_filter( 'better_messages_has_access_to_group_chat', array( $this, 'has_access_to_group_chat' ), 10, 3 );
                add_filter( 'better_messages_thread_image', array( $this, 'group_thread_image' ), 10, 3 );
                add_filter( 'better_messages_thread_url', array( $this, 'group_thread_url' ), 10, 3 );
            }

            $account_required = ( Better_Messages()->settings['chatPage'] === 'masterstudy-account' );

            if ( Better_Messages()->settings['masterStudyAccountTab'] === '1' || $account_required ) {
                add_filter( 'stm_lms_custom_routes_config', array( $this, 'register_account_route' ) );
                add_filter( 'template_include', array( $this, 'override_account_template' ), 25 );
                add_filter( 'stm_lms_menu_items', array( $this, 'add_account_messages_tab' ) );
                add_filter( 'stm_lms_sorted_menu', array( $this, 'add_account_messages_tab' ), 20 );
                add_filter( 'stm_lms_sorted_student_menu', array( $this, 'add_account_messages_tab' ), 20 );
            }

            if ( Better_Messages()->settings['masterStudyDisableNativeMessages'] === '1' ) {
                add_filter( 'stm_lms_menu_items', array( $this, 'hide_native_messages_tab' ), 30 );
                add_filter( 'stm_lms_sorted_menu', array( $this, 'hide_native_messages_tab' ), 30 );
                add_filter( 'stm_lms_sorted_student_menu', array( $this, 'hide_native_messages_tab' ), 30 );
            }

            add_filter( 'better_messages_rest_thread_item', array( $this, 'thread_item' ), 10, 5 );
            add_filter( 'better_messages_rest_user_item', array( $this, 'user_meta' ), 20, 3 );
        }

        private function can_render_message_button( $target_user_id )
        {
            $target_user_id = (int) $target_user_id;
            if ( $target_user_id <= 0 ) return false;
            if ( $target_user_id === (int) Better_Messages()->functions->get_current_user_id() ) return false;

            return true;
        }

        private function render_live_chat_button( array $args )
        {
            $defaults = array(
                'class'      => 'masterstudy-button masterstudy-button_style-primary masterstudy-button_size-sm',
                'text'       => '',
                'user_id'    => 0,
                'unique_tag' => '',
                'subject'    => '',
            );
            $args = array_merge( $defaults, $args );

            $shortcode = '[better_messages_live_chat_button type="button"';
            $shortcode .= ' class="' . esc_attr( $args['class'] ) . '"';
            $shortcode .= ' text="' . Better_Messages()->shortcodes->esc_brackets( $args['text'] ) . '"';
            $shortcode .= ' user_id="' . (int) $args['user_id'] . '"';
            $shortcode .= ' unique_tag="' . esc_attr( $args['unique_tag'] ) . '"';
            if ( ! empty( $args['subject'] ) ) {
                $shortcode .= ' subject="' . Better_Messages()->shortcodes->esc_brackets( $args['subject'] ) . '"';
            }
            $shortcode .= ']';

            $html = do_shortcode( $shortcode );

            $html = preg_replace(
                '#<span class="bm-button-text">(.*?)</span>#s',
                '<span class="masterstudy-button__title bm-button-text">$1</span>',
                $html
            );

            return $html;
        }

        public function course_message_button( $post_id = 0 )
        {
            $course_id = (int) $post_id ? (int) $post_id : (int) get_the_ID();
            if ( ! $course_id ) return;
            if ( get_post_type( $course_id ) !== 'stm-courses' ) return;

            $instructor_id = (int) get_post_field( 'post_author', $course_id );
            if ( ! $this->can_render_message_button( $instructor_id ) ) return;

            $course_title = get_the_title( $course_id );
            if ( ! $course_title ) return;

            $html = $this->render_live_chat_button( array(
                'class'      => 'masterstudy-button masterstudy-button_style-outline masterstudy-button_size-md bm-masterstudy-message-btn',
                'text'       => esc_attr_x( 'Message Instructor', 'MasterStudy Integration', 'bp-better-messages' ),
                'user_id'    => $instructor_id,
                'unique_tag' => 'masterstudy_course_chat_' . $course_id,
                'subject'    => esc_attr( sprintf(
                    _x( 'Question about course "%s"', 'MasterStudy Integration', 'bp-better-messages' ),
                    $course_title
                ) ),
            ) );

            if ( empty( $html ) ) return;

            echo '<div class="bm-masterstudy-pm-wrap bm-masterstudy-pm-wrap-course" style="visibility:hidden">' . $html . '</div>';
            ?>
            <style>
            .bm-masterstudy-pm-wrap-course { display: block; margin-top: 12px; }
            .bm-masterstudy-pm-wrap-course .bm-lc-button { display: flex; width: 100%; box-sizing: border-box; }
            </style>
            <script>
            (function(){
                var run = function(){
                    var w = document.querySelector('.bm-masterstudy-pm-wrap-course');
                    if ( ! w ) return;
                    var buy = document.querySelector('.masterstudy-buy-button');
                    if ( buy && buy.parentNode ) {
                        if ( buy.nextSibling ) {
                            buy.parentNode.insertBefore( w, buy.nextSibling );
                        } else {
                            buy.parentNode.appendChild( w );
                        }
                    }
                    w.style.visibility = 'visible';
                };
                if ( document.readyState === 'loading' ) {
                    document.addEventListener( 'DOMContentLoaded', run );
                } else {
                    run();
                }
            })();
            </script>
            <?php
        }

        public function instructor_profile_button()
        {
            if ( ! $this->is_public_profile( 'instructor' ) ) return;

            $user_id = $this->get_profile_user_id( 'instructor' );
            if ( ! $this->can_render_message_button( $user_id ) ) return;

            $this->print_profile_button( 'instructor', $user_id );
        }

        public function student_profile_button()
        {
            if ( ! $this->is_public_profile( 'student' ) ) return;

            $user_id = $this->get_profile_user_id( 'student' );
            if ( ! $this->can_render_message_button( $user_id ) ) return;

            $this->print_profile_button( 'student', $user_id );
        }

        private function print_profile_button( $kind, $user_id )
        {
            $class = 'masterstudy-button masterstudy-button_style-primary masterstudy-button_size-sm bm-masterstudy-' . $kind . '-msg-btn';
            $unique_prefix = 'masterstudy_' . $kind . '_chat_';

            $html = $this->render_live_chat_button( array(
                'class'      => $class,
                'text'       => esc_attr_x( 'Send Message', 'MasterStudy Integration', 'bp-better-messages' ),
                'user_id'    => $user_id,
                'unique_tag' => $unique_prefix . $user_id,
            ) );

            if ( empty( $html ) ) return;

            $target = '.masterstudy-' . $kind . '-public__actions';
            $wrap_class = 'bm-masterstudy-pm-wrap bm-masterstudy-pm-wrap-' . $kind;

            echo '<div class="' . esc_attr( $wrap_class ) . '" style="display:none">' . $html . '</div>';
            ?>
            <script>
            (function(){
                var doAppend = function(){
                    var btn = document.querySelector('.bm-masterstudy-pm-wrap-<?php echo esc_js( $kind ); ?>');
                    var actions = document.querySelector('<?php echo esc_js( $target ); ?>');
                    if ( ! btn || ! actions ) return;
                    actions.appendChild( btn );
                    btn.style.display = '';
                    if ( window.jQuery ) {
                        window.jQuery( btn ).find( '.masterstudy-button' ).off( 'click' );
                    }
                };
                if ( window.jQuery ) {
                    window.jQuery(function(){ doAppend(); });
                } else if ( document.readyState === 'loading' ) {
                    document.addEventListener( 'DOMContentLoaded', doAppend );
                } else {
                    doAppend();
                }
            })();
            </script>
            <?php
        }

        private function is_public_profile( $kind )
        {
            if ( ! class_exists( 'STM_LMS_User' ) ) return false;

            if ( get_query_var( 'lms_template' ) === 'stm-lms-' . $kind . '-public' ) return true;

            $request = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '';

            return strpos( $request, '/' . $kind . '-public-account/' ) !== false;
        }

        private function get_profile_user_id( $kind )
        {
            $user_id = (int) get_query_var( 'user_id' );
            if ( $user_id > 0 ) return $user_id;

            $request = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '';
            if ( preg_match( '#/' . preg_quote( $kind, '#' ) . '-public-account/(\d+)#', $request, $m ) ) {
                return (int) $m[1];
            }

            return 0;
        }

        public function disable_native_messages_styles()
        {
            ?>
            <style id="bm-masterstudy-disable-native">
            #masterstudy-instructor-message-send,
            #masterstudy-student-message-send,
            [data-id="masterstudy-instructor-message-send"],
            [data-id="masterstudy-student-message-send"] { display: none !important; }
            </style>
            <?php
        }

        public function on_user_enrolled( $user_id, $course_id )
        {
            $thread_id = $this->get_course_thread_id( $course_id );
            if ( $thread_id ) $this->sync_thread_members( $thread_id );
        }

        public function on_user_unenrolled( $user_id, $course_id )
        {
            $thread_id = $this->get_course_thread_id( $course_id, false );
            if ( $thread_id ) $this->sync_thread_members( $thread_id );
        }

        public function sync_thread_members( $thread_id )
        {
            $thread_id = (int) $thread_id;
            if ( ! $thread_id ) return false;

            wp_cache_delete( 'bm_thread_recipients_' . $thread_id, 'bm_messages' );

            $course_id = (int) Better_Messages()->functions->get_thread_meta( $thread_id, 'masterstudy_course_id' );
            if ( ! $course_id ) return false;

            $members = $this->get_course_members( $course_id );

            global $wpdb;
            $array       = array();
            $user_ids    = array();
            $removed_ids = array();

            $recipients = Better_Messages()->functions->get_recipients( $thread_id );

            foreach ( $members as $user_id ) {
                $user_id = (int) $user_id;
                if ( ! $user_id ) continue;

                if ( isset( $recipients[ $user_id ] ) ) {
                    unset( $recipients[ $user_id ] );
                    continue;
                }

                if ( in_array( $user_id, $user_ids, true ) ) continue;

                $user_ids[] = $user_id;
                $array[] = array( $user_id, $thread_id, 0, 0 );
            }

            $changes = false;

            if ( count( $array ) > 0 ) {
                $sql = 'INSERT INTO ' . bm_get_table( 'recipients' ) . '
                    (user_id, thread_id, unread_count, is_deleted)
                    VALUES ';

                $values = array();
                foreach ( $array as $item ) {
                    $values[] = $wpdb->prepare( '(%d, %d, %d, %d)', $item );
                }

                $sql .= implode( ',', $values );
                $wpdb->query( $sql );

                $changes = true;
            }

            if ( count( $recipients ) > 0 ) {
                foreach ( $recipients as $user_id => $recipient ) {
                    $wpdb->delete( bm_get_table( 'recipients' ), array(
                        'thread_id' => $thread_id,
                        'user_id'   => $user_id,
                    ), array( '%d', '%d' ) );

                    $removed_ids[] = $user_id;
                }

                $changes = true;
            }

            Better_Messages()->hooks->clean_thread_cache( $thread_id );

            if ( $changes ) {
                do_action( 'better_messages_thread_updated', $thread_id );
                do_action( 'better_messages_info_changed', $thread_id );
                do_action( 'better_messages_participants_added', $thread_id, $user_ids );
                do_action( 'better_messages_participants_removed', $thread_id, $removed_ids );
            }

            return true;
        }

        public function get_courses( $courses, $user_id )
        {
            if ( $user_id <= 0 ) return $courses;

            $course_ids = $this->get_user_courses( $user_id );

            foreach ( $course_ids as $course_id ) {
                $thread_id = $this->get_course_thread_id( $course_id, false );
                if ( ! $thread_id ) continue;

                $image = get_the_post_thumbnail_url( $course_id, 'thumbnail' );

                $courses[] = array(
                    'course_id' => $course_id,
                    'name'      => get_the_title( $course_id ),
                    'image'     => $image ? $image : '',
                    'url'       => get_permalink( $course_id ),
                    'thread_id' => $thread_id,
                    'messages'  => 1,
                );
            }

            return $courses;
        }

        public function user_has_courses( $has, $user_id )
        {
            if ( $has ) return $has;
            if ( $user_id <= 0 ) return false;

            return count( $this->get_user_courses( $user_id ) ) > 0;
        }

        public function bulk_get_all_groups( $groups )
        {
            $courses = get_posts( array(
                'post_type'      => 'stm-courses',
                'post_status'    => 'publish',
                'posts_per_page' => -1,
                'fields'         => 'ids',
            ) );

            if ( ! $courses ) return $groups;

            foreach ( $courses as $course_id ) {
                $thread_id = $this->get_course_thread_id( $course_id, false );
                if ( ! $thread_id ) continue;

                $groups[] = array(
                    'group_id'  => 'masterstudy_course_' . $course_id,
                    'thread_id' => $thread_id,
                );
            }

            return $groups;
        }

        public function bulk_get_group_members( $members, $group )
        {
            if ( ! isset( $group['group_id'] ) ) return $members;
            if ( strpos( $group['group_id'], 'masterstudy_course_' ) !== 0 ) return $members;

            $course_id = (int) str_replace( 'masterstudy_course_', '', $group['group_id'] );

            return $this->get_course_members( $course_id );
        }

        public function is_valid_group( $is_valid, $thread_id )
        {
            $course_id = $this->get_thread_course_id( $thread_id );

            if ( $course_id && get_post_status( $course_id ) === 'publish' ) return true;

            return $is_valid;
        }

        public function has_access_to_group_chat( $has_access, $thread_id, $user_id )
        {
            $course_id = $this->get_thread_course_id( $thread_id );
            if ( ! $course_id ) return $has_access;

            if ( current_user_can( 'manage_options' ) ) return true;
            if ( (int) get_post_field( 'post_author', $course_id ) === (int) $user_id ) return true;

            global $wpdb;
            $is_enrolled = $wpdb->get_var( $wpdb->prepare(
                "SELECT 1 FROM {$wpdb->prefix}stm_lms_user_courses WHERE user_id = %d AND course_id = %d LIMIT 1",
                $user_id,
                $course_id
            ) );

            return ! empty( $is_enrolled );
        }

        public function group_thread_image( $image, $thread_id, $thread )
        {
            $course_id = $this->get_thread_course_id( $thread_id );
            if ( ! $course_id ) return $image;

            $course_image = get_the_post_thumbnail_url( $course_id, 'thumbnail' );

            return $course_image ? $course_image : $this->get_default_course_image_html();
        }

        public function get_default_course_image_html()
        {
            return 'html:<span style="display:flex;align-items:center;justify-content:center;width:100%;height:100%;background:rgba(0,0,0,0.05);color:rgba(0,0,0,0.45);border-radius:50%;aspect-ratio:1/1;box-sizing:border-box"><svg style="width:60%;height:60%;max-width:36px;max-height:36px" viewBox="0 0 24 24" stroke="currentColor" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 10v6M2 10l10-5 10 5-10 5z"></path><path d="M6 12v5c3 3 9 3 12 0v-5"></path></svg></span>';
        }

        public function group_thread_url( $url, $thread_id, $thread )
        {
            $course_id = $this->get_thread_course_id( $thread_id );
            if ( ! $course_id ) return $url;

            $permalink = get_permalink( $course_id );

            return $permalink ? $permalink : $url;
        }

        public function register_account_route( $page_routes )
        {
            if ( ! isset( $page_routes['user_url'] ) || ! isset( $page_routes['user_url']['sub_pages'] ) ) {
                return $page_routes;
            }

            $page_routes['user_url']['sub_pages']['bm_messages_url'] = array(
                'template'  => 'account/bm-messages',
                'protected' => true,
                'url'       => 'messages',
            );

            return $page_routes;
        }

        public function override_account_template( $template )
        {
            if ( get_query_var( 'lms_template' ) !== 'account/bm-messages' ) return $template;

            $stub = Better_Messages()->path . 'addons/masterstudy-account-messages.php';

            return file_exists( $stub ) ? $stub : $template;
        }

        public function hide_native_messages_tab( $menus )
        {
            if ( ! is_array( $menus ) ) return $menus;

            foreach ( $menus as $key => $menu ) {
                if ( isset( $menu['id'] ) && $menu['id'] === 'messages' ) {
                    unset( $menus[ $key ] );
                }
            }

            return array_values( $menus );
        }

        public function add_account_messages_tab( $menus )
        {
            if ( ! is_array( $menus ) ) return $menus;

            foreach ( $menus as $menu ) {
                if ( isset( $menu['id'] ) && $menu['id'] === 'better_messages' ) return $menus;
            }

            $is_active = class_exists( 'STM_LMS_User_Menu' ) && STM_LMS_User_Menu::get_current_account_slug() === 'messages';
            $menu_url  = function_exists( 'ms_plugin_user_account_url' ) ? ms_plugin_user_account_url( 'messages' ) : home_url( '/' );

            $menus[] = array(
                'order'        => 121,
                'id'           => 'better_messages',
                'slug'         => 'messages',
                'lms_template' => 'account/bm-messages',
                'menu_title'   => _x( 'Messages', 'MasterStudy Integration', 'bp-better-messages' ),
                'menu_icon'    => 'stmlms-menu-messages',
                'menu_url'     => $menu_url,
                'section'      => 'communication',
                'is_active'    => $is_active,
            );

            return $menus;
        }

        public function thread_item( $thread_item, $thread_id, $thread_type, $include_personal, $user_id )
        {
            if ( Better_Messages()->settings['coursesShowInfoCard'] !== '1' ) return $thread_item;

            $course_id = $this->get_thread_course_id( $thread_id );

            if ( ! $course_id ) {
                $unique_tag = (string) Better_Messages()->functions->get_thread_meta( $thread_id, 'unique_tag' );
                if ( strpos( $unique_tag, 'masterstudy_course_chat_' ) === 0 ) {
                    $course_id = (int) str_replace( 'masterstudy_course_chat_', '', explode( '|', $unique_tag )[0] );
                }
            }

            if ( ! $course_id ) return $thread_item;

            $existing = isset( $thread_item['threadInfo'] ) ? $thread_item['threadInfo'] : '';
            $thread_item['threadInfo'] = $existing . $this->course_thread_info_html( $course_id );

            return $thread_item;
        }

        public function course_thread_info_html( $course_id )
        {
            $course = get_post( $course_id );

            if ( ! $course ) return '';

            $title    = esc_html( $course->post_title );
            $url      = get_permalink( $course_id );
            $image_id = get_post_thumbnail_id( $course_id );

            $html = '<div class="bm-product-info">';

            if ( $image_id ) {
                $image_src = wp_get_attachment_image_src( $image_id, array( 100, 100 ) );
                if ( $image_src ) {
                    $html .= '<div class="bm-product-image">';
                    $html .= '<a href="' . esc_url( $url ) . '" target="_blank"><img src="' . esc_url( $image_src[0] ) . '" alt="' . $title . '" /></a>';
                    $html .= '</div>';
                }
            }

            $html .= '<div class="bm-product-details">';
            $html .= '<div class="bm-product-title"><a href="' . esc_url( $url ) . '" target="_blank">' . $title . '</a></div>';

            $instructor_id   = (int) get_post_field( 'post_author', $course_id );
            $instructor_name = $instructor_id ? get_the_author_meta( 'display_name', $instructor_id ) : '';
            if ( $instructor_name ) {
                $html .= '<div class="bm-product-subtitle">' . esc_html( sprintf(
                    _x( 'Instructor: %s', 'MasterStudy Integration', 'bp-better-messages' ),
                    $instructor_name
                ) ) . '</div>';
            }

            $html .= '</div>';
            $html .= '</div>';

            return $html;
        }

        public function user_meta( $item, $user_id, $include_personal = false )
        {
            if ( $user_id <= 0 ) return $item;
            if ( ! class_exists( 'STM_LMS_Instructor' ) || ! class_exists( 'STM_LMS_User' ) ) return $item;
            if ( ! STM_LMS_Instructor::is_instructor( $user_id ) ) return $item;

            $profile_url = STM_LMS_User::instructor_public_page_url( $user_id );
            if ( $profile_url ) $item['url'] = esc_url( $profile_url );

            return $item;
        }

        public function get_course_thread_id( $course_id, $create = true )
        {
            global $wpdb;

            $threadsmeta_table = bm_get_table( 'threadsmeta' );
            $threads_table     = bm_get_table( 'threads' );

            $thread_id = $wpdb->get_var( $wpdb->prepare(
                "SELECT tm.bm_thread_id FROM {$threadsmeta_table} tm
                 INNER JOIN {$threads_table} t ON t.id = tm.bm_thread_id
                 WHERE tm.meta_key = 'masterstudy_course_id' AND tm.meta_value = %s
                 LIMIT 1",
                $course_id
            ) );

            if ( $thread_id ) return (int) $thread_id;

            if ( ! $create ) return false;

            $course = get_post( $course_id );

            if ( ! $course || $course->post_type !== 'stm-courses' ) return false;

            $wpdb->insert(
                $threads_table,
                array(
                    'subject' => $course->post_title,
                    'type'    => 'course',
                )
            );

            $thread_id = $wpdb->insert_id;

            if ( ! $thread_id ) return false;

            Better_Messages()->functions->update_thread_meta( $thread_id, 'masterstudy_course_id', $course_id );

            $this->sync_thread_members( $thread_id );

            return (int) $thread_id;
        }

        public function get_thread_course_id( $thread_id )
        {
            return (int) Better_Messages()->functions->get_thread_meta( $thread_id, 'masterstudy_course_id' );
        }

        public function get_course_members( $course_id )
        {
            global $wpdb;

            $members = array();

            $instructor_id = (int) get_post_field( 'post_author', $course_id );
            if ( $instructor_id > 0 ) $members[] = $instructor_id;

            $students = $wpdb->get_col( $wpdb->prepare(
                "SELECT user_id FROM {$wpdb->prefix}stm_lms_user_courses WHERE course_id = %d",
                $course_id
            ) );

            foreach ( (array) $students as $student_id ) {
                $members[] = (int) $student_id;
            }

            return array_unique( $members );
        }

        private function get_user_courses( $user_id )
        {
            global $wpdb;

            $authored = get_posts( array(
                'post_type'      => 'stm-courses',
                'post_status'    => 'publish',
                'author'         => $user_id,
                'posts_per_page' => -1,
                'fields'         => 'ids',
            ) );

            $enrolled = $wpdb->get_col( $wpdb->prepare(
                "SELECT course_id FROM {$wpdb->prefix}stm_lms_user_courses WHERE user_id = %d",
                $user_id
            ) );

            return array_unique( array_map( 'intval', array_merge( (array) $authored, (array) $enrolled ) ) );
        }
    }
}
