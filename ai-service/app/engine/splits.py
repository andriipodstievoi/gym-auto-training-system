"""Choosing a weekly structure from training frequency and training age.

A split is two things: how often each muscle group is trained, and what the
member is asked to do when they walk in. Frequency decides most of it - two
days a week only works as full body, six days only works if the work is
divided - and experience breaks the ties, because a beginner benefits more
from repeating the main lifts often than from the specialisation a split like
push/pull/legs buys.

Each day template names its primary movement slots in the order they should be
performed (heaviest, most technical first) and the muscle groups eligible for
accessory work. The generator fills the slots; this module only decides the
shape.
"""

from __future__ import annotations

from dataclasses import dataclass

from app.engine.exercises import MovementPattern, MuscleGroup
from app.schemas import Experience

P = MovementPattern
M = MuscleGroup


@dataclass(frozen=True, slots=True)
class DayTemplate:
    label: str
    patterns: tuple[MovementPattern, ...]
    muscles: tuple[MuscleGroup, ...]


@dataclass(frozen=True, slots=True)
class Split:
    name: str
    days: tuple[DayTemplate, ...]


FULL_BODY_A = DayTemplate(
    "Full body A",
    (P.SQUAT, P.HORIZONTAL_PUSH, P.HORIZONTAL_PULL, P.HINGE, P.CORE),
    (M.QUADS, M.CHEST, M.BACK, M.HAMSTRINGS, M.SHOULDERS, M.CORE),
)
FULL_BODY_B = DayTemplate(
    "Full body B",
    (P.HINGE, P.VERTICAL_PULL, P.VERTICAL_PUSH, P.LUNGE, P.CORE),
    (M.HAMSTRINGS, M.BACK, M.SHOULDERS, M.GLUTES, M.TRICEPS, M.CORE),
)
FULL_BODY_C = DayTemplate(
    "Full body C",
    (P.SQUAT, P.HORIZONTAL_PUSH, P.VERTICAL_PULL, P.CARRY, P.CORE),
    (M.QUADS, M.CHEST, M.BACK, M.BICEPS, M.CALVES, M.CORE),
)

PUSH_A = DayTemplate(
    "Push A",
    (P.HORIZONTAL_PUSH, P.VERTICAL_PUSH),
    (M.CHEST, M.SHOULDERS, M.TRICEPS, M.CORE),
)
PUSH_B = DayTemplate(
    "Push B",
    (P.VERTICAL_PUSH, P.HORIZONTAL_PUSH),
    (M.SHOULDERS, M.CHEST, M.TRICEPS, M.CORE),
)
PULL_A = DayTemplate(
    "Pull A",
    (P.VERTICAL_PULL, P.HORIZONTAL_PULL),
    (M.BACK, M.BICEPS, M.SHOULDERS, M.CORE),
)
PULL_B = DayTemplate(
    "Pull B",
    (P.HORIZONTAL_PULL, P.VERTICAL_PULL),
    (M.BACK, M.SHOULDERS, M.BICEPS, M.CORE),
)
LEGS_A = DayTemplate(
    "Legs A",
    (P.SQUAT, P.HINGE, P.LUNGE),
    (M.QUADS, M.HAMSTRINGS, M.GLUTES, M.CALVES, M.CORE),
)
LEGS_B = DayTemplate(
    "Legs B",
    (P.HINGE, P.SQUAT, P.LUNGE),
    (M.HAMSTRINGS, M.GLUTES, M.QUADS, M.CALVES, M.CORE),
)

UPPER_A = DayTemplate(
    "Upper A",
    (P.HORIZONTAL_PUSH, P.HORIZONTAL_PULL, P.VERTICAL_PUSH, P.VERTICAL_PULL),
    (M.CHEST, M.BACK, M.SHOULDERS, M.TRICEPS, M.BICEPS),
)
UPPER_B = DayTemplate(
    "Upper B",
    (P.VERTICAL_PULL, P.VERTICAL_PUSH, P.HORIZONTAL_PULL, P.HORIZONTAL_PUSH),
    (M.BACK, M.SHOULDERS, M.CHEST, M.BICEPS, M.TRICEPS),
)
UPPER_C = DayTemplate(
    "Upper C",
    (P.HORIZONTAL_PULL, P.HORIZONTAL_PUSH, P.VERTICAL_PULL, P.CARRY),
    (M.BACK, M.CHEST, M.SHOULDERS, M.TRICEPS, M.BICEPS),
)
LOWER_A = DayTemplate(
    "Lower A",
    (P.SQUAT, P.HINGE, P.LUNGE),
    (M.QUADS, M.HAMSTRINGS, M.GLUTES, M.CALVES, M.CORE),
)
LOWER_B = DayTemplate(
    "Lower B",
    (P.HINGE, P.SQUAT, P.CORE),
    (M.HAMSTRINGS, M.GLUTES, M.QUADS, M.CALVES, M.CORE),
)
LOWER_C = DayTemplate(
    "Lower C",
    (P.LUNGE, P.HINGE, P.CORE),
    (M.GLUTES, M.QUADS, M.HAMSTRINGS, M.CALVES, M.CORE),
)


def choose_split(days_per_week: int, experience: Experience) -> Split:
    """Pick the split for a frequency and a training age.

    Beginners stay on whole-body or upper/lower shapes at every frequency:
    they gain more from practising the main patterns often than from the extra
    per-session volume a body-part split allows, and they recover fast enough
    to repeat them.
    """
    novice = experience is Experience.BEGINNER

    if days_per_week <= 2:
        return Split("Full body", (FULL_BODY_A, FULL_BODY_B))

    if days_per_week == 3:
        if novice:
            return Split("Full body", (FULL_BODY_A, FULL_BODY_B, FULL_BODY_C))
        return Split("Push / Pull / Legs", (PUSH_A, PULL_A, LEGS_A))

    if days_per_week == 4:
        return Split("Upper / Lower", (UPPER_A, LOWER_A, UPPER_B, LOWER_B))

    if days_per_week == 5:
        if novice:
            return Split(
                "Upper / Lower plus a full-body day",
                (UPPER_A, LOWER_A, UPPER_B, LOWER_B, FULL_BODY_C),
            )
        return Split(
            "Push / Pull / Legs plus Upper / Lower",
            (PUSH_A, PULL_A, LEGS_A, UPPER_B, LOWER_B),
        )

    if novice:
        return Split(
            "Upper / Lower, three times weekly",
            (UPPER_A, LOWER_A, UPPER_B, LOWER_B, UPPER_C, LOWER_C),
        )
    return Split(
        "Push / Pull / Legs, twice weekly",
        (PUSH_A, PULL_A, LEGS_A, PUSH_B, PULL_B, LEGS_B),
    )
