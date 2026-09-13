"""
HASTAMA — Offline CAPTCHA Generator & Validator

Generates CAPTCHA images server-side using Pillow.
Code is stored in the session (never exposed to client).
"""

import io
import math
import random
import secrets
import string
import time

from PIL import Image, ImageDraw, ImageFont

# ── Configuration ─────────────────────────────────────────────
CAPTCHA_LENGTH = 6
CAPTCHA_EXPIRY_SECONDS = 180  # 3 minutes
CAPTCHA_SESSION_KEY = "captcha_code"
CAPTCHA_TS_KEY = "captcha_ts"
CAPTCHA_ATTEMPTS_KEY = "captcha_attempts"

# Characters: uppercase + digits (ambiguous chars removed)
CAPTCHA_CHARS = string.ascii_uppercase.replace("O", "").replace("I", "").replace("0", "").replace("1", "")

# Image dimensions
IMG_WIDTH = 180
IMG_HEIGHT = 56

# Color palette (Hastama brand — teal/blue tones)
BG_COLORS = [
    (240, 248, 255),  # alice blue
    (245, 250, 255),  # light sky
    (238, 244, 250),  # soft blue
    (242, 247, 252),  # pale azure
]
TEXT_COLORS = [
    (30, 64, 75),     # dark teal
    (15, 23, 42),     # slate 900
    (30, 58, 138),    # blue 800
    (55, 48, 107),    # indigo 900
    (17, 94, 89),     # teal 800
]
LINE_COLORS = [
    (180, 200, 220),
    (170, 190, 210),
    (160, 185, 205),
    (148, 163, 184),
]
DOT_COLORS = [
    (180, 200, 220),
    (160, 180, 200),
    (148, 163, 184),
    (120, 140, 160),
]


def _get_font(size: int):
    """Try to load a monospace font, fall back to default."""
    font_paths = [
        "C:/Windows/Fonts/consola.ttf",
        "C:/Windows/Fonts/cour.ttf",
        "C:/Windows/Fonts/arial.ttf",
        "C:/Windows/Fonts/georgia.ttf",
    ]
    for path in font_paths:
        try:
            return ImageFont.truetype(path, size)
        except (OSError, IOError):
            continue
    return ImageFont.load_default()


def generate_captcha_image() -> tuple[str, bytes]:
    """
    Generate a CAPTCHA image.

    Returns:
        (code, image_bytes) — the code is NOT stored anywhere; caller must save it.
    """
    code = "".join(secrets.choice(CAPTCHA_CHARS) for _ in range(CAPTCHA_LENGTH))

    # Create image
    bg_color = random.choice(BG_COLORS)
    img = Image.new("RGB", (IMG_WIDTH, IMG_HEIGHT), bg_color)
    draw = ImageDraw.Draw(img)

    # ── Draw noise dots ──
    for _ in range(random.randint(80, 120)):
        x = random.randint(0, IMG_WIDTH - 1)
        y = random.randint(0, IMG_HEIGHT - 1)
        color = random.choice(DOT_COLORS)
        r = random.randint(1, 2)
        draw.ellipse([x - r, y - r, x + r, y + r], fill=color)

    # ── Draw interference lines ──
    for _ in range(random.randint(3, 5)):
        x1 = random.randint(0, IMG_WIDTH)
        y1 = random.randint(0, IMG_HEIGHT)
        x2 = random.randint(0, IMG_WIDTH)
        y2 = random.randint(0, IMG_HEIGHT)
        color = random.choice(LINE_COLORS)
        draw.line([(x1, y1), (x2, y2)], fill=color, width=random.randint(1, 2))

    # ── Draw text with per-character rotation ──
    font_size = random.randint(28, 34)
    font = _get_font(font_size)
    char_width = IMG_WIDTH // (CAPTCHA_LENGTH + 1)
    start_x = char_width // 2

    for i, char in enumerate(code):
        x = start_x + i * char_width + random.randint(-3, 3)
        y = random.randint(4, IMG_HEIGHT - font_size - 4)
        color = random.choice(TEXT_COLORS)

        # Create a small image for each character with rotation
        char_img = Image.new("RGBA", (font_size + 10, font_size + 10), (0, 0, 0, 0))
        char_draw = ImageDraw.Draw(char_img)
        char_draw.text((2, 2), char, font=font, fill=color + (255,))

        # Random rotation
        angle = random.randint(-15, 15)
        char_img = char_img.rotate(angle, expand=True, resample=Image.BICUBIC)

        # Paste onto main image
        img.paste(char_img, (x, y), char_img)

    # ── Draw wave interference lines ──
    for _ in range(random.randint(2, 3)):
        color = random.choice(LINE_COLORS) + (80,)
        overlay = Image.new("RGBA", (IMG_WIDTH, IMG_HEIGHT), (0, 0, 0, 0))
        overlay_draw = ImageDraw.Draw(overlay)
        points = []
        amplitude = random.randint(3, 8)
        frequency = random.uniform(0.02, 0.06)
        phase = random.uniform(0, 2 * math.pi)
        for x in range(0, IMG_WIDTH, 2):
            y = int(IMG_HEIGHT / 2 + amplitude * math.sin(frequency * x + phase))
            points.append((x, y))
        if len(points) > 1:
            overlay_draw.line(points, fill=color, width=1)
        img = Image.alpha_composite(img.convert("RGBA"), overlay).convert("RGB")

    # ── Save to bytes ──
    buf = io.BytesIO()
    img.save(buf, format="PNG", optimize=True)
    buf.seek(0)

    return code, buf.getvalue()


