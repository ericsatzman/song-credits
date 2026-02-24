<?php
/**
 * Handles all external API communication (MusicBrainz + Discogs).
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Song_Credits_API {

    /** @var string MusicBrainz API base URL. */
    private $mb_base = 'https://musicbrainz.org/ws/2/';

    /** @var string Discogs API base URL. */
    private $dc_base = 'https://api.discogs.com/';

    /** @var string Wikidata API base URL. */
    private $wd_api = 'https://www.wikidata.org/w/api.php';

    /** @var array<string, string[]> Allowed hosts per data source. */
    private $allowed_hosts = array(
        'musicbrainz' => array( 'musicbrainz.org' ),
        'discogs'     => array( 'api.discogs.com' ),
        'wikidata'    => array( 'www.wikidata.org', 'wikidata.org' ),
    );

    /** @var string User-Agent sent with every request. */
    private $user_agent;

    /** @var string Discogs personal access token (optional). */
    private $discogs_token;

    /** @var bool Whether debug logging is enabled. */
    private $debug_logging = false;

    /**
     * Constructor — reads settings once.
     */
    public function __construct() {
        $settings            = get_option( 'song_credits_settings', array() );
        $email               = ! empty( $settings['contact_email'] ) ? $settings['contact_email'] : 'admin@example.com';
        $this->user_agent    = 'SongCreditsWP/' . SONG_CREDITS_VERSION . ' ( ' . $email . ' )';
        $this->discogs_token = ! empty( $settings['discogs_token'] ) ? $settings['discogs_token'] : '';
        $this->debug_logging = ! empty( $settings['debug_logging'] );
    }

    /* ------------------------------------------------------------------
     *  PUBLIC: Main entry point
     * ----------------------------------------------------------------*/

    /**
     * Fetch credits from all configured sources.
     *
     * @param  string $artist Artist name.
     * @param  string $title  Song title.
     * @return array  Structured credits array.
     */
    public function fetch_credits( $artist, $title ) {
        $credits = array(
            'artist'     => $artist,
            'title'      => $title,
            'year'       => '',
            'categories' => array(),
            'sources'    => array(),
        );

        // --- MusicBrainz (always available, no key required) ---
        $mb = $this->fetch_musicbrainz( $artist, $title );
        if ( ! empty( $mb['categories'] ) ) {
            $credits['categories'] = $mb['categories'];
            $credits['sources'][]  = 'MusicBrainz';
            if ( ! empty( $mb['artist'] ) ) {
                $credits['artist'] = $mb['artist'];
            }
            if ( ! empty( $mb['title'] ) ) {
                $credits['title'] = $mb['title'];
            }
            if ( ! empty( $mb['year'] ) ) {
                $credits['year'] = $mb['year'];
            }
        }

        // --- Discogs (only when a token is configured) ---
        if ( ! empty( $this->discogs_token ) ) {
            $dc = $this->fetch_discogs( $artist, $title );
            if ( ! empty( $dc['categories'] ) ) {
                $credits = $this->merge_credits( $credits, $dc );
                $credits['sources'][] = 'Discogs';
            }
            if ( empty( $credits['year'] ) && ! empty( $dc['year'] ) ) {
                $credits['year'] = $dc['year'];
            }
        }

        // --- Wikidata fallback (only when performer credits are still missing) ---
        if ( ! $this->has_performers( $credits ) ) {
            $wd_performers = $this->fetch_wikidata_performers( $artist, $title );
            foreach ( $wd_performers as $entry ) {
                $this->append_unique_credit(
                    $credits['categories'],
                    'Performers',
                    $entry['name'] ?? '',
                    $entry['role'] ?? __( 'Performer', 'song-credits' )
                );
            }
            if ( $this->has_performers( $credits ) ) {
                $credits['sources'][] = 'Wikidata';
            }
        }

        return $this->sanitize_credits_payload( $credits );
    }

    /**
     * Basic connectivity checks for upstream providers.
     *
     * @return array<string,array<string,mixed>>
     */
    public function test_connections() {
        $results = array(
            'musicbrainz' => array( 'ok' => false, 'message' => __( 'Unknown', 'song-credits' ) ),
            'discogs'     => array( 'ok' => false, 'message' => __( 'Unknown', 'song-credits' ) ),
            'wikidata'    => array( 'ok' => false, 'message' => __( 'Unknown', 'song-credits' ) ),
        );

        $mb = $this->request(
            add_query_arg(
                array(
                    'query' => 'recording:"Superstition" AND artist:"Stevie Wonder"',
                    'fmt'   => 'json',
                    'limit' => 1,
                ),
                $this->mb_base . 'recording/'
            ),
            'musicbrainz'
        );
        if ( is_wp_error( $mb ) ) {
            $results['musicbrainz']['message'] = $mb->get_error_message();
        } else {
            $results['musicbrainz']['ok'] = true;
            $results['musicbrainz']['message'] = __( 'Reachable', 'song-credits' );
        }

        if ( empty( $this->discogs_token ) ) {
            $results['discogs']['ok'] = false;
            $results['discogs']['message'] = __( 'Skipped: no token configured', 'song-credits' );
        } else {
            $dc = $this->request( $this->dc_base . 'oauth/identity', 'discogs' );
            if ( is_wp_error( $dc ) ) {
                $results['discogs']['message'] = $dc->get_error_message();
            } else {
                $results['discogs']['ok'] = true;
                $results['discogs']['message'] = __( 'Reachable and authenticated', 'song-credits' );
            }
        }

        $wd = $this->request(
            add_query_arg(
                array(
                    'action'   => 'wbsearchentities',
                    'search'   => 'Superstition',
                    'language' => 'en',
                    'type'     => 'item',
                    'limit'    => 1,
                    'format'   => 'json',
                ),
                $this->wd_api
            ),
            'wikidata'
        );
        if ( is_wp_error( $wd ) ) {
            $results['wikidata']['message'] = $wd->get_error_message();
        } else {
            $results['wikidata']['ok'] = true;
            $results['wikidata']['message'] = __( 'Reachable', 'song-credits' );
        }

        return $results;
    }

    /* ------------------------------------------------------------------
     *  MUSICBRAINZ
     * ----------------------------------------------------------------*/

    /**
     * Query MusicBrainz for recording-level and work-level credits.
     */
    private function fetch_musicbrainz( $artist, $title ) {
        $result = array(
            'artist'     => '',
            'title'      => '',
            'year'       => '',
            'categories' => array(),
        );

        // 1. Search for the recording.
        $query = sprintf(
            'recording:"%s" AND artist:"%s"',
            $this->mb_escape_query( $title ),
            $this->mb_escape_query( $artist )
        );

        $search = $this->request(
            add_query_arg( array(
                'query' => $query,
                'fmt'   => 'json',
                'limit' => 5,
            ), $this->mb_base . 'recording/' ),
            'musicbrainz'
        );

        if ( is_wp_error( $search ) || empty( $search['recordings'] ) ) {
            return $result;
        }

        $recording = $this->pick_best_mb_recording( $search['recordings'], $artist, $title );
        if ( empty( $recording['id'] ) ) {
            return $result;
        }
        $recording_id = $recording['id'];
        $result['title'] = $recording['title'];
        if ( ! empty( $recording['first-release-date'] ) ) {
            $result['year'] = $this->extract_year( $recording['first-release-date'] );
        }

        if ( ! empty( $recording['artist-credit'] ) ) {
            $result['artist'] = $this->format_artist_credit( $recording['artist-credit'] );
            foreach ( $recording['artist-credit'] as $ac ) {
                $name = $ac['name'] ?? ( $ac['artist']['name'] ?? '' );
                $this->append_unique_credit( $result['categories'], 'Performers', $name, __( 'Primary artist', 'song-credits' ) );
            }
        }

        // 2. Get recording relationships (performers, engineers, producers).
        sleep( 1 ); // MusicBrainz rate-limit: 1 req/s.

        $detail = $this->request(
            add_query_arg( array(
                'inc' => 'artist-credits+artist-rels+work-rels+instrument-rels',
                'fmt' => 'json',
            ), $this->mb_base . 'recording/' . $recording_id ),
            'musicbrainz'
        );

        if ( is_wp_error( $detail ) ) {
            return $result;
        }

        if ( ! empty( $detail['relations'] ) ) {
            foreach ( $detail['relations'] as $rel ) {
                // MusicBrainz returns instrument credits with target-type "artist"
                // and a type like "guitar", "piano", "drums", etc., OR
                // with type = "instrument" and the actual instrument in attributes.
                if ( empty( $rel['artist'] ) ) {
                    continue;
                }
                $cat  = $this->mb_categorize( $rel['type'], $rel );
                $role = $this->mb_role( $rel );
                $this->append_unique_credit( $result['categories'], $cat, $rel['artist']['name'], $role );
                $instrument_role = $this->mb_instrumentation_role( $rel );
                if ( '' !== $instrument_role ) {
                    $this->append_unique_credit( $result['categories'], 'Performers', $rel['artist']['name'], $instrument_role );
                }
            }
        }

        // 3. Follow the "performance" link to the *work* for songwriter data.
        if ( ! empty( $detail['relations'] ) ) {
            foreach ( $detail['relations'] as $rel ) {
                if ( 'performance' !== ( $rel['type'] ?? '' ) || empty( $rel['work']['id'] ) ) {
                    continue;
                }

                sleep( 1 );

                $work = $this->request(
                    add_query_arg( array(
                        'inc' => 'artist-rels',
                        'fmt' => 'json',
                    ), $this->mb_base . 'work/' . $rel['work']['id'] ),
                    'musicbrainz'
                );

                if ( ! is_wp_error( $work ) && ! empty( $work['relations'] ) ) {
                    foreach ( $work['relations'] as $wrel ) {
                        if ( empty( $wrel['artist'] ) ) {
                            continue;
                        }
                        $cat  = $this->mb_categorize( $wrel['type'], $wrel );
                        $role = $this->mb_role( $wrel );
                        $this->append_unique_credit( $result['categories'], $cat, $wrel['artist']['name'], $role );
                        $instrument_role = $this->mb_instrumentation_role( $wrel );
                        if ( '' !== $instrument_role ) {
                            $this->append_unique_credit( $result['categories'], 'Performers', $wrel['artist']['name'], $instrument_role );
                        }
                    }
                }
                break; // One work is sufficient.
            }
        }

        return $result;
    }

    /* ------------------------------------------------------------------
     *  DISCOGS
     * ----------------------------------------------------------------*/

    /**
     * Query Discogs for release-level credits.
     */
    private function fetch_discogs( $artist, $title ) {
        $result = array(
            'year'       => '',
            'categories' => array(),
        );

        // 1. Search releases.
        $search = $this->request(
            add_query_arg( array(
                'q'        => $title,
                'artist'   => $artist,
                'track'    => $title,
                'type'     => 'release',
                'per_page' => 8,
            ), $this->dc_base . 'database/search' ),
            'discogs'
        );

        if ( is_wp_error( $search ) || empty( $search['results'] ) ) {
            return $result;
        }

        // 2. Pick the most relevant release, then fetch details.
        $best_result = $this->pick_best_discogs_result( $search['results'], $artist, $title );
        if ( empty( $best_result['id'] ) ) {
            return $result;
        }
        $release_id  = $best_result['id'];
        $release     = $this->request( $this->dc_base . 'releases/' . $release_id, 'discogs' );

        if ( is_wp_error( $release ) ) {
            return $result;
        }
        if ( ! empty( $release['year'] ) ) {
            $result['year'] = $this->extract_year( $release['year'] );
        } elseif ( ! empty( $best_result['year'] ) ) {
            $result['year'] = $this->extract_year( $best_result['year'] );
        }

        // Release-level credits.
        if ( ! empty( $release['artists'] ) ) {
            foreach ( $release['artists'] as $ra ) {
                $this->append_unique_credit(
                    $result['categories'],
                    'Performers',
                    $ra['name'] ?? '',
                    __( 'Release artist', 'song-credits' )
                );
            }
        }

        if ( ! empty( $release['extraartists'] ) ) {
            foreach ( $release['extraartists'] as $ea ) {
                $role = ! empty( $ea['role'] ) ? $ea['role'] : __( 'Unknown Role', 'song-credits' );
                $cat  = $this->dc_categorize( $role );
                $this->append_unique_credit( $result['categories'], $cat, $ea['name'] ?? '', $role );
                $instrument_role = $this->discogs_instrumentation_role( $role );
                if ( '' !== $instrument_role ) {
                    $this->append_unique_credit( $result['categories'], 'Performers', $ea['name'] ?? '', $instrument_role );
                }
            }
        }

        // Track-level credits (match by title).
        if ( ! empty( $release['tracklist'] ) ) {
            $track = $this->pick_best_discogs_track( $release['tracklist'], $title );
            if ( ! empty( $track['artists'] ) ) {
                foreach ( $track['artists'] as $ta ) {
                    $this->append_unique_credit(
                        $result['categories'],
                        'Performers',
                        $ta['name'] ?? '',
                        __( 'Track artist', 'song-credits' )
                    );
                }
            }
            if ( ! empty( $track['extraartists'] ) ) {
                foreach ( $track['extraartists'] as $ea ) {
                    $role = ! empty( $ea['role'] ) ? $ea['role'] : __( 'Unknown Role', 'song-credits' );
                    $cat  = $this->dc_categorize( $role );
                    $this->append_unique_credit( $result['categories'], $cat, $ea['name'] ?? '', $role );
                    $instrument_role = $this->discogs_instrumentation_role( $role );
                    if ( '' !== $instrument_role ) {
                        $this->append_unique_credit( $result['categories'], 'Performers', $ea['name'] ?? '', $instrument_role );
                    }
                }
            }
        }

        return $result;
    }

    /* ------------------------------------------------------------------
     *  HTTP HELPER
     * ----------------------------------------------------------------*/

    /**
     * Perform a GET request via the WordPress HTTP API.
     *
     * @param  string $url    Full URL with query args.
     * @param  string $source 'musicbrainz' or 'discogs'.
     * @return array|WP_Error Decoded JSON body or error.
     */
    private function request( $url, $source = 'musicbrainz' ) {
        if ( empty( $this->allowed_hosts[ $source ] ) ) {
            $this->log_event( 'error', 'Unsupported API source', array( 'source' => $source ) );
            do_action( 'song_credits_api_error', $source, 'unsupported_source' );
            return new WP_Error( 'api_source_error', __( 'Unsupported API source', 'song-credits' ) );
        }

        $host = wp_parse_url( $url, PHP_URL_HOST );
        if ( ! is_string( $host ) || ! in_array( strtolower( $host ), $this->allowed_hosts[ $source ], true ) ) {
            $this->log_event( 'warning', 'Blocked API host', array( 'source' => $source, 'host' => (string) $host ) );
            do_action( 'song_credits_api_error', $source, 'blocked_host' );
            return new WP_Error( 'api_host_error', __( 'Blocked API host', 'song-credits' ) );
        }

        $args = array(
            'timeout'    => 15,
            'user-agent' => $this->user_agent,
            'headers'    => array(),
            'redirection'        => 3,
            'reject_unsafe_urls' => true,
            'sslverify'          => true,
        );

        if ( 'discogs' === $source && $this->discogs_token ) {
            $args['headers']['Authorization'] = 'Discogs token=' . $this->discogs_token;
        }

        $max_attempts = 3;
        for ( $attempt = 1; $attempt <= $max_attempts; $attempt++ ) {
            $response = wp_remote_get( $url, $args );

            if ( is_wp_error( $response ) ) {
                $this->log_event(
                    'warning',
                    'API request transport error',
                    array(
                        'source'  => $source,
                        'host'    => $host,
                        'attempt' => $attempt,
                        'error'   => $response->get_error_message(),
                    )
                );
                do_action( 'song_credits_api_error', $source, 'transport' );
                if ( $attempt < $max_attempts ) {
                    sleep( $attempt );
                    continue;
                }
                return $response;
            }

            $code = (int) wp_remote_retrieve_response_code( $response );
            if ( 200 !== $code ) {
                $this->log_event(
                    'warning',
                    'API non-200 response',
                    array(
                        'source'  => $source,
                        'host'    => $host,
                        'attempt' => $attempt,
                        'code'    => $code,
                    )
                );
                do_action( 'song_credits_api_error', $source, 'http_' . $code );
                if ( $attempt < $max_attempts && $this->is_retryable_status( $code ) ) {
                    $retry_after = (int) wp_remote_retrieve_header( $response, 'retry-after' );
                    $delay       = $retry_after > 0 ? min( $retry_after, 10 ) : $attempt;
                    $this->log_event(
                        'info',
                        'API retry scheduled',
                        array(
                            'source'      => $source,
                            'code'        => $code,
                            'attempt'     => $attempt,
                            'retry_after' => $delay,
                        )
                    );
                    sleep( max( 1, $delay ) );
                    continue;
                }
                return new WP_Error(
                    'api_error',
                    /* translators: %d: HTTP status code */
                    sprintf( __( 'API returned HTTP %d', 'song-credits' ), $code )
                );
            }

            $data = json_decode( wp_remote_retrieve_body( $response ), true );

            if ( JSON_ERROR_NONE !== json_last_error() ) {
                $this->log_event(
                    'warning',
                    'API JSON decode error',
                    array(
                        'source'  => $source,
                        'host'    => $host,
                        'attempt' => $attempt,
                    )
                );
                do_action( 'song_credits_api_error', $source, 'json_decode' );
                if ( $attempt < $max_attempts ) {
                    sleep( $attempt );
                    continue;
                }
                return new WP_Error( 'json_error', __( 'Invalid JSON from API', 'song-credits' ) );
            }

            return $data;
        }

        return new WP_Error( 'api_error', __( 'API request failed', 'song-credits' ) );
    }

    /**
     * Log bounded debug context to error log when enabled.
     */
    private function log_event( $level, $message, $context = array() ) {
        if ( ! $this->debug_logging ) {
            return;
        }

        $safe_context = array();
        if ( is_array( $context ) ) {
            $context = array_slice( $context, 0, 8, true );
            foreach ( $context as $key => $value ) {
                $k = sanitize_key( (string) $key );
                if ( '' === $k || false !== strpos( $k, 'token' ) ) {
                    continue;
                }
                if ( is_scalar( $value ) || null === $value ) {
                    $clean_value = sanitize_text_field( (string) $value );
                    $safe_context[ $k ] = function_exists( 'mb_substr' ) ? mb_substr( $clean_value, 0, 200 ) : substr( $clean_value, 0, 200 );
                } else {
                    $safe_context[ $k ] = '[non-scalar]';
                }
            }
        }

        $line = sprintf(
            'SongCredits[%s] %s %s',
            strtoupper( sanitize_key( (string) $level ) ),
            sanitize_text_field( (string) $message ),
            wp_json_encode( $safe_context )
        );
        error_log( $line ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
    }

    /**
     * Sanitize the credits payload before it is cached/returned.
     */
    private function sanitize_credits_payload( $credits ) {
        if ( ! is_array( $credits ) ) {
            return array(
                'artist'     => '',
                'title'      => '',
                'year'       => '',
                'categories' => array(),
                'sources'    => array(),
            );
        }

        $clean = array(
            'artist'     => sanitize_text_field( (string) ( $credits['artist'] ?? '' ) ),
            'title'      => sanitize_text_field( (string) ( $credits['title'] ?? '' ) ),
            'year'       => $this->extract_year( $credits['year'] ?? '' ),
            'categories' => array(),
            'sources'    => array(),
        );

        if ( ! empty( $credits['sources'] ) && is_array( $credits['sources'] ) ) {
            foreach ( $credits['sources'] as $source ) {
                $source = sanitize_text_field( (string) $source );
                if ( '' === $source ) {
                    continue;
                }
                $clean['sources'][] = $source;
            }
            $clean['sources'] = array_values( array_unique( $clean['sources'] ) );
        }

        if ( ! empty( $credits['categories'] ) && is_array( $credits['categories'] ) ) {
            foreach ( $credits['categories'] as $category => $entries ) {
                $category_name = sanitize_text_field( (string) $category );
                if ( '' === $category_name || ! is_array( $entries ) ) {
                    continue;
                }

                $clean_entries = array();
                foreach ( $entries as $entry ) {
                    $name = sanitize_text_field( (string) ( $entry['name'] ?? '' ) );
                    $role = sanitize_text_field( (string) ( $entry['role'] ?? '' ) );
                    if ( '' === $name || '' === $role ) {
                        continue;
                    }
                    $clean_entries[] = array(
                        'name' => $name,
                        'role' => $role,
                    );
                }

                if ( ! empty( $clean_entries ) ) {
                    $clean['categories'][ $category_name ] = $clean_entries;
                }
            }
        }

        return $clean;
    }

    /* ------------------------------------------------------------------
     *  HELPERS — MusicBrainz
     * ----------------------------------------------------------------*/

    /**
     * Escape Lucene special characters *inside* a quoted phrase.
     */
    private function mb_escape_query( $value ) {
        // Inside a quoted phrase only backslash and double-quote need escaping.
        return str_replace(
            array( '\\', '"' ),
            array( '\\\\', '\\"' ),
            $value
        );
    }

    /**
     * Build a single display string from an artist-credit array.
     */
    private function format_artist_credit( $credits ) {
        $parts = array();
        foreach ( $credits as $ac ) {
            $name   = $ac['name'] ?? ( $ac['artist']['name'] ?? '' );
            $join   = $ac['joinphrase'] ?? '';
            $parts[] = $name . $join;
        }
        return implode( '', $parts );
    }

    /**
     * Map a MusicBrainz relationship type to a UI category.
     *
     * MusicBrainz uses the instrument name itself as the relationship type
     * (e.g. "guitar", "piano") for instrument credits, so we need a broad
     * instrument keyword list in addition to the named performer roles.
     *
     * @param  string $type     Relationship type string.
     * @param  array  $relation Full relation array (used to inspect attributes).
     * @return string Category label.
     */
    private function mb_categorize( $type, $relation = array() ) {
        $type_lower = strtolower( $type );

        // Explicit named categories checked first.
        $map = array(
            'Production'  => array( 'producer', 'co-producer', 'executive producer' ),
            'Engineering' => array( 'engineer', 'sound', 'audio', 'recording', 'mix', 'mastering', 'balance', 'editor', 'programming' ),
            'Songwriting' => array( 'composer', 'lyricist', 'writer', 'songwriter', 'librettist', 'arranger', 'orchestrator' ),
            // Broad performer / instrument terms — checked LAST so Production/Engineering/Songwriting
            // keywords take priority for ambiguous types like "programming".
            'Performers'  => array(
                // Generic MB performer types
                'performer', 'vocal', 'performing orchestra', 'conductor', 'chorus master', 'concertmaster',
                // MB uses the bare instrument name as the relationship type:
                'instrument',
                // String family
                'guitar', 'bass guitar', 'bass', 'banjo', 'ukulele', 'mandolin', 'lute', 'sitar',
                'violin', 'viola', 'cello', 'double bass', 'harp', 'dulcimer',
                // Keyboard / piano
                'piano', 'keyboard', 'organ', 'synthesizer', 'synth', 'harpsichord', 'accordion', 'melodica',
                // Wind
                'flute', 'oboe', 'clarinet', 'bassoon', 'saxophone', 'sax', 'trumpet', 'trombone',
                'french horn', 'tuba', 'harmonica', 'recorder', 'piccolo', 'cornet', 'flugelhorn',
                // Percussion / drums
                'drums', 'drum', 'percussion', 'timpani', 'xylophone', 'marimba', 'vibraphone',
                'congas', 'bongos', 'tabla', 'djembe', 'tambourine', 'cajon',
                // Electronic / DJ
                'turntables', 'dj', 'beatbox', 'sampler',
                // Other
                'backing', 'lead', 'rhythm', 'chorus', 'choir', 'strings', 'horns', 'orchestra',
                'solo', 'featuring', 'rap', 'mc',
            ),
        );

        foreach ( $map as $category => $keywords ) {
            foreach ( $keywords as $kw ) {
                if ( false !== strpos( $type_lower, $kw ) ) {
                    return $category;
                }
            }
        }

        // If the relation has instrument attributes, it's a Performer regardless
        // of the type string (covers catch-all MB "instrument" type).
        if ( ! empty( $relation['attributes'] ) ) {
            foreach ( $relation['attributes'] as $attr ) {
                if ( is_array( $attr ) ) {
                    // Instrument attribute objects have a "type" of "instrument".
                    if ( isset( $attr['type'] ) && false !== strpos( strtolower( (string) $attr['type'] ), 'instrument' ) ) {
                        return 'Performers';
                    }
                    continue;
                }
                if ( is_string( $attr ) && $this->looks_like_performer_text( $attr ) ) {
                    return 'Performers';
                }
            }
        }

        return __( 'Other', 'song-credits' );
    }

    /**
     * Build a human-readable role string from a MusicBrainz relation.
     */
    private function mb_role( $relation ) {
        if ( ! empty( $relation['attributes'] ) ) {
            $parts = array();
            foreach ( $relation['attributes'] as $attr ) {
                if ( is_string( $attr ) && '' !== trim( $attr ) ) {
                    $parts[] = ucfirst( $attr );
                    continue;
                }
                if ( is_array( $attr ) ) {
                    if ( ! empty( $attr['value'] ) && is_string( $attr['value'] ) ) {
                        $parts[] = ucfirst( $attr['value'] );
                        continue;
                    }
                    if ( ! empty( $attr['name'] ) && is_string( $attr['name'] ) ) {
                        $parts[] = ucfirst( $attr['name'] );
                        continue;
                    }
                    if ( ! empty( $attr['type'] ) && is_string( $attr['type'] ) ) {
                        $parts[] = ucfirst( $attr['type'] );
                    }
                }
            }
            if ( ! empty( $parts ) ) {
                return implode( ', ', array_unique( $parts ) );
            }
        }
        return ucfirst( str_replace( '-', ' ', $relation['type'] ?? '' ) );
    }

    /**
     * Choose the most likely MusicBrainz recording match.
     */
    private function pick_best_mb_recording( $recordings, $artist, $title ) {
        $best       = null;
        $best_score = -1;

        foreach ( $recordings as $recording ) {
            if ( empty( $recording['id'] ) ) {
                continue;
            }
            $recording_title  = isset( $recording['title'] ) ? (string) $recording['title'] : '';
            $recording_artist = '';
            if ( ! empty( $recording['artist-credit'] ) ) {
                $recording_artist = $this->format_artist_credit( $recording['artist-credit'] );
            }

            $title_score  = $this->score_text_match( $title, $recording_title );
            $artist_score = $this->score_text_match( $artist, $recording_artist );
            $score        = ( $title_score * 0.7 ) + ( $artist_score * 0.3 );

            if ( $score > $best_score ) {
                $best_score = $score;
                $best       = $recording;
            }
        }

        return is_array( $best ) ? $best : array();
    }

    /**
     * Choose the most likely Discogs release search result.
     */
    private function pick_best_discogs_result( $results, $artist, $title ) {
        $best       = null;
        $best_score = -1;

        foreach ( $results as $item ) {
            if ( empty( $item['id'] ) ) {
                continue;
            }

            $candidate = isset( $item['title'] ) ? (string) $item['title'] : '';
            $title_score  = $this->score_text_match( $title, $candidate );
            $artist_score = $this->score_text_match( $artist, $candidate );
            $score        = ( $title_score * 0.65 ) + ( $artist_score * 0.35 );

            if ( $score > $best_score ) {
                $best_score = $score;
                $best       = $item;
            }
        }

        return is_array( $best ) ? $best : array();
    }

    /**
     * Pick the best matching track from a Discogs release tracklist.
     */
    private function pick_best_discogs_track( $tracklist, $title ) {
        $best       = null;
        $best_score = -1;

        foreach ( $tracklist as $track ) {
            if ( empty( $track['title'] ) ) {
                continue;
            }

            $score = $this->score_text_match( $title, (string) $track['title'] );
            if ( $score > $best_score ) {
                $best_score = $score;
                $best       = $track;
            }
        }

        // Avoid attaching wrong track credits if nothing is even moderately close.
        if ( $best_score < 55 ) {
            return array();
        }

        return is_array( $best ) ? $best : array();
    }

    /**
     * Normalize text for tolerant title/artist matching.
     */
    private function normalize_for_match( $value ) {
        $text = wp_strip_all_tags( (string) $value );
        $text = remove_accents( $text );
        $text = strtolower( $text );
        $text = preg_replace( '/\b(feat|ft|featuring)\.?\s+.+$/i', '', $text );
        $text = preg_replace( '/\([^)]*(feat|ft|featuring)[^)]*\)/i', '', $text );
        $text = str_replace( '&', ' and ', $text );
        $text = preg_replace( '/[^a-z0-9]+/i', ' ', $text );
        $text = preg_replace( '/\s+/', ' ', $text );
        return trim( $text );
    }

    /**
     * Rough text similarity score (0-100) tuned for artist/title lookup.
     */
    private function score_text_match( $needle, $haystack ) {
        $a = $this->normalize_for_match( $needle );
        $b = $this->normalize_for_match( $haystack );

        if ( '' === $a || '' === $b ) {
            return 0;
        }

        if ( $a === $b ) {
            return 100;
        }

        $score = 0;
        if ( false !== strpos( $b, $a ) || false !== strpos( $a, $b ) ) {
            $score += 40;
        }

        $a_tokens = array_values( array_filter( explode( ' ', $a ) ) );
        $b_tokens = array_values( array_filter( explode( ' ', $b ) ) );
        if ( ! empty( $a_tokens ) && ! empty( $b_tokens ) ) {
            $overlap = array_intersect( $a_tokens, $b_tokens );
            $union   = array_unique( array_merge( $a_tokens, $b_tokens ) );
            if ( ! empty( $union ) ) {
                $score += (int) round( 40 * ( count( $overlap ) / count( $union ) ) );
            }
        }

        similar_text( $a, $b, $pct );
        $score += (int) round( $pct * 0.2 );

        return min( 100, max( 0, $score ) );
    }

    /**
     * Retry only on transient HTTP statuses.
     */
    private function is_retryable_status( $status_code ) {
        $status_code = (int) $status_code;
        return in_array( $status_code, array( 408, 409, 425, 429, 500, 502, 503, 504 ), true );
    }

    /**
     * Check whether the assembled credits already include performer entries.
     */
    private function has_performers( $credits ) {
        return ! empty( $credits['categories']['Performers'] ) && is_array( $credits['categories']['Performers'] );
    }

    /**
     * Fallback performer lookup via Wikidata.
     *
     * @return array[] Array of ['name' => string, 'role' => string].
     */
    private function fetch_wikidata_performers( $artist, $title ) {
        $search = $this->request(
            add_query_arg(
                array(
                    'action'   => 'wbsearchentities',
                    'search'   => $title,
                    'language' => 'en',
                    'type'     => 'item',
                    'limit'    => 8,
                    'format'   => 'json',
                ),
                $this->wd_api
            ),
            'wikidata'
        );

        if ( is_wp_error( $search ) || empty( $search['search'] ) || ! is_array( $search['search'] ) ) {
            return array();
        }

        $candidates = array();
        $all_performer_ids = array();

        foreach ( $search['search'] as $item ) {
            $qid = isset( $item['id'] ) ? (string) $item['id'] : '';
            if ( '' === $qid ) {
                continue;
            }

            $entity_doc = $this->request( 'https://www.wikidata.org/wiki/Special:EntityData/' . rawurlencode( $qid ) . '.json', 'wikidata' );
            if ( is_wp_error( $entity_doc ) || empty( $entity_doc['entities'][ $qid ] ) ) {
                continue;
            }

            $entity        = $entity_doc['entities'][ $qid ];
            $performer_ids = $this->extract_wikidata_entity_ids( $entity, 'P175' );
            if ( empty( $performer_ids ) ) {
                continue;
            }

            $label = '';
            if ( ! empty( $entity['labels']['en']['value'] ) ) {
                $label = (string) $entity['labels']['en']['value'];
            } elseif ( ! empty( $item['label'] ) ) {
                $label = (string) $item['label'];
            }

            $candidates[] = array(
                'qid'           => $qid,
                'title_label'   => $label,
                'performer_ids' => array_values( array_unique( $performer_ids ) ),
            );
            $all_performer_ids = array_merge( $all_performer_ids, $performer_ids );
        }

        if ( empty( $candidates ) ) {
            return array();
        }

        $performer_labels = $this->wikidata_labels_by_qid( array_values( array_unique( $all_performer_ids ) ) );
        if ( empty( $performer_labels ) ) {
            return array();
        }

        $best_index = -1;
        $best_score = -1;
        foreach ( $candidates as $i => $candidate ) {
            $title_score = $this->score_text_match( $title, $candidate['title_label'] );
            $artist_score = 0;
            foreach ( $candidate['performer_ids'] as $pid ) {
                if ( empty( $performer_labels[ $pid ] ) ) {
                    continue;
                }
                $artist_score = max( $artist_score, $this->score_text_match( $artist, $performer_labels[ $pid ] ) );
            }
            $score = ( $title_score * 0.75 ) + ( $artist_score * 0.25 );
            if ( $score > $best_score ) {
                $best_score = $score;
                $best_index = $i;
            }
        }

        if ( $best_index < 0 || $best_score < 35 ) {
            return array();
        }

        $entries = array();
        foreach ( $candidates[ $best_index ]['performer_ids'] as $pid ) {
            if ( empty( $performer_labels[ $pid ] ) ) {
                continue;
            }
            $entries[] = array(
                'name' => $performer_labels[ $pid ],
                'role' => __( 'Performer (Wikidata)', 'song-credits' ),
            );
        }

        return $entries;
    }

    /**
     * Extract linked entity IDs from a Wikidata claim list.
     *
     * @return string[]
     */
    private function extract_wikidata_entity_ids( $entity, $property ) {
        $ids = array();
        if ( empty( $entity['claims'][ $property ] ) || ! is_array( $entity['claims'][ $property ] ) ) {
            return $ids;
        }

        foreach ( $entity['claims'][ $property ] as $claim ) {
            $datavalue = $claim['mainsnak']['datavalue']['value'] ?? null;
            if ( ! is_array( $datavalue ) || empty( $datavalue['id'] ) ) {
                continue;
            }
            $ids[] = (string) $datavalue['id'];
        }

        return array_values( array_unique( $ids ) );
    }

    /**
     * Resolve Wikidata QIDs to English labels.
     *
     * @param string[] $qids
     * @return array<string,string>
     */
    private function wikidata_labels_by_qid( $qids ) {
        if ( empty( $qids ) ) {
            return array();
        }

        $labels = array();
        $chunks = array_chunk( $qids, 50 );
        foreach ( $chunks as $chunk ) {
            $resp = $this->request(
                add_query_arg(
                    array(
                        'action'    => 'wbgetentities',
                        'ids'       => implode( '|', $chunk ),
                        'props'     => 'labels',
                        'languages' => 'en',
                        'format'    => 'json',
                    ),
                    $this->wd_api
                ),
                'wikidata'
            );

            if ( is_wp_error( $resp ) || empty( $resp['entities'] ) || ! is_array( $resp['entities'] ) ) {
                continue;
            }

            foreach ( $resp['entities'] as $qid => $entity ) {
                if ( empty( $entity['labels']['en']['value'] ) ) {
                    continue;
                }
                $labels[ (string) $qid ] = (string) $entity['labels']['en']['value'];
            }
        }

        return $labels;
    }

    /**
     * Extract a 4-digit year from date-like or numeric inputs.
     */
    private function extract_year( $value ) {
        if ( is_int( $value ) ) {
            return ( $value >= 1000 && $value <= 9999 ) ? (string) $value : '';
        }
        if ( ! is_string( $value ) && ! is_numeric( $value ) ) {
            return '';
        }

        $text = trim( (string) $value );
        if ( '' === $text ) {
            return '';
        }
        if ( preg_match( '/\b(1[0-9]{3}|20[0-9]{2}|2100)\b/', $text, $m ) ) {
            return (string) $m[1];
        }
        return '';
    }

    /**
     * Add a credit entry while deduping by normalized name + role within a category.
     */
    private function append_unique_credit( &$categories, $category, $name, $role ) {
        $name = is_string( $name ) ? trim( $name ) : '';
        $role = is_string( $role ) ? trim( $role ) : '';
        if ( '' === $name || '' === $role ) {
            return;
        }

        if ( ! isset( $categories[ $category ] ) || ! is_array( $categories[ $category ] ) ) {
            $categories[ $category ] = array();
        }

        foreach ( $categories[ $category ] as $existing ) {
            if (
                strtolower( (string) ( $existing['name'] ?? '' ) ) === strtolower( $name ) &&
                strtolower( (string) ( $existing['role'] ?? '' ) ) === strtolower( $role )
            ) {
                return;
            }
        }

        // In Performers, keep one row per name and merge all unique role text.
        if ( 'Performers' === $category ) {
            foreach ( $categories[ $category ] as $idx => $existing ) {
                if ( strtolower( (string) ( $existing['name'] ?? '' ) ) !== strtolower( $name ) ) {
                    continue;
                }
                $categories[ $category ][ $idx ]['role'] = $this->merge_role_text(
                    (string) ( $existing['role'] ?? '' ),
                    $role
                );
                return;
            }
        }

        $categories[ $category ][] = array(
            'name' => $name,
            'role' => $role,
        );
    }

    /**
     * Merge two role strings into a unique comma-separated list.
     */
    private function merge_role_text( $existing, $incoming ) {
        $pieces = array_merge(
            preg_split( '/\s*,\s*/', (string) $existing ),
            preg_split( '/\s*,\s*/', (string) $incoming )
        );

        $result = array();
        $seen   = array();
        foreach ( $pieces as $piece ) {
            $piece = trim( (string) $piece );
            if ( '' === $piece ) {
                continue;
            }
            $key = strtolower( $piece );
            if ( isset( $seen[ $key ] ) ) {
                continue;
            }
            $seen[ $key ] = true;
            $result[]     = $piece;
        }

        return implode( ', ', $result );
    }

    /**
     * Detect whether an attribute string should be treated as a performer/instrument credit.
     */
    private function looks_like_performer_text( $value ) {
        $text = strtolower( trim( (string) $value ) );
        if ( '' === $text ) {
            return false;
        }

        $needles = array(
            'vocal', 'voice', 'singer', 'perform', 'instrument', 'guitar', 'bass', 'drum',
            'percussion', 'piano', 'keyboard', 'synth', 'violin', 'viola', 'cello', 'sax',
            'trumpet', 'trombone', 'flute', 'clarinet', 'harp', 'banjo', 'ukulele', 'dj',
            'turntables', 'choir', 'chorus', 'orchestra', 'conductor',
        );

        foreach ( $needles as $needle ) {
            if ( false !== strpos( $text, $needle ) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Extract MusicBrainz instrumentation from relation attributes.
     */
    private function mb_instrumentation_role( $relation ) {
        if ( empty( $relation['attributes'] ) || ! is_array( $relation['attributes'] ) ) {
            return '';
        }

        $parts = array();
        foreach ( $relation['attributes'] as $attr ) {
            if ( is_string( $attr ) ) {
                $clean = trim( $attr );
                if ( '' !== $clean && $this->looks_like_performer_text( $clean ) ) {
                    $parts[] = ucfirst( $clean );
                }
                continue;
            }

            if ( ! is_array( $attr ) ) {
                continue;
            }

            $attr_type = isset( $attr['type'] ) ? strtolower( (string) $attr['type'] ) : '';
            if ( false === strpos( $attr_type, 'instrument' ) ) {
                continue;
            }

            $value = '';
            if ( ! empty( $attr['value'] ) && is_string( $attr['value'] ) ) {
                $value = $attr['value'];
            } elseif ( ! empty( $attr['name'] ) && is_string( $attr['name'] ) ) {
                $value = $attr['name'];
            } elseif ( ! empty( $attr['type'] ) && is_string( $attr['type'] ) ) {
                $value = $attr['type'];
            }

            $value = trim( $value );
            if ( '' !== $value ) {
                $parts[] = ucfirst( $value );
            }
        }

        if ( empty( $parts ) ) {
            return '';
        }

        return implode( ', ', array_values( array_unique( $parts ) ) );
    }

    /**
     * Extract instrument-focused role text from a Discogs role string.
     */
    private function discogs_instrumentation_role( $role ) {
        $role = trim( (string) $role );
        if ( '' === $role ) {
            return '';
        }

        $segments = preg_split( '/\s*[,;\/]\s*/', $role );
        if ( ! is_array( $segments ) || empty( $segments ) ) {
            return '';
        }

        $parts = array();
        foreach ( $segments as $segment ) {
            $segment = trim( (string) $segment );
            if ( '' === $segment || ! $this->looks_like_performer_text( $segment ) ) {
                continue;
            }
            $parts[] = ucfirst( $segment );
        }

        if ( empty( $parts ) ) {
            return '';
        }

        return implode( ', ', array_values( array_unique( $parts ) ) );
    }

    /* ------------------------------------------------------------------
     *  HELPERS — Discogs
     * ----------------------------------------------------------------*/

    /**
     * Map a Discogs role string to a UI category.
     */
    private function dc_categorize( $role ) {
        $role_lower = strtolower( $role );

        $map = array(
            'Production'  => array( 'producer', 'produced', 'executive' ),
            'Engineering' => array( 'engineer', 'mixed', 'mastered', 'recorded', 'technician', 'programming', 'edited' ),
            'Songwriting' => array( 'written', 'composed', 'lyrics', 'songwriter', 'music by', 'arranged', 'orchestrated', 'adapted' ),
            'Performers'  => array(
                // Voice
                'vocals', 'voice', 'singing', 'rap', 'mc', 'spoken',
                // Guitar family
                'guitar', 'bass guitar', 'bass', 'banjo', 'ukulele', 'mandolin', 'lute', 'sitar', 'dulcimer',
                // Keyboard / piano
                'piano', 'keyboard', 'organ', 'synthesizer', 'synth', 'harpsichord', 'accordion', 'melodica', 'wurlitzer', 'rhodes', 'clavinet',
                // Strings
                'violin', 'viola', 'cello', 'double bass', 'harp', 'string',
                // Wind
                'saxophone', 'sax', 'trumpet', 'trombone', 'flute', 'oboe', 'clarinet', 'bassoon',
                'french horn', 'tuba', 'harmonica', 'recorder', 'piccolo', 'cornet', 'flugelhorn',
                // Percussion / drums
                'drums', 'drum', 'percussion', 'timpani', 'xylophone', 'marimba', 'vibraphone',
                'congas', 'bongos', 'tabla', 'djembe', 'tambourine', 'cajon', 'cowbell',
                // Electronic / DJ
                'turntables', 'dj', 'beatbox', 'sampler',
                // Generic
                'horns', 'horn', 'brass', 'woodwind', 'choir', 'orchestra', 'backing',
                'lead', 'rhythm', 'solo', 'featuring', 'feat', 'with', 'additional',
                'instrument', 'performer', 'conductor', 'concertmaster',
            ),
        );

        foreach ( $map as $category => $keywords ) {
            foreach ( $keywords as $kw ) {
                if ( false !== strpos( $role_lower, $kw ) ) {
                    return $category;
                }
            }
        }
        return __( 'Other', 'song-credits' );
    }

    /* ------------------------------------------------------------------
     *  HELPERS — Merge
     * ----------------------------------------------------------------*/

    /**
     * Merge secondary credits into primary, de-duplicating by name + role.
     */
    private function merge_credits( $primary, $secondary ) {
        foreach ( $secondary['categories'] as $cat => $entries ) {
            if ( ! isset( $primary['categories'][ $cat ] ) ) {
                $primary['categories'][ $cat ] = array();
            }
            foreach ( $entries as $entry ) {
                $dominated = false;
                foreach ( $primary['categories'][ $cat ] as $existing ) {
                    if (
                        strtolower( $existing['name'] ) === strtolower( $entry['name'] ) &&
                        strtolower( $existing['role'] ) === strtolower( $entry['role'] )
                    ) {
                        $dominated = true;
                        break;
                    }
                }
                if ( ! $dominated ) {
                    $primary['categories'][ $cat ][] = $entry;
                }
            }
        }
        return $primary;
    }
}
