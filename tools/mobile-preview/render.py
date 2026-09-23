"""Render app/templates/admin.html with fake data into a static preview page.

Purpose: verify the mobile (/admin) redesign visually without touching the live
server or the database. Output: tools/mobile-preview/admin-preview.html plus a
tiny static server (serve.py) that maps /static/* to app/static/*.
"""
from __future__ import annotations

import json
from pathlib import Path

from jinja2 import Environment, FileSystemLoader, select_autoescape

ROOT = Path(__file__).resolve().parents[2]
OUT_DIR = Path(__file__).resolve().parent


def url_for(endpoint: str, **values):
    if endpoint == "static":
        return "/static/" + str(values.get("path", "")).lstrip("/")
    return "/" + endpoint


class Session(dict):
    def get(self, key, default=None):  # noqa: D102
        return dict.get(self, key, default)


class Request:
    def __init__(self):
        self.session = Session(username="admin", is_admin=True)


class User:
    def __init__(self, username, department, work_hours, substitute, employment_status="official", is_active="active", name="", last_name=""):
        self.username = username
        self.department = department
        self.work_hours = work_hours
        self.substitute = substitute
        self.employment_status = employment_status
        self.is_active = is_active
        self.name = name
        self.last_name = last_name
        self.password = ""


class Simple:
    def __init__(self, **kw):
        self.__dict__.update(kw)


def build_context():
    users = [
        User("ali.rezaei", "فنی و مهندسی", "۸:۰۰ - ۱۷:۰۰", "—", "official", "active", "علی", "رضایی"),
        User("maryam.hoseini", "منابع انسانی", "۹:۰۰ - ۱۸:۰۰", "علی رضایی", "official", "active", "مریم", "حسینی"),
        User("hossein.karimi", "مالی", "۷:۳۰ - ۱۶:۳۰", "—", "unofficial", "active", "حسین", "کریمی"),
        User("zahra.ahmadi", "فنی و مهندسی", "۸:۰۰ - ۱۷:۰۰", "—", "official", "active", "زهرا", "احمدی"),
        User("reza.mohammadi", "پشتیبانی", "۸:۰۰ - ۱۶:۰۰", "مریم حسینی", "unofficial", "inactive", "رضا", "محمدی"),
        User("sara.nasiri", "بازاریابی", "۱۰:۰۰ - ۱۹:۰۰", "—", "official", "active", "سارا", "نصیری"),
    ]

    def chart_row(username, percent, display):
        return Simple(username=username, percent=percent, display=display, row_number=1, total_pass_time=display)

    pass_chart = [chart_row(u, p, d) for u, p, d in [
        ("علی رضایی", 92, "۳۲:۱۵"),
        ("زهرا احمدی", 78, "۲۷:۴۰"),
        ("حسین کریمی", 64, "۲۱:۰۵"),
        ("سارا نصیری", 51, "۱۸:۳۰"),
        ("مریم حسینی", 38, "۱۲:۵۵"),
    ]]
    overtime_chart = [chart_row(u, p, d) for u, p, d in [
        ("زهرا احمدی", 88, "۴۱:۲۰"),
        ("علی رضایی", 71, "۳۳:۱۰"),
        ("مریم حسینی", 58, "۲۶:۴۵"),
        ("حسین کریمی", 47, "۱۹:۳۰"),
        ("سارا نصیری", 30, "۱۴:۰۵"),
    ]]

    return {
        "request": Request(),
        "url_for": url_for,
        "messages": [],
        "users": users,
        "reports": [],
        "pass_reports": [],
        "overtime_reports": [],
        "total_users": 42,
        "unique_departments": 6,
        "overtime_user_count": 17,
        "no_overtime_users": 25,
        "total_overtime_time": "۱۲۴:۳۰",
        "total_pass_time": "۳۱۸:۴۵",
        "average_overtime_per_user": "۰۷:۱۹",
        "average_pass_per_user": "۰۹:۴۲",
        "top_overtime_user": Simple(username="زهرا احمدی", total_ezafe_time="۴۱:۲۰"),
        "top_pass_user": Simple(username="علی رضایی", total_pass_time="۳۲:۱۵"),
        "top_department_name": "فنی و مهندسی",
        "top_department_count": 14,
        "pass_percent": 63,
        "overtime_percent": 41,
        "total_leave_taken": 128,
        "total_leave_requests": 34,
        "pass_chart_data": pass_chart,
        "overtime_chart_data": overtime_chart,
    }


def main() -> None:
    env = Environment(
        loader=FileSystemLoader(str(ROOT / "app" / "templates")),
        autoescape=select_autoescape(["html", "xml"]),
    )
    template = env.get_template("admin.html")
    html = template.render(**build_context())
    # The preview page is served by serve.py from the repo root, so /static/* works.
    out = OUT_DIR / "admin-preview.html"
    out.write_text(html, encoding="utf-8")
    print(f"rendered -> {out} ({len(html)} bytes)")
    (OUT_DIR / "preview-meta.json").write_text(json.dumps({"source": "admin.html"}, ensure_ascii=False), encoding="utf-8")


if __name__ == "__main__":
    main()
