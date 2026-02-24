/**
 * Song Credits Lookup — frontend.
 *
 * Vanilla JS, no jQuery dependency.
 * Uses the Fetch API to POST to admin-ajax.php.
 */
( function ( root, factory ) {
    if ( typeof module !== 'undefined' && module.exports ) {
        module.exports = factory( root );
        return;
    }
    factory( root );
}( typeof globalThis !== 'undefined' ? globalThis : this, function ( root ) {
    'use strict';

    function extractLookupParams( search ) {
        var params = new URLSearchParams( search || '' );
        var artist = ( params.get( 'sc_artist' ) || params.get( 'artist' ) || '' ).trim();
        var title  = ( params.get( 'sc_title' ) || params.get( 'title' ) || '' ).trim();
        return {
            artist: artist,
            title: title,
        };
    }

    if ( root ) {
        root.songCreditsExtractLookupParams = extractLookupParams;
    }

    if ( typeof document === 'undefined' ) {
        return {
            extractLookupParams: extractLookupParams,
        };
    }

    document.addEventListener( 'DOMContentLoaded', function () {
        if ( typeof root.songCreditsData === 'undefined' || ! root.songCreditsData ) {
            return;
        }

        var form          = document.getElementById( 'song-credits-form' );
        var results       = document.getElementById( 'song-credits-results' );
        var btn           = document.getElementById( 'song-credits-submit' );
        var reset         = document.getElementById( 'song-credits-reset' );
        var artistInput   = document.getElementById( 'song-credits-artist' );
        var titleInput    = document.getElementById( 'song-credits-title' );
        var statusRegion  = document.getElementById( 'song-credits-status' );
        var recentWrap    = document.getElementById( 'song-credits-recent' );
        var recentReset   = document.getElementById( 'song-credits-recent-reset' );
        var artistOptions = document.getElementById( 'song-credits-artist-options' );
        var titleOptions  = document.getElementById( 'song-credits-title-options' );

        if ( ! form || ! results || ! btn || ! reset || ! artistInput || ! titleInput ) {
            return;
        }

        var recentKey        = 'songCreditsRecentSearches';
        var recentSearches   = loadRecentSearches();
        var remoteSuggestions = [];

        renderRecentSearches();
        refreshAutocomplete();
        fetchRemoteSuggestions();

        artistInput.addEventListener( 'input', function () {
            refreshAutocomplete( artistInput.value, titleInput.value );
        } );
        titleInput.addEventListener( 'input', function () {
            refreshAutocomplete( artistInput.value, titleInput.value );
        } );

        if ( recentReset ) {
            recentReset.addEventListener( 'click', function () {
                recentSearches = [];
                if ( root.localStorage ) {
                    try {
                        root.localStorage.removeItem( recentKey );
                    } catch ( e ) {
                        // Ignore storage failures.
                    }
                }
                renderRecentSearches();
                refreshAutocomplete( artistInput.value, titleInput.value );
                announceStatus( root.songCreditsData.i18n.recentCleared || '' );
            } );
        }

        reset.addEventListener( 'click', function () {
            form.reset();
            results.innerHTML = '';
            results.className = 'song-credits-results';
            announceStatus( root.songCreditsData.i18n.noResults );
            artistInput.focus();
        } );

        form.addEventListener( 'submit', function ( e ) {
            e.preventDefault();
            runLookup( artistInput.value.trim(), titleInput.value.trim(), { fromRecent: false } );
        } );

        autoLookupFromQuery();

        function runLookup( artist, title, options ) {
            options = options || {};

            if ( ! artist || ! title ) {
                return;
            }

            var originalLabel = btn.textContent;
            btn.disabled      = true;
            btn.textContent   = root.songCreditsData.i18n.searching;
            btn.classList.add( 'song-credits-loading' );
            results.innerHTML = '';
            results.className = 'song-credits-results';
            announceStatus( options.fromRecent ? root.songCreditsData.i18n.usingRecent : root.songCreditsData.i18n.searching );

            var body = new FormData();
            body.append( 'action', 'song_credits_lookup' );
            body.append( 'nonce',  root.songCreditsData.nonce );
            body.append( 'artist', artist );
            body.append( 'title',  title );

            fetch( root.songCreditsData.ajaxUrl, {
                method:      'POST',
                credentials: 'same-origin',
                body:        body,
            } )
            .then( function ( r ) { return r.json(); } )
            .then( function ( json ) {
                if ( json.success && json.data ) {
                    renderCredits( json.data );
                    saveRecentSearch( json.data.artist || artist, json.data.title || title );
                    announceStatus( root.songCreditsData.i18n.loaded );
                } else {
                    showError( json.data && json.data.message ? json.data.message : root.songCreditsData.i18n.noResults );
                }
            } )
            .catch( function () {
                showError( root.songCreditsData.i18n.error );
            } )
            .finally( function () {
                btn.disabled    = false;
                btn.textContent = originalLabel;
                btn.classList.remove( 'song-credits-loading' );
            } );
        }

        function autoLookupFromQuery() {
            var lookup = extractLookupParams( root.location ? root.location.search : '' );
            if ( ! lookup.artist || ! lookup.title ) {
                return;
            }
            artistInput.value = lookup.artist;
            titleInput.value = lookup.title;
            runLookup( lookup.artist, lookup.title, { fromRecent: false } );
            clearLookupQueryParams();
        }

        function clearLookupQueryParams() {
            if ( ! root.history || ! root.history.replaceState || ! root.location ) {
                return;
            }
            var url = new URL( root.location.href );
            url.searchParams.delete( 'sc_artist' );
            url.searchParams.delete( 'sc_title' );
            url.searchParams.delete( 'artist' );
            url.searchParams.delete( 'title' );
            root.history.replaceState( {}, '', url.toString() );
        }

        function fetchRemoteSuggestions() {
            var body = new FormData();
            body.append( 'action', 'song_credits_suggestions' );
            body.append( 'nonce', root.songCreditsData.nonce );

            fetch( root.songCreditsData.ajaxUrl, {
                method: 'POST',
                credentials: 'same-origin',
                body: body,
            } )
            .then( function (r) { return r.json(); } )
            .then( function (json) {
                if ( json.success && Array.isArray( json.data ) ) {
                    remoteSuggestions = json.data;
                    refreshAutocomplete( artistInput.value, titleInput.value );
                }
            } )
            .catch( function () {
                // Silent fail — autocomplete still works from recent local data.
            } );
        }

        function loadRecentSearches() {
            if ( ! root.localStorage ) {
                return [];
            }
            try {
                var raw = root.localStorage.getItem( recentKey );
                var parsed = raw ? JSON.parse( raw ) : [];
                return Array.isArray( parsed ) ? parsed : [];
            } catch ( e ) {
                return [];
            }
        }

        function saveRecentSearch( artist, title ) {
            var cleanArtist = String( artist || '' ).trim();
            var cleanTitle  = String( title || '' ).trim();
            if ( ! cleanArtist || ! cleanTitle ) {
                return;
            }

            var key = cleanArtist.toLowerCase() + '|' + cleanTitle.toLowerCase();
            recentSearches = recentSearches.filter( function (item) {
                var itemKey = String( item.artist || '' ).toLowerCase() + '|' + String( item.title || '' ).toLowerCase();
                return itemKey !== key;
            } );

            recentSearches.unshift( { artist: cleanArtist, title: cleanTitle } );
            recentSearches = recentSearches.slice( 0, 12 );

            if ( root.localStorage ) {
                try {
                    root.localStorage.setItem( recentKey, JSON.stringify( recentSearches ) );
                } catch ( e ) {
                    // Ignore quota/storage failures.
                }
            }

            renderRecentSearches();
            refreshAutocomplete( artistInput.value, titleInput.value );
        }

        function renderRecentSearches() {
            if ( ! recentWrap ) {
                return;
            }
            var existingList = recentWrap.querySelector( '.song-credits-recent-list' );
            if ( existingList ) {
                existingList.remove();
            }
            var existingEmpty = recentWrap.querySelector( '.song-credits-recent-empty' );
            if ( existingEmpty ) {
                existingEmpty.remove();
            }

            if ( ! recentSearches.length ) {
                recentWrap.appendChild( el( 'p', 'song-credits-recent-empty', root.songCreditsData.i18n.noRecent ) );
                if ( recentReset ) {
                    recentReset.disabled = true;
                }
                return;
            }
            if ( recentReset ) {
                recentReset.disabled = false;
            }

            var list = el( 'div', 'song-credits-recent-list' );
            recentSearches.forEach( function (item) {
                var b = el( 'button', 'song-credits-recent-item', item.artist + ' — ' + item.title );
                b.type = 'button';
                b.addEventListener( 'click', function () {
                    artistInput.value = item.artist;
                    titleInput.value = item.title;
                    runLookup( item.artist, item.title, { fromRecent: true } );
                } );
                list.appendChild( b );
            } );

            recentWrap.appendChild( list );
        }

        function refreshAutocomplete( artistQuery, titleQuery ) {
            artistQuery = String( artistQuery || '' ).trim().toLowerCase();
            titleQuery  = String( titleQuery || '' ).trim().toLowerCase();

            var merged = recentSearches.concat( remoteSuggestions );
            var seenArtists = {};
            var seenTitles  = {};
            var artists = [];
            var titles  = [];

            merged.forEach( function (item) {
                var a = String( item.artist || '' ).trim();
                var t = String( item.title || '' ).trim();
                if ( a ) {
                    var aKey = a.toLowerCase();
                    if ( ( ! artistQuery || aKey.indexOf( artistQuery ) >= 0 ) && ! seenArtists[ aKey ] ) {
                        seenArtists[ aKey ] = true;
                        artists.push( a );
                    }
                }
                if ( t ) {
                    var tKey = t.toLowerCase();
                    if ( ( ! titleQuery || tKey.indexOf( titleQuery ) >= 0 ) && ! seenTitles[ tKey ] ) {
                        seenTitles[ tKey ] = true;
                        titles.push( t );
                    }
                }
            } );

            if ( artistOptions ) {
                artistOptions.innerHTML = '';
                artists.slice( 0, 120 ).forEach( function (value) {
                    var option = document.createElement( 'option' );
                    option.value = value;
                    artistOptions.appendChild( option );
                } );
            }
            if ( titleOptions ) {
                titleOptions.innerHTML = '';
                titles.slice( 0, 120 ).forEach( function (value) {
                    var option = document.createElement( 'option' );
                    option.value = value;
                    titleOptions.appendChild( option );
                } );
            }
        }

        function renderCredits( data ) {
            results.innerHTML = '';
            results.className = 'song-credits-results song-credits-has-results';

            var header   = el( 'div', 'song-credits-header song-credits-animate song-credits-fade-in' );
            var titleEl  = el( 'h2',  'song-credits-song-title',  data.title  || '' );
            var artistEl = el( 'h3',  'song-credits-artist-name', data.artist || '' );
            var yearEl   = el( 'p',   'song-credits-year', data.year ? 'Released ' + data.year : '' );
            header.appendChild( titleEl );
            header.appendChild( artistEl );
            if ( data.year ) {
                header.appendChild( yearEl );
            }
            results.appendChild( header );

            var grid = el( 'div', 'song-credits-grid song-credits-animate song-credits-fade-in' );
            var order = [ 'Songwriting', 'Performers', 'Production', 'Engineering', 'Other' ];
            var cats  = data.categories || {};
            var keys  = Object.keys( cats ).sort( function ( a, b ) {
                var ia = order.indexOf( a ), ib = order.indexOf( b );
                return ( ia < 0 ? 999 : ia ) - ( ib < 0 ? 999 : ib );
            } );

            keys.forEach( function ( cat, idx ) {
                var entries = cats[ cat ];
                if ( ! entries || ! entries.length ) { return; }

                var section  = el( 'div', 'song-credits-category song-credits-animate song-credits-slide-up' );
                section.style.animationDelay = ( idx * 70 ) + 'ms';
                var catTitle = el( 'h4',  'song-credits-category-title', cat );
                section.appendChild( catTitle );

                var list = el( 'ul', 'song-credits-list' );
                entries.forEach( function ( entry ) {
                    var li   = el( 'li',   'song-credits-entry' );
                    var name = el( 'span', 'song-credits-name', entry.name || '' );
                    var role = el( 'span', 'song-credits-role', entry.role || '' );
                    li.appendChild( name );
                    li.appendChild( role );
                    list.appendChild( li );
                } );

                section.appendChild( list );
                grid.appendChild( section );
            } );

            results.appendChild( grid );

            if ( data.sources && data.sources.length ) {
                var src = el( 'div', 'song-credits-sources song-credits-animate song-credits-fade-in',
                    root.songCreditsData.i18n.sources + ' ' + data.sources.join( ', ' ) );
                src.style.animationDelay = '200ms';
                results.appendChild( src );
            }

            results.scrollIntoView( { behavior: 'smooth', block: 'start' } );
            results.focus();
        }

        function showError( msg ) {
            results.innerHTML = '';
            results.className = 'song-credits-results song-credits-error';
            var box = el( 'div', 'song-credits-error-message song-credits-animate song-credits-fade-in', msg );
            box.setAttribute( 'role', 'alert' );
            results.appendChild( box );
            announceStatus( msg );
        }

        function announceStatus( msg ) {
            if ( ! statusRegion ) {
                return;
            }
            statusRegion.textContent = '';
            root.setTimeout( function () {
                statusRegion.textContent = msg || '';
            }, 10 );
        }

        function el( tag, cls, text ) {
            var node = document.createElement( tag );
            node.className = cls;
            if ( text ) { node.textContent = text; }
            return node;
        }
    } );

    return {
        extractLookupParams: extractLookupParams,
    };
} ) );
