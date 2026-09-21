"""Offline preview renderer for the Hastama admin panel.

Renders app/templates/admin.html with mock data (no SQL Server needed) so the
dashboard redesign can be inspected/screenshotted in a real browser.

Usage:
    python preview/render.py     # writes preview/out/index.html (+ out-empty/)
"""

from __future__ import annotations

import json
import shutil
from pathlib import Path

from jinja2 import Environment, FileSystemLoader, select_autoescape

REPO = Path(__file__).resolve().parents[1]
OUT = Path(__file__).resolve().parent / "out"

FA = "۰۱۲۳۴۵۶۷۸۹"


def fa(value) -> str:
    return str(value).translate(str.maketrans("0123456789", FA))


PASS_CHART = [
    {"username": "it", "display": "۰۷:۱۱", "percent": 100},
    {"username": "m.rezaei", "display": "۰۵:۴۰", "percent": 78},
    {"username": "s.ahmadi", "display": "۰۴:۱۲", "percent": 58},
    {"username": "h.karimi", "display": "۰۲:۳۵", "percent": 36},
    {"username": "n.hosseini", "display": "۰۱:۲۰", "percent": 19},
]

OVERTIME_CHART = [
    {"username": "it", "display": "۱۵:۰۵", "percent": 100},
    {"username": "m.rezaei", "display": "۱۱:۳۰", "percent": 76},
    {"username": "s.ahmadi", "display": "۰۸:۴۵", "percent": 58},
    {"username": "h.karimi", "display": "۰۵:۱۰", "percent": 34},
    {"username": "n.hosseini", "display": "۰۲:۰۰", "percent": 13},
]


class TopUser:
    def __init__(self, username, time_value, extra=None):
        self.username = username
        self.total_ezafe_time = time_value
        self.total_pass_time = time_value
        self.extra = extra


class Session(dict):
    def get(self, key, default=None):
        return super().get(key, default)


class Request:
    method = "GET"

    def __init__(self):
        self.session = Session(username="admin")


CONTEXT = {
    "request": Request(),
    "users": [],
    "reports": [],
    "overtime_reports": [],
    "pass_reports": [],
    "user_options": [],
    "total_users": fa(16),
    "total_pass_time": "۰۷:۱۱",
    "total_overtime_time": "۱۵:۰۵",
    "total_leave_taken": fa(0),
    "total_leave_requests": fa(0),
    "unique_departments": fa(13),
    "average_overtime_per_user": "۰۰:۵۶",
    "average_pass_per_user": "۰۰:۲۶",
    "overtime_user_count": fa(1),
    "no_overtime_users": fa(15),
    "top_department_name": "حسابداری",
    "top_department_count": fa(3),
    "top_overtime_user": TopUser("it", "۱۵:۰۵"),
    "top_pass_user": TopUser("it", "۰۷:۱۱"),
    "subscription_days_left": fa(231),
    "subscription_end_date": fa("1405/12/29"),
    "subscription_remaining_percent": fa(63),
    "pass_percent": fa(44),
    "overtime_percent": fa(94),
    "pass_chart_data": PASS_CHART,
    "overtime_chart_data": OVERTIME_CHART,
}


def build_env() -> Environment:
    env = Environment(
        loader=FileSystemLoader(str(REPO / "app" / "templates")),
        autoescape=select_autoescape(["html"]),
    )
    env.globals["url_for"] = lambda name, path="": f"/static/{path}"
    return env


def render(out_dir: Path, context: dict, name: str = "index.html") -> None:
    html = build_env().get_template("admin.html").render(**context)
    (out_dir / name).write_text(html, encoding="utf-8")


def main() -> None:
    OUT.mkdir(parents=True, exist_ok=True)
    static_link = OUT / "static"
    if static_link.is_symlink() or static_link.exists():
        if static_link.is_dir() and not static_link.is_symlink():
            shutil.rmtree(static_link)
        else:
            static_link.unlink()
    static_link.symlink_to(REPO / "app" / "static", target_is_directory=True)

    render(OUT, CONTEXT)

    # نسخهٔ بدون داده (empty state) برای بررسی کارت‌های خالی
    empty_out = OUT.parent / "out-empty"
    empty_out.mkdir(parents=True, exist_ok=True)
    link = empty_out / "static"
    if link.exists() or link.is_symlink():
        link.unlink()
    link.symlink_to(REPO / "app" / "static", target_is_directory=True)
    empty = dict(CONTEXT)
    empty.update({
        "pass_chart_data": [],
        "overtime_chart_data": [],
        "top_overtime_user": None,
        "top_pass_user": None,
        "top_department_name": "-",
        "top_department_count": fa(0),
        "total_users": fa(0),
        "total_pass_time": "۰۰:۰۰",
        "total_overtime_time": "۰۰:۰۰",
        "total_leave_taken": fa(0),
        "total_leave_requests": fa(0),
        "unique_departments": fa(0),
        "average_overtime_per_user": "۰۰:۰۰",
        "average_pass_per_user": "۰۰:۰۰",
        "overtime_user_count": fa(0),
        "no_overtime_users": fa(0),
        "pass_percent": fa(0),
        "overtime_percent": fa(0),
    })
    render(empty_out, empty)
    (OUT / "context.json").write_text(json.dumps({k: str(v) for k, v in CONTEXT.items()}, ensure_ascii=False, indent=2), encoding="utf-8")
    print(f"rendered -> {OUT / 'index.html'}")


if __name__ == "__main__":
    main()
