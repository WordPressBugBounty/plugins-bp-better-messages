<?php
defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WordPlus_SDK_Polls_Rest' ) ) {
    final class WordPlus_SDK_Polls_Rest
    {
        private $polls;

        private $namespace;

        private $context;

        private $callbacks;

        public function __construct( WordPlus_SDK_Polls $polls, $namespace, $context, array $callbacks = array() )
        {
            $this->polls     = $polls;
            $this->namespace = trim( (string) $namespace, '/' );
            $this->context   = (string) $context;
            $this->callbacks = array_merge( array(
                'authorize' => function () {
                    return is_user_logged_in();
                },
                'user'      => function () {
                    return get_current_user_id();
                },
                'can_view'  => function () {
                    return true;
                },
                'can_vote'  => null,
                'can_add'   => null,
                'can_end'   => function ( $poll, $user ) {
                    return (int) $poll['creator_id'] === (int) $user;
                },
                'respond'   => function ( $poll, $user ) use ( $polls ) {
                    return array( 'state' => $polls->state( $poll ), 'member' => $polls->member( $poll, $user ) );
                },
                'user_item' => function ( $user ) {
                    return $user;
                },
            ), array_filter( $callbacks, 'is_callable' ) );

            if ( null === $this->callbacks['can_vote'] ) {
                $this->callbacks['can_vote'] = $this->callbacks['can_view'];
            }

            if ( null === $this->callbacks['can_add'] ) {
                $this->callbacks['can_add'] = $this->callbacks['can_vote'];
            }
        }

        public function register()
        {
            $object = '/polls/(?P<object_id>\d+)';
            $allow  = array( $this, 'authorize' );

            register_rest_route( $this->namespace, $object . '/vote', array(
                'methods'             => 'POST',
                'callback'            => array( $this, 'vote' ),
                'permission_callback' => $allow,
            ) );

            register_rest_route( $this->namespace, $object . '/options', array(
                'methods'             => 'POST',
                'callback'            => array( $this, 'add_option' ),
                'permission_callback' => $allow,
            ) );

            register_rest_route( $this->namespace, $object . '/end', array(
                'methods'             => 'POST',
                'callback'            => array( $this, 'end' ),
                'permission_callback' => $allow,
            ) );

            register_rest_route( $this->namespace, $object . '/voters', array(
                'methods'             => 'GET',
                'callback'            => array( $this, 'voters' ),
                'permission_callback' => $allow,
            ) );
        }

        public function authorize( $request )
        {
            return (bool) call_user_func( $this->callbacks['authorize'], $request );
        }

        public function vote( $request )
        {
            $found = $this->find( $request, 'can_vote' );

            if ( is_wp_error( $found ) ) {
                return $found;
            }

            list( $poll, $user ) = $found;

            $options = $request->get_param( 'options' );
            $poll    = $this->polls->vote( $poll, $user, null === $options ? array() : $options );

            return is_wp_error( $poll ) ? $poll : rest_ensure_response( call_user_func( $this->callbacks['respond'], $poll, $user, $request ) );
        }

        public function add_option( $request )
        {
            $found = $this->find( $request, 'can_add' );

            if ( is_wp_error( $found ) ) {
                return $found;
            }

            list( $poll, $user ) = $found;

            $poll = $this->polls->add_option( $poll, $user, $request->get_param( 'text' ) );

            return is_wp_error( $poll ) ? $poll : rest_ensure_response( call_user_func( $this->callbacks['respond'], $poll, $user, $request ) );
        }

        public function end( $request )
        {
            $found = $this->find( $request, 'can_end' );

            if ( is_wp_error( $found ) ) {
                return $found;
            }

            list( $poll, $user ) = $found;

            $poll = $this->polls->end( $poll, $user );

            return is_wp_error( $poll ) ? $poll : rest_ensure_response( call_user_func( $this->callbacks['respond'], $poll, $user, $request ) );
        }

        public function voters( $request )
        {
            $found = $this->find( $request, 'can_view' );

            if ( is_wp_error( $found ) ) {
                return $found;
            }

            list( $poll ) = $found;

            $page = $this->polls->voters( $poll, $request->get_param( 'option' ), null === $request->get_param( 'page' ) ? 1 : $request->get_param( 'page' ) );

            if ( is_wp_error( $page ) ) {
                return $page;
            }

            $page['users'] = array_map( $this->callbacks['user_item'], $page['users'] );

            return rest_ensure_response( $page );
        }

        private function find( $request, $may )
        {
            $poll = $this->polls->get( $this->context, (int) $request->get_param( 'object_id' ) );
            $user = call_user_func( $this->callbacks['user'] );

            if ( null === $poll || ! call_user_func( $this->callbacks['can_view'], $poll, $user ) ) {
                return new WP_Error( 'wordplus_poll_not_found', __( 'This poll is not there any more', 'wordplus-sdk' ), array( 'status' => 404 ) );
            }

            if ( 'can_view' !== $may && ! call_user_func( $this->callbacks[ $may ], $poll, $user ) ) {
                return new WP_Error( 'wordplus_poll_forbidden', __( 'Sorry, you cannot do that', 'wordplus-sdk' ), array( 'status' => 403 ) );
            }

            return array( $poll, $user );
        }
    }
}
