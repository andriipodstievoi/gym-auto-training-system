"""The engine's own version, kept apart from the modules that report it.

It lives in a leaf module so that the generator can stamp it onto a plan and
``app.engine`` can re-export it without the two importing each other.
"""

from __future__ import annotations

#: Bumped whenever a rule change would alter the plan for an unchanged
#: assessment. Stored on every plan so a programme can be explained later.
ENGINE_VERSION = "1.0.0"
