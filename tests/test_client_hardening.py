"""Client-side hardening regression tests.

Covers the comment-free delivery pipeline (``app/services/client_assets.py`` +
``_ClientAssetMinifierMiddleware``), static-file exposure, the removal of
Jinja values from JavaScript string contexts inside inline handlers, and the
browser-storage hygiene added to logout.

Nothing in this file claims that client code can be hidden: the browser receives
readable, modifiable code.  These tests only prove that the *developer* surface
(comments, internal paths, dormant debug output) no longer ships by default and
that no security control moved to the client.
"""

from __future__ import annotations

import pathlib
import re
import shutil
import subprocess

import pytest

from app.services import client_assets

STATIC = pathlib.Path("app/static")
JS_DIR = STATIC / "js"
CSS_DIR = STATIC / "css"
TEMPLATES = pathlib.Path("app/templates")


def _client():
    from starlette.testclient import TestClient

    import app.main as main_mod

    return TestClient(main_mod.app, base_url="http://testserver")


# ── 1. the minifier itself ───────────────────────────────────────────────────


class TestJavaScriptMinifier:
    def test_line_comment_is_removed(self):
        out = client_assets.minify_js("var a = 1; // internal note\nvar b = 2;\n")
        assert "internal note" not in out
        assert "var a = 1;" in out and "var b = 2;" in out

    def test_block_comment_is_removed_but_newlines_survive(self):
        out = client_assets.minify_js("var a = 1;\n/* chatty\n   note */\nvar b = 2;\n")
        assert "chatty" not in out
        assert out.count("\n") >= 2, "automatic semicolon insertion must never be risked"

    def test_comment_like_text_inside_a_string_is_kept(self):
        src = 'var url = "http://example.test/a//b";\nvar path = "/x/*y*/z";\n'
        assert client_assets.minify_js(src) == src.replace("\n", "\n")

    def test_regex_literal_containing_slashes_is_kept(self):
        src = 'var re = /a\\/\\/b/g; // strip me\n'
        out = client_assets.minify_js(src)
        assert "/a\\/\\/b/g" in out
        assert "strip me" not in out

    def test_template_literal_is_byte_identical(self):
        src = "const t = `line1\n  indented ${ {a: 1} }\n`; // c\n"
        out = client_assets.minify_js(src)
        assert "`line1\n  indented ${ {a: 1} }\n`" in out

    def test_comment_between_two_identifiers_cannot_glue_tokens(self):
        assert client_assets.minify_js("var a/*c*/b = 1;\n").strip().startswith("var a b = 1;")

    def test_division_is_not_mistaken_for_a_regex(self):
        out = client_assets.minify_js("var x = 10 / 2; // half\n")
        assert "10 / 2;" in out

    @pytest.mark.parametrize(
        "src",
        [
            'var s = "unterminated\n',
            "var a = 1; /* unterminated",
            "var r = /unterminated\n",
            "var t = `unterminated",
        ],
    )
    def test_unreadable_input_is_refused(self, src):
        with pytest.raises(client_assets.MinifyError):
            client_assets.minify_js(src)

    def test_every_shipped_file_keeps_its_token_stream(self):
        for path in sorted(JS_DIR.rglob("*.js")):
            text = path.read_text("utf-8", "ignore")
            if len(text.encode("utf-8")) > client_assets.MAX_JS_BYTES:
                continue
            mini = client_assets.minify_js(text)  # raises MinifyError on any doubt
            assert client_assets._js_tokens(mini) == client_assets._js_tokens(text), path


class TestCssMinifier:
    def test_comment_is_removed(self):
        out = client_assets.minify_css("/* note */ .a { color: red; }\n")
        assert "note" not in out
        assert ".a" in out and "color" in out and "red" in out

    def test_comment_like_text_inside_url_is_kept(self):
        src = "a { background: url(http://x.test/a//b.png); }\n"
        assert "http://x.test/a//b.png" in client_assets.minify_css(src)

    def test_string_content_is_never_touched(self):
        src = 'a::after { content: "/* not a comment */"; }\n'
        assert '"/* not a comment */"' in client_assets.minify_css(src)

    def test_descendant_selector_space_is_preserved(self):
        out = client_assets.minify_css("div span { color: red; }")
        assert "div span" in out

    def test_unbalanced_block_is_refused(self):
        with pytest.raises(client_assets.MinifyError):
            client_assets.minify_css("a { color: red; ")

    def test_every_shipped_stylesheet_minifies(self):
        for path in sorted(CSS_DIR.rglob("*.css")):
            client_assets.minify_css(path.read_text("utf-8", "ignore"))


