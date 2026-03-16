<?php

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Better_Messages_Points' ) ) {
    class Better_Messages_Points
    {
        /** @var Better_Messages_Points_Provider|null */
        private $provider = null;

        public static function instance()
        {
            static $instance = null;

            if ( null === $instance ) {
                $instance = new Better_Messages_Points();
            }

            return $instance;
        }

        public function __construct()
        {
            require_once __DIR__ . '/provider-interface.php';
            require_once __DIR__ . '/mycred-provider.php';
            require_once __DIR__ . '/gamipress-provider.php';

            $this->init_provider();

            if ( ! $this->provider ) {
                return;
            }

            add_filter( 'better_messages_can_send_message',       array( $this, 'check_message_balance' ), 10, 3 );
            add_action( 'better_messages_message_sent',           array( $this, 'charge_for_message' ) );
            add_action( 'bp_better_messages_new_thread_created',  array( $this, 'charge_new_thread_created' ), 10, 2 );
            add_action( 'better_messages_before_new_thread',      array( $this, 'check_new_thread_balance' ), 10, 2 );

            add_filter( 'better_messages_rest_thread_item',       array( $this, 'add_message_charge_to_thread' ), 10, 5 );

            add_filter( 'better_messages_call_create_custom_error', array( $this, 'check_call_balance' ), 10, 4 );
            add_action( 'better_messages_register_call_usage',      array( $this, 'charge_call_usage' ), 10, 3 );

            add_filter( 'better_messages_thread_self_update_extra',  array( $this, 'add_balance_to_self_update' ), 10, 3 );

            $balanceKeys = [
                'pointsBalanceHeader',
                'pointsBalanceThreadsList',
                'pointsBalanceThreadsListBottom',
                'pointsBalanceUserMenu',
                'pointsBalanceUserMenuPopup',
                'pointsBalanceReplyForm',
            ];
            foreach ( $balanceKeys as $key ) {
                if ( Better_Messages()->settings[ $key ] === '1' ) {
                    add_filter( 'better_messages_rest_response_headers', array( $this, 'add_balance_header' ), 10, 3 );
                    break;
                }
            }
        }

        private function init_provider()
        {
            $selected = Better_Messages()->settings['pointsSystem'];

            if ( $selected === 'none' ) {
                return;
            }

            $providers = [
                'mycred'    => new Better_Messages_Points_MyCred(),
                'gamipress' => new Better_Messages_Points_GamiPress(),
            ];

            if ( isset( $providers[ $selected ] ) && $providers[ $selected ]->is_available() ) {
                $this->provider = $providers[ $selected ];
            }
        }

        /**
         * Auto-detect active points provider for backward compatibility.
         * If a user had myCRED or GamiPress active before the pointsSystem setting existed,
         * detect it based on plugin availability and configured charge rates.
         */
        private function auto_detect_provider()
        {
            $mycred_provider = new Better_Messages_Points_MyCred();
            if ( $mycred_provider->is_available() && $this->has_configured_rates( $mycred_provider->get_settings_prefix() ) ) {
                return 'mycred';
            }

            $gamipress_provider = new Better_Messages_Points_GamiPress();
            if ( $gamipress_provider->is_available() && $this->has_configured_rates( $gamipress_provider->get_settings_prefix() ) ) {
                return 'gamipress';
            }

            return 'none';
        }

        private function has_configured_rates( $prefix )
        {
            $keys = [ 'NewMessageCharge', 'NewThreadCharge', 'CallPricing' ];
            foreach ( $keys as $key ) {
                $setting_key = $prefix . $key;
                $values = Better_Messages()->settings[ $setting_key ] ?? [];
                if ( is_array( $values ) ) {
                    foreach ( $values as $role_data ) {
                        if ( isset( $role_data['value'] ) && $role_data['value'] > 0 ) {
                            return true;
                        }
                    }
                }
            }
            return false;
        }

        /**
         * @return Better_Messages_Points_Provider|null
         */
        public function get_provider()
        {
            return $this->provider;
        }

        public function check_message_balance( $allowed, $user_id, $thread_id )
        {
            if ( ! $this->provider ) return $allowed;

            if ( $this->is_ai_bot_thread( $thread_id ) ) return $allowed;

            if ( ! $this->is_thread_type_charged( $thread_id, 'NewMessageChargeTypes' ) ) return $allowed;

            $charge_rate = apply_filters(
                'better_messages_points_message_charge_rate',
                $this->provider->get_user_charge_rate( $user_id, $this->provider->setting_key( 'NewMessageCharge' ) ),
                0, $thread_id, $user_id
            );
            $charge_rate = apply_filters( 'better_messages_mycred_message_charge_rate', $charge_rate, 0, $thread_id, $user_id ); // backward compat

            if ( $charge_rate === 0 ) return $allowed;

            $balance = $this->provider->get_user_balance( $user_id );

            if ( $balance < $charge_rate ) {
                $allowed = false;
                global $bp_better_messages_restrict_send_message;
                $bp_better_messages_restrict_send_message['points_restricted'] = Better_Messages()->settings[ $this->provider->setting_key( 'NewMessageChargeMessage' ) ];
            }

            return $allowed;
        }

        public function charge_for_message( $message )
        {
            if ( ! $this->provider ) return;

            if ( trim( $message->message ) === '<!-- BBPM START THREAD -->' ) return;

            if ( $this->is_ai_bot_thread( $message->thread_id ) ) return;

            if ( ! $this->is_thread_type_charged( $message->thread_id, 'NewMessageChargeTypes' ) ) return;

            $user_id = (int) $message->sender_id;

            $charge_rate = apply_filters(
                'better_messages_points_message_charge_rate',
                $this->provider->get_user_charge_rate( $user_id, $this->provider->setting_key( 'NewMessageCharge' ) ),
                $message->id, $message->thread_id, $user_id
            );
            $charge_rate = apply_filters( 'better_messages_mycred_message_charge_rate', $charge_rate, $message->id, $message->thread_id, $user_id ); // backward compat

            if ( $charge_rate === 0 ) return;

            $log_template = Better_Messages()->settings[ $this->provider->setting_key( 'LogNewMessage' ) ];
            $log_entry = str_replace( '{id}', $message->id, $log_template );

            $this->provider->deduct_points( $user_id, $charge_rate, 'better_messages_new_message_' . $message->id, $log_entry );
        }

        public function check_new_thread_balance( &$args, &$errors )
        {
            if ( ! $this->provider ) return;

            $allowed_types = Better_Messages()->settings[ $this->provider->setting_key( 'NewThreadChargeTypes' ) ] ?? [];
            if ( ! in_array( 'thread', $allowed_types, true ) ) return;

            $user_id = Better_Messages()->functions->get_current_user_id();

            if ( ! is_array( $args['recipients'] ) ) {
                $args['recipients'] = [ $args['recipients'] ];
            }

            // Skip AI bot threads — they have their own pricing
            if ( count( $args['recipients'] ) === 1 ) {
                $recipient_id = reset( $args['recipients'] );
                if ( $recipient_id < 0 && class_exists( 'Better_Messages_AI' ) && isset( Better_Messages()->ai ) ) {
                    $bot_id = Better_Messages()->ai->get_bot_id_from_user( $recipient_id );
                    if ( $bot_id ) return;
                }
            }

            $charge_rate = $this->provider->get_user_charge_rate( $user_id, $this->provider->setting_key( 'NewThreadCharge' ) );
            if ( $charge_rate === 0 ) return;

            $balance = $this->provider->get_user_balance( $user_id );

            if ( $balance < $charge_rate ) {
                $errors['points_restricted'] = Better_Messages()->settings[ $this->provider->setting_key( 'NewThreadChargeMessage' ) ];
            }
        }

        public function charge_new_thread_created( $thread_id, $bpbm_last_message_id )
        {
            if ( ! $this->provider ) return;

            if ( $this->is_ai_bot_thread( $thread_id ) ) return;

            if ( ! $this->is_thread_type_charged( $thread_id, 'NewThreadChargeTypes' ) ) return;

            $user_id = Better_Messages()->functions->get_current_user_id();

            $charge_rate = $this->provider->get_user_charge_rate( $user_id, $this->provider->setting_key( 'NewThreadCharge' ) );
            if ( $charge_rate === 0 ) return;

            $log_template = Better_Messages()->settings[ $this->provider->setting_key( 'LogNewThread' ) ];
            $log_entry = str_replace( '{id}', $thread_id, $log_template );

            $this->provider->deduct_points( $user_id, $charge_rate, 'better_messages_new_thread_' . $thread_id, $log_entry );
        }

        public function check_call_balance( $error, $thread_id, $caller_user_id, $target_user_id )
        {
            if ( ! $this->provider ) return $error;
            if ( ! empty( $error ) ) return $error;

            $charge_rate = $this->provider->get_user_charge_rate( $caller_user_id, $this->provider->setting_key( 'CallPricing' ) );
            $charge_rate = apply_filters( 'better_messages_points_call_charge_rate', $charge_rate, $thread_id, $caller_user_id, $target_user_id );
            $charge_rate = apply_filters( 'better_messages_mycred_call_charge_rate', $charge_rate, $thread_id, $caller_user_id, $target_user_id ); // backward compat

            if ( $charge_rate === 0 ) return $error;

            $balance = $this->provider->get_user_balance( $caller_user_id );

            if ( $balance < $charge_rate ) {
                return Better_Messages()->settings[ $this->provider->setting_key( 'CallPricingStartMessage' ) ];
            }

            return $error;
        }

        public function charge_call_usage( $message_id, $thread_id, $caller_user_id )
        {
            if ( ! $this->provider ) return;
            if ( $caller_user_id <= 0 || ! Better_Messages()->calls ) return;

            $charge_rate = $this->provider->get_user_charge_rate( $caller_user_id, $this->provider->setting_key( 'CallPricing' ) );
            $charge_rate = apply_filters( 'better_messages_points_call_charge_rate', $charge_rate, $thread_id, $caller_user_id, 0 );
            $charge_rate = apply_filters( 'better_messages_mycred_call_charge_rate', $charge_rate, $thread_id, $caller_user_id, 0 ); // backward compat
            if ( $charge_rate === 0 ) return;

            if ( ! Better_Messages()->calls->call_has_confirmed_traffic( $message_id ) ) return;

            $current_minute = Better_Messages()->functions->get_message_meta( $message_id, 'mins' );
            if ( $current_minute === '' ) return;

            $current_minute = intval( $current_minute ) + 1;
            $meta_key = $this->provider->get_meta_prefix() . '_charged_mins';
            $charged_minutes = (int) Better_Messages()->functions->get_message_meta( $message_id, $meta_key );

            if ( $charged_minutes >= $current_minute ) return;

            $uncharged_minutes = $current_minute - $charged_minutes;
            $to_charge = $charge_rate * $uncharged_minutes;
            $balance = $this->provider->get_user_balance( $caller_user_id );

            if ( $balance >= $to_charge ) {
                $log_template = Better_Messages()->settings[ $this->provider->setting_key( 'LogCallUsage' ) ];
                $log_entry = str_replace( '{id}', $message_id, $log_template );

                $this->provider->deduct_points( $caller_user_id, $to_charge, 'better_messages_call_charge_' . $message_id, $log_entry );

                Better_Messages()->functions->update_message_meta( $message_id, $meta_key, $current_minute );
            } else {
                wp_send_json( [ 'action' => 'end_call', 'reason' => Better_Messages()->settings[ $this->provider->setting_key( 'CallPricingEndMessage' ) ] ] );
            }
        }

        private function is_thread_type_charged( $thread_id, $types_key )
        {
            $allowed_types = Better_Messages()->settings[ $this->provider->setting_key( $types_key ) ] ?? [];
            $thread_type = Better_Messages()->functions->get_thread_type( (int) $thread_id );
            return in_array( $thread_type, $allowed_types, true );
        }

        private function is_ai_bot_thread( $thread_id )
        {
            $bot_id = Better_Messages()->functions->get_thread_meta( $thread_id, 'ai_bot_thread' );
            return ! empty( $bot_id );
        }

        public function add_balance_header( $headers, $result, $request )
        {
            $user_id = Better_Messages()->functions->get_current_user_id();
            if ( $user_id > 0 && $this->provider ) {
                $headers['X-BM-Points-Balance'] = $this->provider->get_user_balance( $user_id );
            }
            return $headers;
        }

        public function add_balance_to_self_update( $extra, $thread_id, $user_id )
        {
            if ( $user_id > 0 && $this->provider ) {
                $extra['pointsBalance'] = (int) $this->provider->get_user_balance( $user_id );
            }
            return $extra;
        }

        public function add_message_charge_to_thread( $thread_item, $thread_id, $type, $personal_data, $current_user_id )
        {
            if ( ! $personal_data || ! $this->provider || $current_user_id <= 0 ) {
                return $thread_item;
            }

            if ( $this->is_ai_bot_thread( $thread_id ) ) return $thread_item;

            $allowed_types = Better_Messages()->settings[ $this->provider->setting_key( 'NewMessageChargeTypes' ) ] ?? [];
            if ( ! in_array( $type, $allowed_types, true ) ) {
                return $thread_item;
            }

            $charge_rate = $this->provider->get_user_charge_rate(
                $current_user_id,
                $this->provider->setting_key( 'NewMessageCharge' )
            );

            $charge_rate = apply_filters( 'better_messages_points_message_charge_rate', $charge_rate, 0, $thread_id, $current_user_id );
            $charge_rate = apply_filters( 'better_messages_mycred_message_charge_rate', $charge_rate, 0, $thread_id, $current_user_id ); // backward compat

            if ( $charge_rate > 0 ) {
                $thread_item['messageCharge'] = $charge_rate;
            }

            return $thread_item;
        }
    }

    function Better_Messages_Points() {
        return Better_Messages_Points::instance();
    }
}
