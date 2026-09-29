from __future__ import annotations



def parse_time(value: str | None) -> tuple[int, int] | None:
    if not value:
        return None
    try:
        if isinstance(value, str):
            value = value.strip()
            if len(value) == 4 and value.isdigit():
                value = f"{value[:2]}:{value[2:]}"
            hour_str, minute_str = value.split(':', 1)
            hour = int(hour_str)
            minute = int(minute_str)
            return hour, minute
    except Exception:
        return None
    return None


def to_minutes(value: tuple[int, int] | None) -> int:
    if not value:
        return 0
    return value[0] * 60 + value[1]


def time_is_inside_range(clock_value: str | None, range_value: str | None) -> bool:
    clock = parse_time(clock_value)
    if not clock or not range_value or "-" not in range_value:
        return False

    start_value, end_value = range_value.replace(" ", "").split("-", 1)
    start = parse_time(start_value)
    end = parse_time(end_value)
    if not start or not end:
        return False

    current_minutes = to_minutes(clock)
    start_minutes = to_minutes(start)
    end_minutes = to_minutes(end)
    if end_minutes <= start_minutes:
        return current_minutes >= start_minutes or current_minutes <= end_minutes
    return start_minutes <= current_minutes <= end_minutes


def format_minutes(total_minutes: int) -> str:
    sign = "-" if total_minutes < 0 else ""
    total_minutes = abs(total_minutes)
    hours = total_minutes // 60
    minutes = total_minutes % 60
    return f"{sign}{hours:02}:{minutes:02}"


def normalize_work_hours(entry_time: str | None, work_start: str | None, work_end: str | None) -> tuple[str | None, str | None]:
    if not entry_time or not work_start or not work_end:
        return work_start, work_end

    start_time = parse_time(work_start)
    end_time = parse_time(work_end)
    if start_time and end_time and end_time[0] * 60 + end_time[1] <= start_time[0] * 60 + start_time[1]:
        return work_start, work_end

    original_range = f"{work_start}-{work_end}"
    swapped_range = f"{work_end}-{work_start}"
    entry_in_original = time_is_inside_range(entry_time, original_range)
    entry_in_swapped = time_is_inside_range(entry_time, swapped_range)

    if entry_in_original and entry_in_swapped:
        def range_duration(range_value: str) -> int:
            start_value, end_value = range_value.replace(" ", "").split("-", 1)
            start = parse_time(start_value)
            end = parse_time(end_value)
            if not start or not end:
                return float('inf')
            start_minutes = to_minutes(start)
            end_minutes = to_minutes(end)
            if end_minutes <= start_minutes:
                end_minutes += 24 * 60
            return end_minutes - start_minutes

        original_duration = range_duration(original_range)
        swapped_duration = range_duration(swapped_range)
        if swapped_duration < original_duration:
            return work_end, work_start

    if not entry_in_original and entry_in_swapped:
        return work_end, work_start

    return work_start, work_end


def build_presence_summary(entry_time: str | None, work_start: str | None, work_end: str | None, now_time: str | None = None):
    entry = parse_time(entry_time)
    work_start, work_end = normalize_work_hours(entry_time, work_start, work_end)
    work_start_time = parse_time(work_start)
    work_end_time = parse_time(work_end)

    if not entry or not work_start_time or not work_end_time:
        return {
            "check_in_time": entry_time or "--:--",
            "check_out_time": work_end or "--:--",
            "today_work_minutes": 0,
            "today_overtime_minutes": 0,
            "today_work_hours": "00:00",
            "overtime_hours": "00:00",
            "ring_green_percent": 0,
            "ring_blue_percent": 0,
            "ring_mode": "green",
            "status_text": "در انتظار ثبت ورود",
            "work_start": work_start,
            "work_end": work_end,
        }

    work_start_minutes = to_minutes(work_start_time)
    work_end_minutes = to_minutes(work_end_time)
    if work_end_minutes <= work_start_minutes:
        work_end_minutes += 24 * 60

    scheduled_work_minutes = max(0, work_end_minutes - work_start_minutes)
    today_work_minutes = 0
    overtime_minutes = 0
    green_percent = 0
    blue_percent = 0

    if now_time:
        now = parse_time(now_time)
        if now:
            current_minutes = to_minutes(now)
            entry_minutes = to_minutes(entry)
            effective_end_minutes = work_end_minutes
            if effective_end_minutes <= entry_minutes:
                effective_end_minutes += 24 * 60
            if effective_end_minutes > 24 * 60 and current_minutes < entry_minutes:
                current_minutes += 24 * 60

            timeline_duration = max(1, scheduled_work_minutes)
            if current_minutes <= work_start_minutes:
                green_percent = 0
                today_work_minutes = 0
            elif current_minutes < effective_end_minutes:
                today_work_minutes = min(max(0, current_minutes - entry_minutes), scheduled_work_minutes)
                green_percent = min(100, int((today_work_minutes / timeline_duration) * 100))
            else:
                today_work_minutes = min(max(0, effective_end_minutes - entry_minutes), scheduled_work_minutes)
                green_percent = min(100, int((today_work_minutes / max(timeline_duration, 1)) * 100))
                overtime_minutes = max(0, current_minutes - effective_end_minutes)
                blue_percent = min(100, int((overtime_minutes / max(timeline_duration, 1)) * 100))
    else:
        green_percent = 0

    ring_mode = "green"
    if blue_percent > 0:
        ring_mode = "blue"

    ring_progress = max(green_percent, blue_percent)

    return {
        "check_in_time": entry_time,
        "check_out_time": "--:--",
        "scheduled_work_minutes": scheduled_work_minutes,
        "today_work_minutes": today_work_minutes,
        "today_overtime_minutes": overtime_minutes,
        "today_work_hours": format_minutes(today_work_minutes),
        "overtime_hours": format_minutes(overtime_minutes),
        "ring_green_percent": green_percent,
        "ring_blue_percent": blue_percent,
        "ring_progress": ring_progress,
        "ring_mode": ring_mode,
        "status_text": "در حال انجام کار",
        "normalized_work_start": work_start,
        "normalized_work_end": work_end,
    }
