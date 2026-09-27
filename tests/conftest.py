"""Shared test bootstrap for the pytest suite.

The application opens its SQL Server connection at import time
(`app.main` and `app.api.routes.auth` call ``pyodbc.connect`` on module
level), but the unit-test suite is written to run without a database:
every DB-backed test injects its own fake cursor (see
``tests/test_user_panel_context.py`` and ``probe.py``).

To keep ``import app.main`` working on machines without SQL Server / the
ODBC driver (e.g. the CI runner), install a minimal in-memory stand-in
for ``pyodbc`` *before* any application module is imported. Tests that
need cursor behaviour monkeypatch it explicitly, exactly as before.
"""

import os
import sys
import types

# The application validates the ``Host`` header against one canonical public
# hostname (``hastama.ir``, see ``app.main.allowed_hosts``).  ``TestClient``
# speaks as ``testserver``, so the test session declares its own allow-list here
# — before ``app.main`` is imported.  Production keeps the strict default.
os.environ.setdefault(
    "HASTAMA_ALLOWED_HOSTS",
    "testserver,localhost,127.0.0.1,hastama.ir,www.hastama.ir",
)


class _FakeCursor:
    def execute(self, *args, **kwargs):
        return self

    def fetchone(self):
        return None

    def fetchall(self):
        return []

    def commit(self):
        pass

    def close(self):
        pass


class _FakeConnection:
    def cursor(self):
        return _FakeCursor()

    def close(self):
        pass

    def commit(self):
        pass


def _connect(*_args, **_kwargs):
    return _FakeConnection()


def _install_fake_pyodbc() -> None:
    module = types.ModuleType("pyodbc")
    module.connect = _connect
    module.Error = type("Error", (Exception,), {})
    sys.modules["pyodbc"] = module


_install_fake_pyodbc()
