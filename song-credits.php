<?php
/**
 * Plugin Name: Song Credits Lookup
 * Plugin URI:  https://example.com/song-credits
 * Description: Retrieve and display detailed song credits — performers, producers, engineers, and songwriters — powered by MusicBrainz and Discogs.
 * Version:     1.0.0
 * Author:      Your Name
 * Author URI:  https://example.com
 * License:     GPL-2.0+
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: song-credits
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP:      7.4
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Define plugin constants.
define( 'SONG_CREDITS_VERSION', '1.0.0' );
define( 'SONG_CREDITS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'SONG_CREDITS_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'SONG_CREDITS_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

// Include required files.
require_once SONG_CREDITS_PLUGIN_DIR . 'includes/class-song-credits-api.php';
require_once SONG_CREDITS_PLUGIN_DIR . 'includes/class-song-credits-settings.php';
require_once SONG_CREDITS_PLUGIN_DIR . 'includes/class-song-credits-shortcode.php';

/**
 * Initialize the plugin.
 */
function song_credits_init() {
    Song_Credits_Settings::get_instance();
    Song_Credits_Shortcode::get_instance();
}
add_action( 'plugins_loaded', 'song_credits_init' );

/**
 * Activation hook — set default options.
 */
function song_credits_activate() {
    if ( false === get_option( 'song_credits_settings' ) ) {
        add_option( 'song_credits_settings', array(
            'discogs_token'  => '',
            'contact_email'  => get_option( 'admin_email' ),
            'cache_duration' => 24,
            'rate_limit_per_minute' => 10,
            'debug_logging'  => 0,
        ) );
    }
}
register_activation_hook( __FILE__, 'song_credits_activate' );

/**
 * Deactivation hook — flush cached lookups.
 */
function song_credits_deactivate() {
    global $wpdb;
    $wpdb->query(
        "DELETE FROM {$wpdb->options}
         WHERE option_name LIKE '_transient_song_credits_%'
            OR option_name LIKE '_transient_timeout_song_credits_%'"
    );
}
register_deactivation_hook( __FILE__, 'song_credits_deactivate' );
