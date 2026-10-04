# User Panel Laravel Migration - Changes Summary

## Overview
This document summarizes all changes made to align the Laravel Vue user panel with the Python FastAPI/Jinja version.

## Files Modified

### 1. DashboardPage.vue
**Location:** `laravel/resources/js/pages/user/DashboardPage.vue`

**Changes:**
- **Calendar Widget**: Implemented full Jalali calendar rendering with:
  - Day grid with proper month offsets
  - Friday highlighting (`.red-day` class)
  - Holiday marking (`.holiday` class)
  - Today highlighting (`.today` class)
  - Previous/next month navigation
  - Persian month names and digit conversion

- **Progress Ring Animation**: Ported from Python JS with:
  - Dual-ring SVG animation (green=work hours, blue=overtime)
  - Real-time calculation based on check-in/out times and work schedule
  - Smooth `requestAnimationFrame` animation with easing
  - Auto-refresh every 30 seconds
  - Proper handling of overnight shifts

- **Real Data Fetching**: Replaced hardcoded zeros with:
  - Leave balance: Computed from `GET /get_leave_info` (approved count and remaining days)
  - Monthly overtime: Summed from `GET /get_overtime_requests` filtered by user
  - Today's delay: Calculated from check-in time vs work start time

**Key Functions Added:**
- `calendarDays` computed property - generates calendar grid cells
- `prevMonth()`, `nextMonthNav()` - calendar navigation
- `parseClock()`, `getCurrentMinutes()` - time parsing utilities
- `normalizeShiftMinutes()`, `getShiftTimelineState()` - shift calculation
- `updatePresenceRing()` - ring animation with easing
- `loadLeaveBalance()`, `loadMonthlyOvertime()`, `loadTodayDelay()` - data fetchers

### 2. LeaveRequestPanel.vue
**Location:** `laravel/resources/js/pages/user/panels/LeaveRequestPanel.vue`

**Changes:**
- Replaced hardcoded `SUBSTITUTE_OPTIONS` array (11 names) with dynamic API fetch
- Added `loadSubstituteOptions()` function that calls `GET /get_users`
- Options now fetched on component mount
- Fallback to "بدون جانشین" (no substitute) if API fails

**Before:**
```javascript
const SUBSTITUTE_OPTIONS = [
    'بدون جانشین',
    'فاطمه سالاری فر',
    'مریم براتی',
    // ... 9 more hardcoded names
];
```

**After:**
```javascript
const substituteOptions = ref([{ value: '', label: 'بدون جانشین' }]);

async function loadSubstituteOptions() {
    const response = await api.get('/get_users', { baseURL: '' });
    const users = Array.isArray(response?.users) ? response.users : [];
    substituteOptions.value = [
        { value: '', label: 'بدون جانشین' },
        ...users.map((u) => ({ value: u.value, label: u.label || u.value })),
    ];
}
```

### 3. TicketPage.vue
**Location:** `laravel/resources/js/pages/user/TicketPage.vue`

**Changes:**
- Updated `submitEdit()` to handle 410 Gone status with user-friendly message
- Updated `confirmDelete()` to handle 410 Gone status with user-friendly message
- Both operations now show "ویرایش/حذف تیکت در این نسخه پشتیبانی نمی‌شود" when the legacy endpoints return 410

**Note:** The Laravel backend intentionally returns 410 Gone for `POST /update_ticket` and `POST /delete-ticket`. The normalized API (`PATCH /api/tickets/{id}`) only supports status/priority/category/assignee changes, not title/description/receiver editing. Ticket deletion is not supported in the normalized API.

### 4. LeaveReportPanel.vue
**Location:** `laravel/resources/js/pages/user/panels/LeaveReportPanel.vue`

**Changes:**
- Added `approvedCount` computed property - counts leaves with status "تایید شده"
- Added `remainingCount` computed property - sums approved leave days
- Template now displays real computed values instead of hardcoded "۰"

