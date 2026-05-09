<?php
defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Better_Messages_TutorLMS' ) ) {

    class Better_Messages_TutorLMS
    {

        public static function instance()
        {
            static $instance = null;

            if ( null === $instance ) {
                $instance = new Better_Messages_TutorLMS();
            }

            return $instance;
        }

        public function __construct()
        {
            add_shortcode( 'better_messages_tutorlms_course_button',     array( $this, 'course_button_shortcode' ) );
            add_shortcode( 'better_messages_tutorlms_instructor_button', array( $this, 'instructor_button_shortcode' ) );
            add_shortcode( 'better_messages_tutorlms_student_button',    array( $this, 'student_button_shortcode' ) );

            if ( Better_Messages()->settings['tutorLmsIntegration'] !== '1' ) return;

            if ( Better_Messages()->settings['tutorLmsMessageButton'] === '1' ) {
                add_action( 'tutor_course/single/actions_btn_group/before', array( $this, 'course_message_button' ), 10 );
                add_action( 'tutor_course/single/entry/after', array( $this, 'course_message_button' ), 10 );
            }

            if ( Better_Messages()->settings['tutorLmsInstructorPMButton'] === '1' ) {
                add_action( 'tutor_profile/instructor/before/wrap', array( $this, 'instructor_profile_message_button' ), 10 );
            }

            if ( Better_Messages()->settings['tutorLmsStudentPMButton'] === '1' ) {
                add_action( 'tutor_profile/student/before/wrap', array( $this, 'student_profile_message_button' ), 10 );
            }

            if ( Better_Messages()->settings['tutorLmsInstructorPMButton'] === '1' || Better_Messages()->settings['tutorLmsStudentPMButton'] === '1' ) {
                add_action( 'wp_footer', array( $this, 'profile_button_position_script' ), 50 );
            }

            add_action( 'tutor_after_enrolled', array( $this, 'on_user_enrolled' ), 10, 3 );
            add_action( 'tutor_after_enrollment_deleted', array( $this, 'on_user_unenrolled' ), 10, 2 );
            add_action( 'tutor_after_enrollment_cancelled', array( $this, 'on_user_unenrolled' ), 10, 2 );

            if ( Better_Messages()->settings['tutorLmsGroupChat'] === '1' ) {
                add_filter( 'better_messages_courses_active', array( $this, 'enabled' ) );
                add_filter( 'better_messages_get_courses', array( $this, 'get_courses' ), 10, 2 );
                add_filter( 'better_messages_user_has_courses', array( $this, 'user_has_courses' ), 10, 2 );
                add_filter( 'better_messages_bulk_get_all_groups', array( $this, 'bulk_get_all_groups' ), 10, 1 );
                add_filter( 'better_messages_bulk_get_group_members', array( $this, 'bulk_get_group_members' ), 10, 2 );
                add_filter( 'better_messages_is_valid_course', array( $this, 'is_valid_group' ), 10, 2 );
                add_filter( 'better_messages_has_access_to_group_chat', array( $this, 'has_access_to_group_chat' ), 10, 3 );
                add_filter( 'better_messages_thread_image', array( $this, 'group_thread_image' ), 10, 3 );
                add_filter( 'better_messages_thread_url', array( $this, 'group_thread_url' ), 10, 3 );
            }

            $dashboard_required = ( Better_Messages()->settings['chatPage'] === 'tutor-dashboard' );

            if ( Better_Messages()->settings['tutorLmsDashboardTab'] === '1' || $dashboard_required ) {
                add_filter( 'tutor_dashboard/nav_items', array( $this, 'add_dashboard_tab' ) );
                add_filter( 'tutor_dashboard/instructor_nav_items', array( $this, 'add_dashboard_tab' ) );
                add_filter( 'load_dashboard_template_part_from_other_location', array( $this, 'route_dashboard_messages' ) );
            }

            add_filter( 'better_messages_rest_thread_item', array( $this, 'thread_item' ), 10, 5 );
            add_filter( 'better_messages_rest_user_item', array( $this, 'user_meta' ), 20, 3 );
        }

        public function enabled()
        {
            return true;
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
                'class'      => 'tutor-btn tutor-btn-outline-primary',
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

            return do_shortcode( $shortcode );
        }

        private $course_message_button_rendered = array();

        public function course_message_button()
        {
            $course_id = get_the_ID();
            if ( ! $course_id ) return;
            if ( get_post_type( $course_id ) !== 'courses' ) return;
            if ( isset( $this->course_message_button_rendered[ $course_id ] ) ) return;
            $this->course_message_button_rendered[ $course_id ] = true;

            $instructor_id = (int) get_post_field( 'post_author', $course_id );
            if ( ! $this->can_render_message_button( $instructor_id ) ) return;

            $course_title = get_the_title( $course_id );
            if ( ! $course_title ) return;

            $html = $this->render_live_chat_button( array(
                'class'      => 'tutor-btn tutor-btn-outline-primary tutor-btn-md tutor-btn-block bm-tutor-message-btn',
                'text'       => esc_attr_x( 'Message Instructor', 'Tutor LMS Integration', 'bp-better-messages' ),
                'user_id'    => $instructor_id,
                'unique_tag' => 'tutorlms_course_chat_' . $course_id,
                'subject'    => esc_attr( sprintf(
                    _x( 'Question about course "%s"', 'Tutor LMS Integration', 'bp-better-messages' ),
                    $course_title
                ) ),
            ) );

            if ( ! empty( $html ) ) {
                echo '<div class="bm-tutor-pm-wrap">' . $html . '</div>';
            }
        }

        public function course_button_shortcode( $atts = array() )
        {
            $atts = shortcode_atts( array(
                'course_id' => 0,
                'user_id'   => 0,
                'class'     => '',
                'text'      => '',
            ), $atts, 'better_messages_tutorlms_course_button' );

            $course_id = (int) ( $atts['course_id'] ?: get_the_ID() );
            if ( ! $course_id || get_post_type( $course_id ) !== 'courses' ) return '';

            $instructor_id = (int) ( $atts['user_id'] ?: get_post_field( 'post_author', $course_id ) );
            if ( ! $this->can_render_message_button( $instructor_id ) ) return '';

            $course_title = get_the_title( $course_id );
            if ( ! $course_title ) return '';

            $html = $this->render_live_chat_button( array(
                'class'      => $atts['class'] ?: 'tutor-btn tutor-btn-outline-primary tutor-btn-md tutor-btn-block bm-tutor-message-btn',
                'text'       => $atts['text'] ?: esc_attr_x( 'Message Instructor', 'Tutor LMS Integration', 'bp-better-messages' ),
                'user_id'    => $instructor_id,
                'unique_tag' => 'tutorlms_course_chat_' . $course_id,
                'subject'    => esc_attr( sprintf(
                    _x( 'Question about course "%s"', 'Tutor LMS Integration', 'bp-better-messages' ),
                    $course_title
                ) ),
            ) );

            if ( empty( $html ) ) return '';

            return '<div class="bm-tutor-pm-wrap">' . $html . '</div>';
        }

        public function instructor_button_shortcode( $atts = array() )
        {
            return $this->profile_button_shortcode( $atts, 'instructor' );
        }

        public function student_button_shortcode( $atts = array() )
        {
            return $this->profile_button_shortcode( $atts, 'student' );
        }

        private function profile_button_shortcode( $atts, $kind )
        {
            $atts = shortcode_atts( array(
                'user_id' => 0,
                'class'   => '',
                'text'    => '',
            ), $atts, 'better_messages_tutorlms_' . $kind . '_button' );

            $user_id = (int) $atts['user_id'] ?: $this->get_profile_user_id();

            if ( ! $user_id && is_author() ) {
                $user_id = (int) get_queried_object_id();
            }

            if ( ! $this->can_render_message_button( $user_id ) ) return '';

            $html = $this->render_live_chat_button( array(
                'class'      => $atts['class'] ?: 'tutor-btn tutor-btn-outline-primary bm-tutor-' . $kind . '-msg-btn',
                'text'       => $atts['text'] ?: esc_attr_x( 'Send Message', 'Tutor LMS Integration', 'bp-better-messages' ),
                'user_id'    => $user_id,
                'unique_tag' => 'tutorlms_' . $kind . '_chat_' . $user_id,
            ) );

            if ( empty( $html ) ) return '';

            return '<div class="bm-tutor-pm-wrap">' . $html . '</div>';
        }

        public function instructor_profile_message_button()
        {
            $this->render_profile_message_button( 'instructor' );
        }

        public function student_profile_message_button()
        {
            $this->render_profile_message_button( 'student' );
        }

        private function render_profile_message_button( $type )
        {
            $user_id = $this->get_profile_user_id();
            if ( ! $this->can_render_message_button( $user_id ) ) return;

            $html = $this->render_live_chat_button( array(
                'class'      => 'tutor-btn tutor-btn-outline-primary bm-tutor-' . $type . '-msg-btn',
                'text'       => esc_attr_x( 'Send Message', 'Tutor LMS Integration', 'bp-better-messages' ),
                'user_id'    => $user_id,
                'unique_tag' => 'tutorlms_' . $type . '_chat_' . $user_id,
            ) );

            if ( ! empty( $html ) ) {
                echo '<div class="bm-tutor-pm-wrap">' . $html . '</div>';
            }
        }

        public function profile_button_position_script()
        {
            if ( ! get_query_var( 'tutor_profile_username' ) ) return;
            ?>
            <script>
            (function(){
                var btn = document.querySelector('.bm-tutor-pm-wrap');
                var ppArea = document.querySelector('.tutor-user-public-profile .pp-area .profile-name');
                if ( btn && ppArea ) {
                    btn.style.marginTop = '12px';
                    ppArea.appendChild( btn );
                }
            })();
            </script>
            <?php
        }

        private function get_profile_user_id()
        {
            $username = get_query_var( 'tutor_profile_username' );
            if ( ! $username ) return 0;

            $user = tutor_utils()->get_user_by_login( $username );
            if ( ! $user || empty( $user->ID ) ) return 0;

            return (int) $user->ID;
        }

        public function on_user_enrolled( $course_id, $user_id, $enrolled_id )
        {
            if ( Better_Messages()->settings['tutorLmsGroupChat'] !== '1' ) return;

            $thread_id = $this->get_course_thread_id( $course_id );
            if ( ! $thread_id ) return;

            $this->sync_thread_members( $thread_id );
        }

        public function on_user_unenrolled( $course_id, $user_id )
        {
            if ( Better_Messages()->settings['tutorLmsGroupChat'] !== '1' ) return;

            $thread_id = $this->get_course_thread_id( $course_id, false );
            if ( ! $thread_id ) return;

            $this->sync_thread_members( $thread_id );
        }

        public function sync_thread_members( $thread_id )
        {
            $thread_id = (int) $thread_id;
            if ( ! $thread_id ) return false;

            wp_cache_delete( 'thread_recipients_' . $thread_id, 'bm_messages' );
            wp_cache_delete( 'bm_thread_recipients_' . $thread_id, 'bm_messages' );

            $course_id = (int) Better_Messages()->functions->get_thread_meta( $thread_id, 'tutorlms_course_id' );
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
                    if ( $user_id < 0 ) continue;

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
            if ( Better_Messages()->settings['tutorLmsGroupChat'] !== '1' ) return $has;
            return count( $this->get_user_courses( $user_id ) ) > 0;
        }

        public function bulk_get_all_groups( $groups )
        {
            $courses = get_posts( array(
                'post_type'      => 'courses',
                'post_status'    => 'publish',
                'posts_per_page' => -1,
                'fields'         => 'ids',
            ) );

            if ( ! $courses ) return $groups;

            foreach ( $courses as $course_id ) {
                $thread_id = $this->get_course_thread_id( $course_id, false );
                if ( ! $thread_id ) continue;

                $groups[] = array(
                    'group_id'  => 'tutor_course_' . $course_id,
                    'thread_id' => $thread_id,
                );
            }

            return $groups;
        }

        public function bulk_get_group_members( $members, $group )
        {
            if ( ! isset( $group['group_id'] ) ) return $members;
            if ( strpos( $group['group_id'], 'tutor_course_' ) !== 0 ) return $members;

            $course_id = (int) str_replace( 'tutor_course_', '', $group['group_id'] );

            return $this->get_course_members( $course_id );
        }

        public function is_valid_group( $is_valid, $thread_id )
        {
            $course_id = (int) Better_Messages()->functions->get_thread_meta( $thread_id, 'tutorlms_course_id' );

            if ( $course_id && get_post_status( $course_id ) === 'publish' ) {
                return true;
            }

            return $is_valid;
        }

        public function has_access_to_group_chat( $has_access, $thread_id, $user_id )
        {
            $course_id = $this->get_thread_course_id( $thread_id );

            if ( ! $course_id ) return $has_access;

            if ( current_user_can( 'manage_options' ) ) return true;

            $instructors = tutor_utils()->get_instructors_by_course( $course_id );
            if ( $instructors ) {
                foreach ( $instructors as $instructor ) {
                    if ( (int) $instructor->ID === (int) $user_id ) return true;
                }
            }

            if ( tutor_utils()->is_enrolled( $course_id, $user_id ) ) return true;

            return false;
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

        public function add_dashboard_tab( $items )
        {
            $items['messages'] = array(
                'title' => _x( 'Messages', 'Tutor LMS Integration', 'bp-better-messages' ),
                'icon'  => class_exists( '\\Tutor\\Components\\SvgIcon' ) ? 'comments' : 'tutor-icon-comment',
            );

            return $items;
        }

        public function route_dashboard_messages( $other )
        {
            if ( get_query_var( 'tutor_dashboard_page' ) === 'messages' ) {
                $stub = Better_Messages()->path . 'addons/tutorlms-dashboard-messages.php';
                if ( file_exists( $stub ) ) {
                    return $stub;
                }
            }

            return $other;
        }

        public function thread_item( $thread_item, $thread_id, $thread_type, $include_personal, $user_id )
        {
            if ( Better_Messages()->settings['coursesShowInfoCard'] !== '1' ) return $thread_item;

            $course_id = $this->get_thread_course_id( $thread_id );

            if ( ! $course_id ) {
                $unique_tag = Better_Messages()->functions->get_thread_meta( $thread_id, 'unique_tag' );

                if ( ! empty( $unique_tag ) && strpos( $unique_tag, 'tutorlms_course_chat_' ) === 0 ) {
                    $parts = explode( '|', $unique_tag );
                    if ( isset( $parts[0] ) ) {
                        $course_id = (int) str_replace( 'tutorlms_course_chat_', '', $parts[0] );
                    }
                }
            }

            if ( ! $course_id ) return $thread_item;

            $thread_info  = isset( $thread_item['threadInfo'] ) ? $thread_item['threadInfo'] : '';
            $thread_info .= $this->course_thread_info_html( $course_id );
            $thread_item['threadInfo'] = $thread_info;

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
                    _x( 'Instructor: %s', 'Tutor LMS Integration', 'bp-better-messages' ),
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
            if ( ! function_exists( 'tutor_utils' ) ) return $item;

            $is_instructor = tutor_utils()->is_instructor( $user_id, true );
            $profile_url   = tutor_utils()->profile_url( $user_id, $is_instructor );

            if ( $profile_url && $profile_url !== '#' ) {
                $item['url'] = esc_url( $profile_url );
            }

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
                 WHERE tm.meta_key = 'tutorlms_course_id' AND tm.meta_value = %s
                 LIMIT 1",
                $course_id
            ) );

            if ( $thread_id ) return (int) $thread_id;

            if ( ! $create ) return false;

            $course = get_post( $course_id );

            if ( ! $course || $course->post_type !== 'courses' ) return false;

            $wpdb->insert(
                $threads_table,
                array(
                    'subject' => $course->post_title,
                    'type'    => 'course',
                )
            );

            $thread_id = $wpdb->insert_id;

            if ( ! $thread_id ) return false;

            Better_Messages()->functions->update_thread_meta( $thread_id, 'tutorlms_course_id', $course_id );

            $this->sync_thread_members( $thread_id );

            return (int) $thread_id;
        }

        public function get_thread_course_id( $thread_id )
        {
            $course_id = Better_Messages()->functions->get_thread_meta( $thread_id, 'tutorlms_course_id' );

            if ( ! $course_id ) return false;

            return (int) $course_id;
        }

        public function get_course_members( $course_id )
        {
            global $wpdb;

            $members = array();

            $instructors = tutor_utils()->get_instructors_by_course( $course_id );
            if ( $instructors ) {
                foreach ( $instructors as $instructor ) {
                    $members[] = (int) $instructor->ID;
                }
            }

            $enrolled_post_type = apply_filters( 'tutor_enrollment_post_type', 'tutor_enrolled' );

            $students = $wpdb->get_col( $wpdb->prepare(
                "SELECT post_author FROM {$wpdb->posts}
                 WHERE post_type = %s AND post_parent = %d AND post_status = %s",
                $enrolled_post_type,
                $course_id,
                'completed'
            ) );

            if ( $students ) {
                foreach ( $students as $student_id ) {
                    $members[] = (int) $student_id;
                }
            }

            return array_unique( $members );
        }

        private function get_user_courses( $user_id )
        {
            $authored = get_posts( array(
                'post_type'      => 'courses',
                'post_status'    => 'publish',
                'author'         => $user_id,
                'posts_per_page' => -1,
                'fields'         => 'ids',
            ) );

            global $wpdb;
            $enrolled_post_type = apply_filters( 'tutor_enrollment_post_type', 'tutor_enrolled' );

            $enrolled = $wpdb->get_col( $wpdb->prepare(
                "SELECT post_parent FROM {$wpdb->posts}
                 WHERE post_type = %s AND post_author = %d AND post_status = %s",
                $enrolled_post_type,
                $user_id,
                'completed'
            ) );

            return array_unique( array_merge(
                $authored ? array_map( 'intval', $authored ) : array(),
                $enrolled ? array_map( 'intval', $enrolled ) : array()
            ) );
        }
    }
}
