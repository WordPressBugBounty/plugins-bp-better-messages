<?php
defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Better_Messages_Notification_Levels' ) ):

    class Better_Messages_Notification_Levels
    {
        const FOLLOW   = 0;
        const NONE     = 1;
        const MENTIONS = 2;
        const ALL      = 3;

        const NOTIFY_TYPES   = array( 'mention', 'reply', 'everyone' );
        const PERSONAL_TYPES = array( 'mention', 'reply' );

        const SNOOZE_PREFIX = '_bm_mute_until_';

        const SNOOZE_OPTION = 'better_messages_has_snoozes';

        private $default_memo = array();

        private $locked_memo = array();

        private $one_to_one_memo = array();

        private $recipients_memo = array();

        public static function instance()
        {
            static $instance = null;

            if ( null === $instance ) {
                $instance = new Better_Messages_Notification_Levels();
            }

            return $instance;
        }

        public function __construct()
        {
            add_action( 'init', array( $this, 'register_worker_task' ) );
            add_action( 'better_messages_notification_snoozes', array( $this, 'restore_expired_snoozes' ) );

            add_action( 'better_messages_thread_updated', array( $this, 'forget_thread' ), 1 );
            add_action( 'better_messages_info_changed', array( $this, 'forget_thread' ), 1 );
            add_action( 'better_messages_participant_added', array( $this, 'forget_thread' ), 1 );
            add_action( 'better_messages_participant_removed', array( $this, 'forget_thread' ), 1 );
            add_action( 'better_messages_participants_added', array( $this, 'forget_thread' ), 1 );
            add_action( 'better_messages_participants_removed', array( $this, 'forget_thread' ), 1 );

            add_action( 'added_bm_thread_meta', array( $this, 'forget_thread_meta' ), 1, 2 );
            add_action( 'updated_bm_thread_meta', array( $this, 'forget_thread_meta' ), 1, 2 );
            add_action( 'deleted_bm_thread_meta', array( $this, 'forget_thread_meta' ), 1, 2 );
        }

        public function forget_thread_meta( $meta_id, $thread_id )
        {
            $this->forget_thread( $thread_id );
        }

        public function register_worker_task()
        {
            Better_Messages_Worker()->add_task( 'better_messages_notification_snoozes', MINUTE_IN_SECONDS );
        }

        public function forget_thread( $thread_id = 0 )
        {
            $thread_id = (int) $thread_id;

            unset( $this->default_memo[ $thread_id ], $this->locked_memo[ $thread_id ], $this->one_to_one_memo[ $thread_id ] );

            foreach ( array_keys( $this->recipients_memo ) as $key ) {
                if ( strpos( $key, $thread_id . '|' ) === 0 ) {
                    unset( $this->recipients_memo[ $key ] );
                }
            }
        }

        public static function levels()
        {
            return array( 'all', 'mentions', 'none' );
        }

        public static function sanitize_level( $value, $fallback = 'all' )
        {
            return in_array( $value, self::levels(), true ) ? $value : $fallback;
        }

        public static function level_to_int( $name )
        {
            switch ( $name ) {
                case 'all':
                    return self::ALL;
                case 'mentions':
                    return self::MENTIONS;
                case 'none':
                    return self::NONE;
                case 'default':
                    return self::FOLLOW;
            }

            return null;
        }

        public static function int_to_choice( $stored )
        {
            switch ( (int) $stored ) {
                case self::ALL:
                    return 'all';
                case self::MENTIONS:
                    return 'mentions';
                case self::NONE:
                    return 'none';
                case self::FOLLOW:
                    return 'default';
            }

            return 'none';
        }

        public function is_one_to_one( $thread_id )
        {
            $thread_id = (int) $thread_id;

            if ( isset( $this->one_to_one_memo[ $thread_id ] ) ) {
                return $this->one_to_one_memo[ $thread_id ];
            }

            $one_to_one = false;

            if ( Better_Messages()->functions->get_thread_type( $thread_id ) === 'thread' ) {
                $one_to_one = count( Better_Messages()->functions->get_recipients( $thread_id ) ) <= 2;
            }

            $this->one_to_one_memo[ $thread_id ] = $one_to_one;

            return $one_to_one;
        }

        public function get_room_level( $thread_id )
        {
            $stored = Better_Messages()->functions->get_thread_meta( $thread_id, 'notification_level' );

            if ( in_array( $stored, self::levels(), true ) ) {
                return $stored;
            }

            return Better_Messages()->functions->get_thread_meta( $thread_id, 'enable_notifications' ) === '1' ? 'all' : 'mentions';
        }

        public function get_thread_default_level( $thread_id )
        {
            $thread_id = (int) $thread_id;

            if ( isset( $this->default_memo[ $thread_id ] ) ) {
                return $this->default_memo[ $thread_id ];
            }

            $settings = Better_Messages()->settings;
            $type     = Better_Messages()->functions->get_thread_type( $thread_id );

            switch ( $type ) {
                case 'chat-room':
                    $level = $this->get_room_level( $thread_id );
                    break;
                case 'group':
                    $level = self::sanitize_level( isset( $settings['notifyLevelGroups'] ) ? $settings['notifyLevelGroups'] : 'all' );
                    break;
                case 'course':
                    $level = self::sanitize_level( isset( $settings['notifyLevelCourses'] ) ? $settings['notifyLevelCourses'] : 'all' );
                    break;
                default:
                    if ( $this->is_one_to_one( $thread_id ) ) {
                        $level = 'all';
                    } else {
                        $level = self::sanitize_level( isset( $settings['notifyLevelGroupThreads'] ) ? $settings['notifyLevelGroupThreads'] : 'all' );
                    }
                    break;
            }

            $level = self::sanitize_level( apply_filters( 'better_messages_thread_default_notification_level', $level, $thread_id, $type ), $level );

            $this->default_memo[ $thread_id ] = $level;

            return $level;
        }

        public function is_thread_locked( $thread_id )
        {
            $thread_id = (int) $thread_id;

            if ( isset( $this->locked_memo[ $thread_id ] ) ) {
                return $this->locked_memo[ $thread_id ];
            }

            $locked = false;

            if ( Better_Messages()->functions->get_thread_type( $thread_id ) === 'chat-room' ) {
                if ( Better_Messages()->functions->get_thread_meta( $thread_id, 'exclude_from_threads_list' ) === '1' ) {
                    $locked = true;
                } else if ( function_exists( 'Better_Messages_Chats' ) && Better_Messages_Chats()->is_ephemeral_thread( $thread_id ) ) {
                    $locked = true;
                }
            }

            $locked = (bool) apply_filters( 'better_messages_thread_notifications_locked', $locked, $thread_id );

            $this->locked_memo[ $thread_id ] = $locked;

            return $locked;
        }

        public function get_stored_level( $thread_id, $user_id )
        {
            global $wpdb;

            $stored = $wpdb->get_var( $wpdb->prepare(
                "SELECT `is_muted` FROM `" . bm_get_table( 'recipients' ) . "` WHERE `thread_id` = %d AND `user_id` = %d LIMIT 1",
                $thread_id, $user_id
            ) );

            return is_null( $stored ) ? null : (int) $stored;
        }

        public function resolve_level( $stored, $thread_id )
        {
            if ( $this->is_thread_locked( $thread_id ) ) {
                return 'none';
            }

            $stored = (int) $stored;

            if ( $this->is_one_to_one( $thread_id ) ) {
                return ( $stored === self::FOLLOW || $stored === self::MENTIONS || $stored === self::ALL ) ? 'all' : 'none';
            }

            switch ( $stored ) {
                case self::ALL:
                    return 'all';
                case self::MENTIONS:
                    return 'mentions';
                case self::FOLLOW:
                    return $this->get_thread_default_level( $thread_id );
            }

            return 'none';
        }

        public function get_effective_level( $thread_id, $user_id )
        {
            $this->maybe_restore_snooze( $thread_id, $user_id );

            $stored = $this->get_stored_level( $thread_id, $user_id );

            if ( is_null( $stored ) ) {
                return 'none';
            }

            return $this->resolve_level( $stored, $thread_id );
        }

        public function push_channel_open( $thread_id )
        {
            return $this->channel_open( $thread_id, 'push' );
        }

        public function email_channel_open( $thread_id )
        {
            return $this->channel_open( $thread_id, 'email' );
        }

        private function channel_open( $thread_id, $channel )
        {
            $settings = Better_Messages()->settings;
            $type     = Better_Messages()->functions->get_thread_type( $thread_id );
            $open     = true;

            if ( $type === 'group' ) {
                $gates = $channel === 'email'
                    ? array( 'group_id' => 'enableGroupsEmails', 'peepso_group_id' => 'PSenableGroupsEmails', 'um_group_id' => 'UMenableGroupsEmails', 'fluentcommunity_group_id' => 'FCenableGroupsEmails' )
                    : array( 'group_id' => 'enableGroupsPushs', 'peepso_group_id' => 'PSenableGroupsPushs', 'um_group_id' => 'UMenableGroupsPushs', 'fluentcommunity_group_id' => 'FCenableGroupsPushs' );

                foreach ( $gates as $meta_key => $setting ) {
                    if ( ( isset( $settings[ $setting ] ) ? $settings[ $setting ] : '0' ) === '1' ) {
                        continue;
                    }

                    $group_id = Better_Messages()->functions->get_thread_meta( $thread_id, $meta_key );

                    if ( ! empty( $group_id ) ) {
                        $open = false;
                        break;
                    }
                }
            }

            if ( $type === 'course' ) {
                $setting = $channel === 'email' ? 'enableCoursesEmails' : 'enableCoursesPushs';

                if ( ( isset( $settings[ $setting ] ) ? $settings[ $setting ] : '0' ) !== '1' ) {
                    $open = false;
                }
            }

            return (bool) apply_filters( 'better_messages_notification_channel_open', $open, $thread_id, $channel, $type );
        }

        public function force_enabled()
        {
            return isset( Better_Messages()->settings['mentionsForceNotifications'] ) && Better_Messages()->settings['mentionsForceNotifications'] === '1';
        }

        public function level_clause( $thread_id, $personal_ids, $everyone, $gate_open, $alias = 'r' )
        {
            $personal = array_values( array_filter( array_unique( array_map( 'intval', (array) $personal_ids ) ) ) );
            $in       = implode( ',', $personal );
            $column   = "`{$alias}`.`is_muted`";
            $user     = "`{$alias}`.`user_id`";
            $parts    = array();

            if ( $this->is_one_to_one( $thread_id ) ) {
                if ( $gate_open ) {
                    $parts[] = "{$column} IN (0, 2, 3)";
                }
            } else if ( $gate_open ) {
                $default = $this->get_thread_default_level( $thread_id );

                $parts[] = "{$column} = 3";

                if ( $default === 'all' ) {
                    $parts[] = "{$column} = 0";
                }

                if ( $everyone ) {
                    $parts[] = "{$column} = 2";

                    if ( $default === 'mentions' ) {
                        $parts[] = "{$column} = 0";
                    }
                }

                if ( $in !== '' ) {
                    $parts[] = "( {$column} = 2 AND {$user} IN ({$in}) )";

                    if ( $default === 'mentions' ) {
                        $parts[] = "( {$column} = 0 AND {$user} IN ({$in}) )";
                    }
                }
            }

            if ( $in !== '' && $this->force_enabled() ) {
                $parts[] = "( {$column} IN (0, 1, 2, 3) AND {$user} IN ({$in}) )";
            }

            if ( empty( $parts ) ) {
                return '0 = 1';
            }

            return '( ' . implode( ' OR ', array_unique( $parts ) ) . ' )';
        }

        public function get_notify_recipients( $message, $channel = 'push' )
        {
            $thread_id  = isset( $message->thread_id ) ? (int) $message->thread_id : 0;
            $message_id = isset( $message->id ) ? (int) $message->id : 0;
            $key        = $thread_id . '|' . $message_id . '|' . $channel;

            if ( $message_id > 0 && isset( $this->recipients_memo[ $key ] ) ) {
                return $this->recipients_memo[ $key ];
            }

            $ids = $this->compute_notify_recipients( $message, $channel );

            $ids = array_values( array_unique( array_map( 'intval', (array) apply_filters( 'better_messages_notification_recipients', $ids, $message, $channel ) ) ) );

            if ( $message_id > 0 ) {
                $this->recipients_memo[ $key ] = $ids;
            }

            return $ids;
        }

        private function compute_notify_recipients( $message, $channel )
        {
            global $wpdb;

            if ( ! empty( $message->is_update ) || ! empty( $message->push_deferred ) ) {
                return array();
            }

            if ( isset( $message->is_pending ) && (int) $message->is_pending === 1 ) {
                return array();
            }

            if ( $channel === 'mobile' ) {
                $intent = isset( $message->mobile_push ) ? (bool) $message->mobile_push : true;
            } else {
                $intent = isset( $message->send_push ) ? (bool) $message->send_push : true;
            }

            $thread_id = isset( $message->thread_id ) ? (int) $message->thread_id : 0;

            if ( ! $intent || $thread_id <= 0 || $this->is_thread_locked( $thread_id ) ) {
                return array();
            }

            $this->restore_expired_snoozes( $thread_id );

            $targets  = Better_Messages()->mentions->get_message_targets( $message );
            $everyone = ! empty( $targets['everyone'] ) || ! empty( $message->notify_everyone );

            $clause = $this->level_clause( $thread_id, $targets['personal'], $everyone, $this->push_channel_open( $thread_id ) );

            if ( $clause === '0 = 1' ) {
                return array();
            }

            $exclude = array( isset( $message->sender_id ) ? (int) $message->sender_id : 0 );

            if ( ! empty( $message->notify_exclude ) ) {
                $exclude = array_merge( $exclude, array_map( 'intval', (array) $message->notify_exclude ) );
            }

            $exclude = implode( ',', array_unique( array_map( 'intval', $exclude ) ) );

            $ids = $wpdb->get_col( $wpdb->prepare(
                "SELECT `r`.`user_id`
                FROM `" . bm_get_table( 'recipients' ) . "` `r`
                WHERE `r`.`thread_id` = %d
                AND `r`.`is_deleted` = 0
                AND `r`.`user_id` NOT IN ({$exclude})
                AND {$clause}",
                $thread_id
            ) );

            return array_map( 'intval', (array) $ids );
        }

        public function get_snooze( $thread_id, $user_id )
        {
            $raw = Better_Messages()->functions->get_thread_meta( $thread_id, self::SNOOZE_PREFIX . (int) $user_id );

            if ( empty( $raw ) || ! is_string( $raw ) || strpos( $raw, '|' ) === false ) {
                return null;
            }

            list( $until, $restore ) = explode( '|', $raw, 2 );

            return array(
                'until'   => (int) $until,
                'restore' => (int) $restore,
            );
        }

        private function clear_snooze( $thread_id, $user_id )
        {
            Better_Messages()->functions->delete_thread_meta( $thread_id, self::SNOOZE_PREFIX . (int) $user_id );
        }

        public function maybe_restore_snooze( $thread_id, $user_id )
        {
            if ( get_option( self::SNOOZE_OPTION ) !== '1' ) {
                return false;
            }

            $snooze = $this->get_snooze( $thread_id, $user_id );

            if ( ! $snooze || $snooze['until'] > time() ) {
                return false;
            }

            $this->restore_snooze( $thread_id, $user_id, $snooze );

            return true;
        }

        public function restore_expired_snoozes( $thread_id = 0 )
        {
            global $wpdb;

            if ( get_option( self::SNOOZE_OPTION ) !== '1' ) {
                return 0;
            }

            $table     = bm_get_table( 'threadsmeta' );
            $thread_id = (int) $thread_id;
            $like      = $wpdb->esc_like( self::SNOOZE_PREFIX ) . '%';

            if ( $thread_id > 0 ) {
                $rows = $wpdb->get_results( $wpdb->prepare(
                    "SELECT `bm_thread_id`, `meta_key`, `meta_value` FROM `{$table}` WHERE `bm_thread_id` = %d AND `meta_key` LIKE %s",
                    $thread_id, $like
                ) );
            } else {
                $rows = $wpdb->get_results( $wpdb->prepare(
                    "SELECT `bm_thread_id`, `meta_key`, `meta_value` FROM `{$table}` WHERE `meta_key` LIKE %s AND CAST( SUBSTRING_INDEX( `meta_value`, '|', 1 ) AS UNSIGNED ) <= %d LIMIT 500",
                    $like, time()
                ) );

                if ( empty( $rows ) ) {
                    $running = $wpdb->get_var( $wpdb->prepare(
                        "SELECT 1 FROM `{$table}` WHERE `meta_key` LIKE %s LIMIT 1",
                        $like
                    ) );

                    if ( ! $running ) {
                        delete_option( self::SNOOZE_OPTION );
                    }

                    return 0;
                }
            }

            $restored = 0;

            foreach ( (array) $rows as $row ) {
                $parts = explode( '|', (string) $row->meta_value, 2 );

                if ( count( $parts ) !== 2 || (int) $parts[0] > time() ) {
                    continue;
                }

                $user_id = (int) substr( $row->meta_key, strlen( self::SNOOZE_PREFIX ) );

                $this->restore_snooze( (int) $row->bm_thread_id, $user_id, array( 'until' => (int) $parts[0], 'restore' => (int) $parts[1] ) );

                $restored++;
            }

            return $restored;
        }

        private function restore_snooze( $thread_id, $user_id, $snooze )
        {
            global $wpdb;

            $this->clear_snooze( $thread_id, $user_id );

            $restore = (int) $snooze['restore'];

            if ( ! in_array( $restore, array( self::FOLLOW, self::MENTIONS, self::ALL ), true ) ) {
                $restore = self::FOLLOW;
            }

            $updated = $wpdb->query( $wpdb->prepare(
                "UPDATE `" . bm_get_table( 'recipients' ) . "`
                SET `is_muted` = %d, `last_update` = %d, `last_email` = %s
                WHERE `thread_id` = %d AND `user_id` = %d AND `is_muted` = %d",
                $restore, Better_Messages()->functions->get_microtime(), gmdate( 'Y-m-d H:i:s' ), $thread_id, $user_id, self::NONE
            ) );

            $this->after_member_change( $thread_id, $user_id );

            if ( $updated ) {
                do_action( 'better_messages_thread_self_update', $thread_id, $user_id );
                do_action( 'better_messages_notification_level_changed', $thread_id, $user_id, self::int_to_choice( $restore ), 0 );
            }
        }

        public static function snooze_durations()
        {
            $durations = apply_filters( 'better_messages_mute_durations', array( 60, 480, 1440, 10080 ) );

            $durations = array_values( array_unique( array_filter( array_map( 'intval', (array) $durations ), function ( $minutes ) {
                return $minutes > 0 && $minutes <= 43200;
            } ) ) );

            sort( $durations );

            return $durations;
        }

        public function set_user_level( $thread_id, $user_id, $level, $minutes = 0 )
        {
            global $wpdb;

            $thread_id = (int) $thread_id;
            $user_id   = (int) $user_id;
            $minutes   = (int) $minutes;

            $stored = $this->get_stored_level( $thread_id, $user_id );

            if ( is_null( $stored ) ) {
                return new WP_Error( 'not_participant', _x( 'You are not a participant of this conversation', 'Rest API Error', 'bp-better-messages' ), array( 'status' => 403 ) );
            }

            if ( $this->is_thread_locked( $thread_id ) ) {
                return new WP_Error( 'notifications_locked', _x( 'Notifications of this conversation can not be changed', 'Rest API Error', 'bp-better-messages' ), array( 'status' => 403 ) );
            }

            $new = self::level_to_int( $level );

            if ( is_null( $new ) || ( $level === 'mentions' && $this->is_one_to_one( $thread_id ) ) ) {
                return new WP_Error( 'invalid_level', _x( 'This notification level is not available here', 'Rest API Error', 'bp-better-messages' ), array( 'status' => 400 ) );
            }

            if ( $minutes > 0 ) {
                if ( $level !== 'none' ) {
                    return new WP_Error( 'invalid_duration', _x( 'Only muting can be set for a time', 'Rest API Error', 'bp-better-messages' ), array( 'status' => 400 ) );
                }

                $minutes = min( $minutes, 43200 );
                $current = $this->get_snooze( $thread_id, $user_id );

                if ( $current ) {
                    $restore = $current['restore'];
                } else {
                    $restore = ( $stored === self::NONE ) ? self::FOLLOW : $stored;
                }

                Better_Messages()->functions->update_thread_meta( $thread_id, self::SNOOZE_PREFIX . $user_id, ( time() + $minutes * MINUTE_IN_SECONDS ) . '|' . (int) $restore );
                update_option( self::SNOOZE_OPTION, '1', true );
            } else {
                $this->clear_snooze( $thread_id, $user_id );
            }

            $wpdb->query( $wpdb->prepare(
                "UPDATE `" . bm_get_table( 'recipients' ) . "`
                SET `is_muted` = %d, `last_update` = %d, `last_email` = %s
                WHERE `thread_id` = %d AND `user_id` = %d",
                $new, Better_Messages()->functions->get_microtime(), gmdate( 'Y-m-d H:i:s' ), $thread_id, $user_id
            ) );

            $this->after_member_change( $thread_id, $user_id );

            do_action( 'better_messages_thread_self_update', $thread_id, $user_id );
            do_action( 'better_messages_notification_level_changed', $thread_id, $user_id, $level, $minutes );

            return true;
        }

        private function after_member_change( $thread_id, $user_id )
        {
            if ( isset( Better_Messages()->hooks ) && method_exists( Better_Messages()->hooks, 'clean_thread_cache' ) ) {
                Better_Messages()->hooks->clean_thread_cache( $thread_id );
            }

            $this->forget_thread( $thread_id );
        }

        public function thread_payload( $thread_id, $user_id, $stored = null )
        {
            $thread_id = (int) $thread_id;
            $locked    = $this->is_thread_locked( $thread_id );

            $payload = array(
                'notificationDefault' => $this->is_one_to_one( $thread_id ) ? 'all' : $this->get_thread_default_level( $thread_id ),
                'notificationLocked'  => $locked,
            );

            if ( is_null( $user_id ) ) {
                return $payload;
            }

            if ( $this->maybe_restore_snooze( $thread_id, $user_id ) || is_null( $stored ) ) {
                $stored = $this->get_stored_level( $thread_id, $user_id );
            }

            $snooze = is_null( $stored ) ? null : $this->get_snooze( $thread_id, $user_id );

            $payload['notificationLevel']      = is_null( $stored ) ? 'default' : self::int_to_choice( $stored );
            $payload['notificationMutedUntil'] = ( $snooze && (int) $stored === self::NONE ) ? (int) $snooze['until'] : null;
            $payload['isMuted']                = is_null( $stored ) ? false : ( $this->resolve_level( $stored, $thread_id ) === 'none' );

            return $payload;
        }

        public function get_user_email_scope( $user_id )
        {
            $user_id = (int) $user_id;

            if ( $user_id <= 0 ) {
                return 'off';
            }

            if ( ! Better_Messages()->notifications->is_user_emails_enabled( $user_id ) ) {
                return 'off';
            }

            $scope = Better_Messages()->functions->get_user_meta( $user_id, 'bm_email_scope', true );

            if ( ! in_array( $scope, array( 'all', 'direct' ), true ) ) {
                $scope = isset( Better_Messages()->settings['emailScopeDefault'] ) ? Better_Messages()->settings['emailScopeDefault'] : 'all';

                if ( ! in_array( $scope, array( 'all', 'direct', 'off' ), true ) ) {
                    $scope = 'all';
                }
            }

            return apply_filters( 'better_messages_user_email_scope', $scope, $user_id );
        }

        public function set_user_email_scope( $user_id, $scope )
        {
            $user_id = (int) $user_id;

            if ( $user_id <= 0 || ! in_array( $scope, array( 'all', 'direct', 'off' ), true ) ) {
                return false;
            }

            if ( $scope === 'off' ) {
                Better_Messages()->functions->delete_user_meta( $user_id, 'bm_email_scope' );
                Better_Messages()->notifications->user_emails_enabled_update( $user_id, 'no' );
            } else {
                Better_Messages()->functions->update_user_meta( $user_id, 'bm_email_scope', $scope );
                Better_Messages()->notifications->user_emails_enabled_update( $user_id, 'yes' );
            }

            return true;
        }
    }

endif;

function Better_Messages_Notification_Levels()
{
    return Better_Messages_Notification_Levels::instance();
}
