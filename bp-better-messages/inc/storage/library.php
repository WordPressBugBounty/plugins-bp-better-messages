<?php
defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Better_Messages_Storage_Library' ) ):

    class Better_Messages_Storage_Library
    {
        const PER_PAGE  = 20;
        const BATCH_MAX = 50;

        public static function instance()
        {
            static $instance = null;

            if ( null === $instance ) {
                $instance = new Better_Messages_Storage_Library();
            }

            return $instance;
        }

        public function __construct()
        {
            add_action( 'rest_api_init', array( $this, 'rest_api_init' ) );
        }

        private function storage()
        {
            return Better_Messages_Storage();
        }

        public function can_view()
        {
            return current_user_can( 'bm_can_administrate' );
        }

        public function can_manage()
        {
            return current_user_can( 'manage_options' );
        }

        public function rest_api_init()
        {
            register_rest_route( 'better-messages/v1/admin', '/library', array(
                'methods'             => 'GET',
                'callback'            => array( $this, 'rest_list' ),
                'permission_callback' => array( $this, 'can_view' ),
            ) );

            register_rest_route( 'better-messages/v1/admin', '/library/delete', array(
                'methods'             => 'POST',
                'callback'            => array( $this, 'rest_delete' ),
                'permission_callback' => array( $this, 'can_view' ),
            ) );

            register_rest_route( 'better-messages/v1/admin', '/library/retry', array(
                'methods'             => 'POST',
                'callback'            => array( $this, 'rest_retry' ),
                'permission_callback' => array( $this, 'can_view' ),
            ) );

            register_rest_route( 'better-messages/v1/admin', '/library/move', array(
                'methods'             => 'POST',
                'callback'            => array( $this, 'rest_move' ),
                'permission_callback' => array( $this, 'can_manage' ),
            ) );

            register_rest_route( 'better-messages/v1/admin', '/library/rebuild', array(
                'methods'             => 'POST',
                'callback'            => array( $this, 'rest_rebuild' ),
                'permission_callback' => array( $this, 'can_manage' ),
            ) );
        }

        private function ids_param( WP_REST_Request $request )
        {
            $ids = array_values( array_unique( array_filter( array_map( 'intval', (array) $request->get_param( 'ids' ) ) ) ) );

            return array_slice( $ids, 0, self::BATCH_MAX );
        }

        private function objects_table()
        {
            $sdk = $this->storage()->sdk();

            return $sdk && $sdk->table_exists() ? $sdk->table() : '';
        }

        private function from()
        {
            $objects = $this->objects_table();

            return "`{$this->storage()->table()}` f" . ( $objects !== '' ? " LEFT JOIN `{$objects}` o ON o.`id` = f.`object_id`" : " LEFT JOIN (SELECT NULL AS `id`, NULL AS `state`, NULL AS `location`, NULL AS `target`, NULL AS `error`, NULL AS `size`) o ON 1 = 0" );
        }

        public function where( WP_REST_Request $request )
        {
            global $wpdb;

            $where = array( $wpdb->prepare( 'f.`blog_id` = %d', get_current_blog_id() ), 'f.`attachment_id` IS NOT NULL' );

            switch ( (string) $request->get_param( 'type' ) ) {
                case 'images':
                    $where[] = "f.`mime` LIKE 'image/%' AND f.`kind` <> 'e2e'";
                    break;
                case 'videos':
                    $where[] = "f.`mime` LIKE 'video/%' AND f.`kind` NOT IN ('e2e','video')";
                    break;
                case 'audio':
                    $where[] = "f.`mime` LIKE 'audio/%' AND f.`kind` NOT IN ('e2e','voice')";
                    break;
                case 'voice':
                    $where[] = "f.`kind` = 'voice'";
                    break;
                case 'video-messages':
                    $where[] = "f.`kind` = 'video'";
                    break;
                case 'encrypted':
                    $where[] = "f.`kind` = 'e2e'";
                    break;
                case 'documents':
                    $where[] = "f.`mime` NOT LIKE 'image/%' AND f.`mime` NOT LIKE 'video/%' AND f.`mime` NOT LIKE 'audio/%' AND f.`kind` NOT IN ('e2e','voice','video')";
                    break;
            }

            $place = (string) $request->get_param( 'location' );

            if ( $place === 'local' ) {
                $where[] = "( o.`state` IS NULL OR o.`state` <> 'stored' )";
            } else if ( $place !== '' ) {
                $where[] = $wpdb->prepare( "o.`state` = 'stored' AND o.`location` = %s", $place );
            }

            switch ( (string) $request->get_param( 'state' ) ) {
                case 'moving':
                    $where[] = "( o.`state` IN ('pending_move','moving') OR o.`target` IS NOT NULL )";
                    break;
                case 'failed':
                    $where[] = "( o.`state` = 'failed' OR ( o.`error` IS NOT NULL AND o.`error` <> '' ) )";
                    break;
                case 'deleting':
                    $where[] = "o.`state` IN ('deleting','removing','delete_failed')";
                    break;
            }

            $search = trim( (string) $request->get_param( 'search' ) );

            if ( $search !== '' ) {
                $where[] = $wpdb->prepare( 'f.`name` LIKE %s', '%' . $wpdb->esc_like( $search ) . '%' );
            }

            $thread = (int) $request->get_param( 'thread' );

            if ( $thread > 0 ) {
                $where[] = $wpdb->prepare( 'f.`thread_id` = %d', $thread );
            }

            $user = $request->get_param( 'user' );

            if ( $user !== null && $user !== '' && (int) $user !== 0 ) {
                $where[] = $wpdb->prepare( 'f.`uploader_id` = %d', (int) $user );
            }

            foreach ( array( 'from' => '>=', 'to' => '<' ) as $param => $operator ) {
                $date = (string) $request->get_param( $param );

                if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
                    $time = (int) get_gmt_from_date( $date . ' 00:00:00', 'U' );

                    if ( $param === 'to' ) {
                        $time += DAY_IN_SECONDS;
                    }

                    $where[] = $wpdb->prepare( "f.`created_at` {$operator} %d", $time );
                }
            }

            return implode( ' AND ', $where );
        }

        private function admin_token()
        {
            $user_id = get_current_user_id();
            $b64     = function ( $data ) {
                return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' );
            };
            $payload = $b64( wp_json_encode( array( 'uid' => $user_id, 'iat' => (int) ( floor( time() / 43200 ) * 43200 ) ) ) );
            $key     = hash_hmac( 'sha256', Better_Messages()->functions->get_user_secret_key( $user_id ), wp_salt( 'auth' ) );

            return $payload . '.' . $b64( hash_hmac( 'sha256', $payload, $key, true ) );
        }

        public function file_urls( $attachment_id, $token )
        {
            $storage = $this->storage();

            if ( $storage->is_cloud_file( $attachment_id ) ) {
                $view     = $storage->signed_url( $attachment_id, 'inline', 604800 );
                $download = $storage->signed_url( $attachment_id, 'download', 604800 );

                return array(
                    'view'     => $view ? $view['url'] : '',
                    'download' => $download ? $download['url'] : ( $view ? $view['url'] : '' ),
                );
            }

            $path = get_attached_file( $attachment_id );

            if ( ! $path || ! file_exists( $path ) ) {
                return array( 'view' => '', 'download' => '' );
            }

            if ( Better_Messages()->settings['attachmentsProxy'] === '1' ) {
                $url = add_query_arg( 'token', rawurlencode( $token ), Better_Messages_Files()->get_proxy_url( $attachment_id ) );

                return array( 'view' => $url, 'download' => $url );
            }

            $url = wp_get_attachment_url( $attachment_id );

            return array( 'view' => $url, 'download' => $url );
        }

        public function place_labels()
        {
            $labels = array( '' => _x( 'This server', 'WP Admin', 'bp-better-messages' ) );
            $sdk    = $this->storage()->sdk();

            if ( $sdk ) {
                foreach ( $sdk->get_settings()['locations'] as $id => $location ) {
                    $labels[ $id ] = WordPlus_SDK_Storage::preset( $location['preset'] )['label'] . ': ' . $location['bucket'] . ( $location['prefix'] !== '' ? '/' . $location['prefix'] : '' );
                }
            }

            return $labels;
        }

        public function places()
        {
            $sdk = $this->storage()->sdk();

            return $sdk ? $sdk->queue()->places( Better_Messages_Storage::PLUGIN ) : array( array( 'id' => 'local', 'label' => _x( 'This server', 'WP Admin', 'bp-better-messages' ), 'files' => 0, 'bytes' => 0 ) );
        }

        public function summary()
        {
            global $wpdb;

            $storage = $this->storage();
            $states  = array( 'pending' => 0, 'failed' => 0, 'deleting' => 0 );
            $objects = $this->objects_table();

            if ( $objects !== '' ) {
                foreach ( $wpdb->get_results( $wpdb->prepare(
                    "SELECT `state`, ( `error` IS NOT NULL AND `error` <> '' ) AS `has_error`, ( `target` IS NOT NULL ) AS `queued`, COUNT(*) AS `files` FROM `{$objects}` WHERE `blog_id` = %d AND `plugin` = %s GROUP BY `state`, `has_error`, `queued`",
                    get_current_blog_id(), Better_Messages_Storage::PLUGIN
                ) ) as $row ) {
                    $deleting = in_array( $row->state, array( 'deleting', 'removing', 'delete_failed' ), true );
                    $queued   = (int) $row->queued && ! $deleting;

                    if ( $queued || in_array( $row->state, array( 'pending_move', 'moving' ), true ) ) $states['pending'] += (int) $row->files;
                    if ( $row->state === 'failed' || ( ( $deleting || $queued ) && (int) $row->has_error ) ) $states['failed'] += (int) $row->files;
                    if ( $deleting ) $states['deleting'] += (int) $row->files;
                }
            }

            $backfill = $storage->backfill_state();

            return array(
                'places'    => $this->places(),
                'states'    => $states,
                'indexed'   => (int) $backfill['indexed'],
                'indexDone' => (bool) $backfill['done'],
            );
        }

        public function rest_list( WP_REST_Request $request )
        {
            global $wpdb;

            $storage = $this->storage();

            if ( ! $storage->table_exists() ) {
                return array( 'files' => array(), 'total' => 0, 'pages' => 0, 'summary' => $this->summary(), 'canManage' => $this->can_manage() );
            }

            if ( ! $storage->backfill_done() ) {
                $storage->backfill( 3 );
            }

            $page     = max( 1, (int) $request->get_param( 'page' ) );
            $per_page = self::PER_PAGE;
            $where    = $this->where( $request );
            $order    = $request->get_param( 'order' ) === 'asc' ? 'ASC' : 'DESC';
            $sort     = $request->get_param( 'sort' ) === 'size' ? 'f.`size`' : 'f.`created_at`';
            $from     = $this->from();

            $total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$from} WHERE {$where}" );

            $rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT f.*, o.`state` AS `o_state`, o.`location` AS `o_location`, o.`target` AS `o_target`, o.`error` AS `o_error`, o.`size` AS `o_size`
                 FROM {$from} WHERE {$where} ORDER BY {$sort} {$order}, f.`id` {$order} LIMIT %d OFFSET %d",
                $per_page, ( $page - 1 ) * $per_page
            ) );

            $storage->prime( wp_list_pluck( $rows, 'attachment_id' ) );

            $labels = $this->place_labels();
            $token  = $this->admin_token();
            $users  = array();
            $files  = array();

            foreach ( $rows as $row ) {
                $attachment_id = (int) $row->attachment_id;
                $uploader      = (int) $row->uploader_id;
                $exists        = (bool) get_post( $attachment_id );
                $state         = $row->o_state === null ? 'local' : (string) $row->o_state;

                if ( ! isset( $users[ $uploader ] ) ) {
                    $item = $uploader !== 0 ? Better_Messages()->functions->rest_user_item( $uploader, false ) : null;
                    $users[ $uploader ] = $item ? array(
                        'id'     => $uploader,
                        'name'   => isset( $item['name'] ) ? html_entity_decode( (string) $item['name'], ENT_QUOTES, 'UTF-8' ) : '',
                        'avatar' => isset( $item['avatar'] ) ? (string) $item['avatar'] : '',
                    ) : array( 'id' => $uploader, 'name' => '', 'avatar' => '' );
                }

                $place  = in_array( $state, array( 'stored', 'deleting', 'removing', 'delete_failed' ), true ) ? (string) $row->o_location : '';
                $urls   = $exists ? $this->file_urls( $attachment_id, $token ) : array( 'view' => '', 'download' => '' );
                $target = $row->o_target === null ? null : (string) $row->o_target;

                $files[] = array(
                    'id'         => $attachment_id,
                    'name'       => (string) $row->name,
                    'mime'       => (string) $row->mime,
                    'size'       => $state === 'stored' && $row->o_size !== null ? (int) $row->o_size : (int) $row->size,
                    'kind'       => (string) $row->kind,
                    'state'      => $state,
                    'error'      => (string) $row->o_error,
                    'place'      => $place === '' ? 'local' : $place,
                    'placeName'  => isset( $labels[ $place ] ) ? $labels[ $place ] : $place,
                    'target'     => $target === null ? null : ( $target === '' ? 'local' : $target ),
                    'targetName' => $target === null ? '' : ( isset( $labels[ $target ] ) ? $labels[ $target ] : $target ),
                    'threadId'   => (int) $row->thread_id,
                    'messageId'  => (int) $row->message_id,
                    'threadUrl'  => (int) $row->thread_id > 0 ? Better_Messages()->functions->add_hash_arg( 'conversation/' . (int) $row->thread_id, array( 'scrollToContainer' => '' ), Better_Messages()->functions->get_link() ) : '',
                    'uploader'   => $users[ $uploader ],
                    'date'       => (int) $row->created_at,
                    'view'       => $urls['view'],
                    'download'   => $urls['download'],
                    'exists'     => $exists,
                );
            }

            return array(
                'files'     => $files,
                'total'     => $total,
                'pages'     => (int) ceil( $total / $per_page ),
                'summary'   => $this->summary(),
                'canManage' => $this->can_manage(),
            );
        }

        public function rest_delete( WP_REST_Request $request )
        {
            $deleted = 0;

            foreach ( $this->ids_param( $request ) as $attachment_id ) {
                if ( empty( get_post_meta( $attachment_id, 'bp-better-messages-attachment', true ) ) ) {
                    continue;
                }

                $references = count( get_post_meta( $attachment_id, 'bp-better-messages-message-id' ) );

                for ( $i = 0; $i < $references + 1 && get_post( $attachment_id ); $i++ ) {
                    if ( ! Better_Messages_Files()->remove_attachment( $attachment_id ) ) {
                        break;
                    }
                }

                if ( get_post( $attachment_id ) ) {
                    $path = get_attached_file( $attachment_id );
                    wp_delete_attachment( $attachment_id, true );

                    if ( $path ) {
                        Better_Messages_Files()->cleanup_empty_directories( $path );
                    }
                }

                $deleted++;

                do_action( 'better_messages_library_file_deleted', $attachment_id, get_current_user_id() );
            }

            return array( 'deleted' => $deleted );
        }

        private function pending_object( $attachment_id )
        {
            global $wpdb;

            $storage = $this->storage();
            $sdk     = $storage->sdk();

            if ( ! $sdk ) {
                return null;
            }

            $object = $storage->object( $attachment_id, true );

            if ( $object ) {
                return $object;
            }

            $row = $storage->get_row( $attachment_id );

            return $row && (int) $row->object_id > 0 ? $sdk->object( (int) $row->object_id ) : null;
        }

        public function rest_retry( WP_REST_Request $request )
        {
            $storage = $this->storage();
            $sdk     = $storage->sdk();
            $retried = 0;

            if ( ! $sdk ) {
                return array( 'retried' => 0 );
            }

            foreach ( $this->ids_param( $request ) as $attachment_id ) {
                $object = $this->pending_object( $attachment_id );
                $row    = $storage->get_row( $attachment_id );

                if ( ! $object ) {
                    continue;
                }

                $route = $row ? $storage->route( Better_Messages_Storage::file_group( $row->kind, $row->mime ) ) : null;

                if ( in_array( $object->state, array( 'deleting', 'delete_failed' ), true ) ) {
                    $sdk->update_row( $object->id, array( 'state' => 'deleting', 'attempts' => 0, 'next_attempt_at' => time(), 'error' => null ) );
                    $sdk->schedule( 0 );
                    $retried++;
                } else if ( $object->target !== null && in_array( $object->state, array( 'local', 'failed', 'stored' ), true ) ) {
                    $sdk->move( $object, (string) $object->target );
                    $retried++;
                } else if ( in_array( $object->state, array( 'failed', 'local' ), true ) && $route && $row && (int) $row->message_id > 0 ) {
                    $sdk->update_row( $object->id, array( 'state' => 'local' ) );
                    $sdk->send( $storage->sync_local_path( $sdk->object( $object->id ) ), $route['id'] );
                    $sdk->schedule( 0 );
                    $retried++;
                } else if ( (string) $object->error !== '' ) {
                    $sdk->update_row( $object->id, array( 'error' => null, 'attempts' => 0 ) );
                    $retried++;
                }
            }

            return array( 'retried' => $retried );
        }

        public function rest_move( WP_REST_Request $request )
        {
            $storage = $this->storage();
            $sdk     = $storage->sdk();
            $to      = (string) $request->get_param( 'to' );
            $to      = $to === 'local' ? '' : $to;

            if ( ! $sdk || ( $to !== '' && ! $sdk->get_location( $to ) ) ) {
                return new WP_Error( 'bm_storage_invalid', _x( 'That storage location no longer exists.', 'WP Admin', 'bp-better-messages' ), array( 'status' => 400 ) );
            }

            $queued = 0;

            foreach ( $this->ids_param( $request ) as $attachment_id ) {
                $object = $storage->object( $attachment_id, true );

                if ( ! $object || ! in_array( $object->state, array( 'local', 'failed', 'stored' ), true ) ) {
                    continue;
                }

                $place = $object->state === 'stored' ? (string) $object->location : '';

                if ( $place === $to ) {
                    if ( $object->target !== null ) {
                        $sdk->update_row( $object->id, array( 'target' => null ) );
                    }

                    continue;
                }

                $sdk->move( $object, $to );
                $queued++;
            }

            return array( 'queued' => $queued );
        }

        public function rest_rebuild( WP_REST_Request $request )
        {
            global $wpdb;

            $storage = $this->storage();

            if ( ! $storage->table_exists() ) {
                return $this->summary();
            }

            if ( $request->get_param( 'restart' ) ) {
                $objects = $this->objects_table();

                if ( $objects !== '' ) {
                    $wpdb->query( $wpdb->prepare(
                        "DELETE f FROM `{$storage->table()}` f LEFT JOIN `{$objects}` o ON o.`id` = f.`object_id` WHERE f.`blog_id` = %d AND f.`attachment_id` IS NOT NULL AND ( o.`id` IS NULL OR o.`state` IN ('local','stored','failed') )",
                        get_current_blog_id()
                    ) );
                } else {
                    $wpdb->query( $wpdb->prepare( "DELETE FROM `{$storage->table()}` WHERE `blog_id` = %d AND `attachment_id` IS NOT NULL", get_current_blog_id() ) );
                }

                delete_option( Better_Messages_Storage::BACKFILL_OPTION );
            }

            $storage->backfill( 15 );
            $storage->schedule_job();

            return $this->summary();
        }
    }

endif;

function Better_Messages_Storage_Library()
{
    return Better_Messages_Storage_Library::instance();
}
