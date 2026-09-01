"""Shared fixtures.

The autouse fixture below is the reason this suite can be trusted to be
offline. ``Settings`` reads ``.env``, so a developer with a real key in their
working copy would otherwise have the prose layer fire during a test run - a
network call, a bill, and a plan whose coaching notes differ from CI's.
Blanking the key puts every test in the same state as a fresh clone.
"""

from __future__ import annotations

from typing import Any

import pytest

from app.config import settings
from app.schemas import Assessment


@pytest.fixture(autouse=True)
def offline(monkeypatch: pytest.MonkeyPatch) -> None:
    """Force the no-API-key path for every test."""
    monkeypatch.setattr(settings, "anthropic_api_key", "")


def assessment_payload(**overrides: Any) -> dict[str, Any]:
    payload: dict[str, Any] = {
        "profile": {"age": 28, "height_cm": 182, "weight_kg": 84.0},
        "goal": "muscle_gain",
        "experience": "intermediate",
        "days_per_week": 4,
        "minutes_per_session": 75,
        "equipment": "full_gym",
        "limitations": [],
        "disliked_exercises": [],
        "locale": "en",
    }
    payload.update(overrides)
    return payload


def make_assessment(**overrides: Any) -> Assessment:
    return Assessment.model_validate(assessment_payload(**overrides))
