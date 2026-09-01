"""Tests for the deterministic rule engine.

These are the tests that matter most in this service. The engine is the
product: if it drifts, members get worse programmes and nobody sees an
exception.
"""

from __future__ import annotations

from itertools import pairwise

import pytest

from app.engine import ENGINE_VERSION, generate_plan
from app.engine.exercises import CATALOGUE, CATALOGUE_BY_NAME, MovementPattern
from app.engine.progression import MESOCYCLE_WEEKS
from app.engine.volume import (
    WARMUP_EXERCISE_NAME,
    estimate_session_minutes,
    weekly_set_targets,
    working_sets,
)
from app.schemas import Equipment, Experience, Goal, Limitation, PlanStatus, TrainingPlan
from tests.conftest import make_assessment

ALL_EXPERIENCES = list(Experience)
ALL_FREQUENCIES = [2, 3, 4, 5, 6]


def plan_exercise_names(plan: TrainingPlan) -> set[str]:
    """Every prescribed movement, warm-up rows excluded."""
    return {
        exercise.name
        for week in plan.weeks
        for day in week.days
        for exercise in day.exercises
        if exercise.name != WARMUP_EXERCISE_NAME
    }


def week_volume(plan: TrainingPlan, index: int) -> int:
    return sum(working_sets(day) for day in plan.weeks[index].days)


# --- the catalogue ----------------------------------------------------------


def test_catalogue_is_large_enough_to_program_from():
    assert len(CATALOGUE) >= 60
    assert len(CATALOGUE_BY_NAME) == len(CATALOGUE), "exercise names must be unique"


def test_catalogue_covers_every_pattern_at_every_equipment_tier():
    for equipment in Equipment:
        available = {exercise.pattern for exercise in CATALOGUE if exercise.equipment is equipment}
        assert available, f"{equipment} has no exercises of its own"
    for pattern in MovementPattern:
        assert any(exercise.pattern is pattern for exercise in CATALOGUE), pattern


# --- determinism ------------------------------------------------------------


def test_the_same_assessment_generates_an_identical_plan():
    assessment = make_assessment(
        goal="fat_loss",
        experience="advanced",
        days_per_week=5,
        limitations=["knee"],
        disliked_exercises=["burpee"],
    )

    first = generate_plan(assessment).model_dump(mode="json")
    second = generate_plan(assessment).model_dump(mode="json")

    del first["generated_at"], second["generated_at"]
    assert first == second


def test_plans_are_stamped_with_the_engine_version():
    plan = generate_plan(make_assessment())

    assert plan.engine_version == ENGINE_VERSION == "1.0.0"
    assert plan.status is PlanStatus.OK
    assert plan.llm_used is False


# --- shape of a week --------------------------------------------------------


@pytest.mark.parametrize("days", ALL_FREQUENCIES)
@pytest.mark.parametrize("experience", ALL_EXPERIENCES)
def test_every_frequency_and_experience_fills_every_day(days: int, experience: Experience):
    plan = generate_plan(make_assessment(days_per_week=days, experience=experience.value))

    assert plan.split, "a plan always names its split"
    for week in plan.weeks:
        assert len(week.days) == days
        assert [day.index for day in week.days] == list(range(1, days + 1))
        assert len({day.label for day in week.days}) == days
        for day in week.days:
            working = [item for item in day.exercises if item.rir is not None]
            assert working, f"{day.label} in week {week.index} has no working exercises"
            assert day.exercises[0].name == WARMUP_EXERCISE_NAME


@pytest.mark.parametrize("days", ALL_FREQUENCIES)
def test_no_exercise_is_prescribed_twice_in_the_same_day(days: int):
    plan = generate_plan(make_assessment(days_per_week=days))

    for week in plan.weeks:
        for day in week.days:
            names = [item.name for item in day.exercises]
            assert len(names) == len(set(names))


def test_every_prescribed_exercise_comes_from_the_catalogue():
    plan = generate_plan(make_assessment(days_per_week=6, experience="advanced"))

    assert plan_exercise_names(plan) <= set(CATALOGUE_BY_NAME)


# --- filtering --------------------------------------------------------------


def test_bodyweight_assessments_never_get_barbell_or_machine_work():
    plan = generate_plan(make_assessment(equipment="bodyweight", days_per_week=3))

    names = plan_exercise_names(plan)
    assert names
    for name in names:
        assert CATALOGUE_BY_NAME[name].equipment is Equipment.BODYWEIGHT
    forbidden = ("barbell", "machine", "cable", "dumbbell", "kettlebell", "band", "sled", "press")
    assert not [name for name in names if any(word in name.lower() for word in forbidden)]


