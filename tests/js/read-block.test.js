// Reading a block back for "Save as my variant…".
//
// readBlock() is fillBlock() in reverse: what an editor typed, for the fields a
// variant can keep. If the two disagree about a field type, a saved variant
// either loses a value or puts back a different one — so the last test runs
// them into each other.

const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { JSDOM } = require('jsdom');

const PICKER = fs.readFileSync(path.join(__dirname, '../../src/web/js/picker.js'), 'utf8');

const NAME = (handle) => `fields[contentBlocks][entries][uid:a][fields][${handle}]`;
const encode = (value) => 'base64:' + Buffer.from(value).toString('base64');

function load(markup) {
    const dom = new JSDOM('<!DOCTYPE html><body></body>', { runScripts: 'outside-only', pretendToBeVisual: true });
    const { window } = dom;
    window.jQuery = () => ({ data: () => undefined });
    window.__componentGuideTest = {};
    window.eval(PICKER);
    const seam = window.__componentGuideTest;
    assert.equal(typeof seam.readBlock, 'function', 'picker.js exposes readBlock to tests');

    window.document.body.innerHTML = `<div class="matrixblock" data-type="hero">${markup}</div>`;
    const block = window.document.querySelector('.matrixblock');
    const plain = (x) => JSON.parse(JSON.stringify(x));
    return {
        window,
        block,
        read: (handles) => plain(seam.readBlock(block, handles)),
        fill: (prefill) => seam.fillBlock(block, prefill),
    };
}

const field = (label, inner) => `<div class="field"><div class="heading"><label>${label}</label></div>${inner}</div>`;
const text = (handle, value = '') => field(handle, `<input type="text" name="${NAME(handle)}" value="${value}">`);
const lightswitch = (handle, on) => field(handle, `
    <button type="button" class="lightswitch${on ? ' on' : ''}">
        <input type="hidden" name="${NAME(handle)}" value="${on ? '1' : ''}">
    </button>`);
const select = (handle, options, selected) => field(handle, `
    <select name="${NAME(handle)}">
        ${options.map((o) => `<option value="${encode(o)}"${o === selected ? ' selected' : ''}>${o}</option>`).join('')}
    </select>`);
const radios = (handle, options, checked) => field(handle, options.map((o) =>
    `<input type="radio" name="${NAME(handle)}" value="${encode(o)}"${o === checked ? ' checked' : ''}>`).join(''));
const richText = (handle, html) => field(handle, `<textarea class="hidden" name="${NAME(handle)}">${html}</textarea>`);
const plainText = (handle, value) => field(handle, `<textarea name="${NAME(handle)}">${value}</textarea>`);

test('text, switch, dropdown and radio come back decoded', (t) => {
    const { window, read } = load(
        text('heading', 'Two hours on the rig')
        + lightswitch('reversed', true)
        + select('theme', ['light', 'dark'], 'dark')
        + radios('size', ['small', 'large'], 'large')
    );
    t.after(() => window.close());

    const result = read(['heading', 'reversed', 'theme', 'size']);
    assert.deepEqual(result.values, { heading: 'Two hours on the rig', reversed: true, theme: 'dark', size: 'large' });
    assert.deepEqual(result.kept, ['heading', 'reversed', 'theme', 'size']);
    assert.deepEqual(result.skipped, []);
});

test('a switch that is off is saved as off, not left out', (t) => {
    const { window, read } = load(lightswitch('reversed', false));
    t.after(() => window.close());

    assert.deepEqual(read(['reversed']).values, { reversed: false });
});

test('rich text is named as not kept; plain multi-line text is kept', (t) => {
    const { window, read } = load(richText('bodyText', '<p>Hi</p>') + plainText('note', 'Line one'));
    t.after(() => window.close());

    const result = read(['bodyText', 'note']);
    assert.deepEqual(result.values, { note: 'Line one' });
    assert.deepEqual(result.skipped, ['bodyText']);
});

test('text over the prefill limit is not kept', (t) => {
    const { window, read } = load(text('heading', 'x'.repeat(501)));
    t.after(() => window.close());

    const result = read(['heading']);
    assert.deepEqual(result.values, {});
    assert.deepEqual(result.skipped, ['heading']);
});

test('a nested block with a field of the same name is not read', (t) => {
    const { window, read } = load(
        text('heading', 'Outer')
        + `<div class="matrixblock" data-type="card">${text('heading', 'Inner')}</div>`
    );
    t.after(() => window.close());

    assert.deepEqual(read(['heading']).values, { heading: 'Outer' });
});

test('fields the block does not have are simply absent', (t) => {
    const { window, read } = load(text('heading', 'Hi'));
    t.after(() => window.close());

    assert.deepEqual(read(['heading', 'eyebrow']).values, { heading: 'Hi' });
});

test('what readBlock saves, fillBlock puts back', (t) => {
    const { window, read, fill } = load(
        text('heading')
        + lightswitch('reversed', false)
        + select('theme', ['light', 'dark'], 'light')
    );
    t.after(() => window.close());

    const saved = { heading: 'Drop it off in the morning', reversed: true, theme: 'dark' };
    fill(saved);
    assert.deepEqual(read(Object.keys(saved)).values, saved);
});

test('rich text is written through the editor once it exists, not into the hidden textarea', async (t) => {
    const { window, block, fill } = load(richText('bodyText', ''));
    t.after(() => window.close());

    // CKEditor is created after Matrix inserts the block.
    const textarea = block.querySelector('textarea');
    let data = '';
    setTimeout(() => {
        const editable = window.document.createElement('div');
        editable.className = 'ck-editor__editable';
        editable.ckeditorInstance = { getData: () => data, setData: (html) => { data = html; } };
        textarea.parentElement.appendChild(editable);
    }, 120);

    fill({ bodyText: '<p>We start on the rig.</p>' });
    await new Promise((resolve) => setTimeout(resolve, 400));

    assert.equal(data, '<p>We start on the rig.</p>');
    assert.equal(textarea.value, '', 'the textarea is the editor’s to sync, not ours');
});

test('rich text the editor already holds is left alone', async (t) => {
    const { window, block, fill } = load(richText('bodyText', ''));
    t.after(() => window.close());

    let data = '<p>Typed by the editor</p>';
    const editable = window.document.createElement('div');
    editable.className = 'ck-editor__editable';
    editable.ckeditorInstance = { getData: () => data, setData: (html) => { data = html; } };
    block.querySelector('textarea').parentElement.appendChild(editable);

    fill({ bodyText: '<p>From the story</p>' });
    await new Promise((resolve) => setTimeout(resolve, 50));

    assert.equal(data, '<p>Typed by the editor</p>');
});
