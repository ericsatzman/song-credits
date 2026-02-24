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

        var form    = document.getElementById( 'song-credits-form' );
        var results = document.getElementById( 'song-credits-results' );
        var btn     = document.getElementById( 'song-credits-submit' );
        var reset   = document.getElementById( 'song-credits-reset' );

        if ( ! form || ! results || ! btn || ! reset ) {
            return;
        }

        reset.addEventListener( 'click', function () {
            form.reset();
            results.innerHTML = '';
            results.className = 'song-credits-results';
            form.querySelector( '[name="artist"]' ).focus();
        } );

        form.addEventListener( 'submit', function ( e ) {
            e.preventDefault();

            var artist = form.querySelector( '[name="artist"]' ).value.trim();
            var title  = form.querySelector( '[name="title"]' ).value.trim();
            if ( ! artist || ! title ) {
                return;
            }

            // UI: disable button, show spinner text.
            var originalLabel = btn.textContent;
            btn.disabled    = true;
            btn.textContent = root.songCreditsData.i18n.searching;
            btn.classList.add( 'song-credits-loading' );
            results.innerHTML   = '';
            results.className   = 'song-credits-results';

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
                } else {
                    showError( json.data && json.data.message
                        ? json.data.message
                        : root.songCreditsData.i18n.noResults );
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
        } );

        autoLookupFromQuery();

        /* ---- Renderers -------------------------------------------- */

        function autoLookupFromQuery() {
            var lookup = extractLookupParams( root.location ? root.location.search : '' );
            if ( ! lookup.artist || ! lookup.title ) {
                return;
            }

            form.querySelector( '[name="artist"]' ).value = lookup.artist;
            form.querySelector( '[name="title"]' ).value = lookup.title;
            form.dispatchEvent( new Event( 'submit', { cancelable: true } ) );
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

        function renderCredits( data ) {
            results.innerHTML = '';
            results.className = 'song-credits-results song-credits-has-results';

            // Header.
            var header   = el( 'div', 'song-credits-header' );
            var titleEl  = el( 'h2',  'song-credits-song-title',  data.title  || '' );
            var artistEl = el( 'h3',  'song-credits-artist-name', data.artist || '' );
            var yearEl   = el( 'p',   'song-credits-year', data.year ? 'Released ' + data.year : '' );
            header.appendChild( titleEl );
            header.appendChild( artistEl );
            if ( data.year ) {
                header.appendChild( yearEl );
            }
            results.appendChild( header );

            // Category grid.
            var grid = el( 'div', 'song-credits-grid' );
            var order = [ 'Songwriting', 'Performers', 'Production', 'Engineering', 'Other' ];
            var cats  = data.categories || {};
            var keys  = Object.keys( cats ).sort( function ( a, b ) {
                var ia = order.indexOf( a ), ib = order.indexOf( b );
                return ( ia < 0 ? 999 : ia ) - ( ib < 0 ? 999 : ib );
            } );

            keys.forEach( function ( cat ) {
                var entries = cats[ cat ];
                if ( ! entries || ! entries.length ) { return; }

                var section  = el( 'div', 'song-credits-category' );
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

            // Sources footer.
            if ( data.sources && data.sources.length ) {
                var src = el( 'div', 'song-credits-sources',
                    root.songCreditsData.i18n.sources + ' ' + data.sources.join( ', ' ) );
                results.appendChild( src );
            }

            results.scrollIntoView( { behavior: 'smooth', block: 'start' } );
        }

        function showError( msg ) {
            results.innerHTML = '';
            results.className = 'song-credits-results song-credits-error';
            var box = el( 'div', 'song-credits-error-message', msg );
            box.setAttribute( 'role', 'alert' );
            results.appendChild( box );
        }

        /** Tiny helper: create an element with class and optional text. */
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
