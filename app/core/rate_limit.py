"""Small in-process sliding-window rate limiter.

Used by the public endpoints (registration submission, anonymous support
ticket, call-system kiosk endpoints) that previously had no throttle at all.
``/registration/submit`` in particular runs ``bcrypt`` (cost 12) for every
request, so an unauthenticated loop was a cheap CPU exhaustion vector.

The limiter is per process: with multiple workers the effective limit scales
with the worker count.  Hastama runs a single uvicorn process on the LAN host
(see ``docs/HASTAMA_PRODUCTION_DEPLOYMENT.md``), and the reverse proxy is the
documented place for global limits.
"""

from __future__ import annotations

import threading
import time
from collections import defaultdict, deque
from typing import Deque, Dict


class SlidingWindowLimiter:
    def __init__(self, max_keys: int = 20000) -> None:
        self._hits: Dict[str, Deque[float]] = defaultdict(deque)
        self._lock = threading.Lock()
        self._max_keys = max_keys

    def allow(self, key: str, *, limit: int, window_seconds: int) -> bool:
        """Record a hit for *key* and return ``False`` when the limit is hit."""
        now = time.time()
        cutoff = now - window_seconds
        with self._lock:
            bucket = self._hits[key]
            while bucket and bucket[0] <= cutoff:
                bucket.popleft()
            if len(bucket) >= limit:
                return False
            bucket.append(now)
            if len(self._hits) > self._max_keys:
                self._hits = defaultdict(deque, {
                    k: v for k, v in self._hits.items() if v and v[-1] > cutoff
                })
            return True

    def retry_after(self, key: str, window_seconds: int) -> int:
        with self._lock:
            bucket = self._hits.get(key)
            if not bucket:
                return 0
            return max(1, int(window_seconds - (time.time() - bucket[0])))

    def reset(self) -> None:
        with self._lock:
            self._hits.clear()


limiter = SlidingWindowLimiter()
