<?php

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WordPlus_SDK_Booking_Fluent' ) ) {
    final class WordPlus_SDK_Booking_Fluent extends WordPlus_SDK_Booking_Adapter
    {
        const SLUG = 'fluentbooking';

        const GROUP_TYPES = array( 'group', 'group_event' );

        const ONLINE_TYPES = array( 'google_meet', 'zoom_meeting', 'ms_teams', 'online_meeting' );

        const PLACE_TYPES = array( 'in_person_organizer', 'in_person_guest', 'custom' );

        const DRAWN_TYPES = array( 'google_meet', 'ms_teams', 'zoom_meeting', 'online_meeting', 'in_person_guest', 'custom', 'in_person_organizer', 'phone_guest', 'phone_organizer' );

        const MEETING_TYPE = 'wordplus_';

        const MEETING_MARK = 'wordplus_meeting';

        const OTHERS_MARK = 'wordplus_others';

        private $looked_at_later = array();

        private $changed_later = array();

        private $looking_later = false;

        private $meetings_hooked = false;

        private $pages_said = array();

        public function slug()
        {
            return self::SLUG;
        }

        public function hook()
        {
            foreach ( array( 'scheduled', 'pending' ) as $status ) {
                add_action( 'fluent_booking/pre_after_booking_' . $status, array( $this, 'booking_made' ) );
                add_action( 'fluent_booking/after_booking_' . $status, array( $this, 'booking_made' ) );
            }

            add_action( 'fluent_booking/after_booking_meta_update', array( $this, 'row_added' ) );

            add_action( 'fluent_booking/after_booking_rescheduled', array( $this, 'booking_rescheduled' ), 10, 2 );
            add_action( 'fluent_booking/payment/status_changed', array( $this, 'payment_changed' ), 20, 2 );

            foreach ( array( 'cancelled', 'auto_cancelled', 'rejected', 'completed', 'no_show' ) as $status ) {
                add_action( 'fluent_booking/booking_schedule_' . $status, array( $this, 'booking_changed' ) );
            }

            foreach ( array( 'email', 'first_name', 'last_name' ) as $column ) {
                add_action( 'fluent_booking/after_patch_booking_' . $column, array( $this, 'booking_changed' ) );
            }

            add_action( 'fluent_booking/before_patch_booking_schedule', array( $this, 'look_later' ), 10, 2 );

            add_action( 'fluent_booking/after_delete_booking', array( $this, 'booking_deleted' ) );

            add_action( 'fluent_booking/booking_confirmation_footer', array( $this, 'booking_page' ) );
            add_filter( 'fluent_booking/schedule_receipt_data', array( $this, 'receipt_meeting' ), 10, 2 );

            add_filter( 'fluent_booking/booking_meta_info_main_meta', array( $this, 'host_page' ), 10, 2 );

            add_filter( 'fluent_booking/mail', array( $this, 'split_guests_mail' ), -1000, 2 );
            add_filter( 'fluent_booking/mail', array( $this, 'print_links' ), 10, 2 );
            add_filter( 'fluent_booking/editor_shortcodes_groups', array( $this, 'list_placeholders' ), 10, 3 );
        }

        public function placeholder( $key )
        {
            return '%' . $key . '_url%';
        }

        public function print_links( $mail, $context = array() )
        {
            if ( ! is_array( $mail ) || ! is_array( $context ) || empty( $context['booking'] ) ) {
                return $mail;
            }

            $id = self::id_of( $context['booking'] );

            if ( '' === $id ) {
                return $mail;
            }

            $others    = ! empty( $context[ self::OTHERS_MARK ] );
            $recipient = $others || ( isset( $context['recipient'] ) && 'host' === $context['recipient'] ) ? 'staff' : 'customer';
            $subject   = isset( $mail['subject'] ) ? (string) $mail['subject'] : '';
            $body      = isset( $mail['body'] ) ? (string) $mail['body'] : '';
            $booking   = null;

            if ( ! $others && isset( $context['recipient'] ) && 'guest' === $context['recipient'] ) {
                $meeting = $this->personal_meeting( $context['booking'], 'customer' );

                if ( null !== $meeting ) {
                    $body = WordPlus_SDK_Bookings::swap_address( $body, $meeting['from'], $meeting['to'] );
                }
            }

            foreach ( array_keys( $this->bookings->links() ) as $key ) {
                $placeholder = $this->placeholder( $key );

                if ( false === strpos( $subject, $placeholder ) && false === strpos( $body, $placeholder ) ) {
                    continue;
                }

                if ( null === $booking ) {
                    $booking = $this->get( $id );
                }

                if ( null === $booking ) {
                    return $mail;
                }

                $url     = $this->bookings->link_url( $key, $booking, $recipient );
                $subject = str_replace( $placeholder, $url, $subject );
                $body    = str_replace( $placeholder, esc_attr( $url ), $body );
            }

            $mail['subject'] = $subject;
            $mail['body']    = $body;

            return $mail;
        }

        public function split_guests_mail( $mail, $context = array() )
        {
            if ( ! is_array( $mail ) || ! is_array( $context ) || empty( $context['booking'] ) || ! empty( $context[ self::OTHERS_MARK ] ) ) {
                return $mail;
            }

            if ( ! isset( $context['recipient'] ) || 'guest' !== $context['recipient'] || ! class_exists( '\FluentBooking\App\Services\Mailer' ) ) {
                return $mail;
            }

            $others = self::additional_guests( $context['booking'] );

            if ( empty( $others ) || ! $this->signs( $mail, $context['booking'] ) ) {
                return $mail;
            }

            $split = self::split_addresses( isset( $mail['to'] ) ? $mail['to'] : '', $others );

            if ( empty( $split['others'] ) ) {
                return $mail;
            }

            $headers = array();

            foreach ( is_array( $mail['headers'] ?? null ) ? $mail['headers'] : preg_split( "/\r\n|\n|\r/", (string) ( $mail['headers'] ?? '' ) ) as $line ) {
                if ( '' !== trim( (string) $line ) && 0 !== stripos( ltrim( (string) $line ), 'content-type:' ) ) {
                    $headers[] = (string) $line;
                }
            }

            \FluentBooking\App\Services\Mailer::send(
                implode( ', ', $split['others'] ),
                isset( $mail['subject'] ) ? (string) $mail['subject'] : '',
                isset( $mail['body'] ) ? (string) $mail['body'] : '',
                $headers,
                isset( $mail['attachments'] ) ? (array) $mail['attachments'] : array(),
                array_merge( $context, array( self::OTHERS_MARK => true ) )
            );

            if ( empty( $split['kept'] ) ) {
                return false;
            }

            $mail['to'] = isset( $mail['to'] ) && is_array( $mail['to'] ) ? $split['kept'] : implode( ', ', $split['kept'] );

            return $mail;
        }

        private static function additional_guests( $booking )
        {
            if ( ! is_object( $booking ) || ! method_exists( $booking, 'getAdditionalGuests' ) ) {
                return array();
            }

            $own    = isset( $booking->email ) ? strtolower( trim( (string) $booking->email ) ) : '';
            $others = array();

            foreach ( (array) $booking->getAdditionalGuests() as $guest ) {
                $address = self::address_of( $guest );

                if ( '' !== $address && $address !== $own ) {
                    $others[ $address ] = true;
                }
            }

            return $others;
        }

        private function signs( array $mail, $booking )
        {
            $text = ( isset( $mail['subject'] ) ? (string) $mail['subject'] : '' ) . ' ' . ( isset( $mail['body'] ) ? (string) $mail['body'] : '' );

            foreach ( array_keys( $this->bookings->links() ) as $key ) {
                if ( false !== strpos( $text, $this->placeholder( $key ) ) ) {
                    return true;
                }
            }

            return null !== $this->personal_meeting( $booking, 'customer' );
        }

        private static function split_addresses( $to, array $others )
        {
            $split = array( 'kept' => array(), 'others' => array() );

            foreach ( is_array( $to ) ? $to : explode( ',', (string) $to ) as $entry ) {
                $entry = trim( (string) $entry );

                if ( '' === $entry ) {
                    continue;
                }

                $split[ isset( $others[ self::address_of( $entry ) ] ) ? 'others' : 'kept' ][] = $entry;
            }

            return $split;
        }

        private static function address_of( $entry )
        {
            $entry = trim( (string) $entry );

            if ( preg_match( '/<([^>]*)>/', $entry, $inside ) ) {
                $entry = $inside[1];
            }

            return strtolower( trim( $entry ) );
        }

        public function list_placeholders( $groups, $event = null, $is_html = false )
        {
            if ( ! $is_html || ! is_array( $groups ) || ! isset( $groups['booking']['shortcodes'] ) ) {
                return $groups;
            }

            foreach ( $this->bookings->links() as $key => $link ) {
                $placeholder = $this->placeholder( $key );

                $groups['booking']['shortcodes'][ $placeholder ] = '' !== $link['label'] ? $link['label'] : $placeholder;
            }

            return $groups;
        }

        public function meeting_registered( $key )
        {
            if ( $this->meetings_hooked ) {
                return;
            }

            $this->meetings_hooked = true;

            add_filter( 'fluent_booking/get_location_fields', array( $this, 'location_fields' ), 10, 2 );

            foreach ( array( 'scheduled', 'pending' ) as $status ) {
                add_action( 'fluent_booking/pre_after_booking_' . $status, array( $this, 'write_meeting' ), 5 );
            }

            add_action( 'fluent_booking/after_booking_rescheduled', array( $this, 'write_meeting' ), 5 );

            add_filter( 'fluent_booking/location_details_html', array( $this, 'meeting_html' ), 10, 2 );
            add_filter( 'fluent_booking/location_icon_heading_html', array( $this, 'meeting_heading' ), 10, 2 );
            add_filter( 'fluent_booking/booking_fields', array( $this, 'meeting_choices' ) );
        }

        public function location_fields( $fields )
        {
            if ( ! is_array( $fields ) ) {
                return $fields;
            }

            if ( ! isset( $fields['conferencing']['options'] ) || ! is_array( $fields['conferencing']['options'] ) ) {
                $fields['conferencing'] = array(
                    'label'   => isset( $fields['conferencing']['label'] ) ? $fields['conferencing']['label'] : 'Conferencing',
                    'options' => array(),
                );
            }

            foreach ( $this->bookings->meetings() as $key => $meeting ) {
                $fields['conferencing']['options'][ self::MEETING_TYPE . $key ] = array(
                    'title'         => $meeting['label'],
                    'location_type' => 'conferencing',
                    'disabled'      => false,
                );
            }

            return $fields;
        }

        public function write_meeting( $booking )
        {
            if ( '' === self::id_of( $booking ) ) {
                return;
            }

            $this->write_meeting_of( $booking );

            global $wpdb;

            $earlier = $wpdb->get_col( $wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}fcal_bookings WHERE parent_id = %d ORDER BY id ASC",
                (int) $booking->id
            ) );

            foreach ( (array) $earlier as $id ) {
                $this->write_meeting_of( (object) array( 'id' => (int) $id ) );
            }
        }

        private function write_meeting_of( $booking )
        {
            global $wpdb;

            $id      = self::id_of( $booking );
            $details = maybe_unserialize( (string) $wpdb->get_var( $wpdb->prepare(
                "SELECT location_details FROM {$wpdb->prefix}fcal_bookings WHERE id = %d",
                (int) $id
            ) ) );
            $key     = self::meeting_of( $details );

            if ( '' === $key ) {
                return;
            }

            $found = $this->get( $id );
            $url   = null === $found ? '' : $this->bookings->meeting_url( $key, $found );

            if ( '' === $url ) {
                return;
            }

            $written = array(
                'type'                 => 'online_meeting',
                'online_platform_link' => $url,
                self::MEETING_MARK     => $key,
            );

            if ( $written === $details ) {
                return;
            }

            $wpdb->update(
                $wpdb->prefix . 'fcal_bookings',
                array( 'location_details' => maybe_serialize( $written ) ),
                array( 'id' => (int) $id )
            );

            if ( $booking instanceof \FluentBooking\App\Models\Booking ) {
                $booking->location_details = $written;
            }
        }

        private function meeting_of( $details )
        {
            if ( ! is_array( $details ) || empty( $details['type'] ) ) {
                return '';
            }

            $type = (string) $details['type'];

            if ( 'online_meeting' === $type && ! empty( $details[ self::MEETING_MARK ] ) ) {
                $key = (string) $details[ self::MEETING_MARK ];
            } elseif ( 0 === strpos( $type, self::MEETING_TYPE ) ) {
                $key = substr( $type, strlen( self::MEETING_TYPE ) );
            } else {
                return '';
            }

            $meetings = $this->bookings->meetings();

            return isset( $meetings[ $key ] ) ? $key : '';
        }

        private function personal_meeting( $booking, $recipient )
        {
            $id = self::id_of( $booking );

            if ( '' === $id ) {
                return null;
            }

            $details = isset( $booking->location_details ) ? maybe_unserialize( $booking->location_details ) : null;
            $key     = $this->meeting_of( $details );

            if ( '' === $key || empty( $details['online_platform_link'] ) ) {
                return null;
            }

            $found = $this->get( $id );
            $to    = null === $found ? '' : $this->bookings->personal_meeting_url( $key, $found, $recipient );
            $from  = (string) $details['online_platform_link'];

            return '' === $to || $to === $from ? null : array( 'from' => $from, 'to' => $to );
        }

        public function receipt_meeting( $data, $booking = null )
        {
            $meeting = is_array( $data ) ? $this->personal_meeting( $booking, 'customer' ) : null;

            if ( null === $meeting ) {
                return $data;
            }

            foreach ( array( 'sections', 'bookmarks' ) as $part ) {
                if ( ! empty( $data[ $part ] ) && is_array( $data[ $part ] ) ) {
                    array_walk_recursive( $data[ $part ], function ( &$value ) use ( $meeting ) {
                        if ( is_string( $value ) ) {
                            $value = WordPlus_SDK_Bookings::swap_address( $value, $meeting['from'], $meeting['to'] );
                        }
                    } );
                }
            }

            return $data;
        }

        private function meeting_label( $type )
        {
            $meetings = $this->bookings->meetings();
            $type     = (string) $type;
            $key      = 0 === strpos( $type, self::MEETING_TYPE ) ? substr( $type, strlen( self::MEETING_TYPE ) ) : '';

            return '' !== $key && isset( $meetings[ $key ] ) ? $meetings[ $key ]['label'] : '';
        }

        public function meeting_html( $html, $details = array() )
        {
            $key = $this->meeting_of( $details );

            if ( '' === $key || 'online_meeting' !== $details['type'] ) {
                return $html;
            }

            $meetings = $this->bookings->meetings();

            return (string) preg_replace( '#^<b>[^<]*</b>#', '<b>' . esc_html( $meetings[ $key ]['label'] ) . '</b>', (string) $html, 1 );
        }

        public function meeting_heading( $html, $locations = array() )
        {
            $empty = '<div class="slot_location fcal_icon_item fcal_img_item fcal_loc_google_meet"></div>';
            $parts = explode( $empty, (string) $html );

            if ( count( $parts ) < 2 || ! is_array( $locations ) ) {
                return $html;
            }

            $icon  = defined( 'FLUENT_BOOKING_URL' ) ? '<img class="fcal_loc_icon link_icon" src="' . esc_url( FLUENT_BOOKING_URL . 'assets/images/link.svg' ) . '" alt="" />' : '';
            $drawn = array_shift( $parts );

            foreach ( $locations as $location ) {
                $type = is_array( $location ) && isset( $location['type'] ) ? (string) $location['type'] : '';

                if ( in_array( $type, self::DRAWN_TYPES, true ) ) {
                    continue;
                }

                if ( empty( $parts ) ) {
                    break;
                }

                $label  = $this->meeting_label( $type );
                $drawn .= '' === $label ? $empty : '<div class="slot_location fcal_icon_item fcal_img_item fcal_loc_' . esc_attr( $type ) . '">' . $icon . '<span class="fcal_loc_text">' . esc_html( $label ) . '</span></div>';
                $drawn .= array_shift( $parts );
            }

            foreach ( $parts as $part ) {
                $drawn .= $empty . $part;
            }

            return $drawn;
        }

        public function meeting_choices( $fields )
        {
            if ( ! is_array( $fields ) ) {
                return $fields;
            }

            foreach ( $fields as $index => $field ) {
                if ( ! is_array( $field ) || ! isset( $field['name'], $field['options'] ) || 'location' !== $field['name'] || ! is_array( $field['options'] ) ) {
                    continue;
                }

                foreach ( $field['options'] as $option => $choice ) {
                    $label = is_array( $choice ) && isset( $choice['type'] ) ? $this->meeting_label( $choice['type'] ) : '';

                    if ( '' !== $label ) {
                        $fields[ $index ]['options'][ $option ]['title'] = $label;
                    }
                }
            }

            return $fields;
        }

        public function booking_made( $booking )
        {
            $id = self::id_of( $booking );

            if ( '' !== $id ) {
                $this->made( $id );
            }
        }

        public function row_added( $booking )
        {
            if ( is_object( $booking ) && isset( $booking->status ) && ! in_array( (string) $booking->status, array( 'scheduled', 'pending', 'reserved' ), true ) ) {
                $this->booking_made( $booking );
            }
        }

        public function booking_changed( $booking )
        {
            $id = self::id_of( $booking );

            if ( '' !== $id ) {
                $this->changed( $id );
            }
        }

        public function booking_rescheduled( $booking, $previous = null )
        {
            global $wpdb;

            $this->booking_changed( $booking );

            if ( '' === self::id_of( $booking ) || ! is_object( $previous ) || empty( $previous->group_id ) || empty( $booking->event_type ) ) {
                return;
            }

            if ( ! in_array( (string) $booking->event_type, self::GROUP_TYPES, true ) || (int) $previous->group_id === (int) $booking->group_id ) {
                return;
            }

            $left = $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}fcal_bookings WHERE event_id = %d AND group_id = %d AND id <> %d ORDER BY id ASC LIMIT 1",
                (int) $booking->event_id,
                (int) $previous->group_id,
                (int) $booking->id
            ) );

            if ( null !== $left ) {
                $this->changed( (string) $left );
            }
        }

        public function payment_changed( $order, $booking = null )
        {
            $id = self::id_of( $booking );

            if ( '' === $id ) {
                return;
            }

            $this->changed_later[ $id ] = true;
            $this->look_at_the_end();
        }

        public function look_later( $booking, $data = array() )
        {
            if ( ! is_array( $data ) || ! isset( $data['column'], $data['update_all'] ) || 'status' !== $data['column'] || ! filter_var( $data['update_all'], FILTER_VALIDATE_BOOLEAN ) ) {
                return;
            }

            if ( ! is_object( $booking ) || empty( $booking->event_id ) || empty( $booking->group_id ) ) {
                return;
            }

            $event = (int) $booking->event_id;
            $group = (int) $booking->group_id;

            $this->looked_at_later[ $event . ':' . $group ] = array( 'event' => $event, 'group' => $group );
            $this->look_at_the_end();
        }

        private function look_at_the_end()
        {
            if ( ! $this->looking_later ) {
                $this->looking_later = true;

                add_action( 'shutdown', array( $this, 'look_now' ) );
            }
        }

        public function look_now()
        {
            global $wpdb;

            $ids = array();

            foreach ( $this->looked_at_later as $held ) {
                $ids = array_merge( $ids, (array) $wpdb->get_col( $wpdb->prepare(
                    "SELECT id FROM {$wpdb->prefix}fcal_bookings WHERE event_id = %d AND group_id = %d",
                    $held['event'],
                    $held['group']
                ) ) );
            }

            foreach ( array_keys( $this->changed_later ) as $id ) {
                $ids = array_merge( $ids, (array) $wpdb->get_col( $wpdb->prepare(
                    "SELECT id FROM {$wpdb->prefix}fcal_bookings WHERE id = %d OR parent_id = %d ORDER BY id ASC",
                    (int) $id,
                    (int) $id
                ) ) );
            }

            $this->looked_at_later = array();
            $this->changed_later   = array();
            $this->looking_later   = false;

            foreach ( array_unique( array_map( 'strval', $ids ) ) as $id ) {
                $this->changed( $id );
            }
        }

        public function booking_deleted( $id )
        {
            if ( (int) $id > 0 ) {
                $this->deleted( (string) (int) $id );
            }
        }

        public function booking_page( $booking )
        {
            $id = self::id_of( $booking );

            if ( '' === $id || isset( $this->pages_said[ $id ] ) ) {
                return;
            }

            $this->pages_said[ $id ] = true;

            $html = $this->page_html( $id, 'customer' );

            if ( '' !== $html ) {
                echo '<div class="fcal_booking_manage fcal_normal_booking_footer wordplus-booking-page">' . $html . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            }
        }

        public function host_page( $cards, $booking = null )
        {
            $id      = self::id_of( $booking );
            $user_id = (int) get_current_user_id();
            $shaped  = '' !== $id && $user_id > 0 && is_array( $cards ) ? $this->get( $id ) : null;

            if ( null === $shaped || ! in_array( $user_id, array_map( 'intval', array_column( $shaped['staff'], 'user_id' ) ), true ) ) {
                return $cards;
            }

            $html = $this->page_html( $id, 'staff' );

            if ( '' !== $html ) {
                $cards[] = array(
                    'id'      => 'wordplus_booking_page',
                    'title'   => _x( 'Messages', 'Bookings (FluentBooking): the heading of the card on a booking in the view of its host', 'wordplus-sdk' ),
                    'content' => '<p>' . $html . '</p>',
                );
            }

            return $cards;
        }

        private static function id_of( $booking )
        {
            return is_object( $booking ) && ! empty( $booking->id ) ? (string) $booking->id : '';
        }

        public function get( $id )
        {
            global $wpdb;

            $row = $wpdb->get_row( $wpdb->prepare(
                "SELECT b.id, b.hash, b.group_id, b.parent_id, b.host_user_id, b.person_user_id, b.start_time, b.end_time,
                    b.first_name, b.last_name, b.email, b.location_details, b.status, b.event_type,
                    e.title AS service, e.max_book_per_slot, c.user_id AS calendar_user_id
                FROM {$wpdb->prefix}fcal_bookings b
                LEFT JOIN {$wpdb->prefix}fcal_calendar_events e ON e.id = b.event_id
                LEFT JOIN {$wpdb->prefix}fcal_calendars c ON c.id = b.calendar_id
                WHERE b.id = %d AND b.booking_type = 'scheduling'",
                (int) $id
            ), ARRAY_A );

            if ( ! is_array( $row ) ) {
                return null;
            }

            $id       = (int) $row['id'];
            $in_group = in_array( (string) $row['event_type'], self::GROUP_TYPES, true );
            $location = self::location( $row['location_details'] );
            $staff    = self::hosts( self::host_ids( $id ), (int) $row['host_user_id'], (int) $row['calendar_user_id'] );
            $group    = '';

            if ( $in_group && ! empty( $row['group_id'] ) ) {
                $group = 'slot_' . (int) $row['group_id'];
            } elseif ( count( $staff ) > 1 ) {
                $group = 'team_' . $id;
            }

            return WordPlus_SDK_Bookings::shape( array(
                'plugin'     => self::SLUG,
                'id'         => (string) $id,
                'status'     => self::status( (string) $row['status'] ),
                'start'      => self::utc( $row['start_time'] ),
                'end'        => self::utc( $row['end_time'] ),
                'service'    => (string) $row['service'],
                'place'      => $location['place'],
                'online'     => $location['online'],
                'staff'      => $staff,
                'customers'  => array( self::customer( $row ) ),
                'capacity'   => $in_group ? max( 1, (int) $row['max_book_per_slot'] ) : 1,
                'group'      => $group,
                'series'     => '' === $group ? self::series( $id, (int) $row['parent_id'] ) : '',
                'seats'      => 1,
                'manage_url' => self::attendee_page( (string) $row['hash'] ),
            ) );
        }

        private static function series( $id, $parent_id )
        {
            global $wpdb;

            if ( $parent_id > 0 ) {
                return 'series_' . $parent_id;
            }

            $named = $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}fcal_bookings WHERE parent_id = %d LIMIT 1",
                $id
            ) );

            return null === $named ? '' : 'series_' . $id;
        }

        public function series_bookings( array $booking )
        {
            global $wpdb;

            if ( ! preg_match( '/^series_(\d+)$/D', $booking['series'], $series ) ) {
                return array( (string) $booking['id'] );
            }

            $ids = $wpdb->get_col( $wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}fcal_bookings WHERE id = %d OR parent_id = %d ORDER BY id ASC",
                (int) $series[1],
                (int) $series[1]
            ) );

            return array_values( array_unique( array_merge( array( (string) $booking['id'] ), array_map( 'strval', (array) $ids ) ) ) );
        }

        private static function status( $status )
        {
            $map = array(
                'scheduled'   => WordPlus_SDK_Bookings::APPROVED,
                'rescheduled' => WordPlus_SDK_Bookings::APPROVED,
                'pending'     => WordPlus_SDK_Bookings::PENDING,
                'reserved'    => WordPlus_SDK_Bookings::PENDING,
                'cancelled'   => WordPlus_SDK_Bookings::CANCELLED,
                'rejected'    => WordPlus_SDK_Bookings::REJECTED,
                'completed'   => WordPlus_SDK_Bookings::COMPLETED,
                'no_show'     => WordPlus_SDK_Bookings::NO_SHOW,
            );

            return isset( $map[ $status ] ) ? $map[ $status ] : WordPlus_SDK_Bookings::PENDING;
        }

        private static function location( $details )
        {
            $details = maybe_unserialize( (string) $details );

            if ( ! is_array( $details ) ) {
                return array( 'place' => '', 'online' => false );
            }

            $type  = isset( $details['type'] ) ? (string) $details['type'] : '';
            $place = '';

            if ( in_array( $type, self::PLACE_TYPES, true ) ) {
                $place = ! empty( $details['title'] ) ? $details['title'] : ( isset( $details['description'] ) ? $details['description'] : '' );
            }

            return array(
                'place'  => trim( wp_strip_all_tags( (string) $place ) ),
                'online' => in_array( $type, self::ONLINE_TYPES, true ) || ! empty( $details['online_platform_link'] ),
            );
        }

        public function group_bookings( array $booking )
        {
            global $wpdb;

            if ( ! preg_match( '/^slot_(\d+)$/D', $booking['group'], $slot ) ) {
                return array( (string) $booking['id'] );
            }

            $ids = $wpdb->get_col( $wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}fcal_bookings WHERE group_id = %d ORDER BY id ASC",
                (int) $slot[1]
            ) );

            return array_values( array_unique( array_merge( array( (string) $booking['id'] ), array_map( 'strval', (array) $ids ) ) ) );
        }

        private static function host_ids( $booking_id )
        {
            global $wpdb;

            $ids = $wpdb->get_col( $wpdb->prepare(
                "SELECT user_id FROM {$wpdb->prefix}fcal_booking_hosts WHERE booking_id = %d ORDER BY id ASC",
                $booking_id
            ) );

            return array_values( array_unique( array_filter( array_map( 'intval', (array) $ids ) ) ) );
        }

        private static function hosts( array $others, $host_id, $calendar_user_id )
        {
            $ids   = array_merge( array( $host_id > 0 ? $host_id : $calendar_user_id ), $others );
            $staff = array();

            foreach ( array_unique( array_filter( $ids ) ) as $user_id ) {
                $user = get_userdata( $user_id );

                if ( false !== $user ) {
                    $name    = trim( $user->first_name . ' ' . $user->last_name );
                    $staff[] = self::person( $user_id, '' !== $name ? $name : $user->display_name, $user->user_email );
                }
            }

            return $staff;
        }

        private static function customer( array $row )
        {
            $user_id = (int) $row['person_user_id'];

            if ( $user_id > 0 ) {
                $user = get_userdata( $user_id );

                if ( false === $user || strtolower( trim( (string) $user->user_email ) ) !== strtolower( trim( (string) $row['email'] ) ) ) {
                    $user_id = 0;
                }
            }

            return self::person( $user_id, $row['first_name'] . ' ' . $row['last_name'], $row['email'] );
        }

        private static function attendee_page( $hash )
        {
            if ( '' === $hash ) {
                return '';
            }

            return add_query_arg(
                array(
                    'fluent-booking' => 'booking',
                    'meeting_hash'   => $hash,
                    'type'           => 'confirmation',
                ),
                apply_filters( 'fluent_booking/booking_receipt_landing_base_url', site_url( '/' ) )
            );
        }
    }
}
