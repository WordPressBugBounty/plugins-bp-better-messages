<?php
defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Better_Messages_LearnPress' ) ) {

    class Better_Messages_LearnPress
    {

        public static function instance()
        {
            static $instance = null;

            if ( null === $instance ) {
                $instance = new Better_Messages_LearnPress();
            }

            return $instance;
        }

        public function __construct()
        {
            add_shortcode( 'better_messages_learnpress_course_button',     array( $this, 'course_button_shortcode' ) );
            add_shortcode( 'better_messages_learnpress_instructor_button', array( $this, 'instructor_button_shortcode' ) );

            if ( Better_Messages()->settings['learnPressIntegration'] !== '1' ) return;

            add_filter( 'learn-press/single-course/modern/section-right/buttons', array( $this, 'course_message_button_modern' ), 10, 3 );
            add_action( 'learn-press/after-course-buttons', array( $this, 'course_message_button' ), 10 );

            add_action( 'learnpress/user/course-enrolled', array( $this, 'on_user_enrolled' ), 10, 3 );
            add_action( 'learn-press/user-course-finished', array( $this, 'on_user_finished' ), 10, 3 );
            add_action( 'learn-press/user-item-old/delete', array( $this, 'on_user_unenrolled' ), 10, 2 );

            if ( Better_Messages()->settings['learnPressGroupChat'] === '1' ) {
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

            $profile_tab_required = ( Better_Messages()->settings['chatPage'] === 'learnpress-profile' );

            if ( Better_Messages()->settings['learnPressProfileTab'] === '1' || $profile_tab_required ) {
                add_filter( 'learn-press/profile-tabs', array( $this, 'add_profile_tab' ) );
            }

            if ( Better_Messages()->settings['learnPressInstructorPMButton'] === '1' ) {
                add_filter( 'learn-press/single-instructor/info-right/sections', array( $this, 'instructor_profile_message_button' ), 10, 2 );
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
                'class'      => 'wp-block-learnpress-course-button lp-button',
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

        private function splice_before_wrapper_end( array $components, array $insert )
        {
            if ( ! array_key_exists( 'wrapper_end', $components ) ) {
                return $components + $insert;
            }

            $pos = array_search( 'wrapper_end', array_keys( $components ), true );

            return array_slice( $components, 0, $pos, true ) + $insert + array_slice( $components, $pos, null, true );
        }

        private function get_message_button_html( $course_id )
        {
            $instructor_id = $this->get_course_instructor_id( $course_id );

            if ( ! $this->can_render_message_button( $instructor_id ) ) return '';

            $course_title = get_the_title( $course_id );
            if ( ! $course_title ) return '';

            return $this->render_live_chat_button( array(
                'class'      => 'wp-block-learnpress-course-button lp-button bm-lp-message-btn',
                'text'       => esc_attr_x( 'Message Instructor', 'LearnPress Integration', 'bp-better-messages' ),
                'user_id'    => $instructor_id,
                'unique_tag' => 'learnpress_course_chat_' . $course_id,
                'subject'    => esc_attr( sprintf(
                    _x( 'Question about course "%s"', 'LearnPress Integration', 'bp-better-messages' ),
                    $course_title
                ) ),
            ) );
        }

        public function course_message_button_modern( $buttons, $course, $user )
        {
            $html = $this->get_message_button_html( $course->get_id() );

            if ( empty( $html ) ) return $buttons;

            return $this->splice_before_wrapper_end( $buttons, array( 'btn_message_instructor' => $html ) );
        }

        public function instructor_profile_message_button( $sections, $instructor )
        {
            if ( ! is_object( $instructor ) || ! method_exists( $instructor, 'get_id' ) ) return $sections;

            $instructor_id = (int) $instructor->get_id();

            if ( ! $this->can_render_message_button( $instructor_id ) ) return $sections;

            $html = $this->render_live_chat_button( array(
                'class'      => 'wp-block-learnpress-course-button lp-button bm-lp-instructor-msg-btn',
                'text'       => esc_attr_x( 'Send Message', 'LearnPress Integration', 'bp-better-messages' ),
                'user_id'    => $instructor_id,
                'unique_tag' => 'learnpress_instructor_chat_' . $instructor_id,
            ) );

            if ( empty( $html ) ) return $sections;

            return $this->splice_before_wrapper_end(
                $sections,
                array( 'btn_message_instructor' => '<div class="bm-instructor-pm-wrap">' . $html . '</div>' )
            );
        }

        public function course_message_button()
        {
            $course_id = get_the_ID();

            if ( ! $course_id ) return;

            $html = $this->get_message_button_html( $course_id );

            if ( ! empty( $html ) ) {
                echo $html;
            }
        }

        public function course_button_shortcode( $atts = array() )
        {
            $atts = shortcode_atts( array(
                'course_id' => 0,
                'user_id'   => 0,
                'class'     => '',
                'text'      => '',
            ), $atts, 'better_messages_learnpress_course_button' );

            $course_id = (int) ( $atts['course_id'] ?: get_the_ID() );
            if ( ! $course_id || get_post_type( $course_id ) !== 'lp_course' ) return '';

            $instructor_id = (int) $atts['user_id'] ?: $this->get_course_instructor_id( $course_id );
            if ( ! $this->can_render_message_button( $instructor_id ) ) return '';

            $course_title = get_the_title( $course_id );
            if ( ! $course_title ) return '';

            return $this->render_live_chat_button( array(
                'class'      => $atts['class'] ?: 'wp-block-learnpress-course-button lp-button bm-lp-message-btn',
                'text'       => $atts['text'] ?: esc_attr_x( 'Message Instructor', 'LearnPress Integration', 'bp-better-messages' ),
                'user_id'    => $instructor_id,
                'unique_tag' => 'learnpress_course_chat_' . $course_id,
                'subject'    => esc_attr( sprintf(
                    _x( 'Question about course "%s"', 'LearnPress Integration', 'bp-better-messages' ),
                    $course_title
                ) ),
            ) );
        }

        public function instructor_button_shortcode( $atts = array() )
        {
            $atts = shortcode_atts( array(
                'user_id' => 0,
                'class'   => '',
                'text'    => '',
            ), $atts, 'better_messages_learnpress_instructor_button' );

            $instructor_id = (int) $atts['user_id'];

            if ( ! $instructor_id && is_author() ) {
                $instructor_id = (int) get_queried_object_id();
            }

            if ( ! $this->can_render_message_button( $instructor_id ) ) return '';

            $html = $this->render_live_chat_button( array(
                'class'      => $atts['class'] ?: 'wp-block-learnpress-course-button lp-button bm-lp-instructor-msg-btn',
                'text'       => $atts['text'] ?: esc_attr_x( 'Send Message', 'LearnPress Integration', 'bp-better-messages' ),
                'user_id'    => $instructor_id,
                'unique_tag' => 'learnpress_instructor_chat_' . $instructor_id,
            ) );

            if ( empty( $html ) ) return '';

            return '<div class="bm-instructor-pm-wrap">' . $html . '</div>';
        }

        public function on_user_enrolled( $order_id, $course_id, $user_id )
        {
            if ( Better_Messages()->settings['learnPressGroupChat'] !== '1' ) return;

            $thread_id = $this->get_course_thread_id( $course_id );

            if ( ! $thread_id ) return;

            $this->sync_thread_members( $thread_id );
        }

        public function on_user_unenrolled( $user_id, $course_id )
        {
            if ( Better_Messages()->settings['learnPressGroupChat'] !== '1' ) return;

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

            $course_id = (int) Better_Messages()->functions->get_thread_meta( $thread_id, 'learnpress_course_id' );
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

        public function on_user_finished( $course_id, $user_id, $return )
        {
            do_action( 'better_messages_learnpress_user_finished_course', $course_id, $user_id );
        }

        public function get_courses( $courses, $user_id )
        {
            if ( $user_id <= 0 ) return $courses;

            $user = learn_press_get_user( $user_id );

            if ( ! $user || ! $user->get_id() ) return $courses;

            $course_ids = $this->get_user_course_ids( $user_id );

            foreach ( $course_ids as $course_id ) {
                $course = learn_press_get_course( $course_id );

                if ( ! $course ) continue;

                $thread_id = $this->get_course_thread_id( $course_id, false );

                if ( ! $thread_id ) continue;

                $image = get_the_post_thumbnail_url( $course_id, 'thumbnail' );

                $courses[] = array(
                    'course_id' => $course_id,
                    'name'      => $course->get_title(),
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
            if ( Better_Messages()->settings['learnPressGroupChat'] !== '1' ) return $has;
            return count( $this->get_user_course_ids( $user_id ) ) > 0;
        }

        private function get_user_course_ids( $user_id )
        {
            $instructor_courses = get_posts( array(
                'post_type'      => 'lp_course',
                'post_status'    => 'publish',
                'author'         => $user_id,
                'posts_per_page' => -1,
                'fields'         => 'ids',
            ) );

            global $wpdb;
            $table_name = $wpdb->prefix . 'learnpress_user_items';

            $enrolled_courses = $wpdb->get_col( $wpdb->prepare(
                "SELECT DISTINCT item_id FROM {$table_name}
                 WHERE user_id = %d AND item_type = %s AND status IN ('enrolled', 'finished')",
                $user_id,
                'lp_course'
            ) );

            return array_unique( array_merge(
                $instructor_courses ? $instructor_courses : array(),
                $enrolled_courses ? $enrolled_courses : array()
            ) );
        }

        public function bulk_get_all_groups( $groups )
        {
            $courses = get_posts( array(
                'post_type'      => 'lp_course',
                'post_status'    => 'publish',
                'posts_per_page' => -1,
                'fields'         => 'ids',
            ) );

            if ( ! $courses ) return $groups;

            foreach ( $courses as $course_id ) {
                $thread_id = $this->get_course_thread_id( $course_id, false );

                if ( ! $thread_id ) continue;

                $groups[] = array(
                    'group_id'  => 'lp_course_' . $course_id,
                    'thread_id' => $thread_id,
                );
            }

            return $groups;
        }

        public function bulk_get_group_members( $members, $group )
        {
            if ( ! isset( $group['group_id'] ) ) return $members;
            if ( strpos( $group['group_id'], 'lp_course_' ) !== 0 ) return $members;

            $course_id = (int) str_replace( 'lp_course_', '', $group['group_id'] );

            return $this->get_course_members( $course_id );
        }

        public function is_valid_group( $is_valid, $thread_id )
        {
            $course_id = (int) Better_Messages()->functions->get_thread_meta( $thread_id, 'learnpress_course_id' );

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

            $course = learn_press_get_course( $course_id );

            if ( $course && $this->get_course_instructor_id( $course_id ) === (int) $user_id ) return true;

            $lp_user = learn_press_get_user( $user_id );

            if ( $lp_user && $lp_user->get_id() && $lp_user->has_enrolled_or_finished( $course_id ) ) return true;

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

        public function add_profile_tab( $tabs )
        {
            $tabs['messages'] = array(
                'title'    => _x( 'Messages', 'LearnPress Integration', 'bp-better-messages' ),
                'slug'     => 'messages',
                'callback' => array( $this, 'render_profile_tab' ),
                'priority' => 50,
                'icon'     => '<i class="lp-icon-comment-o"></i>',
            );

            return $tabs;
        }

        public function render_profile_tab()
        {
            echo Better_Messages()->functions->get_page();
        }

        public function thread_item( $thread_item, $thread_id, $thread_type, $include_personal, $user_id )
        {
            if ( Better_Messages()->settings['coursesShowInfoCard'] !== '1' ) return $thread_item;

            $course_id = $this->get_thread_course_id( $thread_id );

            if ( ! $course_id ) {
                $unique_tag = Better_Messages()->functions->get_thread_meta( $thread_id, 'unique_tag' );

                if ( ! empty( $unique_tag ) && strpos( $unique_tag, 'learnpress_course_chat_' ) === 0 ) {
                    $parts = explode( '|', $unique_tag );
                    if ( isset( $parts[0] ) ) {
                        $course_id = (int) str_replace( 'learnpress_course_chat_', '', $parts[0] );
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
            $course = learn_press_get_course( $course_id );

            if ( ! $course ) return '';

            $title    = esc_html( $course->get_title() );
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

            $instructor_name = $course->get_instructor_name();
            if ( $instructor_name ) {
                $html .= '<div class="bm-product-subtitle">' . esc_html( sprintf(
                    _x( 'Instructor: %s', 'LearnPress Integration', 'bp-better-messages' ),
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

            if ( ! function_exists( 'learn_press_user_profile_link' ) ) return $item;

            $profile_url = learn_press_user_profile_link( $user_id );

            if ( $profile_url ) {
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
                 WHERE tm.meta_key = 'learnpress_course_id' AND tm.meta_value = %s
                 LIMIT 1",
                $course_id
            ) );

            if ( $thread_id ) return (int) $thread_id;

            if ( ! $create ) return false;

            $course = learn_press_get_course( $course_id );

            if ( ! $course ) return false;

            $wpdb->insert(
                $threads_table,
                array(
                    'subject' => $course->get_title(),
                    'type'    => 'course',
                )
            );

            $thread_id = $wpdb->insert_id;

            if ( ! $thread_id ) return false;

            Better_Messages()->functions->update_thread_meta( $thread_id, 'learnpress_course_id', $course_id );

            $this->sync_thread_members( $thread_id );

            return (int) $thread_id;
        }

        public function get_thread_course_id( $thread_id )
        {
            $course_id = Better_Messages()->functions->get_thread_meta( $thread_id, 'learnpress_course_id' );

            if ( ! $course_id ) return false;

            return (int) $course_id;
        }

        public function get_course_members( $course_id )
        {
            global $wpdb;

            $members = array();

            $course = learn_press_get_course( $course_id );
            if ( $course ) {
                $instructor_id = $this->get_course_instructor_id( $course_id );
                if ( $instructor_id ) {
                    $members[] = (int) $instructor_id;
                }
            }

            $table_name = $wpdb->prefix . 'learnpress_user_items';

            $students = $wpdb->get_col( $wpdb->prepare(
                "SELECT DISTINCT user_id FROM {$table_name}
                 WHERE item_id = %d AND item_type = %s AND status IN ('enrolled', 'finished')",
                $course_id,
                'lp_course'
            ) );

            if ( $students ) {
                foreach ( $students as $student_id ) {
                    $members[] = (int) $student_id;
                }
            }

            return array_unique( $members );
        }

        private function get_course_instructor_id( $course_id )
        {
            $course = learn_press_get_course( $course_id );

            if ( ! $course ) return 0;

            $instructor = $course->get_instructor();

            if ( $instructor && is_object( $instructor ) && method_exists( $instructor, 'get_id' ) ) {
                return (int) $instructor->get_id();
            }

            return 0;
        }
    }
}
