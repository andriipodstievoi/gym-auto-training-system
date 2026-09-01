"""Rendering a plan as a PDF the member can print and take to the gym.

reportlab is the whole toolchain here: it is a pure-Python library, so the
Docker image and CI need nothing beyond ``pip install``. Anything that renders
HTML would have dragged in a browser or a system binary, which is a poor trade
for a two-page table.

The fonts matter more than they look like they should. reportlab's built-in
Helvetica has no Cyrillic and no Latvian diacritics, and its failure mode is
silent - the glyphs come out as black boxes in a document that raised no
exception. The DejaVu faces vendored in ``app/fonts`` cover both alphabets and
are registered before anything is drawn.
"""

from __future__ import annotations

from io import BytesIO
from pathlib import Path

from reportlab.lib import colors
from reportlab.lib.pagesizes import A4
from reportlab.lib.styles import ParagraphStyle
from reportlab.lib.units import mm
from reportlab.pdfbase import pdfmetrics
from reportlab.pdfbase.ttfonts import TTFont
from reportlab.platypus import (
    KeepTogether,
    PageBreak,
    Paragraph,
    SimpleDocTemplate,
    Spacer,
    Table,
    TableStyle,
)

from app.schemas import Assessment, Locale, PlanDay, TrainingPlan

FONT_DIR = Path(__file__).parent / "fonts"
BODY_FONT = "DejaVuSans"
BOLD_FONT = "DejaVuSans-Bold"

_PAGE_MARGIN = 18 * mm
_INK = colors.HexColor("#1b1b1b")
_MUTED = colors.HexColor("#5a5a5a")
_RULE = colors.HexColor("#c8c8c8")
_DELOAD_BAND = colors.HexColor("#f0ece2")

_fonts_registered = False


def _ensure_fonts() -> None:
    """Register the vendored faces once per process."""
    global _fonts_registered
    if _fonts_registered:
        return
    pdfmetrics.registerFont(TTFont(BODY_FONT, str(FONT_DIR / "DejaVuSans.ttf")))
    pdfmetrics.registerFont(TTFont(BOLD_FONT, str(FONT_DIR / "DejaVuSans-Bold.ttf")))
    pdfmetrics.registerFontFamily(
        BODY_FONT, normal=BODY_FONT, bold=BOLD_FONT, italic=BODY_FONT, boldItalic=BOLD_FONT
    )
    _fonts_registered = True


_LABELS: dict[Locale, dict[str, str]] = {
    Locale.EN: {
        "title": "Training plan",
        "split": "Split",
        "sessions": "Sessions per week",
        "session_length": "Session length",
        "minutes": "min",
        "block": "Block length",
        "weeks": "weeks",
        "notes": "Coaching notes",
        "week": "Week",
        "deload": "deload week",
        "exercise": "Exercise",
        "sets": "Sets",
        "reps": "Reps",
        "rir": "RIR",
        "disclaimer": "Disclaimer",
        "generated": "Generated",
        "engine": "Engine",
    },
    Locale.LV: {
        "title": "Treniņu plāns",
        "split": "Sadalījums",
        "sessions": "Treniņi nedēļā",
        "session_length": "Treniņa ilgums",
        "minutes": "min",
        "block": "Cikla garums",
        "weeks": "nedēļas",
        "notes": "Trenera piezīmes",
        "week": "Nedēļa",
        "deload": "atslodzes nedēļa",
        "exercise": "Vingrinājums",
        "sets": "Sērijas",
        "reps": "Atkārtojumi",
        "rir": "RIR",
        "disclaimer": "Atruna",
        "generated": "Izveidots",
        "engine": "Dzinējs",
    },
    Locale.RU: {
        "title": "План тренировок",
        "split": "Сплит",
        "sessions": "Тренировок в неделю",
        "session_length": "Длительность тренировки",
        "minutes": "мин",
        "block": "Длина цикла",
        "weeks": "недель",
        "notes": "Заметки тренера",
        "week": "Неделя",
        "deload": "разгрузочная неделя",
        "exercise": "Упражнение",
        "sets": "Подходы",
        "reps": "Повторения",
        "rir": "RIR",
        "disclaimer": "Отказ от ответственности",
        "generated": "Создано",
        "engine": "Движок",
    },
}


def pdf_filename(plan: TrainingPlan) -> str:
    """An ASCII filename, so ``Content-Disposition`` needs no encoding games."""
    return f"speks-training-plan-{plan.generated_at.date().isoformat()}.pdf"


def render_plan_pdf(plan: TrainingPlan, assessment: Assessment) -> bytes:
    """Lay the plan out as a printable document and return the bytes."""
    _ensure_fonts()
    labels = _LABELS[assessment.locale]
    styles = _styles()

    buffer = BytesIO()
    document = SimpleDocTemplate(
        buffer,
        pagesize=A4,
        leftMargin=_PAGE_MARGIN,
        rightMargin=_PAGE_MARGIN,
        topMargin=_PAGE_MARGIN,
        bottomMargin=_PAGE_MARGIN,
        title=labels["title"],
        author="SPEKS",
    )

    story: list[object] = [
        Paragraph(_escape(labels["title"]), styles["title"]),
        Spacer(1, 4 * mm),
        _summary_table(plan, assessment, labels, styles),
        Spacer(1, 6 * mm),
        Paragraph(_escape(labels["notes"]), styles["heading"]),
        Paragraph(_escape(plan.coaching_notes), styles["body"]),
        Spacer(1, 4 * mm),
    ]

    for position, week in enumerate(plan.weeks):
        if position > 0:
            story.append(PageBreak())
        heading = f"{labels['week']} {week.index}"
        if week.deload:
            heading = f"{heading} - {labels['deload']}"
        story.append(Paragraph(_escape(heading), styles["week_deload" if week.deload else "week"]))
        story.append(Spacer(1, 2 * mm))
        for day in week.days:
            story.append(
                KeepTogether(
                    [
                        Paragraph(_escape(f"{day.index}. {day.label}"), styles["day"]),
                        _day_table(day, labels, styles),
                        Spacer(1, 4 * mm),
                    ]
                )
            )

    story.append(Spacer(1, 4 * mm))
    story.append(Paragraph(_escape(labels["disclaimer"]), styles["heading"]))
    story.append(Paragraph(_escape(plan.disclaimer), styles["small"]))

    document.build(story)
    return buffer.getvalue()


