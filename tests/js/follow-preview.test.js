// After the gallery adds a block, Live Preview scrolls to it.
//
// The front end has no marker for the new block and the block may have no
// text, so followInPreview() records the preview's element tree before the
// click and, in the first refresh with more elements, takes the element that
// was not there — comparing from the start, because the gallery appends.
// These tests load the real picker.js into jsdom with a stand-in for Craft 6's
// Live Preview pane (no Craft.Preview events: the gallery polls the preview).

const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { JSDOM } = require('jsdom');

const PICKER = fs.readFileSync(path.join(__dirname, '../../src/web/js/picker.js'), 'utf8');

const page = (blocks, after = '<section class="newsletter"><h2>Stay in the loop</h2></section>') => `
<header class="site"><nav><a href="/">Velo</a></nav></header>
<main class="blocks">${blocks}${after}</main>
<footer class="site"><p>Velo Studio</p></footer>
<script>var x = 1;</script>`;

const HERO = '<section class="hero"><h1>Ride better</h1></section>';
const CTA = '<section class="cta"><h2>Ready?</h2><a class="btn">Book</a></section>';
const SPACER = '<div class="spacer"></div>';

function load(initialHtml, { livePreview = true } = {}) {
    const dom = new JSDOM('<!DOCTYPE html><body></body>', { runScripts: 'outside-only', pretendToBeVisual: true });
    const w = dom.window;
    // Craft 6: no Craft.Preview events. Live Preview is a Vue pane; the
    // gallery finds the preview as the largest visible same-origin iframe.
    w.Craft = { t: (c, m) => m };
    w.__componentGuideTest = {};
    w.eval(PICKER);

    if (livePreview) {
        const pane = w.document.createElement('div');
        pane.className = 'lp-editor-pane';
        w.document.body.appendChild(pane);
    }

    // jsdom does no layout: give each frame a size and a parent to count as visible.
    const visible = (f) => {
        Object.defineProperty(f, 'offsetParent', { get: () => w.document.body });
        f.getBoundingClientRect = () => ({ width: 1000, height: 700, top: 0, left: 0, right: 1000, bottom: 700 });
        return f;
    };

    let frame = visible(w.document.createElement('iframe'));
    w.document.body.appendChild(frame);
    frame.contentDocument.body.innerHTML = initialHtml;

    // Craft 6 may swap the frame on a refresh; the gallery polls for it.
    const refresh = (html) => {
        const next = visible(w.document.createElement('iframe'));
        w.document.body.appendChild(next);
        next.contentDocument.body.innerHTML = html;
        const scrolled = [];
        next.contentWindow.Element.prototype.scrollIntoView = function () { scrolled.push(this); };
        frame.remove();
        frame = next;
        return scrolled;
    };

    const added = (beforeHtml, afterHtml) => {
        const a = w.document.createElement('div');
        const b = w.document.createElement('div');
        a.innerHTML = beforeHtml;
        b.innerHTML = afterHtml;
        const el = w.__componentGuideTest.addedElement(a, b);
        return el ? el.outerHTML : null;
    };

    return { w, refresh, added, frame: () => frame, follow: w.__componentGuideTest.followInPreview };
}

const wait = (ms) => new Promise((r) => setTimeout(r, ms));

test('finds an appended block with no text, before a static section', () => {
    const { added } = load('');
    assert.equal(added(page(HERO), page(HERO + SPACER)), SPACER);
});

test('finds the new block when it is the same kind as the one before it', () => {
    const { added } = load('');
    const marked = CTA.replace('class="cta"', 'class="cta" data-new="1"');
    assert.equal(added(page(HERO + CTA), page(HERO + CTA + marked)), marked);
    // …and with nothing after the page builder.
    assert.equal(added(page(HERO + CTA, ''), page(HERO + CTA + marked, '')), marked);
});

test('finds it when every block is wrapped', () => {
    const { added } = load('');
    const wrap = (b) => `<div class="block">${b}</div>`;
    assert.equal(added(page(wrap(HERO)), page(wrap(HERO) + wrap(SPACER))), wrap(SPACER));
});

test('nothing added, nothing found', () => {
    const { added } = load('');
    assert.equal(added(page(HERO + CTA), page(HERO + CTA.replace('Ready?', 'Ready now?'))), null);
});

test('scrolls once a refresh shows the block and the frame has settled', async () => {
    const { follow, refresh } = load(page(HERO + CTA));
    follow();

    // A template that renders nothing until its fields are filled.
    const first = refresh(page(HERO + CTA));
    await wait(600);
    assert.equal(first.length, 0);

    const second = refresh(page(HERO + CTA + SPACER));
    await wait(100);
    assert.equal(second.length, 0, 'not before the page has settled');
    await wait(800);
    assert.equal(second.length, 1);
    assert.equal(second[0].className, 'spacer');
});

test('the editor scrolling in the preview ends it', async () => {
    const { w, follow, refresh, frame } = load(page(HERO + CTA));
    follow();
    frame().contentDocument.dispatchEvent(new w.Event('wheel'));
    const scrolled = refresh(page(HERO + CTA + SPACER));
    await wait(900);
    assert.equal(scrolled.length, 0);
});

test('does nothing without Live Preview', async () => {
    const { follow, refresh } = load(page(HERO), { livePreview: false });
    follow();
    const scrolled = refresh(page(HERO + SPACER));
    await wait(900);
    assert.equal(scrolled.length, 0);
});
