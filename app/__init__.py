__version__ = "0.1.0"

# Must run before anything else in the process can print: the scheduled task
# starts uvicorn with a redirected (piped) stdout, which Windows encodes with
# the ANSI code page, and a Persian ``print`` raised UnicodeEncodeError from
# inside a request handler — surfacing as HTTP 500 on /get_hozoor/{username}.
# Importing the submodule first would already import this package, so this is
# the earliest safe hook.
from app.core.console import enable_utf8_output as _enable_utf8_output

_enable_utf8_output()