class TestHtmlCommentStripping:
    def test_plain_comment_is_removed(self):
        out = client_assets.strip_html_comments("<div><!-- section --><b>x</b></div>")
        assert "section" not in out and "<b>x</b>" in out

    def test_comments_inside_script_and_style_are_kept(self):
        html = '<script>var a = 1; /* keep */ // keep</script><style>/* keep */</style>'
        assert client_assets.strip_html_comments(html) == html

    def test_conditional_comment_is_kept(self):
        html = "<!--[if lt IE 9]><script src=x></script><![endif]-->"
        assert client_assets.strip_html_comments(html) == html

    def test_comment_like_text_in_an_attribute_is_kept(self):
        html = '<div data-note="a <!-- b --> c">x</div>'
        assert client_assets.strip_html_comments(html) == html

    def test_unterminated_comment_is_left_alone(self):
        html = "<p>x</p><!-- dangling"
        assert client_assets.strip_html_comments(html) == html


# ── 2. delivery middleware ───────────────────────────────────────────────────


class TestMinifiedDelivery:
    def test_off_by_default(self, monkeypatch):
        monkeypatch.delenv(client_assets.ENV_FLAG, raising=False)
        assert client_assets.enabled() is False
        with _client() as client:
            served = client.get("/static/js/theme.js")
            raw = (JS_DIR / "theme.js").read_bytes()
        assert served.content == raw, "without the flag the sources must be served byte for byte"

    def test_comments_are_gone_when_enabled(self, monkeypatch):
        monkeypatch.setenv(client_assets.ENV_FLAG, "1")
        with _client() as client:
            js = client.get("/static/js/theme.js")
            css = client.get("/static/css/vazir.css")
            page = client.get("/login")
        assert b"/*" not in js.content and b"//" not in js.content
        assert "<!--" not in page.text
        assert len(css.content) < (CSS_DIR / "vazir.css").stat().st_size
        assert js.headers["content-length"] == str(len(js.content))
        assert page.headers["content-length"] == str(len(page.text.encode("utf-8")))

    def test_page_still_contains_its_scripts_and_forms(self, monkeypatch):
        monkeypatch.setenv(client_assets.ENV_FLAG, "1")
        with _client() as client:
            page = client.get("/login").text
        assert "<script" in page and "</script>" in page
        assert "form" in page.lower()

    def test_json_responses_are_never_rewritten(self, monkeypatch):
        monkeypatch.setenv(client_assets.ENV_FLAG, "1")
        with _client() as client:
            response = client.get("/static/nope.txt")
            missing = client.get("/static/js/does-not-exist.js")
        assert response.status_code == 404
        assert missing.status_code == 404
        assert missing.headers["content-type"].startswith("application/json")

    def test_vendor_bundle_is_not_re_minified(self, monkeypatch):
        """A 900 KB vendored bundle is already minified: it is only stripped of its map pointer."""
        monkeypatch.setenv(client_assets.ENV_FLAG, "1")
        bundle = JS_DIR / "html2pdf.bundle.min.js"
        raw = bundle.read_text("utf-8", "ignore")
        with _client() as client:
            served = client.get("/static/js/html2pdf.bundle.min.js")
        text = served.content.decode("utf-8", "ignore")
        assert served.status_code == 200
        assert text == client_assets.strip_source_map_pointer(raw)
        assert len(text) >= len(raw) - 120, "the bundle must not be re-tokenised"

    def test_missing_static_file_returns_none(self):
        assert client_assets.minified_static_bytes(str(STATIC), "/static/js/no-such.js") is None


# ── 3. every shipped script still parses after minification ──────────────────


@pytest.mark.skipif(shutil.which("node") is None, reason="node is not installed")
def test_minified_javascript_still_parses():
    for path in sorted(JS_DIR.rglob("*.js")):
        text = path.read_text("utf-8", "ignore")
        if path.name.endswith(".min.js"):
            continue
        mini = client_assets.minify_js(text)
        proc = subprocess.run(
            ["node", "--check", "-"], input=mini.encode("utf-8"), capture_output=True, timeout=120
        )
        assert proc.returncode == 0, f"{path}: {proc.stderr.decode('utf-8', 'ignore')[:400]}"


# ── 4. static-file exposure ──────────────────────────────────────────────────


class TestStaticExposure:
    @pytest.mark.parametrize(
        "path",
        [
            "/.env",
            "/.git/config",
            "/logs/",
            "/tests/",
            "/scripts/",
            "/tools/",
            "/database/",
            "/app/main.py",
            "/pyproject.toml",
            "/readme.md",
            "/static/../.env",
            "/static/..%2f.env",
            "/static/js/admin.js.map",
            "/static/js/../../../.env",
        ],
    )
    def test_private_paths_are_not_reachable(self, path):
        with _client() as client:
            response = client.get(path)
        assert response.status_code in (400, 403, 404), f"{path} -> {response.status_code}"

    def test_docs_endpoints_are_not_exposed(self):
        with _client() as client:
            for path in ("/docs", "/redoc", "/openapi.json"):
                assert client.get(path).status_code == 404

    def test_no_source_maps_are_shipped(self, monkeypatch):
        assert list(STATIC.rglob("*.map")) == [], "a source map would publish the original sources"
        monkeypatch.setenv(client_assets.ENV_FLAG, "1")
        with _client() as client:
            for path in ("theme.js", "html2pdf.bundle.min.js", "vendor/persian-date/persian-date.min.js"):
                response = client.get(f"/static/js/{path}")
                if response.status_code == 404:
                    response = client.get(f"/static/vendor/persian-date/persian-date.min.js")
                assert response.status_code == 200, path
                body = response.content.decode("utf-8", "ignore")
                assert "sourceMappingURL" not in body, f"{path} still points at a missing map"
                assert "//# sourceURL" not in body, path