### 5. OvertimeReportPanel.vue
**Location:** `laravel/resources/js/pages/user/panels/OvertimeReportPanel.vue`

**Changes:**
- Added `monthlyOvertimeTotal` computed property
- Parses `daily_overtime` field (HH:MM format) from each record
- Sums all overtime minutes and formats as HH:MM with Persian digits
- Template now displays real total instead of hardcoded "۰"

### 6. HourlyPassReportPanel.vue
**Location:** `laravel/resources/js/pages/user/panels/HourlyPassReportPanel.vue`

**Changes:**
- Added `totalPassDuration` computed property
- Parses `pass_duration` field (HH:MM:SS format) from each record
- Sums all pass durations and formats as HH:MM with Persian digits
- Template now displays real total instead of hardcoded "۰"

### 7. UserPanelLayout.vue
**Location:** `laravel/resources/js/layouts/UserPanelLayout.vue`

**Changes:**
- Added notification polling with 60-second interval
- `loadUnreadCount()` now called every minute to keep badge updated
- Interval cleared on component unmount to prevent memory leaks

**Code Added:**
```javascript
onMounted(() => {
    loadUnreadCount().catch(() => {});
    const notifInterval = setInterval(() => {
        loadUnreadCount().catch(() => {});
    }, 60000);
    onUnmounted(() => {
        clearInterval(notifInterval);
    });
});
```

## Backend Endpoints Used

All changes use existing Laravel backend endpoints:

- `GET /get_today_date` - Current Jalali date
- `GET /get_user_info` - User profile data
- `GET /get_hozoor_today` - Today's attendance (check-in/out, work schedule, server time)
- `GET /get_leave_info` - User's leave requests
- `GET /get_overtime_requests` - All overtime requests (admin-scoped, filtered client-side)
- `GET /get_hourly_pass_requests` - All hourly pass requests (admin-scoped, filtered client-side)
- `GET /get_users` - User list for substitute picker
- `GET /get_receivers` - Admin usernames for ticket recipient picker
- `GET /notifications/unread-count` - Unread notification count

## Features Now Matching Python Version

✅ Calendar widget with Jalali dates, holidays, and today highlight
✅ Progress ring with real-time work hours and overtime animation
✅ Leave balance card showing approved/remaining counts
✅ Monthly overtime total computed from actual data
✅ Today's delay calculated from check-in vs work start
✅ Substitute picker populated from API (not hardcoded)
✅ Report panels showing real totals (leave, overtime, hourly pass)
✅ Notification badge refreshing every 60 seconds
✅ Ticket edit/delete showing clear "not supported" messages

## Build Status

✅ Frontend build successful (`npm run build`)
✅ No TypeScript/ESLint errors
✅ All Vue components compile correctly

## Known Limitations

1. **Ticket Edit/Delete**: The Laravel backend's normalized API doesn't support editing ticket title/description/receiver or deleting tickets. The legacy endpoints return 410 Gone. Users see a clear message that these operations are not supported in this version.

2. **Leave Balance**: The Python version had server-side computed leave balances from the `leave_report` table. The Laravel version computes these client-side from `GET /get_leave_info`, which may differ slightly if there are pending requests.

3. **Overtime/Pass Totals**: Computed client-side from admin-scoped endpoints filtered by username. The Python version had server-side sums, but the results are equivalent.

## Testing Recommendations

1. **Calendar**: Verify month navigation, today highlight, Friday/holiday coloring
2. **Progress Ring**: Test with different check-in/out scenarios, verify animation smoothness
3. **Dashboard Cards**: Confirm leave balance, overtime, and delay show real data
4. **Leave Request**: Verify substitute dropdown populates from API
5. **Report Panels**: Open each report and verify totals are computed correctly
6. **Notifications**: Wait 60+ seconds and verify badge updates without page refresh
7. **Ticket Edit/Delete**: Verify clear error messages appear (not silent failures)
