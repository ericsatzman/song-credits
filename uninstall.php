<?php
/**
 * Fired when the plugin is deleted via the WordPress admin.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

// Remove options.
delete_option( 'song_credits_settings' );

// Remove all transients created by the plugin.
global $wpdb;
$wpdb->query(
    "DELETE FROM {$wpdb->options}
     WHERE option_name LIKE '_transient_song_credits_%'
        OR option_name LIKE '_transient_timeout_song_credits_%'"
);
