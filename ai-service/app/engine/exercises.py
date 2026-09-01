"""The exercise catalogue and the filters that narrow it to one member.

The catalogue is data, not configuration: it lives in the module so that a
plan is reproducible from the code alone, with no database round-trip and no
per-environment drift. Every entry carries the four facts the generator needs
to reason about it - what movement it is, what it trains, the smallest
equipment tier that can perform it, and the joints it should be kept away
from.

Two modelling notes worth stating once.

The pattern axis is coarse on purpose. It groups a movement into its family
(a barbell curl is elbow flexion under the pull family, a triceps pushdown is
elbow extension under the push family), which is enough to build a day around.
Fine-grained selection is the job of ``muscles`` and ``compound``: primary
slots take compound movements only, so an accessory tagged to the same pattern
can never be mistaken for the day's main lift.

Order inside the catalogue is coaching preference, best first. Filtering keeps
that order, so "the first candidate that survives the filters" is a defensible
choice rather than an arbitrary one, and it is the same choice every time.
"""

from __future__ import annotations

from dataclasses import dataclass
from enum import StrEnum

from app.schemas import Assessment, Equipment, Limitation


class MovementPattern(StrEnum):
    SQUAT = "squat"
    HINGE = "hinge"
    HORIZONTAL_PUSH = "horizontal_push"
    VERTICAL_PUSH = "vertical_push"
    HORIZONTAL_PULL = "horizontal_pull"
    VERTICAL_PULL = "vertical_pull"
    LUNGE = "lunge"
    CARRY = "carry"
    CORE = "core"
    CONDITIONING = "conditioning"


class MuscleGroup(StrEnum):
    CHEST = "chest"
    BACK = "back"
    SHOULDERS = "shoulders"
    BICEPS = "biceps"
    TRICEPS = "triceps"
    QUADS = "quads"
    HAMSTRINGS = "hamstrings"
    GLUTES = "glutes"
    CALVES = "calves"
    CORE = "core"


#: Ordered from least to most equipment. A member can perform an exercise when
#: their own tier is at least the exercise's tier.
_EQUIPMENT_TIER: dict[Equipment, int] = {
    Equipment.BODYWEIGHT: 0,
    Equipment.HOME_BASIC: 1,
    Equipment.FULL_GYM: 2,
}


@dataclass(frozen=True, slots=True)
class Exercise:
    """One movement in the catalogue.

    ``equipment`` is the *minimum* tier required, not an exact match, and
    ``contraindications`` lists the limitations that rule the movement out
    entirely - this service never prescribes a "careful" version of something
    a member's joint cannot tolerate.
    """

    name: str
    pattern: MovementPattern
    muscles: tuple[MuscleGroup, ...]
    equipment: Equipment
    contraindications: tuple[Limitation, ...] = ()
    compound: bool = True


_P = MovementPattern
_M = MuscleGroup
_E = Equipment
_L = Limitation


