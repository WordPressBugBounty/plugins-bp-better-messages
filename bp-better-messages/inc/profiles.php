<?php
defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Better_Messages_Profiles' ) ):

    class Better_Messages_Profiles
    {
        private $editable_cache = [];

        private $updated_now = [];

        public static function instance()
        {
            static $instance = null;

            if ( null === $instance ) {
                $instance = new Better_Messages_Profiles();
            }

            return $instance;
        }

        public function __construct()
        {
            add_filter( 'wordplus_profile_editable', array( $this, 'editable' ), 10, 2 );
            add_filter( 'wordplus_profile_person', array( $this, 'person' ) );
            add_filter( 'wordplus_profile_name_allowed', array( $this, 'name_allowed' ), 10, 3 );
            add_action( 'wordplus_profile_updated', array( $this, 'profile_updated' ), 10, 2 );
            add_action( 'profile_update', array( $this, 'member_changed' ), 20, 1 );

            add_filter( 'wordplus_visitor_items', array( $this, 'visitor_items' ), 10, 3 );
            add_action( 'wordplus_visitor_move', array( $this, 'visitor_move' ), 10, 2 );
            add_action( 'wordplus_visitor_forget', array( $this, 'visitor_forget' ) );
            add_action( 'wordplus_visitor_signed_in', array( $this, 'visitor_signed_in' ), 10, 2 );
            add_filter( 'wordplus_move_visitor', array( $this, 'move_visitor' ), 10, 2 );

            add_filter( 'bp_better_messages_script_variable', array( $this, 'script_variables' ) );
            add_filter( 'better_messages_rest_user_item', array( $this, 'user_country' ), 40, 3 );
        }

        private $countries = [];

        public function user_country( $item, $user_id, $include_personal )
        {
            $user_id = (int) $user_id;
            $geo     = function_exists( 'wordplus_geo' ) ? wordplus_geo() : null;

            if ( 0 === $user_id || ! $geo || ! $geo->flags_shown() || ! function_exists( 'wordplus_addresses' ) || ! wordplus_addresses() ) {
                return $item;
            }

            if ( ! array_key_exists( $user_id, $this->countries ) ) {
                $last = wordplus_addresses()->last( $user_id );

                $this->countries[ $user_id ] = $last ? (string) $last['country'] : '';
            }

            if ( '' !== $this->countries[ $user_id ] ) {
                $item['country'] = $this->countries[ $user_id ];
            }

            return $item;
        }

        public function sdk()
        {
            return function_exists( 'wordplus_profiles' ) ? wordplus_profiles() : null;
        }

        public function profile_plugin()
        {
            $found = '';

            $plugins = array(
                'BuddyBoss'       => defined( 'BP_PLATFORM_VERSION' ),
                'BuddyPress'      => function_exists( 'buddypress' ),
                'PeepSo'          => class_exists( 'PeepSo' ),
                'Ultimate Member' => defined( 'ultimatemember_version' ),
                'UsersWP'         => defined( 'USERSWP_VERSION' ),
                'ProfileGrid'     => defined( 'PROGRID_PLUGIN_VERSION' ),
                'WP User Manager' => class_exists( 'WP_User_Manager' ),
                'wpForo'          => defined( 'WPFORO_VERSION' ),
                'FluentCommunity' => defined( 'FLUENT_COMMUNITY_PLUGIN_VERSION' ),
            );

            foreach ( $plugins as $name => $active ) {
                if ( $active ) {
                    $found = $name;
                    break;
                }
            }

            return (string) apply_filters( 'better_messages_profile_plugin', $found );
        }

        public function editable( $editable, $user_id )
        {
            $user_id  = (int) $user_id;
            $none     = array( 'name' => false, 'photo' => false );
            $editable = array( 'name' => ! empty( $editable['name'] ), 'photo' => ! empty( $editable['photo'] ) );

            if ( 0 === $user_id ) {
                return $none;
            }

            if ( isset( $this->editable_cache[ $user_id ] ) ) {
                return array( 'name' => $editable['name'] && $this->editable_cache[ $user_id ]['name'], 'photo' => $editable['photo'] && $this->editable_cache[ $user_id ]['photo'] );
            }

            if ( $user_id < 0 ) {
                $guest = Better_Messages()->guests->get_guest_user( $user_id );

                if ( ! $guest || Better_Messages()->guests->guest_bot_id( $guest ) > 0 ) {
                    return $this->editable_cache[ $user_id ] = $none;
                }

                $result = array(
                    'name'  => $editable['name'],
                    'photo' => $editable['photo'] && Better_Messages()->settings['guestPhotos'] === '1',
                );

                return $this->editable_cache[ $user_id ] = $result;
            }

            if ( '' !== $this->profile_plugin() ) {
                return $this->editable_cache[ $user_id ] = $none;
            }

            $result = array(
                'name'  => $editable['name'] && Better_Messages()->settings['profileName'] === '1',
                'photo' => $editable['photo'] && Better_Messages()->settings['profilePhoto'] === '1',
            );

            if ( $result['name'] || $result['photo'] ) {
                $user = get_userdata( $user_id );

                if ( ! $user ) {
                    return $this->editable_cache[ $user_id ] = $none;
                }

                $item = Better_Messages()->functions->rest_user_item( $user_id, false );

                if ( $result['name'] ) {
                    $shown = html_entity_decode( wp_strip_all_tags( (string) ( $item['name'] ?? '' ) ), ENT_QUOTES, 'UTF-8' );
                    $own   = html_entity_decode( wp_strip_all_tags( Better_Messages()->functions->sanitize_display_name( (string) $user->display_name ) ), ENT_QUOTES, 'UTF-8' );

                    $result['name'] = $shown === $own;
                }

                if ( $result['photo'] ) {
                    $own = Better_Messages()->functions->avatar_url( get_avatar_url( $user_id, array( 'size' => 100 ) ) );

                    $result['photo'] = Better_Messages()->functions->show_avatars() && (string) ( $item['avatar'] ?? '' ) === (string) $own;
                }
            }

            return $this->editable_cache[ $user_id ] = $result;
        }

        public function person( $visitor )
        {
            if ( is_user_logged_in() ) {
                return $visitor;
            }

            $guest = Better_Messages()->guests->authenticated_guest_id();

            return $guest < 0 ? $guest : $visitor;
        }

        public function name_allowed( $allowed, $name, $user_id )
        {
            $user_id = (int) $user_id;

            if ( is_wp_error( $allowed ) || $user_id >= 0 ) {
                return $allowed;
            }

            if ( Better_Messages()->guests->names_must_be_unique() && Better_Messages()->guests->is_name_taken( $name, abs( $user_id ) ) ) {
                return new WP_Error( 'bm_guest_name_taken', _x( 'Sorry, such this name already used by someone else. Please try to use other name.', 'Rest API Error', 'bp-better-messages' ), array( 'status' => 403 ) );
            }

            return $allowed;
        }

        public function profile_updated( $user_id, $fields )
        {
            global $wpdb;

            $user_id = (int) $user_id;
            $fields  = (array) $fields;

            unset( $this->editable_cache[ $user_id ] );

            if ( $user_id < 0 && in_array( 'name', $fields, true ) && $this->sdk() ) {
                $guest = Better_Messages()->guests->get_guest_user( $user_id );
                $name  = Better_Messages()->guests->clean_name( $this->sdk()->name( $user_id ) );

                if ( $guest && '' !== $name && $name !== (string) $guest->name ) {
                    $wpdb->update( bm_get_table( 'guests' ), array( 'name' => $name ), array( 'id' => abs( $user_id ) ) );
                    wp_cache_delete( 'guest_user_' . abs( $user_id ), 'bm_messages' );
                }
            }

            if ( $user_id < 0 ) {
                Better_Messages()->users->update_last_changed( $user_id );
                do_action( 'better_messages_guest_updated', $user_id );
            }

            $this->user_updated( $user_id );
        }

        public function member_changed( $user_id )
        {
            unset( $this->editable_cache[ (int) $user_id ] );

            $this->user_updated( (int) $user_id );
        }

        private function user_updated( $user_id )
        {
            if ( 0 === $user_id || isset( $this->updated_now[ $user_id ] ) ) {
                return;
            }

            $this->updated_now[ $user_id ] = true;

            if ( $user_id > 0 ) {
                Better_Messages()->users->update_last_changed( $user_id );
            }

            do_action( 'better_messages_user_updated', $user_id );
        }

        public function present( $user_id )
        {
            $sdk = $this->sdk();

            if ( ! $sdk ) {
                return null;
            }

            $profile = $sdk->present( $user_id );

            if ( $user_id < 0 ) {
                $guest = Better_Messages()->guests->get_guest_user( $user_id );

                $profile['name'] = $guest ? (string) $guest->name : $profile['name'];
            }

            return $profile;
        }

        public function script_variables( $vars )
        {
            $sdk     = $this->sdk();
            $user_id = Better_Messages()->functions->get_current_user_id();

            if ( ! is_user_logged_in() ) {
                $vars['guestForm'] = Better_Messages()->guests->form_config();
            } else if ( Better_Messages()->guests->guest_of_member_kept( get_current_user_id() ) < 0 ) {
                $vars['guestOfEmail'] = '1';
            }

            if ( current_user_can( 'bm_can_administrate' ) ) {
                $vars['canAdministrate'] = '1';
            }

            $geo = function_exists( 'wordplus_geo' ) ? wordplus_geo() : null;

            if ( $geo && $geo->flags_shown() ) {
                $vars['flags'] = $geo->flags_url();
            }

            if ( ! $sdk || 0 === $user_id ) {
                return $vars;
            }

            $editable = $sdk->editable( $user_id );

            $vars['profile'] = array(
                'name'   => $editable['name'] ? '1' : '0',
                'photo'  => $editable['photo'] ? '1' : '0',
                'plugin' => $user_id > 0 ? $this->profile_plugin() : '',
            );

            return $vars;
        }

        public function conversations_of( $user_id )
        {
            global $wpdb;

            return array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare(
                "SELECT `thread_id` FROM `" . bm_get_table( 'recipients' ) . "` WHERE `user_id` = %d AND `is_deleted` = 0",
                (int) $user_id
            ) ) );
        }

        public function visitor_items( $items, $visitor, $user )
        {
            $visitor = (int) $visitor;

            if ( $visitor >= 0 || Better_Messages()->settings['guestMoveOffer'] !== '1' ) {
                return $items;
            }

            $guest = Better_Messages()->guests->get_guest_user( $visitor );

            if ( ! $guest || Better_Messages()->guests->guest_bot_id( $guest ) > 0 ) {
                return $items;
            }

            $count = count( $this->conversations_of( $visitor ) );

            if ( $count > 0 ) {
                $items[] = array(
                    'plugin' => 'Better Messages',
                    'label'  => sprintf( _n( '%s conversation', '%s conversations', $count, 'bp-better-messages' ), number_format_i18n( $count ) ),
                );
            }

            return $items;
        }

        public function move_visitor( $visitor, $user )
        {
            if ( (int) $visitor < 0 ) {
                return $visitor;
            }

            $guest = Better_Messages()->guests->authenticated_guest_id();

            return $guest < 0 ? $guest : $visitor;
        }

        public function visitor_move( $visitor, $user )
        {
            $visitor = (int) $visitor;
            $user    = (int) $user;

            if ( $visitor >= 0 || $user <= 0 || ! Better_Messages()->guests->get_guest_user( $visitor ) ) {
                return;
            }

            $this->move_person( $visitor, $user );

            Better_Messages()->guests->delete_guest_user( $visitor, true );
        }

        public function visitor_forget( $visitor )
        {
            $visitor = (int) $visitor;

            if ( $visitor < 0 && Better_Messages()->guests->get_guest_user( $visitor ) ) {
                Better_Messages()->guests->delete_guest_user( $visitor, true );
            }
        }

        public function visitor_signed_in( $visitor, $user )
        {
            $this->carry_restrictions( (int) $visitor, (int) $user );
        }

        public function carry_restrictions( $from, $to )
        {
            global $wpdb;

            if ( $from >= 0 || 0 === $to || $from === $to ) {
                return;
            }

            $moderation = bm_get_table( 'moderation' );

            $wpdb->query( $wpdb->prepare(
                "INSERT IGNORE INTO `{$moderation}` ( `user_id`, `thread_id`, `type`, `expiration`, `admin_id`, `created_at` )
                SELECT %d, `thread_id`, `type`, `expiration`, `admin_id`, `created_at` FROM `{$moderation}` WHERE `user_id` = %d AND `type` IN ( 'ban', 'mute', 'call_ban' )",
                $to,
                $from
            ) );

            if ( $to < 0 ) {
                return;
            }

            $rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT `user_id`, `meta_value` FROM `{$wpdb->usermeta}` WHERE `meta_key` = 'bm_blocked_users' AND `meta_value` LIKE %s",
                '%' . $wpdb->esc_like( 'i:' . $from . ';' ) . '%'
            ) );

            foreach ( (array) $rows as $row ) {
                $blocked = maybe_unserialize( $row->meta_value );

                if ( is_array( $blocked ) && isset( $blocked[ $from ] ) && ! isset( $blocked[ $to ] ) ) {
                    $blocked[ $to ] = $blocked[ $from ];
                    update_user_meta( (int) $row->user_id, 'bm_blocked_users', $blocked );
                }
            }
        }

        public function move_person( $from, $to )
        {
            global $wpdb;

            $from = (int) $from;
            $to   = (int) $to;

            if ( $from >= 0 || 0 === $to || $from === $to ) {
                return 0;
            }

            $recipients = bm_get_table( 'recipients' );
            $messages   = bm_get_table( 'messages' );
            $mentions   = bm_get_table( 'mentions' );
            $meta       = bm_get_table( 'meta' );
            $files      = bm_get_table( 'files' );
            $threads    = $this->conversations_of( $from );
            $moved      = 0;

            $this->carry_restrictions( $from, $to );

            foreach ( $threads as $thread_id ) {
                $moved += (int) Better_Messages()->functions->with_lock( 'thread_participants|' . $thread_id, function () use ( $wpdb, $recipients, $messages, $mentions, $thread_id, $from, $to ) {
                    $theirs = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `{$recipients}` WHERE `thread_id` = %d AND `user_id` = %d", $thread_id, $from ) );
                    $mine   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `{$recipients}` WHERE `thread_id` = %d AND `user_id` = %d", $thread_id, $to ) );

                    if ( ! $theirs ) {
                        return 0;
                    }

                    $others = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM `{$recipients}` WHERE `thread_id` = %d AND `user_id` NOT IN ( %d, %d )", $thread_id, $from, $to ) );

                    if ( $mine && 0 === $others ) {
                        return 0;
                    }

                    $thread  = Better_Messages()->functions->get_thread( $thread_id );
                    $chat_id = $thread && 'chat-room' === $thread->type ? (int) Better_Messages()->functions->get_thread_meta( $thread_id, 'chat_id' ) : 0;

                    if ( $chat_id > 0 && ! $mine && $to > 0 && function_exists( 'Better_Messages_Chats' ) && ! Better_Messages_Chats()->user_can_join( $to, $chat_id ) ) {
                        $wpdb->delete( $recipients, array( 'thread_id' => $thread_id, 'user_id' => $from ), array( '%d', '%d' ) );
                    } else if ( $mine ) {
                        $wpdb->query( $wpdb->prepare( "UPDATE `{$recipients}` SET `unread_count` = `unread_count` + %d, `is_deleted` = 0 WHERE `thread_id` = %d AND `user_id` = %d", (int) $theirs->unread_count, $thread_id, $to ) );
                        $wpdb->delete( $recipients, array( 'thread_id' => $thread_id, 'user_id' => $from ), array( '%d', '%d' ) );
                    } else {
                        $wpdb->update( $recipients, array( 'user_id' => $to ), array( 'thread_id' => $thread_id, 'user_id' => $from ), array( '%d' ), array( '%d', '%d' ) );
                    }

                    $wpdb->update( $messages, array( 'sender_id' => $to ), array( 'thread_id' => $thread_id, 'sender_id' => $from ), array( '%d' ), array( '%d', '%d' ) );
                    $wpdb->query( $wpdb->prepare( "UPDATE IGNORE `{$mentions}` SET `user_id` = %d WHERE `thread_id` = %d AND `user_id` = %d", $to, $thread_id, $from ) );
                    $wpdb->delete( $mentions, array( 'thread_id' => $thread_id, 'user_id' => $from ), array( '%d', '%d' ) );

                    return 1;
                } );

                $this->move_message_meta( $thread_id, $from, $to, $meta, $messages );

                $wpdb->update( $files, array( 'uploader_id' => $to ), array( 'thread_id' => $thread_id, 'uploader_id' => $from ), array( '%d' ), array( '%d', '%d' ) );

                if ( class_exists( 'Better_Messages_E2E_Encryption' ) && Better_Messages_E2E_Encryption::is_e2e_thread( $thread_id ) && Better_Messages()->functions->is_user_participant( $thread_id, $to ) ) {
                    $pending = (array) Better_Messages_E2E_Encryption::get_pending_users( $thread_id );

                    if ( ! in_array( $to, array_map( 'intval', $pending ), true ) ) {
                        $pending[] = $to;
                        Better_Messages_E2E_Encryption::set_pending_users( $thread_id, $pending );
                    }
                }

                wp_cache_delete( 'thread_recipients_' . $thread_id, 'bp_messages' );
                wp_cache_delete( 'bm_thread_recipients_' . $thread_id, 'bm_messages' );

                do_action( 'better_messages_thread_updated', $thread_id );
                do_action( 'better_messages_info_changed', $thread_id, array( $to ) );
            }

            Better_Messages()->users->update_last_changed( $to );

            do_action( 'better_messages_person_moved', $from, $to, $threads );

            return $moved;
        }

        private function move_message_meta( $thread_id, $from, $to, $meta, $messages )
        {
            global $wpdb;

            $wpdb->query( $wpdb->prepare(
                "UPDATE `{$meta}` `meta` INNER JOIN `{$messages}` `messages` ON `messages`.`id` = `meta`.`bm_message_id`
                SET `meta`.`meta_value` = %s
                WHERE `messages`.`thread_id` = %d AND `meta`.`meta_key` = 'starred_by_user' AND `meta`.`meta_value` = %s",
                (string) $to,
                $thread_id,
                (string) $from
            ) );

            $rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT `meta`.`meta_id`, `meta`.`bm_message_id`, `meta`.`meta_value` FROM `{$meta}` `meta` INNER JOIN `{$messages}` `messages` ON `messages`.`id` = `meta`.`bm_message_id`
                WHERE `messages`.`thread_id` = %d AND `meta`.`meta_key` = 'bm_reactions' AND `meta`.`meta_value` LIKE %s",
                $thread_id,
                '%' . $wpdb->esc_like( 'i:' . $from . ';' ) . '%'
            ) );

            foreach ( (array) $rows as $row ) {
                $reactions = maybe_unserialize( $row->meta_value );

                if ( ! is_array( $reactions ) || ! isset( $reactions[ $from ] ) ) {
                    continue;
                }

                if ( ! isset( $reactions[ $to ] ) ) {
                    $reactions[ $to ] = $reactions[ $from ];
                }

                unset( $reactions[ $from ] );

                $wpdb->update( $meta, array( 'meta_value' => maybe_serialize( $reactions ) ), array( 'meta_id' => (int) $row->meta_id ) );
                wp_cache_delete( (int) $row->bm_message_id, 'bm_message_meta' );
            }
        }
    }

endif;

function Better_Messages_Profiles()
{
    return Better_Messages_Profiles::instance();
}
