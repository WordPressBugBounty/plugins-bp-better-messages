<?php
defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WordPlus_SDK_Visitor_Move' ) ) {
    final class WordPlus_SDK_Visitor_Move
    {
        const LINK_TTL = DAY_IN_SECONDS;

        private $sdk;

        public function __construct( WordPlus_SDK $sdk )
        {
            $this->sdk = $sdk;
        }

        public function link( $visitor, $user )
        {
            $token = bin2hex( random_bytes( 20 ) );

            set_transient( 'wordplus_move_' . hash( 'sha256', $token ), array( 'visitor' => (int) $visitor, 'user' => (int) $user ), self::LINK_TTL );

            return $token;
        }

        private function from_link( $token, $user )
        {
            $token = (string) $token;

            if ( 1 !== preg_match( '/^[a-f0-9]{40}$/', $token ) ) {
                return 0;
            }

            $kept = get_transient( 'wordplus_move_' . hash( 'sha256', $token ) );

            return is_array( $kept ) && (int) $kept['user'] === (int) $user && (int) $kept['visitor'] < 0 ? (int) $kept['visitor'] : 0;
        }

        public function visitor( $request = null )
        {
            $user = get_current_user_id();

            if ( $user <= 0 ) {
                return 0;
            }

            $link = is_object( $request ) && method_exists( $request, 'get_param' ) ? (string) $request->get_param( 'link' ) : '';

            if ( '' !== $link ) {
                return $this->from_link( $link, $user );
            }

            $visitor = (int) apply_filters( 'wordplus_move_visitor', 0, $user );

            if ( $visitor >= 0 ) {
                $visitor = (int) $this->sdk->visitors()->current();
            }

            if ( $visitor >= 0 ) {
                return 0;
            }

            $row = $this->sdk->visitors()->store()->find( -$visitor );

            if ( is_array( $row ) && ! empty( $row['user_id'] ) && (int) $row['user_id'] !== $user ) {
                return 0;
            }

            return $visitor;
        }

        public function items( $visitor, $user )
        {
            if ( $visitor >= 0 || $user <= 0 ) {
                return array();
            }

            $items = (array) apply_filters( 'wordplus_visitor_items', array(), (int) $visitor, (int) $user );

            return array_values( array_filter( array_map( function ( $item ) {
                return is_array( $item ) && ! empty( $item['label'] ) ? array( 'plugin' => (string) ( $item['plugin'] ?? '' ), 'label' => (string) $item['label'] ) : null;
            }, $items ) ) );
        }

        public function move( $visitor, $user )
        {
            if ( $visitor >= 0 || $user <= 0 ) {
                return false;
            }

            do_action( 'wordplus_visitor_move', (int) $visitor, (int) $user );

            $profiles = $this->sdk->profiles();

            if ( $profiles ) {
                $profiles->give_photo( $visitor, $user );
                $profiles->forget( $visitor );
            }

            if ( $this->sdk->addresses() ) {
                $this->sdk->addresses()->forget( $visitor );
            }

            return true;
        }

        public function forget( $visitor )
        {
            if ( $visitor >= 0 ) {
                return false;
            }

            do_action( 'wordplus_visitor_forget', (int) $visitor );

            if ( $this->sdk->profiles() ) {
                $this->sdk->profiles()->forget( $visitor );
            }

            if ( $this->sdk->addresses() ) {
                $this->sdk->addresses()->forget( $visitor );
            }

            return true;
        }

        public function routes()
        {
            register_rest_route( 'wordplus/v1', '/visitors/move', array(
                array(
                    'methods'             => 'GET',
                    'callback'            => array( $this, 'rest_items' ),
                    'permission_callback' => array( $this, 'is_member' ),
                ),
                array(
                    'methods'             => 'POST',
                    'callback'            => array( $this, 'rest_answer' ),
                    'permission_callback' => array( $this, 'is_member' ),
                ),
            ) );
        }

        public function is_member()
        {
            return get_current_user_id() > 0;
        }

        public function rest_items( $request )
        {
            $visitor = $this->visitor( $request );

            return array( 'visitor' => $visitor, 'items' => $this->items( $visitor, get_current_user_id() ) );
        }

        public function rest_answer( $request )
        {
            $visitor = $this->visitor( $request );
            $answer  = (string) $request->get_param( 'answer' );

            if ( $visitor >= 0 ) {
                return new WP_Error( 'wordplus_move_no_visitor', __( 'No visitor to move.', 'wordplus-sdk' ), array( 'status' => 404 ) );
            }

            if ( 'move' === $answer ) {
                $this->move( $visitor, get_current_user_id() );
            } else if ( 'forget' === $answer ) {
                $this->forget( $visitor );
            } else {
                return new WP_Error( 'wordplus_move_answer', __( 'Move or forget?', 'wordplus-sdk' ), array( 'status' => 400 ) );
            }

            $link = (string) $request->get_param( 'link' );

            if ( '' !== $link ) {
                delete_transient( 'wordplus_move_' . hash( 'sha256', $link ) );
            }

            return array( 'visitor' => $visitor, 'answer' => $answer );
        }
    }
}