CATALOGUE: tuple[Exercise, ...] = (
    # --- Squat pattern -----------------------------------------------------
    Exercise(
        "Back Squat",
        _P.SQUAT,
        (_M.QUADS, _M.GLUTES, _M.CORE),
        _E.FULL_GYM,
        (_L.KNEE, _L.LOWER_BACK, _L.HIP),
    ),
    Exercise(
        "Front Squat",
        _P.SQUAT,
        (_M.QUADS, _M.GLUTES, _M.CORE),
        _E.FULL_GYM,
        (_L.KNEE, _L.LOWER_BACK),
    ),
    Exercise("Hack Squat Machine", _P.SQUAT, (_M.QUADS, _M.GLUTES), _E.FULL_GYM, (_L.KNEE,)),
    Exercise("Leg Press", _P.SQUAT, (_M.QUADS, _M.GLUTES), _E.FULL_GYM, (_L.KNEE, _L.HIP)),
    Exercise("Goblet Squat", _P.SQUAT, (_M.QUADS, _M.GLUTES, _M.CORE), _E.HOME_BASIC, (_L.KNEE,)),
    Exercise("Dumbbell Box Squat", _P.SQUAT, (_M.QUADS, _M.GLUTES), _E.HOME_BASIC),
    Exercise("Bodyweight Squat", _P.SQUAT, (_M.QUADS, _M.GLUTES), _E.BODYWEIGHT, (_L.KNEE,)),
    Exercise("Box Squat to Bench", _P.SQUAT, (_M.QUADS, _M.GLUTES), _E.BODYWEIGHT),
    Exercise("Wall Sit", _P.SQUAT, (_M.QUADS,), _E.BODYWEIGHT, (), compound=False),
    Exercise(
        "Pistol Squat to Box", _P.SQUAT, (_M.QUADS, _M.GLUTES), _E.BODYWEIGHT, (_L.KNEE, _L.HIP)
    ),
    Exercise("Leg Extension", _P.SQUAT, (_M.QUADS,), _E.FULL_GYM, (_L.KNEE,), compound=False),
    Exercise("Standing Calf Raise", _P.SQUAT, (_M.CALVES,), _E.FULL_GYM, (), compound=False),
    Exercise("Seated Calf Raise", _P.SQUAT, (_M.CALVES,), _E.FULL_GYM, (), compound=False),
    Exercise("Dumbbell Calf Raise", _P.SQUAT, (_M.CALVES,), _E.HOME_BASIC, (), compound=False),
    Exercise("Bodyweight Calf Raise", _P.SQUAT, (_M.CALVES,), _E.BODYWEIGHT, (), compound=False),
    # --- Hinge pattern -----------------------------------------------------
    Exercise(
        "Conventional Deadlift",
        _P.HINGE,
        (_M.HAMSTRINGS, _M.GLUTES, _M.BACK, _M.CORE),
        _E.FULL_GYM,
        (_L.LOWER_BACK, _L.HIP),
    ),
    Exercise(
        "Trap Bar Deadlift",
        _P.HINGE,
        (_M.HAMSTRINGS, _M.GLUTES, _M.QUADS, _M.BACK),
        _E.FULL_GYM,
        (_L.LOWER_BACK,),
    ),
    Exercise(
        "Romanian Deadlift",
        _P.HINGE,
        (_M.HAMSTRINGS, _M.GLUTES, _M.BACK),
        _E.FULL_GYM,
        (_L.LOWER_BACK,),
    ),
    Exercise("Barbell Hip Thrust", _P.HINGE, (_M.GLUTES, _M.HAMSTRINGS), _E.FULL_GYM, (_L.HIP,)),
    Exercise(
        "Back Extension",
        _P.HINGE,
        (_M.GLUTES, _M.HAMSTRINGS, _M.BACK),
        _E.FULL_GYM,
        (_L.LOWER_BACK,),
    ),
    Exercise("Good Morning", _P.HINGE, (_M.HAMSTRINGS, _M.BACK), _E.FULL_GYM, (_L.LOWER_BACK,)),
    Exercise("Seated Leg Curl", _P.HINGE, (_M.HAMSTRINGS,), _E.FULL_GYM, (), compound=False),
    Exercise("Lying Leg Curl", _P.HINGE, (_M.HAMSTRINGS,), _E.FULL_GYM, (), compound=False),
    Exercise(
        "Dumbbell Romanian Deadlift",
        _P.HINGE,
        (_M.HAMSTRINGS, _M.GLUTES),
        _E.HOME_BASIC,
        (_L.LOWER_BACK,),
    ),
    Exercise(
        "Kettlebell Swing",
        _P.HINGE,
        (_M.GLUTES, _M.HAMSTRINGS, _M.CORE),
        _E.HOME_BASIC,
        (_L.LOWER_BACK,),
    ),
    Exercise("Banded Pull-Through", _P.HINGE, (_M.GLUTES, _M.HAMSTRINGS), _E.HOME_BASIC),
    Exercise("Dumbbell Hip Thrust", _P.HINGE, (_M.GLUTES, _M.HAMSTRINGS), _E.HOME_BASIC, (_L.HIP,)),
    Exercise("Glute Bridge", _P.HINGE, (_M.GLUTES, _M.HAMSTRINGS), _E.BODYWEIGHT),
    Exercise("Single-Leg Glute Bridge", _P.HINGE, (_M.GLUTES, _M.HAMSTRINGS), _E.BODYWEIGHT),
    Exercise(
        "Nordic Hamstring Curl",
        _P.HINGE,
        (_M.HAMSTRINGS,),
        _E.BODYWEIGHT,
        (_L.KNEE,),
        compound=False,
    ),
    # --- Horizontal push ---------------------------------------------------
    Exercise(
        "Barbell Bench Press",
        _P.HORIZONTAL_PUSH,
        (_M.CHEST, _M.TRICEPS, _M.SHOULDERS),
        _E.FULL_GYM,
        (_L.SHOULDER,),
    ),
    Exercise(
        "Incline Barbell Bench Press",
        _P.HORIZONTAL_PUSH,
        (_M.CHEST, _M.SHOULDERS, _M.TRICEPS),
        _E.FULL_GYM,
        (_L.SHOULDER,),
    ),
    Exercise(
        "Machine Chest Press",
        _P.HORIZONTAL_PUSH,
        (_M.CHEST, _M.TRICEPS),
        _E.FULL_GYM,
        (_L.SHOULDER,),
    ),
    Exercise(
        "Dip", _P.HORIZONTAL_PUSH, (_M.CHEST, _M.TRICEPS), _E.FULL_GYM, (_L.SHOULDER, _L.ELBOW)
    ),
    Exercise(
        "Cable Fly", _P.HORIZONTAL_PUSH, (_M.CHEST,), _E.FULL_GYM, (_L.SHOULDER,), compound=False
    ),
    Exercise(
        "Cable Triceps Pushdown",
        _P.HORIZONTAL_PUSH,
        (_M.TRICEPS,),
        _E.FULL_GYM,
        (_L.ELBOW,),
        compound=False,
    ),
    Exercise(
        "Skull Crusher", _P.HORIZONTAL_PUSH, (_M.TRICEPS,), _E.FULL_GYM, (_L.ELBOW,), compound=False
    ),
    Exercise(
        "Dumbbell Bench Press",
        _P.HORIZONTAL_PUSH,
        (_M.CHEST, _M.TRICEPS, _M.SHOULDERS),
        _E.HOME_BASIC,
        (_L.SHOULDER,),
    ),
    Exercise(
        "Incline Dumbbell Press",
        _P.HORIZONTAL_PUSH,
        (_M.CHEST, _M.SHOULDERS, _M.TRICEPS),
        _E.HOME_BASIC,
        (_L.SHOULDER,),
    ),
    Exercise(
        "Dumbbell Floor Press",
        _P.HORIZONTAL_PUSH,
        (_M.CHEST, _M.TRICEPS),
        _E.HOME_BASIC,
        (_L.SHOULDER,),
    ),
    Exercise(
        "Dumbbell Fly",
        _P.HORIZONTAL_PUSH,
        (_M.CHEST,),
        _E.HOME_BASIC,
        (_L.SHOULDER,),
        compound=False,
    ),
    Exercise(
        "Dumbbell Triceps Kickback",
        _P.HORIZONTAL_PUSH,
        (_M.TRICEPS,),
        _E.HOME_BASIC,
        (_L.ELBOW,),
        compound=False,
    ),
    Exercise("Push-Up", _P.HORIZONTAL_PUSH, (_M.CHEST, _M.TRICEPS, _M.CORE), _E.BODYWEIGHT),
    Exercise("Incline Push-Up", _P.HORIZONTAL_PUSH, (_M.CHEST, _M.TRICEPS), _E.BODYWEIGHT),
    Exercise(
        "Decline Push-Up",
        _P.HORIZONTAL_PUSH,
        (_M.CHEST, _M.SHOULDERS),
        _E.BODYWEIGHT,
        (_L.SHOULDER,),
    ),
    Exercise(
        "Diamond Push-Up", _P.HORIZONTAL_PUSH, (_M.TRICEPS, _M.CHEST), _E.BODYWEIGHT, (_L.ELBOW,)
    ),
    Exercise(
        "Bench Dip",
        _P.HORIZONTAL_PUSH,
        (_M.TRICEPS,),
        _E.BODYWEIGHT,
        (_L.SHOULDER, _L.ELBOW),
        compound=False,
    ),
    # --- Vertical push -----------------------------------------------------
    Exercise(
        "Overhead Press",
        _P.VERTICAL_PUSH,
        (_M.SHOULDERS, _M.TRICEPS, _M.CORE),
        _E.FULL_GYM,
        (_L.SHOULDER, _L.NECK),
    ),
    Exercise(
        "Machine Shoulder Press",
        _P.VERTICAL_PUSH,
        (_M.SHOULDERS, _M.TRICEPS),
        _E.FULL_GYM,
        (_L.SHOULDER,),
    ),
    Exercise(
        "Landmine Press", _P.VERTICAL_PUSH, (_M.SHOULDERS, _M.CHEST), _E.FULL_GYM, (_L.SHOULDER,)
    ),
    Exercise(
        "Cable Lateral Raise",
        _P.VERTICAL_PUSH,
        (_M.SHOULDERS,),
        _E.FULL_GYM,
        (_L.SHOULDER,),
        compound=False,
    ),
    Exercise(
        "Seated Dumbbell Shoulder Press",
        _P.VERTICAL_PUSH,
        (_M.SHOULDERS, _M.TRICEPS),
        _E.HOME_BASIC,
        (_L.SHOULDER,),
    ),
    Exercise(
        "Arnold Press", _P.VERTICAL_PUSH, (_M.SHOULDERS, _M.TRICEPS), _E.HOME_BASIC, (_L.SHOULDER,)
    ),
    Exercise(
        "Dumbbell Push Press",
        _P.VERTICAL_PUSH,
        (_M.SHOULDERS, _M.TRICEPS, _M.CORE),
        _E.HOME_BASIC,
        (_L.SHOULDER, _L.NECK),
    ),
    Exercise(
        "Dumbbell Lateral Raise",
        _P.VERTICAL_PUSH,
        (_M.SHOULDERS,),
        _E.HOME_BASIC,
        (_L.SHOULDER,),
        compound=False,
    ),
    Exercise(
        "Overhead Triceps Extension",
        _P.VERTICAL_PUSH,
        (_M.TRICEPS,),
        _E.HOME_BASIC,
        (_L.SHOULDER, _L.ELBOW),
        compound=False,
    ),
    Exercise(
        "Pike Push-Up", _P.VERTICAL_PUSH, (_M.SHOULDERS, _M.TRICEPS), _E.BODYWEIGHT, (_L.SHOULDER,)
    ),
    Exercise(
        "Handstand Push-Up",
        _P.VERTICAL_PUSH,
        (_M.SHOULDERS, _M.TRICEPS),
        _E.BODYWEIGHT,
        (_L.SHOULDER, _L.NECK),
    ),
    # --- Horizontal pull ---------------------------------------------------
    Exercise(
        "Barbell Bent-Over Row",
        _P.HORIZONTAL_PULL,
        (_M.BACK, _M.BICEPS),
        _E.FULL_GYM,
        (_L.LOWER_BACK,),
    ),
    Exercise("Seated Cable Row", _P.HORIZONTAL_PULL, (_M.BACK, _M.BICEPS), _E.FULL_GYM),
    Exercise("Chest-Supported Machine Row", _P.HORIZONTAL_PULL, (_M.BACK, _M.BICEPS), _E.FULL_GYM),
    Exercise(
        "Pendlay Row", _P.HORIZONTAL_PULL, (_M.BACK, _M.BICEPS), _E.FULL_GYM, (_L.LOWER_BACK,)
    ),
    Exercise(
        "Cable Face Pull",
        _P.HORIZONTAL_PULL,
        (_M.SHOULDERS, _M.BACK),
        _E.FULL_GYM,
        (),
        compound=False,
    ),
    Exercise(
        "Barbell Curl", _P.HORIZONTAL_PULL, (_M.BICEPS,), _E.FULL_GYM, (_L.ELBOW,), compound=False
    ),
    Exercise(
        "Cable Curl", _P.HORIZONTAL_PULL, (_M.BICEPS,), _E.FULL_GYM, (_L.ELBOW,), compound=False
    ),
    Exercise("One-Arm Dumbbell Row", _P.HORIZONTAL_PULL, (_M.BACK, _M.BICEPS), _E.HOME_BASIC),
    Exercise(
        "Chest-Supported Dumbbell Row", _P.HORIZONTAL_PULL, (_M.BACK, _M.BICEPS), _E.HOME_BASIC
    ),
    Exercise("Band Row", _P.HORIZONTAL_PULL, (_M.BACK, _M.BICEPS), _E.HOME_BASIC),
    Exercise(
        "Band Face Pull",
        _P.HORIZONTAL_PULL,
        (_M.SHOULDERS, _M.BACK),
        _E.HOME_BASIC,
        (),
        compound=False,
    ),
    Exercise(
        "Dumbbell Rear Delt Fly",
        _P.HORIZONTAL_PULL,
        (_M.SHOULDERS,),
        _E.HOME_BASIC,
        (),
        compound=False,
    ),
    Exercise(
        "Dumbbell Curl",
        _P.HORIZONTAL_PULL,
        (_M.BICEPS,),
        _E.HOME_BASIC,
        (_L.ELBOW,),
        compound=False,
    ),
    Exercise(
        "Hammer Curl", _P.HORIZONTAL_PULL, (_M.BICEPS,), _E.HOME_BASIC, (_L.ELBOW,), compound=False
    ),
    Exercise("Inverted Row", _P.HORIZONTAL_PULL, (_M.BACK, _M.BICEPS), _E.BODYWEIGHT),
    Exercise(
        "Prone Y-T-W Raise",
        _P.HORIZONTAL_PULL,
        (_M.SHOULDERS, _M.BACK),
        _E.BODYWEIGHT,
        (),
        compound=False,
    ),
    Exercise(
        "Superman Hold",
        _P.HORIZONTAL_PULL,
        (_M.BACK,),
        _E.BODYWEIGHT,
        (_L.LOWER_BACK,),
        compound=False,
    ),
    # --- Vertical pull -----------------------------------------------------
    Exercise("Lat Pulldown", _P.VERTICAL_PULL, (_M.BACK, _M.BICEPS), _E.FULL_GYM),
    Exercise("Neutral-Grip Pulldown", _P.VERTICAL_PULL, (_M.BACK, _M.BICEPS), _E.FULL_GYM),
    Exercise("Assisted Pull-Up Machine", _P.VERTICAL_PULL, (_M.BACK, _M.BICEPS), _E.FULL_GYM),
    Exercise(
        "Straight-Arm Pulldown",
        _P.VERTICAL_PULL,
        (_M.BACK,),
        _E.FULL_GYM,
        (_L.SHOULDER,),
        compound=False,
    ),
    Exercise("Pull-Up", _P.VERTICAL_PULL, (_M.BACK, _M.BICEPS), _E.HOME_BASIC, (_L.ELBOW,)),
    Exercise("Chin-Up", _P.VERTICAL_PULL, (_M.BACK, _M.BICEPS), _E.HOME_BASIC, (_L.ELBOW,)),
    Exercise("Band-Assisted Pull-Up", _P.VERTICAL_PULL, (_M.BACK, _M.BICEPS), _E.HOME_BASIC),
    Exercise("Kneeling Band Pulldown", _P.VERTICAL_PULL, (_M.BACK, _M.BICEPS), _E.HOME_BASIC),
    # --- Lunge -------------------------------------------------------------
    Exercise("Walking Lunge", _P.LUNGE, (_M.QUADS, _M.GLUTES), _E.HOME_BASIC, (_L.KNEE, _L.HIP)),
    Exercise(
        "Bulgarian Split Squat", _P.LUNGE, (_M.QUADS, _M.GLUTES), _E.HOME_BASIC, (_L.KNEE, _L.HIP)
    ),
    Exercise("Dumbbell Step-Up", _P.LUNGE, (_M.QUADS, _M.GLUTES), _E.HOME_BASIC, (_L.KNEE,)),
    Exercise("Dumbbell Split Squat", _P.LUNGE, (_M.QUADS, _M.GLUTES), _E.HOME_BASIC, (_L.KNEE,)),
    Exercise("Reverse Lunge", _P.LUNGE, (_M.QUADS, _M.GLUTES), _E.BODYWEIGHT, (_L.KNEE,)),
    Exercise("Lateral Lunge", _P.LUNGE, (_M.QUADS, _M.GLUTES), _E.BODYWEIGHT, (_L.KNEE, _L.HIP)),
    Exercise("Bodyweight Step-Up", _P.LUNGE, (_M.QUADS, _M.GLUTES), _E.BODYWEIGHT, (_L.KNEE,)),
    # --- Carry -------------------------------------------------------------
    Exercise("Front-Racked Carry", _P.CARRY, (_M.CORE, _M.SHOULDERS), _E.FULL_GYM, (_L.SHOULDER,)),
    Exercise("Sled Push", _P.CARRY, (_M.QUADS, _M.GLUTES, _M.CORE), _E.FULL_GYM),
    Exercise("Farmer's Carry", _P.CARRY, (_M.CORE, _M.BACK), _E.HOME_BASIC),
    Exercise("Suitcase Carry", _P.CARRY, (_M.CORE,), _E.HOME_BASIC),
    Exercise(
        "Overhead Carry", _P.CARRY, (_M.CORE, _M.SHOULDERS), _E.HOME_BASIC, (_L.SHOULDER, _L.NECK)
    ),
    Exercise("Backpack Carry", _P.CARRY, (_M.CORE, _M.BACK), _E.BODYWEIGHT),
    # --- Core --------------------------------------------------------------
    Exercise("Cable Crunch", _P.CORE, (_M.CORE,), _E.FULL_GYM, (), compound=False),
    Exercise(
        "Hanging Leg Raise", _P.CORE, (_M.CORE,), _E.HOME_BASIC, (_L.LOWER_BACK,), compound=False
    ),
    Exercise("Hanging Knee Raise", _P.CORE, (_M.CORE,), _E.HOME_BASIC, (), compound=False),
    Exercise(
        "Ab Wheel Rollout", _P.CORE, (_M.CORE,), _E.HOME_BASIC, (_L.LOWER_BACK,), compound=False
    ),
    Exercise("Pallof Press", _P.CORE, (_M.CORE,), _E.HOME_BASIC, (), compound=False),
    Exercise("Plank", _P.CORE, (_M.CORE,), _E.BODYWEIGHT, (), compound=False),
    Exercise("Side Plank", _P.CORE, (_M.CORE,), _E.BODYWEIGHT, (), compound=False),
    Exercise("Dead Bug", _P.CORE, (_M.CORE,), _E.BODYWEIGHT, (), compound=False),
    Exercise("Bird Dog", _P.CORE, (_M.CORE,), _E.BODYWEIGHT, (), compound=False),
    Exercise("Hollow Body Hold", _P.CORE, (_M.CORE,), _E.BODYWEIGHT, (_L.NECK,), compound=False),
    Exercise("Reverse Crunch", _P.CORE, (_M.CORE,), _E.BODYWEIGHT, (), compound=False),
    Exercise(
        "Sit-Up", _P.CORE, (_M.CORE,), _E.BODYWEIGHT, (_L.LOWER_BACK, _L.NECK), compound=False
    ),
    Exercise("Russian Twist", _P.CORE, (_M.CORE,), _E.BODYWEIGHT, (_L.LOWER_BACK,), compound=False),
    # --- Conditioning ------------------------------------------------------
    Exercise("Rowing Machine Intervals", _P.CONDITIONING, (), _E.FULL_GYM, (_L.LOWER_BACK,)),
    Exercise("Assault Bike Intervals", _P.CONDITIONING, (), _E.FULL_GYM),
    Exercise("Treadmill Incline Walk", _P.CONDITIONING, (), _E.FULL_GYM),
    Exercise("Stationary Bike Steady State", _P.CONDITIONING, (), _E.FULL_GYM),
    Exercise("Jump Rope", _P.CONDITIONING, (), _E.BODYWEIGHT, (_L.KNEE,)),
    Exercise("Burpee", _P.CONDITIONING, (), _E.BODYWEIGHT, (_L.KNEE, _L.SHOULDER)),
    Exercise("Mountain Climber", _P.CONDITIONING, (), _E.BODYWEIGHT, (_L.SHOULDER,)),
    Exercise("Stair Climb", _P.CONDITIONING, (), _E.BODYWEIGHT, (_L.KNEE,)),
    Exercise("Shadow Boxing", _P.CONDITIONING, (), _E.BODYWEIGHT, (_L.SHOULDER,)),
    Exercise("Brisk Walk", _P.CONDITIONING, (), _E.BODYWEIGHT),
)


