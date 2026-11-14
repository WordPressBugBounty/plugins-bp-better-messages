<?php
defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Better_Messages_Bookings' ) ) :

    class Better_Messages_Bookings
    {
        const QUERY_VAR = 'bm-booking';

        const LINK_CHAT = 'bm_chat';

        const LINK_CALL = 'bm_call';

        const CALL_VAR = 'bm-call';

        const OPEN_VAR = 'bm-booking-open';

        const META_KEY = 'booking_key';

        const META_REF = 'booking_ref';

        const META_STATE = 'booking_state';

        const SETTINGS = array(
            'amelia'        => 'bookingsAmelia',
            'latepoint'     => 'bookingsLatePoint',
            'fluentbooking' => 'bookingsFluentBooking',
            'ssa'           => 'bookingsSSA',
        );

        const SYSTEM_TYPES = array( 'booking_rescheduled', 'booking_cancelled', 'booking_approved', 'booking_rejected' );

        public static function instance()
        {
            static $instance = null;

            if ( null === $instance ) {
                $instance = new Better_Messages_Bookings();
            }

            return $instance;
        }

        public function __construct()
        {
            add_filter( 'better_messages_system_message_text', array( $this, 'system_message_text' ), 10, 3 );
            add_filter( 'better_messages_rest_thread_item', array( $this, 'thread_item' ), 10, 5 );
            add_filter( 'better_messages_rest_thread_item', array( $this, 'call_window_item' ), 20, 5 );
            add_filter( 'better_messages_can_send_message', array( $this, 'close_replies' ), 20, 3 );
            add_filter( 'better_messages_private_call_allowed_error', array( $this, 'refuse_call' ), 10, 4 );

            $on = $this->enabled_plugins();

            if ( empty( $on ) || ! function_exists( 'wordplus_bookings' ) || ! wordplus_bookings() ) {
                return;
            }

            wordplus_bookings()->start( $on );

            add_action( 'init', array( $this, 'register_links' ), 5 );
            add_action( 'template_redirect', array( $this, 'landing' ), -1 );
            add_action( 'rest_api_init', array( $this, 'rest_api_init' ) );

            add_action( WordPlus_SDK_Bookings::ACTION_CHANGED, array( $this, 'booking_changed' ) );
            add_action( WordPlus_SDK_Bookings::ACTION_DELETED, array( $this, 'booking_deleted' ), 10, 2 );
            add_action( 'wordplus_booking_replaced', array( $this, 'booking_replaced' ), 10, 3 );
            add_action( WordPlus_SDK_Bookings::ACTION_PAGE, array( $this, 'page_button' ), 10, 3 );

            add_filter( 'better_messages_email_recipients_where', array( $this, 'email_recipients_where' ) );
            add_filter( 'better_messages_guest_gets_emails', array( $this, 'guest_gets_emails' ), 10, 2 );
            add_filter( 'better_messages_email_thread_url', array( $this, 'email_thread_url' ), 10, 3 );
        }

        public function enabled_plugins()
        {
            $on = array();

            foreach ( self::SETTINGS as $slug => $setting ) {
                if ( isset( Better_Messages()->settings[ $setting ] ) && Better_Messages()->settings[ $setting ] === '1' ) {
                    $on[] = $slug;
                }
            }

            return $on;
        }

        public function register_links()
        {
            wordplus_bookings()->register_link( self::LINK_CHAT, array(
                'label' => _x( 'The link to the conversation of this booking', 'Bookings', 'bp-better-messages' ),
                'url'   => array( $this, 'chat_url' ),
            ) );

            wordplus_bookings()->register_link( self::LINK_CALL, array(
                'label' => _x( 'The link that opens the conversation of this booking and starts its video call', 'Bookings', 'bp-better-messages' ),
                'url'   => array( $this, 'call_url' ),
            ) );

            if ( method_exists( wordplus_bookings(), 'register_meeting' ) ) {
                wordplus_bookings()->register_meeting( self::LINK_CALL, array(
                    'label'    => _x( 'Video call', 'Bookings (the place of a booking)', 'bp-better-messages' ),
                    'url'      => array( $this, 'meeting_url' ),
                    'personal' => array( $this, 'call_url' ),
                ) );
            }
        }

        private function key_of( array $booking )
        {
            if ( $this->is_group( $booking ) ) {
                $what = $booking['group'];
            } elseif ( $this->is_series( $booking ) ) {
                $what = $booking['series'];
            } else {
                $what = $booking['id'];
            }

            return 'booking_' . $booking['plugin'] . '_' . $what;
        }

        private function is_group( array $booking )
        {
            return '' !== $booking['group'];
        }

        private function is_series( array $booking )
        {
            return ! $this->is_group( $booking ) && '' !== ( $booking['series'] ?? '' );
        }

        private function dates_of( array $booking )
        {
            return $this->is_series( $booking ) && method_exists( wordplus_bookings(), 'series_of' ) ? wordplus_bookings()->series_of( $booking ) : array( $booking );
        }

        private function current_date( array $booking )
        {
            return $this->is_series( $booking ) && method_exists( wordplus_bookings(), 'current_of_series' ) ? wordplus_bookings()->current_of_series( $booking ) : $booking;
        }

        private function takes_part( array $booking )
        {
            return ! WordPlus_SDK_Bookings::is_closed( $booking ) && WordPlus_SDK_Bookings::WAITING !== $booking['status'];
        }

        private function group_chats_on()
        {
            return ( Better_Messages()->settings['bookingsGroupChats'] ?? '1' ) === '1';
        }

        public function find_conversation( array $booking )
        {
            global $wpdb;

            $thread_id = (int) $wpdb->get_var( $wpdb->prepare(
                'SELECT `bm_thread_id` FROM `' . bm_get_table( 'threadsmeta' ) . '` WHERE `meta_key` = %s AND `meta_value` = %s LIMIT 1',
                self::META_KEY,
                $this->key_of( $booking )
            ) );

            if ( $thread_id < 1 && $this->is_series( $booking ) ) {
                $thread_id = $this->series_conversation_rekeyed( $booking );
            }

            return $thread_id > 0 && Better_Messages()->functions->is_conversation_exists( $thread_id ) ? $thread_id : 0;
        }

        private function series_conversation_rekeyed( array $booking )
        {
            global $wpdb;

            $refs = array();

            foreach ( $this->dates_of( $booking ) as $date ) {
                $refs[] = $booking['plugin'] . '.' . $date['id'];
            }

            $table     = bm_get_table( 'threadsmeta' );
            $thread_id = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT `ref`.`bm_thread_id` FROM `{$table}` `ref` INNER JOIN `{$table}` `key` ON `key`.`bm_thread_id` = `ref`.`bm_thread_id` AND `key`.`meta_key` = %s AND `key`.`meta_value` LIKE %s WHERE `ref`.`meta_key` = %s AND `ref`.`meta_value` IN (" . implode( ',', array_fill( 0, count( $refs ), '%s' ) ) . ') LIMIT 1',
                array_merge( array( self::META_KEY, $wpdb->esc_like( 'booking_' . $booking['plugin'] . '_series_' ) . '%', self::META_REF ), $refs )
            ) );

            if ( $thread_id > 0 ) {
                Better_Messages()->functions->update_thread_meta( $thread_id, self::META_KEY, $this->key_of( $booking ) );
            }

            return $thread_id;
        }

        public function conversation_for( array $booking )
        {
            if ( $this->is_group( $booking ) && ! $this->group_chats_on() ) {
                return 0;
            }

            $key = $this->key_of( $booking );

            return (int) Better_Messages()->functions->with_lock( 'booking_conversation|' . $key, function () use ( $booking, $key ) {
                $thread_id = $this->find_conversation( $booking );

                if ( $thread_id > 0 ) {
                    $this->sync_members( $thread_id, $booking );
                    return $thread_id;
                }

                $members = $this->members_of( $booking );

                if ( count( $members ) < 2 ) {
                    return 0;
                }

                $thread_id = Better_Messages()->functions->create_new_conversation( $members, $this->subject_of( $booking ) );

                if ( ! $thread_id ) {
                    return 0;
                }

                $functions = Better_Messages()->functions;

                $functions->update_thread_meta( $thread_id, self::META_KEY, $key );
                $functions->update_thread_meta( $thread_id, self::META_REF, $booking['plugin'] . '.' . $booking['id'] );
                $functions->update_thread_meta( $thread_id, self::META_STATE, $this->state_of( $this->current_date( $booking ) ) );

                if ( $this->is_series( $booking ) ) {
                    foreach ( $this->dates_of( $booking ) as $date ) {
                        $functions->update_thread_meta( $thread_id, $this->state_key( $date ), $this->state_of( $date ) );
                    }
                }

                if ( $this->is_group( $booking ) ) {
                    global $wpdb;

                    $wpdb->update( bm_get_table( 'threads' ), array( 'type' => 'booking' ), array( 'id' => $thread_id ), array( '%s' ), array( '%d' ) );
                    wp_cache_delete( 'thread_' . $thread_id . '_type', 'bm_messages' );
                    Better_Messages()->hooks->clean_thread_cache( $thread_id );
                }

                return $thread_id;
            } );
        }

        private function subject_of( array $booking )
        {
            return sprintf( _x( 'Booking: %s', 'Bookings (subject of the conversation)', 'bp-better-messages' ), $booking['service'] );
        }

        private function state_of( array $booking )
        {
            return array(
                'status' => $booking['status'],
                'start'  => (int) $booking['start'],
                'end'    => (int) $booking['end'],
            );
        }

        private function state_key( array $booking )
        {
            return $this->is_series( $booking ) ? self::META_STATE . '_' . $booking['id'] : self::META_STATE;
        }

        public function support_user()
        {
            return isset( Better_Messages()->settings['bookingsSupportUser'] ) ? (int) Better_Messages()->settings['bookingsSupportUser'] : 0;
        }

        private function staff_users( array $booking )
        {
            $users = array();

            foreach ( $booking['staff'] as $person ) {
                if ( $person['user_id'] > 0 ) {
                    $users[] = (int) $person['user_id'];
                }
            }

            if ( empty( $users ) && $this->support_user() > 0 ) {
                $users[] = $this->support_user();
            }

            return $users;
        }

        private function customer_user( array $person )
        {
            if ( $person['user_id'] > 0 ) {
                return (int) $person['user_id'];
            }

            if ( '' === $person['email'] ) {
                return 0;
            }

            return Better_Messages()->guests->guest_for_email( $person['email'], $person['name'] );
        }

        private function members_of( array $booking )
        {
            $members = $this->staff_users( $booking );

            if ( $this->is_series( $booking ) ) {
                foreach ( $this->dates_of( $booking ) as $date ) {
                    if ( $this->takes_part( $date ) ) {
                        $members = array_merge( $members, $this->staff_users( $date ) );
                    }
                }
            }

            if ( ! $this->is_group( $booking ) ) {
                $customer = $this->customer_user( $booking['customers'][0] ?? array( 'user_id' => 0, 'email' => '', 'name' => '' ) );

                if ( 0 !== $customer ) {
                    $members[] = $customer;
                }

                return array_values( array_unique( $members ) );
            }

            $adapter = wordplus_bookings()->adapter( $booking['plugin'] );
            $ids     = $adapter ? $adapter->group_bookings( $booking ) : array( $booking['id'] );

            foreach ( $ids as $id ) {
                $other = (string) $id === $booking['id'] ? $booking : wordplus_bookings()->get( $booking['plugin'], $id );

                if ( null === $other || ! $this->takes_part( $other ) ) {
                    continue;
                }

                foreach ( $other['customers'] as $person ) {
                    $customer = $this->customer_user( $person );

                    if ( 0 !== $customer ) {
                        $members[] = $customer;
                    }
                }
            }

            return array_values( array_unique( $members ) );
        }

        private function sync_members( $thread_id, array $booking )
        {
            $wanted = $this->members_of( $booking );

            if ( empty( $wanted ) || ( ! $this->is_group( $booking ) && count( $wanted ) < 2 ) ) {
                return;
            }

            $functions = Better_Messages()->functions;
            $current   = array_map( 'intval', array_keys( (array) $functions->get_recipients( $thread_id ) ) );

            foreach ( array_diff( $wanted, $current ) as $user_id ) {
                $functions->add_participant_to_thread( $thread_id, (int) $user_id );
            }

            foreach ( array_diff( $current, $wanted ) as $user_id ) {
                $functions->remove_participant_from_thread( $thread_id, (int) $user_id );
            }
        }

        public function booking_changed( array $booking )
        {
            if ( ! isset( self::SETTINGS[ $booking['plugin'] ] ) || ! in_array( $booking['plugin'], $this->enabled_plugins(), true ) ) {
                return;
            }

            $thread_id = $this->find_conversation( $booking );

            if ( 0 === $thread_id ) {
                return;
            }

            Better_Messages()->functions->with_lock( 'booking_conversation|' . $this->key_of( $booking ), function () use ( $thread_id, $booking ) {
                $this->apply_change( $thread_id, $booking );
            } );
        }

        private function apply_change( $thread_id, array $booking )
        {
            $functions = Better_Messages()->functions;
            $series    = $this->is_series( $booking );
            $kept      = $functions->get_thread_meta( $thread_id, $this->state_key( $booking ) );
            $known     = is_array( $kept );
            $kept      = $known ? $kept : array();
            $changes   = WordPlus_SDK_Bookings::changes( $kept, $booking );

            $this->sync_members( $thread_id, $booking );

            if ( $this->is_group( $booking ) ) {
                $changes['status'] = false;
                $booking['status'] = $kept['status'] ?? WordPlus_SDK_Bookings::APPROVED;
            }

            if ( $series ) {
                $functions->update_thread_meta( $thread_id, self::META_STATE, $this->state_of( $this->current_date( $booking ) ) );

                if ( ! $known ) {
                    $functions->update_thread_meta( $thread_id, $this->state_key( $booking ), $this->state_of( $booking ) );
                    return;
                }
            }

            if ( ! $changes['status'] && ! $changes['moved'] ) {
                return;
            }

            $functions->update_thread_meta( $thread_id, $this->state_key( $booking ), $this->state_of( $booking ) );

            if ( $changes['moved'] ) {
                $this->post( $thread_id, 'booking_rescheduled', $booking, $series ? array( 'series' => true, 'from' => (int) ( $kept['start'] ?? 0 ) ) : array() );
            }

            if ( $changes['status'] ) {
                $type = $this->system_type_of( $booking['status'] );

                if ( '' !== $type && ( ! $series || 'booking_cancelled' === $type ) ) {
                    $this->post( $thread_id, $type, $booking, $series ? array( 'series' => true ) : array() );
                }
            }

            do_action( 'better_messages_thread_updated', $thread_id );

            foreach ( $functions->get_recipients_ids( (int) $thread_id, false ) as $member ) {
                $functions->thread_updated_for_user( (int) $thread_id, (int) $member, false );
            }
        }

        private function system_type_of( $status )
        {
            switch ( $status ) {
                case WordPlus_SDK_Bookings::CANCELLED:
                    return 'booking_cancelled';
                case WordPlus_SDK_Bookings::REJECTED:
                    return 'booking_rejected';
                case WordPlus_SDK_Bookings::APPROVED:
                    return 'booking_approved';
                default:
                    return '';
            }
        }

        private function post( $thread_id, $type, array $booking, array $extra = array() )
        {
            $data = array_merge( array(
                'start' => (int) $booking['start'],
                'end'   => (int) $booking['end'],
            ), $extra );

            Better_Messages()->functions->send_system_message( $thread_id, $type, $data, array(
                'count_unread' => true,
                'show_on_site' => true,
                'send_push'    => true,
                'mobile_push'  => true,
                'notification' => array(
                    'title' => $booking['service'],
                    'body'  => $this->system_text( $type, $data ),
                    'type'  => $type,
                ),
            ) );
        }

        public function booking_replaced( $plugin, $old_id, $booking )
        {
            global $wpdb;

            if ( ! is_array( $booking ) || ! in_array( (string) $plugin, $this->enabled_plugins(), true ) ) {
                return;
            }

            $old = wordplus_bookings()->get( $plugin, $old_id );

            if ( null !== $old && $this->is_group( $old ) && $this->key_of( $old ) !== $this->key_of( $booking ) ) {
                $old_thread = $this->find_conversation( $old );

                if ( $old_thread > 0 ) {
                    Better_Messages()->functions->with_lock( 'booking_conversation|' . $this->key_of( $old ), function () use ( $old_thread, $old ) {
                        $this->sync_members( $old_thread, $old );
                    } );
                }
            }

            $thread_id = (int) $wpdb->get_var( $wpdb->prepare(
                'SELECT `bm_thread_id` FROM `' . bm_get_table( 'threadsmeta' ) . '` WHERE `meta_key` = %s AND `meta_value` = %s LIMIT 1',
                self::META_REF,
                $plugin . '.' . $old_id
            ) );

            if ( $thread_id < 1 || ! Better_Messages()->functions->is_conversation_exists( $thread_id ) || 0 !== $this->find_conversation( $booking ) ) {
                return;
            }

            $functions = Better_Messages()->functions;

            if ( $functions->get_thread_meta( $thread_id, self::META_KEY ) === 'booking_' . $plugin . '_' . $old_id ) {
                $functions->update_thread_meta( $thread_id, self::META_KEY, $this->key_of( $booking ) );
            }

            $functions->update_thread_meta( $thread_id, self::META_REF, $plugin . '.' . $booking['id'] );
        }

        public function booking_deleted( $plugin, $id )
        {
            global $wpdb;

            $thread_id = (int) $wpdb->get_var( $wpdb->prepare(
                'SELECT `bm_thread_id` FROM `' . bm_get_table( 'threadsmeta' ) . '` WHERE `meta_key` = %s AND `meta_value` = %s LIMIT 1',
                self::META_REF,
                $plugin . '.' . $id
            ) );

            if ( $thread_id < 1 || ! Better_Messages()->functions->is_conversation_exists( $thread_id ) ) {
                return;
            }

            $functions = Better_Messages()->functions;
            $state     = $functions->get_thread_meta( $thread_id, self::META_STATE );
            $state     = is_array( $state ) ? $state : array();

            if ( ( $state['status'] ?? '' ) === WordPlus_SDK_Bookings::CANCELLED || 'booking' === $functions->get_thread_type( $thread_id ) || $functions->get_thread_meta( $thread_id, self::META_KEY ) !== 'booking_' . $plugin . '_' . $id ) {
                return;
            }

            $state['status'] = WordPlus_SDK_Bookings::CANCELLED;
            $functions->update_thread_meta( $thread_id, self::META_STATE, $state );

            $functions->send_system_message( $thread_id, 'booking_cancelled', array( 'start' => (int) ( $state['start'] ?? 0 ), 'end' => (int) ( $state['end'] ?? 0 ) ), array(
                'count_unread' => true,
            ) );

            do_action( 'better_messages_thread_updated', $thread_id );
        }

        public function close_replies( $allowed, $user_id, $thread_id )
        {
            if ( ! $allowed ) {
                return $allowed;
            }

            $state = Better_Messages()->functions->get_thread_meta( $thread_id, self::META_STATE );

            if ( ! is_array( $state ) || ! in_array( $state['status'] ?? '', array( 'cancelled', 'rejected' ), true ) ) {
                return $allowed;
            }

            if ( 'booking' === Better_Messages()->functions->get_thread_type( $thread_id ) ) {
                return $allowed;
            }

            global $bp_better_messages_restrict_send_message;
            $bp_better_messages_restrict_send_message['booking_closed'] = 'rejected' === $state['status']
                ? _x( 'This booking was rejected, so replies are closed.', 'Bookings', 'bp-better-messages' )
                : _x( 'This booking was cancelled, so replies are closed.', 'Bookings', 'bp-better-messages' );

            return false;
        }

        public function thread_item( $thread_item, $thread_id, $thread_type, $include_personal, $user_id )
        {
            if ( 'thread' !== $thread_type && 'booking' !== $thread_type ) {
                return $thread_item;
            }

            $booking = $this->booking_of_conversation( $thread_id );

            if ( null === $booking ) {
                return $thread_item;
            }

            $thread_item['threadInfo'] = ( $thread_item['threadInfo'] ?? '' ) . $this->card_html( $booking, $thread_type, $include_personal ? $this->manages( $booking, $thread_id, (int) $user_id ) : false );

            return $this->with_staff_override( $thread_item, $booking );
        }

        public function booking_of_conversation( $thread_id )
        {
            static $bookings = array();

            $thread_id = (int) $thread_id;

            if ( array_key_exists( $thread_id, $bookings ) ) {
                return $bookings[ $thread_id ];
            }

            $ref   = Better_Messages()->functions->get_thread_meta( $thread_id, self::META_REF );
            $parts = ! empty( $ref ) && function_exists( 'wordplus_bookings' ) && wordplus_bookings() ? WordPlus_SDK_Bookings::parse_reference( (string) $ref ) : null;

            $booking = null === $parts ? null : wordplus_bookings()->get( $parts['plugin'], $parts['id'] );

            $bookings[ $thread_id ] = null === $booking ? null : $this->current_date( $booking );

            return $bookings[ $thread_id ];
        }

        public function call_minutes()
        {
            return max( 0, (int) ( Better_Messages()->settings['bookingsCallMinutes'] ?? 10 ) );
        }

        public function call_window_for( $thread_id, $user_id )
        {
            $booking = $this->booking_of_conversation( $thread_id );

            if ( null === $booking || $this->is_group( $booking ) || $booking['start'] < 1 || $booking['end'] <= $booking['start'] ) {
                return null;
            }

            if ( in_array( (int) $user_id, $this->staff_users( $booking ), true ) ) {
                return null;
            }

            return WordPlus_SDK_Bookings::call_window( $booking, $this->call_minutes() );
        }

        public function call_window_item( $thread_item, $thread_id, $thread_type, $include_personal, $user_id = null )
        {
            if ( ! $include_personal || 'thread' !== $thread_type || ! isset( $thread_item['permissions'] ) || ! is_array( $thread_item['permissions'] ) ) {
                return $thread_item;
            }

            $permissions = $thread_item['permissions'];

            if ( true !== ( $permissions['canVideoCall'] ?? false ) && true !== ( $permissions['canAudioCall'] ?? false ) ) {
                return $thread_item;
            }

            $window = $this->call_window_for( $thread_id, null === $user_id ? Better_Messages()->functions->get_current_user_id() : (int) $user_id );

            if ( null !== $window ) {
                $thread_item['permissions']['callWindow'] = $window;
            }

            return $thread_item;
        }

        public function refuse_call( $error, $thread_id, $user_id, $target_user_id )
        {
            if ( '' !== (string) $error ) {
                return $error;
            }

            $booking = $this->booking_of_conversation( $thread_id );

            if ( null === $booking || $this->is_group( $booking ) ) {
                return $error;
            }

            if ( WordPlus_SDK_Bookings::is_closed( $booking ) ) {
                return _x( 'This booking was cancelled, so calls are closed.', 'Bookings', 'bp-better-messages' );
            }

            $window = $this->call_window_for( $thread_id, $user_id );

            if ( null === $window ) {
                return $error;
            }

            $now = time();

            if ( $now < $window['open'] ) {
                $minutes = $this->call_minutes();

                return $minutes > 0
                    ? sprintf( _nx( 'You can call from %d minute before the booking starts.', 'You can call from %d minutes before the booking starts.', $minutes, 'Bookings', 'bp-better-messages' ), $minutes )
                    : _x( 'You can call once the booking starts.', 'Bookings', 'bp-better-messages' );
            }

            if ( $now > $window['close'] ) {
                return _x( 'This booking has ended, so calls are closed.', 'Bookings', 'bp-better-messages' );
            }

            return $error;
        }

        private function calls_available( array $booking )
        {
            if ( ! class_exists( 'Better_Messages_Calls' ) || WordPlus_SDK_Bookings::is_closed( $booking ) ) {
                return false;
            }

            $setting = $this->is_group( $booking ) ? 'groupCallsBookings' : 'videoCalls';

            return ( Better_Messages()->settings[ $setting ] ?? '0' ) === '1';
        }

        private function with_staff_override( $thread_item, array $booking )
        {
            $support = $this->support_user();

            if ( $support < 1 || empty( $booking['staff'] ) || (int) $booking['staff'][0]['user_id'] > 0 || '' === $booking['staff'][0]['name'] ) {
                return $thread_item;
            }

            if ( ! isset( $thread_item['participantOverrides'] ) || ! is_array( $thread_item['participantOverrides'] ) ) {
                $thread_item['participantOverrides'] = array();
            }

            $thread_item['participantOverrides'][ (string) $support ] = array(
                'name' => $booking['staff'][0]['name'],
            );

            return $thread_item;
        }

        private function card_html( array $booking, $thread_type, $manage = false )
        {
            $staff = array();

            foreach ( $booking['staff'] as $person ) {
                if ( '' !== $person['name'] ) {
                    $staff[] = $person['name'];
                }
            }

            $place  = $booking['online'] ? _x( 'Online', 'Bookings (card)', 'bp-better-messages' ) : $booking['place'];
            $start  = (int) $booking['start'];
            $end    = (int) $booking['end'];
            $status = 'booking' !== $thread_type ? $booking['status'] : '';
            $time   = (string) get_option( 'time_format' );
            $line   = function ( $kind, $content ) {
                return '<span class="bm-booking-card__line bm-booking-card__line--' . $kind . '">' . $content . '</span>';
            };

            $html = '<div class="bm-product-info bm-booking-card' . ( '' !== $status ? ' bm-booking-card--' . esc_attr( $status ) : '' ) . '">';

            if ( $start > 0 ) {
                $at    = esc_attr( gmdate( 'c', $start ) );
                $html .= '<div class="bm-booking-card__date" aria-hidden="true">';
                $html .= '<time class="bm-local-time bm-booking-card__month" data-format="month" datetime="' . $at . '">' . esc_html( wp_date( 'M', $start ) ) . '</time>';
                $html .= '<time class="bm-local-time bm-booking-card__day" data-format="day" datetime="' . $at . '">' . esc_html( wp_date( 'j', $start ) ) . '</time>';
                $html .= '</div>';
            }

            $html .= '<div class="bm-product-details bm-booking-card__details">';
            $html .= '<div class="bm-booking-card__head">';
            $html .= '<div class="bm-product-title">' . esc_html( $booking['service'] ) . '</div>';

            if ( '' !== $status ) {
                $html .= '<span class="bm-booking-status bm-booking-status-' . esc_attr( $status ) . '">' . esc_html( $this->status_label( $status ) ) . '</span>';
            }

            $html .= '</div><div class="bm-booking-card__lines">';

            if ( $start > 0 ) {
                $shown = wp_date( 'D, M j', $start ) . ' · ' . wp_date( $time, $start );

                if ( $end > $start ) {
                    $shown .= ' – ' . ( wp_date( 'Y-m-d', $end ) === wp_date( 'Y-m-d', $start ) ? wp_date( $time, $end ) : wp_date( 'D, M j', $end ) . ' · ' . wp_date( $time, $end ) );
                }

                $html .= $line( 'when', '<time class="bm-local-time" data-format="range" datetime="' . esc_attr( gmdate( 'c', $start ) ) . '"' . ( $end > $start ? ' data-end="' . esc_attr( gmdate( 'c', $end ) ) . '"' : '' ) . '>' . esc_html( $shown ) . '</time>' );
            }

            if ( ! empty( $staff ) ) {
                $html .= $line( 'with', esc_html( sprintf( _x( 'With %s', 'Bookings (card): the staff member', 'bp-better-messages' ), implode( ', ', $staff ) ) ) );
            }

            if ( '' !== $place ) {
                $html .= $line( $booking['online'] ? 'online' : 'place', esc_html( $place ) );
            }

            $dates = $this->is_series( $booking ) ? count( $this->dates_of( $booking ) ) : 1;
            $seats = (int) ( $booking['seats'] ?? 1 );

            if ( $dates > 1 ) {
                $html .= $line( 'dates', esc_html( sprintf( _nx( '%d date', '%d dates', $dates, 'Bookings (card): the dates of a recurring booking', 'bp-better-messages' ), $dates ) ) );
            }

            if ( $seats > 1 && 'booking' !== $thread_type ) {
                $html .= $line( 'seats', esc_html( sprintf( _nx( '%d place', '%d places', $seats, 'Bookings (card): the places one customer booked', 'bp-better-messages' ), $seats ) ) );
            }

            $html .= '</div></div>';

            if ( $manage && '' !== $booking['manage_url'] && 'booking' !== $thread_type ) {
                $html .= '<a class="bm-booking-card__manage" href="' . esc_url( $booking['manage_url'] ) . '" target="_blank" rel="noopener noreferrer">' . esc_html_x( 'Manage booking', 'Bookings (card)', 'bp-better-messages' ) . '</a>';
            }

            return $html . '</div>';
        }

        private function manages( array $booking, $thread_id, $user_id )
        {
            if ( 0 === $user_id || in_array( $user_id, $this->staff_users( $booking ), true ) || $user_id === $this->support_user() ) {
                return false;
            }

            return in_array( $user_id, array_map( 'intval', Better_Messages()->functions->get_recipients_ids( (int) $thread_id ) ), true );
        }

        private function status_label( $status )
        {
            switch ( $status ) {
                case WordPlus_SDK_Bookings::APPROVED:
                    return _x( 'Confirmed', 'Bookings (status)', 'bp-better-messages' );
                case WordPlus_SDK_Bookings::WAITING:
                    return _x( 'Waiting list', 'Bookings (status)', 'bp-better-messages' );
                case WordPlus_SDK_Bookings::CANCELLED:
                    return _x( 'Cancelled', 'Bookings (status)', 'bp-better-messages' );
                case WordPlus_SDK_Bookings::REJECTED:
                    return _x( 'Rejected', 'Bookings (status)', 'bp-better-messages' );
                case WordPlus_SDK_Bookings::COMPLETED:
                    return _x( 'Completed', 'Bookings (status)', 'bp-better-messages' );
                case WordPlus_SDK_Bookings::NO_SHOW:
                    return _x( 'No show', 'Bookings (status)', 'bp-better-messages' );
                default:
                    return _x( 'Pending', 'Bookings (status)', 'bp-better-messages' );
            }
        }

        public function system_message_text( $text, $type, $data )
        {
            return in_array( $type, self::SYSTEM_TYPES, true ) ? $this->system_text( $type, is_array( $data ) ? $data : array() ) : $text;
        }

        private function system_text( $type, array $data )
        {
            $format = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
            $start  = (int) ( $data['start'] ?? 0 );
            $from   = (int) ( $data['from'] ?? 0 );

            switch ( $type ) {
                case 'booking_rescheduled':
                    if ( $start > 0 && $from > 0 ) {
                        return sprintf( _x( 'The booking of %1$s moved to %2$s', 'Bookings (system message)', 'bp-better-messages' ), wp_date( $format, $from ), wp_date( $format, $start ) );
                    }

                    return $start > 0
                        ? sprintf( _x( 'This booking moved to %s', 'Bookings (system message)', 'bp-better-messages' ), wp_date( $format, $start ) )
                        : _x( 'This booking moved to a new time', 'Bookings (system message)', 'bp-better-messages' );
                case 'booking_cancelled':
                    if ( ! empty( $data['series'] ) && $start > 0 ) {
                        return sprintf( _x( 'The booking of %s was cancelled', 'Bookings (system message)', 'bp-better-messages' ), wp_date( $format, $start ) );
                    }

                    return _x( 'This booking was cancelled', 'Bookings (system message)', 'bp-better-messages' );
                case 'booking_approved':
                    return _x( 'This booking was approved', 'Bookings (system message)', 'bp-better-messages' );
                case 'booking_rejected':
                    return _x( 'This booking was rejected', 'Bookings (system message)', 'bp-better-messages' );
                default:
                    return '';
            }
        }

        public function page_url()
        {
            foreach ( array( 'chatPage', 'guestChatPage' ) as $setting ) {
                $page = Better_Messages()->settings[ $setting ] ?? 0;

                if ( is_numeric( $page ) && (int) $page > 0 && 'publish' === get_post_status( (int) $page ) ) {
                    return (string) get_permalink( (int) $page );
                }
            }

            return '';
        }

        public function chat_url( array $booking, $recipient = 'customer' )
        {
            $page = $this->page_url();
            $base = '' !== $page ? $page : home_url( '/' );
            $ref  = wordplus_bookings()->reference( $booking['plugin'], $booking['id'] );

            if ( 'customer' !== $recipient ) {
                return add_query_arg( self::QUERY_VAR, $ref, $base );
            }

            $person = $booking['customers'][0] ?? null;

            if ( null === $person || (int) $person['user_id'] > 0 ) {
                return add_query_arg( self::QUERY_VAR, $ref, $base );
            }

            if ( '' === $person['email'] || '' === $page ) {
                return '';
            }

            $guest_id = Better_Messages()->guests->guest_for_email( $person['email'], $person['name'] );
            $guest    = 0 !== $guest_id ? Better_Messages()->guests->get_guest_user( $guest_id ) : false;

            if ( ! $guest ) {
                return '';
            }

            return add_query_arg(
                self::QUERY_VAR,
                wordplus_bookings()->reference( $booking['plugin'], $booking['id'], $guest_id, (string) $guest->secret ),
                $base
            );
        }

        public function call_url( array $booking, $recipient = 'customer' )
        {
            $url = $this->chat_url( $booking, $recipient );

            if ( '' === $url || ! $this->calls_available( $booking ) ) {
                return $url;
            }

            return add_query_arg( self::CALL_VAR, 'video', $url );
        }

        public function meeting_url( array $booking )
        {
            $url = $this->chat_url( $booking, 'staff' );

            if ( '' === $url || ! $this->calls_available( $booking ) ) {
                return $url;
            }

            return add_query_arg( self::CALL_VAR, 'video', $url );
        }

        private function conversation_of_guest( array $booking, $guest_id )
        {
            $guest = $guest_id < 0 ? Better_Messages()->guests->get_guest_user( (int) $guest_id ) : false;

            if ( ! $guest || empty( $guest->email ) || ! Better_Messages()->guests->email_mailable( $guest ) ) {
                return 0;
            }

            $mine = null;

            if ( ! empty( $booking['customers'][0]['email'] ) && 0 === strcasecmp( $booking['customers'][0]['email'], (string) $guest->email ) ) {
                $mine = $booking;
            } elseif ( $this->is_group( $booking ) ) {
                $mine = $this->guest_booking_in_group( $booking, (string) $guest->email );
            }

            if ( null === $mine || (int) $mine['customers'][0]['user_id'] > 0 ) {
                return 0;
            }

            return $this->conversation_for( $mine );
        }

        public function rest_api_init()
        {
            register_rest_route( 'better-messages/v1', '/bookings/conversation', array(
                'methods'             => 'POST',
                'callback'            => array( $this, 'rest_conversation' ),
                'permission_callback' => array( Better_Messages_Rest_Api(), 'is_user_authorized' ),
            ) );
        }

        public function rest_conversation( WP_REST_Request $request )
        {
            $refused   = new WP_Error( 'bm_booking_not_yours', _x( 'This booking is not yours.', 'Rest API Error', 'bp-better-messages' ), array( 'status' => 403 ) );
            $guest_id  = Better_Messages()->functions->get_current_user_id();
            $guest     = $guest_id < 0 ? Better_Messages()->guests->get_guest_user( $guest_id ) : false;

            if ( ! $guest || empty( $guest->email ) || ! Better_Messages()->guests->email_mailable( $guest ) ) {
                return $refused;
            }

            $page      = $this->page_url();
            $reference = WordPlus_SDK_Bookings::parse_reference( sanitize_text_field( (string) $request->get_param( 'reference' ) ) );

            if ( '' === $page || null === $reference || ! in_array( $reference['plugin'], $this->enabled_plugins(), true ) ) {
                return $refused;
            }

            $booking = method_exists( wordplus_bookings(), 'living' )
                ? wordplus_bookings()->living( $reference['plugin'], $reference['id'] )
                : wordplus_bookings()->get( $reference['plugin'], $reference['id'] );

            $thread_id = null === $booking ? 0 : $this->conversation_of_guest( $booking, $guest_id );

            if ( $thread_id < 1 ) {
                return $refused;
            }

            $call = sanitize_key( (string) $request->get_param( 'call' ) );
            $call = in_array( $call, array( 'video', 'audio' ), true ) ? $call : '';

            return array(
                'thread_id' => $thread_id,
                'url'       => $this->with_call( Better_Messages()->functions->add_hash_arg( 'conversation/' . $thread_id, array( 'scrollToContainer' => '' ), $page ), $thread_id, $call ),
            );
        }

        public function login_url( $raw, $call = '' )
        {
            $page = $this->page_url();
            $back = add_query_arg( self::QUERY_VAR, rawurlencode( $raw ), '' !== $page ? $page : home_url( '/' ) );

            if ( in_array( $call, array( 'video', 'audio' ), true ) ) {
                $back = add_query_arg( self::CALL_VAR, $call, $back );
            }

            return apply_filters( 'better_messages_login_url', wp_login_url( $back ) );
        }

        private function requested_call()
        {
            $call = isset( $_GET[ self::CALL_VAR ] ) ? sanitize_key( wp_unslash( $_GET[ self::CALL_VAR ] ) ) : '';

            return in_array( $call, array( 'video', 'audio' ), true ) ? $call : '';
        }

        private function with_call( $url, $thread_id, $call )
        {
            return ( $thread_id > 0 && '' !== $call && false !== strpos( $url, '#' ) ) ? $url . '&' . $call . 'Call' : $url;
        }

        private function is_member( array $booking, $user_id )
        {
            if ( in_array( (int) $user_id, $this->staff_users( $booking ), true ) ) {
                return true;
            }

            foreach ( $booking['customers'] as $person ) {
                if ( (int) $person['user_id'] === (int) $user_id ) {
                    return true;
                }
            }

            return false;
        }

        public function landing()
        {
            if ( ! isset( $_GET[ self::QUERY_VAR ] ) ) {
                return;
            }

            nocache_headers();

            $page      = $this->page_url();
            $fallback  = '' !== $page ? $page : home_url( '/' );
            $raw       = sanitize_text_field( wp_unslash( $_GET[ self::QUERY_VAR ] ) );
            $reference = WordPlus_SDK_Bookings::parse_reference( $raw );
            $call      = $this->requested_call();
            $booking   = null;

            if ( null !== $reference && in_array( $reference['plugin'], $this->enabled_plugins(), true ) ) {
                $booking = method_exists( wordplus_bookings(), 'living' )
                    ? wordplus_bookings()->living( $reference['plugin'], $reference['id'] )
                    : wordplus_bookings()->get( $reference['plugin'], $reference['id'] );
            }

            if ( is_user_logged_in() ) {
                $user_id = get_current_user_id();

                if ( null === $booking || ! $this->is_member( $booking, $user_id ) ) {
                    $this->go( Better_Messages()->functions->get_user_messages_url( $user_id ) ?: $fallback );
                }

                $thread_id = $this->conversation_for( $booking );

                $this->go( $thread_id > 0 ? $this->with_call( Better_Messages()->functions->get_user_messages_url( $user_id, $thread_id ), $thread_id, $call ) : Better_Messages()->functions->get_user_messages_url( $user_id ) );
            }

            if ( null !== $booking && '' !== $page && '' !== $reference['signature'] && ! empty( $booking['customers'][0]['email'] ) && (int) $booking['customers'][0]['user_id'] < 1 ) {
                $person   = $booking['customers'][0];
                $guest_id = Better_Messages()->guests->guest_for_email( $person['email'], $person['name'] );
                $guest    = 0 !== $guest_id ? Better_Messages()->guests->get_guest_user( $guest_id ) : false;

                if ( $guest && WordPlus_SDK_Bookings::signature_valid( $reference, $guest_id, (string) $guest->secret ) ) {
                    $thread_id = $this->conversation_for( $booking );

                    $this->go( $this->with_call( Better_Messages()->guests->handover_url( $guest_id, Better_Messages()->functions->add_hash_arg( $thread_id > 0 ? 'conversation/' . $thread_id : '', array( 'scrollToContainer' => '' ), $page ) ), $thread_id, $call ) );
                }
            }

            if ( null !== $booking && '' !== $page && '' === $reference['signature'] && ( $this->is_group( $booking ) || (int) ( $booking['customers'][0]['user_id'] ?? 0 ) < 1 ) ) {
                $open = add_query_arg( self::OPEN_VAR, rawurlencode( $raw ), $page );

                $this->go( '' !== $call ? add_query_arg( self::CALL_VAR, $call, $open ) : $open );
            }

            $this->go( $this->login_url( $raw, $call ) );
        }

        private function go( $url )
        {
            wp_safe_redirect( $url );
            exit;
        }

        public function page_button( array $booking, $recipient, $classes = '' )
        {
            $url = $this->chat_url( $booking, $recipient );

            if ( '' === $url ) {
                return;
            }

            $other = 'staff' === $recipient ? ( $booking['customers'] ?? array() ) : ( $booking['staff'] ?? array() );
            $name  = '';

            if ( ! $this->is_group( $booking ) && 1 === count( $other ) && ( 'staff' === $recipient || (int) ( $other[0]['user_id'] ?? 0 ) > 0 ) ) {
                $name = trim( (string) ( $other[0]['name'] ?? '' ) );
            }
            $label = '' !== $name
                ? sprintf( _x( 'Message %s', 'Bookings (button on a booking page)', 'bp-better-messages' ), $name )
                : _x( 'Message', 'Bookings (button on a booking page)', 'bp-better-messages' );

            echo '<a class="' . esc_attr( trim( 'bm-booking-chat-link ' . $classes ) ) . '" href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a>';
        }

        public function email_recipients_where( $where )
        {
            return "( {$where} OR ( `recipients`.`user_id` < 0 AND `recipients`.`thread_id` IN ( SELECT `bm_thread_id` FROM `" . bm_get_table( 'threadsmeta' ) . "` WHERE `meta_key` = '" . self::META_KEY . "' ) ) )";
        }

        public function guest_gets_emails( $gets, $user_id )
        {
            $guest = Better_Messages()->guests->get_guest_user( (int) $user_id );

            return ( $guest && ! empty( $guest->email ) ) ? true : $gets;
        }

        public function email_thread_url( $url, $user_id, $thread_id )
        {
            if ( (int) $user_id >= 0 ) {
                return $url;
            }

            $ref   = Better_Messages()->functions->get_thread_meta( $thread_id, self::META_REF );
            $parts = empty( $ref ) ? null : WordPlus_SDK_Bookings::parse_reference( (string) $ref );

            if ( null === $parts ) {
                return $url;
            }

            $guest   = Better_Messages()->guests->get_guest_user( (int) $user_id );
            $booking = wordplus_bookings()->get( $parts['plugin'], $parts['id'] );

            if ( ! $guest || null === $booking ) {
                return $url;
            }

            if ( ! empty( $booking['group'] ) ) {
                $mine = $this->guest_booking_in_group( $booking, (string) $guest->email );

                if ( null === $mine ) {
                    return $url;
                }

                $booking = $mine;
            }

            return $this->chat_url( $booking, 'customer' );
        }

        private function guest_booking_in_group( array $booking, $email )
        {
            $adapter = wordplus_bookings()->adapter( $booking['plugin'] );

            foreach ( $adapter ? $adapter->group_bookings( $booking ) : array() as $id ) {
                $other = wordplus_bookings()->get( $booking['plugin'], $id );

                if ( null !== $other && ! empty( $other['customers'][0]['email'] ) && 0 === strcasecmp( $other['customers'][0]['email'], $email ) ) {
                    return $other;
                }
            }

            return null;
        }
    }

endif;

function Better_Messages_Bookings()
{
    return Better_Messages_Bookings::instance();
}
