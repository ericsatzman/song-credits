/**
 * Song Credits Lookup — frontend.
 *
 * Vanilla JS, no jQuery dependency.
 * Uses the Fetch API to POST to admin-ajax.php.
 */
( function () {
    'use strict';

    document.addEventListener( 'DOMContentLoaded', function () {
        if ( typeof window.songCreditsData === 'undefined' || ! window.songCreditsData ) {
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
            btn.textContent = songCreditsData.i18n.searching;
            btn.classList.add( 'song-credits-loading' );
            results.innerHTML   = '';
            results.className   = 'song-credits-results';

            var body = new FormData();
            body.append( 'action', 'song_credits_lookup' );
            body.append( 'nonce',  songCreditsData.nonce );
            body.append( 'artist', artist );
            body.append( 'title',  title );

            fetch( songCreditsData.ajaxUrl, {
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
                        : songCreditsData.i18n.noResults );
                }
            } )
            .catch( function () {
                showError( songCreditsData.i18n.error );
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
            var params = new URLSearchParams( window.location.search || '' );
            var artist = ( params.get( 'sc_artist' ) || params.get( 'artist' ) || '' ).trim();
            var title  = ( params.get( 'sc_title' ) || params.get( 'title' ) || '' ).trim();

            if ( ! artist || ! title ) {
                return;
            }

            form.querySelector( '[name="artist"]' ).value = artist;
            form.querySelector( '[name="title"]' ).value = title;
            form.dispatchEvent( new Event( 'submit', { cancelable: true } ) );
            clearLookupQueryParams();
        }

        function clearLookupQueryParams() {
            if ( ! window.history || ! window.history.replaceState ) {
                return;
            }

            var url = new URL( window.location.href );
            url.searchParams.delete( 'sc_artist' );
            url.searchParams.delete( 'sc_title' );
            url.searchParams.delete( 'artist' );
            url.searchParams.delete( 'title' );
            window.history.replaceState( {}, '', url.toString() );
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
                    songCreditsData.i18n.sources + ' ' + data.sources.join( ', ' ) );
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
} )();
