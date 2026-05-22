(function () {
    'use strict';

    if (typeof window === 'undefined' || !document) {
        return;
    }

    var body = document.body;

    if (!body || !body.classList.contains('bb-readylaunch-template')) {
        return;
    }

    body.classList.add('bm-bb-readylaunch-ready');

    function isRlDark() {
        return document.body.classList.contains('bb-rl-dark-mode');
    }

    function syncDarkMode() {
        var dark = isRlDark();
        var hasDark = document.body.classList.contains('bm-messages-dark');
        var hasLight = document.body.classList.contains('bm-messages-light');

        if (dark && hasDark && !hasLight) {
            return;
        }
        if (!dark && hasLight && !hasDark) {
            return;
        }

        if (dark) {
            document.body.classList.add('bm-messages-dark');
            document.body.classList.remove('bm-messages-light');
        } else {
            document.body.classList.add('bm-messages-light');
            document.body.classList.remove('bm-messages-dark');
        }
    }

    syncDarkMode();

    if (typeof MutationObserver === 'function') {
        var lastDark = isRlDark();
        var darkObserver = new MutationObserver(function () {
            var currentDark = isRlDark();
            if (currentDark === lastDark) {
                return;
            }
            lastDark = currentDark;
            syncDarkMode();
        });

        darkObserver.observe(document.body, { attributes: true, attributeFilter: ['class'] });
    }

    function findSideMenuMessagesLink() {
        var links = document.querySelectorAll('.bb-rl-left-panel-menu-link');
        for (var i = 0; i < links.length; i++) {
            var href = links[i].getAttribute('href') || '';
            if (/\/messages\/?(?:[?#]|$)/.test(href)) {
                return links[i];
            }
        }
        return null;
    }

    function setBadge(host, count, className) {
        if (!host) {
            return;
        }

        var badge = host.querySelector('.' + className);
        var n = parseInt(count, 10) || 0;

        if (n > 0) {
            if (!badge) {
                badge = document.createElement('span');
                badge.className = className;
                host.appendChild(badge);
            }
            badge.textContent = n > 99 ? '99+' : String(n);
            badge.removeAttribute('hidden');
            badge.style.display = '';
        } else if (badge) {
            badge.setAttribute('hidden', 'hidden');
            badge.style.display = 'none';
        }
    }

    function updateUnreadBadges(unread) {
        setBadge(
            document.querySelector('#header-messages-dropdown-elem .notification-link'),
            unread,
            'count'
        );
        setBadge(
            findSideMenuMessagesLink(),
            unread,
            'bm-bb-rl-side-unread'
        );
        setBadge(
            document.querySelector('#user-bp_better_messages_tab, #user-messages'),
            unread,
            'bm-bb-rl-nav-unread'
        );
    }

    function registerHook() {
        if (typeof window.wp === 'undefined' || !window.wp.hooks || typeof window.wp.hooks.addAction !== 'function') {
            return false;
        }
        window.wp.hooks.addAction(
            'better_messages_update_unread',
            'better_messages_bb_readylaunch',
            updateUnreadBadges
        );
        return true;
    }

    if (!registerHook()) {
        var tries = 0;
        var interval = setInterval(function () {
            tries++;
            if (registerHook() || tries > 40) {
                clearInterval(interval);
            }
        }, 250);
    }

    // The main BB addon's `relocateBBPressPMLink` targets legacy `.bs-reply-list-item`
    // selectors that don't exist in RL. RL replies use `.bb-rl-forum-reply-list-item`
    // and the actions dropdown is `<ul class="bb_more_options_list">`.
    function relocateBBPressPMLinkRL() {
        var replies = document.querySelectorAll('.bb-rl-forum-reply-list-item');
        for (var i = 0; i < replies.length; i++) {
            var reply = replies[i];
            var pm = reply.querySelector('.bpbm-private-message-link-buddypress');
            if (!pm || pm.dataset.bmRelocatedRl === '1') {
                continue;
            }
            var menuList = reply.querySelector('.bb_more_options_list');
            if (!menuList) {
                continue;
            }

            var label = (pm.textContent || '').trim();

            var li = document.createElement('li');
            li.className = 'bm-bb-rl-pm-item';

            var link = document.createElement('a');
            link.href = pm.href;
            link.className = 'bbp-reply-pm-link bpbm-private-message-link-buddyboss';
            link.textContent = label;

            li.appendChild(link);
            menuList.appendChild(li);

            pm.style.display = 'none';
            pm.dataset.bmRelocatedRl = '1';
        }
    }

    function watchBBPressReplies() {
        relocateBBPressPMLinkRL();
        if (typeof MutationObserver === 'function') {
            var container = document.querySelector('#bbpress-forums') || document.body;
            var bbpObserver = new MutationObserver(function () {
                relocateBBPressPMLinkRL();
            });
            bbpObserver.observe(container, { childList: true, subtree: true });
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', watchBBPressReplies);
    } else {
        watchBBPressReplies();
    }

    function injectMessagesPageTitle() {
        if (!document.body.classList.contains('bm-bb-rl-page-title')) {
            return;
        }
        if (typeof window.BMBBReadyLaunch === 'undefined') {
            return;
        }
        var primary = document.querySelector('.bb-rl-primary-container');
        if (!primary) {
            return;
        }
        if (primary.querySelector('.bm-bb-rl-msg-page-header')) {
            return;
        }
        var title = (window.BMBBReadyLaunch.messagesTitle || 'Messages');

        var header = document.createElement('div');
        header.className = 'bb-rl-secondary-header flex items-center bm-bb-rl-msg-page-header';
        var heading = document.createElement('div');
        heading.className = 'bb-rl-entry-heading flex';
        var h2 = document.createElement('h2');
        h2.textContent = title;
        heading.appendChild(h2);
        header.appendChild(heading);

        primary.insertBefore(header, primary.firstChild);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', injectMessagesPageTitle);
    } else {
        injectMessagesPageTitle();
    }
})();
