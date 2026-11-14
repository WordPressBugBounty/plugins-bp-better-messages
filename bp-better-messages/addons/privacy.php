<?php
defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Better_Messages_Privacy' ) ):

class Better_Messages_Privacy {

    public static function instance() {
        static $instance = null;
        if ( null === $instance ) {
            $instance = new Better_Messages_Privacy();
        }
        return $instance;
    }

    public function __construct() {
        add_filter( 'wp_privacy_personal_data_exporters', array( $this, 'register_exporter' ) );
        add_filter( 'wp_privacy_personal_data_erasers', array( $this, 'register_eraser' ) );
        add_action( 'admin_init', array( $this, 'add_privacy_policy_content' ) );
    }

    public function register_exporter( $exporters ) {
        $exporters['better-messages'] = array(
            'exporter_friendly_name' => __( 'Better Messages', 'bp-better-messages' ),
            'callback'               => array( $this, 'export_personal_data' ),
        );
        return $exporters;
    }

    public function register_eraser( $erasers ) {
        $erasers['better-messages'] = array(
            'eraser_friendly_name' => __( 'Better Messages', 'bp-better-messages' ),
            'callback'             => array( $this, 'erase_personal_data' ),
        );
        return $erasers;
    }

    public function export_personal_data( $email_address, $page = 1 ) {
        global $wpdb;

        $per_page = 50;
        $user = get_user_by( 'email', $email_address );

        if ( ! $user ) {
            return array(
                'data' => array(),
                'done' => true,
            );
        }

        $user_id = $user->ID;
        $export_items = array();

        if ( 1 === (int) $page ) {
            $export_items = $this->address_items( $user_id );
        }

        $messages_table = bm_get_table( 'messages' );
        $threads_table  = bm_get_table( 'threads' );

        if ( ! $messages_table || ! $threads_table ) {
            return array( 'data' => $export_items, 'done' => true );
        }

        $offset = ( $page - 1 ) * $per_page;

        $messages = $wpdb->get_results( $wpdb->prepare(
            "SELECT m.id, m.thread_id, m.message, m.date_sent, t.subject
             FROM {$messages_table} m
             LEFT JOIN {$threads_table} t ON m.thread_id = t.id
             WHERE m.sender_id = %d
             ORDER BY m.date_sent DESC
             LIMIT %d OFFSET %d",
            $user_id, $per_page, $offset
        ) );

        foreach ( $messages as $stored ) {
            $stored->message = Better_Messages()->encryption->open_message( $stored->message, $stored->thread_id );
            $stored->subject = Better_Messages()->encryption->open_subject( $stored->subject, $stored->thread_id );
        }

        foreach ( $messages as $message ) {
            $data = array(
                array(
                    'name'  => __( 'Message', 'bp-better-messages' ),
                    'value' => wp_strip_all_tags( Better_Messages_Mentions::mentions_as_names( $message->message ) ),
                ),
                array(
                    'name'  => __( 'Date Sent', 'bp-better-messages' ),
                    'value' => $message->date_sent,
                ),
            );

            if ( ! empty( $message->subject ) ) {
                array_unshift( $data, array(
                    'name'  => __( 'Conversation Subject', 'bp-better-messages' ),
                    'value' => wp_strip_all_tags( $message->subject ),
                ) );
            }

            $export_items[] = array(
                'group_id'    => 'better-messages',
                'group_label' => __( 'Messages', 'bp-better-messages' ),
                'item_id'     => 'message-' . $message->id,
                'data'        => $data,
            );
        }

        $done = count( $messages ) < $per_page;

        return array(
            'data' => $export_items,
            'done' => $done,
        );
    }

