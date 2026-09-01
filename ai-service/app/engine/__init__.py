"""Deterministic programme generation.

The rule engine decides every number in a plan: split, weekly set volume,
intensity targets, progression model and exercise selection. The LLM layer
(``app.llm``) only ever writes prose on top of what this module produced.

The public surface is deliberately two names. Everything else - the
catalogue, the split templates, the volume tables, the mesocycle - is an
implementation detail of ``generate_plan``, free to change behind a version
bump.
"""

from __future__ import annotations

from app.engine.generator import generate_plan
from app.engine.version import ENGINE_VERSION

__all__ = ["ENGINE_VERSION", "generate_plan"]
