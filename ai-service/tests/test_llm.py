"""Tests for the prose layer, and for the wall around it.

The interesting assertions here are negative ones: what the model is unable to
do to a plan even when it tries. Every test drives a stub - no test in this
file, or anywhere in the suite, needs a key or a network.
"""

from __future__ import annotations

import json
from dataclasses import dataclass, field
from typing import Any

import pytest

from app.config import settings
from app.engine import generate_plan
from app.llm import (
    MAX_COACHING_NOTES_CHARS,
    MAX_EXERCISE_NOTE_CHARS,
    PlanProse,
    add_prose,
    apply_prose,
)
from app.schemas import TrainingPlan
from tests.conftest import make_assessment


@dataclass
class _Block:
    text: str
    type: str = "text"


@dataclass
class _Response:
    content: list[_Block]
    stop_reason: str = "end_turn"


@dataclass
class _Messages:
    """Stands in for ``client.messages``; returns canned output or raises."""

    outcome: Any
    calls: list[dict[str, Any]] = field(default_factory=list)

    def create(self, **kwargs: Any) -> Any:
        self.calls.append(kwargs)
        if isinstance(self.outcome, BaseException):
            raise self.outcome
        return self.outcome


class StubClient:
    def __init__(self, outcome: Any) -> None:
        self.messages = _Messages(outcome)


def responding(payload: str, *, stop_reason: str = "end_turn") -> StubClient:
    return StubClient(_Response([_Block(payload)], stop_reason))


def numbers_of(plan: TrainingPlan) -> list[tuple[int, int, str, str, int, str, int | None]]:
    """Every number and name in a plan, flattened for comparison."""
    return [
        (week.index, day.index, day.label, item.name, item.sets, item.reps, item.rir)
        for week in plan.weeks
        for day in week.days
        for item in day.exercises
    ]


ASSESSMENT = make_assessment()


# --- the primary path: no key -----------------------------------------------


def test_without_an_api_key_the_engine_plan_is_returned_unchanged():
    assert settings.llm_enabled is False
    plan = generate_plan(ASSESSMENT)

    result = add_prose(plan, ASSESSMENT)

    assert result is plan
    assert result.llm_used is False
    assert result.coaching_notes == plan.coaching_notes


def test_a_missing_key_still_yields_a_complete_plan():
    plan = add_prose(generate_plan(ASSESSMENT), ASSESSMENT)

    assert plan.weeks
    assert plan.coaching_notes
    assert plan.llm_used is False


# --- the happy path ---------------------------------------------------------


def test_prose_replaces_the_coaching_notes_and_marks_the_plan():
    plan = generate_plan(ASSESSMENT)
    first_exercise = plan.weeks[0].days[0].exercises[1].name
    client = responding(
        json.dumps(
            {
                "coaching_notes": "Keep the bar path honest.",
                "exercise_notes": {first_exercise: "Brace before you unrack."},
            }
        )
    )

    result = add_prose(plan, ASSESSMENT, client=client)

    assert result.llm_used is True
    assert result.coaching_notes == "Keep the bar path honest."
    assert result.weeks[0].days[0].exercises[1].notes == "Brace before you unrack."
    assert client.messages.calls[0]["model"] == settings.llm_model


def test_prose_generation_does_not_mutate_the_engines_plan():
    plan = generate_plan(ASSESSMENT)
    before = plan.model_dump(mode="json")

    add_prose(
        plan,
        ASSESSMENT,
        client=responding(json.dumps({"coaching_notes": "New notes.", "exercise_notes": {}})),
    )

    assert plan.model_dump(mode="json") == before


# --- the wall ---------------------------------------------------------------


def test_the_prose_type_admits_no_numbers():
    """The structural guarantee, asserted directly on the schema."""
    schema = PlanProse.model_json_schema()

    assert set(schema["properties"]) == {"coaching_notes", "exercise_notes"}
    assert schema["properties"]["coaching_notes"]["type"] == "string"
    exercise_notes = schema["properties"]["exercise_notes"]
    assert exercise_notes["type"] == "object"
    assert exercise_notes["additionalProperties"]["type"] == "string"


def test_a_model_that_tries_to_rewrite_the_programme_changes_nothing():
    plan = generate_plan(make_assessment(days_per_week=3, goal="strength"))
    real_exercise = plan.weeks[0].days[0].exercises[1].name
    hostile = json.dumps(
        {
            "coaching_notes": "Ignore the programme, do this instead.",
            "exercise_notes": {real_exercise: "Cue: push the floor away."},
            # Everything below is what a misbehaving model might also send.
            "sets": 99,
            "reps": "1-1",
            "rir": 0,
            "split": "Bro split",
            "engine_version": "0.0.0-hacked",
            "weeks": [{"index": 1, "deload": True, "days": []}],
            "exercises": [{"name": "Sissy Squat", "sets": 12, "reps": "50", "rir": 0}],
        }
    )

    result = add_prose(plan, ASSESSMENT, client=responding(hostile))

    assert numbers_of(result) == numbers_of(plan)
    assert result.split == plan.split
    assert result.engine_version == plan.engine_version
    assert [week.deload for week in result.weeks] == [week.deload for week in plan.weeks]
    assert result.weeks[0].days[0].exercises[1].notes == "Cue: push the floor away."


