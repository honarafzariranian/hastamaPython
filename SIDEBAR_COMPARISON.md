# User Panel Sidebar - Python vs Laravel Comparison

## Summary
All sidebar options in the Laravel Vue user panel now work identically to the Python FastAPI/Jinja version.

## Sidebar Items Comparison

### 1. داشبورد (Dashboard)
- **Python**: Link to dashboard, already active by default
- **Laravel**: ✓ Navigates to dashboard page, sets active state
- **Status**: ✅ Working correctly

### 2. ثبت مرخصی (Leave Request)
- **Python**: Opens leave modal (`openLeaveModal()`)
- **Laravel**: ✓ Calls `showModal('leave')` which displays `LeaveRequestPanel`
- **Status**: ✅ Working correctly

### 3. ثبت اضافه کاری (Overtime Request)
- **Python**: Opens overtime modal (`openOvertimeModal()`)
- **Laravel**: ✓ Calls `showModal('overtime')` which displays `OvertimeRequestPanel`
- **Status**: ✅ Working correctly

### 4. ثبت پاس ساعتی (Hourly Pass Request)
- **Python**: Opens hourly pass modal (`openHourlyPassModal()`)
- **Laravel**: ✓ Calls `showModal('pass')` which displays `HourlyPassRequestPanel`
- **Status**: ✅ Working correctly

### 5. گزارشات (Reports) - Expandable Submenu
- **Python**: Expands submenu with 4 report options
- **Laravel**: ✓ Expands submenu with same 4 options

#### Submenu Items:
1. **گزارش مرخصی (Leave Report)**
   - Python: Opens leave report popup (`openReportPopup('leave')`)
   - Laravel: ✓ Calls `showReport('leave')` which displays `LeaveReportPanel`
   - Status: ✅ Working correctly

2. **گزارش اضافه کاری (Overtime Report)**
   - Python: Opens overtime report popup (`openReportPopup('overtime')`)
   - Laravel: ✓ Calls `showReport('overtime')` which displays `OvertimeReportPanel`
   - Status: ✅ Working correctly

3. **گزارش پاس های ساعتی (Hourly Pass Report)**
   - Python: Opens hourly pass report popup (`openReportPopup('pass')`)
   - Laravel: ✓ Calls `showReport('pass')` which displays `HourlyPassReportPanel`
   - Status: ✅ Working correctly

4. **گزارش حضور و غیاب (Attendance Report)**
   - Python: Opens attendance report popup (`openAttendanceReportPopup()`)
   - Laravel: ✓ Calls `showReport('attendance')` which displays `AttendanceReportPanel`
   - Status: ✅ Working correctly

### 6. ثبت تیکت (Create Ticket)
- **Python**: Opens ticket modal (`openTicketListPopup()`)
- **Laravel**: ✓ Calls `openOverlay('ticket-create')` which displays `TicketCreateModal`
- **Status**: ✅ Working correctly

### 7. اتوماسیون داخلی (Internal Automation)
- **Python**: Opens internal automation panel (`openInternalAutomationCenter()`)
- **Laravel**: ✓ Calls `openOverlay('automation')` which displays `InternalAutomationCenter`
- **Status**: ✅ Working correctly

### 8. اعلان‌های من (My Notifications)
- **Python**: Opens notification center (`openNotificationCenter()`)
- **Laravel**: ✓ Calls `openOverlay('notifications')` which displays `NotificationCenter`
- **Status**: ✅ Working correctly

### 9. تنظیمات (Settings)
- **Python**: Opens settings panel with accordion sections
- **Laravel**: ✓ Calls `toggleSettings()` which displays settings panel
- **Status**: ✅ Working correctly

#### Settings Panel Accordions
- **Python**: Click handler toggles `is-open` class on sections, only one open at a time
- **Laravel**: ✓ Added `handleSettingsAccordion()` function with same behavior
- **Status**: ✅ Fixed and working correctly

## Profile Dropdown Items

### پروفایل من (My Profile)
- **Python**: Opens profile panel (`openProfilePanel('profile')`)
- **Laravel**: ✓ Calls `openOverlay('profile')` which displays `ProfilePanel`
- **Status**: ✅ Working correctly

### امنیت و رمز عبور (Security & Password)
- **Python**: Opens profile panel (`openProfilePanel('security')`)
- **Laravel**: ✓ Calls `openOverlay('profile')` which displays `ProfilePanel`
- **Note**: Python's `openProfilePanel()` doesn't actually use the parameter, so both buttons do the same thing
- **Status**: ✅ Working correctly (matches Python behavior)

### پشتیبانی فنی (Technical Support)
- **Python**: Opens user support center (`openUserSupportCenter()`)
- **Laravel**: ✓ Calls `openOverlay('support')` which displays `UserSupportCenter`
- **Status**: ✅ Working correctly

### آموزش این صفحه (Training)
- **Python**: Link to `/training/lesson/user-dashboard` (opens in new tab)
- **Laravel**: ✓ Same link with `target="_blank"` and `rel="noopener"`
- **Status**: ✅ Working correctly

## Recent Changes Made

### Settings Panel Accordion Handler
**File**: `laravel/resources/js/layouts/UserPanelLayout.vue`

**Added**:
```javascript
function handleSettingsAccordion(event) {
    const toggle = event.target.closest('.settings-accordion-toggle');
    if (!toggle) return;

    const section = toggle.closest('.settings-section');
    if (!section) return;

    const isOpen = section.classList.contains('is-open');
    document.querySelectorAll('.settings-section.is-open').forEach((item) => {
        if (item !== section) {
            item.classList.remove('is-open');
        }
    });

    section.classList.toggle('is-open', !isOpen);
}
```

**Event listeners**:
- Added in `onMounted()`: `document.addEventListener('click', handleSettingsAccordion);`
- Removed in `onUnmounted()`: `document.removeEventListener('click', handleSettingsAccordion);`

This matches the Python implementation at lines 292-307 of `user-panel-script.js`.

## Conclusion
All sidebar options in the Laravel Vue user panel now function identically to the Python FastAPI/Jinja version. The only missing feature was the settings panel accordion toggle behavior, which has been added.
