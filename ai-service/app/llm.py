"""The optional prose layer.

The engine has already decided the programme by the time anything here runs.
This module's only job is to make the plan read like a coach wrote it, and its
design goal is that a reviewer can see from the types that it cannot do
anything else.

The boundary is enforced structurally, in four steps:

1. The model is asked for JSON matching ``PlanProse`` - two fields, both
   made of strings. There is no numeric field anywhere in the type, so no
   number can survive deserialisation, however the model answers.
2. ``PlanProse`` ignores unknown keys, so a response that also contains
   ``sets`` or ``weeks`` loses them at the parse step rather than being
   trusted and merged.
3. ``apply_prose`` is a pure function from ``(TrainingPlan, PlanProse)`` to a
   new ``TrainingPlan``. It copies the engine's plan and assigns exactly two
   things: ``coaching_notes`` on the plan, and ``notes`` on exercises. Sets,
   reps, reps-in-reserve, exercise names and the week/day structure are
   carried over untouched because nothing here writes them.
4. Per-exercise notes are keyed by exercise *name* and matched against the
   names the engine already chose. A note for an exercise that is not in the
   plan is discarded, so the model cannot introduce, rename or reorder work.

Everything is optional. With no API key - the state a fresh clone and CI are
in - the plan is returned exactly as the engine built it, with ``llm_used``
left false. Every failure mode does the same thing, silently to the caller and
loudly in the log.
"""

from __future__ import annotations

import json
import logging
from typing import Any, Protocol

from pydantic import BaseModel, ConfigDict, Field

from app.config import settings
from app.schemas import Assessment, Locale, TrainingPlan

logger = logging.getLogger(__name__)

#: Defensive caps. The prose is displayed next to the programme, not instead
#: of it, and a runaway answer should not be able to swamp the page.
MAX_COACHING_NOTES_CHARS = 1500
MAX_EXERCISE_NOTE_CHARS = 200
MAX_EXERCISE_NOTES = 12


class PlanProse(BaseModel):
    """Everything the model is permitted to return.

    Strings, and containers of strings. This type is the entire contract with
    the model: widening it is the only way prose generation could ever start
    influencing the programme, which makes that a reviewable change rather
    than an accident.
    """

    model_config = ConfigDict(extra="ignore")

    coaching_notes: str = ""
    #: Exercise name (exactly as the engine wrote it) to a one-line cue.
    exercise_notes: dict[str, str] = Field(default_factory=dict)


class _Messages(Protocol):
    def create(self, **kwargs: Any) -> Any: ...


class ProseClient(Protocol):
    """The slice of the Anthropic client this module uses.

    Narrow on purpose: tests substitute a stub, and the narrower the seam the
    less a stub has to pretend to be.
    """

    messages: _Messages


_LOCALE_INSTRUCTION: dict[Locale, str] = {
    Locale.EN: "Write in English.",
    Locale.LV: "Write in Latvian (latviešu valodā).",
    Locale.RU: "Write in Russian (на русском языке).",
}

_SYSTEM_PROMPT = (
    "You are a strength coach writing the notes that accompany a training "
    "programme for a gym member. The programme is already finalised by a rule "
    "engine: the sets, repetitions, reps-in-reserve targets and exercise "
    "selection are fixed and are not yours to change, comment on as if they "
    "were negotiable, or contradict. Write encouraging, concrete, practical "
    "prose about how to execute what is written. Do not give medical advice. "
    "Do not propose different exercises, different set counts or different "
    "loads. Respond with JSON only."
)


def _prose_schema() -> dict[str, Any]:
    """The JSON schema the model is constrained to, derived from ``PlanProse``.

    Deriving it rather than hand-writing it means the request and the type
    that validates the response cannot drift apart - widening one widens the
    other, in a single reviewable edit. Pydantic's presentation keys are
    dropped and the object is closed, because structured output wants a
    strict schema.
    """
    generated = PlanProse.model_json_schema()
    properties = {
        name: {key: value for key, value in spec.items() if key not in {"default", "title"}}
        for name, spec in generated["properties"].items()
    }
    return {
        "type": "object",
        "properties": properties,
        "required": list(properties),
        "additionalProperties": False,
    }


_PROSE_SCHEMA: dict[str, Any] = _prose_schema()


def add_prose(
    plan: TrainingPlan,
    assessment: Assessment,
    *,
    client: ProseClient | None = None,
) -> TrainingPlan:
    """Return the plan with coaching prose, or the plan exactly as it was.

    This never raises. A missing key, a missing SDK, a network failure, a rate
    limit, a refusal or a malformed answer all lead to the same place: the
    engine's plan, unchanged, with ``llm_used`` false.
    """
    if client is None:
        client = _build_client()
    if client is None:
        return plan

    try:
        prose = _request_prose(client, plan, assessment)
    except Exception:  # degrading to the engine plan is the point of this layer
        logger.warning("prose generation failed; returning the engine plan", exc_info=True)
        return plan

    if prose is None:
        return plan
    return apply_prose(plan, prose)


