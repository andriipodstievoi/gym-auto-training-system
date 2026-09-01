"""Assembly: from an assessment to a finished, periodised plan.

The order of work is deliberate. One week of sessions is designed first - the
blueprint - and only then projected across the mesocycle. A member repeats the
same movements for the whole block and progresses on load and reps, which is
what makes progression legible to them and to us; rebuilding the exercise
selection every week would give a plan that looks varied and trains nothing.

Determinism is a property of the whole module. There is no randomness, no
clock, no set iteration and no dictionary ordering that depends on anything
but insertion. Where a choice has to be made between equally valid exercises,
it is made by rotating through the catalogue in its declared preference order,
so two identical assessments produce two identical plans.
"""

from __future__ import annotations

from dataclasses import dataclass

from app.engine.exercises import (
    CATALOGUE,
    Exercise,
    MovementPattern,
    MuscleGroup,
    available_exercises,
)
from app.engine.notes import coaching_notes, conditioning_note, warmup_note
from app.engine.progression import mesocycle, peak_set_multiplier, scale_sets, shift_rir
from app.engine.splits import DayTemplate, choose_split
from app.engine.version import ENGINE_VERSION
from app.engine.volume import (
    WARMUP_EXERCISE_NAME,
    WARMUP_MINUTES,
    Prescription,
    estimate_session_minutes,
    has_conditioning_finisher,
    prescription,
    round_half_up,
    session_set_budget,
    weekly_set_targets,
)
from app.schemas import Assessment, PlanDay, PlanExercise, PlanWeek, TrainingPlan

_CATALOGUE_INDEX: dict[str, int] = {
    exercise.name: index for index, exercise in enumerate(CATALOGUE)
}

#: A muscle group with fewer sets left than this is not worth another
#: exercise; the remainder is noise against week-to-week load progression.
_MIN_ACCESSORY_NEED = 2


@dataclass(frozen=True, slots=True)
class _Slot:
    """One exercise in the blueprint, before the week multipliers apply."""

    exercise: Exercise
    sets: int
    primary: bool


@dataclass(frozen=True, slots=True)
class _DayBlueprint:
    index: int
    label: str
    slots: tuple[_Slot, ...]
    finisher: Exercise | None


class _Rotation:
    """Deterministic round-robin over candidate lists.

    Two days in the same week that call for the same movement pattern should
    not get the same exercise. Counting how often each key has been drawn from
    and stepping through the candidates gives that variety without a random
    number generator anywhere near a training plan.
    """

    def __init__(self) -> None:
        self._counts: dict[str, int] = {}

    def pick(self, key: str, candidates: list[Exercise]) -> Exercise:
        count = self._counts.get(key, 0)
        self._counts[key] = count + 1
        return candidates[count % len(candidates)]


def generate_plan(assessment: Assessment) -> TrainingPlan:
    """Turn a screened assessment into a full mesocycle.

    The caller is responsible for the PAR-Q+ gate. By the time an assessment
    reaches this function it is treated as cleared to train.
    """
    split = choose_split(assessment.days_per_week, assessment.experience)
    templates = split.days[: assessment.days_per_week]
    catalogue = available_exercises(assessment)

    blueprint = _build_week_blueprint(assessment, templates, catalogue)
    weeks = _project_mesocycle(assessment, blueprint)
    block = mesocycle(assessment.experience)

    return TrainingPlan(
        engine_version=ENGINE_VERSION,
        llm_used=False,
        split=split.name,
        weeks=weeks,
        coaching_notes=coaching_notes(
            locale=assessment.locale,
            split=split.name,
            days=assessment.days_per_week,
            minutes=assessment.minutes_per_session,
            weeks=len(block),
            deload_week=block[-1].index,
            warmup_minutes=WARMUP_MINUTES,
            long_rests=prescription(assessment.goal).long_rests,
        ),
    )


# --- week one ---------------------------------------------------------------


def _build_week_blueprint(
    assessment: Assessment,
    templates: tuple[DayTemplate, ...],
    catalogue: tuple[Exercise, ...],
) -> tuple[_DayBlueprint, ...]:
    plan = prescription(assessment.goal)
    weekly = weekly_set_targets(assessment)
    frequency = _training_frequency(templates)
    peak = peak_set_multiplier(assessment.experience)
    rotation = _Rotation()

    days: list[_DayBlueprint] = []
    for index, template in enumerate(templates, start=1):
        finisher_wanted = has_conditioning_finisher(assessment.goal, index)
        budget = session_set_budget(assessment, peak, finisher_wanted)
        slots = _build_day(template, catalogue, plan, weekly, frequency, budget, rotation)
        finisher = _pick_finisher(catalogue, rotation) if finisher_wanted else None
        days.append(_DayBlueprint(index, template.label, slots, finisher))
    return tuple(days)


