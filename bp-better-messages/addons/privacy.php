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

    /**
     * Register data exporter.
     */
    public function register_exporter( $exporters ) {
        $exporters['better-messages'] = array(
            'exporter_friendly_name' => __( 'Better Messages', 'bp-better-messages' ),
            'callback'               => array( $this, 'export_personal_data' ),
        );
        return $exporters;
    }

    /**
     * Register data eraser.
     */
    public function register_eraser( $erasers ) {
        $erasers['better-messages'] = array(
            'eraser_friendly_name' => __( 'Better Messages', 'bp-better-messages' ),
            'callback'             => array( $this, 'erase_personal_data' ),
        );
        return $erasers;
    }

    /**
     * Export personal data for a user.
     */
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

        $messages_table = bm_get_table( 'messages' );
        $threads_table  = bm_get_table( 'threads' );

        if ( ! $messages_table || ! $threads_table ) {
            return array( 'data' => array(), 'done' => true );
        }

        $offset = ( $page - 1 ) * $per_page;

        // Get messages sent by this user
        $messages = $wpdb->get_results( $wpdb->prepare(
            "SELECT m.id, m.thread_id, m.message, m.date_sent, t.subject
             FROM {$messages_table} m
             LEFT JOIN {$threads_table} t ON m.thread_id = t.id
             WHERE m.sender_id = %d
             ORDER BY m.date_sent DESC
             LIMIT %d OFFSET %d",
            $user_id, $per_page, $offset
        ) );

        foreach ( $messages as $message ) {
            $data = array(
                array(
                    'name'  => __( 'Message', 'bp-better-messages' ),
                    'value' => wp_strip_all_tags( $message->message ),
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

    /**
     * Erase personal data for a user.
     * Anonymizes messages rather than deleting to preserve conversation context for other participants.
     */
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

        if ( ! $messages_table ) {
            return array(
                'items_removed'  => false,
                'items_retained' => false,
                'messages'       => array(),
                'done'           => true,
            );
        }

        // Find messages not yet anonymized (no <!-- BM-PRIVACY-REMOVED --> marker in content)
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

            // Delete attachment files if setting enabled
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

                    // Remove the attachment meta from the message
                    $wpdb->delete( $meta_table, array(
                        'bm_message_id' => $row->bm_message_id,
                        'meta_key'      => $row->meta_key,
                    ) );

                    // Bump message update time so clients re-sync
                    Better_Messages()->functions->update_message_update_time( $row->bm_message_id );
                }
            }

            // Anonymize message content
            $microtime = Better_Messages()->functions->get_microtime();
            $wpdb->query( $wpdb->prepare(
                "UPDATE {$messages_table}
                 SET message = %s, updated_at = %d
                 WHERE id IN ({$ids_placeholder})",
                '<!-- BM-PRIVACY-REMOVED -->' . __( '[Message removed for privacy]', 'bp-better-messages' ),
                $microtime
            ) );

            // Remove remaining message meta (reactions, etc.)
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

        return array(
            'items_removed'  => $items_removed,
            'items_retained' => $done, // recipients records retained
            'messages'       => $messages,
            'done'           => $done,
        );
    }

    /**
     * Add suggested privacy policy content.
     */
    public function add_privacy_policy_content() {
        if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
            return;
        }

        $sections = array();

        // Introduction
        $sections[] = sprintf(
            '<h3>%s</h3><p>%s</p>',
            __( 'Private Messaging', 'bp-better-messages' ),
            __( 'This site uses Better Messages for private messaging between users. The following describes what personal data is collected, how it is used, and your rights regarding that data.', 'bp-better-messages' )
        );

        // What data is collected
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

        // Guest users
        if ( Better_Messages()->settings['guestChat'] ?? false ) {
            $sections[] = sprintf(
                '<h4>%s</h4><p>%s</p>',
                __( 'Guest users', 'bp-better-messages' ),
                __( 'If you use the messaging feature as a guest (without an account), your display name, email address (if provided), and IP address are stored to identify you within the conversation.', 'bp-better-messages' )
            );
        }

        // Browser storage
        $sections[] = sprintf(
            '<h4>%s</h4><p>%s</p>',
            __( 'Browser storage', 'bp-better-messages' ),
            __( 'A copy of your recent messages and conversation data is cached in your browser (IndexedDB) for faster loading. This data stays on your device and is not shared. You can clear it by clearing your browser data.', 'bp-better-messages' )
        );

        // Third-party services
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

        if ( ! empty( Better_Messages()->settings['stipopApiKey'] ) ) {
            $third_party_items[] = __( 'Sticker images are loaded directly from Stipop\'s servers (img.stipop.io) when displayed in messages, which may receive your IP address.', 'bp-better-messages' );
        }

        if ( bpbm_fs()->is__premium_only() && ( Better_Messages()->settings['videoCalls'] ?? false ) ) {
            $third_party_items[] = __( 'Group voice and video calls are routed through a cloud service to connect participants. Private one-on-one calls are established directly between users.', 'bp-better-messages' );
        }

        if ( bpbm_fs()->is__premium_only() && Better_Messages()->realtime ) {
            $third_party_items[] = __( 'Real-time message delivery is handled through a cloud server (cloud.better-messages.com). Your messages are encrypted in transit and pass through this server for instant delivery.', 'bp-better-messages' );
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

        // AI Chat Bots
        if ( class_exists( 'Better_Messages_AI' ) && Better_Messages()->ai ) {
            $sections[] = sprintf(
                '<h4>%s</h4><p>%s</p>',
                __( 'AI Chat Bots', 'bp-better-messages' ),
                __( 'This site may use AI-powered chat bots. When you interact with an AI bot, your messages in that conversation are sent to the AI service provider (such as OpenAI, Anthropic, or Google) to generate responses.', 'bp-better-messages' )
            );
        }

        // Data retention and rights
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
