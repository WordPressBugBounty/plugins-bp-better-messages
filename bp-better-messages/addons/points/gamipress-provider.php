<?php

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Better_Messages_Points_GamiPress' ) ) {
    class Better_Messages_Points_GamiPress extends Better_Messages_Points_Provider
    {
        public function get_provider_id() {
            return 'gamipress';
        }

        public function get_provider_name() {
            return 'GamiPress';
        }

        public function is_available() {
            return class_exists( 'GamiPress' ) && function_exists( 'gamipress_get_user_points' );
        }

        public function get_settings_prefix() {
            return 'GamiPress';
        }

        public function get_meta_prefix() {
            return 'gamipress';
        }

        public function get_user_balance( $user_id ) {
            $point_type = Better_Messages()->settings['GamiPressPointType'];
            return gamipress_get_user_points( $user_id, $point_type );
        }

        public function deduct_points( $user_id, $amount, $reference, $log_entry ) {
            $point_type = Better_Messages()->settings['GamiPressPointType'];
            gamipress_deduct_points_to_user( $user_id, $amount, $point_type, [
                'reason' => $log_entry
            ] );
        }
    }
}
