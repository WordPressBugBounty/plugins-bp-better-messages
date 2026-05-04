<?php
defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Better_Messages_LearnDash' ) ) {

    class Better_Messages_LearnDash
    {

        public static function instance()
        {
            static $instance = null;

            if ( null === $instance ) {
                $instance = new Better_Messages_LearnDash();
            }

            return $instance;
        }

        public function __construct()
        {
            if ( Better_Messages()->settings['learnDashIntegration'] !== '1' ) return;

            if ( Better_Messages()->settings['learnDashMessageButton'] === '1' ) {
                add_action( 'learndash-course-before', array( $this, 'course_message_button' ), 10, 3 );
                add_action( 'learndash-course-payment-buttons-before', array( $this, 'course_message_button' ), 10, 2 );
            }

            if ( Better_Messages()->settings['learnDashCourseGroupChat'] === '1' ) {
                add_action( 'learndash_update_course_access', array( $this, 'on_user_course_access_changed' ), 10, 4 );
            }

            if ( Better_Messages()->settings['learnDashGroupChat'] === '1' ) {
                add_action( 'ld_added_group_access', array( $this, 'on_user_added_to_group' ), 10, 2 );
                add_action( 'ld_removed_group_access', array( $this, 'on_user_removed_from_group' ), 10, 2 );
            }

            if (
                Better_Messages()->settings['learnDashCourseGroupChat'] === '1'
                || Better_Messages()->settings['learnDashGroupChat'] === '1'
            ) {
                add_filter( 'better_messages_courses_active', array( $this, 'enabled' ) );
                add_filter( 'better_messages_get_courses', array( $this, 'get_courses' ), 10, 2 );
                add_filter( 'better_messages_user_has_courses', array( $this, 'user_has_courses' ), 10, 2 );
            }

            if (
                Better_Messages()->settings['learnDashCourseGroupChat'] === '1'
                || Better_Messages()->settings['learnDashGroupChat'] === '1'
            ) {
                add_filter( 'better_messages_bulk_get_all_groups', array( $this, 'bulk_get_all_groups' ), 10, 1 );
                add_filter( 'better_messages_bulk_get_group_members', array( $this, 'bulk_get_group_members' ), 10, 2 );
                add_filter( 'better_messages_is_valid_course', array( $this, 'is_valid_group' ), 10, 2 );
                add_filter( 'better_messages_has_access_to_group_chat', array( $this, 'has_access_to_group_chat' ), 10, 3 );
                add_filter( 'better_messages_thread_image', array( $this, 'group_thread_image' ), 10, 3 );
                add_filter( 'better_messages_thread_url', array( $this, 'group_thread_url' ), 10, 3 );

                add_filter( 'better_messages_rest_thread_item', array( $this, 'thread_item' ), 10, 5 );
            }
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
                'class'      => 'ld-button',
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

        public function course_message_button( $arg1 = 0, $arg2 = 0, $arg3 = 0 )
        {
            $course_id = 0;
            if ( $arg2 && get_post_type( (int) $arg2 ) === 'sfwd-courses' ) {
                $course_id = (int) $arg2;
            } elseif ( $arg1 && get_post_type( (int) $arg1 ) === 'sfwd-courses' ) {
                $course_id = (int) $arg1;
            }
            if ( ! $course_id ) {
                $course_id = (int) get_the_ID();
            }
            if ( ! $course_id ) return;
            if ( get_post_type( $course_id ) !== 'sfwd-courses' ) return;

            static $rendered = array();
            if ( isset( $rendered[ $course_id ] ) ) return;
            $rendered[ $course_id ] = true;

            $instructor_id = (int) get_post_field( 'post_author', $course_id );
            if ( ! $this->can_render_message_button( $instructor_id ) ) return;

            $course_title = get_the_title( $course_id );
            if ( ! $course_title ) return;

            $html = $this->render_live_chat_button( array(
                'class'      => 'ld-button bm-learndash-message-btn',
                'text'       => esc_attr_x( 'Message Instructor', 'LearnDash Integration', 'bp-better-messages' ),
                'user_id'    => $instructor_id,
                'unique_tag' => 'learndash_course_chat_' . $course_id,
                'subject'    => esc_attr( sprintf(
                    _x( 'Question about course "%s"', 'LearnDash Integration', 'bp-better-messages' ),
                    $course_title
                ) ),
            ) );

            if ( ! empty( $html ) ) {
                echo '<div class="bm-learndash-pm-wrap">' . $html . '</div>';
            }
        }

        public function on_user_course_access_changed( $user_id, $course_id, $access_list, $remove )
        {
            if ( Better_Messages()->settings['learnDashCourseGroupChat'] !== '1' ) return;

            $course_id = (int) $course_id;
            if ( ! $course_id ) return;

            $thread_id = $this->get_course_thread_id( $course_id, ! $remove );
            if ( ! $thread_id ) return;

            $this->sync_thread_members( $thread_id );
        }

        public function on_user_added_to_group( $user_id, $group_id )
        {
            if ( Better_Messages()->settings['learnDashGroupChat'] !== '1' ) return;

            $group_id = (int) $group_id;
            if ( ! $group_id ) return;

            $thread_id = $this->get_group_thread_id( $group_id );
            if ( ! $thread_id ) return;

            $this->sync_thread_members( $thread_id );
        }

        public function on_user_removed_from_group( $user_id, $group_id )
        {
            if ( Better_Messages()->settings['learnDashGroupChat'] !== '1' ) return;

            $group_id = (int) $group_id;
            if ( ! $group_id ) return;

            $thread_id = $this->get_group_thread_id( $group_id, false );
            if ( ! $thread_id ) return;

            $this->sync_thread_members( $thread_id );
        }

        public function sync_thread_members( $thread_id )
        {
            $thread_id = (int) $thread_id;
            if ( ! $thread_id ) return false;

            wp_cache_delete( 'thread_recipients_' . $thread_id, 'bm_messages' );
            wp_cache_delete( 'bm_thread_recipients_' . $thread_id, 'bm_messages' );

            $course_id = (int) Better_Messages()->functions->get_thread_meta( $thread_id, 'learndash_course_id' );
            $group_id  = (int) Better_Messages()->functions->get_thread_meta( $thread_id, 'learndash_group_id' );

            if ( $course_id ) {
                $members = $this->get_course_members( $course_id );
            } elseif ( $group_id ) {
                $members = $this->get_group_members( $group_id );
            } else {
                return false;
            }

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

            if ( Better_Messages()->settings['learnDashCourseGroupChat'] === '1' ) {
                foreach ( $this->get_user_courses( $user_id ) as $course_id ) {
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
            }

            if ( Better_Messages()->settings['learnDashGroupChat'] === '1' ) {
                foreach ( $this->get_user_groups( $user_id ) as $group_id ) {
                    $thread_id = $this->get_group_thread_id( $group_id, false );
                    if ( ! $thread_id ) continue;

                    $image = get_the_post_thumbnail_url( $group_id, 'thumbnail' );

                    $courses[] = array(
                        'course_id' => $group_id,
                        'name'      => get_the_title( $group_id ),
                        'image'     => $image ? $image : '',
                        'url'       => get_permalink( $group_id ),
                        'thread_id' => $thread_id,
                        'messages'  => 1,
                    );
                }
            }

            return $courses;
        }

        public function user_has_courses( $has, $user_id )
        {
            if ( $has ) return $has;
            if ( $user_id <= 0 ) return false;
            if ( Better_Messages()->settings['learnDashCourseGroupChat'] === '1'
                && count( $this->get_user_courses( $user_id ) ) > 0 ) return true;
            if ( Better_Messages()->settings['learnDashGroupChat'] === '1'
                && count( $this->get_user_groups( $user_id ) ) > 0 ) return true;
            return $has;
        }

        public function bulk_get_all_groups( $groups )
        {
            if ( Better_Messages()->settings['learnDashCourseGroupChat'] === '1' ) {
                $courses = get_posts( array(
                    'post_type'      => 'sfwd-courses',
                    'post_status'    => 'publish',
                    'posts_per_page' => -1,
                    'fields'         => 'ids',
                ) );

                if ( $courses ) {
                    foreach ( $courses as $course_id ) {
                        $thread_id = $this->get_course_thread_id( $course_id, false );
                        if ( ! $thread_id ) continue;

                        $groups[] = array(
                            'group_id'  => 'learndash_course_' . $course_id,
                            'thread_id' => $thread_id,
                        );
                    }
                }
            }

            if ( Better_Messages()->settings['learnDashGroupChat'] === '1' ) {
                $ld_groups = get_posts( array(
                    'post_type'      => 'groups',
                    'post_status'    => 'publish',
                    'posts_per_page' => -1,
                    'fields'         => 'ids',
                ) );

                if ( $ld_groups ) {
                    foreach ( $ld_groups as $group_id ) {
                        $thread_id = $this->get_group_thread_id( $group_id, false );
                        if ( ! $thread_id ) continue;

                        $groups[] = array(
                            'group_id'  => 'learndash_group_' . $group_id,
                            'thread_id' => $thread_id,
                        );
                    }
                }
            }

            return $groups;
        }

        public function bulk_get_group_members( $members, $group )
        {
            if ( ! isset( $group['group_id'] ) ) return $members;

            if ( strpos( $group['group_id'], 'learndash_course_' ) === 0 ) {
                $course_id = (int) str_replace( 'learndash_course_', '', $group['group_id'] );
                return $this->get_course_members( $course_id );
            }

            if ( strpos( $group['group_id'], 'learndash_group_' ) === 0 ) {
                $group_id = (int) str_replace( 'learndash_group_', '', $group['group_id'] );
                return $this->get_group_members( $group_id );
            }

            return $members;
        }

        public function is_valid_group( $is_valid, $thread_id )
        {
            $course_id = (int) Better_Messages()->functions->get_thread_meta( $thread_id, 'learndash_course_id' );
            if ( $course_id && get_post_status( $course_id ) === 'publish' ) {
                return true;
            }

            $group_id = (int) Better_Messages()->functions->get_thread_meta( $thread_id, 'learndash_group_id' );
            if ( $group_id && get_post_status( $group_id ) === 'publish' ) {
                return true;
            }

            return $is_valid;
        }

        public function has_access_to_group_chat( $has_access, $thread_id, $user_id )
        {
            if ( current_user_can( 'manage_options' ) ) return true;

            $course_id = $this->get_thread_course_id( $thread_id );
            if ( $course_id ) {
                $instructor_id = (int) get_post_field( 'post_author', $course_id );
                if ( $instructor_id && (int) $instructor_id === (int) $user_id ) return true;

                if ( sfwd_lms_has_access( $course_id, $user_id ) ) return true;

                return false;
            }

            $group_id = $this->get_thread_group_id( $thread_id );
            if ( $group_id ) {
                if ( learndash_is_user_in_group( $user_id, $group_id ) ) return true;

                $leaders = learndash_get_groups_administrators( $group_id );
                if ( $leaders ) {
                    foreach ( $leaders as $leader ) {
                        if ( (int) $leader->ID === (int) $user_id ) return true;
                    }
                }

                return false;
            }

            return $has_access;
        }

        public function group_thread_image( $image, $thread_id, $thread )
        {
            $course_id = $this->get_thread_course_id( $thread_id );
            if ( $course_id ) {
                $course_image = get_the_post_thumbnail_url( $course_id, 'thumbnail' );
                if ( $course_image ) return $course_image;
                return $this->get_default_course_image_html();
            }

            $group_id = $this->get_thread_group_id( $thread_id );
            if ( $group_id ) {
                $group_image = get_the_post_thumbnail_url( $group_id, 'thumbnail' );
                if ( $group_image ) return $group_image;
                return $this->get_default_course_image_html();
            }

            return $image;
        }

        public function get_default_course_image_html()
        {
            return 'html:<span style="display:flex;align-items:center;justify-content:center;width:100%;height:100%;background:rgba(0,0,0,0.05);color:rgba(0,0,0,0.45);border-radius:50%;aspect-ratio:1/1;box-sizing:border-box"><svg style="width:60%;height:60%;max-width:36px;max-height:36px" viewBox="0 0 24 24" stroke="currentColor" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 10v6M2 10l10-5 10 5-10 5z"></path><path d="M6 12v5c3 3 9 3 12 0v-5"></path></svg></span>';
        }

        public function group_thread_url( $url, $thread_id, $thread )
        {
            $course_id = $this->get_thread_course_id( $thread_id );
            if ( $course_id ) {
                $permalink = get_permalink( $course_id );
                if ( $permalink ) return $permalink;
            }

            $group_id = $this->get_thread_group_id( $thread_id );
            if ( $group_id ) {
                $permalink = get_permalink( $group_id );
                if ( $permalink ) return $permalink;
            }

            return $url;
        }

        public function thread_item( $thread_item, $thread_id, $thread_type, $include_personal, $user_id )
        {
            if ( Better_Messages()->settings['coursesShowInfoCard'] !== '1' ) return $thread_item;

            $course_id = $this->get_thread_course_id( $thread_id );

            if ( $course_id ) {
                $thread_info  = isset( $thread_item['threadInfo'] ) ? $thread_item['threadInfo'] : '';
                $thread_info .= $this->course_thread_info_html( $course_id );
                $thread_item['threadInfo'] = $thread_info;
                return $thread_item;
            }

            $group_id = $this->get_thread_group_id( $thread_id );

            if ( $group_id ) {
                $thread_info  = isset( $thread_item['threadInfo'] ) ? $thread_item['threadInfo'] : '';
                $thread_info .= $this->group_thread_info_html( $group_id );
                $thread_item['threadInfo'] = $thread_info;
                return $thread_item;
            }

            $unique_tag = Better_Messages()->functions->get_thread_meta( $thread_id, 'unique_tag' );

            if ( ! empty( $unique_tag ) && strpos( $unique_tag, 'learndash_course_chat_' ) === 0 ) {
                $parts = explode( '|', $unique_tag );
                $course_id = (int) str_replace( 'learndash_course_chat_', '', $parts[0] );
                if ( $course_id ) {
                    $thread_info  = isset( $thread_item['threadInfo'] ) ? $thread_item['threadInfo'] : '';
                    $thread_info .= $this->course_thread_info_html( $course_id );
                    $thread_item['threadInfo'] = $thread_info;
                }
            }

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
                    _x( 'Instructor: %s', 'LearnDash Integration', 'bp-better-messages' ),
                    $instructor_name
                ) ) . '</div>';
            }

            $html .= '</div>';
            $html .= '</div>';

            return $html;
        }

        public function group_thread_info_html( $group_id )
        {
            $group = get_post( $group_id );

            if ( ! $group ) return '';

            $title    = esc_html( $group->post_title );
            $url      = get_permalink( $group_id );
            $image_id = get_post_thumbnail_id( $group_id );

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
            $html .= '<div class="bm-product-subtitle">' . esc_html_x( 'LearnDash Group', 'LearnDash Integration', 'bp-better-messages' ) . '</div>';
            $html .= '</div>';
            $html .= '</div>';

            return $html;
        }

        public function get_course_thread_id( $course_id, $create = true )
        {
            global $wpdb;

            $course_id = (int) $course_id;
            if ( ! $course_id ) return false;

            $threadsmeta_table = bm_get_table( 'threadsmeta' );
            $threads_table     = bm_get_table( 'threads' );

            $thread_id = $wpdb->get_var( $wpdb->prepare(
                "SELECT tm.bm_thread_id FROM {$threadsmeta_table} tm
                 INNER JOIN {$threads_table} t ON t.id = tm.bm_thread_id
                 WHERE tm.meta_key = 'learndash_course_id' AND tm.meta_value = %s
                 LIMIT 1",
                $course_id
            ) );

            if ( $thread_id ) return (int) $thread_id;

            if ( ! $create ) return false;

            $course = get_post( $course_id );

            if ( ! $course || $course->post_type !== 'sfwd-courses' ) return false;

            $wpdb->insert(
                $threads_table,
                array(
                    'subject' => $course->post_title,
                    'type'    => 'course',
                )
            );

            $thread_id = $wpdb->insert_id;

            if ( ! $thread_id ) return false;

            Better_Messages()->functions->update_thread_meta( $thread_id, 'learndash_course_id', $course_id );

            $this->sync_thread_members( $thread_id );

            return (int) $thread_id;
        }

        public function get_group_thread_id( $group_id, $create = true )
        {
            global $wpdb;

            $group_id = (int) $group_id;
            if ( ! $group_id ) return false;

            $threadsmeta_table = bm_get_table( 'threadsmeta' );
            $threads_table     = bm_get_table( 'threads' );

            $thread_id = $wpdb->get_var( $wpdb->prepare(
                "SELECT tm.bm_thread_id FROM {$threadsmeta_table} tm
                 INNER JOIN {$threads_table} t ON t.id = tm.bm_thread_id
                 WHERE tm.meta_key = 'learndash_group_id' AND tm.meta_value = %s
                 LIMIT 1",
                $group_id
            ) );

            if ( $thread_id ) return (int) $thread_id;

            if ( ! $create ) return false;

            $group = get_post( $group_id );

            if ( ! $group || $group->post_type !== 'groups' ) return false;

            $wpdb->insert(
                $threads_table,
                array(
                    'subject' => $group->post_title,
                    'type'    => 'course',
                )
            );

            $thread_id = $wpdb->insert_id;

            if ( ! $thread_id ) return false;

            Better_Messages()->functions->update_thread_meta( $thread_id, 'learndash_group_id', $group_id );

            $this->sync_thread_members( $thread_id );

            return (int) $thread_id;
        }

        public function get_thread_course_id( $thread_id )
        {
            $course_id = Better_Messages()->functions->get_thread_meta( $thread_id, 'learndash_course_id' );
            return $course_id ? (int) $course_id : false;
        }

        public function get_thread_group_id( $thread_id )
        {
            $group_id = Better_Messages()->functions->get_thread_meta( $thread_id, 'learndash_group_id' );
            return $group_id ? (int) $group_id : false;
        }

        public function get_course_members( $course_id )
        {
            $members = array();

            $instructor_id = (int) get_post_field( 'post_author', $course_id );
            if ( $instructor_id ) {
                $members[] = $instructor_id;
            }

            $student_query = learndash_get_users_for_course( $course_id, array( 'fields' => 'ID' ), false );

            if ( $student_query instanceof WP_User_Query ) {
                $student_ids = $student_query->get_results();
            } elseif ( is_array( $student_query ) ) {
                $student_ids = $student_query;
            } else {
                $student_ids = array();
            }

            foreach ( $student_ids as $student_id ) {
                $members[] = (int) $student_id;
            }

            return array_values( array_unique( array_filter( $members ) ) );
        }

        public function get_group_members( $group_id )
        {
            $members = array();

            $leader_users = learndash_get_groups_administrators( $group_id );
            if ( $leader_users ) {
                foreach ( $leader_users as $leader ) {
                    $members[] = (int) $leader->ID;
                }
            }

            $student_ids = learndash_get_groups_user_ids( $group_id );
            if ( is_array( $student_ids ) ) {
                foreach ( $student_ids as $student_id ) {
                    $members[] = (int) $student_id;
                }
            }

            return array_values( array_unique( array_filter( $members ) ) );
        }

        private function get_user_courses( $user_id )
        {
            $authored = get_posts( array(
                'post_type'      => 'sfwd-courses',
                'post_status'    => 'publish',
                'author'         => $user_id,
                'posts_per_page' => -1,
                'fields'         => 'ids',
            ) );

            $enrolled = learndash_user_get_enrolled_courses( $user_id );

            return array_values( array_unique( array_merge(
                $authored ? array_map( 'intval', $authored ) : array(),
                is_array( $enrolled ) ? array_map( 'intval', $enrolled ) : array()
            ) ) );
        }

        private function get_user_groups( $user_id )
        {
            $member_groups = learndash_get_users_group_ids( $user_id );
            $leader_groups = learndash_get_administrators_group_ids( $user_id );

            return array_values( array_unique( array_merge(
                is_array( $member_groups ) ? array_map( 'intval', $member_groups ) : array(),
                is_array( $leader_groups ) ? array_map( 'intval', $leader_groups ) : array()
            ) ) );
        }
    }
}
