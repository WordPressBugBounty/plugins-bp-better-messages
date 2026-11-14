<?php

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WordPlus_SDK_Booking_Adapter' ) ) {
    abstract class WordPlus_SDK_Booking_Adapter
    {
        protected $bookings;

        public function __construct( WordPlus_SDK_Bookings $bookings )
        {
            $this->bookings = $bookings;
        }

        abstract public function slug();

        abstract public function hook();

        abstract public function get( $id );

        abstract public function placeholder( $key );

        public function group_bookings( array $booking )
        {
            return array( (string) $booking['id'] );
        }

        public function series_bookings( array $booking )
        {
            return array( (string) $booking['id'] );
        }

        public function link_registered( $key )
        {
        }

        public function meeting_registered( $key )
        {
        }

        protected function replaced( $old_id, $new_id )
        {
            $this->bookings->replaced( $this->slug(), $old_id, $new_id );
        }

        public function replaced_by( $id )
        {
            return '';
        }

        protected function made( $id )
        {
            $this->bookings->made( $this->slug(), $id );
        }

        protected function changed( $id )
        {
            $this->bookings->changed( $this->slug(), $id );
        }

        protected function deleted( $id )
        {
            $this->bookings->deleted( $this->slug(), $id );
        }

        protected function page( $id, $recipient = 'customer', $classes = '' )
        {
            $this->bookings->page( $this->slug(), $id, $recipient, $classes );
        }

        protected function page_html( $id, $recipient = 'customer', $classes = '' )
        {
            ob_start();
            $this->page( $id, $recipient, $classes );

            return trim( (string) ob_get_clean() );
        }

        protected static function utc( $datetime )
        {
            $datetime = trim( (string) $datetime );
            $time     = '' === $datetime ? false : strtotime( $datetime . ' UTC' );

            return false === $time ? 0 : (int) $time;
        }

        protected static function person( $user_id, $name, $email )
        {
            return array(
                'user_id' => (int) $user_id,
                'name'    => trim( (string) $name ),
                'email'   => trim( (string) $email ),
            );
        }
    }
}