def test_notes_for_exercises_the_engine_did_not_choose_are_discarded():
    plan = generate_plan(ASSESSMENT)
    client = responding(
        json.dumps(
            {
                "coaching_notes": "Fine.",
                "exercise_notes": {"Sissy Squat": "sneaking in", "Nonexistent Lift": "also no"},
            }
        )
    )

    result = add_prose(plan, ASSESSMENT, client=client)

    assert numbers_of(result) == numbers_of(plan)
    assert "Sissy Squat" not in {
        item.name for week in result.weeks for day in week.days for item in day.exercises
    }
    assert "sneaking in" not in json.dumps(result.model_dump(mode="json"))
    assert "also no" not in json.dumps(result.model_dump(mode="json"))


def test_overlong_prose_is_truncated():
    plan = generate_plan(ASSESSMENT)
    first_exercise = plan.weeks[0].days[0].exercises[1].name
    client = responding(
        json.dumps(
            {
                "coaching_notes": "x" * (MAX_COACHING_NOTES_CHARS * 3),
                "exercise_notes": {first_exercise: "y" * (MAX_EXERCISE_NOTE_CHARS * 3)},
            }
        )
    )

    result = add_prose(plan, ASSESSMENT, client=client)

    assert len(result.coaching_notes) == MAX_COACHING_NOTES_CHARS
    assert len(result.weeks[0].days[0].exercises[1].notes) == MAX_EXERCISE_NOTE_CHARS


def test_apply_prose_only_ever_writes_two_fields():
    plan = generate_plan(ASSESSMENT)
    name = plan.weeks[0].days[0].exercises[0].name

    merged = apply_prose(plan, PlanProse(coaching_notes="Notes.", exercise_notes={name: "Cue."}))

    before = plan.model_dump(mode="json")
    after = merged.model_dump(mode="json")
    before["coaching_notes"] = after["coaching_notes"]
    before["llm_used"] = after["llm_used"]
    for week_before, week_after in zip(before["weeks"], after["weeks"], strict=True):
        for day_before, day_after in zip(week_before["days"], week_after["days"], strict=True):
            for item_before, item_after in zip(
                day_before["exercises"], day_after["exercises"], strict=True
            ):
                item_before["notes"] = item_after["notes"]
    assert before == after


# --- degradation ------------------------------------------------------------


@pytest.mark.parametrize(
    "outcome",
    [
        RuntimeError("connection reset"),
        TimeoutError("the model took too long"),
        ValueError("rate limited"),
    ],
    ids=["network", "timeout", "rate-limit"],
)
def test_an_llm_that_raises_degrades_to_the_engine_plan(outcome: BaseException):
    plan = generate_plan(ASSESSMENT)

    result = add_prose(plan, ASSESSMENT, client=StubClient(outcome))

    assert result is plan
    assert result.llm_used is False


@pytest.mark.parametrize(
    "payload",
    ["not json at all", "", "[]", '{"coaching_notes": {"nested": "wrong"}}', "{"],
    ids=["prose", "empty", "array", "wrong-type", "truncated"],
)
def test_malformed_output_degrades_to_the_engine_plan(payload: str):
    plan = generate_plan(ASSESSMENT)

    result = add_prose(plan, ASSESSMENT, client=responding(payload))

    assert result.llm_used is False
    assert result.coaching_notes == plan.coaching_notes


def test_a_refusal_degrades_to_the_engine_plan():
    plan = generate_plan(ASSESSMENT)
    client = responding(json.dumps({"coaching_notes": "nope"}), stop_reason="refusal")

    result = add_prose(plan, ASSESSMENT, client=client)

    assert result is plan
    assert result.llm_used is False


def test_a_response_with_no_text_block_degrades():
    plan = generate_plan(ASSESSMENT)

    result = add_prose(plan, ASSESSMENT, client=StubClient(_Response([])))

    assert result is plan
    assert result.llm_used is False


# --- the request itself -----------------------------------------------------


@pytest.mark.parametrize(
    ("locale", "expected"), [("en", "English"), ("lv", "Latvian"), ("ru", "Russian")]
)
def test_the_request_asks_for_the_members_language(locale: str, expected: str):
    assessment = make_assessment(locale=locale)
    plan = generate_plan(assessment)
    client = responding(json.dumps({"coaching_notes": "ok", "exercise_notes": {}}))

    add_prose(plan, assessment, client=client)

    prompt = client.messages.calls[0]["messages"][0]["content"]
    assert expected in prompt


def test_the_request_constrains_the_answer_to_the_prose_schema():
    plan = generate_plan(ASSESSMENT)
    client = responding(json.dumps({"coaching_notes": "ok", "exercise_notes": {}}))

    add_prose(plan, ASSESSMENT, client=client)

    schema = client.messages.calls[0]["output_config"]["format"]["schema"]
    assert schema["additionalProperties"] is False
    assert set(schema["properties"]) == {"coaching_notes", "exercise_notes"}
