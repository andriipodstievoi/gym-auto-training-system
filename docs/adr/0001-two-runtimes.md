# ADR 0001 — Split the training-plan generator into a Python service

**Status:** accepted · 2026-08-28

## Context

The site's flagship feature turns a questionnaire into a periodised training
programme. That work is numeric and rule-heavy: choosing a split from weekly
frequency, scaling set volume by training age, picking a progression model,
filtering an exercise library by available equipment and injury history, then
laying the result out across a mesocycle with a deload.

The rest of the site — memberships, shop, bookings, accounts, admin — is
ordinary transactional web work that Symfony handles well.

## Decision

Keep the two concerns in separate runtimes inside one repository.

- `app/` (Symfony) owns the questionnaire UI, persistence, payments, and
  everything a member sees. It calls the generator over HTTP.
- `ai-service/` (FastAPI) owns programme generation. Its input and output are
  Pydantic models that form an explicit contract.

The rule engine produces every number. An optional LLM layer adds prose only —
coaching cues, warm-ups, weekly focus — and can never alter sets, reps or loads.

## Consequences

**Good.** The generator is unit-testable without booting a web framework or a
database. Programme logic can be iterated on without touching the shop. The LLM
becomes an enhancement rather than a dependency, so the site works with no API
key. It also demonstrates PHP and Python honestly rather than decoratively.

**Costs.** One more service to run, deploy and monitor; a network hop on the
plan request; and a contract that must be kept in sync on both sides. Compose
and CI cover the operational half; the Pydantic schemas are the single source of
truth for the contract.

**Rejected alternative.** Implementing the engine in PHP inside Symfony would
have removed the hop and the extra deployment, but buries the most interesting
logic in controller-adjacent services and makes it far harder to test in isolation.
