# What I decided myself

Andrii asked for a written record of everything I changed on my own initiative,
so he can check it rather than take it on trust.

There are two kinds of thing here and they are kept apart on purpose.

**Part 1** is work that is already on `main`. It is not extra scope — it is
corrections and judgement calls made while delivering M4 to M8, the kind a
milestone cannot be finished without. Every one of these is in a milestone
commit and has been through CI.

**Part 2** is on the `extras` branch only. Nobody asked for any of it. `main`
does not contain it, and if none of it is wanted, deleting the branch loses
nothing.

Both versions can run at once — see *Running both* at the end.

---

## Part 1 — decisions made while delivering M4 to M8 (already on `main`)

### Bugs found and fixed

**A unique index that took booking slots off sale permanently.** *(M5)*
The index was keyed `(trainer_id, starts_at)`. A declined booking kept its row,
so the index kept refusing that hour — while the slot picker went on offering
it. A member could be shown a time that then refused them. I re-keyed it on a
nullable `held_slot_at` that goes NULL when a booking releases the slot; MySQL
allows any number of NULLs in a unique index, so both properties hold at once.
The subagent implemented what was specified, flagged the tension, and left the
call to me — which was correct.
*Proof:* a database-level test that a live hour is refused and a declined one
can be re-booked.

**A checkout session with no URL left a PENDING row nobody could clear.** *(M7)*
The `catch` block directly above it removes the row and explains why; this
branch flashed the same error and only logged. The member was left holding a
pending purchase with no route to cancel it. Both checkout controllers now
agree.
*Proof:* I restored the bug and watched the new assertion fail, then fixed it
again.

**A mail failure could silently swallow a payment confirmation.** *(M7)*
The webhook flushed the promotion, then sent mail. A throwing mailer handed
Stripe a 500; Stripe retried; the retry found a row that was no longer PENDING
and answered "Already handled" — so the confirmation was never sent at all.
Mail failures are now caught and logged. The payment is the fact worth keeping.

**Two translation placeholders rendering on live pages.** *(M4, M8)*
The cart printed `{0}empty|{1}1 item|]1,Inf[ %count% items` because a
pluralised message rendered with a bare `|trans` emits the raw string —
Symfony only selects a plural when `%count%` is in the parameters. Every
catalogue tile printed `from %amount% €29.90` because the translation carried a
placeholder the template never filled. Both were valid 200s; no test failed.
Both were caught by looking at a screenshot, which is why Part 2 adds a
mechanical check.

**An unlabelled form control.** *(M5)*
Lighthouse dropped the coach availability page to accessibility 91. Symfony's
`TimeType` stays a compound widget even with one dropdown, and its label points
at the wrapping div, leaving the `<select>` unlabelled. Replaced with a
`ChoiceType` and a transformer — back to 96, and PHPStan then caught that my
transformer's defensive type checks were dead code under its own generic, which
I deleted rather than suppressed.

**A header that broke for signed-in coaches.** *(M8)*
Signed out the nav carries seven items and fits; signed in as a coach it
carries twelve, and the wordmark collided with the first link. Widened the bar,
tightened the gap, moved the desktop breakpoint `md`→`lg`, and removed a
redundant "My plan" entry pointing at a page already reachable via Account.
The coach link was also using `coach.nav.label` — a key meant as the aria-label
for the coach's *sub*-navigation.

**A stale roadmap on the landing page.** *(M4)*
M3 was still marked `done => false` and the copy claimed the site was live
"through M2". Both had been wrong since before this run.

**I broke `bin/phpunit` myself, then fixed it.** *(M7)*
Adding a `<coverage>` block with `<report>` elements makes a coverage driver
*mandatory*, so the plain `bin/phpunit` the README documents started exiting
with "No tests executed" on any machine without xdebug. Reports moved to the
command line; CI asks for them explicitly.

**A test that only passed because a service was absent.** *(M6)*
Two tests asserted the plan-service-down path by relying on nothing listening
on port 8001 — true in CI, false on a machine running the full stack. They
passed on the server and failed locally, which is the wrong way round. They now
inject a client that refuses the connection, and the suite gives the same
answer either way. I ran it both ways to confirm.

### Design calls I made without being asked

- **Cart mutations got their own CSRF token id** (`cart`), separate from
  `checkout`. Adding to a basket is not handing money to Stripe.
- **An order confirmation email**, mirroring the membership one. M4 said
  "orders"; a receipt is part of that.
- **Two guard tests for the cross-runtime contract.** ADR 0001 accepts one real
  cost for splitting the generator out — a contract kept in step on both sides —
  and four enums carried docblocks asking the next person to remember. A
  docblock cannot fail a build, so `PythonContractTest` reads `schemas.py` and
  does. `TranslationParityTest` does the same for the three catalogues, where a
  missing key fails nothing at runtime and simply renders a raw dotted string to
  somebody browsing in Latvian.
