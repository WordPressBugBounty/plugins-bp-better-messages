(function () {
    if (!window.wp || !window.wp.hooks || !window.wp.element) return;

    var cfg = window.bmMultivendorxV5 || {};
    var routeSlug = cfg.routeSlug || 'bm-messages';
    var metaKey   = cfg.metaKey   || 'bm_livechat_enabled';

    var el = window.wp.element;
    var createElement = el.createElement;
    var useEffect = el.useEffect;
    var useRef = el.useRef;

    var resizeRaf = 0;

    function updateAvailableHeight(rootEl) {
        if (!rootEl) return;
        var nav = document.querySelector('.top-navbar-wrapper');
        if (nav) {
            document.documentElement.style.setProperty(
                '--bm-mvx-navbar-h',
                Math.round(nav.getBoundingClientRect().height) + 'px'
            );
        }
        var available = Math.max(400, window.innerHeight - rootEl.getBoundingClientRect().top);
        document.documentElement.style.setProperty('--bm-mvx-dashboard-h', available + 'px');
    }

    function BmMessagesRoute() {
        var ref = useRef(null);

        useEffect(function () {
            if (!ref.current) return;

            // BM owns `.bp-messages-wrap-main`'s DOM lifecycle (it mounts its
            // own React root into it via `BetterMessages.initialize()`).
            // Append imperatively so React doesn't try to reconcile children.
            var wrap = document.createElement('div');
            wrap.className = 'bp-messages-wrap-main';
            // Avoid `data-full-screen="1"`: BM reparents the wrap to <body>
            // for fixed-position layout in that mode, yanking it out of the
            // dashboard. Height is handled via the CSS variables instead.
            ref.current.appendChild(wrap);

            updateAvailableHeight(ref.current);

            var onResize = function () {
                if (resizeRaf) return;
                resizeRaf = window.requestAnimationFrame(function () {
                    resizeRaf = 0;
                    updateAvailableHeight(ref.current);
                });
            };
            window.addEventListener('resize', onResize);

            if (window.BetterMessages && typeof window.BetterMessages.initialize === 'function') {
                window.BetterMessages.initialize();
            }
            if (window.BetterMessages && typeof window.BetterMessages.parseHash === 'function') {
                window.BetterMessages.parseHash();
            }

            return function cleanup() {
                window.removeEventListener('resize', onResize);
                if (resizeRaf) {
                    window.cancelAnimationFrame(resizeRaf);
                    resizeRaf = 0;
                }
                if (wrap.reactRoot) {
                    try { wrap.reactRoot.unmount(); } catch (e) {}
                }
                if (wrap.parentNode) wrap.parentNode.removeChild(wrap);
                if (window.BetterMessages && typeof window.BetterMessages.resetMainVisibleThread === 'function') {
                    window.BetterMessages.resetMainVisibleThread();
                }
            };
        }, []);

        return createElement('div', { ref: ref, className: 'bm-mvx-dashboard-root' });
    }

    // Vendor dashboard reads routes via the `multivendorx_dashboard_routes`
    // filter — `window.registerMultiVendorXRoute()` only feeds the admin
    // dashboard, which lives at a different mount point.
    window.wp.hooks.addFilter(
        'multivendorx_dashboard_routes',
        'better-messages/mvx-v5',
        function (routes) {
            if (!Array.isArray(routes)) return routes;
            if (routes.some(function (r) { return r && r.tab === routeSlug; })) return routes;
            return routes.concat([{ tab: routeSlug, component: BmMessagesRoute }]);
        }
    );

    // Inject a "Live Chat" tab into the vendor's Settings page by wrapping
    // MVX's webpack `require.context` with a proxy that exposes one extra
    // `./live-chat.ts` key. Fragile by design — depends on the v5 bundle
    // shape (verified against MVX 5.0.1).
    window.wp.hooks.addFilter(
        'multivendorx_settings_context',
        'better-messages/mvx-v5-livechat',
        function (ctx, contextType) {
            if (contextType !== 'dashboardSettings') return ctx;
            if (typeof ctx !== 'function' || typeof ctx.keys !== 'function') return ctx;

            var origKeys = ctx.keys();
            var bmKey = './live-chat.ts';
            if (origKeys.indexOf(bmKey) !== -1) return ctx;

            // Submit to the per-store endpoint so values land in store meta
            // via `$store->update_meta($key, $val)`. The site-wide `settings`
            // endpoint persists to a global option instead.
            var storeId = (window.appLocalizer && window.appLocalizer.store_id) || '';
            var submitUrl = storeId ? ('stores/' + storeId) : 'settings';

            var bmModule = {
                'default': {
                    id: 'live-chat',
                    priority: 99,
                    headerTitle: cfg.settingsTitle || 'Live Chat',
                    headerDescription: cfg.settingsDescription || '',
                    headerIcon: 'live-chat',
                    submitUrl: submitUrl,
                    modal: [{
                        key: metaKey,
                        type: 'checkbox',
                        label: cfg.settingsLabel || 'Enable live chat',
                        desc: cfg.settingsHelp || '',
                        options: [{ key: metaKey, value: metaKey }],
                        look: 'toggle'
                    }]
                }
            };

            function wrapper(key) {
                return key === bmKey ? bmModule : ctx(key);
            }
            wrapper.keys = function () { return origKeys.concat([bmKey]); };
            wrapper.resolve = ctx.resolve;
            wrapper.id = ctx.id;
            return wrapper;
        }
    );
})();