def _training_frequency(templates: tuple[DayTemplate, ...]) -> dict[MuscleGroup, int]:
    """How many days a week each muscle group is scheduled to be trained."""
    frequency: dict[MuscleGroup, int] = {}
    for template in templates:
        for muscle in template.muscles:
            frequency[muscle] = frequency.get(muscle, 0) + 1
    return frequency


def _build_day(
    template: DayTemplate,
    catalogue: tuple[Exercise, ...],
    plan: Prescription,
    weekly: dict[MuscleGroup, int],
    frequency: dict[MuscleGroup, int],
    budget: int,
    rotation: _Rotation,
) -> tuple[_Slot, ...]:
    """Fill one session: main lifts first, then accessories, then stop.

    The two loops answer different questions. The pattern loop asks what the
    day is *for* - a push day opens with a press whatever else happens. The
    accessory loop asks what is still owed to the muscle groups this day is
    responsible for, and buys the largest debt first.
    """
    remaining = {
        muscle: round_half_up(weekly[muscle] / frequency[muscle])
        for muscle in template.muscles
        if frequency.get(muscle, 0) > 0
    }
    per_slot = min(plan.primary_sets, max(2, budget // 2))

    slots: list[_Slot] = []
    used: set[str] = set()
    total = 0

    for pattern in template.patterns:
        if total >= budget:
            break
        candidates = _pattern_candidates(catalogue, pattern, used)
        if not candidates:
            continue
        exercise = rotation.pick(f"pattern:{pattern.value}", candidates)
        sets = min(per_slot, budget - total)
        # A slot is "primary" for prescription purposes only when a compound
        # movement filled it. The core slot on a lower-body day is structural,
        # but a cable crunch still wants accessory reps, not a triple.
        slots.append(_Slot(exercise, sets, primary=exercise.compound))
        used.add(exercise.name)
        total += sets
        _credit(remaining, exercise, sets)

    accessory_sets = min(plan.accessory_sets, max(2, budget // 2))
    while total < budget:
        muscle = _neediest_muscle(remaining, template.muscles)
        if muscle is None:
            break
        candidates = _muscle_candidates(catalogue, muscle, used)
        if not candidates:
            remaining[muscle] = 0
            continue
        exercise = rotation.pick(f"muscle:{muscle.value}", candidates)
        sets = min(accessory_sets, remaining[muscle], budget - total)
        if sets < 1:
            break
        slots.append(_Slot(exercise, sets, primary=False))
        used.add(exercise.name)
        total += sets
        _credit(remaining, exercise, sets)

    return tuple(slots) or _fallback_slots(template, catalogue, budget, per_slot)


def _pattern_candidates(
    catalogue: tuple[Exercise, ...], pattern: MovementPattern, used: set[str]
) -> list[Exercise]:
    """Compound movements for a pattern, preference order preserved.

    Core work has no compound entries worth the name, so that pattern falls
    back to whatever it has. Any other pattern that comes back empty - an
    overhead press for a member with a shoulder limitation, a pull-up with no
    bar - is simply skipped, and the day is built from the patterns that
    survived.
    """
    same_pattern = [
        exercise
        for exercise in catalogue
        if exercise.pattern is pattern and exercise.name not in used
    ]
    compound = [exercise for exercise in same_pattern if exercise.compound]
    return compound or same_pattern


def _muscle_candidates(
    catalogue: tuple[Exercise, ...], muscle: MuscleGroup, used: set[str]
) -> list[Exercise]:
    """Accessory work for one muscle, isolation first, compounds as a backup."""
    candidates = [
        exercise
        for exercise in catalogue
        if muscle in exercise.muscles
        and exercise.name not in used
        and exercise.pattern is not MovementPattern.CONDITIONING
    ]
    return sorted(
        candidates, key=lambda exercise: (exercise.compound, _CATALOGUE_INDEX[exercise.name])
    )


def _neediest_muscle(
    remaining: dict[MuscleGroup, int], order: tuple[MuscleGroup, ...]
) -> MuscleGroup | None:
    """The muscle group owed the most sets, ties broken by template order.

    Iterating the template's own order with a strict comparison means the
    first muscle group listed wins a tie, which is the priority the split
    already encoded.
    """
    best: MuscleGroup | None = None
    best_need = _MIN_ACCESSORY_NEED - 1
    for muscle in order:
        need = remaining.get(muscle, 0)
        if need > best_need:
            best, best_need = muscle, need
    return best


def _credit(remaining: dict[MuscleGroup, int], exercise: Exercise, sets: int) -> None:
    """Book an exercise's sets against the muscles it trains.

    The first listed muscle is the one the movement is chosen for and takes
    full credit; the rest are trained indirectly and take half, which is the
    convention coaches use when counting weekly volume.
    """
    for position, muscle in enumerate(exercise.muscles):
        if muscle not in remaining:
            continue
        credit = sets if position == 0 else sets // 2
        remaining[muscle] = max(0, remaining[muscle] - credit)


def _pick_finisher(catalogue: tuple[Exercise, ...], rotation: _Rotation) -> Exercise | None:
    candidates = [
        exercise for exercise in catalogue if exercise.pattern is MovementPattern.CONDITIONING
    ]
    if not candidates:
        return None
    return rotation.pick("conditioning", candidates)


def _fallback_slots(
    template: DayTemplate,
    catalogue: tuple[Exercise, ...],
    budget: int,
    per_slot: int,
) -> tuple[_Slot, ...]:
    """Last resort for a day whose every pattern was filtered away.

    It can happen: a bodyweight member with several limitations and a list of
    dislikes can empty an upper-body day of everything it asked for. Rather
    than emit a day with no exercises, take whatever the catalogue still
    offers for the day's muscle groups, and failing that anything at all.
    """
    for wanted in (set(template.muscles), None):
        candidates = [
            exercise
            for exercise in catalogue
            if exercise.pattern is not MovementPattern.CONDITIONING
            and (wanted is None or wanted.intersection(exercise.muscles))
        ]
        if candidates:
            slots: list[_Slot] = []
            total = 0
            for exercise in candidates[:3]:
                sets = min(per_slot, budget - total)
                if sets < 1:
                    break
                slots.append(_Slot(exercise, sets, primary=True))
                total += sets
            if slots:
                return tuple(slots)
    return ()


# --- the rest of the block --------------------------------------------------


def _project_mesocycle(
    assessment: Assessment, blueprint: tuple[_DayBlueprint, ...]
) -> list[PlanWeek]:
    plan = prescription(assessment.goal)
    weeks: list[PlanWeek] = []
    for week in mesocycle(assessment.experience):
        days = [
            _materialise_day(assessment, plan, day, week.set_multiplier, week.rir_delta)
            for day in blueprint
        ]
        weeks.append(PlanWeek(index=week.index, deload=week.deload, days=days))
    return weeks


def _materialise_day(
    assessment: Assessment,
    plan: Prescription,
    blueprint: _DayBlueprint,
    set_multiplier: float,
    rir_delta: int,
) -> PlanDay:
    exercises = [
        PlanExercise(
            name=WARMUP_EXERCISE_NAME,
            sets=1,
            reps=f"{WARMUP_MINUTES} min",
            rir=None,
            notes=warmup_note(assessment.locale),
        )
    ]
    for slot in blueprint.slots:
        reps = plan.primary_reps if slot.primary else plan.accessory_reps
        base_rir = plan.primary_rir if slot.primary else plan.accessory_rir
        exercises.append(
            PlanExercise(
                name=slot.exercise.name,
                sets=scale_sets(slot.sets, set_multiplier),
                reps=reps,
                rir=shift_rir(base_rir, rir_delta),
            )
        )
    if blueprint.finisher is not None:
        exercises.append(
            PlanExercise(
                name=blueprint.finisher.name,
                sets=1,
                reps=f"{plan.conditioning_minutes} min",
                rir=None,
                notes=conditioning_note(assessment.locale),
            )
        )

    day = PlanDay(index=blueprint.index, label=blueprint.label, exercises=exercises)
    return _fit_to_clock(day, assessment)


def _fit_to_clock(day: PlanDay, assessment: Assessment) -> PlanDay:
    """Trim a written day until it fits the member's session length.

    The budget is computed before rounding, so a week can land a set or two
    over. This is the backstop that makes "the session fits" an invariant of
    the output rather than an intention of the arithmetic. Sets come off the
    last exercise that still has more than one, which takes them from
    accessories before main lifts.
    """
    limit = assessment.minutes_per_session
    while estimate_session_minutes(day, assessment.goal) > limit:
        trimmable = [
            index
            for index, exercise in enumerate(day.exercises)
            if exercise.rir is not None and exercise.sets > 1
        ]
        if trimmable:
            index = trimmable[-1]
            day.exercises[index] = day.exercises[index].model_copy(
                update={"sets": day.exercises[index].sets - 1}
            )
            continue
        working = [
            index for index, exercise in enumerate(day.exercises) if exercise.rir is not None
        ]
        if len(working) <= 1:
            break
        day.exercises.pop(working[-1])
    return day