- **Bumped the plan service from `0.1.0` to `1.0.0`.** It still advertised its
  stub version on `/health` while the engine reported `1.0.0`, and Symfony
  surfaces that as service status.
- **Three ADRs** recording decisions M2–M6 made but never wrote down.
- **Illustrations are drawn, not photographed, and there are no invented
  photographs of people.** Stock imagery would be someone else's copyright and
  would not survive renaming the gym. The trainer fixtures describe coaches who
  do not exist; generating portraits of them would be worse than having none.

---

## Part 2 — additions nobody asked for (`extras` branch only)

### 1. A guard against text that leaks its own machinery

`app/tests/Controller/RenderedTextSmokeTest.php` — **45 tests**

Two bugs reached rendered pages during this project and neither failed a test,
because both produced a valid 200 with wrong words in it. Both were found by a
human looking at a screenshot. That is not a repeatable way to catch a class of
bug.

This walks fourteen public pages in all three languages and fails on an
unsubstituted `%placeholder%`, a raw `]1,Inf[` pluralisation string, or a
dotted translation key that reached the page untranslated.

**Why it is worth the runtime:** it is the only check in the suite that asserts
on what a member *reads* rather than on status codes and markup.

**Verified, not assumed.** I reintroduced each original bug and confirmed the
guard fails:
- the `from %amount%` placeholder → 2 failures;
- the raw plural → 3 failures, one per locale.

The plural check needed a second test to earn its keep. My first version walked
`/cart` while it was empty, which never renders the count line — so it passed
with the bug reintroduced. A guard that misses the case it was written for is
worse than no guard, because it reads like cover. There is now a separate test
that puts two items in the cart first.

**Cost:** about a minute of suite time.

### 2. A skip link and a visible focus ring

`app/templates/base.html.twig`, `app/assets/styles/app.css`, plus an `a11y.*`
translation key in all three catalogues.

The header carries up to twelve items. Without a skip link, reaching the
content by keyboard costs a dozen tab presses on *every* navigation — which is
most of what using this site by keyboard would be. It is off-screen until
focused.

The focus ring is the other half. Browsers draw theirs in the system accent
colour, which on this dark palette is close to invisible. `:focus-visible`
keeps it off mouse clicks, so pointer users pay nothing.

**Visible difference:** press <kbd>Tab</kbd> on any page.

### 3. A print stylesheet for training plans

`app/assets/styles/app.css`

A programme is the one page on this site somebody genuinely wants on paper —
taped inside a gym bag, not read on a phone between sets. Printed from the
screen styles it comes out as dark grey ink on white with the navigation and
buttons in the way. This strips it to the table, forces black on white, and
stops a week splitting across a page break mid-day.

**Visible difference:** open a plan and press <kbd>Ctrl</kbd>+<kbd>P</kbd>.

### 4. `.dockerignore` files

`/.dockerignore`, `/ai-service/.dockerignore`

The build context for the PHP image is the repository root — **477 MB**, of
which `app/vendor` (138 MB, reinstalled inside the build), `app/var` (199 MB of
cache, logs and a 107 MB Tailwind binary) and `ai-service/.venv` (130 MB of
Windows-built packages a Linux image cannot use) are all shipped to the daemon
before the first instruction runs. There were no `.dockerignore` files anywhere.

These also keep `app/.env.local` out of any image — that is where real Stripe
keys live.

**Not yet verified by running Docker**, because Docker is not installed on this
machine (see below). CI builds both images on every push, so the next push
proves the builds still work; the size saving is arithmetic from the directory
sizes above.

---

## Still blocked: Docker

Docker cannot be installed from this session:

- the session is **not running as administrator**, and Docker Desktop's
  installer needs UAC;
- **WSL is not installed** — only the `wsl.exe` stub exists;
- enabling WSL2 requires a **reboot**.

In an **administrator** PowerShell:

```
wsl --install
```

then reboot, then:

```
winget install --id Docker.DockerDesktop -e
```

Once it runs I can bring the stack up and fix what only shows up when it
actually runs. **One problem is already visible without Docker:** the `php`
service in `infra/compose.yaml` mounts `../app:/var/www/app`, which shadows the
`vendor/` the Dockerfile installs. It works here because `vendor/` exists
locally, but on a fresh clone the container would come up with no dependencies.
I have left it alone rather than guess at a fix I cannot test.

---

## Running both

`main` is the agreed scope. `extras` is this branch. They share the database —
nothing in Part 2 touches the schema — and run from separate working copies:

| Version | Branch | Working copy | URL |
|---|---|---|---|
| 1 — M7 + M8 as agreed | `main` | `C:\dev\gym-proj` | http://127.0.0.1:8000 |
| 2 — with the additions above | `extras` | `C:\dev\gym-proj-extras` | http://127.0.0.1:8010 |

`extras` was branched from `1420ea9`, the commit that closed M7, so
`git diff main..extras` shows exactly Part 2 and nothing else.