    private function address_items( $user_id ) {
        $addresses = function_exists( 'wordplus_addresses' ) ? wordplus_addresses() : null;

        if ( ! $addresses ) {
            return array();
        }

        $items = array();

        foreach ( $addresses->of( (int) $user_id, WordPlus_SDK_Addresses::KEEP ) as $row ) {
            $data = array(
                array(
                    'name'  => __( 'IP address', 'bp-better-messages' ),
                    'value' => $row['ip'],
                ),
            );

            if ( '' !== $row['country'] ) {
                $data[] = array( 'name' => __( 'Country', 'bp-better-messages' ), 'value' => $row['country'] );
            }

            if ( '' !== $row['region'] ) {
                $data[] = array( 'name' => __( 'Region', 'bp-better-messages' ), 'value' => $row['region'] );
            }

            if ( '' !== $row['city'] ) {
                $data[] = array( 'name' => __( 'City', 'bp-better-messages' ), 'value' => $row['city'] );
            }

            $data[] = array( 'name' => __( 'First seen', 'bp-better-messages' ), 'value' => gmdate( 'Y-m-d H:i:s', $row['first_seen'] ) . ' UTC' );
            $data[] = array( 'name' => __( 'Last seen', 'bp-better-messages' ), 'value' => gmdate( 'Y-m-d H:i:s', $row['last_seen'] ) . ' UTC' );

            $items[] = array(
                'group_id'    => 'better-messages-addresses',
                'group_label' => __( 'Addresses', 'bp-better-messages' ),
                'item_id'     => 'address-' . (int) $user_id . '-' . md5( $row['ip'] ),
                'data'        => $data,
            );
        }

        return $items;
    }

    private function erase_addresses( $user_id ) {
        $addresses = function_exists( 'wordplus_addresses' ) ? wordplus_addresses() : null;

        if ( ! $addresses ) {
            return false;
        }

        $had = count( $addresses->of( (int) $user_id, 1 ) ) > 0 || '' !== (string) get_user_meta( (int) $user_id, WordPlus_SDK_Addresses::META, true );

        $addresses->forget( (int) $user_id );
        delete_user_meta( (int) $user_id, WordPlus_SDK_Addresses::META );

        return $had;
    }

