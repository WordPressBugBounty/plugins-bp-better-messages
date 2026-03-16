<?php

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Better_Messages_Points_MyCred' ) ) {
    class Better_Messages_Points_MyCred extends Better_Messages_Points_Provider
    {
        public function get_provider_id() {
            return 'mycred';
        }

        public function get_provider_name() {
            return 'MyCRED';
        }

        public function is_available() {
            return class_exists( 'myCRED_Core' ) && function_exists( 'mycred' );
        }

        public function get_settings_prefix() {
            return 'myCred';
        }

        public function get_meta_prefix() {
            return 'mycred';
        }

        private function get_point_type() {
            $type = Better_Messages()->settings['myCredPointType'];
            if ( empty( $type ) ) {
                $type = MYCRED_DEFAULT_TYPE_KEY;
            }
            return $type;
        }

        public function get_user_balance( $user_id ) {
            $type = $this->get_point_type();
            return mycred( $type )->get_users_balance( $user_id, $type );
        }

        public function deduct_points( $user_id, $amount, $reference, $log_entry ) {
            $type = $this->get_point_type();
            mycred( $type )->add_creds( $reference, $user_id, 0 - $amount, $log_entry, '', '', $type );
        }
    }
}
