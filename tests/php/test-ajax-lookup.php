<?php
/**
 * Integration tests for AJAX lookup flow.
 */

class Test_Song_Credits_Ajax_Lookup extends WP_Ajax_UnitTestCase {

    public function set_up() {
        parent::set_up();
        update_option(
            'song_credits_settings',
            array(
                'discogs_token'  => '',
                'contact_email'  => 'admin@example.com',
                'cache_duration' => 24,
                'debug_logging'  => 0,
            )
        );
    }

    public function tear_down() {
        remove_all_filters( 'song_credits_pre_lookup_result' );
        global $wpdb;
        $wpdb->query(
            "DELETE FROM {$wpdb->options}
             WHERE option_name LIKE '_transient_song_credits_%'
                OR option_name LIKE '_transient_timeout_song_credits_%'"
        );
        parent::tear_down();
    }

    public function test_cache_miss_fetches_and_stores_transient() {
        $fixture = array(
            'artist'     => 'The Who',
            'title'      => 'Who Are You',
            'year'       => '1978',
            'categories' => array(
                'Performers' => array(
                    array(
                        'name' => 'Pete Townshend',
                        'role' => 'Guitar, Vocals',
                    ),
                ),
            ),
            'sources'    => array( 'MusicBrainz' ),
        );

        add_filter(
            'song_credits_pre_lookup_result',
            static function( $pre, $artist, $title ) use ( $fixture ) {
                if ( 'The Who' === $artist && 'Who Are You' === $title ) {
                    return $fixture;
                }
                return $pre;
            },
            10,
            3
        );

        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = array(
            'nonce'  => wp_create_nonce( 'song_credits_nonce' ),
            'artist' => 'The Who',
            'title'  => 'Who Are You',
        );

        try {
            $this->_handleAjax( 'song_credits_lookup' );
        } catch ( WPAjaxDieContinueException $e ) {
            // Expected for wp_send_json_*().
        }

        $response = json_decode( $this->_last_response, true );
        $this->assertTrue( $response['success'] );
        $this->assertSame( 'Who Are You', $response['data']['title'] );

        $cache_key = 'song_credits_' . md5( strtolower( 'The Who' ) . '|' . strtolower( 'Who Are You' ) );
        $cached = get_transient( $cache_key );
        $this->assertIsArray( $cached );
        $this->assertSame( 'The Who', $cached['artist'] );
    }

    public function test_cache_hit_returns_without_fetcher_call() {
        $payload = array(
            'artist'     => 'The Who',
            'title'      => 'Who Are You',
            'year'       => '1978',
            'categories' => array(
                'Performers' => array(
                    array(
                        'name' => 'Roger Daltrey',
                        'role' => 'Lead Vocals',
                    ),
                ),
            ),
            'sources'    => array( 'MusicBrainz' ),
        );

        $cache_key = 'song_credits_' . md5( strtolower( 'The Who' ) . '|' . strtolower( 'Who Are You' ) );
        set_transient( $cache_key, $payload, HOUR_IN_SECONDS );

        add_filter(
            'song_credits_pre_lookup_result',
            static function() {
                return array(
                    'artist'     => 'Wrong',
                    'title'      => 'Wrong',
                    'year'       => '',
                    'categories' => array(),
                    'sources'    => array(),
                );
            }
        );

        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = array(
            'nonce'  => wp_create_nonce( 'song_credits_nonce' ),
            'artist' => 'The Who',
            'title'  => 'Who Are You',
        );

        try {
            $this->_handleAjax( 'song_credits_lookup' );
        } catch ( WPAjaxDieContinueException $e ) {
            // Expected for wp_send_json_*().
        }

        $response = json_decode( $this->_last_response, true );
        $this->assertTrue( $response['success'] );
        $this->assertSame( 'The Who', $response['data']['artist'] );
        $this->assertSame( 'Lead Vocals', $response['data']['categories']['Performers'][0]['role'] );
    }
}
