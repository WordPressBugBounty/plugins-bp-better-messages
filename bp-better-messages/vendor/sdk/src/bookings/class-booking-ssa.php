<?php

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WordPlus_SDK_Booking_SSA' ) ) {
    final class WordPlus_SDK_Booking_SSA extends WordPlus_SDK_Booking_Adapter
    {
        const SLUG = 'ssa';

        const FIELDS = array( 'status', 'start_date', 'end_date', 'appointment_type_id', 'customer_id', 'customer_information', 'web_meeting_url', 'staff_ids' );

        const CLOSED = array( 'canceled', 'abandoned' );

        public function slug()
        {
            return self::SLUG;
        }

        public function hook()
        {
            add_action( 'ssa/appointment/booked', array( $this, 'appointment_made' ) );

            foreach ( array( 'pending', 'rescheduled', 'edited', 'canceled', 'no_show', 'no_show_reverted' ) as $hook ) {
                add_action( 'ssa/appointment/' . $hook, array( $this, 'appointment_changed' ) );
            }

            add_action( 'ssa/appointment/after_update', array( $this, 'appointment_updated' ), 1001, 3 );

            add_action( 'ssa/appointment/after_delete', array( $this, 'appointment_deleted' ) );

            add_filter( 'ssa/templates/get_template_vars', array( $this, 'template_vars' ), 10, 2 );
            add_filter( 'ssa/notifications/email/args', array( $this, 'staff_mail' ), 10, 5 );
            add_filter( 'ssa/notifications/sms/args', array( $this, 'sms' ) );

            add_filter( 'do_shortcode_tag', array( $this, 'manage_page' ), 10, 3 );
        }

        public function placeholder( $key )
        {
            return '{{ ' . $key . '_url }}';
        }

        public function appointment_made( $id )
        {
            $moves = self::moves( (int) $id );
            $from  = null === $moves ? 0 : (int) $moves['from'];

            if ( $from > 0 && $from !== (int) $id && null !== self::moves( $from ) ) {
                $this->replaced( (string) $from, (string) $id );
                return;
            }

            $this->made( (string) $id );
        }

        public function appointment_changed( $id )
        {
            if ( ! self::moved_away( (int) $id ) ) {
                $this->changed( (string) $id );
            }
        }

        public function appointment_updated( $id, $after = array(), $before = array() )
        {
            foreach ( self::FIELDS as $field ) {
                if ( isset( $after[ $field ] ) && ( ! isset( $before[ $field ] ) || $after[ $field ] != $before[ $field ] ) ) {
                    $this->appointment_changed( $id );
                    return;
                }
            }
        }

        private static function moves( $id )
        {
            global $wpdb;

            $row = $wpdb->get_row( $wpdb->prepare(
                "SELECT rescheduled_from_appointment_id AS `from`, rescheduled_to_appointment_id AS `to`, status FROM {$wpdb->prefix}ssa_appointments WHERE id = %d",
                (int) $id
            ), ARRAY_A );

            return is_array( $row ) ? array( 'from' => (int) $row['from'], 'to' => (int) $row['to'], 'status' => (string) $row['status'] ) : null;
        }

        private static function moved_away( $id )
        {
            $moves = self::moves( $id );

            return null !== $moves && $moves['to'] > 0 && $moves['to'] !== (int) $id && in_array( $moves['status'], self::CLOSED, true );
        }

        public function replaced_by( $id )
        {
            return self::moved_away( (int) $id ) ? (string) self::moves( (int) $id )['to'] : '';
        }

        public function appointment_deleted( $id )
        {
            if ( ! empty( $id ) ) {
                $this->deleted( (string) $id );
            }
        }

        public function template_vars( $vars, $template = null )
        {
            if ( 'notification' !== $template || ! is_array( $vars ) || empty( $vars['appointment_id'] ) ) {
                return $vars;
            }

            $links   = $this->bookings->links();
            $booking = empty( $links ) ? null : $this->get( (string) $vars['appointment_id'] );

            if ( null === $booking ) {
                return $vars;
            }

            foreach ( array_keys( $links ) as $key ) {
                $vars[ $key . '_url' ] = $this->bookings->link_url( $key, $booking, 'customer' );
            }

            return $vars;
        }

        public function staff_mail( $args, $notification = array(), $vars = array(), $appointment = null, $recipient_type = 'customer' )
        {
            if ( 'staff' !== $recipient_type || ! is_array( $args ) || ! is_array( $vars ) ) {
                return $args;
            }

            $swaps = $this->staff_links( $vars );

            if ( empty( $swaps ) ) {
                return $args;
            }

            $in_html = array();

            foreach ( $swaps as $printed => $staff ) {
                foreach ( array( $printed, str_replace( '&', '&amp;', $printed ), str_replace( '&', '&#038;', $printed ) ) as $written ) {
                    $in_html[ $written ] = str_replace( '&', '&amp;', $staff );
                }
            }

            if ( isset( $args['subject'] ) ) {
                $args['subject'] = strtr( (string) $args['subject'], $swaps );
            }

            if ( isset( $args['message'] ) ) {
                $args['message'] = strtr( (string) $args['message'], $in_html );
            }

            return $args;
        }

        public function sms( $args )
        {
            if ( ! is_array( $args ) || empty( $args['notification_vars'] ) || ! is_array( $args['notification_vars'] ) ) {
                return $args;
            }

            $vars     = $args['notification_vars'];
            $to       = isset( $args['to_number'] ) && is_scalar( $args['to_number'] ) ? self::digits( $args['to_number'] ) : '';
            $customer = isset( $vars['customer_phone'] ) && is_scalar( $vars['customer_phone'] ) ? self::digits( $vars['customer_phone'] ) : '';

            if ( '' !== $customer && $to === $customer ) {
                return $args;
            }

            $swaps = $this->staff_links( $vars );

            if ( empty( $swaps ) ) {
                return $args;
            }

            $written = array();

            foreach ( $swaps as $printed => $staff ) {
                foreach ( array( '&', '&amp;', '&#038;' ) as $amp ) {
                    $written[ str_replace( '&', $amp, $printed ) ] = str_replace( '&', $amp, $staff );
                }
            }

            foreach ( array( 'subject', 'message' ) as $field ) {
                if ( isset( $args[ $field ] ) && is_string( $args[ $field ] ) ) {
                    $args[ $field ] = strtr( $args[ $field ], $written );
                }
            }

            return $args;
        }

        private static function digits( $number )
        {
            return (string) preg_replace( '/\D+/', '', (string) $number );
        }

        private function staff_links( array $vars )
        {
            $links   = empty( $vars['appointment_id'] ) ? array() : $this->bookings->links();
            $booking = empty( $links ) ? null : $this->get( (string) $vars['appointment_id'] );

            if ( null === $booking ) {
                return array();
            }

            $swaps = array();

            foreach ( array_keys( $links ) as $key ) {
                $printed = isset( $vars[ $key . '_url' ] ) && is_scalar( $vars[ $key . '_url' ] ) ? (string) $vars[ $key . '_url' ] : '';

                if ( '' !== $printed ) {
                    $swaps[ $printed ] = $this->bookings->link_url( $key, $booking, 'staff' );
                }
            }

            return $swaps;
        }

        public function manage_page( $output, $tag, $attr )
        {
            global $ssa_current_appointment_id;

            if ( 'ssa_booking' !== $tag || ! is_array( $attr ) || empty( $attr['edit'] ) || empty( $ssa_current_appointment_id ) ) {
                return $output;
            }

            $id = (int) $attr['edit'];

            if ( $id < 1 || $id !== (int) $ssa_current_appointment_id ) {
                return $output;
            }

            $html = $this->page_html( (string) $id, 'customer', 'wordplus-booking-button' );

            if ( '' === $html ) {
                return $output;
            }

            return $output . '<style>' . self::page_style() . '</style><div class="wordplus-booking-page">' . $html . '</div>';
        }

        private static function page_style()
        {
            $styles = function_exists( 'ssa' ) && isset( ssa()->styles_settings ) && is_object( ssa()->styles_settings ) ? (array) ssa()->styles_settings->get() : array();
            $accent = isset( $styles['accent_color'] ) && preg_match( '/^(#[0-9a-f]{3,8}|rgba?\(\s*[0-9.]+\s*,\s*[0-9.]+\s*,\s*[0-9.]+\s*(,\s*[0-9.]+\s*)?\))$/i', (string) $styles['accent_color'] ) ? (string) $styles['accent_color'] : '#2196f3';
            $ink    = empty( $styles['contrast'] ) ? '#fff' : 'rgba(0, 0, 0, .87)';
            $font   = isset( $styles['font'] ) ? trim( preg_replace( '/[^A-Za-z0-9 ]/', '', (string) $styles['font'] ) ) : '';
            $fonts  = ( '' !== $font ? '"' . $font . '", ' : '' ) . 'Roboto, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif';

            return '.wordplus-booking-page{text-align:center;margin:24px 0}'
                . '.wordplus-booking-page .wordplus-booking-button{display:inline-flex;align-items:center;height:36px;padding:0 16px;border-radius:4px;background:' . $accent . ';color:' . $ink . ';'
                . 'font-family:' . $fonts . ';font-size:14px;font-weight:500;letter-spacing:.0892857143em;text-transform:uppercase;text-decoration:none;'
                . 'box-shadow:0 3px 1px -2px rgba(0,0,0,.2),0 2px 2px 0 rgba(0,0,0,.14),0 1px 5px 0 rgba(0,0,0,.12)}'
                . '.wordplus-booking-page .wordplus-booking-button:hover{opacity:.9}';
        }

        public function get( $id )
        {
            global $wpdb;

            $row = $wpdb->get_row( $wpdb->prepare(
                "SELECT a.id, a.appointment_type_id, a.customer_id, a.customer_information, a.start_date, a.end_date, a.web_meeting_url, a.status,
                    t.title, t.capacity, t.capacity_type, t.web_meetings,
                    ( SELECT m.meta_value FROM {$wpdb->prefix}ssa_appointment_meta m WHERE m.appointment_id = a.id AND m.meta_key = 'status' ORDER BY m.id DESC LIMIT 1 ) AS marked
                FROM {$wpdb->prefix}ssa_appointments a
                LEFT JOIN {$wpdb->prefix}ssa_appointment_types t ON t.id = a.appointment_type_id
                WHERE a.id = %d",
                (int) $id
            ), ARRAY_A );

            if ( ! is_array( $row ) ) {
                return null;
            }

            $start       = self::seconds( $row['start_date'] );
            $information = json_decode( (string) $row['customer_information'], true );
            $information = is_array( $information ) ? $information : array();
            $meetings    = json_decode( (string) $row['web_meetings'], true );

            $is_group  = 'group' === $row['capacity_type'] && (int) $row['capacity'] > 1;
            $staff_ids = self::staff_ids( (int) $row['id'] );

            return WordPlus_SDK_Bookings::shape( array(
                'plugin'     => self::SLUG,
                'id'         => (string) $row['id'],
                'status'     => self::status( (string) $row['status'], (string) $row['marked'] ),
                'start'      => $start,
                'end'        => self::seconds( $row['end_date'] ),
                'service'    => (string) $row['title'],
                'place'      => '',
                'online'     => '' !== (string) $row['web_meeting_url'] || ( is_array( $meetings ) && ! empty( $meetings['provider'] ) ),
                'staff'      => self::staff_of( $staff_ids ),
                'customers'  => array( self::person( (int) $row['customer_id'], self::information( $information, 'Name' ), self::information( $information, 'Email' ) ) ),
                'capacity'   => $is_group ? (int) $row['capacity'] : 1,
                'group'      => $is_group ? self::slot( (int) $row['appointment_type_id'], $start, $staff_ids ) : '',
                'series'     => '',
                'seats'      => 1,
                'manage_url' => self::manage_url( (int) $row['id'] ),
            ) );
        }

        private static function slot( $type, $start, array $staff_ids )
        {
            return 'slot_' . (int) $type . '_' . (int) $start . ( empty( $staff_ids ) ? '' : '_' . implode( '-', $staff_ids ) );
        }

        private static function status( $status, $marked )
        {
            $map = array(
                'booked'          => WordPlus_SDK_Bookings::APPROVED,
                'pending_payment' => WordPlus_SDK_Bookings::PENDING,
                'pending_form'    => WordPlus_SDK_Bookings::PENDING,
                'canceled'        => WordPlus_SDK_Bookings::CANCELLED,
                'abandoned'       => WordPlus_SDK_Bookings::CANCELLED,
            );

            if ( 'booked' === $status && 'no_show' === $marked ) {
                return WordPlus_SDK_Bookings::NO_SHOW;
            }

            return isset( $map[ $status ] ) ? $map[ $status ] : WordPlus_SDK_Bookings::PENDING;
        }

        private static function seconds( $datetime )
        {
            return empty( $datetime ) ? 0 : self::utc( $datetime );
        }

        private static function information( array $information, $field )
        {
            foreach ( array( strtolower( $field ), $field ) as $key ) {
                if ( ! empty( $information[ $key ] ) && is_scalar( $information[ $key ] ) ) {
                    return (string) $information[ $key ];
                }
            }

            return '';
        }

        private static function manage_url( $id )
        {
            try {
                $url = ssa()->appointment_model->get_public_edit_url( $id );
            } catch ( Throwable $e ) {
                return '';
            }

            return is_string( $url ) ? $url : '';
        }

        public function group_bookings( array $booking )
        {
            global $wpdb;

            if ( ! preg_match( '/^slot_(\d+)_(\d+)(?:_(\d+(?:-\d+)*))?$/D', $booking['group'], $slot ) ) {
                return array( (string) $booking['id'] );
            }

            $staff = isset( $slot[3] ) ? $slot[3] : '';
            $ids   = $wpdb->get_col( $wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}ssa_appointments WHERE appointment_type_id = %d AND start_date = %s ORDER BY id ASC",
                (int) $slot[1],
                gmdate( 'Y-m-d H:i:s', (int) $slot[2] )
            ) );

            $kept = array( (string) $booking['id'] );

            foreach ( (array) $ids as $id ) {
                if ( implode( '-', self::staff_ids( (int) $id ) ) === $staff ) {
                    $kept[] = (string) $id;
                }
            }

            return array_values( array_unique( $kept ) );
        }

        private static function staff_ids( $id )
        {
            try {
                $model = ssa()->appointment_model;
                $ids   = is_object( $model ) && method_exists( $model, 'get_staff_ids' ) ? $model->get_staff_ids( (int) $id ) : array();
            } catch ( Throwable $e ) {
                return array();
            }

            $ids = array_filter( array_map( 'intval', is_array( $ids ) ? $ids : array() ) );
            sort( $ids );

            return array_values( array_unique( $ids ) );
        }

        private static function staff_of( array $staff_ids )
        {
            if ( empty( $staff_ids ) ) {
                return array();
            }

            try {
                $model = ssa()->staff_model;
            } catch ( Throwable $e ) {
                return array();
            }

            if ( ! is_object( $model ) || ( class_exists( 'SSA_Missing' ) && $model instanceof SSA_Missing ) || ! method_exists( $model, 'get' ) ) {
                return array();
            }

            $staff = array();

            foreach ( $staff_ids as $staff_id ) {
                try {
                    $row = $model->get( $staff_id );
                } catch ( Throwable $e ) {
                    continue;
                }

                if ( ! is_array( $row ) || empty( $row['id'] ) ) {
                    continue;
                }

                $user_id = isset( $row['user_id'] ) ? (int) $row['user_id'] : 0;
                $user    = $user_id > 0 ? get_userdata( $user_id ) : false;
                $name    = ! empty( $row['name'] ) && is_scalar( $row['name'] ) ? $row['name'] : ( $user ? $user->display_name : '' );
                $email   = ! empty( $row['email'] ) && is_scalar( $row['email'] ) ? $row['email'] : ( $user ? $user->user_email : '' );

                $staff[] = self::person( $user_id, $name, $email );
            }

            return $staff;
        }
    }
}
