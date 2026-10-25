// After the gallery adds a block, Live Preview scrolls to it.
//
// The front end has no marker for the new block and the block may have no
// text, so followInPreview() records the preview's element tree before the
// click and, in the first refresh with more elements, takes the element that
// was not there — comparing from the start, because the gallery appends.
// These tests load the real picker.js into jsdom with a stand-in for Craft's
// Preview events.

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

function load(initialHtml) {
    const dom = new JSDOM('<!DOCTYPE html><body></body>', { runScripts: 'outside-only', pretendToBeVisual: true });
    const w = dom.window;
    const handlers = [];
    w.Garnish = {
        on: (target, type, h) => handlers.push({ target, type, h }),
        off: (target, type, h) => {
            const i = handlers.findIndex((x) => x.target === target && x.type === type && x.h === h);
            if (i !== -1) { handlers.splice(i, 1); }
        },
    };
    w.Craft = { Preview: function () {} };
    w.__componentGuideTest = {};
    w.eval(PICKER);

    const frame = w.document.createElement('iframe');
    frame.className = 'lp-preview';
    w.document.body.appendChild(frame);
    frame.contentDocument.body.innerHTML = initialHtml;

    // Craft replaces the iframe on every refresh and fires afterUpdateIframe
    // before the new one has loaded.
    const refresh = (html) => {
        const next = w.document.createElement('iframe');
        next.className = 'lp-preview';
        w.document.body.appendChild(next);
        handlers.filter((x) => x.target === w.Craft.Preview && x.type === 'afterUpdateIframe')
            .forEach((x) => x.h({ $iframe: [next] }));
        next.contentDocument.body.innerHTML = html;
        const scrolled = [];
        next.contentWindow.Element.prototype.scrollIntoView = function () { scrolled.push(this); };
        next.dispatchEvent(new w.Event('load'));
        frame.remove();
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

    return { w, handlers, refresh, added, follow: w.__componentGuideTest.followInPreview };
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
    const { follow, refresh, handlers } = load(page(HERO + CTA));
    follow();
    assert.equal(handlers.length, 1);

    // A template that renders nothing until its fields are filled.
    const first = refresh(page(HERO + CTA));
    await wait(600);
    assert.equal(first.length, 0);

    const second = refresh(page(HERO + CTA + SPACER));
    await wait(100);
    assert.equal(second.length, 0, 'not before Craft is done with the frame');
    await wait(600);
    assert.equal(second.length, 1);
    assert.equal(second[0].className, 'spacer');

    // The prefill causes one more refresh; the block is followed there too.
    const third = refresh(page(HERO + CTA + SPACER.replace('></div>', '><p>Filled</p></div>')));
    await wait(700);
    assert.equal(third.length, 1);
});

test('the editor scrolling in the preview ends it', async () => {
    const { w, follow, refresh, handlers } = load(page(HERO + CTA));
    const container = w.document.createElement('div');
    container.className = 'lp-iframe-container';
    w.document.body.appendChild(container);
    follow();
    container.dispatchEvent(new w.Event('wheel'));
    assert.equal(handlers.length, 0);
    const scrolled = refresh(page(HERO + CTA + SPACER));
    await wait(700);
    assert.equal(scrolled.length, 0);
});

test('does nothing without Live Preview', () => {
    const { w, follow, handlers } = load(page(HERO));
    w.document.querySelectorAll('iframe').forEach((f) => f.remove());
    follow();
    assert.equal(handlers.length, 0);
});
