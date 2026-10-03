// Prefill for the blocks gallery, against the markup Craft 5 actually renders.
//
// fillBlock() copies a story's args into the block an editor just added. Every
// field type Craft draws its own way is a place where an arg can vanish without
// an error: 1.5.1 fixed exactly that for Lightswitch, which keeps its value in a
// hidden input inside a <button>. These tests load the real picker.js into
// jsdom, so they exercise the shipped code, not a copy of it.

const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { JSDOM } = require('jsdom');

const PICKER = fs.readFileSync(
    path.join(__dirname, '../../src/web/js/picker.js'),
    'utf8'
);

const NAME = (handle) => `fields[entries][uid:x][fields][${handle}]`;
const encode = (value) => 'base64:' + Buffer.from(value).toString('base64');

// Loads picker.js into a fresh page and returns its fillBlock plus a block
// element (detached, so the picker's own DOM watcher never sees it).
// `jqueryData` stands in for Craft's jQuery.data() store, which is where
// Craft keeps its UI instances (Craft.LightSwitch, Selectize).
function load(markup, jqueryData) {
    const dom = new JSDOM('<!DOCTYPE html><body></body>', {
        runScripts: 'outside-only',
        pretendToBeVisual: true,
    });
    const { window } = dom;
    window.jQuery = (el) => ({
        data: (key) => (jqueryData ? jqueryData(el, key) : undefined),
    });
    window.__componentGuideTest = {};
    window.eval(PICKER);

    const fillBlock = window.__componentGuideTest.fillBlock;
    assert.equal(typeof fillBlock, 'function', 'picker.js exposes fillBlock to tests');

    const block = window.document.createElement('div');
    block.innerHTML = markup;
    return { window, block, fill: (prefill) => fillBlock(block, prefill) };
}

// Craft 5's _includes/forms/lightswitch.twig, trimmed to what matters.
const lightswitch = (handle, on) => `
    <button type="button" class="lightswitch${on ? ' on' : ''}" role="switch" aria-checked="${on}">
        <div class="lightswitch-container"><div class="handle"></div></div>
        <input type="hidden" name="${NAME(handle)}" value="${on ? '1' : ''}">
    </button>`;

test('Lightswitch, not yet initialised: a story turns it on', (t) => {
    const { window, block, fill } = load(
        lightswitch('reversed', false)
        + `<input type="text" name="${NAME('heading')}" value="">`
    );
    t.after(() => window.close());

    fill({ reversed: true, heading: 'Image + Text' });

    const sw = block.querySelector('.lightswitch');
    assert.ok(sw.classList.contains('on'), 'switch has the "on" class Craft.LightSwitch reads');
    assert.equal(sw.getAttribute('aria-checked'), 'true');
    assert.equal(sw.querySelector('input').value, '1');
    assert.equal(block.querySelector('input[type="text"]').value, 'Image + Text');
});

test('Lightswitch, not yet initialised: a story turns it off', (t) => {
    const { window, block, fill } = load(lightswitch('reversed', true));
    t.after(() => window.close());

    fill({ reversed: false });

    const sw = block.querySelector('.lightswitch');
    assert.ok(!sw.classList.contains('on'));
    assert.equal(sw.getAttribute('aria-checked'), 'false');
    assert.equal(sw.querySelector('input').value, '');
});

test('Lightswitch, already initialised: goes through Craft.LightSwitch', (t) => {
    const calls = [];
    const instance = {
        turnOn: () => calls.push('turnOn'),
        turnOff: () => calls.push('turnOff'),
    };
    const { window, fill } = load(lightswitch('reversed', false), (el, key) =>
        key === 'lightswitch' && el.classList && el.classList.contains('lightswitch')
            ? instance
            : undefined
    );
    t.after(() => window.close());

    fill({ reversed: true });
    fill({ reversed: false });

    assert.deepEqual(calls, ['turnOn', 'turnOff']);
});

test('Radio Buttons with encoded values: the matching option is checked', (t) => {
    const { window, block, fill } = load(
        ['left', 'right']
            .map((v) => `<input type="radio" name="${NAME('layout')}" value="${encode(v)}"${v === 'left' ? ' checked' : ''}>`)
            .join('')
    );
    t.after(() => window.close());

    fill({ layout: 'right' });

    const checked = block.querySelector('input[type="radio"]:checked');
    assert.equal(checked.value, encode('right'));
});

test('Dropdown with encoded values: the matching option is selected', (t) => {
    const { window, block, fill } = load(`
        <select name="${NAME('theme')}">
            <option value="${encode('light')}" selected>Light</option>
            <option value="${encode('dark')}">Dark</option>
        </select>`);
    t.after(() => window.close());

    fill({ theme: 'dark' });

    assert.equal(block.querySelector('select').value, encode('dark'));
});

test('Dropdown: an option the field does not offer leaves it alone', (t) => {
    const { window, block, fill } = load(`
        <select name="${NAME('theme')}">
            <option value="${encode('light')}" selected>Light</option>
            <option value="${encode('dark')}">Dark</option>
        </select>`);
    t.after(() => window.close());

    fill({ theme: 'sepia' });

    assert.equal(block.querySelector('select').value, encode('light'));
});

test('Number, URL, Email and Phone are filled like text', (t) => {
    const { window, block, fill } = load(`
        <input type="number" name="${NAME('columns')}" value="">
        <input type="url" name="${NAME('link')}" value="">
        <input type="email" name="${NAME('contact')}" value="">
        <input type="tel" name="${NAME('phone')}" value="">`);
    t.after(() => window.close());

    fill({ columns: 3, link: 'https://example.com', contact: 'hi@example.com', phone: '+31 20 123 4567' });

    const value = (handle) => block.querySelector(`[name="${NAME(handle)}"]`).value;
    assert.equal(value('columns'), '3');
    assert.equal(value('link'), 'https://example.com');
    assert.equal(value('contact'), 'hi@example.com');
    assert.equal(value('phone'), '+31 20 123 4567');
});

test('A text field the editor already filled is not overwritten', (t) => {
    const { window, block, fill } = load(
        `<input type="text" name="${NAME('heading')}" value="Mine">`
        + `<textarea name="${NAME('body')}"></textarea>`
    );
    t.after(() => window.close());

    fill({ heading: 'From the story', body: 'Story body' });

    assert.equal(block.querySelector('input').value, 'Mine');
    assert.equal(block.querySelector('textarea').value, 'Story body');
});

test('Checkboxes are left alone (a known limit: several values per field)', (t) => {
    const { window, block, fill } = load(
        ['a', 'b']
            .map((v) => `<input type="checkbox" name="${NAME('tags')}[]" value="${v}">`)
            .join('')
    );
    t.after(() => window.close());

    fill({ tags: 'a' });

    assert.equal(block.querySelectorAll('input:checked').length, 0);
});
