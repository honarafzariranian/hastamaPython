"""Regenerate ``app/data/iran_ip_ranges.txt`` from the RIPE and APNIC registries.

The Iran-only access filter (``app/services/iran_access.py``) ships the Iranian
address space with the application so that a verdict never depends on the
network.  This script is how that file is (re)built: it downloads the two
authoritative delegated-statistics files, keeps every ``IR`` record that is
really allocated or assigned, and writes the ranges back out as CIDRs.

    python scripts/refresh_iran_ip_ranges.py          # rewrite the data file

The same routine is exposed to the operator as the "به‌روزرسانی فهرست" button on
``master-admin → system settings``; running it here is what the first, checked-in
copy of the file came from.  The download is deliberately direct: the machine's
proxy environment variables must not decide which list we end up shipping (the
same rule the outage probe follows).
"""
from __future__ import annotations

import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))

from app.services import iran_access  # noqa: E402


def main() -> int:
    print("Fetching the delegated statistics from:")
    for url in iran_access.REGISTRY_SOURCES:
        print("  " + url)

    try:
        result = iran_access.update_list_from_registries()
    except iran_access.IranAccessError as exc:
        print("FAILED: %s" % exc)
        return 1

    print("")
    print("Wrote %s" % result["list_path"])
    print("  ipv4 ranges : %s" % result["ranges_ipv4"])
    print("  ipv6 ranges : %s" % result["ranges_ipv6"])
    print("  bytes       : %s" % result["list_bytes"])
    if result.get("sources_failed"):
        print("  warnings    : %s" % ", ".join(result["sources_failed"]))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
