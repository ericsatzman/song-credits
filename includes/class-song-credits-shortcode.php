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
            <form id="song-credits-form" class="song-credits-form"
                  aria-label="<?php esc_attr_e( 'Song Credits Lookup', 'song-credits' ); ?>">

                <div class="song-credits-field">
                    <label for="song-credits-artist">
                        <?php esc_html_e( 'Artist Name', 'song-credits' ); ?>
                    </label>
                    <input type="text" id="song-credits-artist" name="artist"
                           required maxlength="200"
                           placeholder="<?php esc_attr_e( 'e.g., Stevie Wonder', 'song-credits' ); ?>"
                           autocomplete="off" />
                </div>

                <div class="song-credits-field">
                    <label for="song-credits-title">
                        <?php esc_html_e( 'Song Title', 'song-credits' ); ?>
                    </label>
                    <input type="text" id="song-credits-title" name="title"
                           required maxlength="200"
                           placeholder="<?php esc_attr_e( 'e.g., Superstition', 'song-credits' ); ?>"
                           autocomplete="off" />
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

            <div id="song-credits-results" class="song-credits-results" aria-live="polite"></div>
        </div>
        <?php
        return ob_get_clean();
    }

    /* --- AJAX Handler ----------------------------------------------- */

    public function ajax_lookup() {

        // 1. Verify nonce.
        if ( ! check_ajax_referer( 'song_credits_nonce', 'nonce', false ) ) {
            wp_send_json_error(
                array( 'message' => __( 'Security check failed. Please refresh the page.', 'song-credits' ) ),
                403
            );
        }

        // 2. Simple per-IP rate limiting (10 req / min).
        $remote_ip = isset( $_SERVER['REMOTE_ADDR'] ) ? wp_unslash( $_SERVER['REMOTE_ADDR'] ) : 'unknown';
        $ip_hash   = md5( sanitize_text_field( $remote_ip ) );
        $rate_key  = 'song_credits_rate_' . $ip_hash;
        $rate_count = (int) get_transient( $rate_key );

        if ( $rate_count >= 10 ) {
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

        if ( mb_strlen( $artist ) > 200 || mb_strlen( $title ) > 200 ) {
            wp_send_json_error(
                array( 'message' => __( 'Input exceeds the 200-character limit.', 'song-credits' ) ),
                400
            );
        }

        // 4. Check transient cache.
        $settings    = get_option( 'song_credits_settings', array() );
        $cache_hours = ! empty( $settings['cache_duration'] ) ? (int) $settings['cache_duration'] : 24;
        $cache_hours = min( 168, max( 1, $cache_hours ) );
        $cache_key   = 'song_credits_' . md5( strtolower( $artist ) . '|' . strtolower( $title ) );
        $cached      = get_transient( $cache_key );

        if ( false !== $cached ) {
            wp_send_json_success( $cached );
        }

        // 5. Fetch fresh data.
        $api     = new Song_Credits_API();
        $credits = $api->fetch_credits( $artist, $title );

        if ( empty( $credits['categories'] ) ) {
            wp_send_json_error(
                array( 'message' => __( 'No credits found. Check spelling or try the full official title.', 'song-credits' ) ),
                200
            );
        }

        // 6. Cache and return.
        set_transient( $cache_key, $credits, $cache_hours * HOUR_IN_SECONDS );
        wp_send_json_success( $credits );
    }
}
