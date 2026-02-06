var html = document.querySelector('html');

var settings = window.BM_Fluent_Community;

const path = '/messages';

function extractPathWithHash(url) {
  try {
    const u = new URL(url, window.location.origin);
    return u.pathname + u.hash;
  } catch (e) {
    const m = url.match(/https?:\/\/[^\/]+(\/.*)/);
    return m ? m[1] : url;
  }
}

wp.hooks.addAction('better_messages_update_unread', 'bm_fluent_com', function( unread ){
  var unreadCounters = document.querySelectorAll('.bm-unread-badge');

  unreadCounters.forEach(function( counter ){
    counter.innerHTML = unread;

    if( unread > 0 ){
      counter.style.display = '';
    } else {
      counter.style.display = 'none';
    }
  });
});

wp.hooks.addFilter('better_messages_navigate_url', 'bm_fluent_com', function( redirected, url ){
  if( typeof window.fluentFrameworkAppRouter !== 'undefined' && typeof window.fluentFrameworkAppRouter.push === 'function' ) {
    try {
      const fcUrl = extractPathWithHash(url);

      if( fcUrl.startsWith( path ) ) {
        window.fluentFrameworkAppRouter.push(fcUrl);
        return true;
      }
    } catch (e){
      console.error('Fluent Community navigation error:', e);
    }
  }

  return redirected;
});


const isMobile = document.body.classList.contains('bp-messages-mobile');
const fullSize = ( typeof settings !== 'undefined' && typeof settings.fullScreen !== 'undefined' ) ? settings.fullScreen : false;
const containerClass = fullSize ? 'fcom_full_size_container' : 'fcom_boxed_container';
const containerStyle = fullSize ? 'padding: 0;' : 'padding: 20px;';
const header = ( ! isMobile && typeof settings !== 'undefined' && settings.title !== '' ) ? '<div class="fhr_content_layout_header"><h1 class="fcom_page_title">' + settings.title + '</h1></div>' : '';

document.addEventListener('fluentCommunityUtilReady', function () {
  updateDynamicCSS();

  window.FluentCommunityUtil.hooks.addFilter("fluent_com_portal_routes", "fluent_chat_route", function (a) {
    return a.push({
      path: path,
      name: "better_messages",
      component: {
        template: header +
          '<div class="fcom_better_messages_wrap ' + containerClass + '" style="' + containerStyle + '">' +
          '<div class="bp-messages-wrap-main" style="height: 900px"></div>' +
          '</div>',
        mounted() {
          updateDynamicCSS();
          BetterMessages.initialize();
          BetterMessages.parseHash();

        },
        beforeRouteLeave(e, n) {
          if( BetterMessages.isInCall()){
            return false;
          }

          document.body.classList.remove('bp-messages-mobile');

          var container = document.querySelector('.bp-messages-wrap-main');
          if( container ){
            if( container.reactRoot ) container.reactRoot.unmount()
            container.remove();
          }

          BetterMessages.resetMainVisibleThread();
        }
      },
      meta: {active: "better-messages"}
    }), a;
  });
});

function updateDynamicCSS(){
  var body = document.body;

  if( html.classList.contains('dark') ){
    body.classList.add('bm-messages-dark');
    body.classList.remove('bm-messages-light');
  } else {
    body.classList.add('bm-messages-light');
    body.classList.remove('bm-messages-dark');
  }

  var style = document.querySelector('#bm-fcom-footer-height-style');

  if ( ! style ) {
    style = document.createElement('style');
    style.id = 'bm-fcom-footer-height-style';
    document.head.appendChild(style);
  }

  var css = ':root{';

  var windowHeight = window.innerHeight;
  css += `--bm-fcom-window-height:${windowHeight}px;`;

  var mobileMenu = document.querySelector('.fcom_mobile_menu');
  if( mobileMenu ) {
    var height = mobileMenu.offsetHeight;
    css += `--bm-fcom-footer-height:${height}px;`;
  }

  var topMenu = document.querySelector('.fcom_top_menu');

  if( topMenu ) {
    var topMenuHeight = topMenu.offsetHeight ;
    css += `--bm-fcom-menu-height:${topMenuHeight}px;`;
  }

  var headerTitle = document.querySelector('.fhr_content_layout_header');

  if( headerTitle ) {
    var headerTitleHeight = headerTitle.offsetHeight ;
    css += `--bm-fcom-title-height:${headerTitleHeight}px;`;
  }

  style.innerHTML = css + '}';
}

const config = { attributes: true, attributeFilter: ['class'] };

// Callback function to execute when mutations are observed
const callback = function(mutationsList, observer) {
  for(let mutation of mutationsList) {
    if (mutation.type === 'attributes' && mutation.attributeName === 'class') {
      updateDynamicCSS();
    }
  }
};

// Create an observer instance linked to the callback function
const observer = new MutationObserver(callback);

// Start observing the target node for configured mutations
observer.observe(html, config);
