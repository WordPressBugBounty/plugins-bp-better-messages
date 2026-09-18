<?php
defined( 'ABSPATH' ) || exit;

if( ! class_exists( 'Better_Messages_Removed_Member' ) ):
    /**
     * Stands in for a member of Better_Messages() that no longer exists, so a
     * snippet written against an older release degrades to a notice instead of
     * taking the site down with a fatal.
     */
    class Better_Messages_Removed_Member {
        /**
         * How the member was reached, for the notice.
         *
         * @var string
         */
        private $name;

        /**
         * What replaced it, for the notice.
         *
         * @var string
         */
        private $advice;

        public function __construct( $name, $advice = '' ){
            $this->name   = $name;
            $this->advice = $advice;
        }

        private function warn( $member ){
            if( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) return;

            $message = $this->name . '->' . $member . ' was removed in Better Messages ' . Better_Messages()->version . '.';

            if( $this->advice !== '' ){
                $message .= ' ' . $this->advice;
            }

            trigger_error( esc_html( $message ), E_USER_NOTICE );
        }

        public function __call( $method, $arguments ){
            $this->warn( $method );

            return '';
        }

        public function __get( $property ){
            $this->warn( $property );

            return null;
        }

        public function __set( $property, $value ){
            $this->warn( $property );
        }

        public function __isset( $property ){
            return false;
        }
    }
endif;
