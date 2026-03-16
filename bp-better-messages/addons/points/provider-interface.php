<?php

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Better_Messages_Points_Provider' ) ) {
    abstract class Better_Messages_Points_Provider
    {
        /**
         * @return string Provider ID ('mycred', 'gamipress')
         */
        abstract public function get_provider_id();

        /**
         * @return string Human-readable provider name
         */
        abstract public function get_provider_name();

        /**
         * @return bool Whether the underlying plugin is installed and active
         */
        abstract public function is_available();

        /**
         * @return string Settings key prefix for this provider (e.g. 'myCred', 'GamiPress')
         */
        abstract public function get_settings_prefix();

        /**
         * @return string Meta key prefix for tracking charged minutes
         */
        abstract public function get_meta_prefix();

        /**
         * @param int $user_id
         * @return float|int User's current points balance
         */
        abstract public function get_user_balance( $user_id );

        /**
         * Deduct points from a user.
         *
         * @param int $user_id
         * @param float|int $amount Positive amount to deduct
         * @param string $reference Unique reference key for this transaction
         * @param string $log_entry Log/reason text
         */
        abstract public function deduct_points( $user_id, $amount, $reference, $log_entry );

        /**
         * Get the charge rate for a user based on their roles and the given setting key.
         *
         * @param int $user_id
         * @param string $setting_key Full settings key (e.g. 'myCredNewMessageCharge')
         * @return int
         */
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

        /**
         * Get the full settings key for a given type.
         *
         * @param string $type One of 'NewMessageCharge', 'NewThreadCharge', 'CallPricing',
         *                     'NewMessageChargeMessage', 'NewThreadChargeMessage',
         *                     'CallPricingStartMessage', 'CallPricingEndMessage'
         * @return string
         */
        public function setting_key( $type ) {
            return $this->get_settings_prefix() . $type;
        }
    }
}
