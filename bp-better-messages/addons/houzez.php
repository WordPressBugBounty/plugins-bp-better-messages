<?php
defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Better_Messages_Houzez' ) ) {

	class Better_Messages_Houzez {

		const LIVE_CHAT_PAGE_SLUG = 'dashboard-live-chat';
		const LIVE_CHAT_PAGE_OPTION = 'better_messages_houzez_live_chat_page_id';
		const LIVE_CHAT_TEMPLATE_KEY = 'better-messages/houzez-dashboard-live-chat.php';

		private static $REALTOR_TYPES = array( 'houzez_agent', 'houzez_agency' );

		private $visible_property_ids = array();

		public static function instance() {
			static $instance = null;
			if ( null === $instance ) {
				$instance = new Better_Messages_Houzez();
			}
			return $instance;
		}

		public function __construct() {
			add_shortcode( 'better_messages_houzez_property_button', array( $this, 'property_button_shortcode' ) );
			add_shortcode( 'better_messages_houzez_property_card_button', array( $this, 'property_card_button_shortcode' ) );
			add_shortcode( 'better_messages_houzez_agent_button', array( $this, 'agent_button_shortcode' ) );
			add_shortcode( 'better_messages_houzez_agency_button', array( $this, 'agency_button_shortcode' ) );

			if ( empty( Better_Messages()->settings['houzezIntegration'] ) ) {
				return;
			}

			add_action( 'wp_head', array( $this, 'print_styles' ), 100 );
			add_filter( 'better_messages_rest_user_item', array( $this, 'rewrite_agent_user_item' ), 15, 3 );
			add_filter( 'better_messages_rest_thread_item', array( $this, 'thread_item' ), 10, 5 );

			if ( ! empty( Better_Messages()->settings['houzezPropertyButton'] ) ) {
				add_action( 'wp_footer', array( $this, 'render_property_button_auto' ), 20 );
			}

			if ( ! empty( Better_Messages()->settings['houzezAgentButton'] ) ) {
				add_action( 'wp_footer', array( $this, 'render_agent_profile_button_auto' ), 20 );
				add_action( 'wp_footer', array( $this, 'render_agency_profile_button_auto' ), 20 );
			}

			if ( ! empty( Better_Messages()->settings['houzezCardButton'] ) ) {
				add_action( 'the_post', array( $this, 'capture_card_property_post' ), 10, 2 );
				add_action( 'wp_footer', array( $this, 'render_card_buttons_auto' ), 25 );
			}

			if ( Better_Messages()->settings['chatPage'] === 'houzez-dashboard' ) {
				add_action( 'init', array( $this, 'ensure_live_chat_page' ), 20 );
				add_filter( 'houzez_is_dashboard_filter', array( $this, 'register_dashboard_template' ) );
				add_filter( 'template_include', array( $this, 'template_include_live_chat' ), 99 );
				add_filter( 'body_class', array( $this, 'add_live_chat_body_class' ) );
			}

			if (
				! empty( Better_Messages()->settings['houzezDashboardTab'] )
				|| Better_Messages()->settings['chatPage'] === 'houzez-dashboard'
			) {
				add_action( 'wp_footer', array( $this, 'render_sidebar_tab_injection' ), 30 );
			}
		}

		public function register_dashboard_template( $files ) {
			$files[] = self::LIVE_CHAT_TEMPLATE_KEY;
			return $files;
		}

		public function get_live_chat_page_id() {
			$page_id = (int) get_option( self::LIVE_CHAT_PAGE_OPTION, 0 );
			if ( $page_id && get_post_status( $page_id ) === 'publish' ) {
				return $page_id;
			}
			return 0;
		}

		public function ensure_live_chat_page() {
			$existing_id = $this->get_live_chat_page_id();
			if ( $existing_id ) {
				$current_template = get_post_meta( $existing_id, '_wp_page_template', true );
				if ( $current_template !== self::LIVE_CHAT_TEMPLATE_KEY ) {
					update_post_meta( $existing_id, '_wp_page_template', self::LIVE_CHAT_TEMPLATE_KEY );
				}
				return;
			}

			$existing = get_page_by_path( self::LIVE_CHAT_PAGE_SLUG );
			if ( $existing && $existing->post_status === 'publish' ) {
				update_post_meta( $existing->ID, '_wp_page_template', self::LIVE_CHAT_TEMPLATE_KEY );
				update_option( self::LIVE_CHAT_PAGE_OPTION, (int) $existing->ID );
				return;
			}

			$page_id = wp_insert_post( array(
				'post_title'   => _x( 'Live Chat', 'Houzez Integration (Dashboard page)', 'bp-better-messages' ),
				'post_name'    => self::LIVE_CHAT_PAGE_SLUG,
				'post_status'  => 'publish',
				'post_type'    => 'page',
				'post_content' => '',
				'post_author'  => 1,
			), true );

			if ( is_wp_error( $page_id ) || ! $page_id ) {
				return;
			}

			update_post_meta( $page_id, '_wp_page_template', self::LIVE_CHAT_TEMPLATE_KEY );
			update_option( self::LIVE_CHAT_PAGE_OPTION, (int) $page_id );
			flush_rewrite_rules( false );
		}

		public function add_live_chat_body_class( $classes ) {
			if ( is_page() ) {
				$page_id = $this->get_live_chat_page_id();
				if ( $page_id && get_queried_object_id() === $page_id ) {
					$classes[] = 'page-bm-houzez-live-chat';
				}
			}
			return $classes;
		}

		public function template_include_live_chat( $template ) {
			if ( ! is_page() ) return $template;
			$page_id = $this->get_live_chat_page_id();
			if ( ! $page_id || get_queried_object_id() !== $page_id ) return $template;

			$custom = __DIR__ . '/houzez/dashboard-live-chat.php';
			if ( file_exists( $custom ) ) {
				return $custom;
			}
			return $template;
		}

		public function render_sidebar_tab_injection() {
			if ( ! is_user_logged_in() ) return;

			$live_chat_url = Better_Messages()->functions->get_link();
			if ( empty( $live_chat_url ) ) return;

			$disable_native = ! empty( Better_Messages()->settings['houzezDisableNativeMessages'] )
				&& Better_Messages()->settings['chatPage'] === 'houzez-dashboard';
			?>
			<script>
				(function () {
					var liveChatUrl   = <?php echo wp_json_encode( $live_chat_url ); ?>;
					var disableNative = <?php echo wp_json_encode( $disable_native ); ?>;
					var ourLabel      = <?php echo wp_json_encode( _x( 'Messages', 'Houzez Integration (Sidebar tab)', 'bp-better-messages' ) ); ?>;

					function injectTab() {
						var sidebar = document.querySelector('.dashboard-sidebar');
						if ( ! sidebar ) return;
						if ( sidebar.querySelector('[data-bm-houzez-tab="live-chat"]') ) return;

						var allLinks = sidebar.querySelectorAll('a[href]');
						var nativeLink = null;
						for ( var i = 0; i < allLinks.length; i++ ) {
							var href = allLinks[i].getAttribute('href') || '';
							if ( /\/dashboard-messages\/?(\?|#|$)/.test( href ) ) {
								nativeLink = allLinks[i];
								break;
							}
						}

						var currentlyOnLiveChat = ( window.location.pathname + '/' ).indexOf( liveChatUrl.replace( /^https?:\/\/[^\/]+/, '' ) ) === 0
							&& liveChatUrl.indexOf( window.location.pathname ) !== -1;

						var li = document.createElement('li');
						li.setAttribute('data-bm-houzez-tab', 'live-chat');
						var anchor = document.createElement('a');
						anchor.href = liveChatUrl;
						if ( currentlyOnLiveChat ) anchor.className = 'active';
						var icon = document.createElement('i');
						icon.className = 'houzez-icon icon-messages-bubble';
						var label = document.createElement('span');
						label.textContent = ourLabel;
						anchor.appendChild(icon);
						anchor.appendChild(label);
						li.appendChild(anchor);

						if ( nativeLink ) {
							var nativeLi = nativeLink.closest('li');
							if ( nativeLi && nativeLi.parentNode ) {
								nativeLi.parentNode.insertBefore( li, nativeLi.nextSibling );
								if ( disableNative ) {
									nativeLi.style.display = 'none';
								} else if ( currentlyOnLiveChat ) {
									nativeLink.classList.remove('active');
								}
							}
						} else {
							var lastUl = sidebar.querySelector('ul:last-of-type') || sidebar;
							lastUl.appendChild( li );
						}

						bindUnreadBadge( anchor );
					}

					function bindUnreadBadge( anchor ) {
						if ( ! anchor || ! window.wp || ! window.wp.hooks ) return;
						function setBadge( count ) {
							var span = anchor.querySelector('.bm-houzez-unread-badge');
							if ( count > 0 ) {
								if ( ! span ) {
									span = document.createElement('span');
									span.className = 'bm-houzez-unread-badge';
									anchor.appendChild( span );
								}
								span.textContent = count > 99 ? '99+' : String( count );
							} else if ( span ) {
								span.remove();
							}
						}
						window.wp.hooks.addAction( 'better_messages_update_unread', 'bm-houzez-tab', function ( data ) {
							var total = 0;
							if ( typeof data === 'number' ) total = data;
							else if ( data && typeof data === 'object' ) {
								if ( typeof data.unread === 'number' ) total = data.unread;
								else if ( typeof data.total === 'number' ) total = data.total;
								else if ( typeof data.count === 'number' ) total = data.count;
							}
							setBadge( total );
						} );
					}

					if ( document.readyState === 'loading' ) {
						document.addEventListener('DOMContentLoaded', injectTab);
					} else {
						injectTab();
					}
				})();
			</script>
			<?php
		}

		public function thread_item( $thread_item, $thread_id, $thread_type, $include_personal, $user_id ) {
			if ( $thread_type !== 'thread' ) return $thread_item;

			list( $persona_post_id, $persona_type ) = $this->resolve_thread_persona( $thread_id );
			if ( $persona_post_id <= 0 || $persona_type === '' ) return $thread_item;

			$thread_item['threadInfo'] = ( $thread_item['threadInfo'] ?? '' )
				. ( $persona_type === 'property'
					? $this->property_thread_info_html( $persona_post_id )
					: $this->realtor_thread_info_html( $persona_post_id, $persona_type ) );

			if ( $persona_type === 'property' ) {
				list( $display_post_id, $display_type ) = $this->get_property_primary_realtor( $persona_post_id );
			} else {
				$display_post_id = $persona_post_id;
				$display_type    = $persona_type;
			}

			if ( $display_post_id <= 0 || ! in_array( $display_type, self::$REALTOR_TYPES, true ) ) {
				return $thread_item;
			}

			$primary_agent_user_id = (int) get_post_meta( $display_post_id, 'houzez_user_meta_id', true );
			$routed_user_id        = $this->resolve_thread_routed_user( $thread_id, $persona_post_id, $persona_type, $display_post_id );
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

			$viewer_data = get_userdata( $viewer_id );
			if ( ! $viewer_data || ! is_array( $viewer_data->roles ) ) return false;

			return (bool) array_intersect( $viewer_data->roles, self::$REALTOR_TYPES );
		}

		/**
		 * Validate that $post_id is a published CPT of $expected_type and build
		 * the shared { name, url, avatar? } payload used by participantOverrides,
		 * the rewrite_agent_user_item filter, and the "Chatting as X" banner.
		 *
		 * Returns null when the CPT is missing, trashed, or the wrong type.
		 */
		private function realtor_display( $post_id, $expected_type ) {
			$post = get_post( $post_id );
			if ( ! $post || $post->post_type !== $expected_type || $post->post_status !== 'publish' ) return null;

			$out = array(
				'name' => $post->post_title,
				'url'  => get_permalink( $post_id ),
			);
			$avatar = $this->resolve_realtor_avatar_url( $post_id );
			if ( $avatar !== '' ) $out['avatar'] = $avatar;

			return $out;
		}

		private function resolve_thread_routed_user( $thread_id, $persona_post_id, $persona_type, $display_post_id ) {
			$snapshot = (int) Better_Messages()->functions->get_thread_meta( $thread_id, 'houzez_persona_user_id' );
			if ( $snapshot > 0 ) return $snapshot;

			$user_id = ( $persona_type === 'property' )
				? $this->resolve_property_agent_user_id( $persona_post_id )
				: ( $display_post_id > 0 ? $this->resolve_realtor_user_id( $display_post_id ) : 0 );

			if ( $user_id > 0 ) {
				Better_Messages()->functions->update_thread_meta( $thread_id, 'houzez_persona_user_id', $user_id );
			}

			return (int) $user_id;
		}

		private function resolve_thread_persona( $thread_id ) {
			$post_id = (int) Better_Messages()->functions->get_thread_meta( $thread_id, 'houzez_persona_post_id' );
			$type    = (string) Better_Messages()->functions->get_thread_meta( $thread_id, 'houzez_persona_type' );

			if ( $post_id > 0 && $type !== '' ) return array( $post_id, $type );

			$unique_tag = Better_Messages()->functions->get_thread_meta( $thread_id, 'unique_tag' );
			if ( empty( $unique_tag ) ) return array( 0, '' );

			$first    = explode( '|', $unique_tag )[0];
			$prefixes = array(
				'houzez_property_chat_' => 'property',
				'houzez_agent_chat_'    => 'houzez_agent',
				'houzez_agency_chat_'   => 'houzez_agency',
			);

			foreach ( $prefixes as $prefix => $resolved_type ) {
				if ( strpos( $first, $prefix ) !== 0 ) continue;
				$resolved_post_id = (int) substr( $first, strlen( $prefix ) );
				if ( $resolved_post_id <= 0 ) return array( 0, '' );

				Better_Messages()->functions->update_thread_meta( $thread_id, 'houzez_persona_post_id', $resolved_post_id );
				Better_Messages()->functions->update_thread_meta( $thread_id, 'houzez_persona_type', $resolved_type );

				return array( $resolved_post_id, $resolved_type );
			}

			return array( 0, '' );
		}

		private function persona_banner_html( $post_id, $expected_type ) {
			$display = $this->realtor_display( $post_id, $expected_type );
			if ( ! $display ) return '';

			$avatar = isset( $display['avatar'] )
				? '<img src="' . esc_url( $display['avatar'] ) . '" alt="" />'
				: '';

			return '<div class="bm-houzez-persona-banner">'
				. $avatar
				. '<span>' . sprintf(
					esc_html_x( 'Chatting as %s', 'Houzez Integration (persona banner)', 'bp-better-messages' ),
					'<strong>' . esc_html( $display['name'] ) . '</strong>'
				) . '</span>'
				. '</div>';
		}

		/**
		 * Resolve a property's "primary" realtor — the first published houzez_agent
		 * referenced in `fave_agents`, falling back to the property's
		 * `fave_property_agency`. Returns array( int $post_id, string $type ) or
		 * array( 0, '' ) when nothing valid is found.
		 */
		private function get_property_primary_realtor( $property_id ) {
			$agents = (array) get_post_meta( $property_id, 'fave_agents', false );
			foreach ( $agents as $agent_post_id ) {
				$agent_post_id = (int) $agent_post_id;
				if ( $agent_post_id > 0 && $this->is_published_cpt( $agent_post_id, 'houzez_agent' ) ) {
					return array( $agent_post_id, 'houzez_agent' );
				}
			}

			$agency_id = (int) get_post_meta( $property_id, 'fave_property_agency', true );
			if ( $agency_id > 0 && $this->is_published_cpt( $agency_id, 'houzez_agency' ) ) {
				return array( $agency_id, 'houzez_agency' );
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

			$html = '<div class="bm-product-info bm-houzez-property-info">';

			$status_label = $this->get_property_status_label( $property_id );
			if ( $status_label !== '' ) {
				$html .= '<div class="bm-houzez-property-status">' . esc_html( $status_label ) . '</div>';
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

			$address = get_post_meta( $property_id, 'fave_property_address', true );
			if ( ! empty( $address ) ) {
				$html .= '<div class="bm-houzez-property-address">' . esc_html( $address ) . '</div>';
			}

			$html .= '</div>';
			$html .= '</div>';

			return $html;
		}

		private function realtor_thread_info_html( $post_id, $expected_type ) {
			$display = $this->realtor_display( (int) $post_id, $expected_type );
			if ( ! $display ) return '';

			$title    = esc_html( $display['name'] );
			$url      = $display['url'];
			$subtitle = $this->realtor_subtitle( (int) $post_id, $expected_type );

			$html = '<div class="bm-product-info bm-houzez-realtor-info">';

			if ( isset( $display['avatar'] ) ) {
				$html .= '<div class="bm-product-image bm-houzez-realtor-image">'
					. '<a href="' . esc_url( $url ) . '" target="_blank"><img src="' . esc_url( $display['avatar'] ) . '" alt="' . $title . '" /></a>'
					. '</div>';
			}

			$html .= '<div class="bm-product-details">'
				. '<div class="bm-product-title"><a href="' . esc_url( $url ) . '" target="_blank">' . $title . '</a></div>';

			if ( $subtitle !== '' ) {
				$html .= '<div class="bm-product-subtitle">' . esc_html( $subtitle ) . '</div>';
			}

			$html .= '</div></div>';

			return $html;
		}

		private function realtor_subtitle( $post_id, $expected_type ) {
			if ( $expected_type === 'houzez_agency' ) {
				return (string) get_post_meta( $post_id, 'fave_agency_location', true );
			}

			$parts = array();
			$position = (string) get_post_meta( $post_id, 'fave_agent_position', true );
			if ( $position !== '' ) $parts[] = $position;

			$agency_id = (int) get_post_meta( $post_id, 'fave_agent_agencies', true );
			if ( $agency_id > 0 ) {
				$agency = get_post( $agency_id );
				if ( $agency && $agency->post_status === 'publish' ) {
					$parts[] = $agency->post_title;
				}
			}

			return implode( ' · ', $parts );
		}

		private function get_property_status_label( $property_id ) {
			$terms = wp_get_post_terms( $property_id, 'property_status', array( 'fields' => 'names' ) );
			if ( is_wp_error( $terms ) || empty( $terms ) ) return '';
			return (string) $terms[0];
		}

		private function get_property_price_html( $property_id ) {
			if ( function_exists( 'houzez_listing_price_by_id' ) ) {
				$html = houzez_listing_price_by_id( $property_id );
				if ( ! empty( $html ) ) return $html;
			}
			$price   = get_post_meta( $property_id, 'fave_property_price', true );
			$postfix = get_post_meta( $property_id, 'fave_property_price_postfix', true );
			if ( $price === '' || $price === null ) return '';
			$out = is_numeric( $price ) ? '$' . number_format_i18n( (float) $price ) : esc_html( (string) $price );
			if ( $postfix ) $out .= ' / ' . esc_html( $postfix );
			return $out;
		}

		private function get_property_subtitle( $property_id ) {
			$parts = array();

			$beds = get_post_meta( $property_id, 'fave_property_bedrooms', true );
			if ( $beds === '' || $beds === null ) {
				$beds = get_post_meta( $property_id, 'fave_property_rooms', true );
			}
			if ( $beds !== '' && $beds !== null ) {
				$parts[] = sprintf( _nx( '%s bed', '%s beds', (int) $beds, 'Houzez Integration (Thread context)', 'bp-better-messages' ), number_format_i18n( (int) $beds ) );
			}

			$baths = get_post_meta( $property_id, 'fave_property_bathrooms', true );
			if ( $baths !== '' && $baths !== null ) {
				$parts[] = sprintf( _nx( '%s bath', '%s baths', (int) $baths, 'Houzez Integration (Thread context)', 'bp-better-messages' ), number_format_i18n( (int) $baths ) );
			}

			$size = get_post_meta( $property_id, 'fave_property_size', true );
			if ( $size !== '' && $size !== null ) {
				$prefix = get_post_meta( $property_id, 'fave_property_size_prefix', true );
				if ( empty( $prefix ) ) {
					$prefix = function_exists( 'houzez_option' ) ? houzez_option( 'measurement_unit_sqft_text', 'sqft' ) : 'sqft';
				}
				$parts[] = number_format_i18n( (float) $size ) . ' ' . $prefix;
			}

			return implode( ' · ', $parts );
		}

		public function rewrite_agent_user_item( $user_item, $user_id, $context ) {
			$user_id = (int) $user_id;
			if ( $user_id <= 0 || ! is_array( $user_item ) ) return $user_item;

			$agent_id = (int) get_user_meta( $user_id, 'fave_author_agent_id', true );
			if ( $agent_id > 0 ) {
				return $this->apply_realtor_to_user_item( $user_item, $agent_id, 'houzez_agent' );
			}

			$agency_id = (int) get_user_meta( $user_id, 'fave_author_agency_id', true );
			if ( $agency_id > 0 ) {
				return $this->apply_realtor_to_user_item( $user_item, $agency_id, 'houzez_agency' );
			}

			return $user_item;
		}

		private function apply_realtor_to_user_item( $user_item, $post_id, $expected_type ) {
			$display = $this->realtor_display( $post_id, $expected_type );
			if ( ! $display ) return $user_item;
			return array_merge( $user_item, $display );
		}

		private function resolve_realtor_avatar_url( $post_id ) {
			$thumb_id = (int) get_post_thumbnail_id( $post_id );
			if ( $thumb_id > 0 ) {
				$url = wp_get_attachment_image_url( $thumb_id, 'thumbnail' );
				if ( $url ) return $url;
			}

			$linked_user_id = (int) get_post_meta( $post_id, 'houzez_user_meta_id', true );
			if ( $linked_user_id > 0 ) {
				$picture_id = (int) get_user_meta( $linked_user_id, 'fave_author_picture_id', true );
				if ( $picture_id > 0 ) {
					$url = wp_get_attachment_image_url( $picture_id, 'thumbnail' );
					if ( $url ) return $url;
				}
				$custom_url = (string) get_user_meta( $linked_user_id, 'fave_author_custom_picture', true );
				if ( $custom_url !== '' ) return $custom_url;
			}

			return '';
		}

		private function can_render_message_button( $target_user_id ) {
			$target_user_id = (int) $target_user_id;
			if ( $target_user_id <= 0 ) return false;
			if ( $target_user_id === (int) Better_Messages()->functions->get_current_user_id() ) return false;
			return true;
		}

		private function resolve_property_agent_user_id( $property_id ) {
			$property_id = (int) $property_id;
			if ( ! $property_id ) return 0;

			$agents = get_post_meta( $property_id, 'fave_agents', false );
			if ( is_array( $agents ) ) {
				foreach ( $agents as $agent_post_id ) {
					$user_id = $this->get_realtor_linked_user( $agent_post_id );
					if ( $user_id > 0 ) return $user_id;
				}
			}

			$agency_id = (int) get_post_meta( $property_id, 'fave_property_agency', true );
			if ( $agency_id > 0 ) {
				$user_id = $this->get_realtor_linked_user( $agency_id );
				if ( $user_id > 0 ) return $user_id;
			}

			return (int) get_post_field( 'post_author', $property_id );
		}

		private function get_realtor_linked_user( $post_id ) {
			$post_id = (int) $post_id;
			if ( ! $post_id ) return 0;
			return (int) get_post_meta( $post_id, 'houzez_user_meta_id', true );
		}

		private function resolve_realtor_user_id( $post_id ) {
			$user_id = $this->get_realtor_linked_user( $post_id );
			if ( $user_id > 0 ) return $user_id;
			return (int) get_post_field( 'post_author', $post_id );
		}

		private function render_live_chat_button( array $args ) {
			$defaults = array(
				'type'       => 'button',
				'class'      => 'bm-houzez-btn bm-houzez-btn-primary',
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

		public function property_button_shortcode( $atts = array() ) {
			$atts = shortcode_atts( array(
				'property_id' => 0,
				'class'       => '',
				'text'        => '',
			), $atts, 'better_messages_houzez_property_button' );

			$property_id = (int) ( $atts['property_id'] ?: get_queried_object_id() ?: get_the_ID() );
			if ( ! $property_id || get_post_type( $property_id ) !== 'property' ) return '';
			if ( get_post_status( $property_id ) !== 'publish' ) return '';

			$user_id = $this->resolve_property_agent_user_id( $property_id );
			if ( ! $this->can_render_message_button( $user_id ) ) return '';

			$subject = sprintf(
				_x( 'Question about "%s"', 'Houzez Integration (Property button)', 'bp-better-messages' ),
				get_the_title( $property_id )
			);
			$text = $atts['text'] ?: esc_attr_x( 'Live Chat', 'Houzez Integration (Property button)', 'bp-better-messages' );

			return $this->build_button_wrap( array(
				'wrap_class' => 'bm-houzez-property-button-wrap',
				'class'      => $atts['class'] ?: 'bm-houzez-btn bm-houzez-btn-primary bm-houzez-property-btn',
				'text'       => $text,
				'user_id'    => $user_id,
				'unique_tag' => 'houzez_property_chat_' . $property_id,
				'subject'    => esc_attr( $subject ),
			) );
		}

		public function agent_button_shortcode( $atts = array() ) {
			$atts = shortcode_atts( array(
				'agent_id' => 0,
				'class'    => '',
				'text'     => '',
				'inline'   => '',
			), $atts, 'better_messages_houzez_agent_button' );

			$agent_post_id = (int) ( $atts['agent_id'] ?: get_queried_object_id() ?: get_the_ID() );
			if ( ! $agent_post_id || get_post_type( $agent_post_id ) !== 'houzez_agent' ) return '';
			if ( get_post_status( $agent_post_id ) !== 'publish' ) return '';

			$user_id = $this->resolve_realtor_user_id( $agent_post_id );
			if ( ! $this->can_render_message_button( $user_id ) ) return '';

			$subject = sprintf(
				_x( 'Send a message to %s', 'Houzez Integration (Agent button)', 'bp-better-messages' ),
				get_the_title( $agent_post_id )
			);
			$text = $atts['text'] ?: esc_attr_x( 'Live Chat', 'Houzez Integration (Agent button)', 'bp-better-messages' );

			return $this->build_button_wrap( array(
				'wrap_class' => 'bm-houzez-agent-button-wrap' . ( ! empty( $atts['inline'] ) ? ' bm-houzez-inline' : '' ),
				'class'      => $atts['class'] ?: 'bm-houzez-btn bm-houzez-btn-primary bm-houzez-agent-btn',
				'text'       => $text,
				'user_id'    => $user_id,
				'unique_tag' => 'houzez_agent_chat_' . $agent_post_id,
				'subject'    => esc_attr( $subject ),
			) );
		}

		public function agency_button_shortcode( $atts = array() ) {
			$atts = shortcode_atts( array(
				'agency_id' => 0,
				'class'     => '',
				'text'      => '',
				'inline'    => '',
			), $atts, 'better_messages_houzez_agency_button' );

			$agency_post_id = (int) ( $atts['agency_id'] ?: get_queried_object_id() ?: get_the_ID() );
			if ( ! $agency_post_id || get_post_type( $agency_post_id ) !== 'houzez_agency' ) return '';
			if ( get_post_status( $agency_post_id ) !== 'publish' ) return '';

			$user_id = $this->resolve_realtor_user_id( $agency_post_id );
			if ( ! $this->can_render_message_button( $user_id ) ) return '';

			$subject = sprintf(
				_x( 'Send a message to %s', 'Houzez Integration (Agency button)', 'bp-better-messages' ),
				get_the_title( $agency_post_id )
			);
			$text = $atts['text'] ?: esc_attr_x( 'Live Chat', 'Houzez Integration (Agency button)', 'bp-better-messages' );

			return $this->build_button_wrap( array(
				'wrap_class' => 'bm-houzez-agency-button-wrap' . ( ! empty( $atts['inline'] ) ? ' bm-houzez-inline' : '' ),
				'class'      => $atts['class'] ?: 'bm-houzez-btn bm-houzez-btn-primary bm-houzez-agency-btn',
				'text'       => $text,
				'user_id'    => $user_id,
				'unique_tag' => 'houzez_agency_chat_' . $agency_post_id,
				'subject'    => esc_attr( $subject ),
			) );
		}

		private function build_button_wrap( array $args ) {
			$wrap_class = $args['wrap_class'];
			$extra_attr = ! empty( $args['extra_attr'] ) ? $args['extra_attr'] : '';

			unset( $args['wrap_class'], $args['extra_attr'] );

			$html = $this->render_live_chat_button( $args );
			if ( empty( $html ) ) return '';

			return '<div class="' . esc_attr( $wrap_class ) . '"' . $extra_attr . '>' . $html . '</div>';
		}

		public function render_property_button_auto() {
			if ( ! is_singular( 'property' ) ) return;

			$property_id = (int) get_queried_object_id();
			if ( ! $property_id ) return;

			$html = $this->property_button_shortcode( array( 'property_id' => $property_id ) );
			if ( empty( $html ) ) return;

			echo '<template data-bm-houzez-template="property">' . $html . '</template>';
			?>
			<script>
				document.addEventListener('DOMContentLoaded', function () {
					var tpl = document.querySelector('template[data-bm-houzez-template="property"]');
					if ( ! tpl ) return;

					var sendMessageBtns = document.querySelectorAll('.houzez-send-message');
					var sendEmailBtns   = document.querySelectorAll('.houzez_agent_property_form');
					var anchors = [];

					sendMessageBtns.forEach(function (btn) {
						var row = btn.closest('.d-flex') || btn.parentElement;
						if ( row && row.parentElement ) anchors.push(row);
					});

					if ( anchors.length === 0 ) {
						sendEmailBtns.forEach(function (btn) {
							var row = btn.closest('.d-flex') || btn.parentElement;
							if ( row && row.parentElement ) anchors.push(row);
						});
					}

					var seen = new Set();
					anchors.forEach(function (row) {
						if ( seen.has(row) ) return;
						seen.add(row);
						var clone = tpl.content.firstElementChild.cloneNode(true);
						row.parentNode.insertBefore(clone, row.nextSibling);
					});

					var mobileSticky = document.querySelector('.mobile-property-contact .d-flex, .mobile-property-button-contacts');
					if ( mobileSticky && ! mobileSticky.querySelector('.bm-houzez-property-button-wrap') ) {
						var mobileClone = tpl.content.firstElementChild.cloneNode(true);
						mobileClone.classList.add('bm-houzez-mobile-sticky');
						mobileSticky.appendChild(mobileClone);
					}
				});
			</script>
			<?php
		}

		public function render_agent_profile_button_auto() {
			$this->render_realtor_profile_button_auto( 'agent' );
		}

		public function render_agency_profile_button_auto() {
			$this->render_realtor_profile_button_auto( 'agency' );
		}

		private function render_realtor_profile_button_auto( $type ) {
			$post_type = $type === 'agency' ? 'houzez_agency' : 'houzez_agent';
			if ( ! is_singular( $post_type ) ) return;

			$post_id = (int) get_queried_object_id();
			if ( ! $post_id ) return;

			$shortcode_method = $type . '_button_shortcode';
			$atts_key = $type . '_id';
			$html = $this->$shortcode_method( array( $atts_key => $post_id, 'inline' => '1' ) );
			if ( empty( $html ) ) return;

			echo '<template data-bm-houzez-template="' . esc_attr( $type ) . '">' . $html . '</template>';
			$elementor_class = $type === 'agency' ? 'elementor-widget-houzez-agency-contact-btn' : 'elementor-widget-houzez-agent-contact-btn';
			?>
			<script>
				document.addEventListener('DOMContentLoaded', function () {
					var tpl = document.querySelector('template[data-bm-houzez-template="<?php echo esc_js( $type ); ?>"]');
					if ( ! tpl ) return;

					var row = document.querySelector('.agent-profile-buttons')
					       || document.querySelector('.agent-profile-content .d-flex')
					       || document.querySelector('.agent-profile-content .row');
					var elementorAnchor = null;

					if ( ! row ) {
						elementorAnchor = document.querySelector('[data-bs-target="#realtor-form"]')
						               || document.querySelector('.<?php echo esc_js( $elementor_class ); ?>');
						if ( elementorAnchor ) row = elementorAnchor.closest('.d-flex') || elementorAnchor.parentElement;
					}
					if ( ! row ) return;

					var clone = tpl.content.firstElementChild.cloneNode(true);

					// Houzez Studio Elementor templates use .houzez-ele-button which is
					// shorter (40px) and uses 12px font + vertical padding instead of
					// line-height. Mark the wrap so CSS can match that geometry, and
					// drop the inline-flex wrap from the parent column so we sit as a
					// sibling button next to Contact Agent / Call.
					if ( elementorAnchor && row.querySelector('.houzez-ele-button') ) {
						clone.classList.add('bm-houzez-elementor');
					}

					row.appendChild(clone);
				});
			</script>
			<?php
		}

		public function property_card_button_shortcode( $atts = array() ) {
			$atts = shortcode_atts( array(
				'property_id' => 0,
				'class'       => '',
				'text'        => '',
			), $atts, 'better_messages_houzez_property_card_button' );

			$property_id = (int) ( $atts['property_id'] ?: get_queried_object_id() ?: get_the_ID() );
			if ( ! $property_id || get_post_type( $property_id ) !== 'property' ) return '';
			if ( get_post_status( $property_id ) !== 'publish' ) return '';

			$user_id = $this->resolve_property_agent_user_id( $property_id );
			if ( ! $this->can_render_message_button( $user_id ) ) return '';

			$subject = sprintf(
				_x( 'Question about "%s"', 'Houzez Integration (Property card button)', 'bp-better-messages' ),
				get_the_title( $property_id )
			);
			$text = $atts['text'] ?: esc_attr_x( 'Live Chat', 'Houzez Integration (Property card button)', 'bp-better-messages' );

			return $this->build_button_wrap( array(
				'wrap_class' => 'bm-houzez-card-button-wrap',
				'extra_attr' => ' data-bm-houzez-card="' . esc_attr( $property_id ) . '"',
				'type'       => 'button',
				'class'      => $atts['class'] ?: 'btn btn-primary-outlined btn-item px-2 d-flex align-items-center justify-content-center flex-fill gap-1 bm-houzez-card-btn',
				'text'       => $text,
				'user_id'    => $user_id,
				'unique_tag' => 'houzez_property_chat_' . $property_id,
				'subject'    => esc_attr( $subject ),
			) );
		}

		public function render_card_buttons_auto() {
			if ( is_admin() ) return;

			$post_ids = $this->collect_visible_property_ids();
			if ( empty( $post_ids ) ) return;

			$payloads = array();
			foreach ( $post_ids as $pid ) {
				$html = $this->property_card_button_shortcode( array( 'property_id' => $pid ) );
				if ( ! empty( $html ) ) {
					$payloads[ $pid ] = $html;
				}
			}
			if ( empty( $payloads ) ) return;

			echo '<script id="bm-houzez-card-buttons-data" type="application/json">';
			echo wp_json_encode( $payloads );
			echo '</script>';
			?>
			<script>
				(function () {
					function inject() {
						var dataScript = document.getElementById('bm-houzez-card-buttons-data');
						if ( ! dataScript ) return;
						var payloads;
						try { payloads = JSON.parse( dataScript.textContent ); } catch ( e ) { return; }
						if ( ! payloads || typeof payloads !== 'object' ) return;

						var cards = document.querySelectorAll('[data-hz-id]');
						cards.forEach(function ( card ) {
							var rawId = card.getAttribute('data-hz-id') || '';
							var id    = parseInt( rawId, 10 );
							if ( ! id || ! payloads[ id ] ) return;

							var leftWrap = card.querySelector('.item-buttons-left-wrap');
							var btnRow   = leftWrap
								|| card.querySelector('.item-buttons-wrap');

							if ( ! btnRow ) {
								var nativeBtn = card.querySelector('.hz-call-popup-js, .hz-email-popup-js, .hz-btn-whatsapp, .houzez_agent_property_form');
								if ( nativeBtn ) {
									btnRow = nativeBtn.closest('.d-flex, [class*="item-buttons"]');
								}
							}

							if ( ! btnRow ) return;
							if ( btnRow.querySelector('.bm-houzez-card-button-wrap') ) return;

							var holder = document.createElement('div');
							holder.innerHTML = payloads[ id ];
							var wrap = holder.firstElementChild;
							if ( ! wrap ) return;

							btnRow.appendChild( wrap );
							btnRow.classList.add('bm-houzez-with-live-chat');
							if ( leftWrap ) {
								btnRow.classList.add('bm-houzez-list-view');
							}

							var footer = card.querySelector('.item-footer');
							if ( footer ) {
								var existing = footer.className.match(/items-btns-count-(\d+)/);
								if ( existing ) {
									var current = parseInt( existing[1], 10 );
									footer.classList.remove( 'items-btns-count-' + current );
									footer.classList.add( 'items-btns-count-' + Math.min( current + 1, 5 ) );
								}
							}
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
				$this->visible_property_ids[ (int) $post->ID ] = true;
			}
		}

		private function collect_visible_property_ids() {
			$ids = array_keys( $this->visible_property_ids );

			global $wp_query;
			if ( $wp_query && ! empty( $wp_query->posts ) ) {
				foreach ( $wp_query->posts as $post ) {
					if ( ! empty( $post->post_type ) && $post->post_type === 'property' ) {
						$ids[] = (int) $post->ID;
					}
				}
			}

			return array_values( array_unique( array_filter( $ids ) ) );
		}

		private function get_theme_accent_color() {
			if ( function_exists( 'houzez_option' ) ) {
				$color = houzez_option( 'houzez_primary_color' );
				if ( is_string( $color ) && $color !== '' ) {
					return $color;
				}
			}
			return '#00AEEF';
		}

		private function get_theme_accent_hover_color() {
			if ( function_exists( 'houzez_option' ) ) {
				$hover = houzez_option( 'houzez_primary_color_hover' );
				if ( is_array( $hover ) && ! empty( $hover['color'] ) ) {
					return (string) $hover['color'];
				}
				if ( is_string( $hover ) && $hover !== '' ) {
					return $hover;
				}
			}
			return '#0095cc';
		}

		public function print_styles() {
			$accent       = esc_attr( $this->get_theme_accent_color() );
			$accent_hover = esc_attr( $this->get_theme_accent_hover_color() );
			?>
<style id="bm-houzez-button-styles">
:root,
body.houzez-dashboard-body,
.bp-better-messages {
	--bm-houzez-accent: <?php echo $accent; ?>;
	--bm-houzez-accent-hover: <?php echo $accent_hover; ?>;
}
.bm-houzez-property-button-wrap,
.bm-houzez-agent-button-wrap,
.bm-houzez-agency-button-wrap {
	display: block;
	width: 100%;
	box-sizing: border-box;
	margin: 7px 0 0 0;
}
.bm-houzez-property-button-wrap .bm-lc-button,
.bm-houzez-agent-button-wrap .bm-lc-button,
.bm-houzez-agency-button-wrap .bm-lc-button {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	width: 100%;
	box-sizing: border-box;
	gap: 6px;
	padding: 10px 14px;
	border-radius: 6px;
	background: var(--bm-houzez-accent, #00AEEF);
	color: #fff;
	border: 1px solid var(--bm-houzez-accent, #00AEEF);
	font-size: 14px;
	font-weight: 600;
	line-height: 1.2;
	text-align: center;
	cursor: pointer;
	transition: background-color .2s, border-color .2s, color .2s;
}
.bm-houzez-property-button-wrap .bm-lc-button:hover,
.bm-houzez-property-button-wrap .bm-lc-button:focus,
.bm-houzez-agent-button-wrap .bm-lc-button:hover,
.bm-houzez-agent-button-wrap .bm-lc-button:focus,
.bm-houzez-agency-button-wrap .bm-lc-button:hover,
.bm-houzez-agency-button-wrap .bm-lc-button:focus {
	background: var(--bm-houzez-accent-hover, #0095cc);
	border-color: var(--bm-houzez-accent-hover, #0095cc);
	color: #fff;
}
.bm-houzez-property-button-wrap .bm-lc-button:before,
.bm-houzez-agent-button-wrap .bm-lc-button:before,
.bm-houzez-agency-button-wrap .bm-lc-button:before {
	content: "\e92a";
	font-family: 'houzez-iconfont';
	font-weight: 400;
	font-size: 16px;
	line-height: 1;
}
.bm-houzez-agent-button-wrap.bm-houzez-inline,
.bm-houzez-agency-button-wrap.bm-houzez-inline {
	display: inline-flex;
	align-items: stretch;
	flex: 0 0 auto;
	width: auto;
	margin: 0;
	vertical-align: middle;
}
.bm-houzez-agent-button-wrap.bm-houzez-inline .bm-lc-button,
.bm-houzez-agency-button-wrap.bm-houzez-inline .bm-lc-button {
	width: auto;
	padding: 0 22px;
	font-size: 15px;
	line-height: 40px;
	border-radius: 4px;
}
.bm-houzez-agent-button-wrap.bm-houzez-inline .bm-lc-button:before,
.bm-houzez-agency-button-wrap.bm-houzez-inline .bm-lc-button:before {
	font-size: 15px;
}
/* When Houzez renders the agent / agency profile via the Studio Elementor
 * template, the native Contact / Call buttons are 40px tall (12px font with
 * 14px/12px vertical padding, line-height 12px). Match that geometry so the
 * three buttons line up. */
.bm-houzez-agent-button-wrap.bm-houzez-inline.bm-houzez-elementor .bm-lc-button,
.bm-houzez-agency-button-wrap.bm-houzez-inline.bm-houzez-elementor .bm-lc-button {
	padding: 14px 24px 12px;
	font-size: 12px;
	line-height: 12px;
	letter-spacing: 0.5px;
	text-transform: uppercase;
}
.bm-houzez-agent-button-wrap.bm-houzez-inline.bm-houzez-elementor .bm-lc-button:before,
.bm-houzez-agency-button-wrap.bm-houzez-inline.bm-houzez-elementor .bm-lc-button:before {
	font-size: 12px;
}
body.page-bm-houzez-live-chat .wrapper,
body.page-bm-houzez-live-chat .dashboard-right {
	height: 100vh;
}
body.admin-bar.page-bm-houzez-live-chat .wrapper,
body.admin-bar.page-bm-houzez-live-chat .dashboard-right {
	height: calc(100vh - 32px);
}
@media screen and (max-width: 782px) {
	body.admin-bar.page-bm-houzez-live-chat .wrapper,
	body.admin-bar.page-bm-houzez-live-chat .dashboard-right {
		height: calc(100vh - 46px);
	}
}
body.page-bm-houzez-live-chat .dashboard-right {
	display: flex;
	flex-direction: column;
}
body.page-bm-houzez-live-chat .dashboard-right > .topbar,
body.page-bm-houzez-live-chat .dashboard-right > .dashboard-topbar {
	flex: 0 0 auto;
}
.dashboard-content.bm-houzez-dashboard-live-chat {
	padding: 0;
	background: #fff;
	flex: 1 1 auto;
	min-height: 0;
}
.dashboard-sidebar [data-bm-houzez-tab="live-chat"] a {
	position: relative;
}
.dashboard-sidebar [data-bm-houzez-tab="live-chat"] .bm-houzez-unread-badge {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	min-width: 18px;
	height: 18px;
	padding: 0 6px;
	margin-left: auto;
	border-radius: 9px;
	background: var(--bm-houzez-accent, #00AEEF);
	color: #fff;
	font-size: 11px;
	font-weight: 700;
	line-height: 1;
}
.dashboard-content.bm-houzez-dashboard-live-chat .bp-better-messages {
	height: 100%;
	min-height: 0;
}
.dashboard-content.bm-houzez-dashboard-live-chat .bp-messages-wrap,
.dashboard-content.bm-houzez-dashboard-live-chat .bp-messages-wrap-main {
	border: none !important;
	border-radius: 0 !important;
	box-shadow: none !important;
	height: 100% !important;
}
.dashboard-content.bm-houzez-dashboard-live-chat .bp-messages-wrap-main .bp-messages-wrap:not(.bp-messages-full-screen, .bp-messages-mobile),
.dashboard-content.bm-houzez-dashboard-live-chat .bp-messages-wrap-main .bp-messages-threads-wrapper {
	height: 100% !important;
}
.bm-houzez-property-button-wrap.bm-houzez-mobile-sticky {
	margin: 0;
	width: auto;
	flex: 0 0 auto;
	align-self: center;
}
.bm-houzez-property-button-wrap.bm-houzez-mobile-sticky .bm-lc-button {
	width: 44px;
	height: 44px;
	padding: 0;
	font-size: 0;
}
.bm-houzez-property-button-wrap.bm-houzez-mobile-sticky .bm-lc-button:before {
	font-size: 18px;
}
.bm-houzez-property-button-wrap.bm-houzez-mobile-sticky .bm-lc-button .bm-button-text {
	display: none;
}
.item-buttons-wrap.bm-houzez-with-live-chat,
.item-buttons-left-wrap.bm-houzez-with-live-chat {
	display: grid !important;
	grid-auto-flow: column;
	grid-auto-columns: minmax(0, 1fr);
}
.item-buttons-wrap.bm-houzez-with-live-chat > .btn,
.item-buttons-wrap.bm-houzez-with-live-chat > a.btn,
.item-buttons-wrap.bm-houzez-with-live-chat > .bm-houzez-card-button-wrap,
.item-buttons-left-wrap.bm-houzez-with-live-chat > .btn,
.item-buttons-left-wrap.bm-houzez-with-live-chat > a.btn,
.item-buttons-left-wrap.bm-houzez-with-live-chat > .bm-houzez-card-button-wrap {
	min-width: 0;
}
.item-buttons-wrap .bm-houzez-card-button-wrap {
	display: flex;
	flex: 1 1 0;
	min-width: 0;
	margin: 0;
}
.item-buttons-wrap .bm-houzez-card-button-wrap .bm-lc-button.bm-houzez-card-btn,
.item-buttons-left-wrap .bm-houzez-card-button-wrap .bm-lc-button.bm-houzez-card-btn {
	flex: 1 1 0;
	min-width: 0;
	background: transparent;
	color: var(--bm-houzez-accent, #00AEEF) !important;
}
.item-buttons-wrap .bm-houzez-card-button-wrap .bm-lc-button.bm-houzez-card-btn:hover,
.item-buttons-wrap .bm-houzez-card-button-wrap .bm-lc-button.bm-houzez-card-btn:focus,
.item-buttons-left-wrap .bm-houzez-card-button-wrap .bm-lc-button.bm-houzez-card-btn:hover,
.item-buttons-left-wrap .bm-houzez-card-button-wrap .bm-lc-button.bm-houzez-card-btn:focus {
	background: var(--bm-houzez-accent, #00AEEF) !important;
	color: #fff !important;
}
.item-buttons-wrap .bm-houzez-card-button-wrap .bm-lc-button.bm-houzez-card-btn .bm-button-text {
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}
.item-buttons-wrap .bm-houzez-card-button-wrap .bm-lc-button.bm-houzez-card-btn:before {
	content: "\e92a";
	font-family: 'houzez-iconfont';
	font-weight: 400;
	font-size: 14px;
	flex: 0 0 auto;
	line-height: 1;
	speak: none;
	font-style: normal;
	-webkit-font-smoothing: antialiased;
	-moz-osx-font-smoothing: grayscale;
}
.item-buttons-wrap .bm-houzez-card-button-wrap .bm-lc-button:hover,
.item-buttons-wrap .bm-houzez-card-button-wrap .bm-lc-button:focus {
	background: var(--bm-houzez-accent, #00AEEF);
	color: #fff;
}
.bm-houzez-property-info,
.bm-houzez-realtor-info {
	position: relative;
	display: flex;
	align-items: flex-start;
	padding: 10px 12px;
	background: #f7f9fb;
	border-bottom: 1px solid #e6ebf0;
}
.bm-houzez-property-info .bm-product-details,
.bm-houzez-realtor-info .bm-product-details {
	flex: 1 1 auto;
	min-width: 0;
	display: flex;
	flex-direction: column;
	gap: 2px;
}
.bm-houzez-property-info:has(.bm-houzez-property-status) .bm-product-details {
	padding-right: 80px;
}
.bm-houzez-property-info .bm-houzez-property-status {
	position: absolute;
	top: 10px;
	right: 12px;
	font-size: 11px;
	font-weight: 600;
	text-transform: uppercase;
	letter-spacing: .5px;
	padding: 2px 8px;
	border-radius: 3px;
	background: var(--bm-houzez-accent, #00AEEF);
	color: #fff;
	z-index: 1;
}
.bm-houzez-property-info .bm-product-title a,
.bm-houzez-realtor-info .bm-product-title a {
	color: inherit;
	font-weight: 600;
	font-size: 14px;
	text-decoration: none;
}
.bm-houzez-property-info .bm-product-title a:hover,
.bm-houzez-realtor-info .bm-product-title a:hover {
	color: var(--bm-houzez-accent, #00AEEF);
}
.bm-houzez-property-info .bm-product-price {
	font-size: 14px;
	font-weight: 600;
	color: var(--bm-houzez-accent, #00AEEF);
}
.bm-houzez-property-info .bm-product-price .item-price,
.bm-houzez-property-info .bm-product-price span {
	font-size: inherit;
	font-weight: inherit;
	color: inherit;
}
.bm-houzez-property-info .bm-product-subtitle,
.bm-houzez-realtor-info .bm-product-subtitle,
.bm-houzez-property-info .bm-houzez-property-address {
	font-size: 12px;
	color: #6b7280;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}
.bm-houzez-persona-banner {
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 8px 12px;
	background: #fff8e1;
	border-bottom: 1px solid #ffe0a3;
	font-size: 12px;
	color: #6b4f00;
}
.bm-houzez-persona-banner img {
	width: 22px;
	height: 22px;
	border-radius: 50%;
	object-fit: cover;
}
.bm-houzez-persona-banner strong {
	color: #1f2937;
	font-weight: 600;
}
</style>
			<?php
		}
	}

}
