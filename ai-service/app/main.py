"""HTTP surface of the training-plan service."""

from __future__ import annotations

from fastapi import FastAPI, Response
from fastapi.responses import JSONResponse

from app import __version__
from app.config import settings
from app.engine import ENGINE_VERSION, generate_plan
from app.llm import add_prose
from app.pdf import pdf_filename, render_plan_pdf
from app.schemas import Assessment, Health, MedicalReferral, TrainingPlan

app = FastAPI(
    title="SPĒKS training-plan service",
    version=__version__,
    summary="Turns a completed assessment into a periodised training programme.",
)


@app.get("/health", response_model=Health, tags=["ops"])
def health() -> Health:
    """Liveness probe, also used by the Symfony app to show service status."""
    return Health(version=__version__, llm_enabled=settings.llm_enabled)


@app.post("/v1/plan", response_model=MedicalReferral | TrainingPlan, tags=["plans"])
def create_plan(assessment: Assessment) -> MedicalReferral | TrainingPlan:
    """Generate a training plan from a completed assessment.

    The PAR-Q+ safety gate runs first: a flagged assessment never reaches the
    generator, regardless of what the generator later does. Everything past
    the gate is deterministic; the prose layer runs afterwards and can only
    add text, or fail quietly and add none.
    """
    red_flags = assessment.par_q.red_flags()
    if red_flags:
        return MedicalReferral(red_flags=red_flags)

    return add_prose(generate_plan(assessment), assessment)


@app.post(
    "/v1/plan.pdf",
    tags=["plans"],
    response_class=Response,
    responses={
        200: {
            "content": {"application/pdf": {}, "application/json": {}},
            "description": "The plan as a PDF, or a medical referral as JSON.",
        }
    },
)
def create_plan_pdf(assessment: Assessment) -> Response:
    """The same plan as ``/v1/plan``, laid out for printing.

    A flagged assessment returns the referral as JSON, with the same status
    code and body as ``/v1/plan``, rather than a PDF. Rendering the referral
    as a document would hand the member something shaped like a programme,
    and the gate should not look like the thing it is refusing to produce.
    Callers branch on the response ``Content-Type``, or on ``status`` in the
    body, exactly as they already do for the JSON endpoint.
    """
    red_flags = assessment.par_q.red_flags()
    if red_flags:
        referral = MedicalReferral(red_flags=red_flags)
        return JSONResponse(content=referral.model_dump(mode="json"))

    plan = add_prose(generate_plan(assessment), assessment)
    return Response(
        content=render_plan_pdf(plan, assessment),
        media_type="application/pdf",
        headers={
            "Content-Disposition": f'attachment; filename="{pdf_filename(plan)}"',
            "X-Engine-Version": ENGINE_VERSION,
        },
    )
