<?php

use FluentCommunity\App\Models\User;
use FluentCommunity\App\Services\Helper;
use FluentCommunity\Modules\Course\Model\Course;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Better_Messages_Fluent_Community_Courses' ) ) {

    class Better_Messages_Fluent_Community_Courses
    {
        public static function instance()
        {
            static $instance = null;

            if ( null === $instance ) {
                $instance = new Better_Messages_Fluent_Community_Courses();
            }

            return $instance;
        }

        public function __construct()
        {
            add_filter( 'better_messages_is_valid_course',         array( $this, 'is_valid_group' ), 10, 2 );
            add_filter( 'better_messages_has_access_to_group_chat',array( $this, 'has_access_to_group_chat' ), 10, 3 );
            add_filter( 'better_messages_can_send_message',        array( $this, 'can_reply_to_group_chat' ), 10, 3 );

            add_filter( 'better_messages_courses_active',          array( $this, 'enabled' ) );
            add_filter( 'better_messages_get_courses',             array( $this, 'get_courses' ), 10, 2 );
            add_filter( 'better_messages_user_has_courses',        array( $this, 'user_has_courses' ), 10, 2 );

            add_filter( 'better_messages_thread_title',            array( $this, 'course_thread_title' ), 10, 3 );
            add_filter( 'better_messages_thread_image',            array( $this, 'course_thread_image' ), 10, 3 );
            add_filter( 'better_messages_thread_url',              array( $this, 'course_thread_url' ), 10, 3 );
            add_filter( 'better_messages_rest_thread_item',        array( $this, 'thread_item' ), 10, 5 );

            add_action( 'fluent_community/course/created',         array( $this, 'on_something_changed' ), 10, 3 );
            add_action( 'fluent_community/course/updated',         array( $this, 'on_something_changed' ), 10, 3 );
            add_action( 'fluent_community/course/published',       array( $this, 'on_something_changed' ), 10, 3 );
            add_action( 'fluent_community/course/enrolled',        array( $this, 'on_something_changed' ), 10, 4 );
            add_action( 'fluent_community/course/student_left',    array( $this, 'on_something_changed' ), 10, 3 );

            add_action( 'fluent_community/on_wp_init',             array( $this, 'on_wp_init' ), 10, 1 );

            add_filter( 'better_messages_bulk_get_all_groups',     array( $this, 'bulk_get_all_groups' ) );
            add_filter( 'better_messages_bulk_get_group_members',  array( $this, 'bulk_get_group_members' ), 10, 3 );

            add_filter( 'fluent_community/get_course_api_response', array( $this, 'inject_course_chat_meta' ), 10, 2 );
            add_filter( 'fluent_community/course_api_response',     array( $this, 'inject_course_chat_meta' ), 10, 2 );
        }

        public function inject_course_chat_meta( $data, $request_data = array() )
        {
            if ( ! is_array( $data ) || empty( $data['course'] ) ) return $data;

            $course = $data['course'];
            $course_id = is_object( $course ) ? (int) ( $course->id ?? 0 ) : (int) ( $course['id'] ?? 0 );
            if ( ! $course_id ) return $data;

            $bm_data = array(
                'chat_enabled' => $this->is_course_messages_enabled( $course_id ),
                'thread_id'    => 0,
            );

            if ( $bm_data['chat_enabled'] ) {
                $user_id = Better_Messages()->functions->get_current_user_id();
                if ( $user_id !== 0 && $this->user_has_access( $course_id, $user_id ) ) {
                    $bm_data['thread_id'] = (int) $this->get_course_thread_id( $course_id );
                }
            }

            if ( is_object( $course ) ) {
                $course->bm_chat = $bm_data;
                $data['course'] = $course;
            } else {
                $course['bm_chat'] = $bm_data;
                $data['course'] = $course;
            }

            return $data;
        }

        public function on_wp_init( $app )
        {
            $api = \FluentCommunity\App\Functions\Utility::extender();

            $api->addMetaBox( 'better_messages_course_settings', array(
                'section_title'   => _x( 'Course Messages', 'FluentCommunity Integration (Courses Settings)', 'bp-better-messages' ),
                'fields_callback' => function ( $course ) {
                    return array(
                        'enabled' => array(
                            'true_value'     => 'yes',
                            'false_value'    => 'no',
                            'type'           => 'inline_checkbox',
                            'checkbox_label' => _x( 'Enable group messages for this course members', 'FluentCommunity Integration (Courses Settings)', 'bp-better-messages' ),
                        ),
                    );
                },
                'data_callback'   => function ( $course ) {
                    $defaults = array(
                        'enabled' => apply_filters( 'better_messages_fluent_community_course_chat_enabled', 'yes', $course->id ),
                    );

                    $settings = $course->getCustomMeta( 'better_messages_course_settings', $defaults );
                    $settings = wp_parse_args( $settings, $defaults );

                    return $settings;
                },
                'save_callback'   => function ( $settings, $course ) {
                    if ( ! is_array( $settings ) ) {
                        return;
                    }

                    $course->updateCustomMeta( 'better_messages_course_settings', $settings );
                },
            ), array( 'course' ) );
        }

        public function enabled( $var )
        {
            return true;
        }

        public function is_course_messages_enabled( $course_id )
        {
            $course = Course::find( $course_id );

            if ( ! $course ) return false;

            $defaults = array(
                'enabled' => apply_filters( 'better_messages_fluent_community_course_chat_enabled', 'yes', $course_id ),
            );

            $settings = $course->getCustomMeta( 'better_messages_course_settings', $defaults );

            return isset( $settings['enabled'] ) && $settings['enabled'] === 'yes';
        }

        public function user_has_access( $course_id, $user_id )
        {
            $allowed = false;

            if ( $user_id > 0 ) {
                $user = User::find( $user_id );

                if ( $user ) {
                    $course = Course::find( $course_id );

                    if ( $course ) {
                        if ( (int) $course->created_by === (int) $user_id ) {
                            $allowed = true;
                        } else {
                            $role = $user->getSpaceRole( $course );
                            if ( ! empty( $role ) ) {
                                $allowed = true;
                            }
                        }
                    }
                }
            }

            return apply_filters( 'better_messages_fluent_community_course_chat_user_has_access', $allowed, $course_id, $user_id );
        }

        public function is_valid_group( $is_valid, $thread_id )
        {
            $course_id = (int) Better_Messages()->functions->get_thread_meta( $thread_id, 'fluentcommunity_course_id' );

            if ( $course_id ) {
                $course = Course::find( $course_id );
                if ( $course && $this->is_course_messages_enabled( $course_id ) ) {
                    return true;
                }
            }

            return $is_valid;
        }

        public function has_access_to_group_chat( $has_access, $thread_id, $user_id )
        {
            $course_id = (int) Better_Messages()->functions->get_thread_meta( $thread_id, 'fluentcommunity_course_id' );

            if ( $course_id ) {
                if ( $this->is_course_messages_enabled( $course_id ) && $this->user_has_access( $course_id, $user_id ) ) {
                    return true;
                }
            }

            return $has_access;
        }

        public function can_reply_to_group_chat( $allowed, $user_id, $thread_id )
        {
            $type = Better_Messages()->functions->get_thread_type( $thread_id );

            if ( $type === 'course' ) {
                $course_id = (int) Better_Messages()->functions->get_thread_meta( $thread_id, 'fluentcommunity_course_id' );

                if ( $course_id ) {
                    if ( $this->is_course_messages_enabled( $course_id ) && $this->user_has_access( $course_id, $user_id ) ) {
                        return true;
                    } else {
                        return false;
                    }
                }
            }

            return $allowed;
        }

        public function user_has_courses( $has, $user_id )
        {
            if ( $has ) return $has;
            if ( $user_id <= 0 ) return false;

            $user = User::find( $user_id );
            if ( ! $user ) return false;

            $courses = $user->courses;
            if ( ! $courses || count( $courses ) === 0 ) {
                $authored = Course::where( 'created_by', $user_id )->count();
                if ( $authored > 0 ) return true;
                return false;
            }

            foreach ( $courses as $course ) {
                if ( $this->is_course_messages_enabled( $course->id ) ) {
                    return true;
                }
            }

            return false;
        }

        public function get_courses( $courses, $user_id )
        {
            $user = User::find( $user_id );
            if ( ! $user ) return $courses;

            $course_ids = array();

            $enrolled = $user->courses;
            if ( $enrolled ) {
                foreach ( $enrolled as $course ) {
                    $course_ids[] = (int) $course->id;
                }
            }

            $authored = Course::where( 'created_by', $user_id )->get();
            if ( $authored ) {
                foreach ( $authored as $course ) {
                    $course_ids[] = (int) $course->id;
                }
            }

            $course_ids = array_values( array_unique( array_filter( $course_ids ) ) );
            if ( empty( $course_ids ) ) return $courses;

            foreach ( $course_ids as $course_id ) {
                $course = Course::find( $course_id );
                if ( ! $course ) continue;

                if ( ! $this->is_course_messages_enabled( $course_id ) ) continue;

                $thread_id = $this->get_course_thread_id( $course_id );
                $image     = $this->get_course_image( $course->toArray() );

                $courses[] = array(
                    'course_id' => $course_id,
                    'name'      => html_entity_decode( esc_attr( $course->title ) ),
                    'messages'  => 1,
                    'thread_id' => (int) $thread_id,
                    'image'     => $image,
                    'url'       => $course->getPermalink(),
                );
            }

            return $courses;
        }

        public function on_something_changed( $course, $userId = null, $by = null, $created = null )
        {
            if ( ! $course || empty( $course->id ) ) return;
            if ( ! $this->is_course_messages_enabled( $course->id ) ) return;

            $thread_id = $this->get_course_thread_id( $course->id );
            $this->sync_thread_members( $thread_id );
        }

        public function course_thread_url( $url, $thread_id, $thread )
        {
            $thread_type = Better_Messages()->functions->get_thread_type( $thread_id );
            if ( $thread_type !== 'course' ) return $url;

            $course_id = (int) Better_Messages()->functions->get_thread_meta( $thread_id, 'fluentcommunity_course_id' );
            if ( ! $course_id ) return $url;

            $course = Course::find( $course_id );
            if ( $course ) {
                $url = $course->getPermalink();
            }

            return $url;
        }

        public function course_thread_title( $title, $thread_id, $thread )
        {
            $thread_type = Better_Messages()->functions->get_thread_type( $thread_id );
            if ( $thread_type !== 'course' ) return $title;

            $course_id = (int) Better_Messages()->functions->get_thread_meta( $thread_id, 'fluentcommunity_course_id' );
            if ( ! $course_id ) return $title;

            $course = Course::find( $course_id );
            if ( $course ) {
                return $course->title;
            }

            return $title;
        }

        public function course_thread_image( $image, $thread_id, $thread )
        {
            $thread_type = Better_Messages()->functions->get_thread_type( $thread_id );
            if ( $thread_type !== 'course' ) return $image;

            $course_id = (int) Better_Messages()->functions->get_thread_meta( $thread_id, 'fluentcommunity_course_id' );
            if ( ! $course_id ) return $image;

            $course = Course::find( $course_id );
            if ( ! $course ) return $image;

            $course_image = $this->get_course_image( $course->toArray() );
            if ( $course_image !== '' ) return $course_image;

            return $this->get_default_course_image_html();
        }

        public function get_default_course_image_html()
        {
            return 'html:<span style="display:flex;align-items:center;justify-content:center;width:100%;height:100%;background:rgba(0,0,0,0.05);color:rgba(0,0,0,0.45);border-radius:50%;aspect-ratio:1/1;box-sizing:border-box"><svg style="width:60%;height:60%;max-width:36px;max-height:36px" viewBox="0 0 24 24" stroke="currentColor" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 10v6M2 10l10-5 10 5-10 5z"></path><path d="M6 12v5c3 3 9 3 12 0v-5"></path></svg></span>';
        }

        public function thread_item( $thread_item, $thread_id, $thread_type, $include_personal, $user_id )
        {
            if ( Better_Messages()->settings['coursesShowInfoCard'] !== '1' ) return $thread_item;

            $course_id = (int) Better_Messages()->functions->get_thread_meta( $thread_id, 'fluentcommunity_course_id' );

            if ( ! $course_id ) {
                $unique_tag = Better_Messages()->functions->get_thread_meta( $thread_id, 'unique_tag' );

                if ( ! empty( $unique_tag ) && strpos( $unique_tag, 'fluentcommunity_course_chat_' ) === 0 ) {
                    $parts = explode( '|', $unique_tag );
                    if ( isset( $parts[0] ) ) {
                        $course_id = (int) str_replace( 'fluentcommunity_course_chat_', '', $parts[0] );
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
            $course = Course::find( $course_id );
            if ( ! $course ) return '';

            $title = esc_html( $course->title );
            $url   = $course->getPermalink();
            $image = $this->get_course_image( $course->toArray() );

            $html = '<div class="bm-product-info">';
            $html .= '<div class="bm-product-image">';
            $html .= '<a href="' . esc_url( $url ) . '" target="_blank">';

            if ( $image && strpos( $image, 'html:' ) === 0 ) {
                $html .= substr( $image, 5 );
            } elseif ( $image ) {
                $html .= '<img src="' . esc_url( $image ) . '" alt="' . $title . '" />';
            } else {
                $html .= substr( $this->get_default_course_image_html(), 5 );
            }

            $html .= '</a>';
            $html .= '</div>';

            $html .= '<div class="bm-product-details">';
            $html .= '<div class="bm-product-title"><a href="' . esc_url( $url ) . '" target="_blank">' . $title . '</a></div>';

            if ( ! empty( $course->created_by ) ) {
                $instructor_name = Better_Messages()->functions->get_name( (int) $course->created_by );
                if ( $instructor_name ) {
                    $html .= '<div class="bm-product-subtitle">' . esc_html( sprintf(
                        _x( 'Instructor: %s', 'FluentCommunity Integration', 'bp-better-messages' ),
                        $instructor_name
                    ) ) . '</div>';
                }
            }

            $html .= '</div>';
            $html .= '</div>';

            return $html;
        }

        private function get_course_image( array $course_arr )
        {
            if ( ! empty( $course_arr['logo'] ) ) {
                return $course_arr['logo'];
            }

            if ( ! empty( $course_arr['cover_photo'] ) ) {
                return $course_arr['cover_photo'];
            }

            if ( ! empty( $course_arr['settings'] ) ) {
                if ( isset( $course_arr['settings']['emoji'] ) && trim( $course_arr['settings']['emoji'] ) !== '' ) {
                    return 'html:<span class="bm-thread-emoji">' . trim( $course_arr['settings']['emoji'] ) . '</span>';
                }

                if ( isset( $course_arr['settings']['shape_svg'] ) && trim( $course_arr['settings']['shape_svg'] ) !== '' ) {
                    return 'html:<span class="bm-thread-svg">' . trim( $course_arr['settings']['shape_svg'] ) . '</span>';
                }
            }

            return '';
        }

        public function get_course_thread_id( $course_id )
        {
            global $wpdb;

            $thread_id = (int) $wpdb->get_var( $wpdb->prepare( "
                SELECT bm_thread_id
                FROM `" . bm_get_table( 'threadsmeta' ) . "`
                WHERE `meta_key` = 'fluentcommunity_course_id'
                AND   `meta_value` = %s
            ", $course_id ) );

            $thread_exist = $thread_id ? (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM `" . bm_get_table( 'threads' ) . "` WHERE `id` = %d", $thread_id
            ) ) : 0;

            if ( $thread_exist === 0 ) {
                $thread_id = false;
            }

            if ( ! $thread_id ) {
                $wpdb->query( $wpdb->prepare( "
                    DELETE
                    FROM `" . bm_get_table( 'threadsmeta' ) . "`
                    WHERE `meta_key` = 'fluentcommunity_course_id'
                    AND   `meta_value` = %s
                ", $course_id ) );

                $course = Course::find( $course_id );

                if ( $course ) {
                    $wpdb->insert(
                        bm_get_table( 'threads' ),
                        array(
                            'subject' => $course->title,
                            'type'    => 'course',
                        )
                    );

                    $thread_id = (int) $wpdb->insert_id;

                    Better_Messages()->functions->update_thread_meta( $thread_id, 'fluentcommunity_course_thread', true );
                    Better_Messages()->functions->update_thread_meta( $thread_id, 'fluentcommunity_course_id', $course_id );

                    $this->sync_thread_members( $thread_id );
                }
            }

            return $thread_id;
        }

        public function get_course_members( $course_id )
        {
            $result = array();

            $course = Course::find( $course_id );
            if ( ! $course ) return $result;

            if ( $course->created_by ) {
                $result[] = (int) $course->created_by;
            }

            $members = $course->members->toArray();
            foreach ( $members as $member ) {
                if ( isset( $member['pivot']['status'] ) && $member['pivot']['status'] === 'active' ) {
                    $result[] = (int) $member['ID'];
                }
            }

            return array_values( array_unique( array_filter( $result ) ) );
        }

        public function sync_thread_members( $thread_id )
        {
            $thread_id = (int) $thread_id;
            if ( ! $thread_id ) return false;

            wp_cache_delete( 'thread_recipients_' . $thread_id, 'bm_messages' );
            wp_cache_delete( 'bm_thread_recipients_' . $thread_id, 'bm_messages' );

            $course_id = (int) Better_Messages()->functions->get_thread_meta( $thread_id, 'fluentcommunity_course_id' );
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

        public function bulk_get_all_groups( $groups )
        {
            if ( ! class_exists( 'FluentCommunity\\Modules\\Course\\Model\\Course' ) ) return $groups;

            $courses = Course::get();

            if ( $courses ) {
                foreach ( $courses as $course ) {
                    $groups[] = array(
                        'id'         => (int) $course->id,
                        'name'       => esc_attr( $course->title ),
                        'type'       => 'fc_course',
                        'type_label' => 'FluentCommunity Course',
                    );
                }
            }

            return $groups;
        }

        public function bulk_get_group_members( $user_ids, $group_type, $group_id )
        {
            if ( $group_type !== 'fc_course' ) return $user_ids;

            $members = $this->get_course_members( $group_id );

            if ( is_array( $members ) ) {
                foreach ( $members as $uid ) {
                    $user_ids[] = (int) $uid;
                }
            }

            return $user_ids;
        }
    }
}