def test_knee_limitation_excludes_deep_knee_flexion():
    plan = generate_plan(make_assessment(limitations=["knee"], days_per_week=5))

    names = plan_exercise_names(plan)
    for name in names:
        assert Limitation.KNEE not in CATALOGUE_BY_NAME[name].contraindications
    for banned in ("Back Squat", "Leg Press", "Walking Lunge", "Bulgarian Split Squat"):
        assert banned not in names
    assert not [name for name in names if CATALOGUE_BY_NAME[name].pattern is MovementPattern.LUNGE]


def test_shoulder_limitation_excludes_overhead_pressing():
    plan = generate_plan(make_assessment(limitations=["shoulder"], days_per_week=6))

    names = plan_exercise_names(plan)
    overhead = [
        name for name in names if CATALOGUE_BY_NAME[name].pattern is MovementPattern.VERTICAL_PUSH
    ]
    assert not overhead
    for banned in ("Overhead Press", "Pike Push-Up", "Arnold Press", "Barbell Bench Press"):
        assert banned not in names


def test_several_limitations_still_produce_a_usable_plan():
    plan = generate_plan(
        make_assessment(
            limitations=["knee", "shoulder", "lower_back", "hip", "elbow", "neck"],
            equipment="home_basic",
            days_per_week=4,
        )
    )

    names = plan_exercise_names(plan)
    assert names
    for name in names:
        assert not CATALOGUE_BY_NAME[name].contraindications


def test_disliked_exercises_are_excluded_case_insensitively():
    plan = generate_plan(
        make_assessment(disliked_exercises=["BURPEE", "deadlift", "Squat"], days_per_week=5)
    )

    names = plan_exercise_names(plan)
    assert names
    for name in names:
        lowered = name.lower()
        assert "burpee" not in lowered
        assert "deadlift" not in lowered
        assert "squat" not in lowered


def test_disliking_everything_still_returns_a_plan():
    """Dislikes are a preference; safety filters are not. Preference yields."""
    plan = generate_plan(
        make_assessment(
            equipment="bodyweight",
            limitations=["knee", "shoulder", "lower_back", "hip", "elbow", "neck"],
            disliked_exercises=["a", "e", "i", "o", "u", "-"],
        )
    )

    for week in plan.weeks:
        for day in week.days:
            assert [item for item in day.exercises if item.rir is not None]


# --- volume, intensity, time ------------------------------------------------


def test_weekly_set_targets_rise_with_training_age():
    totals = [
        sum(weekly_set_targets(make_assessment(experience=experience.value)).values())
        for experience in (Experience.BEGINNER, Experience.INTERMEDIATE, Experience.ADVANCED)
    ]

    assert totals == sorted(totals)
    assert len(set(totals)) == 3


def test_weekly_set_targets_follow_the_goal():
    def total(goal: Goal) -> int:
        return sum(weekly_set_targets(make_assessment(goal=goal.value)).values())

    assert total(Goal.MUSCLE_GAIN) > total(Goal.STRENGTH)
    assert total(Goal.STRENGTH) > total(Goal.FAT_LOSS)
    assert total(Goal.FAT_LOSS) > total(Goal.GENERAL_FITNESS)


def test_prescribed_volume_rises_with_training_age():
    def volume(experience: Experience) -> int:
        assessment = make_assessment(
            experience=experience.value, days_per_week=4, minutes_per_session=120
        )
        return week_volume(generate_plan(assessment), 0)

    assert (
        volume(Experience.BEGINNER) < volume(Experience.INTERMEDIATE) < volume(Experience.ADVANCED)
    )


def test_prescribed_volume_is_higher_for_muscle_gain_than_general_fitness():
    def volume(goal: Goal) -> int:
        assessment = make_assessment(goal=goal.value, days_per_week=4, minutes_per_session=120)
        return week_volume(generate_plan(assessment), 0)

    assert volume(Goal.MUSCLE_GAIN) > volume(Goal.GENERAL_FITNESS)


@pytest.mark.parametrize("minutes", [30, 45, 60, 75, 90, 120])
@pytest.mark.parametrize("goal", list(Goal))
@pytest.mark.parametrize("days", [2, 4, 6])
def test_every_session_fits_the_members_clock(minutes: int, goal: Goal, days: int):
    assessment = make_assessment(
        minutes_per_session=minutes, goal=goal.value, days_per_week=days, experience="advanced"
    )

    plan = generate_plan(assessment)

    for week in plan.weeks:
        for day in week.days:
            assert estimate_session_minutes(day, assessment.goal) <= minutes


@pytest.mark.parametrize(
    ("goal", "primary_reps", "accessory_reps"),
    [
        (Goal.STRENGTH, "3-5", "6-8"),
        (Goal.MUSCLE_GAIN, "6-8", "10-12"),
        (Goal.FAT_LOSS, "8-12", "12-15"),
        (Goal.GENERAL_FITNESS, "8-10", "12-15"),
    ],
)
def test_rep_ranges_follow_the_goal(goal: Goal, primary_reps: str, accessory_reps: str):
    plan = generate_plan(make_assessment(goal=goal.value, minutes_per_session=90))

    ranges = {
        item.reps
        for week in plan.weeks
        for day in week.days
        for item in day.exercises
        if item.rir is not None
    }
    assert ranges <= {primary_reps, accessory_reps}
    assert primary_reps in ranges


