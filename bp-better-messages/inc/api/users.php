<?php
if ( ! class_exists( 'Better_Messages_Rest_Users' ) ):

    class Better_Messages_Rest_Users
    {

        public static function instance()
        {
            static $instance = null;

            if ( null === $instance ) {
                $instance = new Better_Messages_Rest_Users();
            }

            return $instance;
        }

        public function __construct(){
            add_action( 'rest_api_init', array( $this, 'rest_api_init' ) );
        }

        public function rest_api_init(){
            register_rest_route( 'better-messages/v1', '/getUsers', array(
                'methods'             => 'GET',
                'callback'            => array( $this, 'get_users' ),
                'permission_callback' => '__return_true',
            ) );
        }

        public function get_users( WP_REST_Request $request ){
            global $wpdb;

            $current_user_id = Better_Messages()->functions->get_current_user_id();

            $mode = isset( Better_Messages()->settings['widgetUsersDisplayMode'] )
                ? Better_Messages()->settings['widgetUsersDisplayMode']
                : 'all';

            $roles = isset( Better_Messages()->settings['widgetUsersRoles'] ) && is_array( Better_Messages()->settings['widgetUsersRoles'] )
                ? array_values( array_filter( array_map( 'strval', Better_Messages()->settings['widgetUsersRoles'] ) ) )
                : array();

            $ids = isset( Better_Messages()->settings['widgetUsersIds'] ) && is_array( Better_Messages()->settings['widgetUsersIds'] )
                ? array_values( array_filter( array_map( 'intval', Better_Messages()->settings['widgetUsersIds'] ) ) )
                : array();

            $sort_by_raw = isset( Better_Messages()->settings['widgetUsersSortBy'] )
                ? Better_Messages()->settings['widgetUsersSortBy']
                : 'last_active';
            $sort_by = in_array( $sort_by_raw, array( 'last_active', 'display_name', 'newest' ), true )
                ? $sort_by_raw
                : 'last_active';

            $search   = trim( (string) $request->get_param( 'search' ) );
            $page     = max( 1, (int) $request->get_param( 'page' ) );
            $per_page = (int) $request->get_param( 'per_page' );
            if ( $per_page <= 0 || $per_page > 100 ) $per_page = 20;

            $users_table  = bm_get_table( 'users' );
            $roles_table  = bm_get_table( 'roles' );
            $guests_table = bm_get_table( 'guests' );

            $joins       = array();
            $where_parts = array();
            $params      = array();

            $joins['wp']     = "LEFT JOIN `{$wpdb->users}` wp_u ON wp_u.`ID` = bm_u.`ID`";
            $joins['guests'] = "LEFT JOIN `{$guests_table}` bm_g ON bm_g.`id` = -bm_u.`ID`";

            $where_parts[] = '( '
                . '( bm_u.`ID` > 0 AND wp_u.`ID` IS NOT NULL )'
                . ' OR ( bm_u.`ID` < 0 AND bm_g.`id` IS NOT NULL AND bm_g.`deleted_at` IS NULL'
                .       ' AND ( bm_g.`ip` IS NULL OR bm_g.`ip` NOT LIKE %s ) )'
                . ' )';
            $params[] = 'ai-chat-bot-%';

            if ( $current_user_id !== 0 ) {
                $where_parts[] = 'bm_u.`ID` != %d';
                $params[]      = $current_user_id;
            }

            $specific_order_ids = array();

            if ( $mode === 'roles' ) {
                if ( empty( $roles ) ) {
                    return array( 'users' => array(), 'page' => $page, 'pages' => 0, 'total' => 0 );
                }
                $placeholders   = implode( ',', array_fill( 0, count( $roles ), '%s' ) );
                $joins['roles'] = "INNER JOIN `{$roles_table}` bm_r ON bm_r.`user_id` = bm_u.`ID`";
                $where_parts[]  = "bm_r.`role` IN ({$placeholders})";
                foreach ( $roles as $r ) $params[] = $r;
            } elseif ( $mode === 'specific' ) {
                if ( empty( $ids ) ) {
                    return array( 'users' => array(), 'page' => $page, 'pages' => 0, 'total' => 0 );
                }
                $valid_ids = array_values( array_filter( $ids, function( $id ){
                    return (int) $id !== 0;
                } ) );
                if ( empty( $valid_ids ) ) {
                    return array( 'users' => array(), 'page' => $page, 'pages' => 0, 'total' => 0 );
                }
                $placeholders  = implode( ',', array_fill( 0, count( $valid_ids ), '%d' ) );
                $where_parts[] = "bm_u.`ID` IN ({$placeholders})";
                foreach ( $valid_ids as $id ) $params[] = (int) $id;
                $specific_order_ids = $valid_ids;
            }

            if ( $search !== '' ) {
                $like = '%' . $wpdb->esc_like( $search ) . '%';
                $where_parts[] = '( bm_u.`display_name` LIKE %s OR bm_u.`user_nicename` LIKE %s OR bm_u.`first_name` LIKE %s OR bm_u.`last_name` LIKE %s OR bm_u.`nickname` LIKE %s )';
                array_push( $params, $like, $like, $like, $like, $like );
            }

            $orderby_params = array();
            if ( $mode === 'specific' && ! empty( $specific_order_ids ) ) {
                $field_placeholders = implode( ',', array_fill( 0, count( $specific_order_ids ), '%d' ) );
                $orderby            = "FIELD(bm_u.`ID`, {$field_placeholders})";
                foreach ( $specific_order_ids as $id ) $orderby_params[] = (int) $id;
            } else {
                switch ( $sort_by ) {
                    case 'display_name':
                        $orderby = 'bm_u.`display_name` ASC';
                        break;
                    case 'newest':
                        $joins['wp'] = "INNER JOIN `{$wpdb->users}` wp_u ON wp_u.`ID` = bm_u.`ID`";
                        $orderby     = 'wp_u.`user_registered` DESC, bm_u.`ID` DESC';
                        break;
                    case 'last_active':
                    default:
                        $orderby = 'bm_u.`last_activity` IS NULL, bm_u.`last_activity` DESC, bm_u.`display_name` ASC';
                        break;
                }
            }

            $join_sql  = implode( ' ', $joins );
            $where_sql = implode( ' AND ', $where_parts );

            $count_sql = "SELECT COUNT(DISTINCT bm_u.`ID`) FROM `{$users_table}` bm_u {$join_sql} WHERE {$where_sql}";
            $total     = (int) ( $params
                ? $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) )
                : $wpdb->get_var( $count_sql ) );

            $offset       = ( $page - 1 ) * $per_page;
            $select_sql   = "SELECT DISTINCT bm_u.`ID` FROM `{$users_table}` bm_u {$join_sql} WHERE {$where_sql} ORDER BY {$orderby} LIMIT %d OFFSET %d";
            $select_params = array_merge( $params, $orderby_params, array( $per_page, $offset ) );

            $user_ids = $wpdb->get_col( $wpdb->prepare( $select_sql, $select_params ) );

            $users = array();
            foreach ( $user_ids as $user_id ) {
                $item = Better_Messages()->functions->rest_user_item( (int) $user_id );
                if ( $item ) {
                    $users[] = $item;
                }
            }

            return array(
                'users' => $users,
                'page'  => $page,
                'pages' => $per_page > 0 ? (int) ceil( $total / $per_page ) : 0,
                'total' => $total,
            );
        }
    }


    function Better_Messages_Rest_Users(){
        return Better_Messages_Rest_Users::instance();
    }
endif;
