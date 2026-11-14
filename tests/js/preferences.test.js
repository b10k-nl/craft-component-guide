// The gallery's remembered settings: grouping, Variants as cards, collapsed
// groups. Kept in localStorage since 1.13.0; the cookies they used to live in are
// carried over once and expired, so an editor's settings survive the update.

const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { JSDOM } = require('jsdom');

const PICKER = fs.readFileSync(path.join(__dirname, '../../src/web/js/picker.js'), 'utf8');

function load(cookies = []) {
    const dom = new JSDOM('<!DOCTYPE html><body></body>', { runScripts: 'outside-only', url: 'https://cp.example.test/admin/entries' });
    cookies.forEach((c) => { dom.window.document.cookie = c + '; path=/'; });
    dom.window.__componentGuideTest = {};
    dom.window.eval(PICKER);
    return { window: dom.window, prefs: dom.window.__componentGuideTest.preferences };
}

const stored = (window) => JSON.parse(window.localStorage.getItem('componentGuide.picker') || '{}');

test('defaults: grouped, one card per block, nothing collapsed', () => {
    const { prefs } = load();
    assert.equal(prefs.isGroupingEnabled(), true);
    assert.equal(prefs.isSplitEnabled(), false);
    assert.deepEqual(Object.keys(prefs.getCollapsedGroups()), []);
});

test('settings go to localStorage, not cookies', () => {
    const { window, prefs } = load();
    prefs.setGroupingEnabled(false);
    prefs.setSplitEnabled(true);
    prefs.setCollapsedGroups({ Heroes: true, Lists: false });

    assert.deepEqual(stored(window), { grouped: false, split: true, collapsed: ['Heroes'] });
    assert.equal(window.document.cookie, '');
    assert.equal(prefs.isGroupingEnabled(), false);
    assert.equal(prefs.isSplitEnabled(), true);
    assert.deepEqual(Object.keys(prefs.getCollapsedGroups()), ['Heroes']);
});

test('the old cookies are carried over once, then expired', () => {
    const { window, prefs } = load([
        'cg-picker-grouped=0',
        'cg-picker-split=1',
        'cg-picker-collapsed=' + encodeURIComponent(JSON.stringify(['Heroes', 'Footers'])),
    ]);

    assert.equal(prefs.isGroupingEnabled(), false);
    assert.equal(prefs.isSplitEnabled(), true);
    assert.deepEqual(Object.keys(prefs.getCollapsedGroups()), ['Heroes', 'Footers']);
    assert.deepEqual(stored(window), { grouped: false, split: true, collapsed: ['Heroes', 'Footers'] });
    assert.equal(window.document.cookie, '', 'the cookies are gone');
});

test('a broken old cookie falls back to the default', () => {
    const { prefs } = load(['cg-picker-collapsed=%7Bnot-json']);
    assert.deepEqual(Object.keys(prefs.getCollapsedGroups()), []);
});
