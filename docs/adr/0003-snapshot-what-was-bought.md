# ADR 0003 — A purchase snapshots what it was, not what it points at

**Status:** accepted · 2026-09-01

## Context

Four things on this site record something a member committed to at a moment in
time: a `UserMembership`, an `OrderItem`, a `Booking`, and a `TrainingPlan`.

Each of them has an obvious relational shape. An order line points at a product,
so read the price through the product. A booking points at a trainer, so read
the rate through the trainer. It normalises cleanly and it is what an ORM nudges
you towards.

It is also a bug that only appears months later, in a support conversation.
Reprice a protein tub and every past invoice silently rewrites itself. Raise a
coach's hourly rate and every session anybody ever booked becomes retroactively
more expensive. Nobody sees it happen; the data simply stops matching what
people were told.

## Decision

Copy the facts in at the moment of purchase, and keep the relation only for
reporting.

- `UserMembership` copies `pricePaidCents` from the plan.
- `OrderItem` copies the name, SKU and unit price, and its `product_id` and
  `variant_id` are nullable with `ON DELETE SET NULL`.
- `Booking` copies the coach's hourly rate, pro-rated for the session length.
- `TrainingPlan` stores the whole document the engine returned, with the
  `engine_version` that produced it.

Deleting a product must not delete history. Editing one must not rewrite it.

## Consequences

**Good.** History is true. A receipt still renders when the product is gone, an
old plan still renders when the engine has moved on, and a member's invoice says
what they were actually charged. The nullable FK makes the intent explicit:
this reference is a convenience for reporting, not the source of truth.

**Costs.** The same string is stored in two places, which offends the instinct
that told us to normalise. Renaming a product does not retitle old orders — that
is the feature, but it reads as a bug to anyone who has not read this file.
Reports that group by product have to tolerate a null.

**Where the line is.** `TrainingPlan.payload` takes this furthest: it stores the
whole engine document rather than shredding it into tables. That is deliberate.
The engine owns that shape and versions it; normalising it here would buy a
schema migration every time the engine learned a field, and would make the
Symfony side the authority on a structure it does not decide. `engineVersion`
is what makes an old payload readable rather than mysterious.

**Rejected alternative.** Immutable price rows — never edit a product, insert a
new version and point new orders at it. Correct, and standard in commerce
systems that need it. Rejected here because it puts versioning into every read
path in the shop and the admin, for a gym that reprices a handful of items a
year. The snapshot buys the same guarantee at a fraction of the cost.
