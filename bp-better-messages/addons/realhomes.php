<?php
defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Better_Messages_RealHomes' ) ) {

	class Better_Messages_RealHomes {

		const MESSAGES_MODULE_KEY = 'bm-messages';

		private static $REALTOR_TYPES = array( 'agent', 'agency' );

		private $visible_ids = array(
			'property' => array(),
			'agent'    => array(),
			'agency'   => array(),
		);

		public static function instance() {
			static $instance = null;
			if ( null === $instance ) {
				$instance = new Better_Messages_RealHomes();
			}
			return $instance;
		}

		public function __construct() {
			add_shortcode( 'better_messages_realhomes_property_button', array( $this, 'property_button_shortcode' ) );
			add_shortcode( 'better_messages_realhomes_property_card_button', array( $this, 'property_card_button_shortcode' ) );
			add_shortcode( 'better_messages_realhomes_agent_button', array( $this, 'agent_button_shortcode' ) );
			add_shortcode( 'better_messages_realhomes_agency_button', array( $this, 'agency_button_shortcode' ) );

			if ( empty( Better_Messages()->settings['realhomesIntegration'] ) ) {
				return;
			}

			add_action( 'wp_head', array( $this, 'print_styles' ), 100 );
			add_filter( 'better_messages_rest_user_item', array( $this, 'rewrite_agent_user_item' ), 15, 3 );
			add_filter( 'better_messages_rest_thread_item', array( $this, 'thread_item' ), 10, 5 );

			if ( ! empty( Better_Messages()->settings['realhomesPropertyButton'] ) ) {
				add_action( 'wp_footer', array( $this, 'render_property_button_auto' ), 20 );
			}

			if ( ! empty( Better_Messages()->settings['realhomesAgentButton'] ) ) {
				add_action( 'wp_footer', array( $this, 'render_agent_profile_button_auto' ), 20 );
				add_action( 'wp_footer', array( $this, 'render_agency_profile_button_auto' ), 20 );
			}

			if ( ! empty( Better_Messages()->settings['realhomesAgentCardButton'] ) ) {
				add_action( 'the_post', array( $this, 'capture_card_agent_post' ), 10, 2 );
				add_action( 'wp_footer', array( $this, 'render_agent_card_buttons_auto' ), 25 );
				add_action( 'wp_footer', array( $this, 'render_agency_card_buttons_auto' ), 25 );
			}

			if ( ! empty( Better_Messages()->settings['realhomesCardButton'] ) ) {
				add_action( 'the_post', array( $this, 'capture_card_property_post' ), 10, 2 );
				add_action( 'wp_footer', array( $this, 'render_card_buttons_auto' ), 25 );
			}

			if (
				! empty( Better_Messages()->settings['realhomesDashboardTab'] )
				|| Better_Messages()->settings['chatPage'] === 'realhomes-dashboard'
			) {
				add_filter( 'realhomes_dashboard_menu',     array( $this, 'register_messages_menu_item' ), 20 );
				add_filter( 'realhomes_dashboard_submenu',  array( $this, 'maybe_hide_native_inquiries' ), 20, 2 );
				add_action( 'realhomes_dashboard_before_content', array( $this, 'render_messages_module' ), 5 );
				add_filter( 'body_class',                   array( $this, 'add_messages_module_body_class' ) );
				add_action( 'wp_footer',                    array( $this, 'render_sidebar_tab_injection' ), 30 );
			}
		}

		public function get_live_chat_url() {
			$dashboard_id = (int) get_option( 'inspiry_dashboard_page' );
			if ( ! $dashboard_id ) return '';

			$base = get_permalink( $dashboard_id );
			if ( ! $base ) return '';

			return add_query_arg( 'module', self::MESSAGES_MODULE_KEY, $base );
		}

		public function render_messages_module() {
			global $current_module, $dashboard_globals;
			$submodule = is_array( $dashboard_globals ) && isset( $dashboard_globals['submodule'] ) ? $dashboard_globals['submodule'] : '';
			$active = ( $current_module === self::MESSAGES_MODULE_KEY ) || ( $submodule === self::MESSAGES_MODULE_KEY );
			if ( ! $active ) return;
			echo '<div class="bm-realhomes-dashboard-live-chat">' . do_shortcode( '[better_messages]' ) . '</div>';
			$current_module = '';
			if ( is_array( $dashboard_globals ) ) {
				$dashboard_globals['submodule'] = '';
				$GLOBALS['dashboard_globals'] = $dashboard_globals;
			}
			add_action( 'wp_footer', array( $this, 'render_messages_module_fit_script' ), 5 );
		}

		public function render_messages_module_fit_script() {
			?>
			<script>
			(function () {
				function fitDashboard() {
					var dashboard = document.getElementById('dashboard');
					if ( ! dashboard ) return;
					var rect = dashboard.getBoundingClientRect();
					var available = window.innerHeight - rect.top;
					if ( available > 100 ) {
						dashboard.style.height = available + 'px';
					}
				}
				if ( document.readyState === 'loading' ) {
					document.addEventListener( 'DOMContentLoaded', fitDashboard );
				} else {
					fitDashboard();
				}
				window.addEventListener( 'resize', fitDashboard );
				window.addEventListener( 'load', fitDashboard );
			})();
			</script>
			<?php
		}

		public function add_messages_module_body_class( $classes ) {
			if ( ! is_array( $classes ) ) return $classes;
			$module = isset( $_GET['module'] ) ? sanitize_text_field( wp_unslash( $_GET['module'] ) ) : '';
			if ( $module !== self::MESSAGES_MODULE_KEY ) return $classes;
			$classes[] = 'page-bm-realhomes-live-chat';
			return $classes;
		}

		public function register_messages_menu_item( $menu ) {
			if ( ! is_array( $menu ) ) return $menu;
			if ( isset( $menu['bm-messages'] ) ) return $menu;

			$label = _x( 'Messages', 'RealHomes Integration (Sidebar tab)', 'bp-better-messages' );
			$item  = array( $label, $label, 'fas fa-comments', true );

			if ( ! array_key_exists( 'profile', $menu ) ) {
				$menu['bm-messages'] = $item;
				return $menu;
			}

			$new_menu = array();
			foreach ( $menu as $key => $value ) {
				if ( $key === 'profile' ) {
					$new_menu['bm-messages'] = $item;
				}
				$new_menu[ $key ] = $value;
			}
			return $new_menu;
		}

		public function maybe_hide_native_inquiries( $submenu, $menu ) {
			if ( empty( Better_Messages()->settings['realhomesDisableNativeMessages'] ) ) return $submenu;
			if ( Better_Messages()->settings['chatPage'] !== 'realhomes-dashboard' ) return $submenu;
			unset( $submenu['properties-crm']['crm/inquiries'] );
			return $submenu;
		}

		public function render_sidebar_tab_injection() {
			if ( ! is_user_logged_in() ) return;

			$live_chat_url = Better_Messages()->functions->get_link();
			if ( empty( $live_chat_url ) ) return;

			$disable_native = ! empty( Better_Messages()->settings['realhomesDisableNativeMessages'] )
				&& Better_Messages()->settings['chatPage'] === 'realhomes-dashboard';
			?>
			<script>
				(function () {
					var liveChatUrl   = <?php echo wp_json_encode( $live_chat_url ); ?>;
					var disableNative = <?php echo wp_json_encode( $disable_native ); ?>;
					var ourLabel      = <?php echo wp_json_encode( _x( 'Messages', 'RealHomes Integration (Sidebar tab)', 'bp-better-messages' ) ); ?>;
					var trackedAnchors = [];

					function fixupSidebarTab() {
						var sidebarItem = document.querySelector('#dashboard-menu .menu-item-bm-messages');
						if ( ! sidebarItem ) return;
						var anchor = sidebarItem.querySelector('a[href]');
						if ( ! anchor ) return;

						anchor.setAttribute('data-bm-realhomes-tab', 'live-chat');

						var params = new URLSearchParams( window.location.search );
						if ( params.get('module') === 'bm-messages' ) {
							sidebarItem.classList.add('current');
						}

						trackedAnchors.push( anchor );
					}

					function injectUserDropdownLink() {
						var dropdowns = document.querySelectorAll('.rh_modal__dashboard');
						dropdowns.forEach(function ( dropdown ) {
							if ( dropdown.querySelector('[data-bm-realhomes-tab="live-chat"]') ) return;

							var profileLink = null;
							var nativeLink = null;
							dropdown.querySelectorAll('a[href]').forEach(function ( link ) {
								var href = link.getAttribute('href') || '';
								if ( ! profileLink && /[?&]module=profile(\b|$)/.test( href ) ) profileLink = link;
								if ( ! nativeLink && ( /[?&]module=crm\/inquiries(\b|$)/.test( href ) || /[?&]module=messages(\b|$)/.test( href ) ) ) nativeLink = link;
							});

							var anchor = document.createElement('a');
							anchor.href = liveChatUrl;
							anchor.className = 'rh_modal__dash_link';
							anchor.setAttribute('data-bm-realhomes-tab', 'live-chat');

							var icon = document.createElement('i');
							icon.className = 'fas fa-comments';
							icon.style.fontSize = '16px';
							icon.style.width = '16px';
							icon.style.display = 'inline-block';
							icon.style.textAlign = 'center';
							anchor.appendChild( icon );

							var label = document.createElement('span');
							label.textContent = ourLabel;
							anchor.appendChild( label );

							if ( profileLink && profileLink.parentNode === dropdown ) {
								dropdown.insertBefore( anchor, profileLink );
							} else if ( nativeLink && nativeLink.parentNode === dropdown ) {
								dropdown.insertBefore( anchor, nativeLink.nextSibling );
							} else {
								dropdown.appendChild( anchor );
							}

							if ( nativeLink && disableNative && nativeLink.parentNode === dropdown ) {
								nativeLink.style.display = 'none';
							}

							trackedAnchors.push( anchor );
						});
					}

					function setBadgeOnAnchor( anchor, count ) {
						var span = anchor.querySelector('.bm-realhomes-unread-badge');
						if ( count > 0 ) {
							if ( ! span ) {
								span = document.createElement('span');
								span.className = 'bm-realhomes-unread-badge';
								anchor.appendChild( span );
							}
							span.textContent = count > 99 ? '99+' : String( count );
						} else if ( span ) {
							span.remove();
						}
					}

					function bindUnreadBadges() {
						if ( ! window.wp || ! window.wp.hooks ) return;
						window.wp.hooks.addAction( 'better_messages_update_unread', 'bm-realhomes-tab', function ( data ) {
							var total = 0;
							if ( typeof data === 'number' ) total = data;
							else if ( data && typeof data === 'object' ) {
								if ( typeof data.unread === 'number' ) total = data.unread;
								else if ( typeof data.total === 'number' ) total = data.total;
								else if ( typeof data.count === 'number' ) total = data.count;
							}
							trackedAnchors.forEach(function ( a ) { setBadgeOnAnchor( a, total ); });
						} );
					}

					function injectAll() {
						fixupSidebarTab();
						injectUserDropdownLink();
						bindUnreadBadges();
					}

					if ( document.readyState === 'loading' ) {
						document.addEventListener('DOMContentLoaded', injectAll);
					} else {
						injectAll();
					}
				})();
			</script>
			<?php
		}

		public function thread_item( $thread_item, $thread_id, $thread_type, $include_personal, $user_id ) {
			if ( $thread_type !== 'thread' ) return $thread_item;

			list( $persona_post_id, $persona_type, $property_post_id ) = $this->resolve_thread_persona( $thread_id );
			if ( $persona_post_id <= 0 || $persona_type === '' ) return $thread_item;

			if ( $property_post_id > 0 ) {
				$thread_item['threadInfo'] = ( $thread_item['threadInfo'] ?? '' )
					. $this->property_thread_info_html( $property_post_id );
			}

			$display_post_id = $persona_post_id;
			$display_type    = $persona_type;

			if ( ! in_array( $display_type, self::$REALTOR_TYPES, true ) ) return $thread_item;

			$primary_agent_user_id = $this->get_realtor_linked_user( $display_post_id );
			$routed_user_id        = $this->resolve_thread_routed_user( $thread_id, $persona_post_id, $persona_type, $display_post_id, $property_post_id );
			$persona_user_id       = $primary_agent_user_id > 0 ? $primary_agent_user_id : $routed_user_id;
			$viewer_id             = (int) $user_id;
			$participants          = isset( $thread_item['participants'] ) ? array_map( 'intval', $thread_item['participants'] ) : array();

			if ( $persona_user_id > 0 && in_array( $persona_user_id, $participants, true ) ) {
				$thread_item = $this->add_persona_override( $thread_item, $persona_user_id, $display_post_id, $display_type );
			}

			if ( $viewer_id <= 0 || ! in_array( $viewer_id, $participants, true ) ) return $thread_item;
			if ( $primary_agent_user_id > 0 && $viewer_id === $primary_agent_user_id ) return $thread_item;

			if ( $this->viewer_is_on_agent_side( $viewer_id, $persona_user_id ) ) {
				$thread_item['threadInfo'] .= $this->persona_banner_html( $display_post_id, $display_type );
			}

			return $thread_item;
		}

		private function add_persona_override( $thread_item, $persona_user_id, $display_post_id, $display_type ) {
			$display = $this->realtor_display( $display_post_id, $display_type );
			if ( ! $display ) return $thread_item;

			if ( ! isset( $thread_item['participantOverrides'] ) || ! is_array( $thread_item['participantOverrides'] ) ) {
				$thread_item['participantOverrides'] = array();
			}
			$thread_item['participantOverrides'][ (string) $persona_user_id ] = $display;

			return $thread_item;
		}

		private function viewer_is_on_agent_side( $viewer_id, $persona_user_id ) {
			if ( $persona_user_id > 0 && $viewer_id === $persona_user_id ) return true;

			$role_post_id = (int) get_user_meta( $viewer_id, 'inspiry_role_post_id', true );
			if ( $role_post_id > 0 ) {
				$role_post = get_post( $role_post_id );
				if ( $role_post && in_array( $role_post->post_type, self::$REALTOR_TYPES, true ) ) {
					return true;
				}
			}

			return false;
		}

		private function realtor_display( $post_id, $expected_type ) {
			$post = get_post( $post_id );
			if ( ! $post || $post->post_type !== $expected_type || $post->post_status !== 'publish' ) return null;

			$out = array(
				'name' => $post->post_title,
				'url'  => get_permalink( $post_id ),
			);

			$thumb_id = (int) get_post_thumbnail_id( $post_id );
			if ( $thumb_id > 0 ) {
				$avatar = wp_get_attachment_image_url( $thumb_id, 'thumbnail' );
				if ( $avatar ) $out['avatar'] = $avatar;
			}

			return $out;
		}

		private function resolve_thread_routed_user( $thread_id, $persona_post_id, $persona_type, $display_post_id, $property_post_id ) {
			$snapshot = (int) Better_Messages()->functions->get_thread_meta( $thread_id, 'realhomes_persona_user_id' );
			if ( $snapshot > 0 ) return $snapshot;

			if ( $property_post_id > 0 ) {
				$user_id = $this->resolve_property_agent_user_id( $property_post_id, $display_post_id );
			} else {
				$user_id = $display_post_id > 0 ? $this->resolve_realtor_user_id( $display_post_id ) : 0;
			}

			if ( $user_id > 0 ) {
				Better_Messages()->functions->update_thread_meta( $thread_id, 'realhomes_persona_user_id', $user_id );
			}

			return (int) $user_id;
		}

		private function resolve_thread_persona( $thread_id ) {
			$cache_post_id     = (int) Better_Messages()->functions->get_thread_meta( $thread_id, 'realhomes_persona_post_id' );
			$cache_type        = (string) Better_Messages()->functions->get_thread_meta( $thread_id, 'realhomes_persona_type' );
			$cache_property_id = (int) Better_Messages()->functions->get_thread_meta( $thread_id, 'realhomes_property_post_id' );

			if ( $cache_post_id > 0 && $cache_type !== '' ) {
				return array( $cache_post_id, $cache_type, $cache_property_id );
			}

			$unique_tag = Better_Messages()->functions->get_thread_meta( $thread_id, 'unique_tag' );
			if ( empty( $unique_tag ) ) return array( 0, '', 0 );

			$first = explode( '|', $unique_tag )[0];

			$persona_post_id  = 0;
			$persona_type     = '';
			$property_post_id = 0;

			if ( preg_match( '/^realhomes_property_chat_(\d+)_agent_(\d+)$/', $first, $m ) ) {
				$property_post_id = (int) $m[1];
				$persona_post_id  = (int) $m[2];
				$persona_type     = 'agent';
			} elseif ( preg_match( '/^realhomes_property_chat_(\d+)_agency_(\d+)$/', $first, $m ) ) {
				$property_post_id = (int) $m[1];
				$persona_post_id  = (int) $m[2];
				$persona_type     = 'agency';
			} elseif ( preg_match( '/^realhomes_property_chat_(\d+)$/', $first, $m ) ) {
				$property_post_id = (int) $m[1];
				list( $persona_post_id, $persona_type ) = $this->get_property_primary_realtor( $property_post_id );
			} elseif ( preg_match( '/^realhomes_agent_chat_(\d+)$/', $first, $m ) ) {
				$persona_post_id = (int) $m[1];
				$persona_type    = 'agent';
			} elseif ( preg_match( '/^realhomes_agency_chat_(\d+)$/', $first, $m ) ) {
				$persona_post_id = (int) $m[1];
				$persona_type    = 'agency';
			}

			if ( $persona_post_id <= 0 || $persona_type === '' ) return array( 0, '', 0 );
			if ( ! $this->is_published_cpt( $persona_post_id, $persona_type ) ) return array( 0, '', 0 );

			Better_Messages()->functions->update_thread_meta( $thread_id, 'realhomes_persona_post_id', $persona_post_id );
			Better_Messages()->functions->update_thread_meta( $thread_id, 'realhomes_persona_type', $persona_type );
			if ( $property_post_id > 0 ) {
				Better_Messages()->functions->update_thread_meta( $thread_id, 'realhomes_property_post_id', $property_post_id );
			}

			return array( $persona_post_id, $persona_type, $property_post_id );
		}

		private function persona_banner_html( $post_id, $expected_type ) {
			$display = $this->realtor_display( $post_id, $expected_type );
			if ( ! $display ) return '';

			$avatar = isset( $display['avatar'] )
				? '<img src="' . esc_url( $display['avatar'] ) . '" alt="" />'
				: '';

			return '<div class="bm-realhomes-persona-banner">'
				. $avatar
				. '<span>' . sprintf(
					esc_html_x( 'Chatting as %s', 'RealHomes Integration (persona banner)', 'bp-better-messages' ),
					'<strong>' . esc_html( $display['name'] ) . '</strong>'
				) . '</span>'
				. '</div>';
		}

		private function get_property_primary_realtor( $property_id ) {
			$agents = (array) get_post_meta( $property_id, 'REAL_HOMES_agents', false );
			foreach ( $agents as $agent_post_id ) {
				$agent_post_id = (int) $agent_post_id;
				if ( $agent_post_id > 0 && $this->is_published_cpt( $agent_post_id, 'agent' ) ) {
					return array( $agent_post_id, 'agent' );
				}
			}

			return array( 0, '' );
		}

		private function is_published_cpt( $post_id, $expected_type ) {
			return get_post_type( $post_id ) === $expected_type && get_post_status( $post_id ) === 'publish';
		}

		private function property_thread_info_html( $property_id ) {
			$property_id = (int) $property_id;
			if ( ! $property_id ) return '';

			$post = get_post( $property_id );
			if ( ! $post || $post->post_type !== 'property' || $post->post_status !== 'publish' ) return '';

			$title = esc_html( get_the_title( $property_id ) );
			$url   = get_permalink( $property_id );

			$html = '<div class="bm-product-info bm-realhomes-property-info">';

			$status_terms = wp_get_post_terms( $property_id, 'property-status', array( 'fields' => 'names' ) );
			if ( ! is_wp_error( $status_terms ) && ! empty( $status_terms ) ) {
				$html .= '<div class="bm-realhomes-property-status">' . esc_html( $status_terms[0] ) . '</div>';
			}

			$image_id = get_post_thumbnail_id( $property_id );
			if ( $image_id ) {
				$image_src = wp_get_attachment_image_src( $image_id, array( 120, 90 ) );
				if ( $image_src ) {
					$html .= '<div class="bm-product-image">';
					$html .= '<a href="' . esc_url( $url ) . '" target="_blank"><img src="' . esc_url( $image_src[0] ) . '" alt="' . $title . '" /></a>';
					$html .= '</div>';
				}
			}

			$html .= '<div class="bm-product-details">';

			$html .= '<div class="bm-product-title"><a href="' . esc_url( $url ) . '" target="_blank">' . $title . '</a></div>';

			$price_html = $this->get_property_price_html( $property_id );
			if ( $price_html !== '' ) {
				$html .= '<div class="bm-product-price">' . $price_html . '</div>';
			}

			$subtitle = $this->get_property_subtitle( $property_id );
			if ( $subtitle !== '' ) {
				$html .= '<div class="bm-product-subtitle">' . esc_html( $subtitle ) . '</div>';
			}

			$address = get_post_meta( $property_id, 'REAL_HOMES_property_address', true );
			if ( ! empty( $address ) ) {
				$html .= '<div class="bm-realhomes-property-address">' . esc_html( $address ) . '</div>';
			}

			$html .= '</div>';
			$html .= '</div>';

			return $html;
		}

		private function get_property_price_html( $property_id ) {
			$price   = get_post_meta( $property_id, 'REAL_HOMES_property_price', true );
			$postfix = get_post_meta( $property_id, 'REAL_HOMES_property_price_postfix', true );
			if ( $price === '' || $price === null ) return '';
			$out = is_numeric( $price ) ? '$' . number_format_i18n( (float) $price ) : esc_html( (string) $price );
			if ( $postfix ) $out .= ' ' . esc_html( $postfix );
			return $out;
		}

		private function get_property_subtitle( $property_id ) {
			$parts = array();

			$beds = get_post_meta( $property_id, 'REAL_HOMES_property_bedrooms', true );
			if ( $beds !== '' && $beds !== null ) {
				$parts[] = sprintf( _nx( '%s bed', '%s beds', (int) $beds, 'RealHomes Integration (Thread context)', 'bp-better-messages' ), number_format_i18n( (int) $beds ) );
			}

			$baths = get_post_meta( $property_id, 'REAL_HOMES_property_bathrooms', true );
			if ( $baths !== '' && $baths !== null ) {
				$parts[] = sprintf( _nx( '%s bath', '%s baths', (int) $baths, 'RealHomes Integration (Thread context)', 'bp-better-messages' ), number_format_i18n( (int) $baths ) );
			}

			$size = get_post_meta( $property_id, 'REAL_HOMES_property_size', true );
			if ( $size !== '' && $size !== null ) {
				$postfix = get_post_meta( $property_id, 'REAL_HOMES_property_size_postfix', true );
				if ( empty( $postfix ) ) $postfix = 'Sq Ft';
				$parts[] = ( is_numeric( $size ) ? number_format_i18n( (float) $size ) : esc_html( (string) $size ) ) . ' ' . esc_html( $postfix );
			}

			return implode( ' · ', $parts );
		}

		public function rewrite_agent_user_item( $user_item, $user_id, $context ) {
			$user_id = (int) $user_id;
			if ( $user_id <= 0 || ! is_array( $user_item ) ) return $user_item;

			$role_post_id = (int) get_user_meta( $user_id, 'inspiry_role_post_id', true );
			if ( $role_post_id <= 0 ) return $user_item;

			$post = get_post( $role_post_id );
			if ( ! $post || $post->post_status !== 'publish' ) return $user_item;
			if ( ! in_array( $post->post_type, self::$REALTOR_TYPES, true ) ) return $user_item;

			$display = $this->realtor_display( $role_post_id, $post->post_type );
			if ( ! $display ) return $user_item;

			return array_merge( $user_item, $display );
		}

		private function can_render_message_button( $target_user_id ) {
			$target_user_id = (int) $target_user_id;
			if ( $target_user_id <= 0 ) return false;
			if ( $target_user_id === (int) Better_Messages()->functions->get_current_user_id() ) return false;
			return true;
		}

		private function get_realtor_linked_user( $post_id ) {
			$post_id = (int) $post_id;
			if ( ! $post_id ) return 0;
			$users = get_users( array(
				'meta_key'   => 'inspiry_role_post_id',
				'meta_value' => $post_id,
				'number'     => 1,
				'fields'     => 'ID',
			) );
			return ! empty( $users ) ? (int) $users[0] : 0;
		}

		private function resolve_realtor_user_id( $post_id ) {
			$user_id = $this->get_realtor_linked_user( $post_id );
			if ( $user_id > 0 ) return $user_id;
			return (int) get_post_field( 'post_author', $post_id );
		}

		private function resolve_property_agent_user_id( $property_id, $preferred_agent_post_id = 0 ) {
			$property_id = (int) $property_id;
			if ( ! $property_id ) return 0;

			$preferred_agent_post_id = (int) $preferred_agent_post_id;
			if ( $preferred_agent_post_id > 0 ) {
				$user_id = $this->resolve_realtor_user_id( $preferred_agent_post_id );
				if ( $user_id > 0 ) return $user_id;
			}

			$agents = get_post_meta( $property_id, 'REAL_HOMES_agents', false );
			if ( is_array( $agents ) ) {
				foreach ( $agents as $agent_post_id ) {
					$user_id = $this->get_realtor_linked_user( $agent_post_id );
					if ( $user_id > 0 ) return $user_id;
				}
			}

			return (int) get_post_field( 'post_author', $property_id );
		}

		private function render_live_chat_button( array $args ) {
			$defaults = array(
				'type'       => 'button',
				'class'      => 'bm-realhomes-btn bm-realhomes-btn-primary',
				'text'       => '',
				'user_id'    => 0,
				'unique_tag' => '',
				'subject'    => '',
				'alt'        => '',
			);
			$args = array_merge( $defaults, $args );

			$shortcode  = '[better_messages_live_chat_button';
			$shortcode .= ' type="' . esc_attr( $args['type'] ) . '"';
			$shortcode .= ' class="' . esc_attr( $args['class'] ) . '"';
			$shortcode .= ' text="' . Better_Messages()->shortcodes->esc_brackets( $args['text'] ) . '"';
			$shortcode .= ' user_id="' . (int) $args['user_id'] . '"';
			$shortcode .= ' unique_tag="' . esc_attr( $args['unique_tag'] ) . '"';
			if ( ! empty( $args['subject'] ) ) {
				$shortcode .= ' subject="' . Better_Messages()->shortcodes->esc_brackets( $args['subject'] ) . '"';
			}
			if ( ! empty( $args['alt'] ) ) {
				$shortcode .= ' alt="' . Better_Messages()->shortcodes->esc_brackets( $args['alt'] ) . '"';
			}
			$shortcode .= ']';

			return do_shortcode( $shortcode );
		}

		private function build_button_wrap( array $args ) {
			$wrap_class = $args['wrap_class'];
			$extra_attr = ! empty( $args['extra_attr'] ) ? $args['extra_attr'] : '';
			unset( $args['wrap_class'], $args['extra_attr'] );

			$html = $this->render_live_chat_button( $args );
			if ( empty( $html ) ) return '';

			return '<div class="' . esc_attr( $wrap_class ) . '"' . $extra_attr . '>' . $html . '</div>';
		}

		public function property_button_shortcode( $atts = array() ) {
			$atts = shortcode_atts( array(
				'property_id' => 0,
				'agent_id'    => 0,
				'class'       => '',
				'text'        => '',
			), $atts, 'better_messages_realhomes_property_button' );

			$property_id = (int) ( $atts['property_id'] ?: get_queried_object_id() ?: get_the_ID() );
			if ( ! $property_id || get_post_type( $property_id ) !== 'property' ) return '';
			if ( get_post_status( $property_id ) !== 'publish' ) return '';

			$agent_post_id = (int) $atts['agent_id'];
			$unique_tag    = 'realhomes_property_chat_' . $property_id;
			if ( $agent_post_id > 0 && $this->is_published_cpt( $agent_post_id, 'agent' ) ) {
				$unique_tag .= '_agent_' . $agent_post_id;
			}

			$user_id = $this->resolve_property_agent_user_id( $property_id, $agent_post_id );
			if ( ! $this->can_render_message_button( $user_id ) ) return '';

			$subject = sprintf(
				_x( 'Question about "%s"', 'RealHomes Integration (Property button)', 'bp-better-messages' ),
				get_the_title( $property_id )
			);
			$text = $atts['text'] ?: esc_attr_x( 'Live Chat', 'RealHomes Integration (Property button)', 'bp-better-messages' );

			return $this->build_button_wrap( array(
				'wrap_class' => 'bm-realhomes-property-button-wrap',
				'class'      => $atts['class'] ?: 'bm-realhomes-btn bm-realhomes-btn-primary bm-realhomes-property-btn',
				'text'       => $text,
				'user_id'    => $user_id,
				'unique_tag' => $unique_tag,
				'subject'    => esc_attr( $subject ),
			) );
		}

		public function agent_button_shortcode( $atts = array() ) {
			return $this->realtor_button_shortcode( 'agent', $atts );
		}

		public function agency_button_shortcode( $atts = array() ) {
			return $this->realtor_button_shortcode( 'agency', $atts );
		}

		private function realtor_button_shortcode( $type, $atts ) {
			$id_attr = $type . '_id';
			$atts = shortcode_atts( array(
				$id_attr => 0,
				'class'  => '',
				'text'   => '',
				'style'  => '',
			), $atts, 'better_messages_realhomes_' . $type . '_button' );

			$post_id = (int) ( $atts[ $id_attr ] ?: get_queried_object_id() ?: get_the_ID() );
			if ( ! $this->is_published_cpt( $post_id, $type ) ) return '';

			$user_id = $this->resolve_realtor_user_id( $post_id );
			if ( ! $this->can_render_message_button( $user_id ) ) return '';

			$subject = sprintf(
				_x( 'Send a message to %s', 'RealHomes Integration (Realtor button)', 'bp-better-messages' ),
				get_the_title( $post_id )
			);
			$unique_tag = 'realhomes_' . $type . '_chat_' . $post_id;

			if ( $atts['style'] === 'contact-item' ) {
				return $this->realtor_contact_item_html( array(
					'class'      => 'bm-realhomes-contact-link bm-realhomes-' . $type . '-btn',
					'text'       => $atts['text'] ?: esc_attr_x( 'Send a message', 'RealHomes Integration (Contact item)', 'bp-better-messages' ),
					'user_id'    => $user_id,
					'unique_tag' => $unique_tag,
					'subject'    => esc_attr( $subject ),
					'item_class' => 'bm-realhomes-' . $type . '-contact-item',
					'label'      => esc_html_x( 'Live Chat', 'RealHomes Integration (Contact item label)', 'bp-better-messages' ),
				) );
			}

			return $this->build_button_wrap( array(
				'wrap_class' => 'bm-realhomes-' . $type . '-button-wrap',
				'class'      => $atts['class'] ?: 'bm-realhomes-btn bm-realhomes-btn-primary bm-realhomes-' . $type . '-btn',
				'text'       => $atts['text'] ?: esc_attr_x( 'Live Chat', 'RealHomes Integration (Realtor button)', 'bp-better-messages' ),
				'user_id'    => $user_id,
				'unique_tag' => $unique_tag,
				'subject'    => esc_attr( $subject ),
			) );
		}

		private function realtor_contact_item_html( array $args ) {
			$button_html = $this->render_live_chat_button( array(
				'type'       => 'link',
				'class'      => $args['class'],
				'text'       => $args['text'],
				'user_id'    => $args['user_id'],
				'unique_tag' => $args['unique_tag'],
				'subject'    => $args['subject'],
			) );
			if ( empty( $button_html ) ) return '';

			$icon = '<svg class="rh-ultra-dark bm-realhomes-contact-icon" xmlns="http://www.w3.org/2000/svg" height="24" width="24" viewBox="0 0 24 24"><path d="M0 0h24v24H0V0z" fill="none"/><path d="M4 4h16v12H5.17L4 17.17V4z" opacity=".3"/><path d="M20 2H4c-1.1 0-1.99.9-1.99 2L2 22l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2zm0 14H5.17L4 17.17V4h16v12z"/></svg>';

			return '<div class="agent-contact-item bm-realhomes-contact-item ' . esc_attr( $args['item_class'] ) . '">'
				. $icon
				. '<div class="agent-contact-item-inner">'
				. '<h4 class="agent-contact-item-label">' . $args['label'] . '</h4>'
				. $button_html
				. '</div>'
				. '</div>';
		}

		public function render_property_button_auto() {
			if ( ! is_singular( 'property' ) ) return;

			$property_id = (int) get_queried_object_id();
			if ( ! $property_id ) return;

			$payloads = array();
			foreach ( $this->get_property_agent_ids( $property_id ) as $agent_post_id ) {
				$html = $this->property_button_shortcode( array(
					'property_id' => $property_id,
					'agent_id'    => $agent_post_id,
				) );
				if ( ! empty( $html ) ) $payloads[] = $html;
			}

			if ( empty( $payloads ) ) {
				$html = $this->property_button_shortcode( array( 'property_id' => $property_id ) );
				if ( ! empty( $html ) ) $payloads[] = $html;
			}

			if ( empty( $payloads ) ) return;

			echo '<script type="application/json" id="bm-realhomes-property-payloads">' . wp_json_encode( $payloads ) . '</script>';
			?>
			<script>
				document.addEventListener('DOMContentLoaded', function () {
					var dataEl = document.getElementById('bm-realhomes-property-payloads');
					if ( ! dataEl ) return;
					var payloads;
					try { payloads = JSON.parse( dataEl.textContent ); } catch ( e ) { return; }
					if ( ! Array.isArray( payloads ) || ! payloads.length ) return;

					var cards = document.querySelectorAll('.rh_property_agent');
					if ( cards.length === 0 ) {
						cards = document.querySelectorAll('.agent-for-sidebar, .property-agent-card');
					}
					if ( cards.length === 0 ) return;

					cards.forEach(function ( card, idx ) {
						if ( card.querySelector('.bm-realhomes-property-button-wrap') ) return;
						var payload = payloads[ idx ] || payloads[0];
						if ( ! payload ) return;

						var anchor = card.querySelector('.rh-property-agent-info-sidebar, .rh_property_agent__agent_info, .rh-agent-thumb-title-wrapper');
						var holder = document.createElement('div');
						holder.innerHTML = payload;
						var wrap = holder.firstElementChild;
						if ( ! wrap ) return;

						if ( anchor && anchor.parentNode ) {
							anchor.parentNode.insertBefore( wrap, anchor.nextSibling );
						} else {
							card.appendChild( wrap );
						}
					});
				});
			</script>
			<?php
		}

		private function get_property_agent_ids( $property_id ) {
			$property_id = (int) $property_id;
			if ( ! $property_id ) return array();

			$agents = get_post_meta( $property_id, 'REAL_HOMES_agents', false );
			if ( ! is_array( $agents ) ) return array();

			$ids = array();
			foreach ( $agents as $agent_post_id ) {
				$agent_post_id = (int) $agent_post_id;
				if ( $agent_post_id > 0 && $this->is_published_cpt( $agent_post_id, 'agent' ) ) {
					$ids[] = $agent_post_id;
				}
			}
			return array_values( array_unique( $ids ) );
		}

		public function render_agent_profile_button_auto() {
			$this->render_realtor_profile_button_auto( 'agent' );
		}

		public function render_agency_profile_button_auto() {
			$this->render_realtor_profile_button_auto( 'agency' );
		}

		private function render_realtor_profile_button_auto( $type ) {
			if ( ! is_singular( $type ) ) return;

			$post_id = (int) get_queried_object_id();
			if ( ! $post_id ) return;

			$shortcode_method = $type . '_button_shortcode';
			$atts_key = $type . '_id';

			$contact_html = $this->$shortcode_method( array( $atts_key => $post_id, 'style' => 'contact-item' ) );
			$fallback_html = $this->$shortcode_method( array( $atts_key => $post_id ) );
			if ( empty( $contact_html ) && empty( $fallback_html ) ) return;

			echo '<template data-bm-realhomes-template="' . esc_attr( $type ) . '-contact">' . $contact_html . '</template>';
			echo '<template data-bm-realhomes-template="' . esc_attr( $type ) . '-fallback">' . $fallback_html . '</template>';
			$type_class = $type === 'agency' ? 'bm-realhomes-agency-contact-item' : 'bm-realhomes-agent-contact-item';
			?>
			<script>
				document.addEventListener('DOMContentLoaded', function () {
					var contactsList = document.querySelector('<?php echo $type === 'agency' ? '.agency-contacts-list, .agent-contacts-list' : '.agent-contacts-list'; ?>');
					if ( contactsList && ! contactsList.querySelector('.<?php echo esc_js( $type_class ); ?>') ) {
						var tpl = document.querySelector('template[data-bm-realhomes-template="<?php echo esc_js( $type ); ?>-contact"]');
						if ( tpl && tpl.content.firstElementChild ) {
							contactsList.appendChild( tpl.content.firstElementChild.cloneNode(true) );
							return;
						}
					}

					var fallbackTpl = document.querySelector('template[data-bm-realhomes-template="<?php echo esc_js( $type ); ?>-fallback"]');
					if ( ! fallbackTpl ) return;
					var anchor = document.querySelector('.<?php echo esc_js( $type ); ?>-contact-form-wrapper, .rh-<?php echo esc_js( $type ); ?>-detail-page, .<?php echo esc_js( $type ); ?>-detail-content');
					if ( ! anchor ) return;
					if ( anchor.querySelector('.bm-realhomes-<?php echo esc_js( $type ); ?>-button-wrap') ) return;
					var clone = fallbackTpl.content.firstElementChild.cloneNode(true);
					anchor.parentNode.insertBefore( clone, anchor );
				});
			</script>
			<?php
		}

		public function property_card_button_shortcode( $atts = array() ) {
			$atts = shortcode_atts( array(
				'property_id' => 0,
				'class'       => '',
				'text'        => '',
			), $atts, 'better_messages_realhomes_property_card_button' );

			$property_id = (int) ( $atts['property_id'] ?: get_queried_object_id() ?: get_the_ID() );
			if ( ! $property_id || get_post_type( $property_id ) !== 'property' ) return '';
			if ( get_post_status( $property_id ) !== 'publish' ) return '';

			$user_id = $this->resolve_property_agent_user_id( $property_id );
			if ( ! $this->can_render_message_button( $user_id ) ) return '';

			$subject = sprintf(
				_x( 'Question about "%s"', 'RealHomes Integration (Property card button)', 'bp-better-messages' ),
				get_the_title( $property_id )
			);
			$text = $atts['text'] ?: esc_attr_x( 'Live Chat', 'RealHomes Integration (Property card button)', 'bp-better-messages' );

			return $this->build_button_wrap( array(
				'wrap_class' => 'bm-realhomes-card-button-wrap',
				'extra_attr' => ' data-bm-realhomes-card="' . esc_attr( $property_id ) . '"',
				'type'       => 'button',
				'class'      => $atts['class'] ?: 'bm-realhomes-card-btn rh-ui-tooltip',
				'text'       => $text,
				'user_id'    => $user_id,
				'unique_tag' => 'realhomes_property_chat_' . $property_id,
				'subject'    => esc_attr( $subject ),
				'alt'        => $text,
			) );
		}

		public function render_card_buttons_auto() {
			if ( is_admin() ) return;
			if ( is_singular( 'property' ) ) return;

			$post_ids = $this->collect_visible_post_ids_by_type( 'property' );
			if ( empty( $post_ids ) ) return;

			$by_id   = array();
			$ordered = array();
			foreach ( $post_ids as $pid ) {
				$html = $this->property_card_button_shortcode( array( 'property_id' => $pid ) );
				if ( ! empty( $html ) ) {
					$by_id[ $pid ] = $html;
					$ordered[]     = $pid;
				}
			}
			if ( empty( $by_id ) ) return;

			$payload = array( 'by_id' => $by_id, 'ordered' => $ordered );
			echo '<script id="bm-realhomes-card-buttons-data" type="application/json">';
			echo wp_json_encode( $payload );
			echo '</script>';
			?>
			<script>
				(function () {
					function inject() {
						var dataScript = document.getElementById('bm-realhomes-card-buttons-data');
						if ( ! dataScript ) return;
						var payload;
						try { payload = JSON.parse( dataScript.textContent ); } catch ( e ) { return; }
						if ( ! payload || ! payload.by_id ) return;
						var byId    = payload.by_id;
						var ordered = ( payload.ordered || [] ).slice();
						var usedIds = {};

						var cards = document.querySelectorAll(
							'.rh-ultra-property-card, .rh-ultra-list-card, .rh-property-card, ' +
							'.property-grid-item, article.property, [data-rh-id]'
						);

						cards.forEach(function ( card ) {
							if ( card.querySelector('.bm-realhomes-card-button-wrap') ) return;

							var pid = 0;
							var rhAttr = card.getAttribute('data-rh-id') || '';
							var rhMatch = rhAttr.match(/(\d+)/);
							if ( rhMatch ) pid = parseInt( rhMatch[1], 10 );
							if ( ! pid ) {
								var match = ( card.className || '' ).match(/post-(\d+)/);
								if ( match ) pid = parseInt( match[1], 10 );
							}
							if ( ! pid ) {
								var idMatch = ( card.id || '' ).match(/property-(\d+)/);
								if ( idMatch ) pid = parseInt( idMatch[1], 10 );
							}
							if ( ! pid ) {
								var link = card.querySelector('a[href*="/property/"]');
								if ( link ) {
									while ( ordered.length ) {
										var candidate = ordered.shift();
										if ( ! usedIds[ candidate ] ) { pid = candidate; break; }
									}
								}
							}
							if ( ! pid || ! byId[ pid ] ) return;
							usedIds[ pid ] = true;

							var btnRow = card.querySelector('.rh-ultra-action-buttons, .rh-property-card-actions, .property-card-actions');
							if ( ! btnRow ) return;

							var holder = document.createElement('div');
							holder.innerHTML = byId[ pid ];
							var wrap = holder.firstElementChild;
							if ( ! wrap ) return;

							btnRow.appendChild( wrap );
							btnRow.classList.add('bm-realhomes-with-live-chat');
						});
					}

					if ( document.readyState === 'loading' ) {
						document.addEventListener('DOMContentLoaded', inject );
					} else {
						inject();
					}
				})();
			</script>
			<?php
		}

		public function capture_card_property_post( $post, $query = null ) {
			if ( is_singular( 'property' ) ) return;
			if ( $post && ! empty( $post->post_type ) && $post->post_type === 'property' ) {
				$this->visible_ids['property'][ (int) $post->ID ] = true;
			}
		}

		public function capture_card_agent_post( $post, $query = null ) {
			if ( ! $post || empty( $post->post_type ) ) return;
			if ( in_array( $post->post_type, self::$REALTOR_TYPES, true ) && ! is_singular( $post->post_type ) ) {
				$this->visible_ids[ $post->post_type ][ (int) $post->ID ] = true;
			}
		}

		private function collect_visible_post_ids_by_type( $post_type ) {
			$ids = isset( $this->visible_ids[ $post_type ] ) ? array_keys( $this->visible_ids[ $post_type ] ) : array();
			global $wp_query;
			if ( $wp_query && ! empty( $wp_query->posts ) ) {
				foreach ( $wp_query->posts as $post ) {
					if ( ! empty( $post->post_type ) && $post->post_type === $post_type ) {
						$ids[] = (int) $post->ID;
					}
				}
			}
			return array_values( array_unique( array_filter( $ids ) ) );
		}

		public function render_agent_card_buttons_auto() {
			$this->render_realtor_card_buttons_auto( 'agent' );
		}

		public function render_agency_card_buttons_auto() {
			$this->render_realtor_card_buttons_auto( 'agency' );
		}

		private function render_realtor_card_buttons_auto( $type ) {
			if ( is_admin() ) return;
			if ( is_singular( $type ) ) return;

			$ids = $this->collect_visible_post_ids_by_type( $type );
			if ( empty( $ids ) ) return;

			$shortcode_method = $type . '_button_shortcode';
			$atts_key = $type . '_id';
			$by_id   = array();
			$ordered = array();
			foreach ( $ids as $post_id ) {
				$html = $this->$shortcode_method( array( $atts_key => $post_id, 'style' => 'contact-item' ) );
				if ( ! empty( $html ) ) {
					$by_id[ $post_id ] = $html;
					$ordered[]         = $post_id;
				}
			}
			if ( empty( $by_id ) ) return;

			$payload       = array( 'by_id' => $by_id, 'ordered' => $ordered );
			$data_id       = 'bm-realhomes-' . $type . '-card-buttons-data';
			$card_selector = $type === 'agency'
				? '.agency-card, article.agency, .rh-ultra-agency-card, .rh-agency-card'
				: '.agent-card, article.agent, .rh-ultra-agent-card, .rh-agent-card';
			$list_selector = $type === 'agency' ? '.agency-contacts-list, .agent-contacts-list' : '.agent-contacts-list';
			$item_class    = 'bm-realhomes-' . $type . '-contact-item';
			echo '<script id="' . esc_attr( $data_id ) . '" type="application/json">' . wp_json_encode( $payload ) . '</script>';
			?>
			<script>
				(function () {
					function inject() {
						var dataScript = document.getElementById(<?php echo wp_json_encode( $data_id ); ?>);
						if ( ! dataScript ) return;
						var payload;
						try { payload = JSON.parse( dataScript.textContent ); } catch ( e ) { return; }
						if ( ! payload || ! payload.by_id ) return;
						var byId    = payload.by_id;
						var ordered = ( payload.ordered || [] ).slice();
						var usedIds = {};

						var cards = document.querySelectorAll(<?php echo wp_json_encode( $card_selector ); ?>);
						cards.forEach(function ( card ) {
							var list = card.querySelector(<?php echo wp_json_encode( $list_selector ); ?>);
							if ( ! list ) return;
							if ( list.querySelector('.' + <?php echo wp_json_encode( $item_class ); ?>) ) return;

							var pid = 0;
							var match = ( card.className || '' ).match(/post-(\d+)/);
							if ( match ) pid = parseInt( match[1], 10 );
							if ( ! pid ) {
								var idRegex = new RegExp(<?php echo wp_json_encode( $type ); ?> + '-(\\d+)');
								var idMatch = ( card.id || '' ).match( idRegex );
								if ( idMatch ) pid = parseInt( idMatch[1], 10 );
							}
							if ( ! pid ) {
								while ( ordered.length ) {
									var c = ordered.shift();
									if ( ! usedIds[ c ] ) { pid = c; break; }
								}
							}
							if ( ! pid || ! byId[ pid ] ) return;
							usedIds[ pid ] = true;

							var holder = document.createElement('div');
							holder.innerHTML = byId[ pid ];
							var wrap = holder.firstElementChild;
							if ( ! wrap ) return;
							list.appendChild( wrap );
						});
					}

					if ( document.readyState === 'loading' ) {
						document.addEventListener('DOMContentLoaded', inject );
					} else {
						inject();
					}
				})();
			</script>
			<?php
		}

		public function print_styles() {
			?>
<style id="bm-realhomes-button-styles">
:root,
body.page-bm-realhomes-live-chat,
.bp-better-messages {
	--bm-realhomes-accent: var(--rh-global-color-primary, #1ea69a);
	--bm-realhomes-accent-hover: var(--rh-global-color-primary, #178b81);
}
.bm-realhomes-property-button-wrap,
.bm-realhomes-agent-button-wrap,
.bm-realhomes-agency-button-wrap {
	display: block;
	width: 100%;
	box-sizing: border-box;
	margin: 10px 0;
}
.bm-realhomes-property-button-wrap .bm-lc-button,
.bm-realhomes-agent-button-wrap .bm-lc-button,
.bm-realhomes-agency-button-wrap .bm-lc-button {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	width: 100%;
	box-sizing: border-box;
	gap: 8px;
	padding: 12px 18px;
	border-radius: 6px;
	background: var(--bm-realhomes-accent, #1ea69a);
	color: #fff;
	border: 1px solid var(--bm-realhomes-accent, #1ea69a);
	font-size: 14px;
	font-weight: 600;
	line-height: 1.2;
	text-align: center;
	text-decoration: none;
	cursor: pointer;
	transition: background-color .2s, border-color .2s, color .2s;
}
.bm-realhomes-property-button-wrap .bm-lc-button:hover,
.bm-realhomes-property-button-wrap .bm-lc-button:focus,
.bm-realhomes-agent-button-wrap .bm-lc-button:hover,
.bm-realhomes-agent-button-wrap .bm-lc-button:focus,
.bm-realhomes-agency-button-wrap .bm-lc-button:hover,
.bm-realhomes-agency-button-wrap .bm-lc-button:focus {
	background: var(--bm-realhomes-accent-hover, #178b81);
	border-color: var(--bm-realhomes-accent-hover, #178b81);
	color: #fff;
	filter: brightness(1.1);
}
.bm-realhomes-property-button-wrap .bm-lc-button:before,
.bm-realhomes-agent-button-wrap .bm-lc-button:before,
.bm-realhomes-agency-button-wrap .bm-lc-button:before {
	content: "\f075";
	font-family: 'Font Awesome 6 Free', 'Font Awesome 5 Free', 'FontAwesome';
	font-weight: 900;
	font-size: 15px;
	line-height: 1;
}
.bm-realhomes-contact-item .bm-realhomes-contact-icon {
	flex: 0 0 auto;
	fill: var(--bm-realhomes-accent, #1ea69a);
	width: 24px;
	height: 24px;
}
.bm-realhomes-contact-item .agent-contact-item-inner {
	display: flex;
	flex-direction: column;
	gap: 2px;
}
.bm-realhomes-contact-item .bm-realhomes-contact-link {
	text-decoration: none;
	cursor: pointer;
}
.bm-realhomes-contact-item .bm-realhomes-contact-link:hover .bm-button-text,
.bm-realhomes-contact-item .bm-realhomes-contact-link:focus .bm-button-text {
	color: var(--bm-realhomes-accent, #1ea69a);
}
.bm-realhomes-contact-item .bm-realhomes-contact-link:before {
	content: none;
}
.bm-realhomes-card-button-wrap {
	vertical-align: middle;
}
.bm-realhomes-card-button-wrap .bm-lc-button.bm-realhomes-card-btn {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	width: 32px;
	height: 32px;
	padding: 0;
	border-radius: 50%;
	background: transparent;
	color: var(--bm-realhomes-accent, #1ea69a);
	border: 0;
	font-size: 0;
	line-height: 1;
	text-decoration: none;
	cursor: pointer;
	transition: background-color .2s, color .2s;
}
.bm-realhomes-card-button-wrap .bm-lc-button.bm-realhomes-card-btn:hover,
.bm-realhomes-card-button-wrap .bm-lc-button.bm-realhomes-card-btn:focus {
	background: var(--bm-realhomes-accent, #1ea69a);
	color: #fff;
}
.rh-ultra-action-dark .bm-realhomes-card-button-wrap .bm-lc-button.bm-realhomes-card-btn {
	background: #1a1a1a;
	color: #fff;
}
.rh-ultra-action-dark.hover-dark .bm-realhomes-card-button-wrap .bm-lc-button.bm-realhomes-card-btn:hover,
.rh-ultra-action-dark.hover-dark .bm-realhomes-card-button-wrap .bm-lc-button.bm-realhomes-card-btn:focus {
	background: var(--bm-realhomes-accent, #1ea69a);
	color: #fff;
}
.bm-realhomes-card-button-wrap .bm-lc-button.bm-realhomes-card-btn:before {
	content: "\f075";
	font-family: 'Font Awesome 6 Free', 'Font Awesome 5 Free', 'FontAwesome';
	font-weight: 900;
	font-size: 14px;
	line-height: 1;
}
.bm-realhomes-card-button-wrap .bm-lc-button.bm-realhomes-card-btn .bm-button-text {
	display: none;
}
body.page-bm-realhomes-live-chat #dashboard {
	height: 100vh;
	min-height: 0;
	overflow: hidden;
}
body.page-bm-realhomes-live-chat #dashboard-content,
body.page-bm-realhomes-live-chat .dashboard-content {
	height: 100%;
	padding: 0 !important;
	background: #fff;
	overflow: hidden;
	display: flex;
	flex-direction: column;
}
body.page-bm-realhomes-live-chat #dashboard-content > *:not(.bm-realhomes-dashboard-live-chat),
body.page-bm-realhomes-live-chat .dashboard-content > *:not(.bm-realhomes-dashboard-live-chat) {
	flex: 0 0 auto;
}
body.page-bm-realhomes-live-chat .dashboard-page-head {
	display: none;
}
.bm-realhomes-dashboard-live-chat {
	flex: 1 1 auto;
	min-height: 0;
	background: #fff;
}
.bm-realhomes-dashboard-live-chat .bp-better-messages,
.bm-realhomes-dashboard-live-chat .bp-messages-wrap,
.bm-realhomes-dashboard-live-chat .bp-messages-wrap-main {
	height: 100% !important;
}
.bm-realhomes-dashboard-live-chat .bp-messages-wrap,
.bm-realhomes-dashboard-live-chat .bp-messages-wrap-main {
	border: none !important;
	border-radius: 0 !important;
	box-shadow: none !important;
}
.bm-realhomes-dashboard-live-chat .bp-messages-wrap-main .bp-messages-wrap:not(.bp-messages-full-screen, .bp-messages-mobile),
.bm-realhomes-dashboard-live-chat .bp-messages-wrap-main .bp-messages-threads-wrapper {
	height: 100% !important;
}
#dashboard-menu .menu-item-bm-messages a,
#dashboard-sidebar [data-bm-realhomes-tab="live-chat"] a,
.dashboard-sidebar [data-bm-realhomes-tab="live-chat"] a,
.rh_modal__dashboard a[data-bm-realhomes-tab="live-chat"] {
	position: relative;
}
#dashboard-menu .menu-item-bm-messages a .bm-realhomes-unread-badge,
#dashboard-sidebar [data-bm-realhomes-tab="live-chat"] .bm-realhomes-unread-badge,
.dashboard-sidebar [data-bm-realhomes-tab="live-chat"] .bm-realhomes-unread-badge,
.rh_modal__dashboard a[data-bm-realhomes-tab="live-chat"] .bm-realhomes-unread-badge {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	min-width: 18px;
	height: 18px;
	padding: 0 6px;
	margin-left: auto;
	border-radius: 9px;
	background: var(--bm-realhomes-accent, #1ea69a);
	color: #fff;
	font-size: 11px;
	font-weight: 700;
	line-height: 1;
}
.rh_modal__dashboard a[data-bm-realhomes-tab="live-chat"] {
	display: flex;
	align-items: center;
	gap: 10px;
}
.rh_modal__dashboard a[data-bm-realhomes-tab="live-chat"] .bm-realhomes-unread-badge {
	margin-left: auto;
}
.rh-ultra-action-buttons.bm-realhomes-with-live-chat,
.rh-property-card-actions.bm-realhomes-with-live-chat {
	flex-wrap: wrap;
}
.bm-realhomes-property-info {
	position: relative;
	display: flex;
	align-items: flex-start;
	padding: 10px 12px;
	background: #f7f9fb;
	border-bottom: 1px solid #e6ebf0;
}
.bm-realhomes-property-info .bm-product-details {
	flex: 1 1 auto;
	min-width: 0;
	display: flex;
	flex-direction: column;
	gap: 3px;
}
.bm-realhomes-property-info:has(.bm-realhomes-property-status) .bm-product-details {
	padding-right: 80px;
}
.bm-realhomes-property-info .bm-realhomes-property-status {
	position: absolute;
	top: 12px;
	right: 12px;
	font-size: 11px;
	font-weight: 600;
	text-transform: uppercase;
	letter-spacing: .5px;
	padding: 3px 8px;
	border-radius: 3px;
	background: var(--bm-realhomes-accent, #1ea69a);
	color: #fff;
	z-index: 1;
}
.bm-realhomes-property-info .bm-product-title a {
	color: inherit;
	font-weight: 600;
	font-size: 14px;
	text-decoration: none;
}
.bm-realhomes-property-info .bm-product-title a:hover {
	color: var(--bm-realhomes-accent, #1ea69a);
}
.bm-realhomes-property-info .bm-product-price {
	font-size: 14px;
	font-weight: 600;
	color: var(--bm-realhomes-accent, #1ea69a);
}
.bm-realhomes-property-info .bm-product-subtitle,
.bm-realhomes-property-info .bm-realhomes-property-address {
	font-size: 12px;
	color: #6b7280;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}
.bm-realhomes-persona-banner {
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 8px 12px;
	background: #fff8e1;
	border-bottom: 1px solid #ffe0a3;
	font-size: 12px;
	color: #6b4f00;
}
.bm-realhomes-persona-banner img {
	width: 22px;
	height: 22px;
	border-radius: 50%;
	object-fit: cover;
}
.bm-realhomes-persona-banner strong {
	color: #1f2937;
	font-weight: 600;
}
</style>
			<?php
		}
	}

}
