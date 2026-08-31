# M4 → M7: shop, booking, the training-plan generator, hardening

## The goal

Ship M4, M5, M6 and M7. **Stop only when every one of these is true:**

1. Each of M4, M5, M6 and M7 is committed as `Mx: subject` and pushed to `main`.
2. `gh run watch` reports CI green on the most recent push.
3. All four quality gates pass locally (see *Definition of done* below).
4. The status tables in `CLAUDE.md` and `README.md` show M4–M7 as done.

Anything less is not done. Finishing M4 is not finishing — continue straight
into M5, then M6, then M7. If one milestone turns out to be blocked, complete
every other one in full and say plainly which part you left and why.

Read `CLAUDE.md` first. It is the source of truth for the toolchain, the
conventions and the gotchas already paid for.

You are the **lead** on this run, not the only worker. Delegate implementation
to subagents and keep the serialising work for yourself.

**Resuming:** this may re-enter mid-run. Before starting anything, check
`git log --oneline -8` and the status tables in `CLAUDE.md` to see which
milestones already landed, and pick up from the first one that has not. Never
redo a milestone that is already committed and green.

## Before you write any code

**Load the tools first.** Fetch the chrome-devtools MCP in one call —
`ToolSearch(query: "chrome-devtools", max_results: 30)` — and use it for every
browser check, never the built-in Browser pane. `evaluate_script` to assert real
DOM and Alpine state, `list_console_messages` to catch silent JS errors,
`lighthouse_audit` on every page with custom interaction. That audit earns its
keep: in M2 it caught a WCAG 2.5.3 failure no screenshot would have shown, and
in M3 a password form missing its username field. If a server shows as deferred
or still connecting, load it before concluding anything is unavailable.
`plugin:figma:figma` needs OAuth and is unusable in a non-interactive session —
say so rather than working around it.

Also available and worth using: `superpowers:brainstorming` before designing M6,
`superpowers:systematic-debugging` on any bug, `superpowers:test-driven-development`
for the rule engine, `superpowers:verification-before-completion` before claiming
anything passes, and `claude-mem:mem-search` to recall earlier sessions.

**Start the services.** MySQL, Redis and Mailpit are stopped and do not survive a
session ending; neither does the web server. Start each as a background task (not
`Start-Process`) using the exact commands in CLAUDE.md, then serve with
`symfony server:start --no-tls --port=8000`. Never `php -S` — it serves
`public/bundles/` as `text/html` and the back office renders unstyled. Re-run
`bin/console tailwind:build` after adding CSS classes.

## How to delegate

**You own** — because parallel edits corrupt them: migrations,
`config/packages/*`, `translations/*.yaml`, `importmap.php`, the four quality
gates, browser verification, commits and pushes.

**Delegate** self-contained feature work. Every subagent starts cold: give it the
file paths, the conventions it must follow, and what "done" means.

- **Safe in parallel:** `ai-service/` Python work and `app/` Symfony work. They
  share no files — that is exactly what ADR 0001 bought.
- **Never in parallel:** anything touching a migration, a translation file,
  `security.yaml` or `importmap.php`. Serialise those through yourself.

Run all four gates and commit after **each** milestone, not at the end. Keep
`main` green so an interrupted run leaves nothing broken.

## Scope, in order

**M4 — Shop.** `Product` and `ProductCategory` already exist with prices in
integer cents, stock, and a seeded 4-category / 8-product catalogue plus admin
CRUD. Nothing public exists: no shop controller, route, template or cart. Build
the catalogue pages, a cart, and orders. `Product` has no variants yet — its
docblock says variants arrive with the cart, so add them here. Imitate
`CheckoutController`: write a PENDING row, hand off to Stripe, and let only the
signed webhook confirm it. The header nav renders `shop` as a disabled
"coming soon" span — wire it up.

**M5 — Trainers and booking.** `Trainer` is read-only on the public site and
already has its nullable `user` relation. Add availability, session booking and
member↔coach messaging, with email through Mailer. Nothing booking-related
exists yet — no entity, enum or table.

