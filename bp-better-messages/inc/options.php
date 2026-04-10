<?php
defined( 'ABSPATH' ) || exit;
class Better_Messages_Options
{
    protected  $path ;
    public  $settings ;
    public $defaults;

    public static function instance()
    {
        static  $instance = null ;

        if ( null === $instance ) {
            $instance = new Better_Messages_Options();
            $instance->setup_globals();
            $instance->setup_actions();
        }

        return $instance;
    }

    public function setup_globals()
    {
        $this->path = Better_Messages()->path . '/views/';
        $this->defaults = array(
            'mechanism'                   => 'ajax',
            'template'                    => 'modern',
            'thread_interval'             => 3,
            'site_interval'               => 10,
            'attachmentsFormats'          => array('jpg','jpeg','jpe','png','gif','webp','avif','heic','mp3','m4a','ogg','wav','flac','mp4','mov','webm','pdf','doc','docx','xls','xlsx','ppt','pptx','odt','txt','rtf','csv','zip'),
            'attachmentsRetention'        => 365,
            'attachmentsEnable'           => '0',
            'attachmentsHide'             => '1',
            'attachmentsProxy'            => '0',
            'attachmentsProxyMethod'      => 'php',
            'attachmentsXAccelPrefix'     => '/bm-files/',
            'attachmentsMaxSize'          => wp_max_upload_size() / 1024 / 1024,
            'attachmentsMaxNumber'        => 0,
            'attachmentsUploadMethod'     => 'post',
            'attachmentsRandomizeFilenames'     => '0',
            'attachmentsBrowserEnable'    => '0',
            'transcodingImageFormat'      => 'original',
            'transcodingImageQuality'     => 85,
            'transcodingImageMaxResolution' => 0,
            'transcodingStripMetadata'    => '0',
            'transcodingVideoFormat'      => 'original',
            'miniChatsEnable'             => '0',
            'miniWidgetsStyle'            => 'classic',
            'miniWidgetsAnimation'        => '1',
            'bubbleChatHeads'             => '0',
            'bubbleChatHeadsLimit'        => '5',
            'bubbleIcon'                  => 'comment',
            'bubbleCloseOnOutside'        => '0',
            'combinedChatsEnable'         => '0',
            'searchAllUsers'              => '1',
            'disableSubject'              => '0',
            'disableEnterForTouch'        => '1',
            'autoFullScreen'              => '1',
            'tapToOpenMsg'                => '1',
            'mobileSwipeBack'             => '1',
            'mobilePopup'                 => '0',
            'mobileFullScreen'            => '1',
            'chatPage'                    => '0',
            'wooCommerceMessagesSlug'     => 'messages',
            'messagesStatus'              => '0',
            'messagesStatusList'          => '0',
            'messagesStatusDetailed'      => '0',
            'allowDeleteMessages'         => '0',
            'deleteMethod'                => 'delete',
            'fastStart'                   => '1',
            'miniThreadsEnable'           => '0',
            'miniFriendsEnable'           => '0',
            'friendsMode'                 => '0',
            'singleThreadMode'            => '0',
            'newThreadMode'               => '0',
            'disableGroupThreads'         => '0',
            'oEmbedEnable'                => '1',
            'disableEnterForDesktop'      => '0',
            'rateLimitReply'              => [],
            'rateLimitReplyMessage'       => 'Your limit for replies is exceeded',
            'restrictNewThreads'          => [],
            'restrictNewThreadsMessage'   => 'You are not allowed to start new conversations',
            'restrictBadWordsList'        => 'Your message contains a word from blacklist',
            'restrictNewThreadsRemoveNewThreadButton' => '0',
            'restrictNewReplies'          => [],
            'restrictNewRepliesMessage'   => 'You are not allowed to continue conversation',
            'restrictCalls'               => [],
            'restrictCallsMessage'        => 'You are not allowed to make a call',
            'restrictViewMessages'        => [],
            'restrictViewMessagesMessage' => 'Message hidden',
            'restrictViewMiniThreads'     => [],
            'restrictViewMiniFriends'     => [],
            'restrictViewMiniGroups'      => [],
            'restrictMobilePopup'         => [],
            'videoCalls'                  => '0',
            'audioCalls'                  => '0',
            'userListButton'              => '0',
            'UMuserListButton'            => '1',
            'combinedView'                => '1',
            'enablePushNotifications'     => '0',
            'pushNotificationsLogic'      => 'offline',
            'colorGeneral'                => '#21759b',
            'encryptionEnabled'           => '1',
            'encryptionLocal'             => '0',
            'e2eEncryption'               => '0',
            'e2eDefault'                  => '0',
            'e2eForceSend'                => '0',
            'e2eAllowGuests'              => '0',
            'stickersProvider'            => '',
            'stickerPacksHash'            => '',
            'stickerSuggestions'          => '0',
            'stipopApiKey'                => '',
            'stipopLanguage'              => 'en',
            'allowMuteThreads'            => '1',
            'mentionsForceNotifications'  => '0',
            'callsRevertIcons'            => '0',
            'callRequestTimeLimit'        => '30',
            'callsLimitFriends'           => '0',
            'stopBPNotifications'         => '0',
            'restrictThreadsDeleting'     => '0',
            'disableFavoriteMessages'     => '0',
            'disableSearch'               => '0',
            'enableUnreadFilter'          => '0',
            'disableUserSettings'         => '0',
            'disableNewThread'            => '0',
            'profileVideoCall'            => '0',
            'profileAudioCall'            => '0',
            'miniChatAudioCall'           => '0',
            'miniChatVideoCall'           => '0',
            'disableUsersSearch'          => '0',
            'fixedHeaderHeight'           => '0',
            'mobilePopupLocationBottom'   => 20,
            'rateLimitNewThread'          => 0,
            'notificationsInterval'       => 15,
            'disableOnSiteNotification'   => '0',
            'allowSoundDisable'           => '1',
            'enableGroups'                => '0',
            'enableMiniGroups'            => '0',
            'allowGroupLeave'             => '0',
            'gifsProvider'                => '',
            'giphyApiKey'                 => '',
            'giphyContentRating'          => 'g',
            'giphyLanguage'               => 'en',
            'klipyApiKey'                 => '',
            'klipyLocale'                 => 'en',
            'enableReplies'               => '1',
            'enableSelfReplies'           => '0',
            'messagesMinHeight'           => 450,
            'messagesHeight'              => 650,
            'sideThreadsWidth'            => 320,
            'sidebarCompactMode'          => 'auto',
            'sidebarUserToggle'           => '1',
            'sidebarCompactBreakpoint'    => 0,
            'sidebarHideBreakpoint'       => 0,

            'notificationSound'           => 100,
            'notificationSoundId'         => 0,
            'notificationSoundUrl'        => '',

            'sentSound'                   => 50,
            'sentSoundId'                 => 0,
            'sentSoundUrl'                => '',

            'callSound'                   => 100,
            'callSoundId'                 => 0,
            'callSoundUrl'                => '',

            'dialingSound'                => 50,
            'dialingSoundId'              => 0,
            'dialingSoundUrl'             => '',

            'modernLayout'                => 'left',
            'deletedBehaviour'            => 'ignore',
            'unreadCounter'               => 'messages',
            'allowEditMessages'           => '0',
            'enableNiceLinks'             => '1',
            'userStatuses'                => '0',
            'myProfileButton'             => '1',
            'titleNotifications'          => '1',
            'enableMiniCloseButton'       => '0',
            'bpProfileSlug'               => 'bp-messages',
            'bpGroupSlug'                 => 'bp-messages',
            'mobilePopupLocation'         => 'right',
            'mobileOnsiteLocation'        => 'auto',
            'badWordsList'                => '',
            'badWordsSkipAdmins'          => '0',
            'groupCallsGroups'            => '0',
            'groupCallsThreads'           => '0',
            'groupCallsChats'             => '0',
            'groupAudioCallsGroups'       => '0',
            'groupAudioCallsThreads'      => '0',
            'groupAudioCallsChats'        => '0',
            'allowUsersRestictNewThreads' => '0',
            'enableGroupsEmails'          => '1',
            'enableGroupsPushs'           => '0',
            'desktopFullScreen'           => '1',
            'restrictRoleBlock'           => [],
            'restrictRoleType'            => 'allow',
            'restrictRoleMessage'         => 'You are not allowed to send messages',
            'friendsOnSiteNotifications'  => '0',
            'groupsOnSiteNotifications'   => '0',
            'enableUsersSuggestions'      => '1',
            'hidePossibleBreakingElements'  => '0',

            'pointsSystem'                  => 'none',
            'myCredPointType'               => '',

            'myCredNewMessageCharge'        => [],
            'myCredNewMessageChargeTypes'   => ['thread', 'group', 'chat-room'],
            'myCredNewMessageChargeMessage' => 'Not enough points to send a new message.',
            'myCredNewThreadCharge'         => [],
            'myCredNewThreadChargeTypes'    => ['thread', 'group'],
            'myCredNewThreadChargeMessage'  => 'Not enough points to start a new conversation.',
            'myCredCallPricing'         => [],
            'myCredCallPricingStartMessage'  => 'Not enough points to start new call',
            'myCredCallPricingEndMessage'    => 'Not enough points to continue the call',
            'myCredLogNewMessage'            => 'Better Messages for message #{id}',
            'myCredLogNewThread'             => 'Better Messages for new conversation #{id}',
            'myCredLogCallUsage'             => 'Better Messages for call usage #{id}',

            'pointsBalanceHeader'               => '0',
            'pointsBalanceThreadsList'          => '0',
            'pointsBalanceThreadsListBottom'    => '0',
            'pointsBalanceUserMenu'             => '0',
            'pointsBalanceUserMenuPopup'        => '0',
            'pointsBalanceReplyForm'            => '0',
            'pointsBalanceUrl'                  => '',
            'GamiPressPointType'               => '',
            'GamiPressNewMessageCharge'        => [],
            'GamiPressNewMessageChargeTypes'   => ['thread', 'group', 'chat-room'],
            'GamiPressNewMessageChargeMessage' => 'Not enough points to send a new message.',
            'GamiPressNewThreadCharge'         => [],
            'GamiPressNewThreadChargeTypes'    => ['thread', 'group'],
            'GamiPressNewThreadChargeMessage'  => 'Not enough points to start a new conversation.',
            'GamiPressCallPricing'             => [],
            'GamiPressCallPricingStartMessage' => 'Not enough points to start new call',
            'GamiPressCallPricingEndMessage'   => 'Not enough points to continue the call',
            'GamiPressLogNewMessage'           => 'Better Messages: {user} deducted {points} {points_type} for message #{id} for a new total of {total_points} {points_type}',
            'GamiPressLogNewThread'            => 'Better Messages: {user} deducted {points} {points_type} for new conversation #{id} for a new total of {total_points} {points_type}',
            'GamiPressLogCallUsage'            => 'Better Messages: {user} deducted {points} {points_type} for call usage #{id} for a new total of {total_points} {points_type}',
            'createEmailTemplate'           => '1',
            // Email template customization
            'emailTemplateSource'           => 'buddypress',  // 'buddypress' or 'custom' (for BP sites)
            'emailTemplateMode'             => 'simple',      // 'simple' or 'custom'
            'emailLogoId'                   => 0,             // Attachment ID for logo
            'emailLogoUrl'                  => '',            // URL of the logo
            'emailPrimaryColor'             => '#21759b',     // Primary/button color
            'emailBackgroundColor'          => '#f6f6f6',     // Background color
            'emailContentBgColor'           => '#ffffff',     // Content area background
            'emailTextColor'                => '#333333',     // Text color
            'emailHeaderText'               => '',            // Custom header text (empty = default "Hi {name}")
            'emailFooterText'               => '',            // Custom footer text
            'emailButtonText'               => '',            // Custom button text (empty = default)
            'emailCustomHtml'               => '',            // Full custom HTML template
            'emailUnsubscribeLink'          => '0',           // Show unsubscribe link in message emails
            'notificationsOfflineDelay'     => 15,
            'bbPressAuthorDetailsLink'      => '0',
            'enableGroupsFiles'             => '0',
            'combinedFriendsEnable'         => '0',
            'mobileFriendsEnable'           => '0',
            'combinedGroupsEnable'          => '0',
            'mobileGroupsEnable'            => '0',
            'umProfilePMButton'             => '1',
            'umOnlyFriendsMode'             => '0',
            'umOnlyFollowersMode'           => '0',
            'allowUsersBlock'               => '0',
            'allowReports'                  => '0',
            'restrictBlockUsers'            => [],
            'restrictBlockUsersImmun'       => [],
            'messagesViewer'                => '1',
            'enableReactions'               => '1',
            'enableReactionsPopup'          => '1',

            'UMminiFriendsEnable'           => '0',
            'UMcombinedFriendsEnable'       => '0',
            'UMmobileFriendsEnable'         => '0',
            'UMenableGroups'                => '0',
            'UMenableGroupsFiles'           => '0',
            'UMenableGroupsEmails'          => '0',
            'UMenableGroupsPushs'           => '0',
            'UMminiGroupsEnable'            => '0',
            'UMcombinedGroupsEnable'        => '0',
            'UMmobileGroupsEnable'          => '0',

            'peepsoHeader'                  => '1',
            'peepsoProfileVideoCall'        => '0',
            'peepsoProfileAudioCall'        => '0',
            'PSonlyFriendsMode'             => '0',
            'PSminiFriendsEnable'           => '0',
            'PScombinedFriendsEnable'       => '0',
            'PSmobileFriendsEnable'         => '0',
            'PSenableGroups'                => '0',
            'PSenableGroupsFiles'           => '0',
            'PSenableGroupsEmails'          => '0',
            'PSenableGroupsPushs'           => '0',
            'PSminiGroupsEnable'            => '0',
            'PScombinedGroupsEnable'        => '0',
            'PSmobileGroupsEnable'          => '0',

            'FcFullScreen'                  => '1',
            'FcPageTitle'                   => '0',
            'FCenableMessageButton'         => '1',
            'FCProfileVideoCall'            => '0',
            'FCProfileAudioCall'            => '0',
            'FCenableGroups'                => '1',
            'FCenableGroupsFiles'           => '0',
            'FCenableGroupsEmails'          => '0',
            'FCenableGroupsPushs'           => '0',
            'FCminiGroupsEnable'            => '0',
            'FCcombinedGroupsEnable'        => '0',
            'FCmobileGroupsEnable'          => '0',

            'SDenableProfileButton'         => '1',
            'SDenableAuthorButton'          => '1',
            'SDenableSidebarMessages'       => '1',
            'SDenableDropdownMessages'      => '1',
            'SDProfileVideoCall'            => '0',
            'SDProfileAudioCall'            => '0',

            'privateThreadInvite'           => '0',
            'reactionsEmojies'              => Better_Messages_Reactions::get_default_reactions(),
            'bpForceMiniChat'               => '0',
            'umForceMiniChat'               => '0',
            'psForceMiniChat'               => '0',
            'emojiSet'                      => 'apple',
            'smileToEmoji'                  => '1',
            'emojiPicker'                   => '1',
            'emojiSpriteDelivery'           => 'cdn',
            'privacyEmbeds'                 => '0',
            'privacyDeleteAttachments'      => '1',
            'attachmentsAllowPhoto'         => '0',
            'onsitePosition'                => 'right',
            'bpFallback'                    => '0',
            'miniChatDisableSync'           => '0',
            'pinnedThreads'                 => '1',
            'enableDrafts'                  => '1',
            'bpAppPush'                     => '0',
            'guestChat'                     => '0',
            'deleteMessagesOnUserDelete'    => '0',
            'dokanIntegration'              => '0',
            'MultiVendorXIntegration'       => '0',
            'wcVendorsIntegration'          => '0',
            'wcfmIntegration'               => '0',
            'wooCommerceIntegration'                  => '0',
            'wooCommerceProductButton'                => '1',
            'wooCommerceProductButtonPlacement'       => 'before_summary',
            'wooCommerceOrderButton'                  => '1',
            'wooCommerceOrderButtonPlacement'         => 'after_order_table',
            'wooCommercePrePurchaseButton'            => '0',
            'wooCommercePrePurchaseCartPlacement'     => 'after_cart_table',
            'wooCommercePrePurchaseCheckoutPlacement' => 'after_order_summary',
            'wooCommerceMyAccountLink'                => '1',
            'wooCommerceProductSupportUser'           => 0,
            'wooCommerceOrderSupportUser'             => 0,
            'wooCommercePrePurchaseSupportUser'       => 0,
            'jetEngineAvatars'              => '0',
            'hivepressIntegration'          => '0',
            'hivepressMenuItem'             => '0',
            'redirectUnlogged'              => '0',
            'wpJobManagerIntegration'       => '0',
            'pinnedMessages'                => '0',
            'privateReplies'                => '0',
            'enableForwardMessages'         => '0',
            'forwardMessagesAttribution'    => '0',
            'openAiApiKey'                  => '',
            'anthropicApiKey'               => '',
            'geminiApiKey'                  => '',
            'voiceTranscription'            => '0',
            'voiceTranscriptionProvider'    => 'openai',
            'voiceTranscriptionLanguage'    => '',
            'voiceTranscriptionModel'       => 'gpt-4o-mini-transcribe',
            'voiceTranscriptionPrompt'      => '',

            'voiceMessagesMaxDuration'      => 0,
            'voiceMessagesAutoDelete'       => 0,
            'voiceMessagesAutoDeleteMode'   => 'complete',
            'restrictVoiceMessages'         => [],

            'deleteOldMessages'             => 0,
            'suggestedConversations'        => [],

            'messagesPremoderation'         => '0',
            'messagesPremoderationRolesNewConv'    => [],
            'messagesPremoderationRolesReplies'    => [],
            'messagesModerateFirstTimeSenders'     => '0',
            'messagesModerationNotificationEmails' => '',

            'aiModerationProvider'          => 'openai',
            'aiModerationEnabled'           => '0',
            'aiModerationAction'            => 'flag',
            'aiModerationImages'            => '0',
            'aiModerationCategories'        => ['hate', 'harassment', 'sexual', 'violence', 'self-harm', 'illicit'],
            'aiModerationCustomRules'       => '',
            'aiModerationContextMessages'   => '0',
            'aiModerationThreshold'         => '0.5',
            'aiModerationBypassRoles'       => [],

            'aiTranslationEnabled'          => '0',
            'aiTranslationLanguages'        => [],

            'miniWidgetsOrder'              => [],
            'sidePanelTabsOrder'            => [],
            'mobileTabsOrder'               => []
        );

        $args = get_option( 'bp-better-chat-settings', array() );

        if ( ! Better_Messages()->functions->can_use_premium_code() || ! bpbm_fs()->is_premium() ) {
            $args['mechanism'] = 'ajax';
            $args['miniChatsEnable'] = '0';
            $args['combinedChatsEnable'] = '0';
            $args['messagesStatus'] = '0';
            $args['messagesStatusList'] = '0';
            $args['messagesStatusDetailed'] = '0';
            $args['miniThreadsEnable'] = '0';
            $args['videoCalls'] = '0';
            $args['audioCalls'] = '0';
            $args['encryptionEnabled'] = '0';
            $args['encryptionLocal'] = '0';
            $args['e2eEncryption'] = '0';
            $args['e2eDefault'] = '0';
            $args['e2eForceSend'] = '0';
            $args['e2eAllowGuests'] = '0';
            $args['userStatuses'] = '0';
        }

        if( Better_Messages()->functions->can_use_premium_code() && bpbm_fs()->is_premium() ){
            $args['mechanism'] = 'websocket';
            $args['encryptionEnabled'] = '1';
        }

        if( ! is_admin() && current_user_can( 'manage_options') ){
            $args['disableUsersSearch'] = '0';
        }

        if( isset($args['disableUsersSearch']) && $args['disableUsersSearch'] === '1' ){
            $args['searchAllUsers'] = '0';
            $args['enableUsersSuggestions'] = '0';
        }

        if( ! isset($args['messagesViewer']) || $args['messagesViewer'] === '0' ){
            $args['allowReports'] = '0';
        }

        $this->settings = wp_parse_args( $args, $this->defaults );

        // Migrate emailCustomHtml from main settings to separate option if needed
        if( ! empty( $this->settings['emailCustomHtml'] ) ){
            $existing = get_option( 'better-messages-email-custom-html', '' );
            if( empty( $existing ) ){
                update_option( 'better-messages-email-custom-html', $this->settings['emailCustomHtml'], false );
            }
            $this->settings['emailCustomHtml'] = ''; // Clear from main settings
        }
    }

