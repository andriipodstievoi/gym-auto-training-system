"""HTTP surface of the training-plan service."""

from __future__ import annotations

from fastapi import FastAPI, HTTPException, status

from app import __version__
from app.config import settings
from app.engine import ENGINE_VERSION
from app.schemas import Assessment, Health, MedicalReferral

app = FastAPI(
    title="SPĒKS training-plan service",
    version=__version__,
    summary="Turns a completed assessment into a periodised training programme.",
)


@app.get("/health", response_model=Health, tags=["ops"])
def health() -> Health:
    """Liveness probe, also used by the Symfony app to show service status."""
    return Health(version=__version__, llm_enabled=settings.llm_enabled)


@app.post(
    "/v1/plan",
    response_model=MedicalReferral,
    tags=["plans"],
    responses={501: {"description": "Plan generation lands in milestone M6."}},
)
def create_plan(assessment: Assessment) -> MedicalReferral:
    """Generate a training plan from a completed assessment.

    The PAR-Q+ safety gate is live already: a flagged assessment never reaches
    the generator, regardless of what the generator later does.
    """
    red_flags = assessment.par_q.red_flags()
    if red_flags:
        return MedicalReferral(red_flags=red_flags)

    raise HTTPException(
        status_code=status.HTTP_501_NOT_IMPLEMENTED,
        detail=(
            f"Rule engine {ENGINE_VERSION} is a stub; programme generation "
            "is scheduled for milestone M6."
        ),
    )
