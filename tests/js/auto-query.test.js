const test = require('node:test');
const assert = require('node:assert/strict');

const { extractLookupParams } = require('../../assets/js/song-credits.js');

test('extractLookupParams prefers sc_* params', () => {
    const out = extractLookupParams('?sc_artist=The%20Who&sc_title=Who%20Are%20You&artist=Ignored&title=Ignored');
    assert.equal(out.artist, 'The Who');
    assert.equal(out.title, 'Who Are You');
});

test('extractLookupParams falls back to artist/title params', () => {
    const out = extractLookupParams('?artist=Stevie%20Wonder&title=Superstition');
    assert.equal(out.artist, 'Stevie Wonder');
    assert.equal(out.title, 'Superstition');
});

test('extractLookupParams returns empty values when query is incomplete', () => {
    const out = extractLookupParams('?sc_artist=OnlyArtist');
    assert.equal(out.artist, 'OnlyArtist');
    assert.equal(out.title, '');
});
