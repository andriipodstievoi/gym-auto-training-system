"""The prose the engine writes for itself.

Every string here is deterministic and factual: it restates decisions the
engine has already made, in the member's language, so a plan generated with
no API key still reads as a plan rather than a table of numbers. When the LLM
layer is available it replaces ``coaching_notes`` wholesale - which is exactly
why this text has to stand on its own first.
"""

from __future__ import annotations

from app.schemas import Locale

_COACHING_NOTES: dict[Locale, str] = {
    Locale.EN: (
        "Split: {split}. {days} sessions a week of about {minutes} minutes, "
        "run as a {weeks}-week block. Week {deload_week} is a deload: sets are "
        "cut back and every set stops further from failure, so accumulated "
        "fatigue clears before the next block. "
        "Reps in reserve (RIR) is how many repetitions you should still have "
        "left when you rack the weight. Add a little load or one repetition "
        "once you reach the top of a rep range at the prescribed RIR. "
        "Warm up for {warmup} minutes and ramp up to your first working set. "
        "Rest about {rest} between working sets."
    ),
    Locale.LV: (
        "Sadalījums: {split}. {days} treniņi nedēļā, katrs apmēram {minutes} "
        "minūtes, {weeks} nedēļu ciklā. {deload_week}. nedēļa ir atslodze: "
        "sēriju skaits ir samazināts un katra sērija beidzas tālāk no atteices, "
        "lai uzkrātais nogurums pazustu pirms nākamā cikla. "
        "Atkārtojumu rezerve (RIR) ir tas, cik atkārtojumu tev vēl jāspēj "
        "izdarīt, kad noliec svaru. Pievieno nedaudz svara vai vienu "
        "atkārtojumu, kad sasniedz diapazona augšējo robežu ar noteikto rezervi. "
        "Iesildies {warmup} minūtes un pakāpeniski sasniedz pirmo darba sēriju. "
        "Starp darba sērijām atpūties apmēram {rest}."
    ),
    Locale.RU: (
        "Сплит: {split}. {days} тренировки в неделю примерно по {minutes} минут, "
        "цикл из {weeks} недель. Неделя {deload_week} — разгрузочная: количество "
        "подходов снижено, и каждый подход заканчивается дальше от отказа, чтобы "
        "накопленная усталость ушла до начала следующего цикла. "
        "Запас повторений (RIR) — это сколько повторений у тебя должно остаться, "
        "когда ты ставишь вес. Добавляй немного веса или одно повторение, когда "
        "доходишь до верхней границы диапазона с указанным запасом. "
        "Разминайся {warmup} минут и постепенно выходи на первый рабочий подход. "
        "Между рабочими подходами отдыхай около {rest}."
    ),
}

_WARMUP_NOTES: dict[Locale, str] = {
    Locale.EN: "Easy cardio, joint circles, then ramp-up sets of the first lift.",
    Locale.LV: (
        "Viegls kardio, locītavu vingrinājumi, tad iesildošās sērijas pirmajam vingrinājumam."
    ),
    Locale.RU: "Лёгкое кардио, суставная разминка, затем разминочные подходы первого упражнения.",
}

_CONDITIONING_NOTES: dict[Locale, str] = {
    Locale.EN: "Steady effort you could hold a short conversation through.",
    Locale.LV: "Vienmērīgs temps, kurā vēl vari īsi sarunāties.",
    Locale.RU: "Ровный темп, при котором ещё можно коротко разговаривать.",
}

_REST_LABEL: dict[Locale, dict[str, str]] = {
    Locale.EN: {"short": "60-90 seconds", "long": "2-3 minutes"},
    Locale.LV: {"short": "60-90 sekundes", "long": "2-3 minūtes"},
    Locale.RU: {"short": "60-90 секунд", "long": "2-3 минуты"},
}


def coaching_notes(
    *,
    locale: Locale,
    split: str,
    days: int,
    minutes: int,
    weeks: int,
    deload_week: int,
    warmup_minutes: int,
    long_rests: bool,
) -> str:
    """Restate the plan's own decisions as a short briefing."""
    rest = _REST_LABEL[locale]["long" if long_rests else "short"]
    return _COACHING_NOTES[locale].format(
        split=split,
        days=days,
        minutes=minutes,
        weeks=weeks,
        deload_week=deload_week,
        warmup=warmup_minutes,
        rest=rest,
    )


def warmup_note(locale: Locale) -> str:
    return _WARMUP_NOTES[locale]


def conditioning_note(locale: Locale) -> str:
    return _CONDITIONING_NOTES[locale]