**M6 — The flagship, and the reason the repo has two runtimes.** `ai-service` is
currently a **stub**: `POST /v1/plan` runs the PAR-Q+ red-flag gate (which is
real and works) and then returns **501** for every clean assessment.
`engine/__init__.py` is one line, `ENGINE_VERSION = "0.1.0-stub"`. The full
Pydantic contract in `schemas.py` already defines `Assessment`, `TrainingPlan`,
`PlanWeek`, `PlanDay`, `PlanExercise` and `MedicalReferral` — build to it rather
than reshaping it. Implement the deterministic rule engine (split selection,
weekly set volume, intensity, progression, exercise filtering by equipment and
injury, mesocycle layout with a deload), then the optional LLM prose layer, then
PDF export. The rule engine computes **every** number; the LLM only writes prose
and may never change sets, reps or loads. Plans must generate with no API key.

On the Symfony side, build the questionnaire UI and persistence. Note:
`ai_service.client` is configured in `framework.yaml` but **no PHP code calls it
yet** — you are writing that first call. `App\Domain\Enum\Limitation` mirrors the
Python enum and the two must stay in step; `Goal`, `Experience` and `Equipment`
have no PHP counterpart yet.

**M7 — Hardening.** Coverage is switched off: `phpunit.dist.xml` has no
`<coverage>` block and CI sets `coverage: none`. Turn it on, raise coverage,
write the docs, take the screenshots. `docs/` contains only ADR 0001.

## Hard constraints

- **Never handle Stripe keys.** Andrii creates the account and puts the keys in
  `app/.env.local` himself. Do not ask for them, paste them, read them back, or
  put them in `.env`, code, docs or a commit. `STRIPE_PUBLIC_KEY`,
  `STRIPE_SECRET_KEY` and `STRIPE_WEBHOOK_SECRET` exist in `app/.env` as empty
  placeholders — leave them empty. The site must boot and every page must render
  with no keys configured. Build and test it that way. Same rule for
  `AI_ANTHROPIC_API_KEY`: plans must generate without it.
- Symfony 7.4 LTS, PHP 8.3. Symfony 8 needs PHP 8.4, so the project stays on 7.4.
- Tailwind 4 + Alpine via AssetMapper. No bundler. New JS goes in `importmap.php`.
- **PHPStan stays at level 8.** No new ignores, no baseline, no `assert()`, no
  inline `@var` to get past it. When it complains it is usually right — it has
  caught dead code and a real runtime bug across M2 and M3.
- Locale-prefixed routes: `/{_locale}` with requirement `en|lv|ru`.
- Translated content uses `App\Domain\TranslatedString`. Never `nameEn`/`nameLv`/
  `nameRu` triplets. Constraint messages go in the **`validators`** domain.
- Money is integer cents; currency is EUR everywhere.
- Nothing is on `PATH` — use the full binary paths from CLAUDE.md.
- The working copy is `C:\dev\gym-proj`, deliberately outside OneDrive.

## Definition of done, per milestone

Run all four gates:

```
cd app && vendor/bin/php-cs-fixer fix --dry-run --diff
cd app && bin/console cache:warmup --env=dev && vendor/bin/phpstan analyse
cd app && bin/phpunit
cd ai-service && .venv/Scripts/python.exe -m ruff check . && .venv/Scripts/python.exe -m ruff format --check . && .venv/Scripts/python.exe -m pytest -q
```

The repository tests need a migrated and seeded test database — see CLAUDE.md.
Tests must not depend on a live SMTP server; the test env uses the null mail
transport.

Then verify in a real browser via chrome-devtools that the new flows work in all
three locales, that the console is clean, and that Lighthouse shows no
application-level failures. Commit as `Mx: subject`, push, and confirm CI is
green with `gh run watch`. Update the status tables in `CLAUDE.md` and
`README.md` as each milestone lands.

## State you are inheriting

- M0–M3 done and pushed. `main` is clean at `fd67aad`, CI green.
- 138 PHPUnit tests passing. PHPStan level 8, clean, no ignores beyond the two
  documented association-type ones.
- Entities: `Branch`, `FloorZone`, `Equipment`, `Exercise`, `MembershipPlan`,
  `Product`, `ProductCategory`, `Trainer`, `User`, `UserMembership`.
- Real accounts: registration, form login, logout, roles. `/admin` is gated by
  `ROLE_ADMIN` through the same form login; the M1 in-memory admin is gone.
- Stripe test-mode membership checkout plus the signed webhook that confirms it.
- Fixtures seed `admin@speks.lv`, `member@speks.lv`, `prospect@speks.lv`, all
  with password `speks-dev`.
