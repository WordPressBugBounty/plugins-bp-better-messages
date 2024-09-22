var OneSignalUpdate = (function() {
    var timeoutId;

    return function() {
        var user_id = parseInt(Better_Messages.user_id);

        BetterMessages.getApi().then( function ( api ) {
            BetterMessages.getSetting( 'oneSignal' ).then( function ( savedOneSignal ) {
                let updateNeeded = false;

                OneSignal.getUserId().then( function (subscriptionId) {
                    if( ! subscriptionId ) return;

                    if( ! savedOneSignal ){
                        updateNeeded = true;
                    } else {
                        if( savedOneSignal.user_id != user_id ){
                            updateNeeded = true;
                        }

                        if( savedOneSignal.subscription_id != subscriptionId ){
                            updateNeeded = true;
                        }
                    }

                    if( updateNeeded ){
                        // Clear the previous timeout
                        if (timeoutId) {
                            clearTimeout(timeoutId);
                        }

                        // Set a new timeout
                        timeoutId = setTimeout(function() {
                            api.post( 'oneSignal/updateSubscription', {
                                subscription_id: subscriptionId
                            }).then( function ( response ) {
                                BetterMessages.updateSetting('oneSignal', {
                                    user_id: user_id,
                                    subscription_id: subscriptionId
                                });

                                OneSignal.setExternalUserId(user_id);
                            }).catch( function ( error ) {
                                console.error( error );
                            })
                        }, 3000 )
                    }
                });
            } )
        } )
    }
})();


if( typeof OneSignal !== 'undefined' ) {
    OneSignal.push(function () {
        OneSignal.isPushNotificationsEnabled().then( function ( isSubscribed ) {
            if( isSubscribed ) OneSignalUpdate();
        });

        OneSignal.on('subscriptionChange', function (isSubscribed) {
            if( isSubscribed ) OneSignalUpdate();
        })
    })
}