CATALOGUE_BY_NAME: dict[str, Exercise] = {exercise.name: exercise for exercise in CATALOGUE}


def is_disliked(name: str, disliked: list[str]) -> bool:
    """Case-insensitive substring match, so "squat" removes every squat."""
    lowered = name.casefold()
    return any(term.strip() and term.strip().casefold() in lowered for term in disliked)


def available_exercises(assessment: Assessment) -> tuple[Exercise, ...]:
    """Narrow the catalogue to what this member can and will actually do.

    Equipment and limitations are hard filters - the first is physical
    availability, the second is safety. Dislikes are a preference, so if
    honouring them would leave nothing to program with, they are dropped and
    the safety filters stand alone. A member who dislikes everything gets a
    plan they will grumble about, not an empty one.
    """
    tier = _EQUIPMENT_TIER[assessment.equipment]
    limitations = set(assessment.limitations)

    safe = tuple(
        exercise
        for exercise in CATALOGUE
        if _EQUIPMENT_TIER[exercise.equipment] <= tier
        and not limitations.intersection(exercise.contraindications)
    )

    if not assessment.disliked_exercises:
        return safe

    liked = tuple(
        exercise
        for exercise in safe
        if not is_disliked(exercise.name, assessment.disliked_exercises)
    )
    return liked or safe
