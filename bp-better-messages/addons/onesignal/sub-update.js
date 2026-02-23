(function() {
    var user_id = parseInt(Better_Messages.user_id);
    var timeoutId;
    var sdkVersion = Better_Messages.oneSignalSdk || '';

    /**
     * New SDK (v16) branch
     * Used when OneSignal WP plugin 3.x is active (both V2 and V3 paths)
     */
    function initNewSdk() {
        window.OneSignalDeferred = window.OneSignalDeferred || [];
        OneSignalDeferred.push(function(OneSignal) {
            OneSignal.login(user_id.toString());

            checkAndUpdateSubscription(OneSignal);

            OneSignal.User.PushSubscription.addEventListener("change", function(event) {
                checkAndUpdateSubscription(OneSignal);
            });
        });
    }

    function checkAndUpdateSubscription(OneSignal) {
        var subscriptionId = OneSignal.User.PushSubscription.id;
        var optedIn = OneSignal.User.PushSubscription.optedIn;

        if (!optedIn || !subscriptionId) {
            return;
        }

        BetterMessages.getApi().then(function(api) {
            BetterMessages.getSetting('oneSignal2025').then(function(savedOneSignal) {
                var updateNeeded = false;

                if (!savedOneSignal) {
                    updateNeeded = true;
                } else {
                    if (savedOneSignal.user_id != user_id) {
                        updateNeeded = true;
                    }
                    if (savedOneSignal.subscription_id != subscriptionId) {
                        updateNeeded = true;
                    }
                }

                if (updateNeeded) {
                    if (timeoutId) {
                        clearTimeout(timeoutId);
                    }

                    timeoutId = setTimeout(function() {
                        api.post('oneSignal/updateSubscription', {
                            subscription_id: subscriptionId
                        }).then(function(response) {
                            BetterMessages.updateSetting('oneSignal2025', {
                                user_id: user_id,
                                subscription_id: subscriptionId
                            });
                        }).catch(function(error) {
                            console.error(error);
                        });
                    }, 3000);
                }
            });
        });
    }

    /**
     * Legacy SDK branch
     * Kept for backward compatibility with old OneSignal WP plugin versions
     * that used the pre-v16 SDK
     */
    function initLegacySdk() {
        var OneSignalUpdate = function() {
            BetterMessages.getApi().then(function(api) {
                BetterMessages.getSetting('oneSignal').then(function(savedOneSignal) {
                    var updateNeeded = false;

                    OneSignal.getUserId().then(function(subscriptionId) {
                        if (!subscriptionId) return;

                        if (!savedOneSignal) {
                            updateNeeded = true;
                        } else {
                            if (savedOneSignal.user_id != user_id) {
                                updateNeeded = true;
                            }
                            if (savedOneSignal.subscription_id != subscriptionId) {
                                updateNeeded = true;
                            }
                        }

                        if (updateNeeded) {
                            if (timeoutId) {
                                clearTimeout(timeoutId);
                            }

                            timeoutId = setTimeout(function() {
                                api.post('oneSignal/updateSubscription', {
                                    subscription_id: subscriptionId
                                }).then(function(response) {
                                    BetterMessages.updateSetting('oneSignal', {
                                        user_id: user_id,
                                        subscription_id: subscriptionId
                                    });
                                    OneSignal.setExternalUserId(user_id);
                                }).catch(function(error) {
                                    console.error(error);
                                });
                            }, 3000);
                        }
                    });
                });
            });
        };

        OneSignal.push(function() {
            OneSignal.isPushNotificationsEnabled().then(function(isSubscribed) {
                if (isSubscribed) OneSignalUpdate();
            });
            OneSignal.on('subscriptionChange', function(isSubscribed) {
                if (isSubscribed) OneSignalUpdate();
            });
        });
    }

    // SDK version routing
    if (sdkVersion === 'v16') {
        initNewSdk();
    } else if (typeof OneSignal !== 'undefined' && typeof OneSignal.getUserId === 'function') {
        initLegacySdk();
    } else {
        initNewSdk();
    }
})();
