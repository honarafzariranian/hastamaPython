<?php

namespace App\Support\Registration;

use RuntimeException;

/**
 * `ValueError` raised by `clean_display_text()` in `app/core/validation.py`.
 *
 * The Python registration handler catches exactly this exception and answers
 * `400 {"success": false, "errors": [str(exc)]}` — the message *is* the response
 * body, which is why the throw lives in {@see DisplayText} and the translation
 * back to a `JsonResponse` lives in the controller, mirroring the `try/except
 * ValueError` block around the six `clean_display_text` calls in
 * `submit_registration`.
 */
final class DisplayTextError extends RuntimeException {}
