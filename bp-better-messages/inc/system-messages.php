<?php
defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Better_Messages_System_Messages' ) ):

    class Better_Messages_System_Messages
    {
        private $suppress_user_left = array();

        public static function instance()
        {
            static $instance = null;

            if ( null === $instance ) {
                $instance = new Better_Messages_System_Messages();
            }

            return $instance;
        }

        public function __construct()
        {
            add_action( 'better_messages_participant_added',        array( $this, 'on_participant_added' ),        10, 3 );
            add_action( 'better_messages_participant_removed',      array( $this, 'on_participant_removed' ),      10, 2 );
            add_action( 'better_messages_thread_subject_changed',   array( $this, 'on_subject_changed' ),          10, 3 );
            add_action( 'added_post_meta',                          array( $this, 'on_chat_room_thumbnail_meta' ), 10, 4 );
            add_action( 'updated_post_meta',                        array( $this, 'on_chat_room_thumbnail_meta' ), 10, 4 );
            add_action( 'better_messages_user_promoted',            array( $this, 'on_user_promoted' ),            10, 3 );
            add_action( 'better_messages_user_demoted',             array( $this, 'on_user_demoted' ),             10, 3 );
            add_action( 'better_messages_user_kicked',              array( $this, 'on_user_kicked' ),              10, 3 );
            add_action( 'better_messages_user_muted',               array( $this, 'on_user_muted' ),               10, 4 );
            add_action( 'better_messages_user_banned',              array( $this, 'on_user_banned' ),              10, 4 );
            add_action( 'better_messages_group_call_joined',        array( $this, 'on_group_call_joined' ),        10, 4 );
        }

        public function suppress_user_left( $thread_id, $user_id )
        {
            $this->suppress_user_left[ (int) $thread_id . ':' . (int) $user_id ] = true;
        }

        private function passes_user_cooldown( $thread_id, $event_type, $user_id )
        {
            $user_id = (int) $user_id;

            if ( $user_id === 0 ) {
                return true;
            }

            $default = (int) ( Better_Messages()->settings['systemMessagesUserCooldownSeconds'] ?? 86400 );
            $window  = (int) apply_filters(
                'better_messages_system_message_user_cooldown_seconds',
                $default,
                $event_type,
                $thread_id,
                $user_id
            );

            if ( $window <= 0 ) {
                return true;
            }

            $key       = '_bm_sysmsg_user_' . $event_type . '_' . $user_id;
            $last_emit = (int) Better_Messages()->functions->get_thread_meta( $thread_id, $key );

            if ( $last_emit && ( time() - $last_emit ) < $window ) {
                return false;
            }

            Better_Messages()->functions->update_thread_meta( $thread_id, $key, time() );
            return true;
        }

        private function passes_thread_rate_limit( $thread_id, $event_type )
        {
            $max = (int) apply_filters(
                'better_messages_system_message_thread_rate_max',
                5,
                $event_type,
                $thread_id
            );

            $window = (int) apply_filters(
                'better_messages_system_message_thread_rate_seconds',
                300,
                $event_type,
                $thread_id
            );

            if ( $max <= 0 || $window <= 0 ) {
                return true;
            }

            $key = '_bm_sysmsg_rate_' . $event_type;
            $raw = Better_Messages()->functions->get_thread_meta( $thread_id, $key );

            $now    = time();
            $cutoff = $now - $window;

            $timestamps = array();
            if ( is_string( $raw ) && $raw !== '' ) {
                foreach ( explode( ',', $raw ) as $ts ) {
                    $ts = (int) $ts;
                    if ( $ts >= $cutoff ) {
                        $timestamps[] = $ts;
                    }
                }
            }

            if ( count( $timestamps ) >= $max ) {
                return false;
            }

            $timestamps[] = $now;
            Better_Messages()->functions->update_thread_meta( $thread_id, $key, implode( ',', $timestamps ) );
            return true;
        }

        private function get_transient_zone( $thread_id )
        {
            global $wpdb;
            $table = bm_get_table( 'messages' );

            $sql = $wpdb->prepare(
                "SELECT * FROM `{$table}`
                 WHERE thread_id = %d
                   AND sender_id = 0
                   AND created_at > COALESCE(
                       ( SELECT MAX(created_at) FROM `{$table}` WHERE thread_id = %d AND sender_id != 0 ),
                       0
                   )
                 ORDER BY created_at ASC",
                (int) $thread_id,
                (int) $thread_id
            );

            $rows = $wpdb->get_results( $sql );
            return is_array( $rows ) ? $rows : array();
        }

        private function row_event_type( $row )
        {
            if ( ! isset( $row->message ) || ! is_string( $row->message ) ) {
                return null;
            }
            if ( preg_match( '/<!-- BM-SYSTEM-MESSAGE:(\w+)/', $row->message, $m ) ) {
                return $m[1];
            }
            return null;
        }

        private function row_users( $row )
        {
            $data = Better_Messages()->functions->get_message_meta( (int) $row->id, 'system_data', true );
            if ( ! is_array( $data ) ) {
                return array();
            }

            if ( isset( $data['users'] ) && is_array( $data['users'] ) ) {
                $out = array();
                foreach ( $data['users'] as $u ) {
                    if ( is_array( $u ) && isset( $u['user_id'] ) ) {
                        $out[] = $this->normalize_user_entry( $u );
                    }
                }
                return $out;
            }

            if ( isset( $data['user_id'] ) ) {
                return array( $this->normalize_user_entry( $data ) );
            }

            return array();
        }

        private function normalize_user_entry( $entry )
        {
            $out = array(
                'user_id'   => (int) ( $entry['user_id'] ?? 0 ),
                'user_name' => (string) ( $entry['user_name'] ?? '' ),
            );
            if ( isset( $entry['actor_id'] ) && (int) $entry['actor_id'] !== 0 ) {
                $out['actor_id']   = (int) $entry['actor_id'];
                $out['actor_name'] = (string) ( $entry['actor_name'] ?? '' );
            }
            return $out;
        }

        private function find_user_index( $users, $user_id )
        {
            $user_id = (int) $user_id;
            foreach ( $users as $i => $u ) {
                if ( (int) ( $u['user_id'] ?? 0 ) === $user_id ) {
                    return $i;
                }
            }
            return -1;
        }

        private function build_user_entry_from_event( $event_data )
        {
            return $this->normalize_user_entry( $event_data );
        }

        private function persist_users( $row, $thread_id, $users )
        {
            $existing = Better_Messages()->functions->get_message_meta( (int) $row->id, 'system_data', true );
            if ( ! is_array( $existing ) ) {
                $existing = array();
            }

            $existing['users'] = array_values( $users );

            if ( ! empty( $users ) ) {
                $first = $users[0];
                $existing['user_id']   = (int) ( $first['user_id'] ?? 0 );
                $existing['user_name'] = (string) ( $first['user_name'] ?? '' );
                if ( isset( $first['actor_id'] ) ) {
                    $existing['actor_id']   = (int) $first['actor_id'];
                    $existing['actor_name'] = (string) ( $first['actor_name'] ?? '' );
                } else {
                    unset( $existing['actor_id'], $existing['actor_name'] );
                }
            }

            Better_Messages()->functions->update_message_meta( (int) $row->id, 'system_data', $existing );
            Better_Messages()->functions->update_message_update_time( (int) $row->id );
            do_action( 'better_messages_message_meta_updated', (int) $thread_id, (int) $row->id, 'system_data', $existing );
        }

        private function persist_data( $row, $thread_id, $data )
        {
            Better_Messages()->functions->update_message_meta( (int) $row->id, 'system_data', $data );
            Better_Messages()->functions->update_message_update_time( (int) $row->id );
            do_action( 'better_messages_message_meta_updated', (int) $thread_id, (int) $row->id, 'system_data', $data );
        }

        private function delete_system_row( $row, $thread_id = 0 )
        {
            $thread_id = (int) ( $thread_id ?: ( $row->thread_id ?? 0 ) );
            $event_type = $this->row_event_type( $row );

            if ( $thread_id > 0 && $event_type !== null ) {
                foreach ( $this->row_users( $row ) as $u ) {
                    $this->clear_user_cooldown( $thread_id, $event_type, (int) ( $u['user_id'] ?? 0 ) );
                }
            }

            Better_Messages()->functions->delete_message( (int) $row->id, $thread_id, true, 'delete' );
        }

        private function clear_user_cooldown( $thread_id, $event_type, $user_id )
        {
            if ( $user_id === 0 || $event_type === null ) {
                return;
            }
            Better_Messages()->functions->delete_thread_meta( $thread_id, '_bm_sysmsg_user_' . $event_type . '_' . $user_id );
        }

        private function gates_pass_for_emit( $thread_id, $event_type, $user_id = 0 )
        {
            if ( $user_id !== 0 && ! $this->passes_user_cooldown( $thread_id, $event_type, $user_id ) ) {
                return false;
            }
            if ( ! $this->passes_thread_rate_limit( $thread_id, $event_type ) ) {
                return false;
            }
            return true;
        }

        private function emit_event( $thread_id, $event_type, $event_data, $user_id = 0 )
        {
            $zone = $this->get_transient_zone( $thread_id );

            switch ( $event_type ) {
                case 'user_joined':
                    $this->emit_with_cancel( $thread_id, 'user_joined', 'user_left', $event_data, $zone, $user_id );
                    return;
                case 'user_left':
                    $this->emit_with_cancel( $thread_id, 'user_left', 'user_joined', $event_data, $zone, $user_id );
                    return;
                case 'user_promoted':
                    $this->emit_with_cancel( $thread_id, 'user_promoted', 'user_demoted', $event_data, $zone, $user_id );
                    return;
                case 'user_demoted':
                    $this->emit_with_cancel( $thread_id, 'user_demoted', 'user_promoted', $event_data, $zone, $user_id );
                    return;
                case 'user_muted':
                    $this->emit_with_cancel( $thread_id, 'user_muted', null, $event_data, $zone, $user_id );
                    return;
                case 'user_kicked':
                case 'user_banned':
                    $this->emit_kick_or_ban( $thread_id, $event_type, $event_data, $zone, $user_id );
                    return;
                case 'subject_changed':
                    $this->emit_subject_changed( $thread_id, $event_data, $zone );
                    return;
                case 'image_changed':
                    $this->emit_image_changed( $thread_id, $event_data, $zone );
                    return;
            }

            if ( $this->gates_pass_for_emit( $thread_id, $event_type, $user_id ) ) {
                Better_Messages()->functions->send_system_message( $thread_id, $event_type, $event_data );
            }
        }

        private function emit_with_cancel( $thread_id, $event_type, $opposite_type, $event_data, $zone, $user_id )
        {
            $new_user_id = (int) ( $event_data['user_id'] ?? 0 );

            if ( $opposite_type !== null ) {
                foreach ( $zone as $row ) {
                    if ( $this->row_event_type( $row ) !== $opposite_type ) {
                        continue;
                    }
                    $users = $this->row_users( $row );
                    $idx   = $this->find_user_index( $users, $new_user_id );
                    if ( $idx === -1 ) {
                        continue;
                    }
                    array_splice( $users, $idx, 1 );
                    $this->clear_user_cooldown( $thread_id, $opposite_type, $new_user_id );
                    if ( empty( $users ) ) {
                        $this->delete_system_row( $row, $thread_id );
                    } else {
                        $this->persist_users( $row, $thread_id, $users );
                    }
                    return;
                }
            }

            foreach ( $zone as $row ) {
                if ( $this->row_event_type( $row ) !== $event_type ) {
                    continue;
                }
                $users = $this->row_users( $row );
                if ( $this->find_user_index( $users, $new_user_id ) !== -1 ) {
                    return;
                }
                $users[] = $this->build_user_entry_from_event( $event_data );
                $this->persist_users( $row, $thread_id, $users );
                return;
            }

            if ( ! $this->gates_pass_for_emit( $thread_id, $event_type, $user_id ) ) {
                return;
            }

            $event_data['users'] = array( $this->build_user_entry_from_event( $event_data ) );
            Better_Messages()->functions->send_system_message( $thread_id, $event_type, $event_data );
        }

        private function emit_kick_or_ban( $thread_id, $event_type, $event_data, $zone, $user_id )
        {
            $new_user_id = (int) ( $event_data['user_id'] ?? 0 );

            foreach ( $zone as $row ) {
                if ( $this->row_event_type( $row ) !== 'user_joined' ) {
                    continue;
                }
                $users = $this->row_users( $row );
                $idx   = $this->find_user_index( $users, $new_user_id );
                if ( $idx === -1 ) {
                    continue;
                }
                array_splice( $users, $idx, 1 );
                $this->clear_user_cooldown( $thread_id, 'user_joined', $new_user_id );
                if ( empty( $users ) ) {
                    $this->delete_system_row( $row, $thread_id );
                } else {
                    $this->persist_users( $row, $thread_id, $users );
                }
                break;
            }

            $zone = $this->get_transient_zone( $thread_id );

            foreach ( $zone as $row ) {
                if ( $this->row_event_type( $row ) !== $event_type ) {
                    continue;
                }
                $users = $this->row_users( $row );
                if ( $this->find_user_index( $users, $new_user_id ) !== -1 ) {
                    return;
                }
                $users[] = $this->build_user_entry_from_event( $event_data );
                $this->persist_users( $row, $thread_id, $users );
                return;
            }

            if ( ! $this->gates_pass_for_emit( $thread_id, $event_type, $user_id ) ) {
                return;
            }

            $event_data['users'] = array( $this->build_user_entry_from_event( $event_data ) );
            Better_Messages()->functions->send_system_message( $thread_id, $event_type, $event_data );
        }

        private function emit_subject_changed( $thread_id, $event_data, $zone )
        {
            foreach ( $zone as $row ) {
                if ( $this->row_event_type( $row ) !== 'subject_changed' ) {
                    continue;
                }
                $existing = Better_Messages()->functions->get_message_meta( (int) $row->id, 'system_data', true );
                if ( ! is_array( $existing ) ) {
                    $existing = array();
                }
                $existing['new_subject'] = (string) ( $event_data['new_subject'] ?? '' );
                $existing['user_id']     = (int) ( $event_data['user_id'] ?? 0 );
                $existing['user_name']   = (string) ( $event_data['user_name'] ?? '' );
                $this->persist_data( $row, $thread_id, $existing );
                return;
            }

            if ( ! $this->gates_pass_for_emit( $thread_id, 'subject_changed', 0 ) ) {
                return;
            }

            Better_Messages()->functions->send_system_message( $thread_id, 'subject_changed', $event_data );
        }

        private function emit_image_changed( $thread_id, $event_data, $zone )
        {
            foreach ( $zone as $row ) {
                if ( $this->row_event_type( $row ) === 'image_changed' ) {
                    return;
                }
            }

            if ( ! $this->gates_pass_for_emit( $thread_id, 'image_changed', 0 ) ) {
                return;
            }

            Better_Messages()->functions->send_system_message( $thread_id, 'image_changed', $event_data );
        }

        private static $event_setting_keys = array(
            'user_joined'      => 'enableSystemMessagesUserJoined',
            'user_left'        => 'enableSystemMessagesUserLeft',
            'subject_changed'  => 'enableSystemMessagesSubjectChanged',
            'image_changed'    => 'enableSystemMessagesImageChanged',
            'user_promoted'    => 'enableSystemMessagesUserPromoted',
            'user_demoted'     => 'enableSystemMessagesUserDemoted',
            'user_kicked'      => 'enableSystemMessagesUserKicked',
            'user_muted'       => 'enableSystemMessagesUserMuted',
            'user_banned'      => 'enableSystemMessagesUserBanned',
            'call_started'     => 'enableSystemMessagesCallStarted',
        );

        public static function get_event_keys()
        {
            return array_keys( self::$event_setting_keys );
        }

        public function get_thread_payload( $thread_id, $type, $participants_count, $is_moderator )
        {
            $thread_id = (int) $thread_id;

            $allow_override = ( Better_Messages()->settings['enableSystemMessagesOverride'] ?? '1' ) === '1';
            $can_override   = (bool) (
                $is_moderator
                && $allow_override
                && $type !== 'chat-room'
                && ( $type !== 'thread' || $participants_count > 2 )
            );

            $stored   = Better_Messages()->functions->get_thread_meta( $thread_id, 'enable_system_messages' );
            $override = $stored === 'yes' ? true : ( $stored === 'no' ? false : null );

            return [
                'enabled'     => (bool) $this->is_enabled_for_thread( $thread_id ),
                'canOverride' => $can_override,
                'override'    => $override,
            ];
        }

        public function should_emit( $thread_id, $event_type = null )
        {
            $thread_id = (int) $thread_id;
            if ( $thread_id <= 0 ) {
                return false;
            }

            if ( $event_type !== null && ! isset( self::$event_setting_keys[ $event_type ] ) ) {
                return false;
            }

            if ( ! $this->is_enabled_for_thread( $thread_id ) ) {
                return false;
            }

            if ( $event_type !== null ) {
                if ( $this->is_event_disabled_for_chat_room( $thread_id, $event_type ) ) {
                    return false;
                }

                $global_enabled = ( Better_Messages()->settings['enableSystemMessages'] ?? '0' ) === '1';
                if ( $global_enabled ) {
                    $key = self::$event_setting_keys[ $event_type ];
                    if ( ( Better_Messages()->settings[ $key ] ?? '1' ) !== '1' ) {
                        return false;
                    }
                }
            }

            return true;
        }

        private function is_event_disabled_for_chat_room( $thread_id, $event_type )
        {
            if ( Better_Messages()->functions->get_thread_type( $thread_id ) !== 'chat-room' ) {
                return false;
            }

            $disabled = Better_Messages()->functions->get_thread_meta( $thread_id, 'system_messages_disabled_types' );

            if ( ! is_array( $disabled ) ) {
                return false;
            }

            return in_array( $event_type, $disabled, true );
        }

        private function is_enabled_for_thread( $thread_id )
        {
            $global_enabled = ( Better_Messages()->settings['enableSystemMessages'] ?? '0' ) === '1';
            $allow_override = ( Better_Messages()->settings['enableSystemMessagesOverride'] ?? '1' ) === '1';

            if ( ! $allow_override ) {
                return $global_enabled;
            }

            $thread_setting = Better_Messages()->functions->get_thread_meta( $thread_id, 'enable_system_messages' );

            if ( $thread_setting === 'no' ) {
                return false;
            }

            if ( $thread_setting === 'yes' ) {
                if ( $global_enabled ) {
                    return true;
                }
                return Better_Messages()->functions->get_thread_type( $thread_id ) === 'chat-room';
            }

            return $global_enabled;
        }

        public function on_participant_added( $thread_id, $user_id, $context = '' )
        {
            $thread_id = (int) $thread_id;
            $user_id   = (int) $user_id;

            if ( ! $this->should_emit( $thread_id, 'user_joined' ) ) {
                return;
            }

            if ( Better_Messages()->functions->is_ai_bot_user( $user_id ) ) {
                return;
            }

            $data = array(
                'user_id'   => $user_id,
                'user_name' => Better_Messages()->functions->get_name( $user_id ),
            );

            if ( $context !== 'admin' ) {
                $actor_id = (int) Better_Messages()->functions->get_current_user_id();
                if ( $actor_id !== 0 && $actor_id !== $user_id && ! Better_Messages()->functions->is_ai_bot_user( $actor_id ) ) {
                    $data['actor_id']   = $actor_id;
                    $data['actor_name'] = Better_Messages()->functions->get_name( $actor_id );
                }
            }

            $this->emit_event( $thread_id, 'user_joined', $data, $user_id );
        }

        public function on_participant_removed( $thread_id, $user_id )
        {
            $thread_id = (int) $thread_id;
            $user_id   = (int) $user_id;

            $key = $thread_id . ':' . $user_id;
            if ( isset( $this->suppress_user_left[ $key ] ) ) {
                unset( $this->suppress_user_left[ $key ] );
                return;
            }

            if ( ! $this->should_emit( $thread_id, 'user_left' ) ) {
                return;
            }

            if ( Better_Messages()->functions->is_ai_bot_user( $user_id ) ) {
                return;
            }

            $name = Better_Messages()->functions->get_name( $user_id );

            $this->emit_event( $thread_id, 'user_left', array(
                'user_id'   => $user_id,
                'user_name' => $name,
            ), $user_id );
        }

        public function on_subject_changed( $thread_id, $new_subject, $old_subject )
        {
            $thread_id = (int) $thread_id;

            if ( ! $this->should_emit( $thread_id, 'subject_changed' ) ) {
                return;
            }

            $actor_id   = (int) Better_Messages()->functions->get_current_user_id();
            $actor_name = $actor_id !== 0 ? Better_Messages()->functions->get_name( $actor_id ) : '';

            $this->emit_event( $thread_id, 'subject_changed', array(
                'user_id'     => $actor_id,
                'user_name'   => $actor_name,
                'new_subject' => (string) $new_subject,
                'old_subject' => (string) $old_subject,
            ) );
        }

        public function on_chat_room_thumbnail_meta( $meta_id, $object_id, $meta_key, $meta_value )
        {
            if ( $meta_key !== '_thumbnail_id' ) {
                return;
            }

            $post_id = (int) $object_id;
            if ( $post_id <= 0 ) {
                return;
            }

            if ( get_post_type( $post_id ) !== 'bpbm-chat' ) {
                return;
            }

            if ( ! isset( Better_Messages()->chats ) ) {
                return;
            }

            $thread_id = (int) Better_Messages()->chats->get_chat_thread_id( $post_id );
            if ( $thread_id <= 0 ) {
                return;
            }

            if ( ! $this->should_emit( $thread_id, 'image_changed' ) ) {
                return;
            }

            $this->emit_event( $thread_id, 'image_changed', array() );
        }

        public function on_user_promoted( $thread_id, $user_id, $actor_id = 0 )
        {
            $this->emit_moderation_event( $thread_id, $user_id, $actor_id, 'user_promoted' );
        }

        public function on_user_demoted( $thread_id, $user_id, $actor_id = 0 )
        {
            $this->emit_moderation_event( $thread_id, $user_id, $actor_id, 'user_demoted' );
        }

        public function on_user_kicked( $thread_id, $user_id, $actor_id = 0 )
        {
            $this->emit_moderation_event( $thread_id, $user_id, $actor_id, 'user_kicked' );
        }

        public function on_user_muted( $thread_id, $user_id, $actor_id = 0, $expiration = 0 )
        {
            $this->emit_moderation_event( $thread_id, $user_id, $actor_id, 'user_muted', array(
                'expiration' => (int) $expiration,
            ) );
        }

        public function on_user_banned( $thread_id, $user_id, $actor_id = 0, $expiration = 0 )
        {
            $this->emit_moderation_event( $thread_id, $user_id, $actor_id, 'user_banned', array(
                'expiration' => (int) $expiration,
            ) );
        }

        private function emit_moderation_event( $thread_id, $user_id, $actor_id, $event_type, $extra = array() )
        {
            $thread_id = (int) $thread_id;
            $user_id   = (int) $user_id;
            $actor_id  = (int) $actor_id;

            if ( ! $this->should_emit( $thread_id, $event_type ) ) {
                return;
            }

            if ( Better_Messages()->functions->is_ai_bot_user( $user_id ) ) {
                return;
            }

            if ( $actor_id !== 0 && Better_Messages()->functions->is_ai_bot_user( $actor_id ) ) {
                return;
            }

            $data = array(
                'user_id'    => $user_id,
                'user_name'  => Better_Messages()->functions->get_name( $user_id ),
                'actor_id'   => $actor_id,
                'actor_name' => $actor_id !== 0 ? Better_Messages()->functions->get_name( $actor_id ) : '',
            );

            if ( ! empty( $extra ) ) {
                $data = array_merge( $data, $extra );
            }

            $this->emit_event( $thread_id, $event_type, $data, $user_id );
        }

        public function on_group_call_joined( $thread_id, $type, $user_id, $is_room_start )
        {
            if ( ! $is_room_start ) {
                return;
            }

            $thread_id = (int) $thread_id;
            $user_id   = (int) $user_id;

            if ( $user_id !== 0 && Better_Messages()->functions->is_ai_bot_user( $user_id ) ) {
                return;
            }

            if ( ! $this->should_emit( $thread_id, 'call_started' ) ) {
                return;
            }

            $call_type = ( $type === 'video' ) ? 'video' : 'audio';

            $debounce_window = (int) apply_filters( 'better_messages_call_started_debounce_seconds', 300, $thread_id );
            if ( $debounce_window > 0 ) {
                $last_emit = (int) Better_Messages()->functions->get_thread_meta( $thread_id, '_bm_call_started_emit_' . $call_type );
                if ( $last_emit && ( time() - $last_emit ) < $debounce_window ) {
                    return;
                }
                Better_Messages()->functions->update_thread_meta( $thread_id, '_bm_call_started_emit_' . $call_type, time() );
            }

            Better_Messages()->functions->send_system_message( $thread_id, 'call_started', array(
                'user_id'   => $user_id,
                'user_name' => $user_id !== 0 ? Better_Messages()->functions->get_name( $user_id ) : '',
                'call_type' => $call_type,
            ) );
        }
    }

endif;

function Better_Messages_System_Messages()
{
    return Better_Messages_System_Messages::instance();
}