    /**
     * Get email custom HTML template (loaded on demand to avoid bloating main settings)
     *
     * @return string
     */
    public function get_email_custom_html()
    {
        return get_option( 'better-messages-email-custom-html', '' );
    }

    public function setup_actions()
    {
        add_action( 'admin_menu', array( $this, 'settings_page' ) );
    }

    /**
     * Settings page
     */
    public function settings_page()
    {
        $administration_menu_title = _x('Administration', 'WP Admin', 'bp-better-messages');
        $plugin_menu_title = _x( 'Better Messages', 'WP Admin', 'bp-better-messages' );

        $notifications_count = 0;

        if( class_exists('Better_Messages_User_Reports') ){
            $reports_count = Better_Messages_User_Reports::instance()->get_reported_messages_count();

            if( $reports_count > 0 ){
                $notifications_count += $reports_count;
            }
        }

        $pending_count = Better_Messages()->functions->get_pending_messages_count();

        if( $pending_count > 0 ){
            $notifications_count += $pending_count;
        }

        if( $notifications_count > 0 ){
            $administration_menu_title .= " <span class='awaiting-mod count-{$notifications_count} bm-reports-count'>{$notifications_count}</span>";
            $plugin_menu_title .= " <span class='awaiting-mod count-{$notifications_count} bm-reports-count'>{$notifications_count}</span>";

            wp_register_style('bm-reports-count', false);
            wp_add_inline_style('bm-reports-count', '.awaiting-mod.bm-reports-count+.fs-trial{display:none!important}');
            wp_enqueue_style( 'bm-reports-count' );
        }


        add_menu_page(
            __( 'Better Messages' ),
            $plugin_menu_title,
            'manage_options',
            'bp-better-messages',
            array( $this, 'settings_page_new_html' ),
            'dashicons-format-chat'
        );

        add_submenu_page(
            'bp-better-messages',
            _x( 'Settings', 'WP Admin', 'bp-better-messages' ),
            _x( 'Settings', 'WP Admin', 'bp-better-messages' ),
            'manage_options',
            'bp-better-messages',
            array( $this, 'settings_page_new_html' ),
            0
        );

        add_submenu_page(
            'bp-better-messages',
            _x( 'AI Chat Bots', 'WP Admin', 'bp-better-messages' ),
            _x( 'AI Chat Bots', 'WP Admin', 'bp-better-messages' ),
            'manage_options',
            'bp-better-messages-ai',
            array( $this, 'ai_bots_page_new_html' ),
            2
        );

        add_submenu_page(
            'bp-better-messages',
            _x( 'Chat Rooms', 'WP Admin', 'bp-better-messages' ),
            _x( 'Chat Rooms', 'WP Admin', 'bp-better-messages' ),
            'manage_options',
            'bp-better-messages-chat-rooms',
            array( $this, 'chat_rooms_page_html' ),
            3
        );

        add_submenu_page(
            'bp-better-messages',
            _x( 'Administration', 'WP Admin', 'bp-better-messages' ),
            $administration_menu_title,
            'bm_can_administrate',
            'better-messages-viewer',
            array($this, 'viewer_page_new_html'),
            10
        );
        //}

        /* add_submenu_page(
            'bp-better-messages',
            _x('System', 'WP Admin', 'bp-better-messages'),
            _x('System', 'WP Admin', 'bp-better-messages'),
            'manage_options',
            'better-messages-system',
            array($this, 'system_page_html'),
            5
        ); */

        /*add_submenu_page(
            'bp-better-messages',
            _x('Moderation', 'Admin Menu', 'bp-better-messages'),
            _x('Moderation', 'Admin Menu', 'bp-better-messages'),
            'manage_options',
            'better-messages-moderation',
            array($this, 'moderation_page_html'),
            2
        );*/
    }

