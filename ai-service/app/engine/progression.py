"""The mesocycle: how the same session changes from week to week.

A block accumulates work and then removes it. Volume climbs ten percent a
week while the intensity target creeps up - reps in reserve fall from two
toward zero, which is the same as saying each set is taken closer to failure
- and the final week is a deload: roughly half the sets, and every set
stopped well short. That is the one deload in the block, always last, so the
member finishes rested and the next block can start heavier.

Block length follows training age. A beginner accumulates fatigue slowly and
progresses on load alone for a while, so four weeks is enough before a reset;
an advanced lifter needs the longer runway to make the accumulation matter.
"""

from __future__ import annotations

from dataclasses import dataclass

from app.engine.volume import round_half_up
from app.schemas import Experience

MESOCYCLE_WEEKS: dict[Experience, int] = {
    Experience.BEGINNER: 4,
    Experience.INTERMEDIATE: 5,
    Experience.ADVANCED: 6,
}

#: Weekly volume step during the accumulation weeks.
WEEKLY_SET_STEP = 0.10

#: How close to failure the accumulation weeks are allowed to get. Reps in
#: reserve never drop more than this below the goal's baseline.
MAX_RIR_REDUCTION = 2

DELOAD_SET_MULTIPLIER = 0.55
DELOAD_RIR_DELTA = 3

#: Reps in reserve is clamped to this range: zero is a set taken to failure,
#: five is barely a working set.
MIN_RIR = 0
MAX_RIR = 5


@dataclass(frozen=True, slots=True)
class WeekPrescription:
    """What happens to the base session in one week of the block."""

    index: int
    set_multiplier: float
    rir_delta: int
    deload: bool


def mesocycle(experience: Experience) -> tuple[WeekPrescription, ...]:
    """The full block for a training age, deload included and always last."""
    weeks = MESOCYCLE_WEEKS[experience]
    accumulation = tuple(
        WeekPrescription(
            index=index,
            set_multiplier=1.0 + WEEKLY_SET_STEP * (index - 1),
            rir_delta=-min(index - 1, MAX_RIR_REDUCTION),
            deload=False,
        )
        for index in range(1, weeks)
    )
    deload = WeekPrescription(
        index=weeks,
        set_multiplier=DELOAD_SET_MULTIPLIER,
        rir_delta=DELOAD_RIR_DELTA,
        deload=True,
    )
    return (*accumulation, deload)


def peak_set_multiplier(experience: Experience) -> float:
    """The heaviest week's multiplier, which is what the clock must survive."""
    return max(week.set_multiplier for week in mesocycle(experience))


def scale_sets(base_sets: int, multiplier: float) -> int:
    """Apply a week's volume multiplier to one exercise.

    Never returns zero: an exercise that survives into a week is performed in
    that week, even during the deload.
    """
    return max(1, round_half_up(base_sets * multiplier))


def shift_rir(base_rir: int | None, delta: int) -> int | None:
    """Move an intensity target, leaving warm-ups and conditioning alone."""
    if base_rir is None:
        return None
    return max(MIN_RIR, min(MAX_RIR, base_rir + delta))
