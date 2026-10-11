// "Variants as cards" in the blocks gallery.
//
// A story's args can change a block's whole layout, not just its colour, so the
// gallery can show every reproducible story as its own card. galleryCards()
// decides which cards exist; these tests load the real picker.js into jsdom.

const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { JSDOM } = require('jsdom');

const PICKER = fs.readFileSync(
    path.join(__dirname, '../../src/web/js/picker.js'),
    'utf8'
);

function load() {
    const dom = new JSDOM('<!DOCTYPE html><body></body>', { runScripts: 'outside-only' });
    dom.window.__componentGuideTest = {};
    dom.window.eval(PICKER);
    const galleryCards = dom.window.__componentGuideTest.galleryCards;
    assert.equal(typeof galleryCards, 'function', 'picker.js exposes galleryCards to tests');
    // Results come from another realm; compare plain copies.
    const run = (...args) => JSON.parse(JSON.stringify(galleryCards(...args)));
    return { window: dom.window, run };
}

const story = (id) => ({ id, title: id, previewUrl: '/p/' + id, prefill: null });

const items = [
    { type: 'hero', label: 'Hero' },
    { type: 'richText', label: 'Rich text' },
    { type: 'plain', label: 'No component' },
];
const comps = {
    hero: { title: 'Hero', stories: [story('centred'), story('split'), story('dark')] },
    richText: { title: 'Rich text', stories: [story('default')] },
};

const summary = (cards) => cards.map((c) => c.item.type + ':' + (c.story ? c.story.id : '-'));

test('off: one card per entry type, stories stay behind the switcher', (t) => {
    const { window, run } = load();
    t.after(() => window.close());

    assert.deepEqual(summary(run(items, comps, false)), ['hero:-', 'richText:-', 'plain:-']);
});

test('on: a block with several stories becomes one card per story, in story order', (t) => {
    const { window, run } = load();
    t.after(() => window.close());

    assert.deepEqual(summary(run(items, comps, true)), [
        'hero:centred', 'hero:split', 'hero:dark',
        'richText:-',
        'plain:-',
    ]);
});

test('on: each per-story card carries its own story and preview', (t) => {
    const { window, run } = load();
    t.after(() => window.close());

    const split = run(items, comps, true).find((c) => c.story && c.story.id === 'split');
    assert.equal(split.story.previewUrl, '/p/split');
    assert.equal(split.comp.title, 'Hero');
});

test('on: entry types without a component keep their plain card', (t) => {
    const { window, run } = load();
    t.after(() => window.close());

    const plain = run(items, comps, true).filter((c) => c.item.type === 'plain');
    assert.equal(plain.length, 1);
    assert.equal(plain[0].comp, undefined);
});