    public function settings_page_new_html()
    {
        wp_enqueue_media();

        $all_roles = get_editable_roles();
        $roles = array();
        foreach ( $all_roles as $role_key => $role_data ) {
            if ( $role_key === 'administrator' ) continue;
            $roles[] = array(
                'key'  => $role_key,
                'name' => translate_user_role( $role_data['name'] ),
            );
        }
        // wpRoles = editable roles minus administrator (no bm-guest, no bm-bot)
        $wp_roles = $roles;

        $roles[] = array( 'key' => 'bm-guest', 'name' => _x( 'Guests', 'Settings page', 'bp-better-messages' ) );

        // allRoles includes administrator, bm-guest, bm-bot (used for role-to-role "To" dropdown)
        $all_roles_list = array();
        foreach ( $all_roles as $role_key => $role_data ) {
            $all_roles_list[] = array(
                'key'  => $role_key,
                'name' => translate_user_role( $role_data['name'] ),
            );
        }
        $all_roles_list[] = array( 'key' => 'bm-guest', 'name' => _x( 'Guests', 'Settings page', 'bp-better-messages' ) );
        $all_roles_list[] = array( 'key' => 'bm-bot', 'name' => _x( 'AI Chat Bots', 'Settings page', 'bp-better-messages' ) );

        $pages_list = get_pages( array( 'sort_column' => 'post_title', 'sort_order' => 'ASC' ) );
        $pages = array();
        if ( ! empty( $pages_list ) ) {
            foreach ( $pages_list as $page ) {
                $pages[] = array(
                    'id'    => $page->ID,
                    'title' => $page->post_title,
                );
            }
        }

        $is_premium = function_exists('bpbm_fs') && bpbm_fs()->is_premium();
        $can_use_premium = Better_Messages()->functions->can_use_premium_code();
        $websocket_allowed = Better_Messages()->functions->can_use_premium_code_premium_only();

        $license_url = admin_url('admin.php?page=bp-better-messages-pricing');
        $account_url = admin_url('admin.php?page=bp-better-messages-account');

        $is_trial_utilized = function_exists('bpbm_fs') ? bpbm_fs()->is_trial_utilized() : true;
        $trial_url = ( function_exists('bpbm_fs') && ! $is_trial_utilized && ! $can_use_premium ) ? bpbm_fs()->get_trial_url() : '';

        $has_websocket_class = class_exists('Better_Messages_WebSocket');
        $download_url = '';
        if ( $can_use_premium && ! $is_premium && function_exists('bpbm_fs') ) {
            $download_url = bpbm_fs()->_get_latest_download_local_url();
        }

        $reconnect_url = '';
        if ( $is_premium && ! $can_use_premium && function_exists('bpbm_fs') ) {
            $user = bpbm_fs()->get_user();
            $reconnect_url = $user ? $account_url : bpbm_fs()->get_reconnect_url();
        }

        $site_id = $has_websocket_class ? Better_Messages_WebSocket()->site_id : '';

        $license_check = array();
        if ( $can_use_premium && $has_websocket_class && function_exists('bpbm_fs') ) {
            $user = bpbm_fs()->get_user();
            $site = bpbm_fs()->get_site();
            if ( $user && $site ) {
                $license_check = array(
                    'check' => array(
                        'domain'      => $site_id,
                        'license_key' => base64_encode( Better_Messages_WebSocket()->secret_key ),
                        'site'        => $site->id,
                    ),
                    'lock' => array(
                        'domain'      => $site_id,
                        'license_key' => base64_encode( Better_Messages_WebSocket()->secret_key ),
                        'site'        => $site->id,
                        'user'        => $user->id,
                        'auth'        => hash('sha256', $user->secret_key),
                    ),
                );
            }
        }

        $customize_url = Better_Messages()->customize->customization_link(array( 'panel' => 'better_messages' ));

        $has_buddypress  = class_exists('BuddyPress');
        $has_um          = defined('ultimatemember_version');
        $has_asgaros     = class_exists('AsgarosForum');
        $has_woocommerce = class_exists('WooCommerce');
        $has_suredash    = defined('SUREDASHBOARD_VER');
        $has_fluent_community = defined('FLUENT_COMMUNITY_PLUGIN_VERSION');
        $has_userswp     = defined('USERSWP_VERSION');
        $has_profile_grid = defined('PROGRID_PLUGIN_VERSION');
        $has_wp_user_manager = class_exists('WP_User_Manager');
        $has_dokan       = class_exists('WeDevs_Dokan');
        $has_wc_vendors  = class_exists('WCV_Vendors');
        $has_wcfm        = class_exists('WCFM');
        $has_multivendorx = defined('MVX_PLUGIN_VERSION');
        $has_hivepress   = function_exists('hivepress');
        $has_wp_job_manager = class_exists('WP_Job_Manager');
        $has_bbpress     = class_exists('bbPress');
        $has_jetengine   = function_exists('jet_engine');
        $has_friends     = Better_Messages()->functions->is_friends_active();
        $has_peepso      = class_exists('PeepSo');

        // BuddyPress sub-feature detection
        $has_bp_friends  = function_exists('friends_check_friendship');
        $has_bp_groups   = function_exists('bm_bp_is_active') && bm_bp_is_active('groups');

        // PeepSo sub-feature detection
        $has_peepso_friends = class_exists('PeepSoFriendsPlugin');
        $has_peepso_groups  = class_exists('PeepSoGroupsPlugin') || class_exists('PeepSoGroup');

        // Ultimate Member sub-feature detection
        $has_um_friends    = class_exists('UM_Friends_API');
        $has_um_groups     = class_exists('UM_Groups');
        $has_um_followers  = class_exists('UM_Followers_API');

        // BuddyBoss App
        $has_buddyboss_app = function_exists('bbapp_send_push_notification');

        $cron_jobs_late = false;
        $cron_jobs_list = array(
            'better_messages_send_notifications',
            'better_messages_sync_unread',
            'better_messages_cleaner_job',
            'better_messages_ai_ensure_completion_job',
            'better_messages_sync_user_index_weekly',
        );
        $cron_events = _get_cron_array();
        $cron_time   = time();
        $cron_status = array();
        if ( is_array( $cron_events ) ) {
            foreach ( $cron_events as $timestamp => $event ) {
                foreach ( $event as $hook => $details ) {
                    if ( in_array( $hook, $cron_jobs_list, true ) ) {
                        $is_late = $timestamp - ( $cron_time - ( 10 * MINUTE_IN_SECONDS ) ) < 0;
                        if ( $is_late ) {
                            $cron_jobs_late = true;
                        }
                        $cron_status[ $hook ] = array(
                            'is_late'   => $is_late,
                            'time_diff' => human_time_diff( $timestamp, $cron_time ) . ( $is_late ? ' ago' : '' ),
                        );
                    }
                }
            }
        }

        // Database info for Tools tab
        global $wpdb;
        $db_info = array();
        if ( class_exists( 'Better_Messages_Rest_Api_DB_Migrate' ) ) {
            $tables = apply_filters( 'better_messages_tables_list', Better_Messages_Rest_Api_DB_Migrate()->get_tables() );
            foreach ( $tables as $table ) {
                $entry = array( 'name' => $table, 'exists' => false );
                $query = $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) );
                if ( $wpdb->get_var( $query ) === $table ) {
                    $entry['exists'] = true;
                    $table_info = $wpdb->get_row( $wpdb->prepare( 'SHOW TABLE STATUS WHERE NAME LIKE %s;', $table ) );
                    if ( $table_info && isset( $table_info->Collation ) ) {
                        $entry['collation'] = $table_info->Collation;
                    }
                }
                $db_info[] = $entry;
            }
        }

        $last_sync_raw = get_option( 'bm_sync_user_roles_index_finish', false );
        $last_sync = $last_sync_raw ? wp_date( 'd-m-Y H:i', $last_sync_raw ) : '-';
        $next_sync_raw = wp_next_scheduled( 'better_messages_sync_user_index_weekly' );
        $next_sync = $next_sync_raw ? wp_date( 'd-m-Y H:i', $next_sync_raw ) : '-';

        // MyCred point types
        $mycred_point_types = array();
        if ( function_exists( 'mycred_get_types' ) ) {
            $mc_types = mycred_get_types();
            foreach ( $mc_types as $type_id => $label ) {
                $mycred_point_types[] = array(
                    'slug' => $type_id,
                    'name' => $label,
                );
            }
        }

        // GamiPress point types
        $gamipress_point_types = array();
        if ( function_exists( 'gamipress_get_points_types' ) ) {
            $gp_types = gamipress_get_points_types();
            foreach ( $gp_types as $slug => $pt ) {
                $gamipress_point_types[] = array(
                    'slug' => $slug,
                    'name' => isset( $pt['singular_name'] ) ? $pt['singular_name'] : $slug,
                );
            }
        }

        // Emoji sets
        $emoji_sets = array();
        if ( class_exists( 'Better_Messages_Emojis' ) ) {
            $emoji_sets = Better_Messages_Emojis()->emoji_sets;
        }

        // Suggested conversations users (resolve IDs to labels)
        $suggested_conversations_users = array();
        if ( is_array( $this->settings['suggestedConversations'] ) ) {
            foreach ( $this->settings['suggestedConversations'] as $user_id ) {
                if ( Better_Messages()->functions->is_user_exists( $user_id ) ) {
                    $user = Better_Messages()->functions->rest_user_item( $user_id );
                    $suggested_conversations_users[] = array(
                        'value'  => $user['user_id'],
                        'label'  => $user['name'],
                        'avatar' => $user['avatar'],
                    );
                }
            }
        }

        $resolve_support_user = function( $user_id ){
            $user_id = (int) $user_id;
            if ( $user_id <= 0 || ! Better_Messages()->functions->is_user_exists( $user_id ) ) {
                return null;
            }
            $user = Better_Messages()->functions->rest_user_item( $user_id );
            return array(
                'value'  => $user['user_id'],
                'label'  => $user['name'],
                'avatar' => $user['avatar'],
            );
        };

        $woocommerce_product_support_user      = $resolve_support_user( $this->settings['wooCommerceProductSupportUser'] );
        $woocommerce_order_support_user        = $resolve_support_user( $this->settings['wooCommerceOrderSupportUser'] );
        $woocommerce_pre_purchase_support_user = $resolve_support_user( $this->settings['wooCommercePrePurchaseSupportUser'] );

        // Load emailCustomHtml from separate option for frontend display
        $settings_for_frontend = $this->settings;
        $settings_for_frontend['emailCustomHtml'] = $this->get_email_custom_html();

        $settings_data = array(
            'settings'           => $settings_for_frontend,
            'roles'              => $roles,
            'allRoles'           => $all_roles_list,
            'wpRoles'            => $wp_roles,
            'pages'              => $pages,
            'isPremium'          => $is_premium,
            'canUsePremiumCode'  => $can_use_premium,
            'pluginUrl'          => Better_Messages()->url,
            'websocketAllowed'   => $websocket_allowed,
            'licenseUrl'         => $license_url,
            'accountUrl'         => $account_url,
            'isTrialUtilized'    => $is_trial_utilized,
            'trialUrl'           => $trial_url,
            'hasWebSocketClass'  => $has_websocket_class,
            'downloadUrl'        => $download_url,
            'reconnectUrl'       => $reconnect_url,
            'siteId'             => $site_id,
            'licenseCheck'       => $license_check,
            'customizeUrl'       => $customize_url,
            'isSsl'              => is_ssl() || defined('BM_DEV'),
            'hasBuddyPress'      => $has_buddypress,
            'hasUltimateMember'  => $has_um,
            'hasAsgarosForum'    => $has_asgaros,
            'hasWooCommerce'     => $has_woocommerce,
            'hasSureDash'        => $has_suredash,
            'hasFluentCommunity' => $has_fluent_community,
            'hasUsersWP'         => $has_userswp,
            'hasProfileGrid'     => $has_profile_grid,
            'hasWpUserManager'   => $has_wp_user_manager,
            'hasPeepSo'          => $has_peepso,
            'hasDokan'           => $has_dokan,
            'hasWcVendors'       => $has_wc_vendors,
            'hasWcfm'            => $has_wcfm,
            'hasMultiVendorX'    => $has_multivendorx,
            'hasHivePress'       => $has_hivepress,
            'hasWpJobManager'    => $has_wp_job_manager,
            'hasBbPress'         => $has_bbpress,
            'hasJetEngine'       => $has_jetengine,
            'hasFriends'         => $has_friends,
            'hasVoiceMessages'   => class_exists('BP_Better_Messages_Voice_Messages'),
            'translationLanguages' => class_exists('Better_Messages_AI') ? Better_Messages_AI::instance()->get_all_translation_languages() : array(),
            'giphyError'         => get_option( 'better_messages_gifs_giphy_error', get_option( 'bp_better_messages_giphy_error', false ) ),
            'klipyError'         => get_option( 'better_messages_gifs_klipy_error', false ),
            'gifProviders'       => class_exists( 'Better_Messages_Gif_Provider_Factory' ) ? Better_Messages_Gif_Provider_Factory::get_providers_info() : array(),
            'stipopError'        => get_option( 'bp_better_messages_stipop_error', false ),
            'stickerManifestUrl' => class_exists( 'Better_Messages_Stickers_Manager' ) ? Better_Messages_Stickers_Manager::instance()->get_manifest_url_for_current_locale() : '',
            'siteLocales'        => array_values( array_unique( array_merge( array( get_locale() ), function_exists( 'get_available_languages' ) ? get_available_languages() : array() ) ) ),
            'ffmpegInstalled'    => class_exists('Better_Messages_Files') && Better_Messages_Files::is_ffmpeg_installed(),
            'cronJobsLate'       => $cron_jobs_late,
            'pluginVersion'      => Better_Messages()->version,
            'detectedServer'     => class_exists('Better_Messages_Files') ? Better_Messages_Files::detect_server_capabilities() : array( 'server' => 'unknown', 'available' => array( 'php' ) ),
            'uploadsPath'        => trailingslashit( wp_upload_dir()['basedir'] ),
            'adminUrl'           => admin_url(),
            'fileFormats'        => array_diff_key( wp_get_ext_types(), array( 'code' => true ) ),
            'currentUserEmail'   => wp_get_current_user()->user_email,
            'phpVersionOk'       => version_compare( PHP_VERSION, '8.1', '>=' ),
            'hasBpFriends'       => $has_bp_friends,
            'hasBpGroups'        => $has_bp_groups,
            'hasPeepSoFriends'   => $has_peepso_friends,
            'hasPeepSoGroups'    => $has_peepso_groups,
            'hasUmFriends'       => $has_um_friends,
            'hasUmGroups'        => $has_um_groups,
            'hasUmFollowers'     => $has_um_followers,
            'hasFcSpaces'        => $has_fluent_community,
            'hasBuddyBossApp'    => $has_buddyboss_app,
            'hasOneSignal'       => defined('ONESIGNAL_PLUGIN_URL') || defined('ONESIGNAL_VERSION_V3') || class_exists('OneSignal'),
            'hasProgressify'     => class_exists('DaftPlug\Progressify\Plugin') || defined('PROGRESSIFY_VERSION'),
            'myMessagesUrl'      => do_shortcode('[better_messages_my_messages_url]'),
            'docsUrl'            => 'https://www.wordplus.org',
            'openaiError'        => get_option( 'better_messages_openai_error', false ),
            'cronStatus'         => $cron_status,
            'dbInfo'             => $db_info,
            'utf8mb4Supported'   => $wpdb->has_cap( 'utf8mb4' ),
            'lastSync'           => $last_sync,
            'nextSync'           => $next_sync,
            'hasMyCred'          => class_exists( 'myCRED_Core' ),
            'myCredPointTypes'   => $mycred_point_types,
            'hasGamiPress'       => function_exists( 'gamipress_get_points_types' ),
            'gamiPressPointTypes' => $gamipress_point_types,
            'emojiSets'          => $emoji_sets,
            'wasmUrls'           => array(
                array( 'name' => 'libheif', 'url' => class_exists('Better_Messages_Files') ? Better_Messages_Files::get_libheif_wasm_url() : '' ),
                array( 'name' => 'ffmpeg', 'url' => class_exists('Better_Messages_Files') && Better_Messages_Files::get_ffmpeg_wasm_url() ? Better_Messages_Files::get_ffmpeg_wasm_url() . 'ffmpeg-core.wasm' : false ),
            ),
            'ffmpegSize'         => class_exists('Better_Messages_Files') ? Better_Messages_Files::get_ffmpeg_info()['size'] : '',
            'suggestedConversationsUsers' => $suggested_conversations_users,
            'wooCommerceProductSupportUserData'     => $woocommerce_product_support_user,
            'wooCommerceOrderSupportUserData'       => $woocommerce_order_support_user,
            'wooCommercePrePurchaseSupportUserData' => $woocommerce_pre_purchase_support_user,
            'emojiCustomization' => get_option( 'bm-emoji-set-2', array() ),
            'emojiSpriteUrls'    => array(
                'apple'    => 'https://cdn.jsdelivr.net/npm/emoji-datasource-apple@14.0.0/img/apple/sheets-256/64.png',
                'twitter'  => 'https://cdn.jsdelivr.net/npm/emoji-datasource-twitter@14.0.0/img/twitter/sheets-256/64.png',
                'google'   => 'https://cdn.jsdelivr.net/npm/emoji-datasource-google@14.0.0/img/google/sheets-256/64.png',
                'facebook' => 'https://cdn.jsdelivr.net/npm/emoji-datasource-facebook@14.0.0/img/facebook/sheets-256/64.png',
            ),
            'emojiDatasetBaseUrl' => Better_Messages()->url . 'assets/emojies/',
            'emojiSpriteStatus'  => Better_Messages_Emojis()->getLocalStatus(),
            'contactUrl'         => method_exists( bpbm_fs(), 'contact_url' ) ? bpbm_fs()->contact_url() : '',
            'emailPreviewStrings' => array(
                'testConversation' => __( 'Test Conversation', 'bp-better-messages' ),
                'emailSubject'     => sprintf( _x( 'You have unread messages: "%s"', 'Email notification header for non BuddyPress websites', 'bp-better-messages' ), __( 'Test Conversation', 'bp-better-messages' ) ),
                'hiUser'           => sprintf( __( 'Hi %s,', 'bp-better-messages' ), 'John' ),
                'wroteLabel'       => sprintf( __( '%s wrote:', 'bp-better-messages' ), 'Jane Smith' ),
                'viewConversation' => __( 'View Conversation', 'bp-better-messages' ),
                'unsubscribe'      => _x( 'Unsubscribe from email notifications about unread messages', 'Email footer', 'bp-better-messages' ),
            ),
        );

        ?>
        <script type="text/javascript">
            window.BM_Settings_Data = <?php echo wp_json_encode( $settings_data ); ?>;
        </script>
        <div id="bm-settings-new"></div>
        <?php
    }

    public function ai_bots_page_new_html()
    {
        wp_enqueue_media();

        $providers = array();
        if ( version_compare( phpversion(), '8.1', '>=' ) && class_exists('Better_Messages_AI_Provider_Factory') ) {
            $providers = Better_Messages_AI_Provider_Factory::get_providers_info();
        }

        $voices = array(
            'alloy', 'ash', 'ballad', 'coral', 'echo', 'sage', 'shimmer', 'verse'
        );

        $has_voice_messages = class_exists('BP_Better_Messages_Voice_Messages');

        $image_pricing = array();
        if ( version_compare( phpversion(), '8.1', '>=' ) && class_exists('Better_Messages_AI') ) {
            $image_pricing = Better_Messages()->ai->get_image_pricing();
        }

        $model_pricing = array();
        $tool_pricing  = array();
        if ( version_compare( phpversion(), '8.1', '>=' ) && class_exists('Better_Messages_AI') ) {
            $model_pricing = Better_Messages()->ai->get_model_pricing_rules();
            $tool_pricing  = Better_Messages()->ai->get_tool_pricing();
        }

        $data = array(
            'providers'        => $providers,
            'voices'           => $voices,
            'hasVoiceMessages' => $has_voice_messages,
            'imagePricing'     => $image_pricing,
            'modelPricing'     => $model_pricing,
            'toolPricing'      => $tool_pricing,
            'pluginUrl'        => Better_Messages()->url,
            'pluginVersion'    => Better_Messages()->version,
            'phpVersion'       => phpversion(),
            'hasAnyApiKey'     => ! empty( Better_Messages()->settings['openAiApiKey'] )
                || ! empty( Better_Messages()->settings['anthropicApiKey'] )
                || ! empty( Better_Messages()->settings['geminiApiKey'] ),
            'settingsUrl'      => add_query_arg( 'page', 'bp-better-messages', admin_url('admin.php') ) . '#/integrations/openai',
            'pointsSystemActive' => class_exists( 'Better_Messages_Points' ) && Better_Messages_Points()->get_provider() !== null,
            'pointsSystemName'   => ( class_exists( 'Better_Messages_Points' ) && Better_Messages_Points()->get_provider() )
                ? Better_Messages_Points()->get_provider()->get_provider_name()
                : '',
            'pointsSystemProvider' => ( class_exists( 'Better_Messages_Points' ) && Better_Messages_Points()->get_provider() )
                ? Better_Messages_Points()->get_provider()->get_provider_id()
                : '',
            'roles'              => array_map( function( $role_key, $role_data ) {
                return array( 'key' => $role_key, 'name' => translate_user_role( $role_data['name'] ) );
            }, array_keys( get_editable_roles() ), array_values( get_editable_roles() ) ),
        );

        ?>
        <style>:root { --bm-avatar-radius: <?php echo intval( get_theme_mod( 'bm-avatar-radius', 2 ) ); ?>px; }</style>
        <script type="text/javascript">
            window.BM_AI_Bots_Data = <?php echo wp_json_encode( $data ); ?>;
        </script>
        <div id="bm-ai-bots-new"></div>
        <?php
    }

    public function chat_rooms_page_html()
    {
        wp_enqueue_media();

        $roles = get_editable_roles();
        if ( isset( $roles['administrator'] ) ) unset( $roles['administrator'] );

        $roles['bm-guest'] = array(
            'name' => _x( 'Guests', 'Settings page', 'bp-better-messages' )
        );

        $default_settings = Better_Messages()->chats->get_chat_settings( 0 );

        $data = array(
            'pluginUrl'       => Better_Messages()->url,
            'pluginVersion'   => Better_Messages()->version,
            'roles'           => $roles,
            'isWebSocket'     => Better_Messages()->settings['mechanism'] === 'websocket',
            'defaultSettings' => $default_settings,
        );

        ?>
        <style>:root { --bm-avatar-radius: <?php echo intval( get_theme_mod( 'bm-avatar-radius', 2 ) ); ?>px; }</style>
        <script type="text/javascript">
            window.BM_Chat_Rooms_Data = <?php echo wp_json_encode( $data ); ?>;
        </script>
        <div id="bm-chat-rooms-new"></div>
        <?php
    }

    public function viewer_page_new_html(){
        $messages_enabled = ! defined('BM_DISABLE_MESSAGES_VIEWER') && Better_Messages()->settings['messagesViewer'] !== '0';
        $reports_enabled = class_exists('Better_Messages_User_Reports');
        $bulk_messaging_enabled = current_user_can('manage_options');

        $data = array(
            'messagesEnabled'      => $messages_enabled,
            'reportsEnabled'       => $reports_enabled,
            'bulkMessagingEnabled'  => $bulk_messaging_enabled,
            'pluginUrl'            => Better_Messages()->url,
            'pluginVersion'        => Better_Messages()->version,
        );

        ?>
        <script type="text/javascript">
            window.BM_Viewer_Data = <?php echo wp_json_encode( $data ); ?>;
        </script>
        <div id="bm-viewer-new"></div>
        <?php
    }

    public function system_page_html(){
        include $this->path . 'layout-system.php';
    }

    public function moderation_page_html(){
        include $this->path . 'layout-moderation.php';
    }

    /**
     * Recursively sanitize an array — strings get sanitize_text_field,
     * numbers and booleans pass through, sub-arrays recurse.
     */
    public function sanitize_array_recursive( $arr ) {
        $sanitized = array();
        foreach ( $arr as $key => $value ) {
            $safe_key = is_string( $key ) ? sanitize_text_field( $key ) : $key;
            if ( is_array( $value ) ) {
                $sanitized[ $safe_key ] = $this->sanitize_array_recursive( $value );
            } else if ( is_string( $value ) ) {
                $sanitized[ $safe_key ] = sanitize_text_field( $value );
            } else {
                $sanitized[ $safe_key ] = $value;
            }
        }
        return $sanitized;
    }

    /**
     * Sanitize emoji customization data.
     */
    public function sanitize_emoji_data( $emojies ) {
        return $this->sanitize_array_recursive( $emojies );
    }

    /**
     * Sanitize an inline SVG markup string with a tag/attribute whitelist.
     * Used for the bubbleIcon setting where users can paste SVG from lucide.dev etc.
     */
    public function sanitize_svg( $svg ) {
        $allowed_attrs = array(
            'xmlns'             => true,
            'xmlns:xlink'       => true,
            'viewbox'           => true,
            'width'             => true,
            'height'            => true,
            'fill'              => true,
            'stroke'            => true,
            'stroke-width'      => true,
            'stroke-linecap'    => true,
            'stroke-linejoin'   => true,
            'stroke-miterlimit' => true,
            'stroke-dasharray'  => true,
            'stroke-dashoffset' => true,
            'stroke-opacity'    => true,
            'fill-opacity'      => true,
            'fill-rule'         => true,
            'clip-rule'         => true,
            'opacity'           => true,
            'transform'         => true,
            'd'                 => true,
            'cx'                => true,
            'cy'                => true,
            'r'                 => true,
            'rx'                => true,
            'ry'                => true,
            'x'                 => true,
            'y'                 => true,
            'x1'                => true,
            'y1'                => true,
            'x2'                => true,
            'y2'                => true,
            'points'            => true,
            'id'                => true,
            'class'             => true,
            'style'             => true,
            'preserveaspectratio' => true,
        );
        $allowed_tags = array(
            'svg'      => $allowed_attrs,
            'g'        => $allowed_attrs,
            'path'     => $allowed_attrs,
            'circle'   => $allowed_attrs,
            'ellipse'  => $allowed_attrs,
            'rect'     => $allowed_attrs,
            'line'     => $allowed_attrs,
            'polyline' => $allowed_attrs,
            'polygon'  => $allowed_attrs,
            'title'    => $allowed_attrs,
            'desc'     => $allowed_attrs,
            'defs'     => $allowed_attrs,
            'use'      => $allowed_attrs,
        );
        return wp_kses( $svg, $allowed_tags );
    }

    public function update_settings( $settings )
    {
        if( isset( $settings['emojiSettings'] ) && ! empty( trim($settings['emojiSettings']) ) ){
            $emojies = json_decode( wp_unslash($settings['emojiSettings']), true );
            $emojies = $this->sanitize_emoji_data( $emojies );
            update_option( 'bm-emoji-set-2', $emojies );
            update_option( 'bm-emoji-hash', hash('md5', json_encode($emojies) ) );
            unset($settings['emojiSettings']);
        }

        if ( !isset( $settings['PSminiGroupsEnable'] ) ) {
            $settings['PSminiGroupsEnable'] = '0';
        }
        if ( !isset( $settings['PScombinedGroupsEnable'] ) ) {
            $settings['PScombinedGroupsEnable'] = '0';
        }
        if ( !isset( $settings['PSmobileGroupsEnable'] ) ) {
            $settings['PSmobileGroupsEnable'] = '0';
        }

        if ( !isset( $settings['UMminiGroupsEnable'] ) ) {
            $settings['UMminiGroupsEnable'] = '0';
        }
        if ( !isset( $settings['UMcombinedGroupsEnable'] ) ) {
            $settings['UMcombinedGroupsEnable'] = '0';
        }
        if ( !isset( $settings['UMmobileGroupsEnable'] ) ) {
            $settings['UMmobileGroupsEnable'] = '0';
        }
        if ( !isset( $settings['privateThreadInvite'] ) ) {
            $settings['privateThreadInvite'] = '0';
        }
        if ( !isset( $settings['PSonlyFriendsMode'] ) ) {
            $settings['PSonlyFriendsMode'] = '0';
        }
        if ( !isset( $settings['PSminiFriendsEnable'] ) ) {
            $settings['PSminiFriendsEnable'] = '0';
        }
        if ( !isset( $settings['PScombinedFriendsEnable'] ) ) {
            $settings['PScombinedFriendsEnable'] = '0';
        }
        if ( !isset( $settings['PSmobileFriendsEnable'] ) ) {
            $settings['PSmobileFriendsEnable'] = '0';
        }

        if ( !isset( $settings['PSenableGroups'] ) ) {
            $settings['PSenableGroups'] = '0';
        }
        if ( !isset( $settings['PSenableGroupsFiles'] ) ) {
            $settings['PSenableGroupsFiles'] = '0';
        }
        if ( !isset( $settings['PSenableGroupsEmails'] ) ) {
            $settings['PSenableGroupsEmails'] = '0';
        }

        if ( !isset( $settings['PSenableGroupsPushs'] ) ) {
            $settings['PSenableGroupsPushs'] = '0';
        }

        if ( !isset( $settings['UMenableGroups'] ) ) {
            $settings['UMenableGroups'] = '0';
        }
        if ( !isset( $settings['UMenableGroupsFiles'] ) ) {
            $settings['UMenableGroupsFiles'] = '0';
        }
        if ( !isset( $settings['UMenableGroupsEmails'] ) ) {
            $settings['UMenableGroupsEmails'] = '0';
        }

        if ( !isset( $settings['UMenableGroupsPushs'] ) ) {
            $settings['UMenableGroupsPushs'] = '0';
        }

        if ( !isset( $settings['UMminiFriendsEnable'] ) ) {
            $settings['UMminiFriendsEnable'] = '0';
        }
        if ( !isset( $settings['UMcombinedFriendsEnable'] ) ) {
            $settings['UMcombinedFriendsEnable'] = '0';
        }
        if ( !isset( $settings['UMmobileFriendsEnable'] ) ) {
            $settings['UMmobileFriendsEnable'] = '0';
        }
        if ( !isset( $settings['sidebarUserToggle'] ) ) {
            $settings['sidebarUserToggle'] = '0';
        }

        if ( defined('FLUENT_COMMUNITY_PLUGIN_VERSION') ) {
            if ( ! isset( $settings['FCenableMessageButton'] ) ) {
                $settings['FCenableMessageButton'] = '0';
            }

            if ( ! isset( $settings['FcFullScreen'] ) ) {
                $settings['FcFullScreen'] = '0';
            }

            if ( ! isset( $settings['FcPageTitle'] ) ) {
                $settings['FcPageTitle'] = '0';
            }

            if ( ! isset( $settings['FCProfileVideoCall'] ) ) {
                $settings['FCProfileVideoCall'] = '0';
            }

            if ( ! isset( $settings['FCProfileAudioCall'] ) ) {
                $settings['FCProfileAudioCall'] = '0';
            }

            if ( ! isset( $settings['FCenableGroups'] ) ) {
                $settings['FCenableGroups'] = '0';
            }

            if ( ! isset( $settings['FCenableGroupsFiles'] ) ) {
                $settings['FCenableGroupsFiles'] = '0';
            }

            if ( ! isset( $settings['FCenableGroupsEmails'] ) ) {
                $settings['FCenableGroupsEmails'] = '0';
            }

            if ( ! isset( $settings['FCenableGroupsPushs'] ) ) {
                $settings['FCenableGroupsPushs'] = '0';
            }

            if ( ! isset( $settings['FCminiGroupsEnable'] ) ) {
                $settings['FCminiGroupsEnable'] = '0';
            }

            if ( ! isset( $settings['FCcombinedGroupsEnable'] ) ) {
                $settings['FCcombinedGroupsEnable'] = '0';
            }

            if ( ! isset( $settings['FCmobileGroupsEnable'] ) ) {
                $settings['FCmobileGroupsEnable'] = '0';
            }
        }

        if ( defined('SUREDASHBOARD_VER') ) {
            if ( ! isset( $settings['SDenableProfileButton'] ) ) {
                $settings['SDenableProfileButton'] = '0';
            }
            if ( ! isset( $settings['SDenableAuthorButton'] ) ) {
                $settings['SDenableAuthorButton'] = '0';
            }
            if ( ! isset( $settings['SDenableSidebarMessages'] ) ) {
                $settings['SDenableSidebarMessages'] = '0';
            }
            if ( ! isset( $settings['SDenableDropdownMessages'] ) ) {
                $settings['SDenableDropdownMessages'] = '0';
            }
            if ( ! isset( $settings['SDProfileVideoCall'] ) ) {
                $settings['SDProfileVideoCall'] = '0';
            }
            if ( ! isset( $settings['SDProfileAudioCall'] ) ) {
                $settings['SDProfileAudioCall'] = '0';
            }
        }

        if ( !isset( $settings['attachmentsEnable'] ) ) {
            $settings['attachmentsEnable'] = '0';
        }
        if ( !isset( $settings['attachmentsHide'] ) ) {
            $settings['attachmentsHide'] = '0';
        }
        if ( !isset( $settings['attachmentsProxy'] ) ) {
            $settings['attachmentsProxy'] = '0';
        }
        if ( !isset( $settings['attachmentsProxyMethod'] ) || !in_array( $settings['attachmentsProxyMethod'], array( 'php', 'xsendfile', 'xaccel', 'litespeed' ), true ) ) {
            $settings['attachmentsProxyMethod'] = 'php';
        }
        if ( !isset( $settings['attachmentsXAccelPrefix'] ) ) {
            $settings['attachmentsXAccelPrefix'] = '/bm-files/';
        }
        if ( !isset( $settings['attachmentsUploadMethod'] ) || !in_array( $settings['attachmentsUploadMethod'], array( 'tus', 'post' ), true ) ) {
            $settings['attachmentsUploadMethod'] = 'tus';
        }
        if ( !isset( $settings['attachmentsRandomizeFilenames'] ) ) {
            $settings['attachmentsRandomizeFilenames'] = '0';
        }
        if ( !isset( $settings['attachmentsBrowserEnable'] ) ) {
            $settings['attachmentsBrowserEnable'] = '0';
        }
        if ( !isset( $settings['transcodingImageFormat'] ) || !in_array( $settings['transcodingImageFormat'], array( 'original', 'webp', 'avif', 'jpeg' ), true ) ) {
            $settings['transcodingImageFormat'] = 'original';
        }
        if ( !isset( $settings['transcodingImageQuality'] ) ) {
            $settings['transcodingImageQuality'] = 85;
        } else {
            $settings['transcodingImageQuality'] = max( 1, min( 100, intval( $settings['transcodingImageQuality'] ) ) );
        }
        if ( !isset( $settings['transcodingImageMaxResolution'] ) ) {
            $settings['transcodingImageMaxResolution'] = 0;
        } else {
            $settings['transcodingImageMaxResolution'] = max( 0, intval( $settings['transcodingImageMaxResolution'] ) );
        }
        if ( !isset( $settings['transcodingStripMetadata'] ) ) {
            $settings['transcodingStripMetadata'] = '0';
        }
        if ( !isset( $settings['transcodingVideoFormat'] ) || !in_array( $settings['transcodingVideoFormat'], array( 'original', 'mp4' ), true ) ) {
            $settings['transcodingVideoFormat'] = 'original';
        }
        if ( !isset( $settings['miniChatsEnable'] ) ) {
            $settings['miniChatsEnable'] = '0';
        }
        if ( !isset( $settings['combinedChatsEnable'] ) ) {
            $settings['combinedChatsEnable'] = '0';
        }
        if ( !isset( $settings['searchAllUsers'] ) ) {
            $settings['searchAllUsers'] = '0';
        }
        if ( !isset( $settings['disableSubject'] ) ) {
            $settings['disableSubject'] = '0';
        }
        if ( !isset( $settings['disableEnterForTouch'] ) ) {
            $settings['disableEnterForTouch'] = '0';
        }
        if ( !isset( $settings['mobileFullScreen'] ) ) {
            $settings['mobileFullScreen'] = '0';
        }
        if ( !isset( $settings['messagesStatus'] ) ) {
            $settings['messagesStatus'] = '0';
        }
        if ( !isset( $settings['messagesStatusList'] ) ) {
            $settings['messagesStatusList'] = '0';
        }
        if ( !isset( $settings['messagesStatusDetailed'] ) ) {
            $settings['messagesStatusDetailed'] = '0';
        }
        if ( !isset( $settings['allowDeleteMessages'] ) ) {
            $settings['allowDeleteMessages'] = '0';
        }
        if ( !isset( $settings['fastStart'] ) ) {
            $settings['fastStart'] = '0';
        }
        if ( !isset( $settings['miniFriendsEnable'] ) ) {
            $settings['miniFriendsEnable'] = '0';
        }
        if ( !isset( $settings['miniThreadsEnable'] ) ) {
            $settings['miniThreadsEnable'] = '0';
        }
        if ( !isset( $settings['friendsMode'] ) ) {
            $settings['friendsMode'] = '0';
        }
        if ( !isset( $settings['singleThreadMode'] ) ) {
            $settings['singleThreadMode'] = '0';
        }
        if ( !isset( $settings['newThreadMode'] ) ) {
            $settings['newThreadMode'] = '0';
        }
        if ( !isset( $settings['disableGroupThreads'] ) ) {
            $settings['disableGroupThreads'] = '0';
        }
        if ( !isset( $settings['mobilePopup'] ) ) {
            $settings['mobilePopup'] = '0';
        }
        if ( !isset( $settings['autoFullScreen'] ) ) {
            $settings['autoFullScreen'] = '0';
        }
        if ( !isset( $settings['tapToOpenMsg'] ) ) {
            $settings['tapToOpenMsg'] = '0';
        }
        if ( !isset( $settings['mobileSwipeBack'] ) ) {
            $settings['mobileSwipeBack'] = '0';
        }
        if ( !isset( $settings['oEmbedEnable'] ) ) {
            $settings['oEmbedEnable'] = '0';
        }
        if ( !isset( $settings['disableEnterForDesktop'] ) ) {
            $settings['disableEnterForDesktop'] = '0';
        }
        if ( !isset( $settings['restrictNewThreads'] ) ) {
            $settings['restrictNewThreads'] = [];
        }

        if( ! isset( $settings['messagesPremoderationRolesNewConv'] ) ){
            $settings['messagesPremoderationRolesNewConv'] = [];
        }

        if( ! isset( $settings['messagesPremoderationRolesReplies'] ) ){
            $settings['messagesPremoderationRolesReplies'] = [];
        }

        if( ! isset( $settings['messagesModerateFirstTimeSenders'] ) ){
            $settings['messagesModerateFirstTimeSenders'] = '0';
        }

        if( ! isset( $settings['messagesModerationNotificationEmails'] ) ){
            $settings['messagesModerationNotificationEmails'] = '';
        }

        if( ! isset( $settings['aiModerationEnabled'] ) ){
            $settings['aiModerationEnabled'] = '0';
        }

        // When AI moderation is disabled, sub-setting inputs are disabled and not submitted.
        // Preserve existing sub-settings from database to prevent silent reset to defaults.
        if( $settings['aiModerationEnabled'] !== '1' ) {
            $existing = $this->settings;
            if( ! isset( $settings['aiModerationAction'] ) ){
                $settings['aiModerationAction'] = isset( $existing['aiModerationAction'] ) ? $existing['aiModerationAction'] : 'flag';
            }
            if( ! isset( $settings['aiModerationImages'] ) ){
                $settings['aiModerationImages'] = isset( $existing['aiModerationImages'] ) ? $existing['aiModerationImages'] : '0';
            }
            if( ! isset( $settings['aiModerationCategories'] ) ){
                $settings['aiModerationCategories'] = isset( $existing['aiModerationCategories'] ) ? $existing['aiModerationCategories'] : [];
            }
            if( ! isset( $settings['aiModerationThreshold'] ) ){
                $settings['aiModerationThreshold'] = isset( $existing['aiModerationThreshold'] ) ? $existing['aiModerationThreshold'] : '0.5';
            }
            if( ! isset( $settings['aiModerationBypassRoles'] ) ){
                $settings['aiModerationBypassRoles'] = isset( $existing['aiModerationBypassRoles'] ) ? $existing['aiModerationBypassRoles'] : [];
            }
        } else {
            if( ! isset( $settings['aiModerationAction'] ) ){
                $settings['aiModerationAction'] = 'flag';
            }
            if( ! isset( $settings['aiModerationImages'] ) ){
                $settings['aiModerationImages'] = '0';
            }
            if( ! isset( $settings['aiModerationCategories'] ) ){
                $settings['aiModerationCategories'] = [];
            }
            if( ! isset( $settings['aiModerationThreshold'] ) ){
                $settings['aiModerationThreshold'] = '0.5';
            }
            if( ! isset( $settings['aiModerationBypassRoles'] ) ){
                $settings['aiModerationBypassRoles'] = [];
            }
        }

        // Validate threshold is within 0-1 range
        if( isset( $settings['aiModerationThreshold'] ) ){
            $settings['aiModerationThreshold'] = max( 0, min( 1, (float) $settings['aiModerationThreshold'] ) );
            $settings['aiModerationThreshold'] = (string) $settings['aiModerationThreshold'];
        }

        if( ! isset( $settings['aiTranslationEnabled'] ) ){
            $settings['aiTranslationEnabled'] = '0';
        }

        if ( !isset( $settings['aiTranslationLanguages'] ) ) {
            $existing = $this->settings;
            $settings['aiTranslationLanguages'] = isset( $existing['aiTranslationLanguages'] ) ? $existing['aiTranslationLanguages'] : [];
        }

        if ( !isset( $settings['restrictBlockUsers'] ) ) {
            $settings['restrictBlockUsers'] = [];
        }
        if ( !isset( $settings['restrictBlockUsersImmun'] ) ) {
            $settings['restrictBlockUsersImmun'] = [];
        }
        if ( !isset( $settings['restrictNewReplies'] ) ) {
            $settings['restrictNewReplies'] = [];
        }
        if ( !isset( $settings['restrictCalls'] ) ) {
            $settings['restrictCalls'] = [];
        }
        if ( !isset( $settings['restrictViewMessages'] ) ) {
            $settings['restrictViewMessages'] = [];
        }
        if ( !isset( $settings['restrictViewMiniThreads'] ) ) {
            $settings['restrictViewMiniThreads'] = [];
        }
        if ( !isset( $settings['restrictRoleBlock'] ) ) {
            $settings['restrictRoleBlock'] = [];
        }
        if ( !isset( $settings['restrictViewMiniFriends'] ) ) {
            $settings['restrictViewMiniFriends'] = [];
        }
        if ( !isset( $settings['restrictViewMiniGroups'] ) ) {
            $settings['restrictViewMiniGroups'] = [];
        }
        if ( !isset( $settings['restrictMobilePopup'] ) ) {
            $settings['restrictMobilePopup'] = [];
        }
        if ( !isset( $settings['restrictVoiceMessages'] ) ) {
            $settings['restrictVoiceMessages'] = [];
        }
        if ( !isset( $settings['miniWidgetsOrder'] ) ) {
            $settings['miniWidgetsOrder'] = [];
        }
        if ( !isset( $settings['miniWidgetsStyle'] ) ) {
            $settings['miniWidgetsStyle'] = 'classic';
        }
        if ( !isset( $settings['miniWidgetsAnimation'] ) ) {
            $settings['miniWidgetsAnimation'] = '1';
        }
        if ( !isset( $settings['bubbleChatHeads'] ) ) {
            $settings['bubbleChatHeads'] = '0';
        }
        if ( !isset( $settings['bubbleChatHeadsLimit'] ) ) {
            $settings['bubbleChatHeadsLimit'] = '5';
        }
        if ( !isset( $settings['bubbleIcon'] ) ) {
            $settings['bubbleIcon'] = 'comment';
        }
        if ( !isset( $settings['bubbleCloseOnOutside'] ) ) {
            $settings['bubbleCloseOnOutside'] = '0';
        }
        if ( !isset( $settings['sidePanelTabsOrder'] ) ) {
            $settings['sidePanelTabsOrder'] = [];
        }
        if ( !isset( $settings['mobileTabsOrder'] ) ) {
            $settings['mobileTabsOrder'] = [];
        }
        if ( !isset( $settings['videoCalls'] ) ) {
            $settings['videoCalls'] = '0';
        }
        if ( !isset( $settings['audioCalls'] ) ) {
            $settings['audioCalls'] = '0';
        }
        if ( !isset( $settings['userListButton'] ) ) {
            $settings['userListButton'] = '0';
        }
        if ( !isset( $settings['UMuserListButton'] ) ) {
            $settings['UMuserListButton'] = '0';
        }
        if ( !isset( $settings['combinedView'] ) ) {
            $settings['combinedView'] = '0';
        }
        if ( !isset( $settings['enablePushNotifications'] ) ) {
            $settings['enablePushNotifications'] = '0';
        }
        if ( !isset( $settings['allowMuteThreads'] ) ) {
            $settings['allowMuteThreads'] = '0';
        }
        if ( !isset( $settings['callsRevertIcons'] ) ) {
            $settings['callsRevertIcons'] = '0';
        }
        if ( !isset( $settings['callRequestTimeLimit'] ) ) {
            $settings['callRequestTimeLimit'] = '30';
        }
        if ( !isset( $settings['fixedHeaderHeight'] ) ) {
            $settings['fixedHeaderHeight'] = '0';
        }
        if ( !isset( $settings['mobilePopupLocationBottom'] ) ) {
            $settings['mobilePopupLocationBottom'] = '0';
        }
        if ( !isset( $settings['callsLimitFriends'] ) ) {
            $settings['callsLimitFriends'] = '0';
        }
        if ( !isset( $settings['stopBPNotifications'] ) ) {
            $settings['stopBPNotifications'] = '0';
        }
        if ( !isset( $settings['restrictThreadsDeleting'] ) ) {
            $settings['restrictThreadsDeleting'] = '0';
        }
        if ( !isset( $settings['disableFavoriteMessages'] ) ) {
            $settings['disableFavoriteMessages'] = '0';
        }
        if ( !isset( $settings['enableUnreadFilter'] ) ) {
            $settings['enableUnreadFilter'] = '0';
        }
        if ( !isset( $settings['disableSearch'] ) ) {
            $settings['disableSearch'] = '0';
        }
        if ( !isset( $settings['disableUserSettings'] ) ) {
            $settings['disableUserSettings'] = '0';
        }
        if ( !isset( $settings['disableNewThread'] ) ) {
            $settings['disableNewThread'] = '0';
        }
        if ( !isset( $settings['profileVideoCall'] ) ) {
            $settings['profileVideoCall'] = '0';
        }
        if ( !isset( $settings['profileAudioCall'] ) ) {
            $settings['profileAudioCall'] = '0';
        }
        if ( !isset( $settings['peepsoProfileVideoCall'] ) ) {
            $settings['peepsoProfileVideoCall'] = '0';
        }
        if ( !isset( $settings['peepsoProfileAudioCall'] ) ) {
            $settings['peepsoProfileAudioCall'] = '0';
        }
        if ( !isset( $settings['miniChatAudioCall'] ) ) {
            $settings['miniChatAudioCall'] = '0';
        }
        if ( !isset( $settings['miniChatVideoCall'] ) ) {
            $settings['miniChatVideoCall'] = '0';
        }
        if ( !isset( $settings['disableUsersSearch'] ) ) {
            $settings['disableUsersSearch'] = '0';
        }
        if ( !isset( $settings['disableOnSiteNotification'] ) ) {
            $settings['disableOnSiteNotification'] = '0';
        }
        if ( !isset( $settings['allowSoundDisable'] ) ) {
            $settings['allowSoundDisable'] = '0';
        }

        if ( !isset( $settings['enableGroups'] ) ) {
            $settings['enableGroups'] = '0';
        }

        if ( !isset( $settings['enableMiniGroups'] ) ) {
            $settings['enableMiniGroups'] = '0';
        }

        if ( !isset( $settings['allowGroupLeave'] ) ) {
            $settings['allowGroupLeave'] = '0';
        }

        if ( !isset( $settings['enableReplies'] ) ) {
            $settings['enableReplies'] = '0';
        }

        if ( !isset( $settings['enableSelfReplies'] ) ) {
            $settings['enableSelfReplies'] = '0';
        }

        if ( !isset( $settings['allowEditMessages'] ) ) {
            $settings['allowEditMessages'] = '0';
        }

        if ( !isset( $settings['enableNiceLinks'] ) ) {
            $settings['enableNiceLinks'] = '0';
        }

        if ( !isset( $settings['userStatuses'] ) ) {
            $settings['userStatuses'] = '0';
        }

        if ( !isset( $settings['myProfileButton'] ) ) {
            $settings['myProfileButton'] = '0';
        }

        if ( !isset( $settings['titleNotifications'] ) ) {
            $settings['titleNotifications'] = '0';
        }

        if ( !isset( $settings['restrictNewThreadsRemoveNewThreadButton'] ) ) {
            $settings['restrictNewThreadsRemoveNewThreadButton'] = '0';
        }

        if ( !isset( $settings['enableMiniCloseButton'] ) ) {
            $settings['enableMiniCloseButton'] = '0';
        }

        if( ! isset( $settings['groupCallsGroups'] ) ){
            $settings['groupCallsGroups'] = '0';
        }

        if( ! isset( $settings['groupCallsThreads'] ) ){
            $settings['groupCallsThreads'] = '0';
        }

        if( ! isset( $settings['groupCallsChats'] ) ){
            $settings['groupCallsChats'] = '0';
        }

        if( ! isset( $settings['groupAudioCallsGroups'] ) ){
            $settings['groupAudioCallsGroups'] = '0';
        }

        if( ! isset( $settings['groupAudioCallsThreads'] ) ){
            $settings['groupAudioCallsThreads'] = '0';
        }

        if( ! isset( $settings['groupAudioCallsChats'] ) ){
            $settings['groupAudioCallsChats'] = '0';
        }

        if( ! isset( $settings['allowUsersRestictNewThreads'] ) ){
            $settings['allowUsersRestictNewThreads'] = '0';
        }

        if( ! isset( $settings['enableGroupsEmails'] ) ){
            $settings['enableGroupsEmails'] = '0';
        }

        if( ! isset( $settings['enableGroupsPushs'] ) ){
            $settings['enableGroupsPushs'] = '0';
        }

        if( ! isset( $settings['desktopFullScreen'] ) ){
            $settings['desktopFullScreen'] = '0';
        }

        if( ! isset( $settings['friendsOnSiteNotifications'] ) ){
            $settings['friendsOnSiteNotifications'] = '0';
        }

        if( ! isset( $settings['groupsOnSiteNotifications'] ) ){
            $settings['groupsOnSiteNotifications'] = '0';
        }

        if( ! isset( $settings['enableUsersSuggestions'] ) ){
            $settings['enableUsersSuggestions'] = '0';
        }

        if( ! isset( $settings['hidePossibleBreakingElements'] ) ){
            $settings['hidePossibleBreakingElements'] = '0';
        }

        if( ! isset( $settings['createEmailTemplate'] ) ){
            $settings['createEmailTemplate'] = '0';
        }

        if( ! isset( $settings['bbPressAuthorDetailsLink'] ) ){
            $settings['bbPressAuthorDetailsLink'] = '0';
        }

        if( ! isset( $settings['enableGroupsFiles'] ) ){
            $settings['enableGroupsFiles'] = '0';
        }

        if( ! isset( $settings['combinedFriendsEnable'] ) ){
            $settings['combinedFriendsEnable'] = '0';
        }

        if( ! isset( $settings['combinedGroupsEnable'] ) ){
            $settings['combinedGroupsEnable'] = '0';
        }

        if( ! isset( $settings['mobileFriendsEnable'] ) ){
            $settings['mobileFriendsEnable'] = '0';
        }

        if( ! isset( $settings['mobileGroupsEnable'] ) ){
            $settings['mobileGroupsEnable'] = '0';
        }

        if( ! isset( $settings['umProfilePMButton'] ) ){
            $settings['umProfilePMButton'] = '0';
        }

        if( ! isset( $settings['umOnlyFriendsMode'] ) ){
            $settings['umOnlyFriendsMode'] = '0';
        }

        if( ! isset( $settings['umOnlyFollowersMode'] ) ){
            $settings['umOnlyFollowersMode'] = '0';
        }

        if( ! isset( $settings['allowUsersBlock'] ) ){
            $settings['allowUsersBlock'] = '0';
        }

        if( ! isset( $settings['allowReports'] ) ){
            $settings['allowReports'] = '0';
        }

        if( ! isset( $settings['messagesViewer'] ) ) {
            $settings['messagesViewer'] = '0';
        }

        if( ! isset( $settings['enableReactions'] ) ) {
            $settings['enableReactions'] = '0';
        }

        if( ! isset( $settings['enableReactionsPopup'] ) ) {
            $settings['enableReactionsPopup'] = '0';
        }

        if( ! isset( $settings['peepsoHeader'] ) ) {
            $settings['peepsoHeader'] = '0';
        }

        if( ! isset( $settings['bpForceMiniChat'] ) ) {
            $settings['bpForceMiniChat'] = '0';
        }

        if( ! isset( $settings['umForceMiniChat'] ) ) {
            $settings['umForceMiniChat'] = '0';
        }

        if( ! isset( $settings['psForceMiniChat'] ) ) {
            $settings['psForceMiniChat'] = '0';
        }

        if( ! isset( $settings['attachmentsAllowPhoto'] ) ) {
            $settings['attachmentsAllowPhoto'] = '0';
        }

        if( ! isset( $settings['bpFallback'] ) ) {
            $settings['bpFallback'] = '0';
        }

        if( ! isset( $settings['miniChatDisableSync'] ) ) {
            $settings['miniChatDisableSync'] = '0';
        }

        if( ! isset( $settings['pinnedThreads'] ) ) {
            $settings['pinnedThreads'] = '0';
        }

        if( ! isset( $settings['smileToEmoji'] ) ) {
            $settings['smileToEmoji'] = '1';
        }

        if( ! isset( $settings['emojiPicker'] ) ) {
            $settings['emojiPicker'] = '1';
        }

        if( ! isset( $settings['emojiSpriteDelivery'] ) || ! in_array( $settings['emojiSpriteDelivery'], array( 'cdn', 'self-hosted' ) ) ) {
            $settings['emojiSpriteDelivery'] = 'cdn';
        }

        if( ! isset( $settings['privacyEmbeds'] ) ) {
            $settings['privacyEmbeds'] = '0';
        }

        if( ! isset( $settings['privacyDeleteAttachments'] ) ) {
            $settings['privacyDeleteAttachments'] = '1';
        }

        if( ! isset( $settings['enableDrafts'] ) ) {
            $settings['enableDrafts'] = '0';
        }

        if( ! isset( $settings['bpAppPush'] ) ) {
            $settings['bpAppPush'] = '0';
        }

        if( ! isset( $settings['guestChat'] ) ) {
            $settings['guestChat'] = '0';
        }

        if( ! isset( $settings['dokanIntegration'] ) ) {
            $settings['dokanIntegration'] = '0';
        }

        if( ! isset( $settings['MultiVendorXIntegration'] ) ) {
            $settings['MultiVendorXIntegration'] = '0';
        }

        if( ! isset( $settings['wcVendorsIntegration'] ) ) {
            $settings['wcVendorsIntegration'] = '0';
        }

        if( ! isset( $settings['wcfmIntegration'] ) ) {
            $settings['wcfmIntegration'] = '0';
        }

        if( ! isset( $settings['wooCommerceIntegration'] ) ) {
            $settings['wooCommerceIntegration'] = '0';
        }

        if( ! isset( $settings['wooCommerceProductButton'] ) ) {
            $settings['wooCommerceProductButton'] = '0';
        }

        if( ! isset( $settings['wooCommerceOrderButton'] ) ) {
            $settings['wooCommerceOrderButton'] = '0';
        }

        if( ! isset( $settings['wooCommercePrePurchaseButton'] ) ) {
            $settings['wooCommercePrePurchaseButton'] = '0';
        }

        if( ! isset( $settings['wooCommerceMyAccountLink'] ) ) {
            $settings['wooCommerceMyAccountLink'] = '0';
        }

        if( ! isset( $settings['jetEngineAvatars'] ) ) {
            $settings['jetEngineAvatars'] = '0';
        }

        if( ! isset( $settings['hivepressIntegration'] ) ) {
            $settings['hivepressIntegration'] = '0';
        }

        if( ! isset( $settings['hivepressMenuItem'] ) ) {
            $settings['hivepressMenuItem'] = '0';
        }

        if( ! isset( $settings['redirectUnlogged'] ) ) {
            $settings['redirectUnlogged'] = '0';
        }

        if( ! isset( $settings['wpJobManagerIntegration'] ) ) {
            $settings['wpJobManagerIntegration'] = '0';
        }

        if( ! isset( $settings['deleteMessagesOnUserDelete'] ) ){
            $settings['deleteMessagesOnUserDelete'] = '0';
        }

        if( ! isset( $settings['encryptionLocal'] ) ) {
            $settings['encryptionLocal'] = '0';
        }

        if( ! isset( $settings['e2eEncryption'] ) ) {
            $settings['e2eEncryption'] = '0';
        }

        if( ! isset( $settings['e2eDefault'] ) ) {
            $settings['e2eDefault'] = '0';
        }

        if( ! isset( $settings['e2eForceSend'] ) ) {
            $settings['e2eForceSend'] = '0';
        }

        if( ! isset( $settings['e2eAllowGuests'] ) ) {
            $settings['e2eAllowGuests'] = '0';
        }

        if( ! isset( $settings['deleteMethod'] ) || $settings['deleteMethod'] !== 'replace' ) {
            $settings['deleteMethod'] = 'delete';
        }

        if( ! isset( $settings['pinnedMessages'] ) ) {
            $settings['pinnedMessages'] = '0';
        }

        if( ! isset( $settings['privateReplies'] ) ) {
            $settings['privateReplies'] = '0';
        }

        if( ! isset( $settings['enableForwardMessages'] ) ) {
            $settings['enableForwardMessages'] = '0';
        }

        if( ! isset( $settings['forwardMessagesAttribution'] ) ) {
            $settings['forwardMessagesAttribution'] = '0';
        }

        if( ! isset( $settings['messagesPremoderation'] ) ) {
            $settings['messagesPremoderation'] = '0';
        }

        if( ! isset( $settings['mentionsForceNotifications'] ) ) {
            $settings['mentionsForceNotifications'] = '0';
        }

        if( ! isset( $settings['emailUnsubscribeLink'] ) ) {
            $settings['emailUnsubscribeLink'] = '0';
        }

        if( ! isset( $settings['badWordsSkipAdmins'] ) ) {
            $settings['badWordsSkipAdmins'] = '0';
        }

        if( ! isset( $settings['voiceTranscription'] ) ) {
            $settings['voiceTranscription'] = '0';
        }

        if( isset( $settings['voiceTranscriptionLanguage'] ) ) {
            $lang = strtolower( trim( $settings['voiceTranscriptionLanguage'] ) );
            if ( $lang !== '' && $lang !== 'auto' && ! preg_match( '/^[a-z]{2,3}$/', $lang ) ) {
                $lang = '';
            }
            if ( $lang === 'auto' ) {
                $lang = '';
            }
            $settings['voiceTranscriptionLanguage'] = $lang;
        }

        // Enum validations for select/radio fields
        if( ! isset( $settings['sidebarCompactMode'] ) || ! in_array( $settings['sidebarCompactMode'], ['auto', 'always_compact', 'always_expanded'], true ) ) {
            $settings['sidebarCompactMode'] = 'auto';
        }

        if( ! isset( $settings['pushNotificationsLogic'] ) || ! in_array( $settings['pushNotificationsLogic'], ['offline', 'unread'], true ) ) {
            $settings['pushNotificationsLogic'] = 'offline';
        }

        if( ! isset( $settings['modernLayout'] ) || ! in_array( $settings['modernLayout'], ['left', 'right', 'leftAll'], true ) ) {
            $settings['modernLayout'] = 'left';
        }

        if( ! isset( $settings['deletedBehaviour'] ) || ! in_array( $settings['deletedBehaviour'], ['ignore', 'include'], true ) ) {
            $settings['deletedBehaviour'] = 'ignore';
        }

        if( ! isset( $settings['unreadCounter'] ) || ! in_array( $settings['unreadCounter'], ['messages', 'conversations'], true ) ) {
            $settings['unreadCounter'] = 'messages';
        }

        if( ! isset( $settings['onsitePosition'] ) || ! in_array( $settings['onsitePosition'], ['left', 'right'], true ) ) {
            $settings['onsitePosition'] = 'right';
        }

        if( ! isset( $settings['mobilePopupLocation'] ) || ! in_array( $settings['mobilePopupLocation'], ['left', 'right'], true ) ) {
            $settings['mobilePopupLocation'] = 'right';
        }

        if( ! isset( $settings['mobileOnsiteLocation'] ) || ! in_array( $settings['mobileOnsiteLocation'], ['auto', 'top', 'bottom'], true ) ) {
            $settings['mobileOnsiteLocation'] = 'auto';
        }

        if( ! isset( $settings['giphyContentRating'] ) || ! in_array( $settings['giphyContentRating'], ['g', 'pg', 'pg-13', 'r'], true ) ) {
            $settings['giphyContentRating'] = 'g';
        }

        if ( isset( $settings['gifsProvider'] ) && ! in_array( $settings['gifsProvider'], [ '', 'giphy', 'klipy', 'disabled' ], true ) ) {
            $settings['gifsProvider'] = '';
        }

        if ( isset( $settings['stickersProvider'] ) && ! in_array( $settings['stickersProvider'], [ '', 'builtin', 'stipop', 'disabled' ], true ) ) {
            $settings['stickersProvider'] = '';
        }

        $wc_product_placements = ['before_summary', 'before_add_to_cart', 'after_add_to_cart', 'after_summary', 'manual'];
        if( ! isset( $settings['wooCommerceProductButtonPlacement'] ) || ! in_array( $settings['wooCommerceProductButtonPlacement'], $wc_product_placements, true ) ) {
            $settings['wooCommerceProductButtonPlacement'] = 'before_summary';
        }

        $wc_order_placements = ['before_order_table', 'after_order_table', 'after_customer_details', 'manual'];
        if( ! isset( $settings['wooCommerceOrderButtonPlacement'] ) || ! in_array( $settings['wooCommerceOrderButtonPlacement'], $wc_order_placements, true ) ) {
            $settings['wooCommerceOrderButtonPlacement'] = 'after_order_table';
        }

        $wc_cart_placements = ['before_cart', 'after_cart_table', 'cart_collaterals', 'proceed_to_checkout', 'after_cart', 'manual'];
        if( ! isset( $settings['wooCommercePrePurchaseCartPlacement'] ) || ! in_array( $settings['wooCommercePrePurchaseCartPlacement'], $wc_cart_placements, true ) ) {
            $settings['wooCommercePrePurchaseCartPlacement'] = 'after_cart_table';
        }

        $wc_checkout_placements = ['before_form', 'before_order_summary', 'after_order_summary', 'after_form', 'manual'];
        if( ! isset( $settings['wooCommercePrePurchaseCheckoutPlacement'] ) || ! in_array( $settings['wooCommercePrePurchaseCheckoutPlacement'], $wc_checkout_placements, true ) ) {
            $settings['wooCommercePrePurchaseCheckoutPlacement'] = 'after_order_summary';
        }

        // Email template source validation (for BuddyPress sites)
        if( ! isset( $settings['emailTemplateSource'] ) || ! in_array( $settings['emailTemplateSource'], ['buddypress', 'custom'] ) ) {
            $settings['emailTemplateSource'] = 'buddypress';
        }

        // Email template mode validation
        if( ! isset( $settings['emailTemplateMode'] ) || ! in_array( $settings['emailTemplateMode'], ['simple', 'custom'] ) ) {
            $settings['emailTemplateMode'] = 'simple';
        }

        if( ! isset( $settings['restrictRoleType'] ) || $settings['restrictRoleType'] !== 'disallow' ) {
            $settings['restrictRoleType'] = 'allow';
        }

        if( ! isset( $settings['pointsSystem'] ) || ! in_array( $settings['pointsSystem'], ['none', 'mycred', 'gamipress'], true ) ) {
            $settings['pointsSystem'] = 'none';
        }

        if( ! isset( $settings['voiceMessagesAutoDeleteMode'] ) || ! in_array( $settings['voiceMessagesAutoDeleteMode'], ['complete', 'replace'], true ) ) {
            $settings['voiceMessagesAutoDeleteMode'] = 'complete';
        }

        if( ! isset( $settings['suggestedConversations'] ) ) {
            $settings['suggestedConversations'] = [];
        } else {
            $settings['suggestedConversations'] = array_map('intval', $settings['suggestedConversations']);
        }

        $settings['wooCommerceProductSupportUser']     = isset( $settings['wooCommerceProductSupportUser'] ) ? (int) $settings['wooCommerceProductSupportUser'] : 0;
        $settings['wooCommerceOrderSupportUser']       = isset( $settings['wooCommerceOrderSupportUser'] ) ? (int) $settings['wooCommerceOrderSupportUser'] : 0;
        $settings['wooCommercePrePurchaseSupportUser'] = isset( $settings['wooCommercePrePurchaseSupportUser'] ) ? (int) $settings['wooCommercePrePurchaseSupportUser'] : 0;

        $links_allowed = [
            'restrictBadWordsList',
            'restrictCallsMessage',
            'restrictNewThreadsMessage',
            'restrictNewRepliesMessage',
            'restrictViewMessagesMessage',
            'rateLimitReplyMessage',
            'myCredNewMessageChargeMessage',
            'restrictRoleMessage',
            'myCredNewThreadChargeMessage',
            'myCredCallPricingStartMessage',
            'myCredCallPricingEndMessage',
            'GamiPressNewMessageChargeMessage',
            'GamiPressNewThreadChargeMessage',
            'GamiPressCallPricingStartMessage',
            'GamiPressCallPricingEndMessage'
        ];

        $textareas = [ 'badWordsList', 'messagesModerationNotificationEmails', 'voiceTranscriptionPrompt', 'aiModerationCustomRules' ];

        // Fields that need special HTML handling (processed separately with wp_kses)
        $html_fields = [ 'emailCustomHtml' ];

        $int_only = [
            'thread_interval'           => 1,
            'site_interval'             => 1,
            'attachmentsRetention'      => 0,
            'callRequestTimeLimit'      => 10,
            'fixedHeaderHeight'         => 0,
            'messagesHeight'            => 200,
            'messagesMinHeight'         => 100,
            'sideThreadsWidth'          => 320,
            'sidebarCompactBreakpoint'  => 0,
            'sidebarHideBreakpoint'     => 0,
            'mobilePopupLocationBottom' => 0,
            'rateLimitNewThread'        => 0,
            'notificationsInterval'     => 0,
            'notificationsOfflineDelay' => 0,
            'notificationSound'         => 0,
            'notificationSoundId'       => 0,
            'sentSound'                 => 0,
            'sentSoundId'               => 0,
            'callSound'                 => 0,
            'callSoundId'               => 0,
            'dialingSound'              => 0,
            'dialingSoundId'            => 0,
            'modernBorderRadius'        => 0,
            'attachmentsMaxSize'        => 1,
            'attachmentsMaxNumber'      => 0,
            'voiceMessagesMaxDuration'  => 0,
            'voiceMessagesAutoDelete'   => 0,
            'deleteOldMessages'         => 0,
            'emailLogoId'               => 0
        ];

        $arrays = [
            'rateLimitReply',
            'restrictRoleBlock',
            'myCredNewMessageCharge',
            'myCredNewMessageChargeTypes',
            'myCredNewThreadCharge',
            'myCredNewThreadChargeTypes',
            'myCredCallPricing',
            'GamiPressNewMessageCharge',
            'GamiPressNewMessageChargeTypes',
            'GamiPressNewThreadCharge',
            'GamiPressNewThreadChargeTypes',
            'GamiPressCallPricing',
            'reactionsEmojies',
            'suggestedConversations',
            'messagesPremoderationRolesNewConv',
            'messagesPremoderationRolesReplies',
            'stickerPacksSummary'
        ];

        foreach ( $settings as $key => $value ) {
            /** Processing checkbox groups **/

            if( in_array( $key, $arrays ) ){
                $this->settings[$key] = $this->sanitize_array_recursive( (array) $value );
            } else if ( is_array( $value ) ) {
                $this->settings[$key] = array();
                foreach ( $value as $val ) {
                    $this->settings[$key][] = sanitize_text_field( $val );
                }
            } else if ( in_array( $key, $html_fields ) ) {
                // HTML fields are processed separately with wp_kses later
                $this->settings[$key] = $value;
            } else if ( $key === 'bubbleIcon' && is_string( $value ) && stripos( ltrim( $value ), '<svg' ) === 0 ) {
                // Custom SVG icon — sanitize with SVG-safe tag/attribute whitelist
                $this->settings[$key] = $this->sanitize_svg( wp_unslash( $value ) );
            } else {
                if( in_array( $key, $textareas ) ){
                    $this->settings[$key] = sanitize_textarea_field( $value );
                } else if ( in_array( $key, $links_allowed ) ) {
                    $this->settings[$key] = wp_kses( $value, 'user_description' );
                } else {
                    $this->settings[$key] = sanitize_text_field( $value );

                    if ( array_key_exists( $key, $int_only ) ) {
                        $intval = intval( $value );
                        if ( $intval <= $int_only[$key] ) {
                            $intval = $int_only[$key];
                        }
                        $this->settings[$key] = $intval;
                    }

                }

            }
        }

        $sounds_keys = [
            'notificationSound',
            'sentSound',
            'callSound',
            'dialingSound'
        ];

        foreach ( $sounds_keys as $key ) {
            if(  $this->settings[ $key . 'Id'] > 0 ){
                $attachment_url = wp_get_attachment_url( $settings[$key . 'Id'] );
                if( $attachment_url && str_ends_with( $attachment_url, '.mp3' ) ){
                    $this->settings[$key . 'Url'] = $attachment_url;
                } else {
                    $this->settings[$key . 'Id'] = 0;
                    $this->settings[$key . 'Url'] = '';
                }
            } else {
                $this->settings[$key . 'Id'] = 0;
                $this->settings[$key . 'Url'] = '';
            }
        }

        // Process email logo
        if( $this->settings['emailLogoId'] > 0 ){
            $attachment_url = wp_get_attachment_url( $this->settings['emailLogoId'] );
            if( $attachment_url && wp_attachment_is_image( $this->settings['emailLogoId'] ) ){
                $this->settings['emailLogoUrl'] = $attachment_url;
            } else {
                $this->settings['emailLogoId'] = 0;
                $this->settings['emailLogoUrl'] = '';
            }
        } else {
            $this->settings['emailLogoId'] = 0;
            $this->settings['emailLogoUrl'] = '';
        }

        // Sanitize pointsBalanceUrl as URL
        if( isset( $this->settings['pointsBalanceUrl'] ) && ! empty( $this->settings['pointsBalanceUrl'] ) ){
            $this->settings['pointsBalanceUrl'] = esc_url_raw( $this->settings['pointsBalanceUrl'] );
        }

        // Validate email colors (hex format)
        $email_color_keys = ['emailPrimaryColor', 'emailBackgroundColor', 'emailContentBgColor', 'emailTextColor'];
        $email_color_defaults = [
            'emailPrimaryColor'     => '#21759b',
            'emailBackgroundColor'  => '#f6f6f6',
            'emailContentBgColor'   => '#ffffff',
            'emailTextColor'        => '#333333'
        ];

        foreach ( $email_color_keys as $color_key ) {
            if( isset( $this->settings[$color_key] ) ){
                $color = $this->settings[$color_key];
                // Validate hex color format
                if( ! preg_match('/^#([A-Fa-f0-9]{6}|[A-Fa-f0-9]{3})$/', $color) ){
                    $this->settings[$color_key] = $email_color_defaults[$color_key];
                }
            }
        }

        // Handle email custom HTML — allow email-specific CSS properties (mso-*, Margin, vendor prefixes)
        // that wp_kses would otherwise strip. Only admins can save this content.
        if( isset( $this->settings['emailCustomHtml'] ) && ! empty( $this->settings['emailCustomHtml'] ) ){
            $allow_all_css = function( $styles ) {
                return array_merge( $styles, array(
                    'mso-table-lspace', 'mso-table-rspace', 'mso-padding-alt',
                    '-webkit-font-smoothing', '-ms-text-size-adjust', '-webkit-text-size-adjust',
                    'Margin', 'Margin-bottom', 'Margin-top', 'Margin-left', 'Margin-right',
                ) );
            };
            add_filter( 'safe_style_css', $allow_all_css );

            // Allow all HTML tags that are needed for email templates
            $allowed_html = array_merge(
                wp_kses_allowed_html('post'),
                array(
                    'html'   => array( 'lang' => true, 'xmlns' => true ),
                    'head'   => array(),
                    'meta'   => array( 'name' => true, 'content' => true, 'http-equiv' => true, 'charset' => true ),
                    'title'  => array(),
                    'body'   => array( 'class' => true, 'style' => true ),
                    'style'  => array( 'type' => true ),
                    'link'   => array( 'rel' => true, 'href' => true, 'type' => true ),
                    'center' => array(),
                    'table'  => array( 'width' => true, 'border' => true, 'cellpadding' => true, 'cellspacing' => true, 'class' => true, 'style' => true, 'align' => true, 'bgcolor' => true, 'role' => true ),
                    'tr'     => array( 'class' => true, 'style' => true, 'align' => true, 'valign' => true, 'bgcolor' => true ),
                    'td'     => array( 'width' => true, 'class' => true, 'style' => true, 'align' => true, 'valign' => true, 'colspan' => true, 'rowspan' => true, 'bgcolor' => true ),
                    'th'     => array( 'width' => true, 'class' => true, 'style' => true, 'align' => true, 'valign' => true, 'colspan' => true, 'rowspan' => true, 'scope' => true ),
                    'thead'  => array(),
                    'tbody'  => array(),
                    'tfoot'  => array(),
                    'img'    => array( 'src' => true, 'alt' => true, 'width' => true, 'height' => true, 'class' => true, 'style' => true, 'border' => true ),
                )
            );
            $this->settings['emailCustomHtml'] = wp_kses( wp_unslash( $this->settings['emailCustomHtml'] ), $allowed_html );

            remove_filter( 'safe_style_css', $allow_all_css );
        }

        $this->settings['bpProfileSlug'] = preg_replace('/\s+/', '', trim( $this->settings['bpProfileSlug'] ) );
        $this->settings['bpGroupSlug'] = preg_replace('/\s+/', '', trim( $this->settings['bpGroupSlug'] ) );

        if ( ! isset( $this->settings['bpProfileSlug'] ) || empty( $this->settings['bpProfileSlug'] ) || $this->settings['bpProfileSlug'] === 'messages' ) {
            $this->settings['bpProfileSlug'] = 'bp-messages';
        }

        if ( ! isset( $this->settings['bpGroupSlug'] ) || empty( $this->settings['bpGroupSlug'] ) ) {
            $this->settings['bpGroupSlug'] = 'bp-messages';
        }

        if ( ! isset( $this->settings['wooCommerceMessagesSlug'] ) ) {
            $this->settings['wooCommerceMessagesSlug'] = 'messages';
        }
        $this->settings['wooCommerceMessagesSlug'] = sanitize_title( $this->settings['wooCommerceMessagesSlug'] );
        if ( $this->settings['wooCommerceMessagesSlug'] === '' ) {
            $this->settings['wooCommerceMessagesSlug'] = 'messages';
        }

        // Ensure required output formats are in attachmentsFormats when optimization is enabled
        if ( is_array( $this->settings['attachmentsFormats'] ) ) {
            $required_formats = array();
            if ( $this->settings['transcodingImageFormat'] !== 'original' ) {
                $required_formats = array_merge( $required_formats, array( 'jpg', 'jpeg', 'webp', 'avif' ) );
            }
            if ( $this->settings['transcodingVideoFormat'] !== 'original' ) {
                $required_formats = array_merge( $required_formats, array( 'mp4' ) );
            }
            foreach ( $required_formats as $fmt ) {
                if ( ! in_array( $fmt, $this->settings['attachmentsFormats'], true ) ) {
                    $this->settings['attachmentsFormats'][] = $fmt;
                }
            }
        }

        $this->settings['updateTime'] = time();

        wp_unschedule_hook('better_messages_send_notifications');

        // Save emailCustomHtml to separate option to avoid bloating main settings
        if( isset( $this->settings['emailCustomHtml'] ) && ! empty( $this->settings['emailCustomHtml'] ) ){
            update_option( 'better-messages-email-custom-html', $this->settings['emailCustomHtml'], false );
        }
        $this->settings['emailCustomHtml'] = ''; // Don't store in main settings

        update_option( 'bp-better-chat-settings', $this->settings );
        Better_Messages()->settings = $this->settings;
        do_action( 'bp_better_chat_settings_updated', $this->settings );

        update_option( 'bp-better-chat-settings-updated', true );

        // Ensure user index is created if initial sync never completed
        $last_sync = get_option( 'bm_sync_user_roles_index_finish', false );
        if ( ! $last_sync ) {
            Better_Messages()->users->sync_all_users();
        }
    }

}
function Better_Messages_Options()
{
    return Better_Messages_Options::instance();
}