def apply_prose(plan: TrainingPlan, prose: PlanProse) -> TrainingPlan:
    """Merge prose onto a plan. The only writer of model output in this module.

    Note what this function has access to and still does not do: it holds a
    whole ``TrainingPlan`` and assigns only ``coaching_notes`` and the
    ``notes`` field of exercises whose names the engine itself produced.
    """
    merged = plan.model_copy(deep=True)

    notes = prose.coaching_notes.strip()
    if notes:
        merged.coaching_notes = notes[:MAX_COACHING_NOTES_CHARS]

    known = {
        exercise.name for week in merged.weeks for day in week.days for exercise in day.exercises
    }
    accepted = {
        name: note.strip()[:MAX_EXERCISE_NOTE_CHARS]
        for name, note in list(prose.exercise_notes.items())[:MAX_EXERCISE_NOTES]
        if name in known and note.strip()
    }
    if accepted:
        for week in merged.weeks:
            for day in week.days:
                for exercise in day.exercises:
                    note = accepted.get(exercise.name)
                    if note:
                        exercise.notes = note

    merged.llm_used = True
    return merged


def _build_client() -> ProseClient | None:
    """The real client, or ``None`` when the service should skip the layer.

    The import is deferred so that a deployment without the SDK installed
    degrades in the same way as a deployment without a key, rather than
    failing at start-up.
    """
    if not settings.llm_enabled:
        return None
    try:
        import anthropic
    except ImportError:
        logger.warning("anthropic SDK is not installed; skipping prose generation")
        return None
    return anthropic.Anthropic(
        api_key=settings.anthropic_api_key,
        timeout=settings.llm_timeout_seconds,
        max_retries=settings.llm_max_retries,
    )


def _request_prose(
    client: ProseClient, plan: TrainingPlan, assessment: Assessment
) -> PlanProse | None:
    """Ask for prose and parse it into the only shape we accept."""
    response = client.messages.create(
        model=settings.llm_model,
        max_tokens=4000,
        system=_SYSTEM_PROMPT,
        messages=[{"role": "user", "content": _prompt(plan, assessment)}],
        # Effort is deliberately low: this is a short writing task on fully
        # specified input, and it sits in a request a member is waiting on.
        output_config={
            "effort": "low",
            "format": {"type": "json_schema", "schema": _PROSE_SCHEMA},
        },
    )

    if getattr(response, "stop_reason", None) == "refusal":
        logger.warning("prose generation refused by the model; returning the engine plan")
        return None

    text = _first_text_block(response)
    if not text:
        return None
    return PlanProse.model_validate_json(text)


def _first_text_block(response: Any) -> str:
    for block in getattr(response, "content", []) or []:
        if getattr(block, "type", None) == "text":
            return str(getattr(block, "text", ""))
    return ""


def _prompt(plan: TrainingPlan, assessment: Assessment) -> str:
    """Describe the finished plan and ask for prose about it.

    The programme is serialised as read-only context. The model is never asked
    to return it, which is why there is nothing to compare an answer against:
    the plan object it might have echoed is not the one that gets returned.
    """
    profile = assessment.profile
    summary = {
        "goal": assessment.goal.value,
        "experience": assessment.experience.value,
        "age": profile.age,
        "days_per_week": assessment.days_per_week,
        "minutes_per_session": assessment.minutes_per_session,
        "equipment": assessment.equipment.value,
        "limitations": [limitation.value for limitation in assessment.limitations],
        "split": plan.split,
        "weeks": [
            {
                "index": week.index,
                "deload": week.deload,
                "days": [
                    {
                        "label": day.label,
                        "exercises": [
                            {
                                "name": item.name,
                                "sets": item.sets,
                                "reps": item.reps,
                                "rir": item.rir,
                            }
                            for item in day.exercises
                        ],
                    }
                    for day in week.days
                ],
            }
            for week in plan.weeks
        ],
    }
    exercise_names = sorted(
        {exercise.name for week in plan.weeks for day in week.days for exercise in day.exercises}
    )
    return (
        "Here is a finalised training programme for one member.\n\n"
        f"{json.dumps(summary, ensure_ascii=False)}\n\n"
        f"{_LOCALE_INSTRUCTION[assessment.locale]}\n\n"
        "Return JSON with two keys.\n"
        '"coaching_notes": four to six sentences addressed to the member, covering how to '
        "run the block, how to judge the reps-in-reserve targets, what to do on the deload "
        "week, and what progress should look like. Refer to the programme as written.\n"
        '"exercise_notes": an object mapping exercise name to a single short execution cue. '
        "Use at most "
        f"{MAX_EXERCISE_NOTES} entries, each under {MAX_EXERCISE_NOTE_CHARS} characters. "
        "Keys must be exactly these names: "
        f"{', '.join(exercise_names)}."
    )
