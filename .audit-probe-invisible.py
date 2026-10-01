"""Temporary audit probe: find invisible characters in the module's Persian literals.

Zero-width joiners, bidi marks and RLM are legitimate inside Persian strings but
invisible in an editor, so a hand-retyped copy of a message loses one without any
visible difference.  Run this against the Python module and against the PHP port and
compare the lists.
"""

import io
import re
import sys

INVISIBLE = re.compile("[\u200b-\u200f\u202a-\u202e\u2066-\u2069\ufeff]")
STRING = re.compile(r"(['\"])((?:\\\\.|(?!\1).)*)\1")


def scan(path: str) -> int:
    src = io.open(path, encoding="utf-8").read()
    total = 0
    for match in STRING.finditer(src):
        literal = match.group(2)
        hits = INVISIBLE.findall(literal)
        if not hits:
            continue
        total += 1
        line = src[: match.start()].count("\n") + 1
        points = " ".join("U+%04X" % ord(c) for c in hits)
        escaped = literal.encode("unicode_escape").decode("ascii")
        print(f"{path}:{line}  {points}  {escaped}")

    return total


if __name__ == "__main__":
    count = 0
    for argument in sys.argv[1:]:
        count += scan(argument)

    print(f"literals carrying invisible characters: {count}")
