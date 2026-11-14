<?php

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WordPlus_SDK_Booking_LatePoint' ) ) {
    final class WordPlus_SDK_Booking_LatePoint extends WordPlus_SDK_Booking_Adapter
    {
        const SLUG = 'latepoint';

        const REGISTRATION = 'reg_';

        const MEETING_SYSTEM = 'wordplus_';

        const SERVICE_MEETING = 'wordplus_meeting';

        private $meetings_hooked = false;

        private $looked_at_later = array();

        private $said_made = array();

        private $said_deleted = array();

        private $printed = array();

        private $cabinet_url = null;

        private $viewer = '';

        private $summary_of = '';

        public function slug()
        {
            return self::SLUG;
        }

        public function hook()
        {
            add_action( 'latepoint_booking_created', array( $this, 'booking_made' ) );
            add_action( 'latepoint_event_registration_created', array( $this, 'registration_made' ) );

            add_action( 'latepoint_booking_updated', array( $this, 'booking_changed' ) );
            add_action( 'latepoint_event_registration_cancelled', array( $this, 'registration_changed' ) );

            add_action( 'latepoint_model_save', array( $this, 'model_saved' ) );

            add_action( 'latepoint_booking_will_be_deleted', array( $this, 'booking_deleted' ) );
            add_action( 'latepoint_model_deleted', array( $this, 'model_deleted' ), 10, 2 );
            add_action( 'latepoint_event_deleted', array( $this, 'event_deleted' ), 5 );

            add_action( 'latepoint_customer_dashboard_after_booking_info_tile', array( $this, 'booking_page' ) );
            add_filter( 'latepoint_summary_booking_info_after_start_date', array( $this, 'summary_viewer' ), 10, 4 );
            add_action( 'latepoint_booking_summary_after_booking_actions', array( $this, 'summary_page' ) );

            add_filter( 'latepoint_replace_booking_vars', array( $this, 'print_booking_links' ), 10, 2 );
            add_action( 'latepoint_available_vars_booking', array( $this, 'link_variables' ) );
            add_filter( 'latepoint_replace_all_vars_in_template', array( $this, 'print_other_links' ), 10, 2 );
            add_filter( 'latepoint_process_prepare_data_for_run', array( $this, 'links_for_recipient' ) );

            add_filter( 'wp_mail', array( $this, 'links_for_mail' ) );
        }

        public function placeholder( $key )
        {
            return '{{' . $key . '_url}}';
        }

        public function meeting_placeholder( $key )
        {
            return '{{' . $key . '_meeting_url}}';
        }

        public function print_booking_links( $text, $booking )
        {
            return $this->print_links( $text, is_object( $booking ) && isset( $booking->id ) ? (string) $booking->id : '' );
        }

        public function print_other_links( $text, $vars )
        {
            if ( ! is_array( $vars ) || ! empty( $vars['booking'] ) || ! $this->holds_a_link( $text ) ) {
                return $text;
            }

            if ( ! empty( $vars['event_registration']->id ) ) {
                return $this->print_links( $text, self::REGISTRATION . $vars['event_registration']->id );
            }

            if ( ! empty( $vars['order']->id ) ) {
                return $this->print_links( $text, $this->first_of_order( (int) $vars['order']->id ) );
            }

            return $text;
        }

        public function links_for_recipient( $action )
        {
            $printed       = $this->printed;
            $this->printed = array();

            if ( empty( $printed ) || ! is_object( $action ) || ! isset( $action->prepared_data_for_run ) || self::goes_to_customer( $action ) ) {
                return $action;
            }

            foreach ( $printed as $customer_url => $link ) {
                $staff_url = $this->staff_address( $link );

                array_walk_recursive( $action->prepared_data_for_run, function ( &$value ) use ( $customer_url, $staff_url ) {
                    if ( is_string( $value ) ) {
                        $value = str_replace( (string) $customer_url, $staff_url, $value );
                    }
                } );
            }

            return $action;
        }

        public function links_for_mail( $mail )
        {
            if ( empty( $this->printed ) || ! is_array( $mail ) ) {
                return $mail;
            }

            $recipients = self::addresses( isset( $mail['to'] ) ? $mail['to'] : '' );

            foreach ( self::header_lines( isset( $mail['headers'] ) ? $mail['headers'] : '' ) as $line ) {
                if ( preg_match( '/^\s*(?:cc|bcc)\s*:(.*)$/i', $line, $copy ) ) {
                    $recipients = array_merge( $recipients, self::addresses( $copy[1] ) );
                }
            }

            foreach ( $this->printed as $customer_url => $link ) {
                $customer_url = (string) $customer_url;
                $email        = isset( $link['booking']['customers'][0]['email'] ) ? strtolower( (string) $link['booking']['customers'][0]['email'] ) : '';

                if ( '' !== $email && array( $email ) === array_values( array_unique( $recipients ) ) ) {
                    continue;
                }

                $staff_url = $this->staff_address( $link );

                foreach ( array( 'subject', 'message' ) as $part ) {
                    if ( isset( $mail[ $part ] ) && is_string( $mail[ $part ] ) ) {
                        $mail[ $part ] = str_replace( $customer_url, $staff_url, $mail[ $part ] );
                    }
                }
            }

            return $mail;
        }

        private static function addresses( $line )
        {
            $addresses = array();

            foreach ( is_array( $line ) ? $line : explode( ',', (string) $line ) as $part ) {
                $part = trim( (string) $part );

                if ( preg_match( '/<([^>]*)>/', $part, $inside ) ) {
                    $part = $inside[1];
                }

                if ( '' !== trim( $part ) ) {
                    $addresses[] = strtolower( trim( $part ) );
                }
            }

            return $addresses;
        }

        private static function header_lines( $headers )
        {
            return is_array( $headers ) ? array_map( 'strval', $headers ) : preg_split( "/\r\n|\n|\r/", (string) $headers );
        }

        private static function goes_to_customer( $action )
        {
            $settings = isset( $action->settings ) && is_array( $action->settings ) ? $action->settings : array();
            $line     = isset( $settings['to_email'] ) ? $settings['to_email'] : ( isset( $settings['to_phone'] ) ? $settings['to_phone'] : '' );
            $rest     = preg_replace( '/\{\{customer_[a-z_]+\}\}/', '', (string) $line, -1, $named );

            return $named > 0 && ! preg_match( '/@|\d|\{\{/', (string) $rest );
        }

        private function holds_a_link( $text )
        {
            foreach ( array_keys( $this->bookings->links() ) as $key ) {
                if ( false !== strpos( (string) $text, $this->placeholder( $key ) ) ) {
                    return true;
                }
            }

            foreach ( array_keys( $this->bookings->meetings() ) as $key ) {
                if ( false !== strpos( (string) $text, $this->meeting_placeholder( $key ) ) ) {
                    return true;
                }
            }

            return false;
        }

        private function print_links( $text, $id )
        {
            $text    = (string) $text;
            $booking = null;

            foreach ( array_keys( $this->bookings->links() ) as $key ) {
                $placeholder = $this->placeholder( $key );

                if ( false === strpos( $text, $placeholder ) ) {
                    continue;
                }

                $booking = null === $booking ? $this->get( $id ) : $booking;
                $url     = null === $booking ? '' : $this->bookings->link_url( $key, $booking, 'customer' );

                if ( '' !== $url ) {
                    $this->printed[ $url ] = array( 'key' => $key, 'booking' => $booking );
                }

                $text = str_replace( $placeholder, $url, $text );
            }

            foreach ( array_keys( $this->bookings->meetings() ) as $key ) {
                $placeholder = $this->meeting_placeholder( $key );

                if ( false === strpos( $text, $placeholder ) ) {
                    continue;
                }

                $meeting = $this->meeting( $id );
                $url     = '';

                if ( null !== $meeting && $meeting['key'] === $key ) {
                    $booking = null === $booking ? $this->get( $id ) : $booking;
                    $url     = null === $booking ? $meeting['url'] : $this->bookings->personal_meeting_url( $key, $booking, 'customer' );

                    if ( $url !== $meeting['url'] ) {
                        $this->printed[ $url ] = array( 'staff' => $meeting['url'], 'booking' => $booking );
                    }
                }

                $text = str_replace( $placeholder, $url, $text );
            }

            return $text;
        }

        private function staff_address( array $printed )
        {
            return isset( $printed['staff'] ) ? (string) $printed['staff'] : $this->bookings->link_url( $printed['key'], $printed['booking'], 'staff' );
        }

        public function meeting_registered( $key )
        {
            if ( $this->meetings_hooked ) {
                return;
            }

            $this->meetings_hooked = true;

            add_filter( 'latepoint_list_of_external_meeting_systems', array( $this, 'meeting_systems' ), 10, 2 );
            add_action( 'latepoint_external_meeting_system_settings', array( $this, 'meeting_system_settings' ) );
            add_action( 'latepoint_service_form_after', array( $this, 'service_meeting_field' ) );
            add_action( 'latepoint_service_saved', array( $this, 'service_meeting_saved' ), 10, 3 );

            add_filter( 'latepoint_booking_summary_service_attributes', array( $this, 'summary_meeting' ), 10, 2 );
            add_action( 'latepoint_customer_dashboard_after_booking_info_tile', array( $this, 'cabinet_meeting' ), 5 );
            add_action( 'latepoint_booking_data_form_after', array( $this, 'admin_meeting' ) );
            add_filter( 'latepoint_build_add_to_calendar_link_params', array( $this, 'calendar_meeting' ), 10, 3 );
            add_action( 'latepoint_available_vars_booking', array( $this, 'meeting_variables' ) );
        }

        public function meeting_systems( $systems, $enabled_only = false )
        {
            if ( ! is_array( $systems ) ) {
                return $systems;
            }

            foreach ( $this->bookings->meetings() as $key => $meeting ) {
                if ( ! $enabled_only || $this->meeting_on( $key ) ) {
                    $systems[ self::MEETING_SYSTEM . $key ] = array(
                        'code'      => self::MEETING_SYSTEM . $key,
                        'name'      => $meeting['label'],
                        'image_url' => '',
                    );
                }
            }

            return $systems;
        }

        public function meeting_system_settings( $code )
        {
            if ( null === $this->meeting_of_code( $code ) ) {
                return;
            }

            echo '<p class="os-note">' . esc_html( sprintf(
                /* translators: %s: the variable that prints the meeting's address in a notification */
                _x( 'Select it under Video Meeting for each service that should use it. The meeting link is shown in booking summaries, the customer cabinet and Add to Calendar links, and %s adds it to notifications.', 'Bookings (LatePoint)', 'wordplus-sdk' ),
                $this->meeting_placeholder( (string) $this->meeting_of_code( $code ) )
            ) ) . '</p>';
        }

        public function service_meeting_field( $service )
        {
            if ( ! is_object( $service ) || ! class_exists( 'OsFormHelper' ) ) {
                return;
            }

            $options = array( array( 'value' => '', 'label' => _x( 'None', 'Bookings (LatePoint): no video meeting for a service', 'wordplus-sdk' ) ) );

            foreach ( $this->bookings->meetings() as $key => $meeting ) {
                if ( $this->meeting_on( $key ) ) {
                    $options[] = array( 'value' => $key, 'label' => $meeting['label'] );
                }
            }

            if ( count( $options ) < 2 ) {
                return;
            }

            $chosen = empty( $service->id ) ? '' : $this->service_meeting( (int) $service->id );

            echo '<div class="white-box section-anchor" id="stickySectionWordPlusMeeting">';
            echo '<div class="white-box-header"><div class="os-form-sub-header"><h3>' . esc_html_x( 'Video Meeting', 'Bookings (LatePoint)', 'wordplus-sdk' ) . '</h3></div></div>';
            echo '<div class="white-box-content no-padding"><div class="sub-section-row">';
            echo '<div class="sub-section-label"><h3>' . esc_html_x( 'Meeting', 'Bookings (LatePoint)', 'wordplus-sdk' ) . '</h3></div>';
            echo '<div class="sub-section-content">';
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            echo OsFormHelper::select_field( 'service[' . self::SERVICE_MEETING . ']', _x( 'Video meeting for bookings of this service', 'Bookings (LatePoint)', 'wordplus-sdk' ), $options, $chosen );
            echo '</div></div></div></div>';
        }

        public function service_meeting_saved( $service, $is_new = false, $params = array() )
        {
            if ( ! is_object( $service ) || empty( $service->id ) || ! is_array( $params ) || ! array_key_exists( self::SERVICE_MEETING, $params ) || ! method_exists( $service, 'save_meta_by_key' ) ) {
                return;
            }

            $key      = is_string( $params[ self::SERVICE_MEETING ] ) ? $params[ self::SERVICE_MEETING ] : '';
            $meetings = $this->bookings->meetings();

            $service->save_meta_by_key( self::SERVICE_MEETING, isset( $meetings[ $key ] ) ? $key : '' );
        }

        public function summary_viewer( $html, $booking = null, $cart_item_id = null, $viewer = 'customer' )
        {
            $this->viewer     = 'agent' === $viewer ? 'staff' : 'customer';
            $this->summary_of = is_object( $booking ) && ! empty( $booking->id ) ? (string) $booking->id : '';

            return $html;
        }

        public function summary_meeting( $attributes, $booking )
        {
            $meeting = is_array( $attributes ) ? $this->meeting_of_model( $booking, $this->viewer ) : null;

            if ( null !== $meeting ) {
                $attributes[] = array(
                    'label' => esc_html( $meeting['label'] ),
                    'value' => self::meeting_link( $meeting ),
                );
            }

            return $attributes;
        }

        public function cabinet_meeting( $booking )
        {
            $this->viewer = 'customer';
            $meeting      = $this->meeting_of_model( $booking, 'customer' );

            if ( null !== $meeting ) {
                echo '<div class="customer-booking-info-row"><span class="booking-info-label">' . esc_html( $meeting['label'] ) . '</span><span class="booking-info-value">' . self::meeting_link( $meeting ) . '</span></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            }
        }

        public function admin_meeting( $booking )
        {
            $meeting = $this->meeting_of_model( $booking );

            if ( null !== $meeting ) {
                echo '<div class="os-row"><div class="os-col-12"><p class="os-note">' . esc_html( $meeting['label'] ) . ': ' . self::meeting_link( $meeting, $meeting['url'] ) . '</p></div></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            }
        }

        public function calendar_meeting( $params, $calendar_type, $booking )
        {
            $meeting = is_array( $params ) ? $this->meeting_of_model( $booking, $this->viewer ) : null;

            if ( null === $meeting ) {
                return $params;
            }

            $about = 'outlook' === $calendar_type ? 'body' : 'details';

            if ( empty( $params['location'] ) ) {
                $params['location'] = $meeting['url'];
            }

            $params[ $about ] = trim( ( isset( $params[ $about ] ) ? $params[ $about ] . "\n" : '' ) . $meeting['label'] . ': ' . $meeting['url'] );

            return $params;
        }

        public function link_variables()
        {
            foreach ( $this->bookings->links() as $key => $link ) {
                echo '<li><span class="var-label">' . esc_html( '' !== $link['label'] ? $link['label'] : $key ) . '</span> <span class="var-code os-click-to-copy">' . esc_html( $this->placeholder( $key ) ) . '</span></li>';
            }
        }

        public function meeting_variables()
        {
            foreach ( $this->bookings->meetings() as $key => $meeting ) {
                if ( $this->meeting_on( $key ) ) {
                    echo '<li><span class="var-label">' . esc_html( $meeting['label'] ) . '</span> <span class="var-code os-click-to-copy">' . esc_html( $this->meeting_placeholder( $key ) ) . '</span></li>';
                }
            }
        }

        private static function meeting_link( array $meeting, $text = '' )
        {
            $text = '' !== $text ? $text : _x( 'Join', 'Bookings (LatePoint): the link to the video meeting of a booking', 'wordplus-sdk' );

            return '<a href="' . esc_url( $meeting['url'] ) . '" target="_blank" rel="noopener">' . esc_html( $text ) . '</a>';
        }

        private function meeting_of_model( $booking, $viewer = '' )
        {
            return is_object( $booking ) && ! empty( $booking->id ) && ctype_digit( (string) $booking->id ) ? $this->meeting( (string) $booking->id, $viewer ) : null;
        }

        private function meeting( $id, $viewer = '' )
        {
            $row = ctype_digit( (string) $id ) ? $this->booking_row( (int) $id ) : null;
            $key = null === $row ? '' : (string) $row['meeting'];

            if ( '' === $key || ! $this->meeting_on( $key ) ) {
                return null;
            }

            $booking  = $this->shaped( $row );
            $meetings = $this->bookings->meetings();

            if ( WordPlus_SDK_Bookings::is_closed( $booking ) ) {
                return null;
            }

            $url = 'customer' === $viewer ? $this->bookings->personal_meeting_url( $key, $booking, 'customer' ) : $this->bookings->meeting_url( $key, $booking );

            return '' === $url ? null : array( 'key' => $key, 'label' => $meetings[ $key ]['label'], 'url' => $url );
        }

        private function meeting_of_code( $code )
        {
            $code = (string) $code;
            $key  = 0 === strpos( $code, self::MEETING_SYSTEM ) ? substr( $code, strlen( self::MEETING_SYSTEM ) ) : '';
            $all  = $this->bookings->meetings();

            return '' !== $key && isset( $all[ $key ] ) ? $key : null;
        }

        private function meeting_on( $key )
        {
            global $wpdb;

            $meetings = $this->bookings->meetings();

            if ( ! isset( $meetings[ $key ] ) ) {
                return false;
            }

            return 'on' === (string) $wpdb->get_var( $wpdb->prepare(
                "SELECT value FROM {$wpdb->prefix}latepoint_settings WHERE name = %s LIMIT 1",
                'enable_' . self::MEETING_SYSTEM . $key
            ) );
        }

        private function service_meeting( $service_id )
        {
            global $wpdb;

            return (string) $wpdb->get_var( $wpdb->prepare(
                "SELECT meta_value FROM {$wpdb->prefix}latepoint_service_meta WHERE object_id = %d AND meta_key = %s LIMIT 1",
                $service_id,
                self::SERVICE_MEETING
            ) );
        }

        public function booking_made( $booking )
        {
            if ( is_object( $booking ) && ! empty( $booking->id ) ) {
                $this->say_made( (string) $booking->id );
            }
        }

        public function registration_made( $registration )
        {
            if ( is_object( $registration ) && ! empty( $registration->id ) ) {
                $this->say_made( self::REGISTRATION . $registration->id );
            }
        }

        public function booking_changed( $booking )
        {
            if ( is_object( $booking ) && ! empty( $booking->id ) ) {
                $this->say_changed( (string) $booking->id );
            }
        }

        public function registration_changed( $registration )
        {
            if ( is_object( $registration ) && ! empty( $registration->id ) ) {
                $this->say_changed( self::REGISTRATION . $registration->id );
            }
        }

        public function model_saved( $model )
        {
            if ( $model instanceof OsBookingModel ) {
                $this->look_later( (string) $model->id, self::saved_new( $model ) );
            } elseif ( $model instanceof OsEventRegistrationModel ) {
                $this->look_later( self::REGISTRATION . $model->id, self::saved_new( $model ) );
            } elseif ( $model instanceof OsEventModel ) {
                foreach ( $this->registrations_of_event( (int) $model->id ) as $id ) {
                    $this->look_later( $id );
                }
            }
        }

        public function look_now()
        {
            $looked_at             = $this->looked_at_later;
            $this->looked_at_later = array();

            foreach ( $looked_at as $id => $saved_new ) {
                if ( $saved_new ) {
                    $this->made( (string) $id );
                } else {
                    $this->changed( (string) $id );
                }
            }
        }

        public function booking_deleted( $id )
        {
            $this->say_deleted( (string) $id );
        }

        public function model_deleted( $model, $id = 0 )
        {
            if ( $model instanceof OsBookingModel ) {
                $this->say_deleted( (string) $id );
            } elseif ( $model instanceof OsEventRegistrationModel ) {
                $this->say_deleted( self::REGISTRATION . $id );
            }
        }

        public function event_deleted( $event )
        {
            if ( is_object( $event ) && ! empty( $event->id ) ) {
                foreach ( $this->registrations_of_event( (int) $event->id ) as $id ) {
                    $this->say_deleted( $id );
                }
            }
        }

        public function booking_page( $booking )
        {
            if ( ! is_object( $booking ) || empty( $booking->id ) ) {
                return;
            }

            $this->viewer = 'customer';

            $html = $this->page_html( (string) $booking->id, 'customer', 'latepoint-btn latepoint-btn-primary latepoint-btn-link' );

            if ( '' !== $html ) {
                echo '<div class="customer-booking-info-row wordplus-booking-page">' . $html . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            }
        }

        public function summary_page( $booking )
        {
            if ( ! is_object( $booking ) || empty( $booking->id ) || '' === $this->viewer || (string) $booking->id !== $this->summary_of || self::in_cabinet() ) {
                return;
            }

            echo $this->page_html( (string) $booking->id, $this->viewer, 'booking-summary-action-btn' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        }

        private static function in_cabinet()
        {
            $route = '';

            // phpcs:disable WordPress.Security.NonceVerification
            foreach ( array( $_GET, $_POST ) as $sent ) {
                if ( isset( $sent['route_name'] ) ) {
                    $route = is_string( $sent['route_name'] ) ? sanitize_text_field( wp_unslash( $sent['route_name'] ) ) : '';
                    break;
                }
            }
            // phpcs:enable WordPress.Security.NonceVerification

            return 0 === strpos( $route, 'customer_cabinet__' );
        }

        private function say_made( $id )
        {
            unset( $this->looked_at_later[ $id ] );

            $this->said_made[ $id ] = true;
            $this->made( $id );
        }

        private function say_changed( $id )
        {
            unset( $this->looked_at_later[ $id ] );

            $this->changed( $id );
        }

        private function say_deleted( $id )
        {
            if ( ! empty( $id ) && ! isset( $this->said_deleted[ $id ] ) ) {
                $this->said_deleted[ $id ] = true;
                $this->deleted( $id );
            }
        }

        private function look_later( $id, $saved_new = false )
        {
            if ( empty( $this->looked_at_later ) ) {
                add_action( 'shutdown', array( $this, 'look_now' ) );
            }

            $this->looked_at_later[ $id ] = ! isset( $this->said_made[ $id ] ) && ( $saved_new || ! empty( $this->looked_at_later[ $id ] ) );
        }

        private static function saved_new( $model )
        {
            return ! empty( $model->created_at ) && $model->created_at === $model->updated_at;
        }

        public function get( $id )
        {
            $id = (string) $id;

            if ( 0 === strpos( $id, self::REGISTRATION ) ) {
                $number = substr( $id, strlen( self::REGISTRATION ) );

                return ctype_digit( $number ) ? $this->registration( (int) $number ) : null;
            }

            return ctype_digit( $id ) ? $this->booking( (int) $id ) : null;
        }

        private function booking( $id )
        {
            $row = $this->booking_row( $id );

            return null === $row ? null : $this->shaped( $row );
        }

        private function booking_row( $id )
        {
            global $wpdb;

            $row = $wpdb->get_row( $wpdb->prepare(
                "SELECT b.id, b.status, b.start_date, b.start_time, b.end_date, b.end_time, b.start_datetime_utc, b.end_datetime_utc,
                    b.server_timezone, b.customer_id, b.agent_id, b.service_id, b.location_id, b.total_attendees, b.recurrence_id,
                    s.name AS service, s.capacity_max, l.name AS location, m.meta_value AS meeting
                FROM {$wpdb->prefix}latepoint_bookings b
                LEFT JOIN {$wpdb->prefix}latepoint_services s ON s.id = b.service_id
                LEFT JOIN {$wpdb->prefix}latepoint_locations l ON l.id = b.location_id
                LEFT JOIN {$wpdb->prefix}latepoint_service_meta m ON m.object_id = b.service_id AND m.meta_key = %s
                WHERE b.id = %d",
                self::SERVICE_MEETING,
                $id
            ), ARRAY_A );

            return is_array( $row ) ? $row : null;
        }

        private function shaped( array $row )
        {
            $start    = self::starts( $row );
            $end      = self::ends( $row );
            $capacity = max( 1, (int) $row['capacity_max'] );
            $slot     = array( (int) $row['service_id'], (int) $row['agent_id'], (int) $row['location_id'], $start, $end );
            $meeting  = isset( $row['meeting'] ) ? (string) $row['meeting'] : '';

            return WordPlus_SDK_Bookings::shape( array(
                'plugin'     => self::SLUG,
                'id'         => (string) $row['id'],
                'status'     => self::status( (string) $row['status'] ),
                'start'      => $start,
                'end'        => $end,
                'service'    => (string) $row['service'],
                'place'      => (string) $row['location'],
                'online'     => '' !== $meeting && $this->meeting_on( $meeting ),
                'staff'      => $this->agent( (int) $row['agent_id'] ),
                'customers'  => array( $this->customer( (int) $row['customer_id'] ) ),
                'capacity'   => $capacity,
                'group'      => $capacity > 1 ? 'slot_' . implode( '_', $slot ) : '',
                'series'     => $capacity < 2 && (int) $row['recurrence_id'] > 0 ? 'series_' . (int) $row['recurrence_id'] : '',
                'seats'      => max( 1, (int) $row['total_attendees'] ),
                'manage_url' => $this->cabinet_url(),
            ) );
        }

        public function group_bookings( array $booking )
        {
            global $wpdb;

            $ids = array();

            if ( preg_match( '/^slot_(\d+)_(\d+)_(\d+)_(\d+)_(\d+)$/D', $booking['group'], $slot ) ) {
                $start = (int) $slot[4];
                $end   = (int) $slot[5];

                $rows = (array) $wpdb->get_results( $wpdb->prepare(
                    "SELECT id, start_date, start_time, end_date, end_time, start_datetime_utc, end_datetime_utc, server_timezone
                    FROM {$wpdb->prefix}latepoint_bookings
                    WHERE service_id = %d AND agent_id = %d AND location_id = %d AND status <> %s AND start_date BETWEEN %s AND %s
                    ORDER BY id ASC",
                    (int) $slot[1],
                    (int) $slot[2],
                    (int) $slot[3],
                    'cancelled',
                    gmdate( 'Y-m-d', $start - DAY_IN_SECONDS ),
                    gmdate( 'Y-m-d', $start + DAY_IN_SECONDS )
                ), ARRAY_A );

                foreach ( $rows as $row ) {
                    if ( self::starts( $row ) === $start && self::ends( $row ) === $end ) {
                        $ids[] = (string) $row['id'];
                    }
                }
            } elseif ( preg_match( '/^event_(\d+)$/D', $booking['group'], $event ) ) {
                foreach ( (array) $wpdb->get_col( $wpdb->prepare(
                    "SELECT id FROM {$wpdb->prefix}latepoint_event_registrations WHERE event_id = %d ORDER BY id ASC",
                    (int) $event[1]
                ) ) as $registration ) {
                    $ids[] = self::REGISTRATION . $registration;
                }
            }

            return array_values( array_unique( array_merge( array( (string) $booking['id'] ), $ids ) ) );
        }

        public function series_bookings( array $booking )
        {
            global $wpdb;

            $ids = array();

            if ( preg_match( '/^series_(\d+)$/D', $booking['series'], $series ) && ctype_digit( (string) $booking['id'] ) ) {
                $ids = array_map( 'strval', (array) $wpdb->get_col( $wpdb->prepare(
                    "SELECT b.id FROM {$wpdb->prefix}latepoint_bookings b
                    INNER JOIN {$wpdb->prefix}latepoint_bookings asked ON asked.id = %d AND asked.customer_id = b.customer_id
                    WHERE b.recurrence_id = %d
                    ORDER BY b.start_date ASC, b.start_time ASC, b.id ASC",
                    (int) $booking['id'],
                    (int) $series[1]
                ) ) );
            }

            return array_values( array_unique( array_merge( array( (string) $booking['id'] ), $ids ) ) );
        }

        private static function starts( array $row )
        {
            return self::booking_time( $row['start_date'], $row['start_time'], $row['server_timezone'], $row['start_datetime_utc'] );
        }

        private static function ends( array $row )
        {
            return self::booking_time( $row['end_date'] ?: $row['start_date'], $row['end_time'], $row['server_timezone'], $row['end_datetime_utc'] );
        }

        private static function booking_time( $date, $minutes, $zone, $utc )
        {
            $date = (string) $date;

            if ( '' !== $date && 0 !== strpos( $date, '0000' ) && null !== $minutes && '' !== (string) $minutes ) {
                $time = date_create( $date . ' 00:00:00', self::zone( $zone ) );

                if ( false !== $time ) {
                    if ( (int) $minutes > 0 ) {
                        $time->modify( '+' . (int) $minutes . ' minutes' );
                    }

                    return (int) $time->getTimestamp();
                }
            }

            return self::seconds( $utc );
        }

        private static function zone( $name )
        {
            $name = trim( (string) $name );

            if ( '' !== $name ) {
                try {
                    return new DateTimeZone( $name );
                } catch ( Exception $e ) {
                    return wp_timezone();
                }
            }

            return wp_timezone();
        }

        private static function seconds( $datetime )
        {
            $datetime = (string) $datetime;

            return '' === $datetime || 0 === strpos( $datetime, '0000' ) ? 0 : self::utc( $datetime );
        }

        private static function status( $status )
        {
            $map = array(
                'approved'        => WordPlus_SDK_Bookings::APPROVED,
                'pending'         => WordPlus_SDK_Bookings::PENDING,
                'payment_pending' => WordPlus_SDK_Bookings::PENDING,
                'cancelled'       => WordPlus_SDK_Bookings::CANCELLED,
                'no_show'         => WordPlus_SDK_Bookings::NO_SHOW,
                'completed'       => WordPlus_SDK_Bookings::COMPLETED,
            );

            return isset( $map[ $status ] ) ? $map[ $status ] : WordPlus_SDK_Bookings::PENDING;
        }

        private function registration( $id )
        {
            global $wpdb;

            $row = $wpdb->get_row( $wpdb->prepare(
                "SELECT r.id, r.status, r.customer_id, r.event_id,
                    e.name, e.status AS event_status, e.event_type, e.online_url, e.start_datetime_utc, e.end_datetime_utc, e.capacity, e.agent_id,
                    l.name AS location
                FROM {$wpdb->prefix}latepoint_event_registrations r
                INNER JOIN {$wpdb->prefix}latepoint_events e ON e.id = r.event_id
                LEFT JOIN {$wpdb->prefix}latepoint_locations l ON l.id = e.location_id
                WHERE r.id = %d",
                $id
            ), ARRAY_A );

            if ( ! is_array( $row ) ) {
                return null;
            }

            $event_id = (int) $row['event_id'];
            $place    = $this->event_meta( $event_id, 'location_text' );
            $staff    = $this->agent( (int) $row['agent_id'] );

            if ( empty( $staff ) && '' !== $this->event_meta( $event_id, 'organizer_name' ) ) {
                $staff = array( self::person( 0, $this->event_meta( $event_id, 'organizer_name' ), '' ) );
            }

            return WordPlus_SDK_Bookings::shape( array(
                'plugin'     => self::SLUG,
                'id'         => self::REGISTRATION . $row['id'],
                'status'     => self::registration_status( (string) $row['status'], (string) $row['event_status'] ),
                'start'      => self::seconds( $row['start_datetime_utc'] ),
                'end'        => self::seconds( $row['end_datetime_utc'] ),
                'service'    => (string) $row['name'],
                'place'      => '' !== $place ? $place : (string) $row['location'],
                'online'     => 'online' === (string) $row['event_type'] || '' !== (string) $row['online_url'],
                'staff'      => $staff,
                'customers'  => array( $this->customer( (int) $row['customer_id'] ) ),
                'capacity'   => max( 1, (int) $row['capacity'] ),
                'group'      => 'event_' . $event_id,
                'manage_url' => '',
            ) );
        }

        private static function registration_status( $registration, $event )
        {
            if ( 'cancelled' === $registration || 'cancelled' === $event ) {
                return WordPlus_SDK_Bookings::CANCELLED;
            }

            if ( 'pending' === $registration ) {
                return WordPlus_SDK_Bookings::PENDING;
            }

            return 'completed' === $event ? WordPlus_SDK_Bookings::COMPLETED : WordPlus_SDK_Bookings::APPROVED;
        }

        private function event_meta( $event_id, $key )
        {
            global $wpdb;

            return (string) $wpdb->get_var( $wpdb->prepare(
                "SELECT meta_value FROM {$wpdb->prefix}latepoint_event_meta WHERE object_id = %d AND meta_key = %s LIMIT 1",
                $event_id,
                $key
            ) );
        }

        private function agent( $agent_id )
        {
            $row = $this->person_row( 'latepoint_agents', $agent_id );

            if ( null === $row ) {
                return array();
            }

            return array( self::person( isset( $row['wp_user_id'] ) ? (int) $row['wp_user_id'] : 0, $row['first_name'] . ' ' . $row['last_name'], $row['email'] ) );
        }

        private function customer( $customer_id )
        {
            $row = $this->person_row( 'latepoint_customers', $customer_id );

            if ( null === $row ) {
                return self::person( 0, '', '' );
            }

            return self::person( isset( $row['wordpress_user_id'] ) ? (int) $row['wordpress_user_id'] : 0, $row['first_name'] . ' ' . $row['last_name'], $row['email'] );
        }

        private function person_row( $table, $id )
        {
            global $wpdb;

            if ( $id < 1 ) {
                return null;
            }

            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}{$table} WHERE id = %d", $id ), ARRAY_A );

            return is_array( $row ) ? $row : null;
        }

        private function cabinet_url()
        {
            global $wpdb;

            if ( null === $this->cabinet_url ) {
                $path = $wpdb->get_var( $wpdb->prepare(
                    "SELECT value FROM {$wpdb->prefix}latepoint_settings WHERE name = %s LIMIT 1",
                    'page_url_customer_dashboard'
                ) );
                $path = null === $path ? '/customer-dashboard' : trim( (string) $path );

                $this->cabinet_url = '' === $path ? '' : site_url( $path );
            }

            return $this->cabinet_url;
        }

        private function registrations_of_event( $event_id )
        {
            global $wpdb;

            $ids = array();

            foreach ( (array) $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}latepoint_event_registrations WHERE event_id = %d", $event_id ) ) as $id ) {
                $ids[] = self::REGISTRATION . $id;
            }

            return $ids;
        }

        private function first_of_order( $order_id )
        {
            global $wpdb;

            $booking = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT b.id FROM {$wpdb->prefix}latepoint_bookings b
                INNER JOIN {$wpdb->prefix}latepoint_order_items i ON i.id = b.order_item_id
                WHERE i.order_id = %d ORDER BY b.id ASC LIMIT 1",
                $order_id
            ) );

            if ( $booking > 0 ) {
                return (string) $booking;
            }

            $registration = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}latepoint_event_registrations WHERE order_id = %d ORDER BY id ASC LIMIT 1",
                $order_id
            ) );

            return $registration > 0 ? self::REGISTRATION . $registration : '';
        }
    }
}