def store_captcha_in_session(request, code: str) -> None:
    """Store CAPTCHA code and timestamp in the session."""
    request.session[CAPTCHA_SESSION_KEY] = code
    request.session[CAPTCHA_TS_KEY] = time.time()
    request.session[CAPTCHA_ATTEMPTS_KEY] = 0


def validate_captcha(request, user_code: str) -> tuple[bool, str]:
    """
    Validate the CAPTCHA code from the user.

    Returns:
        (is_valid, error_message)
    """
    stored_code = request.session.get(CAPTCHA_SESSION_KEY)
    stored_ts = request.session.get(CAPTCHA_TS_KEY, 0)
    attempts = request.session.get(CAPTCHA_ATTEMPTS_KEY, 0)

    # Check if CAPTCHA exists
    if not stored_code:
        return False, "کد امنیتی یافت نشد. لطفاً صفحه را مجدداً بارگذاری کنید."

    # Check expiry
    if time.time() - stored_ts > CAPTCHA_EXPIRY_SECONDS:
        # Expire — clear and force new CAPTCHA
        request.session.pop(CAPTCHA_SESSION_KEY, None)
        request.session.pop(CAPTCHA_TS_KEY, None)
        request.session.pop(CAPTCHA_ATTEMPTS_KEY, None)
        return False, "کد امنیتی منقضی شده است. لطفاً کد جدیدی دریافت کنید."

    # Check attempts (after 3 failed attempts, invalidate current CAPTCHA)
    if attempts >= 3:
        request.session.pop(CAPTCHA_SESSION_KEY, None)
        request.session.pop(CAPTCHA_TS_KEY, None)
        request.session.pop(CAPTCHA_ATTEMPTS_KEY, None)
        return False, "تعداد تلاش‌ها بیش از حد مجاز است. کد امنیتی جدیدی دریافت کنید."

    # Validate (case-insensitive)
    if user_code.strip().upper() != stored_code.upper():
        request.session[CAPTCHA_ATTEMPTS_KEY] = attempts + 1
        remaining = 3 - (attempts + 1)
        if remaining > 0:
            return False, f"کد امنیتی اشتباه است. {remaining} تلاش باقی مانده."
        else:
            # Max attempts reached — invalidate
            request.session.pop(CAPTCHA_SESSION_KEY, None)
            request.session.pop(CAPTCHA_TS_KEY, None)
            request.session.pop(CAPTCHA_ATTEMPTS_KEY, None)
            return False, "کد امنیتی اشتباه است. کد جدیدی دریافت کنید."

    # Valid — clear CAPTCHA from session
    request.session.pop(CAPTCHA_SESSION_KEY, None)
    request.session.pop(CAPTCHA_TS_KEY, None)
    request.session.pop(CAPTCHA_ATTEMPTS_KEY, None)
    return True, ""


def captcha_remaining_seconds(request) -> int:
    """Return seconds until current CAPTCHA expires."""
    stored_ts = request.session.get(CAPTCHA_TS_KEY, 0)
    elapsed = time.time() - stored_ts
    remaining = CAPTCHA_EXPIRY_SECONDS - elapsed
    return max(0, int(remaining))
