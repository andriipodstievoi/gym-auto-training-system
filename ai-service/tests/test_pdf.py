"""Tests for the printable plan.

The point of extracting the text back out of the generated file is that
reportlab fails silently on missing glyphs: a document full of black boxes
renders without raising anything. Asserting on bytes and page counts would
pass just as happily. Asserting on the characters would not.
"""

from __future__ import annotations

from io import BytesIO

import pytest
from pypdf import PdfReader

from app.engine import generate_plan
from app.pdf import pdf_filename, render_plan_pdf
from app.schemas import Assessment
from tests.conftest import make_assessment

CYRILLIC = "ЀЁЂЃЄЅІЇЈЉЊЋЌЍЎЏАБВГДЕЖЗИЙКЛМНОПРСТУФХЦЧШЩЪЫЬЭЮЯабвгдежзийклмнопрстуфхцчшщъыьэюя"
LATVIAN = "āēīūķļņšžčģĀĒĪŪĶĻŅŠŽČĢ"


def render(assessment: Assessment) -> bytes:
    return render_plan_pdf(generate_plan(assessment), assessment)


def extract(data: bytes) -> str:
    reader = PdfReader(BytesIO(data))
    return "\n".join(page.extract_text() for page in reader.pages)


def test_the_output_is_a_real_pdf_of_a_plausible_size():
    data = render(make_assessment())

    assert data.startswith(b"%PDF-")
    assert data.rstrip().endswith(b"%%EOF")
    assert len(data) > 20_000


def test_the_pdf_renders_the_plan_it_was_given():
    assessment = make_assessment(days_per_week=4)
    plan = generate_plan(assessment)

    text = extract(render_plan_pdf(plan, assessment))

    assert plan.split in text
    assert plan.coaching_notes[:40] in text
    assert plan.disclaimer[:40] in text
    for day in plan.weeks[0].days:
        assert day.label in text
    for exercise in plan.weeks[0].days[0].exercises:
        assert exercise.name in text
        assert exercise.reps in text


def test_the_pdf_marks_the_deload_week():
    assessment = make_assessment()
    plan = generate_plan(assessment)

    text = extract(render_plan_pdf(plan, assessment))

    assert f"Week {plan.weeks[-1].index} - deload week" in text
    assert "deload week" in text
    assert text.count("deload week") == 1


def test_every_week_and_day_reaches_the_page():
    assessment = make_assessment(days_per_week=3, experience="advanced")
    plan = generate_plan(assessment)

    text = extract(render_plan_pdf(plan, assessment))

    for week in plan.weeks:
        assert f"Week {week.index}" in text
        for day in week.days:
            assert day.label in text


def test_a_russian_plan_renders_cyrillic_that_can_be_read_back():
    assessment = make_assessment(locale="ru")
    plan = generate_plan(assessment)

    text = extract(render_plan_pdf(plan, assessment))

    assert "План тренировок" in text
    assert "Неделя" in text
    assert "разгрузочная неделя" in text
    assert plan.coaching_notes[:40] in text
    assert sum(1 for char in text if char in CYRILLIC) > 500


def test_a_latvian_plan_renders_its_diacritics():
    assessment = make_assessment(locale="lv")
    plan = generate_plan(assessment)

    text = extract(render_plan_pdf(plan, assessment))

    assert "Treniņu plāns" in text
    assert "Nedēļa" in text
    assert "Sadalījums" in text
    assert plan.coaching_notes[:40] in text
    assert sum(1 for char in text if char in LATVIAN) > 100


@pytest.mark.parametrize("locale", ["en", "lv", "ru"])
def test_no_glyph_is_dropped_on_the_way_into_the_document(locale: str):
    """A missing glyph is the failure this whole module is guarding against."""
    assessment = make_assessment(locale=locale)
    plan = generate_plan(assessment)

    text = extract(render_plan_pdf(plan, assessment))

    for word in plan.coaching_notes.split():
        stripped = word.strip(".,:;()")
        if len(stripped) > 4:
            assert stripped in text
            break


@pytest.mark.parametrize("goal", ["fat_loss", "muscle_gain", "strength", "general_fitness"])
@pytest.mark.parametrize("equipment", ["full_gym", "home_basic", "bodyweight"])
def test_every_kind_of_plan_renders(goal: str, equipment: str):
    data = render(make_assessment(goal=goal, equipment=equipment, days_per_week=5))

    assert data.startswith(b"%PDF-")


def test_the_filename_is_ascii_and_dated():
    plan = generate_plan(make_assessment(locale="ru"))

    name = pdf_filename(plan)

    assert name.isascii()
    assert name.startswith("speks-training-plan-")
    assert name.endswith(".pdf")
    assert plan.generated_at.date().isoformat() in name
