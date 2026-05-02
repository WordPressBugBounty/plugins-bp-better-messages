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

  document.addEventListener('click', function(e) {
    var link = e.target.closest('.fcom_mobile_menu a');
    if( link && link.querySelector('.bm-unread-badge') && window.location.pathname.endsWith(path) && window.location.hash && window.location.hash !== '#/' && window.location.hash !== '#' ) {
      e.preventDefault();
      e.stopPropagation();
      window.location.hash = '#/';
    }
  }, true);

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

  if (wantCourseChatButton || wantCourseInstructorButton) {
    window.FluentCommunityUtil.hooks.addFilter('fluent_com_portal_app', 'bm_fluent_com_buttons', bmFluentCommunityCourseButtonsMixin);
  }
});

const wantCourseChatButton       = !!(settings && settings.courseChatButton);
const wantCourseInstructorButton = !!(settings && settings.courseInstructorButton);

function bmFcResolveCourse(component) {
  if (!component) return null;
  if (component.course && typeof component.course === 'object') return component.course;
  if (component.$root && component.$root.course) return component.$root.course;
  let parent = component.$parent;
  let depth = 0;
  while (parent && depth < 8) {
    if (parent.course && typeof parent.course === 'object') return parent.course;
    parent = parent.$parent;
    depth += 1;
  }
  return null;
}

function bmFcOpenCourseChat(course) {
    if (!course || !course.bm_chat) return;
    const threadId = parseInt(course.bm_chat.thread_id, 10);
    if (!threadId) return;

    const realtime  = window.Better_Messages && window.Better_Messages.realtime === '1';
    const miniChats = window.Better_Messages && window.Better_Messages.miniChats === '1';

    if (realtime && miniChats && typeof BetterMessages !== 'undefined' && typeof BetterMessages.miniChatOpen === 'function') {
      BetterMessages.miniChatOpen(threadId);
      return;
    }

    const threadUrl = (window.Better_Messages && window.Better_Messages.threadUrl) || '/messages#/conversation/';
    location.href = threadUrl + threadId + '?&scrollToContainer';
}

function bmFluentCommunityCourseButtonsMixin(app) {
  app.mixin({
    mounted() {
      const el = this.$el;
      if (!el || el.nodeType !== 1) return;

      if (wantCourseChatButton) {
        const actions = el.matches && el.matches('.fcom_course_actions') ? el : (el.querySelector ? el.querySelector('.fcom_course_actions') : null);
        if (actions && !actions.dataset.bmCourseChatInjected) {
          const course = bmFcResolveCourse(this);
          if (course && course.bm_chat && course.bm_chat.chat_enabled && parseInt(course.bm_chat.thread_id, 10) > 0) {
            actions.dataset.bmCourseChatInjected = '1';
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'el-button fcom_secondary_button bm-fc-course-chat-btn';
            btn.innerHTML = '<span>' + settings.i18n.courseChat + '</span>';
            btn.style.marginRight = '8px';
            btn.addEventListener('click', function (e) {
              e.preventDefault();
              bmFcOpenCourseChat(course);
            });
            actions.insertBefore(btn, actions.firstChild);
          }
        }
      }

      if (wantCourseInstructorButton) {
        const creators = el.matches && el.matches('.creator_details_item') ? [el] : (el.querySelectorAll ? Array.from(el.querySelectorAll('.creator_details_item')) : []);
        for (const creatorEl of creators) {
          if (creatorEl.dataset.bmInstructorInjected) continue;
          const course = bmFcResolveCourse(this);
          if (!course) continue;
          const userId = parseInt(course.created_by || (course.creator && course.creator.user_id) || 0, 10);
          if (!userId) continue;
          const currentUserId = parseInt(window.Better_Messages && window.Better_Messages.user_id, 10);
          if (currentUserId && userId === currentUserId) continue;
          creatorEl.dataset.bmInstructorInjected = '1';
          const courseId = parseInt(course.id, 10) || 0;
          const link = document.createElement('a');
          link.href = '#';
          link.className = 'el-button fcom_primary_button bm-lc-button bm-no-loader bm-fc-instructor-btn bm-lc-user-' + userId;
          if (courseId) {
            link.setAttribute('data-bm-unique-key', 'fluentcommunity_course_chat_' + courseId);
          }
          link.innerHTML = '<span>' + settings.i18n.messageInstructor + '</span>';
          link.style.marginTop = '12px';
          link.style.width = '100%';
          creatorEl.appendChild(link);
        }
      }
    }
  });
  return app;
}

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

const callback = function(mutationsList, observer) {
  for(let mutation of mutationsList) {
    if (mutation.type === 'attributes' && mutation.attributeName === 'class') {
      updateDynamicCSS();
    }
  }
};

const observer = new MutationObserver(callback);

observer.observe(html, config);

if( window.visualViewport ){
  var lastViewportHeight = window.visualViewport.height;

  window.visualViewport.addEventListener('resize', function(){
    var currentHeight = window.visualViewport.height;
    var diff = currentHeight - lastViewportHeight;
    lastViewportHeight = currentHeight;

    if( diff > 100 && document.body.classList.contains('bm-reply-area-focused') ){
      document.body.classList.remove('bm-reply-area-focused');
    }

    updateDynamicCSS();
  });
}
