"""How much work to prescribe, at what intensity, and whether it fits.

Three questions live here, and they are separable.

*How much* is weekly hard sets per muscle group. The base numbers sit in the
middle of the range the training-volume literature supports for a trained
adult, then move with training age (a beginner adapts to less and recovers
from less) and with the goal (a strength block spends its budget on fewer,
heavier sets; a fat-loss block trades some of it for conditioning).

*How hard* is the rep range and the reps-in-reserve target, which follow from
the goal alone. Low reps with two in reserve is a strength prescription; ten
to twelve reps with one or two in reserve is a hypertrophy prescription.

*Does it fit* is arithmetic. A set costs time - the work plus the rest that
makes the next set count - and a heavy set costs more of it than a light one.
The generator budgets sessions with these numbers so a 45-minute member is
never handed 90 minutes of work.
"""

from __future__ import annotations

from dataclasses import dataclass

from app.engine.exercises import CATALOGUE_BY_NAME, MovementPattern, MuscleGroup
from app.schemas import Assessment, Experience, Goal, PlanDay

#: Minutes reserved at the start of every session for general warm-up and
#: ramp-up sets. Not counted as working volume.
WARMUP_MINUTES = 8

#: The name of the warm-up row the generator puts at the top of every day.
WARMUP_EXERCISE_NAME = "Warm-up"

#: A session is never budgeted below this, even at the shortest permitted
#: duration. The generator still trims against the clock afterwards, so this
#: is a floor on the plan's ambition, not a promise that overruns time.
MIN_SESSION_SETS = 3

#: Weekly hard sets per muscle group for an intermediate member training for
#: muscle gain. Everything else is a scaling of this.
BASE_WEEKLY_SETS: dict[MuscleGroup, int] = {
    MuscleGroup.CHEST: 14,
    MuscleGroup.BACK: 16,
    MuscleGroup.SHOULDERS: 12,
    MuscleGroup.BICEPS: 8,
    MuscleGroup.TRICEPS: 8,
    MuscleGroup.QUADS: 14,
    MuscleGroup.HAMSTRINGS: 10,
    MuscleGroup.GLUTES: 10,
    MuscleGroup.CALVES: 8,
    MuscleGroup.CORE: 8,
}

#: Training age scales tolerance for volume more than anything else does.
EXPERIENCE_VOLUME_FACTOR: dict[Experience, float] = {
    Experience.BEGINNER: 0.70,
    Experience.INTERMEDIATE: 1.00,
    Experience.ADVANCED: 1.25,
}

#: Goal scales it again: strength buys intensity with volume, fat loss and
#: general fitness give some of the budget back to conditioning.
GOAL_VOLUME_FACTOR: dict[Goal, float] = {
    Goal.MUSCLE_GAIN: 1.00,
    Goal.STRENGTH: 0.85,
    Goal.FAT_LOSS: 0.80,
    Goal.GENERAL_FITNESS: 0.75,
}


@dataclass(frozen=True, slots=True)
class Prescription:
    """Intensity for one goal, split between main lifts and accessories."""

    primary_reps: str
    primary_rir: int
    primary_sets: int
    accessory_reps: str
    accessory_rir: int
    accessory_sets: int
    #: Work plus rest for one set. Heavy sets need longer rests, so a strength
    #: session buys fewer sets with the same clock.
    minutes_per_set: float
    #: Length of the conditioning finisher, when the goal calls for one.
    conditioning_minutes: int
    #: Whether the goal's rest periods are long enough to mention as such.
    long_rests: bool


GOAL_PRESCRIPTION: dict[Goal, Prescription] = {
    Goal.STRENGTH: Prescription("3-5", 2, 4, "6-8", 2, 3, 3.5, 0, True),
    Goal.MUSCLE_GAIN: Prescription("6-8", 2, 4, "10-12", 2, 3, 2.5, 0, True),
    Goal.FAT_LOSS: Prescription("8-12", 2, 3, "12-15", 1, 3, 2.0, 12, False),
    Goal.GENERAL_FITNESS: Prescription("8-10", 3, 3, "12-15", 2, 3, 2.2, 10, False),
}


def prescription(goal: Goal) -> Prescription:
    return GOAL_PRESCRIPTION[goal]


def weekly_set_targets(assessment: Assessment) -> dict[MuscleGroup, int]:
    """Weekly hard sets per muscle group for this member.

    The result is a target, not a promise: the session time budget can cut it
    down, and a muscle no day in the split trains gets nothing.
    """
    experience_factor = EXPERIENCE_VOLUME_FACTOR[assessment.experience]
    goal_factor = GOAL_VOLUME_FACTOR[assessment.goal]
    return {
        muscle: max(2, round_half_up(base * experience_factor * goal_factor))
        for muscle, base in BASE_WEEKLY_SETS.items()
    }


def round_half_up(value: float) -> int:
    """Round half away from zero.

    Python's built-in ``round`` breaks ties to even, which makes the same
    arithmetic produce different set counts for inputs a coach would consider
    equivalent. Determinism is easier to defend with one obvious rule.
    """
    return int(value + 0.5) if value >= 0 else -int(-value + 0.5)


def conditioning_minutes(goal: Goal) -> int:
    return GOAL_PRESCRIPTION[goal].conditioning_minutes


def has_conditioning_finisher(goal: Goal, day_index: int) -> bool:
    """Whether this day ends with a conditioning block.

    Fat loss gets one every session, because the energy cost is the point.
    General fitness alternates, so cardiovascular work happens without
    crowding out the lifting. Strength and muscle gain get none - conditioning
    would compete for the same recovery the main lifts need.
    """
    if goal is Goal.FAT_LOSS:
        return True
    if goal is Goal.GENERAL_FITNESS:
        return day_index % 2 == 1
    return False


def session_set_budget(assessment: Assessment, peak_multiplier: float, has_finisher: bool) -> int:
    """How many working sets a session can hold at its heaviest week.

    The budget is computed against the peak week of the mesocycle rather than
    week one, so the plan still fits the member's clock when volume has
    climbed. Every later week is therefore comfortably inside it.
    """
    plan = prescription(assessment.goal)
    available = assessment.minutes_per_session - WARMUP_MINUTES
    if has_finisher:
        available -= plan.conditioning_minutes
    peak_sets = available / plan.minutes_per_set
    return max(MIN_SESSION_SETS, int(peak_sets / peak_multiplier))


def estimate_session_minutes(day: PlanDay, goal: Goal) -> float:
    """Clock time for one day as written, using the same costs as the budget.

    Written against ``PlanDay`` rather than the generator's internals so tests
    and the PDF can ask the question of a finished plan.
    """
    plan = prescription(goal)
    minutes = 0.0
    for exercise in day.exercises:
        if exercise.name == WARMUP_EXERCISE_NAME:
            minutes += WARMUP_MINUTES
            continue
        catalogued = CATALOGUE_BY_NAME.get(exercise.name)
        if catalogued is not None and catalogued.pattern is MovementPattern.CONDITIONING:
            minutes += plan.conditioning_minutes
            continue
        minutes += exercise.sets * plan.minutes_per_set
    return minutes


def working_sets(day: PlanDay) -> int:
    """Sets that count as training volume - warm-up and conditioning do not."""
    total = 0
    for exercise in day.exercises:
        if exercise.name == WARMUP_EXERCISE_NAME:
            continue
        catalogued = CATALOGUE_BY_NAME.get(exercise.name)
        if catalogued is not None and catalogued.pattern is MovementPattern.CONDITIONING:
            continue
        total += exercise.sets
    return total
