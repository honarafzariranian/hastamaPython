const { JSDOM } = require('jsdom');
const fs = require('fs');
const path = require('path');
const assert = require('assert');

const adminHtml = fs.readFileSync(path.join(__dirname, '../../app/templates/admin.html'), 'utf8');
const adminJs = fs.readFileSync(path.join(__dirname, '../../app/static/js/admin.js'), 'utf8');

const cleanHtml = adminHtml
    .replace(/{%[\s\S]*?%}/g, '')
    .replace(/{{[\s\S]*?}}/g, '');

const dom = new JSDOM(cleanHtml, {
    url: 'http://localhost/admin',
    runScripts: 'dangerously'
});

const { window } = dom;

window.toJalaali = (y, m, d) => ({ jy: 1403, jm: 1, jd: 15 });
window.showSystemSuccess = (msg) => { window._lastSuccess = msg; };
window.showSystemError = (msg) => { window._lastError = msg; };
window.HastamaUX = {
    confirm: async () => true
};

const mockActiveShifts = [
    {
        id: 101,
        username: 'آي تي',
        jalali_year: 1403,
        jalali_month: 1,
        start_day: 1,
        end_day: 31,
        title: 'شيفت تستي',
        shanbeh: '13:00 - 08:00',
        yekshanbeh: '13:00 - 08:00',
        doshanbeh: '13:00 - 08:00',
        seshanbeh: '13:00 - 08:00',
        chaharshanbeh: '13:00 - 08:00',
        panjshanbeh: '13:00 - 08:00',
        jomeh: '13:00 - 08:00'
    }
];

const fetchCalls = [];

window.fetch = async (url, options = {}) => {
    fetchCalls.push({ url, options });
    if (url === '/get_active_shifts') {
        return {
            json: async () => ({
                success: true,
                shifts: mockActiveShifts,
                today: '15',
                year: 1403,
                month: 1
            })
        };
    }
    if (url === '/update_shift') {
        return {
            json: async () => ({
                success: true,
                message: 'شیفت با موفقیت به‌روزرسانی شد'
            })
        };
    }
    if (url.startsWith('/delete_shift/')) {
        return {
            json: async () => ({
                success: true,
                message: 'شیفت حذف شد'
            })
        };
    }
    return {
        json: async () => ({ success: true })
    };
};

try {
    window.eval(adminJs);
} catch (e) {
    // ignore startup side effects
}

async function runTests() {
    console.log('Running active shift edit tests...');

    // 1. Load active shifts
    window.loadActiveShifts();
    await new Promise(r => setTimeout(r, 50));

    const container = window.document.getElementById('shiftActiveCards');
    const card = container.querySelector('.shift-active-card');
    assert(card, 'Active shift card should be rendered');
    assert.strictEqual(card.getAttribute('role'), 'button', 'Card should have role=button');

    const editBtn = card.querySelector('.shift-active-card__edit-btn');
    assert(editBtn, 'Card should have an edit button');

    // 2. Open edit modal via editShift(101)
    window.editShift(101);

    const overlay = window.document.getElementById('shiftPopupOverlay');
    assert.strictEqual(overlay.style.display, 'flex', 'Overlay should be visible (display: flex)');

    const titleInput = window.document.getElementById('shiftTitle');
    assert.strictEqual(titleInput.value, 'شيفت تستي', 'Title should be pre-filled');

    const startDayInput = window.document.getElementById('shiftStartDay');
    assert.strictEqual(Number(startDayInput.value), 1, 'Start day should be 1');

    const endDayInput = window.document.getElementById('shiftEndDay');
    assert.strictEqual(Number(endDayInput.value), 31, 'End day should be 31');

    const shanbehInput = window.document.getElementById('shift_shanbeh');
    assert.strictEqual(shanbehInput.value, '13:00 - 08:00', 'Shanbeh hour should be pre-filled');

    const metaUser = window.document.getElementById('shiftPopupMetaUser');
    assert.strictEqual(metaUser.textContent, 'آي تي', 'Meta user badge should show username');

    const deleteModalBtn = window.document.getElementById('deleteShiftModalBtn');
    assert.strictEqual(deleteModalBtn.style.display, 'inline-flex', 'Delete button in modal should be visible when editing');

    // 3. Edit fields and save
    shanbehInput.value = '14:00 - 08:00';
    titleInput.value = 'شیفت ویرایش شده';

    fetchCalls.length = 0;
    window.saveShift();
    await new Promise(r => setTimeout(r, 50));

    const updateCall = fetchCalls.find(c => c.url === '/update_shift');
    assert(updateCall, 'fetch /update_shift should have been called');

    const payload = JSON.parse(updateCall.options.body);
    assert.strictEqual(payload.id, 101, 'Payload id should be 101');
    assert.strictEqual(payload.title, 'شیفت ویرایش شده', 'Payload title should be updated');
    assert.strictEqual(payload.shanbeh, '14:00 - 08:00', 'Payload shanbeh should be updated');
    assert.strictEqual(payload.start_day, 1, 'Payload start_day should be 1');
    assert.strictEqual(payload.end_day, 31, 'Payload end_day should be 31');

    const refreshCall = fetchCalls.find(c => c.url === '/get_active_shifts');
    assert(refreshCall, '/get_active_shifts should be called after save to refresh cards');

    assert.strictEqual(overlay.style.display, 'none', 'Overlay should close after saving');

    // 4. Test delete from modal
    window.editShift(101);
    assert.strictEqual(overlay.style.display, 'flex', 'Overlay should open again');

    fetchCalls.length = 0;
    await window.deleteShift();
    await new Promise(r => setTimeout(r, 50));

    const deleteCall = fetchCalls.find(c => c.url === '/delete_shift/101');
    assert(deleteCall, 'Should call /delete_shift/101');
    assert.strictEqual(overlay.style.display, 'none', 'Overlay should close after deletion');

    console.log('✓ All active shift edit and delete DOM tests passed!');
    process.exit(0);
}

runTests().catch(err => {
    console.error('Test failed:', err);
    process.exit(1);
});
