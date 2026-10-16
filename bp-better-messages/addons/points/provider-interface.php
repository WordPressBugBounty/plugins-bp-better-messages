<?php

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Better_Messages_Points_Provider' ) ) {
    abstract class Better_Messages_Points_Provider
    {
        abstract public function get_provider_id();

        abstract public function get_provider_name();

        abstract public function is_available();

        abstract public function get_settings_prefix();

        abstract public function get_meta_prefix();

        abstract public function get_user_balance( $user_id );

        abstract public function deduct_points( $user_id, $amount, $reference, $log_entry );

        public function get_user_charge_rate( $user_id, $setting_key ) {
            if ( $user_id < 0 ) return 0;

            $charge_values = Better_Messages()->settings[ $setting_key ];

            $enabled_roles = [];
            foreach ( $charge_values as $role => $value ) {
                if ( $value['value'] > 0 ) {
                    $enabled_roles[ $role ] = (int) $value['value'];
                }
            }

            if ( count( $enabled_roles ) === 0 ) {
                return 0;
            }

            $user_roles = (array) Better_Messages()->functions->get_user_roles( $user_id );

            $user_charge_rate = 0;
            foreach ( $user_roles as $user_role ) {
                if ( isset( $enabled_roles[ $user_role ] ) ) {
                    $role_charge = (int) $enabled_roles[ $user_role ];
                    if ( $role_charge > $user_charge_rate ) {
                        $user_charge_rate = $role_charge;
                    }
                }
            }

            return $user_charge_rate;
        }

        public function setting_key( $type ) {
            return $this->get_settings_prefix() . $type;
        }
    }
}
