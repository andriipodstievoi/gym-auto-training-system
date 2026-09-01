"""API-level tests for the training-plan service."""

from __future__ import annotations

from typing import Any

import pytest
from fastapi.testclient import TestClient

from app.engine import ENGINE_VERSION
from app.main import app
from app.schemas import ParQ
from tests.conftest import assessment_payload

client = TestClient(app)

RED_FLAGS = list(ParQ.model_fields)


#: The assessment the Symfony side sends most often: a returning member with
#: one joint to work around.
_DEFAULTS: dict[str, Any] = {
    "goal": "muscle_gain",
    "experience": "intermediate",
    "days_per_week": 4,
    "minutes_per_session": 75,
    "equipment": "full_gym",
    "limitations": ["shoulder"],
    "locale": "lv",
}


def _assessment(**overrides: Any) -> dict[str, Any]:
    return assessment_payload(**{**_DEFAULTS, **overrides})


def test_health_reports_ok():
    response = client.get("/health")

    assert response.status_code == 200
    body = response.json()
    assert body["status"] == "ok"
    assert body["service"] == "ai-service"


def test_health_reports_the_llm_as_disabled_without_a_key():
    body = client.get("/health").json()

    assert body["llm_enabled"] is False


def test_par_q_red_flag_returns_medical_referral_instead_of_a_plan():
    payload = _assessment(par_q={"chest_pain": True, "recent_surgery": True})

    response = client.post("/v1/plan", json=payload)

    assert response.status_code == 200
    body = response.json()
    assert body["status"] == "medical_referral"
    assert sorted(body["red_flags"]) == ["chest_pain", "recent_surgery"]


@pytest.mark.parametrize("flag", RED_FLAGS)
def test_every_single_red_flag_stops_plan_generation(flag: str):
    response = client.post("/v1/plan", json=_assessment(par_q={flag: True}))

    assert response.status_code == 200
    body = response.json()
    assert body["status"] == "medical_referral"
    assert body["red_flags"] == [flag]
    assert "weeks" not in body


@pytest.mark.parametrize("flag", RED_FLAGS)
def test_every_single_red_flag_also_stops_the_pdf(flag: str):
    response = client.post("/v1/plan.pdf", json=_assessment(par_q={flag: True}))

    assert response.status_code == 200
    assert response.headers["content-type"] == "application/json"
    assert not response.content.startswith(b"%PDF-")
    assert response.json()["status"] == "medical_referral"
    assert response.json()["red_flags"] == [flag]


def test_clean_assessment_returns_a_full_plan():
    response = client.post("/v1/plan", json=_assessment())

    assert response.status_code == 200
    body = response.json()
    assert body["status"] == "ok"
    assert body["engine_version"] == ENGINE_VERSION
    assert body["llm_used"] is False
    assert body["split"]
    assert body["coaching_notes"]
    assert body["disclaimer"]
    assert len(body["weeks"]) >= 4
    assert [week["deload"] for week in body["weeks"]].count(True) == 1
    for week in body["weeks"]:
        assert len(week["days"]) == 4
        for day in week["days"]:
            assert day["exercises"]


def test_the_plan_endpoint_is_deterministic_over_http():
    first = client.post("/v1/plan", json=_assessment()).json()
    second = client.post("/v1/plan", json=_assessment()).json()

    del first["generated_at"], second["generated_at"]
    assert first == second


def test_assessment_validation_rejects_impossible_input():
    response = client.post("/v1/plan", json=_assessment(days_per_week=9))

    assert response.status_code == 422


@pytest.mark.parametrize(
    "overrides",
    [
        {"minutes_per_session": 20},
        {"experience": "olympian"},
        {"equipment": "spaceship"},
        {"profile": {"age": 9, "height_cm": 182, "weight_kg": 84.0}},
    ],
    ids=["too-short", "bad-experience", "bad-equipment", "too-young"],
)
def test_assessment_validation_rejects_out_of_contract_values(overrides: dict[str, Any]):
    assert client.post("/v1/plan", json=_assessment(**overrides)).status_code == 422


def test_the_pdf_endpoint_returns_a_pdf():
    response = client.post("/v1/plan.pdf", json=_assessment())

    assert response.status_code == 200
    assert response.headers["content-type"] == "application/pdf"
    assert response.headers["x-engine-version"] == ENGINE_VERSION
    assert "attachment" in response.headers["content-disposition"]
    assert "speks-training-plan-" in response.headers["content-disposition"]
    assert response.content.startswith(b"%PDF-")
    assert len(response.content) > 20_000


@pytest.mark.parametrize("locale", ["en", "lv", "ru"])
def test_the_pdf_endpoint_serves_every_locale(locale: str):
    response = client.post("/v1/plan.pdf", json=_assessment(locale=locale))

    assert response.status_code == 200
    assert response.content.startswith(b"%PDF-")


def test_the_openapi_document_describes_both_plan_shapes():
    schema = client.get("/openapi.json").json()

    assert "/v1/plan" in schema["paths"]
    assert "/v1/plan.pdf" in schema["paths"]
    assert "501" not in schema["paths"]["/v1/plan"]["post"]["responses"]
    pdf_responses = schema["paths"]["/v1/plan.pdf"]["post"]["responses"]["200"]
    assert "application/pdf" in pdf_responses["content"]
