# ADR 0002 — Only a signed webhook may confirm a payment

**Status:** accepted · 2026-09-01

## Context

Two things on this site are paid for: a membership (M3) and a shop order (M4).
Both use Stripe Checkout, which means the member leaves the site, pays on
Stripe's page, and is redirected back to a success URL we chose.

The tempting implementation is to treat that redirect as proof of payment: the
member is standing on `/checkout/success`, so mark the thing paid. It is one
line, it needs no extra endpoint, and it appears to work every time you test it.

It is also wrong. The success URL is a `GET` a member can type, bookmark or
share. Nothing about arriving there proves money moved.

## Decision

Checkout writes a **PENDING** row and hands off. Only `/webhook/stripe`, which
verifies Stripe's signature against `STRIPE_WEBHOOK_SECRET`, may promote it.

- No controller reachable by a browser ever sets `PAID` or `ACTIVE`.
- The webhook refuses everything when no signing secret is configured, rather
  than trusting an unauthenticated POST that says money arrived.
- The success page is allowed to show PENDING, and says so honestly. The webhook
  is a separate connection and can land after the redirect.
- One endpoint serves both kinds of purchase. Which one a session belongs to is
  decided by its `order_id` metadata, never inferred from the amount or the line
  items.

## Consequences

**Good.** The security property is structural rather than procedural: there is
no code path from a browser request to a paid state, so it cannot be
reintroduced by somebody adding a convenience. Stripe retries until it gets a
2xx, so a confirmation survives our downtime. Because confirmation is
idempotent — a row already promoted returns early — a retry cannot extend a
membership twice or send two receipts.

**Costs.** Local development needs `stripe listen --forward-to` to see anything
get confirmed at all, which surprises people. The success page has to be written
for a state that is not yet final, which is more copy than "thank you". And the
two payment kinds now share an endpoint whose branching must be kept honest.

**Consequence worth stating plainly.** Stock is drawn down in the webhook, not
at checkout, for the same reason: an abandoned checkout must not hold stock
hostage. It is floored at zero, because two members can pay for the last item
within the same second and refusing money Stripe has already taken would be
worse than overselling one unit and sorting it out at the counter.

**Rejected alternative.** Confirming on the success redirect and "verifying
later" with a scheduled reconciliation job. That is the same trust problem with
a delay attached, and it makes the window between fake-paid and detected-unpaid
a business decision rather than an impossibility.
