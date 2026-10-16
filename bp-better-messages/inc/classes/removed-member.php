<?php
defined( 'ABSPATH' ) || exit;

if( ! class_exists( 'Better_Messages_Removed_Member' ) ):
    class Better_Messages_Removed_Member {
        private $name;

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