def test_warm_ups_and_conditioning_carry_no_intensity_target():
    plan = generate_plan(make_assessment(goal="fat_loss", days_per_week=3))

    for week in plan.weeks:
        for day in week.days:
            for item in day.exercises:
                catalogued = CATALOGUE_BY_NAME.get(item.name)
                conditioning = (
                    catalogued is not None and catalogued.pattern is MovementPattern.CONDITIONING
                )
                if item.name == WARMUP_EXERCISE_NAME or conditioning:
                    assert item.rir is None
                else:
                    assert item.rir is not None


def test_fat_loss_plans_end_every_session_with_conditioning():
    plan = generate_plan(make_assessment(goal="fat_loss", days_per_week=4))

    for week in plan.weeks:
        for day in week.days:
            last = CATALOGUE_BY_NAME[day.exercises[-1].name]
            assert last.pattern is MovementPattern.CONDITIONING


def test_strength_plans_do_not_add_conditioning():
    plan = generate_plan(make_assessment(goal="strength", days_per_week=4))

    for name in plan_exercise_names(plan):
        assert CATALOGUE_BY_NAME[name].pattern is not MovementPattern.CONDITIONING


# --- progression ------------------------------------------------------------


@pytest.mark.parametrize("experience", ALL_EXPERIENCES)
def test_mesocycle_length_follows_training_age(experience: Experience):
    plan = generate_plan(make_assessment(experience=experience.value))

    assert len(plan.weeks) == MESOCYCLE_WEEKS[experience]
    assert [week.index for week in plan.weeks] == list(range(1, len(plan.weeks) + 1))


@pytest.mark.parametrize("days", ALL_FREQUENCIES)
@pytest.mark.parametrize("experience", ALL_EXPERIENCES)
def test_there_is_exactly_one_deload_and_it_is_the_last_week(days: int, experience: Experience):
    plan = generate_plan(make_assessment(days_per_week=days, experience=experience.value))

    deloads = [week for week in plan.weeks if week.deload]
    assert len(deloads) == 1
    assert deloads[0] is plan.weeks[-1]


@pytest.mark.parametrize("experience", ALL_EXPERIENCES)
def test_the_deload_cuts_both_volume_and_intensity(experience: Experience):
    plan = generate_plan(
        make_assessment(experience=experience.value, days_per_week=4, minutes_per_session=90)
    )

    assert week_volume(plan, -1) < week_volume(plan, -2)

    def intensity(index: int) -> list[int]:
        return [
            item.rir
            for day in plan.weeks[index].days
            for item in day.exercises
            if item.rir is not None
        ]

    deload, previous = intensity(-1), intensity(-2)
    assert len(deload) == len(previous)
    # Higher reps in reserve is a lighter set: every one of them backs off.
    assert all(after > before for after, before in zip(deload, previous, strict=True))


def test_intensity_or_volume_advances_every_accumulation_week():
    plan = generate_plan(make_assessment(experience="advanced", minutes_per_session=90))

    def snapshot(index: int) -> tuple[int, int]:
        week = plan.weeks[index]
        sets = sum(working_sets(day) for day in week.days)
        rir = sum(item.rir for day in week.days for item in day.exercises if item.rir is not None)
        return sets, rir

    accumulation = [snapshot(index) for index in range(len(plan.weeks) - 1)]
    for earlier, later in pairwise(accumulation):
        harder_volume = later[0] > earlier[0]
        harder_intensity = later[1] < earlier[1]
        assert harder_volume or harder_intensity


def test_the_same_exercises_run_through_the_whole_block():
    """A mesocycle progresses load, not novelty. Selection is week-invariant."""
    plan = generate_plan(make_assessment(days_per_week=3))

    per_week = [[[item.name for item in day.exercises] for day in week.days] for week in plan.weeks]
    assert all(week == per_week[0] for week in per_week)


# --- coaching notes ---------------------------------------------------------


@pytest.mark.parametrize("locale", ["en", "lv", "ru"])
def test_coaching_notes_are_written_in_the_members_language(locale: str):
    plan = generate_plan(make_assessment(locale=locale))

    assert plan.coaching_notes
    assert plan.split in plan.coaching_notes
    if locale == "ru":
        assert any("Ѐ" <= char <= "ӿ" for char in plan.coaching_notes)
    if locale == "lv":
        assert any(char in "āēīūķļņšžčģ" for char in plan.coaching_notes)
