"""The contract between the Symfony app and this service.

Symfony owns the questionnaire UI and persistence; this service owns the
programming logic. Everything crossing the wire is defined here.
"""

from __future__ import annotations

from datetime import UTC, datetime
from enum import StrEnum

from pydantic import BaseModel, Field


class Goal(StrEnum):
    FAT_LOSS = "fat_loss"
    MUSCLE_GAIN = "muscle_gain"
    STRENGTH = "strength"
    GENERAL_FITNESS = "general_fitness"


class Experience(StrEnum):
    BEGINNER = "beginner"  # < 6 months of consistent training
    INTERMEDIATE = "intermediate"  # 6 months - 2 years
    ADVANCED = "advanced"  # 2 years+


class Equipment(StrEnum):
    FULL_GYM = "full_gym"
    HOME_BASIC = "home_basic"  # dumbbells / bands / pull-up bar
    BODYWEIGHT = "bodyweight"


class Limitation(StrEnum):
    SHOULDER = "shoulder"
    LOWER_BACK = "lower_back"
    KNEE = "knee"
    ELBOW = "elbow"
    HIP = "hip"
    NECK = "neck"


class Locale(StrEnum):
    EN = "en"
    LV = "lv"
    RU = "ru"


class ParQ(BaseModel):
    """PAR-Q+ style pre-participation screening.

    Any ``True`` here stops plan generation and routes the member to a
    physician instead. This is deliberately conservative.
    """

    heart_condition: bool = False
    chest_pain: bool = False
    dizziness_or_fainting: bool = False
    bone_or_joint_problem: bool = False
    blood_pressure_medication: bool = False
    recent_surgery: bool = False
    pregnancy: bool = False
    other_reason_not_to_exercise: bool = False

    def red_flags(self) -> list[str]:
        return [name for name, raised in self.model_dump().items() if raised]


class Profile(BaseModel):
    age: int = Field(ge=14, le=90)
    height_cm: int = Field(ge=120, le=230)
    weight_kg: float = Field(ge=35, le=250)


class Assessment(BaseModel):
    """A completed questionnaire, ready to be turned into a programme."""

    profile: Profile
    goal: Goal
    experience: Experience
    days_per_week: int = Field(ge=2, le=6)
    minutes_per_session: int = Field(ge=30, le=120)
    equipment: Equipment
    limitations: list[Limitation] = Field(default_factory=list)
    disliked_exercises: list[str] = Field(default_factory=list)
    par_q: ParQ = Field(default_factory=ParQ)
    locale: Locale = Locale.EN


class PlanExercise(BaseModel):
    name: str
    sets: int
    reps: str  # "6-8" or "AMRAP" - a range, not a single number
    rir: int | None  # reps in reserve; None for warm-ups and cardio
    notes: str = ""


class PlanDay(BaseModel):
    index: int
    label: str  # "Upper A", "Push", "Full body 1"
    exercises: list[PlanExercise]


class PlanWeek(BaseModel):
    index: int
    deload: bool = False
    days: list[PlanDay]


class PlanStatus(StrEnum):
    OK = "ok"
    MEDICAL_REFERRAL = "medical_referral"


class TrainingPlan(BaseModel):
    status: PlanStatus = PlanStatus.OK
    generated_at: datetime = Field(default_factory=lambda: datetime.now(UTC))
    engine_version: str
    llm_used: bool = False
    split: str = ""
    weeks: list[PlanWeek] = Field(default_factory=list)
    coaching_notes: str = ""
    disclaimer: str = (
        "This programme is general fitness guidance, not medical advice. "
        "Stop and consult a physician if you experience pain, dizziness or chest discomfort."
    )


class MedicalReferral(BaseModel):
    """Returned instead of a plan when PAR-Q+ screening raises a flag."""

    status: PlanStatus = PlanStatus.MEDICAL_REFERRAL
    red_flags: list[str]
    message: str = (
        "Your answers include a health flag that should be cleared by a doctor "
        "before starting a new training programme. Our trainers can work with you "
        "once you have medical clearance."
    )


class Health(BaseModel):
    status: str = "ok"
    service: str = "ai-service"
    version: str
    llm_enabled: bool