# ── 5. client-asset hygiene ──────────────────────────────────────────────────


class TestClientAssetHygiene:
    def test_no_jinja_value_inside_an_inline_handler(self):
        """A Jinja value in a JS string inside an attribute is an injection primitive.

        HTML entities are decoded by the parser *before* the JavaScript engine
        parses the handler, so ``onclick="f('{{ value }}')"`` can be broken out of
        by a value containing a quote.  Values belong in data-attributes.
        """
        pattern = re.compile(
            r"on(?:click|change|input|submit|load|error|keyup|keydown|focus|blur|mouse\w+)"
            r'\s*=\s*"[^"]*\{\{'
        )
        offenders = []
        for path in sorted(TEMPLATES.rglob("*.html")):
            text = path.read_text("utf-8", "ignore")
            for match in pattern.finditer(text):
                line = text[: match.start()].count("\n") + 1
                offenders.append(f"{path}:{line}")
        assert offenders == [], f"Jinja value used inside an inline handler: {offenders}"

    def test_delivered_assets_do_not_name_internal_server_paths(self):
        pattern = re.compile(r"app/(?:services|templates|api|core)/|scripts\\\\|E:\\\\Hastama")
        offenders = []
        for path in list(JS_DIR.rglob("*.js")) + list(CSS_DIR.rglob("*.css")):
            if path.name.endswith(".min.js"):
                continue
            text = path.read_text("utf-8", "ignore")
            if pattern.search(text):
                offenders.append(str(path))
        assert offenders == [], f"client assets leak internal paths: {offenders}"

    @pytest.mark.parametrize(
        "pattern",
        [
            r"SECRET_KEY\s*=",
            r"SESSION_SECRET_KEY\s*=",
            r"password_hash\s*[:=]",
            r"pyodbc\.connect",
            r"DRIVER=\{",
            r"AKIA[0-9A-Z]{16}",
        ],
    )
    def test_no_secrets_or_database_logic_in_client_code(self, pattern):
        regex = re.compile(pattern)
        offenders = [
            str(path)
            for path in list(JS_DIR.rglob("*.js")) + list(TEMPLATES.rglob("*.html"))
            if regex.search(path.read_text("utf-8", "ignore"))
        ]
        assert offenders == [], f"{pattern} found in {offenders}"

    def test_inline_javascript_does_not_embed_server_values(self):
        """Only the label document may accept a server-rendered script fragment."""
        allowed = {"label_print_document.html"}
        offenders = []
        for path in sorted(TEMPLATES.rglob("*.html")):
            if path.name in allowed:
                continue
            text = path.read_text("utf-8", "ignore")
            for block in re.findall(r"<script\b(?![^>]*\bsrc=)[^>]*>(.*?)</script>", text, re.S | re.I):
                if re.search(r"\{\{|\{%", block):
                    offenders.append(path.name)
        assert offenders == [], f"inline script carries Jinja data: {sorted(set(offenders))}"

    def test_logout_clears_cached_report_data(self):
        """Attendance / payroll caches must not survive a logout on a shared terminal."""
        for name in ("admin.js", "user-panel-script.js"):
            source = (JS_DIR / name).read_text("utf-8", "ignore")
            body = source[source.index("function logout()"):][:1200]
            assert "localStorage.removeItem" in body, name
            assert "hozoorReportData" in body, name
            assert "sessionStorage.removeItem" in body, name


# ── 6. security headers / cookies unchanged ─────────────────────────────────


class TestHeadersAndCookiesUnaffected:
    def test_csp_is_still_emitted(self, monkeypatch):
        monkeypatch.setenv(client_assets.ENV_FLAG, "1")
        with _client() as client:
            headers = client.get("/login").headers
        csp = headers["content-security-policy"]
        assert "default-src 'self'" in csp
        assert "object-src 'none'" in csp
        assert headers["x-content-type-options"] == "nosniff"
        assert headers["x-frame-options"] == "SAMEORIGIN"

    def test_hardened_cookie_flags_survive_the_rewrite(self, monkeypatch):
        monkeypatch.setenv(client_assets.ENV_FLAG, "1")
        with _client() as client:
            cookies = client.get("/captcha").headers.get_list("set-cookie")
        session = [c for c in cookies if c.startswith("session=")]
        assert session, "the captcha page must still set the signed session cookie"
        lowered = session[0].lower()
        assert "httponly" in lowered and "samesite=lax" in lowered
