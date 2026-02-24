<?php
/**
 * Registers the [song_credits] shortcode and the AJAX endpoint.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Song_Credits_Shortcode {

    /** @var self|null */
    private static $instance = null;

    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_shortcode( 'song_credits', array( $this, 'render' ) );
        add_action( 'wp_enqueue_scripts', array( $this, 'register_assets' ) );
        add_action( 'wp_ajax_song_credits_lookup',        array( $this, 'ajax_lookup' ) );
        add_action( 'wp_ajax_nopriv_song_credits_lookup', array( $this, 'ajax_lookup' ) );
        add_action( 'wp_ajax_song_credits_suggestions',        array( $this, 'ajax_suggestions' ) );
        add_action( 'wp_ajax_nopriv_song_credits_suggestions', array( $this, 'ajax_suggestions' ) );
        add_action( 'song_credits_api_error', array( $this, 'track_api_error' ), 10, 2 );
    }

    /* --- Assets ----------------------------------------------------- */

    public function register_assets() {
        wp_register_style(
            'song-credits',
            SONG_CREDITS_PLUGIN_URL . 'assets/css/song-credits.css',
            array(),
            SONG_CREDITS_VERSION
        );

        wp_register_script(
            'song-credits',
            SONG_CREDITS_PLUGIN_URL . 'assets/js/song-credits.js',
            array(),
            SONG_CREDITS_VERSION,
            true
        );

        wp_localize_script( 'song-credits', 'songCreditsData', array(
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => wp_create_nonce( 'song_credits_nonce' ),
            'i18n'    => array(
                'searching' => __( 'Searching for credits…', 'song-credits' ),
                'noResults' => __( 'No credits found. Try adjusting the artist name or song title.', 'song-credits' ),
                'error'     => __( 'An error occurred. Please try again.', 'song-credits' ),
                'sources'   => __( 'Sources:', 'song-credits' ),
                'loaded'    => __( 'Credits loaded.', 'song-credits' ),
                'recent'    => __( 'Recent Searches', 'song-credits' ),
                'noRecent'  => __( 'No recent searches yet.', 'song-credits' ),
                'usingRecent' => __( 'Running recent search.', 'song-credits' ),
                'resetRecent' => __( 'Reset Recent Searches', 'song-credits' ),
                'recentCleared' => __( 'Recent searches cleared.', 'song-credits' ),
            ),
        ) );
    }

    /* --- Shortcode -------------------------------------------------- */

    public function render( $atts ) {
        wp_enqueue_style( 'song-credits' );
        wp_enqueue_script( 'song-credits' );

        ob_start();
        ?>
        <div id="song-credits-app" class="song-credits-wrap">
            <a href="#song-credits-results" class="song-credits-skip-link"><?php esc_html_e( 'Skip to results', 'song-credits' ); ?></a>
            <form id="song-credits-form" class="song-credits-form"
                  aria-label="<?php esc_attr_e( 'Song Credits Lookup', 'song-credits' ); ?>">

                <div class="song-credits-field">
                    <label for="song-credits-artist">
                        <?php esc_html_e( 'Artist Name', 'song-credits' ); ?>
                    </label>
                    <input type="text" id="song-credits-artist" name="artist"
                           required maxlength="200"
                           placeholder="<?php esc_attr_e( 'e.g., Stevie Wonder', 'song-credits' ); ?>"
                           autocomplete="off" list="song-credits-artist-options" />
                </div>

                <div class="song-credits-field">
                    <label for="song-credits-title">
                        <?php esc_html_e( 'Song Title', 'song-credits' ); ?>
                    </label>
                    <input type="text" id="song-credits-title" name="title"
                           required maxlength="200"
                           placeholder="<?php esc_attr_e( 'e.g., Superstition', 'song-credits' ); ?>"
                           autocomplete="off" list="song-credits-title-options" />
                </div>

                <div class="song-credits-actions">
                    <button type="submit" id="song-credits-submit" class="song-credits-button">
                        <?php esc_html_e( 'Look Up Credits', 'song-credits' ); ?>
                    </button>
                    <button type="button" id="song-credits-reset" class="song-credits-button song-credits-button-secondary">
                        <?php esc_html_e( 'Reset', 'song-credits' ); ?>
                    </button>
                </div>
            </form>
            <datalist id="song-credits-artist-options"></datalist>
            <datalist id="song-credits-title-options"></datalist>
            <div id="song-credits-status" class="screen-reader-text" role="status" aria-live="polite" aria-atomic="true"></div>
            <section id="song-credits-recent" class="song-credits-recent" aria-label="<?php esc_attr_e( 'Recent Searches', 'song-credits' ); ?>">
                <div class="song-credits-recent-head">
                    <h4 class="song-credits-recent-title"><?php esc_html_e( 'Recent Searches', 'song-credits' ); ?></h4>
                    <button type="button" id="song-credits-recent-reset" class="song-credits-recent-reset">
                        <?php esc_html_e( 'Reset Recent Searches', 'song-credits' ); ?>
                    </button>
                </div>
            </section>

            <div id="song-credits-results" class="song-credits-results" tabindex="-1" aria-live="polite"></div>
        </div>
        <?php
        return ob_get_clean();
    }

    /* --- AJAX Handler ----------------------------------------------- */

    public function ajax_lookup() {
        $started_at = microtime( true );
        if ( ! wp_doing_ajax() ) {
            wp_send_json_error(
                array( 'message' => __( 'Invalid request context.', 'song-credits' ) ),
                400
            );
        }

        if ( 'POST' !== strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ?? '' ) ) ) ) {
            wp_send_json_error(
                array( 'message' => __( 'Method not allowed.', 'song-credits' ) ),
                405
            );
        }

        // 1. Verify nonce.
        if ( ! check_ajax_referer( 'song_credits_nonce', 'nonce', false ) ) {
            wp_send_json_error(
                array( 'message' => __( 'Security check failed. Please refresh the page.', 'song-credits' ) ),
                403
            );
        }

        // 2. Simple per-IP rate limiting (10 req / min).
        if ( is_user_logged_in() ) {
            $rate_key = 'song_credits_rate_user_' . get_current_user_id();
        } else {
            $remote_ip = isset( $_SERVER['REMOTE_ADDR'] ) ? wp_unslash( $_SERVER['REMOTE_ADDR'] ) : 'unknown';
            $ip_hash   = md5( sanitize_text_field( $remote_ip ) );
            $rate_key  = 'song_credits_rate_ip_' . $ip_hash;
        }
        $rate_count = (int) get_transient( $rate_key );

        $settings = get_option( 'song_credits_settings', array() );
        $limit    = ! empty( $settings['rate_limit_per_minute'] ) ? (int) $settings['rate_limit_per_minute'] : 10;
        $limit    = min( 120, max( 1, $limit ) );

        if ( $rate_count >= $limit ) {
            wp_send_json_error(
                array( 'message' => __( 'Too many requests. Please wait a minute.', 'song-credits' ) ),
                429
            );
        }
        set_transient( $rate_key, $rate_count + 1, MINUTE_IN_SECONDS );

        // 3. Sanitize & validate inputs.
        $artist = isset( $_POST['artist'] ) ? sanitize_text_field( wp_unslash( $_POST['artist'] ) ) : '';
        $title  = isset( $_POST['title'] )  ? sanitize_text_field( wp_unslash( $_POST['title'] ) )  : '';

        if ( '' === $artist || '' === $title ) {
            wp_send_json_error(
                array( 'message' => __( 'Please provide both an artist name and a song title.', 'song-credits' ) ),
                400
            );
        }

        $artist_len = function_exists( 'mb_strlen' ) ? mb_strlen( $artist ) : strlen( $artist );
        $title_len  = function_exists( 'mb_strlen' ) ? mb_strlen( $title ) : strlen( $title );
        if ( $artist_len > 200 || $title_len > 200 ) {
            wp_send_json_error(
                array( 'message' => __( 'Input exceeds the 200-character limit.', 'song-credits' ) ),
                400
            );
        }

        // 4. Check transient cache.
        $cache_hours = ! empty( $settings['cache_duration'] ) ? (int) $settings['cache_duration'] : 24;
        $cache_hours = min( 168, max( 1, $cache_hours ) );
        $cache_key   = 'song_credits_' . md5( strtolower( $artist ) . '|' . strtolower( $title ) );
        $cached      = get_transient( $cache_key );

        if ( false !== $cached ) {
            $this->update_metrics(
                array(
                    'total_requests'   => 1,
                    'cache_hits'       => 1,
                    'successful_lookups' => 1,
                    'latency_ms'       => $this->elapsed_ms( $started_at ),
                    'sources'          => is_array( $cached['sources'] ?? null ) ? $cached['sources'] : array(),
                )
            );
            wp_send_json_success( $cached );
        }

        // 5. Fetch fresh data.
        $pre = apply_filters( 'song_credits_pre_lookup_result', null, $artist, $title );
        if ( is_array( $pre ) ) {
            $credits = $pre;
        } else {
            $api     = new Song_Credits_API();
            $credits = $api->fetch_credits( $artist, $title );
        }

        if ( empty( $credits['categories'] ) ) {
            $this->update_metrics(
                array(
                    'total_requests' => 1,
                    'cache_misses'   => 1,
                    'failed_lookups' => 1,
                    'latency_ms'     => $this->elapsed_ms( $started_at ),
                )
            );
            wp_send_json_error(
                array( 'message' => __( 'No credits found. Check spelling or try the full official title.', 'song-credits' ) ),
                200
            );
        }

        // 6. Cache and return.
        set_transient( $cache_key, $credits, $cache_hours * HOUR_IN_SECONDS );
        $this->update_metrics(
            array(
                'total_requests'      => 1,
                'cache_misses'        => 1,
                'successful_lookups'  => 1,
                'latency_ms'          => $this->elapsed_ms( $started_at ),
                'sources'             => is_array( $credits['sources'] ?? null ) ? $credits['sources'] : array(),
            )
        );
        wp_send_json_success( $credits );
    }

    /**
     * Return cached lookup suggestions (artist/title pairs) for autocomplete.
     */
    public function ajax_suggestions() {
        if ( ! wp_doing_ajax() ) {
            wp_send_json_error( array( 'message' => __( 'Invalid request context.', 'song-credits' ) ), 400 );
        }

        if ( ! check_ajax_referer( 'song_credits_nonce', 'nonce', false ) ) {
            wp_send_json_error( array( 'message' => __( 'Security check failed.', 'song-credits' ) ), 403 );
        }

        global $wpdb;
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT option_value
                 FROM {$wpdb->options}
                 WHERE option_name LIKE %s
                 LIMIT 200",
                $wpdb->esc_like( '_transient_song_credits_' ) . '%'
            )
        );

        $seen = array();
        $out  = array();
        foreach ( (array) $rows as $row ) {
            $payload = maybe_unserialize( $row->option_value );
            if ( ! is_array( $payload ) ) {
                continue;
            }
            $artist = sanitize_text_field( (string) ( $payload['artist'] ?? '' ) );
            $title  = sanitize_text_field( (string) ( $payload['title'] ?? '' ) );
            if ( '' === $artist || '' === $title ) {
                continue;
            }

            $key = strtolower( $artist . '|' . $title );
            if ( isset( $seen[ $key ] ) ) {
                continue;
            }
            $seen[ $key ] = true;
            $out[] = array(
                'artist' => $artist,
                'title'  => $title,
            );
        }

        usort(
            $out,
            static function( $a, $b ) {
                return strcmp( $a['title'], $b['title'] );
            }
        );

        wp_send_json_success( array_slice( $out, 0, 100 ) );
    }

    /**
     * Track API errors for admin metrics.
     */
    public function track_api_error( $source, $code ) {
        $metrics = get_option( 'song_credits_metrics', array() );
        if ( ! is_array( $metrics ) ) {
            $metrics = array();
        }
        if ( empty( $metrics['api_error_counts'] ) || ! is_array( $metrics['api_error_counts'] ) ) {
            $metrics['api_error_counts'] = array();
        }

        $key = sanitize_key( (string) $source ) . ':' . sanitize_key( (string) $code );
        if ( '' !== $key ) {
            $metrics['api_error_counts'][ $key ] = isset( $metrics['api_error_counts'][ $key ] )
                ? ( (int) $metrics['api_error_counts'][ $key ] + 1 )
                : 1;
        }
        update_option( 'song_credits_metrics', $metrics, false );
    }

    /**
     * Update persistent plugin metrics.
     */
    private function update_metrics( $delta ) {
        $metrics = get_option( 'song_credits_metrics', array() );
        if ( ! is_array( $metrics ) ) {
            $metrics = array();
        }

        $counters = array( 'total_requests', 'cache_hits', 'cache_misses', 'successful_lookups', 'failed_lookups' );
        foreach ( $counters as $counter ) {
            $metrics[ $counter ] = isset( $metrics[ $counter ] ) ? (int) $metrics[ $counter ] : 0;
            $metrics[ $counter ] += (int) ( $delta[ $counter ] ?? 0 );
        }

        $metrics['total_latency_ms'] = isset( $metrics['total_latency_ms'] ) ? (float) $metrics['total_latency_ms'] : 0.0;
        $metrics['total_latency_ms'] += (float) ( $delta['latency_ms'] ?? 0 );

        if ( empty( $metrics['source_counts'] ) || ! is_array( $metrics['source_counts'] ) ) {
            $metrics['source_counts'] = array();
        }
        if ( ! empty( $delta['sources'] ) && is_array( $delta['sources'] ) ) {
            foreach ( $delta['sources'] as $source ) {
                $label = sanitize_text_field( (string) $source );
                if ( '' === $label ) {
                    continue;
                }
                $metrics['source_counts'][ $label ] = isset( $metrics['source_counts'][ $label ] )
                    ? ( (int) $metrics['source_counts'][ $label ] + 1 )
                    : 1;
            }
        }

        update_option( 'song_credits_metrics', $metrics, false );
    }

    /**
     * Elapsed milliseconds helper.
     */
    private function elapsed_ms( $started_at ) {
        $elapsed = microtime( true ) - (float) $started_at;
        return round( max( 0, $elapsed ) * 1000, 2 );
    }
}
