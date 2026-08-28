"""API-level tests for the training-plan service."""

from fastapi.testclient import TestClient

from app.main import app

client = TestClient(app)


def _assessment(**overrides):
    payload = {
        "profile": {"age": 28, "height_cm": 182, "weight_kg": 84.0},
        "goal": "muscle_gain",
        "experience": "intermediate",
        "days_per_week": 4,
        "minutes_per_session": 75,
        "equipment": "full_gym",
        "limitations": ["shoulder"],
        "locale": "lv",
    }
    payload.update(overrides)
    return payload


def test_health_reports_ok():
    response = client.get("/health")

    assert response.status_code == 200
    body = response.json()
    assert body["status"] == "ok"
    assert body["service"] == "ai-service"


def test_par_q_red_flag_returns_medical_referral_instead_of_a_plan():
    payload = _assessment(par_q={"chest_pain": True, "recent_surgery": True})

    response = client.post("/v1/plan", json=payload)

    assert response.status_code == 200
    body = response.json()
    assert body["status"] == "medical_referral"
    assert sorted(body["red_flags"]) == ["chest_pain", "recent_surgery"]


def test_clean_assessment_reaches_the_generator():
    response = client.post("/v1/plan", json=_assessment())

    # M6 implements the engine; until then the gate passes and the stub answers.
    assert response.status_code == 501


def test_assessment_validation_rejects_impossible_input():
    response = client.post("/v1/plan", json=_assessment(days_per_week=9))

    assert response.status_code == 422
