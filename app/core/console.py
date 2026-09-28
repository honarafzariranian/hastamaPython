"""Console encoding bootstrap for the Windows deployment.

The application is started at boot by the scheduled task ``HastamaServer``,
which redirects ``python -m uvicorn ... >> logs\\hastama-autostart.log``.  When
stdout/stderr are pipes, CPython picks the ANSI code page of the host (cp1252
here) instead of UTF-8, so a single ``print()`` of a Persian string raised::

    UnicodeEncodeError: 'charmap' codec can't encode characters ...

from inside the request handler, which uvicorn turned into an HTTP 500.  This
is how ``GET /get_hozoor/{username}`` failed for every non-Latin username
(``آی تی``, ``سالاری``, ...) while ASCII ones (``admin``, ``ali``) worked.

``app/__init__.py`` calls :func:`enable_utf8_output` so the streams are fixed
before any application module (or loguru sink) can write to them, and
:func:`safe_print` is the belt-and-braces variant for diagnostics whose text
comes from user input: logging must never be able to fail a request.
"""

from __future__ import annotations

import sys

# ``replace`` instead of ``strict``: when the encoding cannot represent a
# character we want an ugly log line, not an exception.  Handlers must never
# fail because of what they were trying to report.
STREAM_ERRORS = "replace"


def enable_utf8_output(streams=None) -> None:
    """Switch ``stdout``/``stderr`` to UTF-8 so non-Latin text cannot crash.

    No-op for streams that cannot be reconfigured (pytest capture objects,
    already-closed files, plain byte pipes): those either do not raise or are
    not ours to touch.
    """
    targets = (sys.stdout, sys.stderr) if streams is None else streams
    for stream in targets:
        reconfigure = getattr(stream, "reconfigure", None)
        if reconfigure is None:
            continue
        try:
            reconfigure(encoding="utf-8", errors=STREAM_ERRORS)
        except (ValueError, OSError):
            # Detached/closed stream — nothing to configure.
            continue


def safe_print(*values, **kwargs) -> None:
    """``print`` that never raises, even on an unencodable stream.

    Used for diagnostic output that can contain usernames or other data typed
    by users.  Falls back to an ASCII-escaped line, and finally gives up in
    silence — a debug line is not worth a 500.
    """
    target = kwargs.pop("file", None) or sys.stdout
    try:
        print(*values, file=target, **kwargs)
        return
    except UnicodeEncodeError:
        pass
    except Exception:  # noqa: BLE001 - diagnostics must not break the caller
        return
    try:
        escaped = " ".join(str(value) for value in values)
        escaped = escaped.encode("ascii", "backslashreplace").decode("ascii")
        print(escaped, file=target, **kwargs)
    except Exception:  # noqa: BLE001
        return
