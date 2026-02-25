<?php
defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Better_Messages_SureDash' ) ) {

    class Better_Messages_SureDash
    {

        public static function instance()
        {
            static $instance = null;

            if ( null === $instance ) {
                $instance = new Better_Messages_SureDash();
            }

            return $instance;
        }

        public function __construct()
        {
            if ( Better_Messages()->settings['SDenableProfileButton'] === '1' || Better_Messages()->settings['SDenableAuthorButton'] === '1' ) {
                add_action( 'suredash_before_user_badges', array( $this, 'message_button' ), 10, 1 );
            }

            if ( Better_Messages()->settings['SDenableSidebarMessages'] === '1' ) {
                add_action( 'wp_footer', array( $this, 'sidebar_js' ) );
            }

            if ( Better_Messages()->settings['SDenableDropdownMessages'] === '1' ) {
                add_action( 'wp_footer', array( $this, 'dropdown_js' ) );
            }

            add_action( 'wp_head', array( $this, 'counter_script' ) );
            add_action( 'wp_footer', array( $this, 'dark_mode_script' ) );

            add_filter( 'better_messages_rest_user_item', array( $this, 'rest_user_item' ), 20, 3 );

            if ( Better_Messages()->settings['chatPage'] === 'suredash-portal' ) {
                add_filter( 'suredashboard_portal_sub_queries', array( $this, 'register_messages_subpage' ) );
                add_filter( 'suredash_home_content', array( $this, 'render_messages_page' ), 10, 2 );
                add_action( 'wp_footer', array( $this, 'portal_height_script' ) );
            }

        }

        /**
         * Render message button next to user badges.
         * On user-view page: full button. On post/comment author: compact icon.
         */
        public function message_button( $user_id )
        {
            if ( ! is_user_logged_in() ) {
                return;
            }

            $user_id = (int) $user_id;

            if ( $user_id === (int) get_current_user_id() ) {
                return;
            }

            if ( $user_id <= 0 ) {
                return;
            }

            $is_user_view = $this->is_user_view_page();

            if ( $is_user_view && Better_Messages()->settings['SDenableProfileButton'] !== '1' ) {
                return;
            }

            if ( ! $is_user_view && Better_Messages()->settings['SDenableAuthorButton'] !== '1' ) {
                return;
            }

            $url   = Better_Messages()->functions->get_user_messages_url( get_current_user_id() ) . '&to=' . $user_id;
            $label = esc_attr_x( 'Send Message', 'SureDash Integration', 'bp-better-messages' );

            if ( $is_user_view ) {
                $this->render_profile_button( $url, $label, $user_id );
            } else {
                $this->render_author_button( $url, $label, $user_id );
            }
        }

        /**
         * Full button on user-view profile page
         */
        private function render_profile_button( $url, $label, $user_id )
        {
            echo '<div class="bm-suredash-profile-actions" style="margin-top:8px;display:flex;gap:8px;flex-wrap:wrap;">';

            echo '<a href="' . esc_url( $url ) . '" class="bm-suredash-pm-button portal-button button-secondary" style="display:inline-flex;align-items:center;gap:6px;text-decoration:none;">';
            echo '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path></svg>';
            echo '<span>' . esc_html( $label ) . '</span>';
            echo '</a>';

            if ( Better_Messages()->functions->can_use_premium_code() ) {
                if ( Better_Messages()->settings['SDProfileVideoCall'] === '1' && Better_Messages()->settings['videoCalls'] === '1' ) {
                    $video_url   = add_query_arg( array( 'fast-call' => '', 'to' => $user_id, 'type' => 'video' ), get_site_url() );
                    $video_label = esc_attr_x( 'Video Call', 'SureDash Integration', 'bp-better-messages' );
                    echo '<a href="' . esc_url( $video_url ) . '" class="bm-suredash-video-button portal-button button-secondary" style="display:inline-flex;align-items:center;gap:6px;text-decoration:none;">';
                    echo '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="23 7 16 12 23 17 23 7"></polygon><rect x="1" y="5" width="15" height="14" rx="2" ry="2"></rect></svg>';
                    echo '<span>' . esc_html( $video_label ) . '</span>';
                    echo '</a>';
                }

                if ( Better_Messages()->settings['SDProfileAudioCall'] === '1' && Better_Messages()->settings['audioCalls'] === '1' ) {
                    $audio_url   = add_query_arg( array( 'fast-call' => '', 'to' => $user_id, 'type' => 'audio' ), get_site_url() );
                    $audio_label = esc_attr_x( 'Audio Call', 'SureDash Integration', 'bp-better-messages' );
                    echo '<a href="' . esc_url( $audio_url ) . '" class="bm-suredash-audio-button portal-button button-secondary" style="display:inline-flex;align-items:center;gap:6px;text-decoration:none;">';
                    echo '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.127.96.361 1.903.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0 1 22 16.92z"></path></svg>';
                    echo '<span>' . esc_html( $audio_label ) . '</span>';
                    echo '</a>';
                }
            }

            echo '</div>';
        }

        /**
         * Compact icon button next to post/comment authors
         */
        private function render_author_button( $url, $label, $user_id )
        {
            echo '<a href="' . esc_url( $url ) . '" class="bm-suredash-author-pm" title="' . esc_attr( $label ) . '" style="display:inline-flex;align-items:center;margin-left:4px;color:inherit;opacity:0.6;text-decoration:none;" onmouseover="this.style.opacity=1" onmouseout="this.style.opacity=0.6">';
            echo '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path></svg>';
            echo '</a>';
        }

        /**
         * Check if we're on the user-view page
         */
        private function is_user_view_page()
        {
            if ( function_exists( 'suredash_get_sub_queried_page' ) ) {
                return suredash_get_sub_queried_page() === 'user-view';
            }

            $request_uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( $_SERVER['REQUEST_URI'] ) : '';
            return strpos( $request_uri, '/user-view/' ) !== false;
        }

        /**
         * Inject Messages link into SureDash sidebar navigation
         */
        public function sidebar_js()
        {
            if ( ! is_user_logged_in() ) {
                return;
            }

            $messages_url = Better_Messages()->functions->get_link();
            $label        = esc_js( _x( 'Messages', 'SureDash Integration', 'bp-better-messages' ) );
            $unread       = Better_Messages()->functions->get_user_unread_count( get_current_user_id() );
            $badge_style  = $unread > 0 ? '' : 'display:none;';
            $badge_text   = $unread > 0 ? intval( $unread ) : '';

            ob_start();
            ?>
            <script type="text/javascript">
                (function(){
                    var isMessages = <?php echo json_encode( function_exists('suredash_get_sub_queried_page') && suredash_get_sub_queried_page() === 'messages' ); ?>;

                    var messagesLink = document.createElement('a');
                    messagesLink.href = '<?php echo esc_url( $messages_url ); ?>';
                    messagesLink.className = 'portal-aside-feed portal-aside-group-body bm-suredash-nav-messages' + (isMessages ? ' active' : '');
                    messagesLink.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="portal-feeds-icon" style="flex-shrink:0;"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path></svg>' +
                        '<span class="portal-aside-feed-text"><?php echo $label; ?></span>' +
                        '<span class="bm-suredash-unread-count" style="<?php echo $badge_style; ?>background:#ef4444;color:#fff;border-radius:10px;padding:0 6px;font-size:11px;line-height:20px;margin-left:auto;"><?php echo $badge_text; ?></span>';

                    var feedLink = document.querySelector('.portal-aside-feed');
                    if( feedLink && feedLink.parentNode ){
                        feedLink.parentNode.insertBefore(messagesLink, feedLink.nextSibling);
                    } else {
                        var groupWrap = document.querySelector('.portal-aside-group-wrap');
                        if( groupWrap ){
                            var firstGroup = groupWrap.querySelector('.portal-aside-group');
                            if( firstGroup ){
                                groupWrap.insertBefore(messagesLink, firstGroup);
                            } else {
                                groupWrap.appendChild(messagesLink);
                            }
                        }
                    }
                })();
            </script>
            <?php
            $script = ob_get_clean();

            echo Better_Messages()->functions->minify_js( $script );
        }

        /**
         * Inject Messages link into SureDash profile dropdown
         */
        public function dropdown_js()
        {
            if ( ! is_user_logged_in() ) {
                return;
            }

            $messages_url = Better_Messages()->functions->get_link();
            $label        = esc_js( _x( 'Messages', 'SureDash Integration', 'bp-better-messages' ) );
            $unread       = Better_Messages()->functions->get_user_unread_count( get_current_user_id() );
            $badge_style  = $unread > 0 ? '' : 'display:none;';
            $badge_text   = $unread > 0 ? intval( $unread ) : '';

            ob_start();
            ?>
            <script type="text/javascript">
                (function(){
                    var menuLinks = document.querySelector('.portal-user-menu-links');
                    if( menuLinks ){
                        var msgLink = document.createElement('a');
                        msgLink.href = '<?php echo esc_url( $messages_url ); ?>';
                        msgLink.className = 'portal-user-menu-link bm-suredash-menu-messages';
                        msgLink.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0;"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path></svg>' +
                            '<span class="portal-user-menu-link-title"><?php echo $label; ?></span>' +
                            '<span class="bm-suredash-unread-count" style="<?php echo $badge_style; ?>background:#ef4444;color:#fff;border-radius:10px;padding:0 6px;font-size:11px;line-height:20px;margin-left:auto;"><?php echo $badge_text; ?></span>';
                        menuLinks.appendChild(msgLink);
                    }
                })();
            </script>
            <?php
            $script = ob_get_clean();

            echo Better_Messages()->functions->minify_js( $script );
        }

        /**
         * Real-time unread counter updates
         */
        public function counter_script()
        {
            if ( ! is_user_logged_in() ) {
                return;
            }

            ob_start();
            ?>
            <script type="text/javascript">
                wp.hooks.addAction('better_messages_update_unread', 'bm_suredash', function( unread ) {
                    var counters = document.querySelectorAll('.bm-suredash-unread-count');
                    counters.forEach(function(counter){
                        if( unread > 0 ){
                            counter.textContent = unread;
                            counter.style.display = '';
                        } else {
                            counter.textContent = '';
                            counter.style.display = 'none';
                        }
                    });
                });
            </script>
            <?php
            $script = ob_get_clean();

            echo Better_Messages()->functions->minify_js( $script );
        }

        /**
         * Bridge SureDash dark/light mode to Better Messages
         */
        public function dark_mode_script()
        {
            ob_start();
            ?>
            <script type="text/javascript">
                (function(){
                    function bmSuredashSyncDarkMode(){
                        var body = document.body;
                        var isDark = body.classList.contains('dark-mode') || body.classList.contains('palette-dark');
                        var hasDark = body.classList.contains('bm-messages-dark');
                        var hasLight = body.classList.contains('bm-messages-light');

                        if( isDark && !hasDark ){
                            body.classList.add('bm-messages-dark');
                            body.classList.remove('bm-messages-light');
                        } else if( !isDark && !hasLight ){
                            body.classList.add('bm-messages-light');
                            body.classList.remove('bm-messages-dark');
                        }
                    }

                    bmSuredashSyncDarkMode();

                    var observer = new MutationObserver(function(mutations){
                        for(var i = 0; i < mutations.length; i++){
                            if(mutations[i].type === 'attributes' && mutations[i].attributeName === 'class'){
                                bmSuredashSyncDarkMode();
                                break;
                            }
                        }
                    });

                    observer.observe(document.body, { attributes: true, attributeFilter: ['class'] });

                    document.addEventListener('suredashColorSwitcherChanged', function(){
                        bmSuredashSyncDarkMode();
                    });
                })();
            </script>
            <?php
            $script = ob_get_clean();

            echo Better_Messages()->functions->minify_js( $script );
        }

        /**
         * Register "messages" as a SureDash portal sub-page
         */
        public function register_messages_subpage( $queries )
        {
            $queries[] = 'messages';
            return $queries;
        }

        /**
         * Render Better Messages page inside the SureDash portal
         */
        public function render_messages_page( $content, $type )
        {
            if ( $type !== 'messages' ) {
                return $content;
            }

            if ( ! is_user_logged_in() ) {
                return Better_Messages()->functions->render_login_form();
            }

            return Better_Messages()->functions->get_page( array( 'full_screen' => false ) );
        }

        /**
         * Customize user data in REST API for SureDash profile URLs
         */
        public function rest_user_item( $item, $user_id, $include_personal )
        {
            if ( $user_id <= 0 ) {
                return $item;
            }

            if ( function_exists( 'suredash_get_user_view_link' ) ) {
                $profile_url = suredash_get_user_view_link( $user_id );
                if ( ! empty( $profile_url ) ) {
                    $item['url'] = $profile_url;
                }
            } else {
                $community_slug = function_exists( 'suredash_get_community_slug' ) ? suredash_get_community_slug() : 'portal';
                $item['url'] = home_url( '/' . $community_slug . '/user-view/' . $user_id . '/' );
            }

            return $item;
        }

        /**
         * Make BM fill full portal height via CSS variables + JS measurement
         */
        public function portal_height_script()
        {
            ?>
            <style type="text/css">
                .wp-block-suredash-content:has(.bp-messages-wrap-main) {
                    padding-bottom: 0 !important;
                }
                #portal-main-content .bp-messages-wrap-main .bp-messages-wrap:not(.bp-messages-full-screen, .bp-messages-mobile),
                #portal-main-content .bp-messages-wrap-main .bp-messages-threads-wrapper {
                    height: calc(var(--bm-sd-window-height) - var(--bm-sd-top-offset, 0px)) !important;
                }
                #portal-main-content .bp-messages-wrap-main .bp-messages-wrap {
                    border-radius: 0 !important;
                    box-shadow: none;
                    border: none;
                }
            </style>
            <?php

            ob_start();
            ?>
            <script type="text/javascript">
                (function(){
                    if( window.location.hash ){
                        window.location.hash = window.location.hash.replace('&scrollToContainer', '');
                    }
                    if( window.location.search ){
                        var newUrl = window.location.href.replace('&scrollToContainer', '').replace('?scrollToContainer&', '?').replace('?scrollToContainer', '');
                        if( newUrl !== window.location.href ){
                            history.replaceState(null, '', newUrl);
                        }
                    }

                    function bmSuredashUpdateHeight(){
                        var wrap = document.querySelector('#portal-main-content .bp-messages-wrap-main');
                        if( ! wrap ) return;

                        var style = document.querySelector('#bm-suredash-height-style');
                        if( ! style ){
                            style = document.createElement('style');
                            style.id = 'bm-suredash-height-style';
                            document.head.appendChild(style);
                        }

                        var rect = wrap.getBoundingClientRect();
                        var css = ':root{';
                        css += '--bm-sd-window-height:' + window.innerHeight + 'px;';
                        css += '--bm-sd-top-offset:' + Math.max(0, rect.top) + 'px;';
                        css += '}';

                        style.textContent = css;
                        wrap.style.height = 'auto';
                    }

                    bmSuredashUpdateHeight();
                    window.addEventListener('resize', bmSuredashUpdateHeight);
                })();
            </script>
            <?php
            $script = ob_get_clean();

            echo Better_Messages()->functions->minify_js( $script );
        }
    }
}
