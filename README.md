# SPĒKS — Gym & Automatic Training System

[![CI](https://github.com/andriipodstievoi/gym-auto-training-system/actions/workflows/ci.yml/badge.svg)](https://github.com/andriipodstievoi/gym-auto-training-system/actions/workflows/ci.yml)

A full-stack website for a strength gym in **Rīga, Latvia**: find a branch, buy a
membership, book a trainer, order kit and supplements — and complete an assessment
that generates a **personal, periodised training programme**.

Served in **English, Latvian and Russian**, priced in EUR.

> ⚠️ Training plans produced here are general fitness guidance, not medical advice.
> A PAR-Q+ style screening gate routes flagged members to a physician instead of a programme.

---

## Architecture

Two runtimes, one repository:

```
app/          Symfony 7.4 LTS  — site, shop, accounts, orders, admin
ai-service/   Python FastAPI   — programme rule engine + LLM prose layer
infra/        Docker Compose, nginx, PHP-FPM image
docs/         architecture decision records
```

**Why two runtimes?** Programme design is numeric work — split selection, weekly
set volume, intensity targets, progression models. That belongs in Python, tested
in isolation and independently deployable. The Symfony app owns the questionnaire,
persistence, payments and everything a member sees. See
[ADR 0001](docs/adr/0001-two-runtimes.md).

**Why the rule engine comes first.** The generator computes every number
deterministically. The LLM layer only writes prose on top — coaching cues,
warm-ups, weekly focus, in the member's language. It never changes sets, reps or
loads. The site produces valid programmes with no API key configured.

### Stack

| Layer | Technology |
|---|---|
| Backend | PHP 8.3, Symfony 7.4 LTS, Doctrine ORM, Twig |
| Frontend | Tailwind CSS 4, Alpine.js 3, Symfony AssetMapper (no bundler) |
| Data | MySQL 8.4, Redis 7 (cache + sessions) |
| Plans | Python 3.14, FastAPI, Pydantic v2 |
| Gen AI | Anthropic Claude — optional coaching-narrative layer |
| Payments | Stripe (test mode) |
| Maps | Leaflet + OpenStreetMap |
| Mail | Symfony Mailer, Mailpit locally |
| Infra | Docker Compose, nginx, PHP-FPM |
| Quality | PHPUnit, PHPStan L8, PHP-CS-Fixer, pytest, Ruff, GitHub Actions |

---

## Roadmap

| | Milestone | Status |
|---|---|---|
| **M0** | Foundation — framework, assets, i18n, Docker, CI | ✅ done |
| M1 | Domain model, migrations, fixtures, back office | planned |
| M2 | Public site, Leaflet branch map, SVG floor plan | planned |
| M3 | Accounts, memberships, Stripe test checkout | planned |
| M4 | Shop — catalogue, cart, orders | planned |
| M5 | Trainers, availability, booking, messaging | planned |
| M6 | Assessment, rule engine, LLM layer, PDF export | planned |
| M7 | Coverage, docs, screenshots | planned |

---

## Running it locally

### With Docker (any machine)

```bash
docker compose -f infra/compose.yaml up --build
```

| Service | URL |
|---|---|
| Website | http://localhost:8080 |
| Plan service (OpenAPI) | http://localhost:8002/docs |
| Mailpit | http://localhost:8026 |

Host ports are offset so the stack coexists with a local Laragon install.

### Natively (Windows + Laragon)

PHP, MySQL and Redis come from Laragon and are **not on `PATH`** — call them by
full path, or add `C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64` and
`C:\laragon\bin\symfony` to `PATH`.

Start MySQL, Redis and Mailpit (Laragon → *Start All*), then build the CSS:

```bash
cd app && php bin/console tailwind:build --watch
```

Serve the site:

```bash
cd app && php -S 127.0.0.1:8000 -t public public/index.php
```

Serve the plan service:

```bash
cd ai-service && .venv/Scripts/uvicorn app.main:app --reload --port 8001
```

The site is then on http://127.0.0.1:8000 and redirects `/` to `/en`.

### Required PHP extensions

`intl`, `zip`, `pdo_mysql`, `redis`, `apcu`, `opcache` — plus `xdebug` for coverage.

---

## Configuration

`app/.env` holds development defaults and is committed. Real secrets belong in
`app/.env.local`, which is git-ignored:

```dotenv
STRIPE_SECRET_KEY=sk_test_...
STRIPE_WEBHOOK_SECRET=whsec_...
```

The plan service reads `AI_ANTHROPIC_API_KEY` from its own environment. Leave it
unset and plans still generate — only the coaching narrative is skipped.

---

## Quality gates

Everything below runs in CI on every push and pull request.

```bash
cd app && vendor/bin/php-cs-fixer fix --dry-run --diff
```

```bash
cd app && vendor/bin/phpstan analyse
```

```bash
cd app && bin/phpunit
```

```bash
cd ai-service && .venv/Scripts/python -m ruff check .
```

```bash
cd ai-service && .venv/Scripts/python -m pytest -q
```

---

## Licence

MIT — see [LICENSE](LICENSE).
