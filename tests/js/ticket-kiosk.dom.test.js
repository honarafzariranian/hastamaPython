/* Run with: node tests/js/ticket-kiosk.dom.test.js */
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { JSDOM } = require('jsdom');

const html = fs.readFileSync(path.join(__dirname, '../../app/templates/ticket-kiosk.html'), 'utf8');
const timers = [];
const requests = [];
const dom = new JSDOM(html, {
    url: 'http://localhost/ticket-kiosk',
    runScripts: 'dangerously',
    beforeParse(window) {
        window.setInterval = () => 0;
        window.setTimeout = callback => { timers.push(callback); return timers.length; };
        window.fetch = async (url, options) => {
            requests.push({ url, options });
            return { json: async () => ({ tickets: [] }) };
        };
    },
});
const { window } = dom;
const doc = window.document;
const visible = id => doc.getElementById(id).classList.contains('visible');
const flushTimers = () => { while (timers.length) timers.shift()(); };
const closeButton = doc.querySelector('.k-patient-header button');
assert.ok(closeButton.getAttribute('aria-label'));
assert.equal(closeButton.type, 'button');

// Both the admission choice and every patient step have a way home.
window.startFlow('پذیرش');
assert.ok(visible('choiceOverlay'));
doc.querySelector('.k-choice-header button').click();
assert.ok(!visible('choiceOverlay'));

for (const choose of ['chooseFreeTest', 'choosePrescription']) {
    for (let step = 0; step < 6; step++) {
        window.startFlow('پذیرش');
        window[choose]();
        window.showStep(step);
        flushTimers();
        assert.ok(visible('patientOverlay'));
        closeButton.click();
        assert.ok(!visible('patientOverlay'));
        assert.ok(!visible('choiceOverlay'));
        assert.ok(!visible('virtualKeyboard'));
        assert.equal(window.activeInput, null);
        assert.equal(window.pendingService, null);
    }
}

// Closing before delayed autofocus must not bring the keyboard back.
window.showPatientForm('تست آزاد', null);
closeButton.click();
flushTimers();
assert.ok(!visible('virtualKeyboard'));
assert.equal(window.activeInput, null);

// The same close control works when entering through a checkup.
window.chooseCheckup();
window.takeCheckupTicket(window.CHECKUPS[0].id);
closeButton.click();
flushTimers();
assert.ok(!visible('checkupOverlay'));
assert.ok(!visible('patientOverlay'));
assert.ok(!visible('virtualKeyboard'));
assert.ok(!requests.some(request => request.url === '/api/queue/take'));

// Reopening starts clean, and only keyboard rows override the RTL layout.
window.showPatientForm('نسخه‌دار', null);
flushTimers();
assert.equal(window.currentStep, 0);
assert.equal(doc.getElementById('pf-name').value, '');
assert.equal(window.getComputedStyle(doc.body).direction, 'rtl');
for (const shifted of [false, true]) {
    window.kbShift = shifted;
    window.openKeyboard(doc.getElementById('pf-name'));
    const rows = [...doc.querySelectorAll('.k-kb-row')];
    assert.equal(rows.length, 4);
    rows.forEach(row => assert.equal(window.getComputedStyle(row).direction, 'ltr'));
    const expected = shifted ? window.PERSIAN_SHIFT_ROWS : window.PERSIAN_ROWS;
    rows.slice(0, 3).forEach((row, index) => {
        assert.deepEqual([...row.children].map(key => key.textContent), Array.from(expected[index]));
    });
}
window.kbShift = false;
window.openKeyboard(doc.getElementById('pf-name'));
const firstRow = doc.querySelector('.k-kb-row');
assert.equal(firstRow.firstElementChild.textContent, 'ض'); // Left edge with LTR flex layout.
assert.equal(firstRow.lastElementChild.textContent, 'چ'); // Right edge (first in Persian).
firstRow.lastElementChild.click();
assert.equal(doc.getElementById('pf-name').value, 'چ');
window.kbBackspace();
assert.equal(doc.getElementById('pf-name').value, '');
closeButton.click();

dom.window.close();
console.log('Ticket kiosk DOM tests passed: close flows, delayed focus, keyboard order and typing.');
