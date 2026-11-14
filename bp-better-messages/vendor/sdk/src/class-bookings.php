<?php

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WordPlus_SDK_Bookings' ) ) {
    final class WordPlus_SDK_Bookings
    {
        const PENDING   = 'pending';
        const APPROVED  = 'approved';
        const CANCELLED = 'cancelled';
        const REJECTED  = 'rejected';
        const COMPLETED = 'completed';
        const NO_SHOW   = 'no_show';

        const WAITING = 'waiting';

        const CLOSED = array( self::CANCELLED, self::REJECTED );

        const ACTION_MADE     = 'wordplus_booking_made';
        const ACTION_CHANGED  = 'wordplus_booking_changed';
        const ACTION_DELETED  = 'wordplus_booking_deleted';
        const ACTION_PAGE     = 'wordplus_booking_page';
        const ACTION_REPLACED = 'wordplus_booking_replaced';

        const SIGNATURE_LENGTH = 32;

        const ADAPTERS = array(
            'amelia'        => array(
                'name'     => 'Amelia',
                'constant' => 'AMELIA_VERSION',
                'file'     => 'class-booking-amelia.php',
                'class'    => 'WordPlus_SDK_Booking_Amelia',
            ),
            'latepoint'     => array(
                'name'     => 'LatePoint',
                'constant' => 'LATEPOINT_VERSION',
                'file'     => 'class-booking-latepoint.php',
                'class'    => 'WordPlus_SDK_Booking_LatePoint',
            ),
            'fluentbooking' => array(
                'name'     => 'FluentBooking',
                'constant' => 'FLUENT_BOOKING_VERSION',
                'file'     => 'class-booking-fluent.php',
                'class'    => 'WordPlus_SDK_Booking_Fluent',
            ),
            'ssa'           => array(
                'name'     => 'Simply Schedule Appointments',
                'constant' => 'SSA_PLUGIN_BASENAME',
                'file'     => 'class-booking-ssa.php',
                'class'    => 'WordPlus_SDK_Booking_SSA',
            ),
        );

        private $sdk;

        private $adapters = array();

        private $links = array();

        private $meetings = array();

        private $visitors_store = null;

        public function __construct( WordPlus_SDK $sdk )
        {
            $this->sdk = $sdk;
        }

        public function available()
        {
            $names = array();

            foreach ( self::ADAPTERS as $slug => $adapter ) {
                if ( defined( $adapter['constant'] ) ) {
                    $names[ $slug ] = $adapter['name'];
                }
            }

            return $names;
        }

        public function start( array $slugs )
        {
            foreach ( $slugs as $slug ) {
                $this->adapter( (string) $slug );
            }
        }

        public function adapter( $slug )
        {
            if ( isset( $this->adapters[ $slug ] ) ) {
                return $this->adapters[ $slug ];
            }

            if ( ! isset( self::ADAPTERS[ $slug ] ) || ! defined( self::ADAPTERS[ $slug ]['constant'] ) ) {
                return null;
            }

            require_once __DIR__ . '/bookings/class-booking-adapter.php';
            require_once __DIR__ . '/bookings/' . self::ADAPTERS[ $slug ]['file'];

            $class                   = self::ADAPTERS[ $slug ]['class'];
            $this->adapters[ $slug ] = new $class( $this );

            $this->adapters[ $slug ]->hook();

            foreach ( array_keys( $this->links ) as $key ) {
                $this->adapters[ $slug ]->link_registered( $key );
            }

            foreach ( array_keys( $this->meetings ) as $key ) {
                $this->adapters[ $slug ]->meeting_registered( $key );
            }

            return $this->adapters[ $slug ];
        }

        public function use_adapter( $slug, WordPlus_SDK_Booking_Adapter $adapter )
        {
            $this->adapters[ (string) $slug ] = $adapter;
        }

        public function get( $plugin, $id )
        {
            $adapter = $this->adapter( (string) $plugin );

            return null === $adapter ? null : $adapter->get( (string) $id );
        }

        public static function shape( array $fields )
        {
            return array(
                'plugin'     => isset( $fields['plugin'] ) ? (string) $fields['plugin'] : '',
                'id'         => isset( $fields['id'] ) ? (string) $fields['id'] : '',
                'status'     => isset( $fields['status'] ) ? (string) $fields['status'] : self::PENDING,
                'start'      => isset( $fields['start'] ) ? (int) $fields['start'] : 0,
                'end'        => isset( $fields['end'] ) ? (int) $fields['end'] : 0,
                'service'    => isset( $fields['service'] ) ? (string) $fields['service'] : '',
                'place'      => isset( $fields['place'] ) ? (string) $fields['place'] : '',
                'online'     => ! empty( $fields['online'] ),
                'staff'      => self::people( isset( $fields['staff'] ) ? $fields['staff'] : array() ),
                'customers'  => self::people( isset( $fields['customers'] ) ? $fields['customers'] : array() ),
                'capacity'   => isset( $fields['capacity'] ) ? max( 1, (int) $fields['capacity'] ) : 1,
                'group'      => isset( $fields['group'] ) ? (string) $fields['group'] : '',
                'series'     => isset( $fields['series'] ) ? (string) $fields['series'] : '',
                'seats'      => isset( $fields['seats'] ) ? max( 1, (int) $fields['seats'] ) : 1,
                'manage_url' => isset( $fields['manage_url'] ) ? (string) $fields['manage_url'] : '',
            );
        }

        public function series_of( array $booking )
        {
            $adapter = '' === $booking['series'] ? null : $this->adapter( $booking['plugin'] );

            if ( null === $adapter ) {
                return array( $booking );
            }

            $dates = array();

            foreach ( $adapter->series_bookings( $booking ) as $id ) {
                $date = (string) $id === $booking['id'] ? $booking : $adapter->get( (string) $id );

                if ( null !== $date ) {
                    $dates[] = $date;
                }
            }

            if ( empty( $dates ) ) {
                return array( $booking );
            }

            usort( $dates, function ( $a, $b ) {
                return $a['start'] - $b['start'];
            } );

            return $dates;
        }

        public function current_of_series( array $booking, $now = 0 )
        {
            $now   = $now > 0 ? (int) $now : time();
            $dates = $this->series_of( $booking );

            foreach ( $dates as $date ) {
                if ( ! self::is_closed( $date ) && $date['end'] >= $now ) {
                    return $date;
                }
            }

            return $dates[ count( $dates ) - 1 ];
        }

        private static function people( $people )
        {
            $kept = array();

            foreach ( (array) $people as $person ) {
                $kept[] = array(
                    'user_id' => isset( $person['user_id'] ) ? (int) $person['user_id'] : 0,
                    'name'    => isset( $person['name'] ) ? trim( (string) $person['name'] ) : '',
                    'email'   => isset( $person['email'] ) ? trim( (string) $person['email'] ) : '',
                );
            }

            return $kept;
        }

        public static function changes( array $kept, array $booking )
        {
            $start = (int) ( isset( $kept['start'] ) ? $kept['start'] : 0 );
            $end   = (int) ( isset( $kept['end'] ) ? $kept['end'] : 0 );

            return array(
                'status' => (string) ( isset( $kept['status'] ) ? $kept['status'] : '' ) !== (string) $booking['status'],
                'moved'  => $start !== (int) $booking['start']
                    || ( $end !== (int) $booking['end'] && $end > $start && (int) $booking['end'] > (int) $booking['start'] ),
            );
        }

        public static function is_closed( array $booking )
        {
            return in_array( $booking['status'], self::CLOSED, true );
        }

        public static function call_window( array $booking, $minutes_before )
        {
            return array(
                'open'  => (int) $booking['start'] - 60 * max( 0, (int) $minutes_before ),
                'close' => (int) $booking['end'],
            );
        }

        public function made( $plugin, $id )
        {
            $booking = $this->get( $plugin, $id );

            if ( null !== $booking ) {
                do_action( self::ACTION_MADE, $booking );
                do_action( self::ACTION_CHANGED, $booking );
            }
        }

        public function changed( $plugin, $id )
        {
            $booking = $this->get( $plugin, $id );

            if ( null !== $booking ) {
                do_action( self::ACTION_CHANGED, $booking );
            }
        }

        public function deleted( $plugin, $id )
        {
            do_action( self::ACTION_DELETED, (string) $plugin, (string) $id );
        }

        public function replaced( $plugin, $old_id, $new_id )
        {
            $booking = $this->get( $plugin, $new_id );

            if ( null !== $booking ) {
                do_action( self::ACTION_REPLACED, (string) $plugin, (string) $old_id, $booking );
                do_action( self::ACTION_CHANGED, $booking );
            }
        }

        public function living( $plugin, $id )
        {
            $adapter = $this->adapter( $plugin );
            $id      = (string) $id;
            $seen    = array( $id => true );

            while ( null !== $adapter && count( $seen ) <= 20 ) {
                $next = (string) $adapter->replaced_by( $id );

                if ( '' === $next || isset( $seen[ $next ] ) ) {
                    break;
                }

                $seen[ $next ] = true;
                $id            = $next;
            }

            return $this->get( $plugin, $id );
        }

        public function page( $plugin, $id, $recipient = 'customer', $classes = '' )
        {
            $booking = $this->get( $plugin, $id );

            if ( null !== $booking ) {
                do_action( self::ACTION_PAGE, $booking, 'staff' === $recipient ? 'staff' : 'customer', (string) $classes );
            }
        }

        public function register_link( $key, array $link )
        {
            $key = preg_replace( '/[^a-z0-9_]/', '', strtolower( (string) $key ) );

            if ( '' === $key || ! isset( $link['url'] ) || ! is_callable( $link['url'] ) ) {
                return;
            }

            $this->links[ $key ] = array(
                'label' => isset( $link['label'] ) ? (string) $link['label'] : '',
                'url'   => $link['url'],
            );

            foreach ( $this->adapters as $adapter ) {
                $adapter->link_registered( $key );
            }
        }

        public function links()
        {
            return $this->links;
        }

        public function link_url( $key, array $booking, $recipient = 'customer' )
        {
            if ( ! isset( $this->links[ $key ] ) ) {
                return '';
            }

            return (string) call_user_func( $this->links[ $key ]['url'], $booking, 'staff' === $recipient ? 'staff' : 'customer' );
        }

        public function register_meeting( $key, array $meeting )
        {
            $key = preg_replace( '/[^a-z0-9_]/', '', strtolower( (string) $key ) );

            if ( '' === $key || ! isset( $meeting['url'] ) || ! is_callable( $meeting['url'] ) ) {
                return;
            }

            $this->meetings[ $key ] = array(
                'label'    => isset( $meeting['label'] ) ? (string) $meeting['label'] : $key,
                'url'      => $meeting['url'],
                'personal' => isset( $meeting['personal'] ) && is_callable( $meeting['personal'] ) ? $meeting['personal'] : null,
            );

            foreach ( $this->adapters as $adapter ) {
                $adapter->meeting_registered( $key );
            }
        }

        public function meetings()
        {
            return $this->meetings;
        }

        public function meeting_url( $key, array $booking )
        {
            return isset( $this->meetings[ $key ] ) ? (string) call_user_func( $this->meetings[ $key ]['url'], $booking ) : '';
        }

        public function personal_meeting_url( $key, array $booking, $recipient = 'customer' )
        {
            if ( empty( $this->meetings[ $key ]['personal'] ) ) {
                return $this->meeting_url( $key, $booking );
            }

            $url = (string) call_user_func( $this->meetings[ $key ]['personal'], $booking, 'staff' === $recipient ? 'staff' : 'customer' );

            return '' !== $url ? $url : $this->meeting_url( $key, $booking );
        }

        public static function swap_address( $text, $from, $to )
        {
            $text = (string) $text;
            $from = (string) $from;
            $to   = (string) $to;

            if ( '' === $from || '' === $to || $from === $to || '' === $text ) {
                return $text;
            }

            $forms   = array();
            $escaped = array(
                array( str_replace( '&', '&amp;', $from ), str_replace( '&', '&amp;', $to ) ),
                array( str_replace( '&', '&#038;', $from ), str_replace( '&', '&#038;', $to ) ),
            );
            $plain   = array_merge( array( array( $from, $to ) ), $escaped );
            $written = $plain;

            foreach ( $plain as $form ) {
                $written[] = array( rawurlencode( $form[0] ), rawurlencode( $form[1] ) );
                $written[] = array( urlencode( $form[0] ), urlencode( $form[1] ) );
            }

            foreach ( $written as $form ) {
                if ( ! isset( $forms[ $form[0] ] ) ) {
                    $forms[ $form[0] ] = $form[1];
                }
            }

            $keys = array_map( 'strval', array_keys( $forms ) );
            usort( $keys, function ( $a, $b ) {
                return strlen( $b ) - strlen( $a );
            } );

            $pattern = '/(?:' . implode( '|', array_map( function ( $form ) {
                return preg_quote( $form, '/' );
            }, $keys ) ) . ')(?![0-9A-Za-z._~-])/';

            return (string) preg_replace_callback( $pattern, function ( $found ) use ( $forms ) {
                return $forms[ $found[0] ];
            }, $text );
        }

        public function placeholders( $key, array $slugs )
        {
            $placeholders = array();

            foreach ( $slugs as $slug ) {
                $adapter = $this->adapter( (string) $slug );

                if ( null !== $adapter ) {
                    $placeholders[ $slug ] = array(
                        'name'        => self::ADAPTERS[ $slug ]['name'],
                        'placeholder' => $adapter->placeholder( $key ),
                    );
                }
            }

            return $placeholders;
        }

        public function reference( $plugin, $id, $visitor_id = 0, $secret = '' )
        {
            $reference = $plugin . '.' . $id;

            return 0 === (int) $visitor_id ? $reference : $reference . '.' . self::signature( $plugin, $id, $visitor_id, $secret );
        }

        public static function parse_reference( $value )
        {
            if ( ! is_string( $value ) || ! preg_match( '/^([a-z0-9_-]{1,40})\.([A-Za-z0-9_-]{1,64})(?:\.([a-f0-9]{' . self::SIGNATURE_LENGTH . '}))?$/D', $value, $match ) ) {
                return null;
            }

            return array(
                'plugin'    => $match[1],
                'id'        => $match[2],
                'signature' => isset( $match[3] ) ? $match[3] : '',
            );
        }

        public static function signature_valid( array $reference, $visitor_id, $secret )
        {
            if ( empty( $reference['signature'] ) || '' === (string) $secret ) {
                return false;
            }

            return hash_equals( self::signature( $reference['plugin'], $reference['id'], $visitor_id, $secret ), (string) $reference['signature'] );
        }

        private static function signature( $plugin, $id, $visitor_id, $secret )
        {
            $signed = 'booking|' . $plugin . '|' . $id . '|' . abs( (int) $visitor_id );

            return substr( hash_hmac( 'sha256', $signed, wp_salt( 'auth' ) . (string) $secret ), 0, self::SIGNATURE_LENGTH );
        }

        public function visitors_store()
        {
            if ( null === $this->visitors_store ) {
                require_once __DIR__ . '/bookings/class-booking-visitors-table.php';
                $this->visitors_store = new WordPlus_SDK_Booking_Visitors_Table();
            }

            return $this->visitors_store;
        }

        public function use_visitors_store( $store )
        {
            $this->visitors_store = $store;
        }

        public function visitor_for_email( $email )
        {
            $hash = self::email_hash( $email );

            if ( '' === $hash ) {
                return new WP_Error( 'booking_visitor_email', 'A booking visitor needs an email.', array( 'status' => 400 ) );
            }

            $store    = $this->visitors_store();
            $visitors = $this->sdk->visitors();
            $row      = $store->find( $hash );

            if ( null !== $row ) {
                $token = (int) $row['number'] . '.' . (string) $row['secret'];

                if ( null !== $visitors->check( $token ) ) {
                    return array( 'id' => -1 * (int) $row['number'], 'token' => $token );
                }

                $store->forget( $hash );
            }

            $made = $visitors->create();

            if ( is_wp_error( $made ) ) {
                return $made;
            }

            $parts = WordPlus_SDK_Visitors::parse( (string) $made['token'] );

            if ( null === $parts ) {
                return new WP_Error( 'booking_visitor_kept', 'The visitor could not be kept.', array( 'status' => 500 ) );
            }

            $store->insert( $hash, $parts['number'], $parts['secret'] );

            $row = $store->find( $hash );

            if ( null === $row ) {
                return new WP_Error( 'booking_visitor_kept', 'The visitor could not be kept.', array( 'status' => 500 ) );
            }

            return array(
                'id'    => -1 * (int) $row['number'],
                'token' => (int) $row['number'] . '.' . (string) $row['secret'],
            );
        }

        public function forget_visitor_of_email( $email )
        {
            $hash = self::email_hash( $email );

            if ( '' !== $hash ) {
                $this->visitors_store()->forget( $hash );
            }
        }

        public static function email_hash( $email )
        {
            $email = strtolower( trim( (string) $email ) );

            return '' === $email ? '' : hash( 'sha256', $email );
        }
    }
}