    public function erase_personal_data( $email_address, $page = 1 ) {
        global $wpdb;

        $per_page = 50;
        $user = get_user_by( 'email', $email_address );

        if ( ! $user ) {
            return array(
                'items_removed'  => false,
                'items_retained' => false,
                'messages'       => array(),
                'done'           => true,
            );
        }

        $user_id = $user->ID;
        $messages_table = bm_get_table( 'messages' );

        $addresses_removed = 1 === (int) $page ? $this->erase_addresses( $user_id ) : false;

        if ( ! $messages_table ) {
            return array(
                'items_removed'  => $addresses_removed,
                'items_retained' => false,
                'messages'       => $addresses_removed ? array( __( 'Addresses have been removed.', 'bp-better-messages' ) ) : array(),
                'done'           => true,
            );
        }

        $message_ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT id FROM {$messages_table}
             WHERE sender_id = %d AND message NOT LIKE %s
             ORDER BY id ASC
             LIMIT %d",
            $user_id, '%<!-- BM-PRIVACY-REMOVED -->%', $per_page
        ) );

        $items_removed = false;

        if ( ! empty( $message_ids ) ) {
            $ids_placeholder = implode( ',', array_map( 'intval', $message_ids ) );

            $meta_table = bm_get_table( 'meta' );

            if ( Better_Messages()->settings['privacyDeleteAttachments'] === '1' && $meta_table ) {
                $attachment_rows = $wpdb->get_results(
                    "SELECT bm_message_id, meta_key, meta_value FROM {$meta_table}
                     WHERE bm_message_id IN ({$ids_placeholder})
                     AND meta_key IN ('files', 'attachments')"
                );

                foreach ( $attachment_rows as $row ) {
                    $value = maybe_unserialize( $row->meta_value );
                    if ( is_array( $value ) ) {
                        $file_ids = ( $row->meta_key === 'attachments' ) ? array_keys( $value ) : $value;
                        foreach ( $file_ids as $file_id ) {
                            wp_delete_attachment( intval( $file_id ), true );
                        }
                    }

                    $wpdb->delete( $meta_table, array(
                        'bm_message_id' => $row->bm_message_id,
                        'meta_key'      => $row->meta_key,
                    ) );

                    Better_Messages()->functions->update_message_update_time( $row->bm_message_id );
                }
            }

            $microtime = Better_Messages()->functions->get_microtime();
            $wpdb->query( $wpdb->prepare(
                "UPDATE {$messages_table}
                 SET message = %s, updated_at = %d
                 WHERE id IN ({$ids_placeholder})",
                '<!-- BM-PRIVACY-REMOVED -->' . __( '[Message removed for privacy]', 'bp-better-messages' ),
                $microtime
            ) );

            if ( $meta_table ) {
                $wpdb->query(
                    "DELETE FROM {$meta_table} WHERE bm_message_id IN ({$ids_placeholder})"
                );
            }

            $items_removed = true;
        }

        $done = count( $message_ids ) < $per_page;

        $messages = array();
        if ( $items_removed ) {
            $messages[] = __( 'Message content has been anonymized.', 'bp-better-messages' );
        }
        if ( $done && $items_removed ) {
            $messages[] = __( 'Conversation participation records are retained so other participants\' conversation history remains intact.', 'bp-better-messages' );
        }

        if ( $addresses_removed ) {
            $messages[] = __( 'Addresses have been removed.', 'bp-better-messages' );
        }

        return array(
            'items_removed'  => $items_removed || $addresses_removed,
            'items_retained' => $done,
            'messages'       => $messages,
            'done'           => $done,
        );
    }

    public function add_privacy_policy_content() {
        if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
            return;
        }

        $sections = array();

        $sections[] = sprintf(
            '<h3>%s</h3><p>%s</p>',
            __( 'Private Messaging', 'bp-better-messages' ),
            __( 'This site uses Better Messages for private messaging between users. The following describes what personal data is collected, how it is used, and your rights regarding that data.', 'bp-better-messages' )
        );

        $sections[] = sprintf(
            '<h4>%s</h4><p>%s</p><ul>' .
            '<li>%s</li>' .
            '<li>%s</li>' .
            '<li>%s</li>' .
            '<li>%s</li>' .
            '<li>%s</li>' .
            '</ul>',
            __( 'What data is collected', 'bp-better-messages' ),
            __( 'When you use the messaging feature, the following data is collected and stored on this site:', 'bp-better-messages' ),
            __( 'Messages you send, including text content and file attachments', 'bp-better-messages' ),
            __( 'Conversation participation records (who you are in conversation with)', 'bp-better-messages' ),
            __( 'Message delivery and read timestamps', 'bp-better-messages' ),
            __( 'Your online status and last activity time', 'bp-better-messages' ),
            __( 'Emoji reactions you add to messages', 'bp-better-messages' )
        );

        if ( Better_Messages()->settings['guestChat'] ?? false ) {
            $sections[] = sprintf(
                '<h4>%s</h4><p>%s</p>',
                __( 'Guest users', 'bp-better-messages' ),
                __( 'If you use the messaging feature as a guest (without an account), your display name, your photo and email address (if you give them), and IP address are stored to identify you within the conversation. If you provide an email address, it is used to send you a confirmation link and, once confirmed, to notify you about unread messages and to let you sign in again.', 'bp-better-messages' )
            );
        }

        $sections[] = sprintf(
            '<h4>%s</h4><p>%s</p>',
            __( 'Cookies', 'bp-better-messages' ),
            __( 'No analytics or tracking cookies are set. When a guest or a customer without an account opens a link from an email of this site, a cookie keeps them signed in on that browser for 30 days, or until they sign out. During the website sign-in of the mobile app a cookie holds the sign-in request for up to 10 minutes.', 'bp-better-messages' )
        );

        $geo = function_exists( 'wordplus_geo' ) ? wordplus_geo() : null;

        if ( $geo && $geo->keeps_addresses() ) {
            $sections[] = sprintf(
                '<h4>%s</h4><p>%s</p>',
                __( 'Addresses and locations', 'bp-better-messages' ),
                sprintf(
                    __( 'The addresses you connect from (IP addresses), with the country and city they belong to, are kept for %d days to protect the site from abuse. Locations are determined on this site and no IP address is sent to an external service. Only administrators can see them.', 'bp-better-messages' ),
                    $geo->days()
                )
            );
        }

        $sections[] = sprintf(
            '<h4>%s</h4><p>%s</p>',
            __( 'Browser storage', 'bp-better-messages' ),
            __( 'A copy of your recent messages and conversation data is cached in your browser (IndexedDB) for faster loading. This data stays on your device and is not shared. You can clear it by clearing your browser data.', 'bp-better-messages' )
        );

        $third_party_items = array();

        if ( Better_Messages()->settings['oEmbedEnable'] === '1' && ( ! isset( Better_Messages()->settings['privacyEmbeds'] ) || Better_Messages()->settings['privacyEmbeds'] !== '1' ) ) {
            $third_party_items[] = __( 'Video links (YouTube, Vimeo, etc.) may load embedded players directly from those services, which allows them to collect data about your visit.', 'bp-better-messages' );
        }

        if ( Better_Messages()->settings['oEmbedEnable'] === '1' && isset( Better_Messages()->settings['privacyEmbeds'] ) && Better_Messages()->settings['privacyEmbeds'] === '1' ) {
            $third_party_items[] = __( 'Video links (YouTube, Vimeo, etc.) are shown as previews. The actual video loads from the third-party service only after you click to play it.', 'bp-better-messages' );
        }

        if ( isset( Better_Messages()->settings['emojiSpriteDelivery'] ) && Better_Messages()->settings['emojiSpriteDelivery'] === 'cdn' ) {
            $third_party_items[] = __( 'Emoji images are loaded from an external CDN (jsdelivr.net), which may receive your IP address.', 'bp-better-messages' );
        }

        if ( ! empty( Better_Messages()->settings['giphyApiKey'] ) ) {
            $third_party_items[] = __( 'GIF images are loaded directly from Giphy\'s servers (media.giphy.com) when displayed in messages, which may receive your IP address.', 'bp-better-messages' );
        }

        $captcha = Better_Messages()->guests->captcha_asked() ? wordplus_captcha()->provider() : null;

        if ( $captcha && 'work' !== $captcha->kind() ) {
            $third_party_items[] = sprintf(
                __( 'The sign-up form of guests is protected by %s, which receives your IP address and information about your browser to tell people from bots.', 'bp-better-messages' ),
                esc_html( $captcha->name() )
            );
        }

        if ( ! empty( Better_Messages()->settings['stipopApiKey'] ) ) {
            $third_party_items[] = __( 'Sticker images are loaded directly from Stipop\'s servers (img.stipop.io) when displayed in messages, which may receive your IP address.', 'bp-better-messages' );
        }

        if ( better_messages_is_websocket_build() && ( Better_Messages()->settings['videoCalls'] ?? false ) ) {
            $third_party_items[] = __( 'Group voice and video calls are routed through a cloud service to connect participants. Private one-on-one calls are established directly between users.', 'bp-better-messages' );
        }

        if ( better_messages_is_websocket_build() && Better_Messages()->realtime ) {
            $hosts = array_unique( array_filter( array(
                (string) wp_parse_url( Better_Messages()->websocket->server(), PHP_URL_HOST ),
                (string) wp_parse_url( Better_Messages()->websocket->api_server(), PHP_URL_HOST ),
            ) ) );

            $third_party_items[] = sprintf(
                /* translators: %s: the realtime server's host names, such as ws.wordplus.cloud, rest.wordplus.cloud */
                __( 'Real-time message delivery is handled through a cloud server (%s). Your messages are encrypted in transit and pass through this server for instant delivery.', 'bp-better-messages' ),
                esc_html( implode( ', ', $hosts ) )
            );
        }

        if ( ! empty( $third_party_items ) ) {
            $items_html = '';
            foreach ( $third_party_items as $item ) {
                $items_html .= '<li>' . $item . '</li>';
            }
            $sections[] = sprintf(
                '<h4>%s</h4><p>%s</p><ul>%s</ul>',
                __( 'Third-party services', 'bp-better-messages' ),
                __( 'Depending on the site configuration, the following third-party services may be used:', 'bp-better-messages' ),
                $items_html
            );
        }

        if ( class_exists( 'Better_Messages_AI' ) && Better_Messages()->ai ) {
            $sections[] = sprintf(
                '<h4>%s</h4><p>%s</p>',
                __( 'AI Chat Bots', 'bp-better-messages' ),
                __( 'This site may use AI-powered chat bots. When you interact with an AI bot, your messages in that conversation are sent to the AI service provider (such as OpenAI, Anthropic, or Google) to generate responses.', 'bp-better-messages' )
            );
        }

        $sections[] = sprintf(
            '<h4>%s</h4><p>%s</p>',
            __( 'Data retention and your rights', 'bp-better-messages' ),
            __( 'Your messages are stored as long as your account is active. You can request an export of your messaging data or request deletion of your messages through the privacy tools provided by this site (Tools → Export Personal Data / Erase Personal Data). When messages are erased, the content is replaced with a placeholder to preserve conversation context for other participants.', 'bp-better-messages' )
        );

        $content = implode( "\n", $sections );

        wp_add_privacy_policy_content( __( 'Better Messages', 'bp-better-messages' ), $content );
    }
}

endif;

function Better_Messages_Privacy() {
    return Better_Messages_Privacy::instance();
}