def _styles() -> dict[str, ParagraphStyle]:
    base = ParagraphStyle(
        "body", fontName=BODY_FONT, fontSize=9.5, leading=13.5, textColor=_INK, spaceAfter=3
    )
    return {
        "title": ParagraphStyle(
            "title", parent=base, fontName=BOLD_FONT, fontSize=20, leading=24, spaceAfter=0
        ),
        "heading": ParagraphStyle(
            "heading", parent=base, fontName=BOLD_FONT, fontSize=12, leading=16, spaceAfter=4
        ),
        "week": ParagraphStyle(
            "week", parent=base, fontName=BOLD_FONT, fontSize=14, leading=18, spaceAfter=2
        ),
        "week_deload": ParagraphStyle(
            "week_deload",
            parent=base,
            fontName=BOLD_FONT,
            fontSize=14,
            leading=18,
            spaceAfter=2,
            textColor=_MUTED,
        ),
        "day": ParagraphStyle(
            "day", parent=base, fontName=BOLD_FONT, fontSize=11, leading=15, spaceAfter=2
        ),
        "body": base,
        "cell": ParagraphStyle("cell", parent=base, fontSize=9, leading=12, spaceAfter=0),
        "cell_head": ParagraphStyle(
            "cell_head", parent=base, fontName=BOLD_FONT, fontSize=9, leading=12, spaceAfter=0
        ),
        "small": ParagraphStyle("small", parent=base, fontSize=8.5, leading=12, textColor=_MUTED),
    }


def _summary_table(
    plan: TrainingPlan,
    assessment: Assessment,
    labels: dict[str, str],
    styles: dict[str, ParagraphStyle],
) -> Table:
    minutes = f"{assessment.minutes_per_session} {labels['minutes']}"
    rows = [
        (labels["split"], plan.split),
        (labels["sessions"], str(assessment.days_per_week)),
        (labels["session_length"], minutes),
        (labels["block"], f"{len(plan.weeks)} {labels['weeks']}"),
        (labels["generated"], plan.generated_at.date().isoformat()),
        (labels["engine"], plan.engine_version),
    ]
    data = [
        [
            Paragraph(_escape(name), styles["cell_head"]),
            Paragraph(_escape(value), styles["cell"]),
        ]
        for name, value in rows
    ]
    table = Table(data, colWidths=[50 * mm, 120 * mm], hAlign="LEFT")
    table.setStyle(
        TableStyle(
            [
                ("VALIGN", (0, 0), (-1, -1), "TOP"),
                ("TOPPADDING", (0, 0), (-1, -1), 1),
                ("BOTTOMPADDING", (0, 0), (-1, -1), 1),
                ("LEFTPADDING", (0, 0), (-1, -1), 0),
            ]
        )
    )
    return table


def _day_table(day: PlanDay, labels: dict[str, str], styles: dict[str, ParagraphStyle]) -> Table:
    header = [
        Paragraph(_escape(labels[key]), styles["cell_head"])
        for key in ("exercise", "sets", "reps", "rir")
    ]
    data = [header]
    for exercise in day.exercises:
        name = exercise.name
        if exercise.notes:
            name = f"{name}<br/><font size=8 color='#5a5a5a'>{_escape(exercise.notes)}</font>"
        else:
            name = _escape(name)
        data.append(
            [
                Paragraph(name, styles["cell"]),
                Paragraph(str(exercise.sets), styles["cell"]),
                Paragraph(_escape(exercise.reps), styles["cell"]),
                Paragraph("-" if exercise.rir is None else str(exercise.rir), styles["cell"]),
            ]
        )

    table = Table(
        data, colWidths=[104 * mm, 18 * mm, 30 * mm, 18 * mm], hAlign="LEFT", repeatRows=1
    )
    table.setStyle(
        TableStyle(
            [
                ("VALIGN", (0, 0), (-1, -1), "TOP"),
                ("LINEBELOW", (0, 0), (-1, 0), 0.6, _INK),
                ("LINEBELOW", (0, 1), (-1, -2), 0.25, _RULE),
                ("BACKGROUND", (0, 0), (-1, 0), _DELOAD_BAND),
                ("TOPPADDING", (0, 0), (-1, -1), 3),
                ("BOTTOMPADDING", (0, 0), (-1, -1), 3),
                ("LEFTPADDING", (0, 0), (-1, -1), 4),
            ]
        )
    )
    return table


def _escape(text: str) -> str:
    """Paragraph text is parsed as mini-HTML, so the ampersands have to go."""
    return text.replace("&", "&amp;").replace("<", "&lt;").replace(">", "&gt;")
