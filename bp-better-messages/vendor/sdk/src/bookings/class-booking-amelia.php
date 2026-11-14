<?php

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WordPlus_SDK_Booking_Amelia' ) ) {
    final class WordPlus_SDK_Booking_Amelia extends WordPlus_SDK_Booking_Adapter
    {
        const SLUG = 'amelia';

        const PLACEHOLDER_PREFIX = 'amelia_dynamic_placeholder_';

        private $looked_at_later = array();

        public function slug()
        {
            return self::SLUG;
        }

        public function hook()
        {
            add_action( 'amelia_after_appointment_booking_saved', array( $this, 'booking_made' ) );
            add_action( 'amelia_after_event_booking_saved', array( $this, 'booking_made' ) );
            add_action( 'amelia_after_appointment_added', array( $this, 'appointment_made' ) );

            add_action( 'amelia_after_appointment_status_updated', array( $this, 'appointment_changed' ) );
            add_action( 'amelia_after_appointment_updated', array( $this, 'appointment_updated' ), 10, 3 );
            add_action( 'amelia_after_booking_rescheduled', array( $this, 'rescheduled' ), 10, 2 );
            add_action( 'amelia_after_event_status_updated', array( $this, 'event_changed' ) );
            add_action( 'amelia_after_event_booking_updated', array( $this, 'booking_changed' ) );

            foreach ( array( 'approved', 'pending', 'rejected', 'canceled', 'no-show', 'waiting', 'approved_link', 'rejected_link' ) as $status ) {
                add_action( 'amelia_after_booking_' . $status, array( $this, 'booking_changed' ) );
            }

            add_action( 'amelia_before_booking_canceled', array( $this, 'look_later' ) );

            add_action( 'amelia_after_appointment_deleted', array( $this, 'appointment_deleted' ) );
            add_action( 'amelia_after_event_booking_deleted', array( $this, 'booking_deleted' ) );
            add_action( 'amelia_after_event_deleted', array( $this, 'appointment_deleted' ) );
        }

        public function link_registered( $key )
        {
            add_filter( self::PLACEHOLDER_PREFIX . $key, function ( $data ) use ( $key ) {
                return $this->print_link( $key, $data );
            } );
        }

        public function placeholder( $key )
        {
            return '%' . self::PLACEHOLDER_PREFIX . $key . '%';
        }

        public function print_link( $key, $data )
        {
            if ( ! is_array( $data ) ) {
                return '';
            }

            foreach ( array( 'appointment_cancel_url', 'event_cancel_url' ) as $field ) {
                if ( ! empty( $data[ $field ] ) && preg_match( '#/bookings/cancel/(\d+)#', (string) $data[ $field ], $match ) ) {
                    $booking = $this->get( $match[1] );

                    return null === $booking ? '' : $this->bookings->link_url( $key, $booking, 'customer' );
                }
            }

            $id = 0;

            if ( ! empty( $data['appointment_id'] ) ) {
                $id = $this->first_booking_of_appointment( (int) $data['appointment_id'] );
            } elseif ( ! empty( $data['event_id'] ) ) {
                $id = $this->first_booking_of_event( (int) $data['event_id'] );
            }

            $booking = $id > 0 ? $this->get( (string) $id ) : null;

            return null === $booking ? '' : $this->bookings->link_url( $key, $booking, 'staff' );
        }

        public function booking_made( $booking )
        {
            if ( is_array( $booking ) && ! empty( $booking['id'] ) ) {
                $this->made( $booking['id'] );
            }
        }

        public function appointment_made( $appointment )
        {
            foreach ( self::bookings_in( $appointment ) as $id ) {
                $this->made( $id );
            }
        }

        public function booking_changed( $booking )
        {
            if ( is_array( $booking ) && ! empty( $booking['id'] ) ) {
                $this->changed( $booking['id'] );
            } elseif ( is_array( $booking ) && ! empty( $booking['booking']['id'] ) ) {
                $this->changed( $booking['booking']['id'] );
            }
        }

        public function appointment_changed( $appointment )
        {
            foreach ( self::bookings_in( $appointment ) as $id ) {
                $this->changed( $id );
            }
        }

        public function appointment_updated( $appointment, $old_appointment = null, $removed = null )
        {
            $this->appointment_changed( $appointment );

            foreach ( self::bookings_in( array( 'bookings' => $removed ) ) as $id ) {
                $this->deleted( $id );
            }
        }

        public function rescheduled( $old_appointment, $booking = null )
        {
            if ( is_array( $booking ) && ! empty( $booking['id'] ) ) {
                $this->changed( $booking['id'] );
                return;
            }

            $this->appointment_changed( $old_appointment );
        }

        public function event_changed( $event )
        {
            foreach ( self::bookings_in( $event ) as $id ) {
                $this->changed( $id );
            }
        }

        public function look_later( $booking )
        {
            if ( ! is_array( $booking ) || empty( $booking['id'] ) ) {
                return;
            }

            if ( empty( $this->looked_at_later ) ) {
                add_action( 'shutdown', array( $this, 'look_now' ) );
            }

            $this->looked_at_later[ (string) $booking['id'] ] = true;
        }

        public function look_now()
        {
            foreach ( array_keys( $this->looked_at_later ) as $id ) {
                $this->changed( (string) $id );
            }

            $this->looked_at_later = array();
        }

        public function appointment_deleted( $appointment )
        {
            foreach ( self::bookings_in( $appointment ) as $id ) {
                $this->deleted( $id );
            }
        }

        public function booking_deleted( $booking )
        {
            if ( is_array( $booking ) && ! empty( $booking['id'] ) ) {
                $this->deleted( $booking['id'] );
            }
        }

        private static function bookings_in( $holder )
        {
            if ( ! is_array( $holder ) || empty( $holder['bookings'] ) || ! is_array( $holder['bookings'] ) ) {
                return array();
            }

            $ids = array();

            foreach ( $holder['bookings'] as $booking ) {
                if ( is_array( $booking ) && ! empty( $booking['id'] ) ) {
                    $ids[] = (string) $booking['id'];
                }
            }

            return $ids;
        }

        public function get( $id )
        {
            global $wpdb;

            $row = $wpdb->get_row( $wpdb->prepare(
                "SELECT b.id, b.appointmentId, b.customerId, b.status, b.persons, c.externalId, c.firstName, c.lastName, c.email
                FROM {$wpdb->prefix}amelia_customer_bookings b
                LEFT JOIN {$wpdb->prefix}amelia_users c ON c.id = b.customerId
                WHERE b.id = %d",
                (int) $id
            ), ARRAY_A );

            if ( ! is_array( $row ) ) {
                return null;
            }

            $fields = empty( $row['appointmentId'] ) ? $this->event_fields( (int) $row['id'] ) : $this->appointment_fields( (int) $row['appointmentId'], (int) $row['customerId'] );

            if ( null === $fields ) {
                return null;
            }

            $held_by = (string) $fields['held_status'];
            unset( $fields['held_status'] );

            return WordPlus_SDK_Bookings::shape( array_merge( $fields, array(
                'plugin'     => self::SLUG,
                'id'         => (string) $row['id'],
                'status'     => self::status( (string) $row['status'], $held_by ),
                'customers'  => array( self::person( (int) $row['externalId'], $row['firstName'] . ' ' . $row['lastName'], $row['email'] ) ),
                'seats'      => (int) $row['persons'],
                'manage_url' => '',
            ) ) );
        }

        private static function status( $booking, $held_by )
        {
            $map = array(
                'approved' => WordPlus_SDK_Bookings::APPROVED,
                'pending'  => WordPlus_SDK_Bookings::PENDING,
                'waiting'  => WordPlus_SDK_Bookings::WAITING,
                'canceled' => WordPlus_SDK_Bookings::CANCELLED,
                'rejected' => WordPlus_SDK_Bookings::REJECTED,
                'no-show'  => WordPlus_SDK_Bookings::NO_SHOW,
            );

            if ( isset( $map[ $held_by ] ) && in_array( $map[ $held_by ], WordPlus_SDK_Bookings::CLOSED, true ) ) {
                return $map[ $held_by ];
            }

            return isset( $map[ $booking ] ) ? $map[ $booking ] : WordPlus_SDK_Bookings::PENDING;
        }

        private function appointment_fields( $appointment_id, $customer_id )
        {
            global $wpdb;

            $row = $wpdb->get_row( $wpdb->prepare(
                "SELECT a.status, a.bookingStart, a.bookingEnd, a.parentId, a.googleMeetUrl, a.microsoftTeamsUrl, a.zoomMeeting, a.lessonSpace,
                    s.name AS service, l.name AS location,
                    p.externalId, p.firstName, p.lastName, p.email,
                    " . self::slot_columns() . ",
                    EXISTS ( SELECT 1 FROM {$wpdb->prefix}amelia_appointments r WHERE r.parentId = a.id ) AS isParent
                FROM {$wpdb->prefix}amelia_appointments a
                LEFT JOIN {$wpdb->prefix}amelia_services s ON s.id = a.serviceId
                LEFT JOIN {$wpdb->prefix}amelia_locations l ON l.id = a.locationId
                LEFT JOIN {$wpdb->prefix}amelia_users p ON p.id = a.providerId
                WHERE a.id = %d",
                $appointment_id
            ), ARRAY_A );

            if ( ! is_array( $row ) ) {
                return null;
            }

            $capacity = self::capacity( $row );
            $group    = $capacity > 1 ? 'slot_' . $appointment_id : '';
            $first    = (int) $row['parentId'] > 0 ? (int) $row['parentId'] : ( empty( $row['isParent'] ) ? 0 : $appointment_id );
            $series   = '' === $group && $first > 0 ? 'series_' . $first . '_' . $customer_id : '';

            return array(
                'held_status' => (string) $row['status'],
                'start'       => self::utc( $row['bookingStart'] ),
                'end'         => self::utc( $row['bookingEnd'] ),
                'service'     => (string) $row['service'],
                'place'       => (string) $row['location'],
                'online'      => '' !== (string) $row['googleMeetUrl'] || '' !== (string) $row['microsoftTeamsUrl'] || '' !== (string) $row['zoomMeeting'] || '' !== (string) $row['lessonSpace'],
                'staff'       => array( self::person( (int) $row['externalId'], $row['firstName'] . ' ' . $row['lastName'], $row['email'] ) ),
                'capacity'    => $capacity,
                'group'       => $group,
                'series'      => $series,
            );
        }

        private static function slot_columns()
        {
            global $wpdb;

            return "s.maxCapacity,
                ( SELECT MAX(e.maxCapacity) FROM {$wpdb->prefix}amelia_providers_to_services e WHERE e.userId = a.providerId AND e.serviceId = a.serviceId ) AS employeeCapacity,
                ( SELECT COUNT(*) FROM {$wpdb->prefix}amelia_customer_bookings t WHERE t.appointmentId = a.id AND COALESCE(t.status, '') NOT IN ( 'canceled', 'rejected', 'waiting' ) ) AS takingPart";
        }

        private static function capacity( array $row )
        {
            $capacity = null === $row['employeeCapacity'] ? (int) $row['maxCapacity'] : (int) $row['employeeCapacity'];

            return max( 1, $capacity, (int) $row['takingPart'] );
        }

        public function series_bookings( array $booking )
        {
            global $wpdb;

            if ( ! preg_match( '#^series_(\d+)_(\d+)$#', $booking['series'], $match ) ) {
                return array( (string) $booking['id'] );
            }

            $rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT b.id, " . self::slot_columns() . "
                FROM {$wpdb->prefix}amelia_customer_bookings b
                INNER JOIN {$wpdb->prefix}amelia_appointments a ON a.id = b.appointmentId
                LEFT JOIN {$wpdb->prefix}amelia_services s ON s.id = a.serviceId
                WHERE ( a.id = %d OR a.parentId = %d ) AND b.customerId = %d
                ORDER BY a.bookingStart ASC, b.id ASC",
                (int) $match[1],
                (int) $match[1],
                (int) $match[2]
            ), ARRAY_A );

            $ids = array();

            foreach ( (array) $rows as $row ) {
                if ( 1 === self::capacity( $row ) ) {
                    $ids[] = (string) $row['id'];
                }
            }

            return $ids;
        }

        private function event_fields( $booking_id )
        {
            global $wpdb;

            $event_id = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT p.eventId FROM {$wpdb->prefix}amelia_customer_bookings_to_events_periods t INNER JOIN {$wpdb->prefix}amelia_events_periods p ON p.id = t.eventPeriodId WHERE t.customerBookingId = %d LIMIT 1",
                $booking_id
            ) );

            if ( $event_id < 1 ) {
                return null;
            }

            $event = $wpdb->get_row( $wpdb->prepare(
                "SELECT e.name, e.status, e.maxCapacity, e.customLocation, e.organizerId, l.name AS location,
                    MIN(p.periodStart) AS periodStart, MAX(p.periodEnd) AS periodEnd,
                    MAX(COALESCE(p.googleMeetUrl, '')) AS googleMeetUrl, MAX(COALESCE(p.microsoftTeamsUrl, '')) AS microsoftTeamsUrl,
                    MAX(COALESCE(p.zoomMeeting, '')) AS zoomMeeting, MAX(COALESCE(p.lessonSpace, '')) AS lessonSpace
                FROM {$wpdb->prefix}amelia_events e
                LEFT JOIN {$wpdb->prefix}amelia_locations l ON l.id = e.locationId
                LEFT JOIN {$wpdb->prefix}amelia_events_periods p ON p.eventId = e.id
                WHERE e.id = %d
                GROUP BY e.id, e.name, e.status, e.maxCapacity, e.customLocation, e.organizerId, l.name",
                $event_id
            ), ARRAY_A );

            if ( ! is_array( $event ) ) {
                return null;
            }

            return array(
                'held_status' => (string) $event['status'],
                'start'       => self::utc( $event['periodStart'] ),
                'end'         => self::utc( $event['periodEnd'] ),
                'service'     => (string) $event['name'],
                'place'       => '' !== (string) $event['location'] ? (string) $event['location'] : (string) $event['customLocation'],
                'online'      => '' !== (string) $event['googleMeetUrl'] || '' !== (string) $event['microsoftTeamsUrl'] || '' !== (string) $event['zoomMeeting'] || '' !== (string) $event['lessonSpace'],
                'staff'       => $this->event_staff( $event_id, (int) $event['organizerId'] ),
                'capacity'    => max( 1, (int) $event['maxCapacity'] ),
                'group'       => 'event_' . $event_id,
            );
        }

        private function event_staff( $event_id, $organizer_id )
        {
            global $wpdb;

            $rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT u.id, u.externalId, u.firstName, u.lastName, u.email
                FROM {$wpdb->prefix}amelia_users u
                WHERE u.id = %d OR u.id IN ( SELECT userId FROM {$wpdb->prefix}amelia_events_to_providers WHERE eventId = %d )
                ORDER BY u.id = %d DESC, u.id ASC",
                $organizer_id,
                $event_id,
                $organizer_id
            ), ARRAY_A );

            $staff = array();

            foreach ( (array) $rows as $row ) {
                $staff[] = self::person( (int) $row['externalId'], $row['firstName'] . ' ' . $row['lastName'], $row['email'] );
            }

            return $staff;
        }

        private function first_booking_of_appointment( $appointment_id )
        {
            global $wpdb;

            return (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}amelia_customer_bookings WHERE appointmentId = %d
                ORDER BY COALESCE(status, '') IN ( 'canceled', 'rejected', 'waiting' ) ASC, id ASC LIMIT 1",
                $appointment_id
            ) );
        }

        public function group_bookings( array $booking )
        {
            global $wpdb;

            if ( 0 === strpos( $booking['group'], 'slot_' ) ) {
                return array_map( 'strval', (array) $wpdb->get_col( $wpdb->prepare(
                    "SELECT id FROM {$wpdb->prefix}amelia_customer_bookings WHERE appointmentId = %d ORDER BY id ASC",
                    (int) substr( $booking['group'], 5 )
                ) ) );
            }

            if ( 0 === strpos( $booking['group'], 'event_' ) ) {
                return array_map( 'strval', (array) $wpdb->get_col( $wpdb->prepare(
                    "SELECT DISTINCT t.customerBookingId FROM {$wpdb->prefix}amelia_customer_bookings_to_events_periods t
                    INNER JOIN {$wpdb->prefix}amelia_events_periods p ON p.id = t.eventPeriodId
                    WHERE p.eventId = %d ORDER BY t.customerBookingId ASC",
                    (int) substr( $booking['group'], 6 )
                ) ) );
            }

            return array( (string) $booking['id'] );
        }

        private function first_booking_of_event( $event_id )
        {
            global $wpdb;

            return (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT t.customerBookingId FROM {$wpdb->prefix}amelia_customer_bookings_to_events_periods t
                INNER JOIN {$wpdb->prefix}amelia_events_periods p ON p.id = t.eventPeriodId
                WHERE p.eventId = %d ORDER BY t.customerBookingId ASC LIMIT 1",
                $event_id
            ) );
        }
    }
}
