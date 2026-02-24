<?php
/**
 * Admin settings page under Settings → Song Credits.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Song_Credits_Settings {

	/** @var self|null */
	private static $instance = null;

	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_menu', array( $this, 'add_page' ) );
		add_action( 'admin_init', array( $this, 'register' ) );
		add_action( 'admin_post_song_credits_cache_action', array( $this, 'handle_cache_action' ) );
		add_action( 'admin_post_song_credits_test_connections', array( $this, 'handle_test_connections' ) );
		add_filter( 'plugin_action_links_' . SONG_CREDITS_PLUGIN_BASENAME, array( $this, 'action_links' ) );
	}

	/* --- Menu ------------------------------------------------------- */

	public function add_page() {
		add_options_page(
			__( 'Song Credits Settings', 'song-credits' ),
			__( 'Song Credits', 'song-credits' ),
			'manage_options',
			'song-credits',
			array( $this, 'render_page' )
		);
	}

	/* --- Settings API ----------------------------------------------- */

	public function register() {
		register_setting(
			'song_credits_group',
			'song_credits_settings',
			array( $this, 'sanitize' )
		);

		add_settings_section( 'sc_api', __( 'API Configuration', 'song-credits' ), array( $this, 'section_api' ), 'song-credits' );
		add_settings_section( 'sc_cache', __( 'Caching', 'song-credits' ), array( $this, 'section_cache' ), 'song-credits' );
		add_settings_section( 'sc_security', __( 'Security', 'song-credits' ), array( $this, 'section_security' ), 'song-credits' );
		add_settings_section( 'sc_debug', __( 'Debug', 'song-credits' ), array( $this, 'section_debug' ), 'song-credits' );

		add_settings_field( 'contact_email', __( 'Contact Email', 'song-credits' ), array( $this, 'field_email' ), 'song-credits', 'sc_api' );
		add_settings_field( 'discogs_token', __( 'Discogs Personal Access Token', 'song-credits' ), array( $this, 'field_token' ), 'song-credits', 'sc_api' );
		add_settings_field( 'cache_duration', __( 'Cache Duration (hours)', 'song-credits' ), array( $this, 'field_cache' ), 'song-credits', 'sc_cache' );
		add_settings_field( 'rate_limit_per_minute', __( 'Rate Limit (req/min)', 'song-credits' ), array( $this, 'field_rate_limit' ), 'song-credits', 'sc_security' );
		add_settings_field( 'security_notes', __( 'Security Controls', 'song-credits' ), array( $this, 'field_security_notes' ), 'song-credits', 'sc_security' );
		add_settings_field( 'debug_logging', __( 'Debug Logging', 'song-credits' ), array( $this, 'field_debug_logging' ), 'song-credits', 'sc_debug' );
	}

	/**
	 * Sanitize callback for the settings group.
	 */
	public function sanitize( $input ) {
		$existing = get_option( 'song_credits_settings', array() );

		$email = sanitize_email( $input['contact_email'] ?? '' );
		if ( '' === $email ) {
			$email = ! empty( $existing['contact_email'] ) ? sanitize_email( $existing['contact_email'] ) : sanitize_email( get_option( 'admin_email' ) );
			add_settings_error( 'song_credits_settings', 'invalid_email', __( 'Contact email was invalid. Existing email was kept.', 'song-credits' ), 'error' );
		}

		$token = sanitize_text_field( $input['discogs_token'] ?? '' );
		if ( '' === $token && ! empty( $existing['discogs_token'] ) ) {
			$token = (string) $existing['discogs_token'];
		}
		if ( preg_match( '/\s/', $token ) ) {
			add_settings_error( 'song_credits_settings', 'token_whitespace', __( 'Discogs token should not contain spaces.', 'song-credits' ), 'warning' );
		}

		$cache_duration = min( 168, max( 1, absint( $input['cache_duration'] ?? 24 ) ) );
		$old_cache      = ! empty( $existing['cache_duration'] ) ? (int) $existing['cache_duration'] : 24;
		if ( $cache_duration < $old_cache ) {
			add_settings_error(
				'song_credits_settings',
				'cache_reduced',
				sprintf(
					/* translators: 1: old hours, 2: new hours */
					__( 'Cache duration reduced from %1$d to %2$d hours.', 'song-credits' ),
					$old_cache,
					$cache_duration
				),
				'warning'
			);
		}

		$rate_limit = min( 120, max( 1, absint( $input['rate_limit_per_minute'] ?? 10 ) ) );

		return array(
			'contact_email'          => $email,
			'discogs_token'          => $token,
			'cache_duration'         => $cache_duration,
			'rate_limit_per_minute'  => $rate_limit,
			'debug_logging'          => ! empty( $input['debug_logging'] ) ? 1 : 0,
		);
	}

	/* --- Section text ------------------------------------------------ */

	public function section_api() {
		echo '<p>' . esc_html__( 'MusicBrainz is used by default (no key needed). Add a Discogs token for richer results.', 'song-credits' ) . '</p>';
	}

	public function section_cache() {
		echo '<p>' . esc_html__( 'Control how long lookup data remains in the cache.', 'song-credits' ) . '</p>';
	}

	public function section_security() {
		echo '<p>' . esc_html__( 'Adjust request limits and review active security controls.', 'song-credits' ) . '</p>';
	}

	public function section_debug() {
		echo '<p>' . esc_html__( 'Enable additional plugin diagnostics when troubleshooting API issues.', 'song-credits' ) . '</p>';
	}

	/* --- Field renderers -------------------------------------------- */

	public function field_email() {
		$s = get_option( 'song_credits_settings', array() );
		printf(
			'<input type="email" name="song_credits_settings[contact_email]" value="%s" class="regular-text" />
			 <p class="description">%s</p>',
			esc_attr( $s['contact_email'] ?? '' ),
			esc_html__( 'Required by MusicBrainz — included in the User-Agent header.', 'song-credits' )
		);
	}

	public function field_token() {
		$s     = get_option( 'song_credits_settings', array() );
		$token = isset( $s['discogs_token'] ) ? (string) $s['discogs_token'] : '';
		printf(
			'<input type="password" id="song-credits-token" name="song_credits_settings[discogs_token]" value="%s" class="regular-text" autocomplete="off" />
			 <button type="button" id="song-credits-token-toggle" class="button button-secondary" aria-pressed="false" aria-controls="song-credits-token" style="margin-left:6px;">%s</button>
			 <p class="description">%s <a href="https://www.discogs.com/settings/developers" target="_blank" rel="noopener noreferrer">Discogs Developer Settings</a>.</p>',
			esc_attr( $token ),
			esc_html__( 'Show', 'song-credits' ),
			esc_html__( 'Optional. Generate one at', 'song-credits' )
		);
	}

	public function field_cache() {
		$s = get_option( 'song_credits_settings', array() );
		printf(
			'<input type="number" name="song_credits_settings[cache_duration]" value="%s" min="1" max="168" class="small-text" />
			 <p class="description">%s</p>',
			esc_attr( $s['cache_duration'] ?? 24 ),
			esc_html__( 'How long to cache results (1–168). Default: 24.', 'song-credits' )
		);
	}

	public function field_rate_limit() {
		$s = get_option( 'song_credits_settings', array() );
		printf(
			'<input type="number" name="song_credits_settings[rate_limit_per_minute]" value="%s" min="1" max="120" class="small-text" />
			 <p class="description">%s</p>',
			esc_attr( $s['rate_limit_per_minute'] ?? 10 ),
			esc_html__( 'Maximum lookup requests per minute per guest IP (or per logged-in user).', 'song-credits' )
		);
	}

	public function field_security_notes() {
		echo '<p class="description">' . esc_html__( 'Nonce checks, POST-only AJAX handling, input length checks, host allowlisting, and HTTPS verification are enabled.', 'song-credits' ) . '</p>';
	}

	public function field_debug_logging() {
		$s       = get_option( 'song_credits_settings', array() );
		$enabled = ! empty( $s['debug_logging'] );
		printf(
			'<label><input type="checkbox" name="song_credits_settings[debug_logging]" value="1" %s /> %s</label>
			 <p class="description">%s</p>',
			checked( $enabled, true, false ),
			esc_html__( 'Enable bounded API debug logging to PHP error log', 'song-credits' ),
			esc_html__( 'Logs request failures and retries without sensitive token data.', 'song-credits' )
		);
	}

	/* --- Page renderer ---------------------------------------------- */

	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$cache_notice = $this->get_cache_action_notice();
		$tested       = ! empty( $_GET['song_credits_tested'] ) ? absint( wp_unslash( $_GET['song_credits_tested'] ) ) : 0;
		?>
		<div class="wrap">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
			<?php settings_errors( 'song_credits_settings' ); ?>
			<?php if ( $cache_notice ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php echo esc_html( $cache_notice ); ?></p></div>
			<?php endif; ?>

			<form action="options.php" method="post">
				<?php
				settings_fields( 'song_credits_group' );
				do_settings_sections( 'song-credits' );
				submit_button();
				?>
			</form>

			<hr />
			<h2><?php esc_html_e( 'API Connection Test', 'song-credits' ); ?></h2>
			<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post" style="margin: 0 0 1rem;">
				<?php wp_nonce_field( 'song_credits_test_connections' ); ?>
				<input type="hidden" name="action" value="song_credits_test_connections" />
				<?php submit_button( __( 'Test API Connections', 'song-credits' ), 'secondary', 'submit', false ); ?>
			</form>
			<?php if ( $tested ) : ?>
				<?php $this->render_test_connection_results(); ?>
			<?php endif; ?>

			<hr />
			<h2><?php esc_html_e( 'Usage', 'song-credits' ); ?></h2>
			<p><?php esc_html_e( 'Add the lookup form to any page or post with:', 'song-credits' ); ?></p>
			<p><code>[song_credits]</code></p>

			<hr />
			<h2><?php esc_html_e( 'Cached Searches', 'song-credits' ); ?></h2>
			<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post" style="margin: 0 0 1rem;">
				<?php wp_nonce_field( 'song_credits_cache_action' ); ?>
				<input type="hidden" name="action" value="song_credits_cache_action" />
				<input type="hidden" name="mode" value="clear_all" />
				<?php submit_button( __( 'Clear Cached Searches', 'song-credits' ), 'secondary', 'submit', false ); ?>
			</form>
			<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post" style="margin: 0 0 1rem;">
				<?php wp_nonce_field( 'song_credits_cache_action' ); ?>
				<input type="hidden" name="action" value="song_credits_cache_action" />
				<input type="hidden" name="mode" value="clear_expired" />
				<?php submit_button( __( 'Clear Expired Only', 'song-credits' ), 'secondary', 'submit', false ); ?>
			</form>
			<?php $this->render_cached_searches_table(); ?>

			<hr />
			<h2><?php esc_html_e( 'Performance Metrics', 'song-credits' ); ?></h2>
			<?php $this->render_metrics_panel(); ?>

			<script>
			( function () {
				var tokenInput = document.getElementById( 'song-credits-token' );
				var toggleBtn = document.getElementById( 'song-credits-token-toggle' );
				if ( tokenInput && toggleBtn ) {
					toggleBtn.addEventListener( 'click', function () {
						var hidden = tokenInput.getAttribute( 'type' ) === 'password';
						tokenInput.setAttribute( 'type', hidden ? 'text' : 'password' );
						toggleBtn.textContent = hidden ? '<?php echo esc_js( __( 'Hide', 'song-credits' ) ); ?>' : '<?php echo esc_js( __( 'Show', 'song-credits' ) ); ?>';
						toggleBtn.setAttribute( 'aria-pressed', hidden ? 'true' : 'false' );
					} );
				}

				document.querySelectorAll( '.song-credits-copy-link' ).forEach( function ( btn ) {
					btn.addEventListener( 'click', function () {
						var link = btn.getAttribute( 'data-link' ) || '';
						if ( ! link || ! navigator.clipboard ) {
							return;
						}
						navigator.clipboard.writeText( link );
					} );
				} );
			}() );
			</script>
		</div>
		<?php
	}

	/* --- Admin actions ------------------------------------------------ */

	public function handle_test_connections() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'song-credits' ) );
		}
		check_admin_referer( 'song_credits_test_connections' );

		$api     = new Song_Credits_API();
		$results = $api->test_connections();
		set_transient( 'song_credits_test_results_' . get_current_user_id(), $results, 5 * MINUTE_IN_SECONDS );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'                => 'song-credits',
					'song_credits_tested' => 1,
				),
				admin_url( 'options-general.php' )
			)
		);
		exit;
	}

	public function handle_cache_action() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'song-credits' ) );
		}
		check_admin_referer( 'song_credits_cache_action' );

		global $wpdb;

		$mode  = isset( $_REQUEST['mode'] ) ? sanitize_key( wp_unslash( $_REQUEST['mode'] ) ) : '';
		$count = 0;

		if ( 'clear_all' === $mode ) {
			$count = (int) $wpdb->query(
				"DELETE FROM {$wpdb->options}
				 WHERE option_name LIKE '_transient_song_credits_%'
					OR option_name LIKE '_transient_timeout_song_credits_%'"
			);
		} elseif ( 'clear_expired' === $mode ) {
			$timeout_rows = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT option_name
					 FROM {$wpdb->options}
					 WHERE option_name LIKE %s
					   AND option_value < %d",
					$wpdb->esc_like( '_transient_timeout_song_credits_' ) . '%',
					time()
				)
			);
			foreach ( (array) $timeout_rows as $timeout_name ) {
				$suffix = substr( (string) $timeout_name, strlen( '_transient_timeout_song_credits_' ) );
				if ( ! preg_match( '/^[a-f0-9]{32}$/', $suffix ) ) {
					continue;
				}
				delete_option( '_transient_timeout_song_credits_' . $suffix );
				delete_option( '_transient_song_credits_' . $suffix );
				$count += 2;
			}
		} elseif ( 'delete_selected' === $mode || 'delete_single' === $mode ) {
			$keys = array();
			if ( 'delete_single' === $mode ) {
				$single = isset( $_REQUEST['cache_key'] ) ? strtolower( sanitize_text_field( wp_unslash( $_REQUEST['cache_key'] ) ) ) : '';
				if ( preg_match( '/^[a-f0-9]{32}$/', $single ) ) {
					$keys[] = $single;
				}
			} else {
				$posted_keys = isset( $_POST['cache_keys'] ) && is_array( $_POST['cache_keys'] ) ? wp_unslash( $_POST['cache_keys'] ) : array();
				foreach ( $posted_keys as $key ) {
					$key = strtolower( sanitize_text_field( (string) $key ) );
					if ( preg_match( '/^[a-f0-9]{32}$/', $key ) ) {
						$keys[] = $key;
					}
				}
			}
			$keys = array_values( array_unique( $keys ) );
			foreach ( $keys as $key ) {
				delete_option( '_transient_timeout_song_credits_' . $key );
				delete_option( '_transient_song_credits_' . $key );
				$count += 2;
			}
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'                     => 'song-credits',
					'song_credits_cache_action' => $mode ? $mode : 'updated',
					'song_credits_cache_count'  => $count,
				),
				admin_url( 'options-general.php' )
			)
		);
		exit;
	}

	/* --- Panels ------------------------------------------------------ */

	private function render_test_connection_results() {
		$results = get_transient( 'song_credits_test_results_' . get_current_user_id() );
		if ( empty( $results ) || ! is_array( $results ) ) {
			echo '<p>' . esc_html__( 'No recent test results. Run the connection test.', 'song-credits' ) . '</p>';
			return;
		}
		?>
		<table class="widefat striped">
			<thead>
			<tr>
				<th scope="col"><?php esc_html_e( 'Source', 'song-credits' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Status', 'song-credits' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Details', 'song-credits' ); ?></th>
			</tr>
			</thead>
			<tbody>
			<?php foreach ( $results as $source => $result ) : ?>
				<tr>
					<td><?php echo esc_html( ucfirst( (string) $source ) ); ?></td>
					<td><?php echo ! empty( $result['ok'] ) ? esc_html__( 'Pass', 'song-credits' ) : esc_html__( 'Fail/Skipped', 'song-credits' ); ?></td>
					<td><?php echo esc_html( (string) ( $result['message'] ?? '' ) ); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	private function render_cached_searches_table() {
		$entries         = $this->build_cached_entries();
		$lookup_page_url = $this->get_lookup_page_url();

		$q            = isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( $_GET['q'] ) ) : '';
		$source       = isset( $_GET['source'] ) ? sanitize_text_field( wp_unslash( $_GET['source'] ) ) : '';
		$has_perf     = isset( $_GET['has_performers'] ) ? sanitize_key( wp_unslash( $_GET['has_performers'] ) ) : '';
		$has_year     = isset( $_GET['has_year'] ) ? sanitize_key( wp_unslash( $_GET['has_year'] ) ) : '';
		$orderby      = isset( $_GET['orderby'] ) ? sanitize_key( wp_unslash( $_GET['orderby'] ) ) : 'title';
		$order        = isset( $_GET['order'] ) ? sanitize_key( wp_unslash( $_GET['order'] ) ) : 'asc';
		$paged        = isset( $_GET['paged'] ) ? max( 1, absint( wp_unslash( $_GET['paged'] ) ) ) : 1;
		$per_page     = 25;

		if ( '' !== $q ) {
			$entries = array_filter(
				$entries,
				static function( $entry ) use ( $q ) {
					$q = strtolower( $q );
					return false !== strpos( strtolower( $entry['artist'] ), $q ) || false !== strpos( strtolower( $entry['title'] ), $q );
				}
			);
		}

		if ( '' !== $source ) {
			$entries = array_filter(
				$entries,
				static function( $entry ) use ( $source ) {
					return in_array( $source, $entry['sources_array'], true );
				}
			);
		}

		if ( 'yes' === $has_perf ) {
			$entries = array_filter( $entries, static function( $entry ) { return ! empty( $entry['has_performers'] ); } );
		} elseif ( 'no' === $has_perf ) {
			$entries = array_filter( $entries, static function( $entry ) { return empty( $entry['has_performers'] ); } );
		}

		if ( 'yes' === $has_year ) {
			$entries = array_filter( $entries, static function( $entry ) { return ! empty( $entry['has_year'] ); } );
		} elseif ( 'no' === $has_year ) {
			$entries = array_filter( $entries, static function( $entry ) { return empty( $entry['has_year'] ); } );
		}

		$allowed_orderby = array( 'title', 'artist', 'categories', 'expires' );
		if ( ! in_array( $orderby, $allowed_orderby, true ) ) {
			$orderby = 'title';
		}
		$order = 'desc' === $order ? 'desc' : 'asc';

		usort(
			$entries,
			static function( $a, $b ) use ( $orderby, $order ) {
				if ( 'artist' === $orderby ) {
					$cmp = strcmp( $a['artist'], $b['artist'] );
				} elseif ( 'categories' === $orderby ) {
					$cmp = (int) $a['category_count'] - (int) $b['category_count'];
				} elseif ( 'expires' === $orderby ) {
					$cmp = (int) $a['expires_ts'] - (int) $b['expires_ts'];
				} else {
					$cmp = strcmp( $a['title'], $b['title'] );
				}
				return 'desc' === $order ? -$cmp : $cmp;
			}
		);

		$total     = count( $entries );
		$max_pages = max( 1, (int) ceil( $total / $per_page ) );
		$paged     = min( $paged, $max_pages );
		$offset    = ( $paged - 1 ) * $per_page;
		$rows      = array_slice( $entries, $offset, $per_page );

		$sources = array();
		foreach ( $entries as $entry ) {
			foreach ( $entry['sources_array'] as $src ) {
				$sources[ $src ] = true;
			}
		}
		$sources = array_keys( $sources );
		sort( $sources );

		?>
		<form method="get" style="margin:0 0 1rem;">
			<input type="hidden" name="page" value="song-credits" />
			<label for="song-credits-q" class="screen-reader-text"><?php esc_html_e( 'Search cached entries', 'song-credits' ); ?></label>
			<input id="song-credits-q" type="search" name="q" value="<?php echo esc_attr( $q ); ?>" placeholder="<?php esc_attr_e( 'Search artist or title', 'song-credits' ); ?>" />
			<label for="song-credits-source" class="screen-reader-text"><?php esc_html_e( 'Filter by source', 'song-credits' ); ?></label>
			<select id="song-credits-source" name="source">
				<option value=""><?php esc_html_e( 'All Sources', 'song-credits' ); ?></option>
				<?php foreach ( $sources as $src ) : ?>
					<option value="<?php echo esc_attr( $src ); ?>" <?php selected( $source, $src ); ?>><?php echo esc_html( $src ); ?></option>
				<?php endforeach; ?>
			</select>
			<select name="has_performers">
				<option value=""><?php esc_html_e( 'Performers: Any', 'song-credits' ); ?></option>
				<option value="yes" <?php selected( $has_perf, 'yes' ); ?>><?php esc_html_e( 'Performers: Yes', 'song-credits' ); ?></option>
				<option value="no" <?php selected( $has_perf, 'no' ); ?>><?php esc_html_e( 'Performers: No', 'song-credits' ); ?></option>
			</select>
			<select name="has_year">
				<option value=""><?php esc_html_e( 'Year: Any', 'song-credits' ); ?></option>
				<option value="yes" <?php selected( $has_year, 'yes' ); ?>><?php esc_html_e( 'Year: Yes', 'song-credits' ); ?></option>
				<option value="no" <?php selected( $has_year, 'no' ); ?>><?php esc_html_e( 'Year: No', 'song-credits' ); ?></option>
			</select>
			<?php submit_button( __( 'Apply', 'song-credits' ), 'secondary', 'submit', false ); ?>
		</form>

		<?php if ( empty( $lookup_page_url ) ) : ?>
			<p><?php esc_html_e( 'No published page or post with [song_credits] was found. Create one to enable direct cached-search links.', 'song-credits' ); ?></p>
		<?php endif; ?>

		<?php if ( empty( $rows ) ) : ?>
			<p><?php esc_html_e( 'No cached searches found for the current filters.', 'song-credits' ); ?></p>
			<?php return; ?>
		<?php endif; ?>

		<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
			<?php wp_nonce_field( 'song_credits_cache_action' ); ?>
			<input type="hidden" name="action" value="song_credits_cache_action" />
			<input type="hidden" name="mode" value="delete_selected" />
			<?php submit_button( __( 'Delete Selected', 'song-credits' ), 'delete', 'submit', false ); ?>
			<table class="widefat striped">
				<thead>
				<tr>
					<th scope="col"><input type="checkbox" id="song-credits-select-all" /></th>
					<th scope="col"><a href="<?php echo esc_url( $this->sort_url( 'artist', $orderby, $order, $q, $source, $has_perf, $has_year ) ); ?>"><?php esc_html_e( 'Artist', 'song-credits' ); ?></a></th>
					<th scope="col"><a href="<?php echo esc_url( $this->sort_url( 'title', $orderby, $order, $q, $source, $has_perf, $has_year ) ); ?>"><?php esc_html_e( 'Title', 'song-credits' ); ?></a></th>
					<th scope="col"><?php esc_html_e( 'Sources', 'song-credits' ); ?></th>
					<th scope="col"><a href="<?php echo esc_url( $this->sort_url( 'categories', $orderby, $order, $q, $source, $has_perf, $has_year ) ); ?>"><?php esc_html_e( 'Categories', 'song-credits' ); ?></a></th>
					<th scope="col"><a href="<?php echo esc_url( $this->sort_url( 'expires', $orderby, $order, $q, $source, $has_perf, $has_year ) ); ?>"><?php esc_html_e( 'Expires', 'song-credits' ); ?></a></th>
					<th scope="col"><?php esc_html_e( 'Cache Key', 'song-credits' ); ?></th>
				</tr>
				</thead>
				<tbody>
				<?php foreach ( $rows as $entry ) : ?>
					<?php
					$lookup_url = '';
					if ( ! empty( $lookup_page_url ) ) {
						$lookup_url = add_query_arg(
							array(
								'sc_artist' => $entry['artist'],
								'sc_title'  => $entry['title'],
							),
							$lookup_page_url
						);
					}
					?>
					<tr>
						<td><input type="checkbox" name="cache_keys[]" value="<?php echo esc_attr( $entry['cache_key'] ); ?>" /></td>
						<td><?php echo esc_html( $entry['artist'] ); ?></td>
						<td>
							<?php if ( $lookup_url ) : ?>
								<a href="<?php echo esc_url( $lookup_url ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $entry['title'] ); ?></a>
								<div>
									<a href="<?php echo esc_url( $lookup_url ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Re-run now', 'song-credits' ); ?></a>
									|
									<button type="button" class="button-link song-credits-copy-link" data-link="<?php echo esc_attr( $lookup_url ); ?>" aria-label="<?php echo esc_attr( sprintf( __( 'Copy link for %s', 'song-credits' ), $entry['title'] ) ); ?>"><?php esc_html_e( 'Copy deep link', 'song-credits' ); ?></button>
								</div>
							<?php else : ?>
								<?php echo esc_html( $entry['title'] ); ?>
							<?php endif; ?>
							<div>
								<?php
								$delete_url = wp_nonce_url(
									add_query_arg(
										array(
											'action'    => 'song_credits_cache_action',
											'mode'      => 'delete_single',
											'cache_key' => $entry['cache_key'],
										),
										admin_url( 'admin-post.php' )
									),
									'song_credits_cache_action'
								);
								?>
								<a href="<?php echo esc_url( $delete_url ); ?>" class="button-link-delete"><?php esc_html_e( 'Delete cache entry', 'song-credits' ); ?></a>
							</div>
						</td>
						<td><?php echo esc_html( $entry['sources'] ); ?></td>
						<td><?php echo esc_html( (string) $entry['category_count'] ); ?></td>
						<td><?php echo esc_html( $entry['expires_at'] ? $entry['expires_at'] : __( 'Unknown', 'song-credits' ) ); ?></td>
						<td><code><?php echo esc_html( $entry['cache_key'] ); ?></code></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</form>

		<p>
			<?php
			printf(
				/* translators: 1: current page, 2: max pages */
				esc_html__( 'Page %1$d of %2$d', 'song-credits' ),
				(int) $paged,
				(int) $max_pages
			);
			?>
		</p>
		<?php if ( $max_pages > 1 ) : ?>
			<p>
				<?php for ( $i = 1; $i <= $max_pages; $i++ ) : ?>
					<?php
					$url = add_query_arg(
						array(
							'page'           => 'song-credits',
							'q'              => $q,
							'source'         => $source,
							'has_performers' => $has_perf,
							'has_year'       => $has_year,
							'orderby'        => $orderby,
							'order'          => $order,
							'paged'          => $i,
						),
						admin_url( 'options-general.php' )
					);
					?>
					<a href="<?php echo esc_url( $url ); ?>" <?php echo $i === $paged ? 'aria-current="page"' : ''; ?>><?php echo esc_html( (string) $i ); ?></a>
				<?php endfor; ?>
			</p>
		<?php endif; ?>

		<script>
		( function () {
			var selectAll = document.getElementById( 'song-credits-select-all' );
			if ( ! selectAll ) {
				return;
			}
			selectAll.addEventListener( 'change', function () {
				document.querySelectorAll( 'input[name="cache_keys[]"]' ).forEach( function (box) {
					box.checked = selectAll.checked;
				} );
			} );
		}() );
		</script>
		<?php
	}

	private function render_metrics_panel() {
		$metrics = get_option( 'song_credits_metrics', array() );
		if ( ! is_array( $metrics ) ) {
			$metrics = array();
		}

		$total_requests = (int) ( $metrics['total_requests'] ?? 0 );
		$cache_hits     = (int) ( $metrics['cache_hits'] ?? 0 );
		$cache_misses   = (int) ( $metrics['cache_misses'] ?? 0 );
		$successes      = (int) ( $metrics['successful_lookups'] ?? 0 );
		$failures       = (int) ( $metrics['failed_lookups'] ?? 0 );
		$total_latency  = (float) ( $metrics['total_latency_ms'] ?? 0 );

		$hit_rate = $total_requests > 0 ? round( ( $cache_hits / $total_requests ) * 100, 2 ) : 0;
		$avg_ms   = $total_requests > 0 ? round( $total_latency / $total_requests, 2 ) : 0;

		echo '<ul>';
		echo '<li>' . esc_html( sprintf( __( 'Total requests: %d', 'song-credits' ), $total_requests ) ) . '</li>';
		echo '<li>' . esc_html( sprintf( __( 'Cache hits: %d', 'song-credits' ), $cache_hits ) ) . '</li>';
		echo '<li>' . esc_html( sprintf( __( 'Cache misses: %d', 'song-credits' ), $cache_misses ) ) . '</li>';
		echo '<li>' . esc_html( sprintf( __( 'Cache hit rate: %s%%', 'song-credits' ), $hit_rate ) ) . '</li>';
		echo '<li>' . esc_html( sprintf( __( 'Successful lookups: %d', 'song-credits' ), $successes ) ) . '</li>';
		echo '<li>' . esc_html( sprintf( __( 'Failed lookups: %d', 'song-credits' ), $failures ) ) . '</li>';
		echo '<li>' . esc_html( sprintf( __( 'Average lookup latency: %sms', 'song-credits' ), $avg_ms ) ) . '</li>';
		echo '</ul>';

		if ( ! empty( $metrics['source_counts'] ) && is_array( $metrics['source_counts'] ) ) {
			echo '<h3>' . esc_html__( 'Source Usage', 'song-credits' ) . '</h3>';
			echo '<table class="widefat striped"><thead><tr><th scope="col">' . esc_html__( 'Source', 'song-credits' ) . '</th><th scope="col">' . esc_html__( 'Count', 'song-credits' ) . '</th></tr></thead><tbody>';
			foreach ( $metrics['source_counts'] as $source => $count ) {
				echo '<tr><td>' . esc_html( (string) $source ) . '</td><td>' . esc_html( (string) (int) $count ) . '</td></tr>';
			}
			echo '</tbody></table>';
		}

		if ( ! empty( $metrics['api_error_counts'] ) && is_array( $metrics['api_error_counts'] ) ) {
			echo '<h3>' . esc_html__( 'Recent API Error Counters', 'song-credits' ) . '</h3>';
			echo '<table class="widefat striped"><thead><tr><th scope="col">' . esc_html__( 'Error Key', 'song-credits' ) . '</th><th scope="col">' . esc_html__( 'Count', 'song-credits' ) . '</th></tr></thead><tbody>';
			foreach ( $metrics['api_error_counts'] as $error_key => $count ) {
				echo '<tr><td>' . esc_html( (string) $error_key ) . '</td><td>' . esc_html( (string) (int) $count ) . '</td></tr>';
			}
			echo '</tbody></table>';
		}
	}

	/* --- Helpers ----------------------------------------------------- */

	private function build_cached_entries() {
		global $wpdb;

		$value_prefix   = '_transient_song_credits_';
		$timeout_prefix = '_transient_timeout_song_credits_';

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT option_name, option_value
				 FROM {$wpdb->options}
				 WHERE option_name LIKE %s",
				$wpdb->esc_like( $value_prefix ) . '%'
			)
		);

		$timeouts = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT option_name, option_value
				 FROM {$wpdb->options}
				 WHERE option_name LIKE %s",
				$wpdb->esc_like( $timeout_prefix ) . '%'
			)
		);

		$timeout_map = array();
		foreach ( (array) $timeouts as $timeout_row ) {
			$suffix = substr( (string) $timeout_row->option_name, strlen( $timeout_prefix ) );
			if ( preg_match( '/^[a-f0-9]{32}$/', $suffix ) ) {
				$timeout_map[ $suffix ] = (int) $timeout_row->option_value;
			}
		}

		$entries = array();
		foreach ( (array) $rows as $row ) {
			$suffix = substr( (string) $row->option_name, strlen( $value_prefix ) );
			if ( ! preg_match( '/^[a-f0-9]{32}$/', $suffix ) ) {
				continue;
			}

			$payload = maybe_unserialize( $row->option_value );
			if ( ! is_array( $payload ) ) {
				continue;
			}

			$artist = isset( $payload['artist'] ) ? (string) $payload['artist'] : '';
			$title  = isset( $payload['title'] ) ? (string) $payload['title'] : '';
			$year   = isset( $payload['year'] ) ? (string) $payload['year'] : '';
			$sources_array = ! empty( $payload['sources'] ) && is_array( $payload['sources'] ) ? array_map( 'sanitize_text_field', $payload['sources'] ) : array();
			$sources = implode( ', ', $sources_array );
			$categories = ! empty( $payload['categories'] ) && is_array( $payload['categories'] ) ? $payload['categories'] : array();
			$category_count = count( $categories );
			$has_performers = ! empty( $categories['Performers'] );

			$expires_ts = isset( $timeout_map[ $suffix ] ) ? (int) $timeout_map[ $suffix ] : 0;
			$expires_at = $expires_ts > 0 ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $expires_ts ) : '';

			$entries[] = array(
				'cache_key'       => $suffix,
				'artist'          => $artist,
				'title'           => $title,
				'year'            => $year,
				'has_year'        => '' !== $year,
				'sources'         => $sources,
				'sources_array'   => $sources_array,
				'category_count'  => $category_count,
				'has_performers'  => $has_performers,
				'expires_at'      => $expires_at,
				'expires_ts'      => $expires_ts,
			);
		}

		return $entries;
	}

	private function get_cache_action_notice() {
		$mode  = isset( $_GET['song_credits_cache_action'] ) ? sanitize_key( wp_unslash( $_GET['song_credits_cache_action'] ) ) : '';
		$count = isset( $_GET['song_credits_cache_count'] ) ? absint( wp_unslash( $_GET['song_credits_cache_count'] ) ) : 0;
		if ( '' === $mode ) {
			return '';
		}

		if ( 'clear_expired' === $mode ) {
			return sprintf( __( 'Expired cache entries cleared (%d deletions).', 'song-credits' ), $count );
		}
		if ( 'delete_selected' === $mode || 'delete_single' === $mode ) {
			return sprintf( __( 'Selected cache entries deleted (%d deletions).', 'song-credits' ), $count );
		}
		if ( 'clear_all' === $mode ) {
			return sprintf( __( 'All song-credit cache entries cleared (%d deletions).', 'song-credits' ), $count );
		}
		return __( 'Cache updated.', 'song-credits' );
	}

	private function sort_url( $column, $current_orderby, $current_order, $q, $source, $has_perf, $has_year ) {
		$next_order = 'asc';
		if ( $column === $current_orderby ) {
			$next_order = 'asc' === $current_order ? 'desc' : 'asc';
		}

		return add_query_arg(
			array(
				'page'           => 'song-credits',
				'orderby'        => $column,
				'order'          => $next_order,
				'q'              => $q,
				'source'         => $source,
				'has_performers' => $has_perf,
				'has_year'       => $has_year,
				'paged'          => 1,
			),
			admin_url( 'options-general.php' )
		);
	}

	/**
	 * Find a published URL containing the [song_credits] shortcode.
	 */
	private function get_lookup_page_url() {
		$posts = get_posts(
			array(
				'post_type'      => array( 'page', 'post' ),
				'post_status'    => 'publish',
				'posts_per_page' => 200,
				'orderby'        => 'ID',
				'order'          => 'ASC',
			)
		);

		if ( empty( $posts ) ) {
			return '';
		}

		foreach ( $posts as $post ) {
			if ( empty( $post->post_content ) || ! has_shortcode( $post->post_content, 'song_credits' ) ) {
				continue;
			}
			$url = get_permalink( $post );
			if ( is_string( $url ) && '' !== $url ) {
				return $url;
			}
		}
		return '';
	}

	/* --- Plugin row link -------------------------------------------- */

	public function action_links( $links ) {
		array_unshift(
			$links,
			sprintf(
				'<a href="%s">%s</a>',
				esc_url( admin_url( 'options-general.php?page=song-credits' ) ),
				esc_html__( 'Settings', 'song-credits' )
			)
		);
		return $links;
	}
}
