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

        add_settings_section(
            'sc_api',
            __( 'API Configuration', 'song-credits' ),
            array( $this, 'section_text' ),
            'song-credits'
        );

        add_settings_field( 'contact_email',  __( 'Contact Email', 'song-credits' ),                   array( $this, 'field_email' ),  'song-credits', 'sc_api' );
        add_settings_field( 'discogs_token',  __( 'Discogs Personal Access Token', 'song-credits' ),  array( $this, 'field_token' ),  'song-credits', 'sc_api' );
        add_settings_field( 'cache_duration', __( 'Cache Duration (hours)', 'song-credits' ),         array( $this, 'field_cache' ),  'song-credits', 'sc_api' );
    }

    /**
     * Sanitize callback for the settings group.
     */
    public function sanitize( $input ) {
        return array(
            'contact_email'  => sanitize_email( $input['contact_email'] ?? '' ),
            'discogs_token'  => sanitize_text_field( $input['discogs_token'] ?? '' ),
            'cache_duration' => min( 168, max( 1, absint( $input['cache_duration'] ?? 24 ) ) ),
        );
    }

    /* --- Renderers -------------------------------------------------- */

    public function section_text() {
        echo '<p>' . esc_html__( 'MusicBrainz is used by default (no key needed). Add a Discogs token for richer results.', 'song-credits' ) . '</p>';
    }

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
        $s = get_option( 'song_credits_settings', array() );
        printf(
            '<input type="password" name="song_credits_settings[discogs_token]" value="%s" class="regular-text" autocomplete="off" />
             <p class="description">%s <a href="https://www.discogs.com/settings/developers" target="_blank" rel="noopener noreferrer">Discogs Developer Settings</a>.</p>',
            esc_attr( $s['discogs_token'] ?? '' ),
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

    public function render_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        ?>
        <div class="wrap">
            <h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
            <form action="options.php" method="post">
                <?php
                settings_fields( 'song_credits_group' );
                do_settings_sections( 'song-credits' );
                submit_button();
                ?>
            </form>
            <hr />
            <h2><?php esc_html_e( 'Usage', 'song-credits' ); ?></h2>
            <p><?php esc_html_e( 'Add the lookup form to any page or post with:', 'song-credits' ); ?></p>
            <p><code>[song_credits]</code></p>
            <hr />
            <h2><?php esc_html_e( 'Cached Searches', 'song-credits' ); ?></h2>
            <?php $this->render_cached_searches_table(); ?>
        </div>
        <?php
    }

    /**
     * Render a table of cached search payloads.
     */
    private function render_cached_searches_table() {
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

        if ( empty( $rows ) ) {
            echo '<p>' . esc_html__( 'No cached searches found.', 'song-credits' ) . '</p>';
            return;
        }

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
        foreach ( $rows as $row ) {
            $suffix = substr( (string) $row->option_name, strlen( $value_prefix ) );
            // Only include actual search cache keys (song_credits_<md5>).
            if ( ! preg_match( '/^[a-f0-9]{32}$/', $suffix ) ) {
                continue;
            }

            $payload = maybe_unserialize( $row->option_value );
            if ( ! is_array( $payload ) ) {
                continue;
            }

            $artist = isset( $payload['artist'] ) ? (string) $payload['artist'] : '';
            $title  = isset( $payload['title'] ) ? (string) $payload['title'] : '';
            $sources = ! empty( $payload['sources'] ) && is_array( $payload['sources'] )
                ? implode( ', ', array_map( 'sanitize_text_field', $payload['sources'] ) )
                : '';
            $category_count = ! empty( $payload['categories'] ) && is_array( $payload['categories'] )
                ? count( $payload['categories'] )
                : 0;

            $expires_at = '';
            if ( isset( $timeout_map[ $suffix ] ) && $timeout_map[ $suffix ] > 0 ) {
                $expires_at = wp_date(
                    get_option( 'date_format' ) . ' ' . get_option( 'time_format' ),
                    $timeout_map[ $suffix ]
                );
            }

            $entries[] = array(
                'cache_key'       => $suffix,
                'artist'          => $artist,
                'title'           => $title,
                'sources'         => $sources,
                'category_count'  => $category_count,
                'expires_at'      => $expires_at,
            );
        }

        if ( empty( $entries ) ) {
            echo '<p>' . esc_html__( 'No cached searches found.', 'song-credits' ) . '</p>';
            return;
        }

        usort(
            $entries,
            static function( $a, $b ) {
                return strcmp( (string) $a['title'], (string) $b['title'] );
            }
        );
        $lookup_page_url = $this->get_lookup_page_url();
        ?>
        <?php if ( empty( $lookup_page_url ) ) : ?>
            <p>
                <?php esc_html_e( 'No published page or post with [song_credits] was found. Create one to enable direct cached-search links.', 'song-credits' ); ?>
            </p>
        <?php endif; ?>
        <table class="widefat striped">
            <thead>
                <tr>
                    <th><?php esc_html_e( 'Artist', 'song-credits' ); ?></th>
                    <th><?php esc_html_e( 'Title', 'song-credits' ); ?></th>
                    <th><?php esc_html_e( 'Sources', 'song-credits' ); ?></th>
                    <th><?php esc_html_e( 'Categories', 'song-credits' ); ?></th>
                    <th><?php esc_html_e( 'Expires', 'song-credits' ); ?></th>
                    <th><?php esc_html_e( 'Cache Key', 'song-credits' ); ?></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ( $entries as $entry ) : ?>
                <tr>
                    <td><?php echo esc_html( $entry['artist'] ); ?></td>
                    <td>
                        <?php if ( ! empty( $lookup_page_url ) ) : ?>
                            <?php
                            $lookup_url = add_query_arg(
                                array(
                                    'sc_artist' => $entry['artist'],
                                    'sc_title'  => $entry['title'],
                                ),
                                $lookup_page_url
                            );
                            ?>
                            <a href="<?php echo esc_url( $lookup_url ); ?>" target="_blank" rel="noopener noreferrer">
                                <?php echo esc_html( $entry['title'] ); ?>
                            </a>
                        <?php else : ?>
                            <?php echo esc_html( $entry['title'] ); ?>
                        <?php endif; ?>
                    </td>
                    <td><?php echo esc_html( $entry['sources'] ); ?></td>
                    <td><?php echo esc_html( (string) $entry['category_count'] ); ?></td>
                    <td><?php echo esc_html( $entry['expires_at'] ? $entry['expires_at'] : __( 'Unknown', 'song-credits' ) ); ?></td>
                    <td><code><?php echo esc_html( $entry['cache_key'] ); ?></code></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php
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
        array_unshift( $links, sprintf(
            '<a href="%s">%s</a>',
            esc_url( admin_url( 'options-general.php?page=song-credits' ) ),
            esc_html__( 'Settings', 'song-credits' )
        ) );
        return $links;
    }
}
