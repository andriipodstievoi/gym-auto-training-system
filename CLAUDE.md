# CLAUDE.md

Context for Claude Code working in this repository. Read this before touching anything.

## What this is

**SPĒKS** — a gym website for a strength gym in **Rīga, Latvia**, with an automatic
training-plan generator. It is also a **portfolio piece**: a deliberate secondary goal is
to use as many technologies from Andrii's CV as possible, honestly rather than
decoratively. CV list: HTML, CSS, PHP, JavaScript, Symfony, Alpine.js, Tailwind,
databases, LLM + Gen AI, Linux/Docker, Git + GitHub, Python.

Repository: https://github.com/andriipodstievoi/gym-auto-training-system

The gym name `SPĒKS` (Latvian for "strength") is a placeholder Andrii can rename.

## Layout

```
app/          Symfony 7.4 LTS — site, shop, accounts, admin
ai-service/   Python 3.14 + FastAPI — training-plan rule engine + LLM prose layer
infra/        Docker Compose, nginx, PHP-FPM image
docs/adr/     architecture decision records
```

Two runtimes on purpose — see `docs/adr/0001-two-runtimes.md`.

## Local toolchain (Windows + Laragon)

**Nothing is on `PATH`. Always use full paths.**

| Tool | Path |
|---|---|
| PHP 8.3.30 (ZTS, VS16, x64) | `C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe` |
| Composer 2.9.4 | `C:\laragon\bin\composer\composer.phar` |
| Symfony CLI 5.17.1 | `C:\laragon\bin\symfony\symfony.exe` |
| MySQL 8.4.3 | `C:\laragon\bin\mysql\mysql-8.4.3-winx64\bin\` (datadir `C:\laragon\data\mysql-8.4`) |
| Redis 5.0.14 | `C:\laragon\bin\redis\redis-x64-5.0.14.1\` |
| Mailpit 1.22.3 | `C:\laragon\bin\mailpit\1.22.3\mailpit.exe` |

PHP extensions enabled for this project: `zip`, `opcache`, `sockets`, `bz2`, `pdo_pgsql`,
`pgsql`, plus PECL `redis` 6.3.0, `apcu` 5.1.28, `xdebug` 3.5.3. Docker and WSL are **not**
installed on this machine — Docker is validated in GitHub Actions instead.

Databases: `gym_app` and `gym_app_test`, user `gym` / password `gym`.

### Starting services

Laragon's `laragon.exe start` does nothing headless. Start each binary directly and keep it
running (background tasks, not `Start-Process` — detached children get killed with the
parent):

```bash
"C:/laragon/bin/mysql/mysql-8.4.3-winx64/bin/mysqld.exe" --defaults-file="C:/laragon/bin/mysql/mysql-8.4.3-winx64/my.ini" --console
```

```bash
cd "C:/laragon/bin/redis/redis-x64-5.0.14.1" && ./redis-server.exe redis.windows.conf
```

```bash
"C:/laragon/bin/mailpit/1.22.3/mailpit.exe"
```

### Running the site

**Use the Symfony CLI, never `php -S`.** The PHP built-in server serves files under
`public/bundles/` as `text/html`, so the browser rejects the EasyAdmin stylesheet and the
back office renders unstyled. Symfony CLI needs PHP on `PATH`, so prefix it:

```bash
cd app && export PATH="/c/laragon/bin/php/php-8.3.30-Win32-vs16-x64:$PATH" && "C:/laragon/bin/symfony/symfony.exe" server:start --no-tls --port=8000
```

Site: http://127.0.0.1:8000 (`/` redirects to `/en`) · Admin: http://127.0.0.1:8000/admin ·
Mailpit UI: http://localhost:8025

## Conventions

- **Translated content** uses `App\Domain\TranslatedString` — a readonly value object holding
  `en`/`lv`/`ru`, stored in a JSON column by `App\Doctrine\Type\TranslatedStringType`
  (registered as `translated_string`). Fallback order per locale is documented on the class.
  Edit it in forms with `App\Form\TranslatedStringType`, and in EasyAdmin with
  `App\Admin\Field\TranslatedField`. **Do not add `nameEn`/`nameLv`/`nameRu` triplets.**
- **Money** is stored as integer cents; currency is EUR everywhere.
- **Enums** live in `App\Domain\Enum` and are stored as their backing string.
  `Limitation` mirrors the enum in `ai-service/app/schemas.py` — keep the two in step.
- Routes are locale-prefixed: `/{_locale}` with requirement `en|lv|ru`.
- Entities use fluent setters returning `static`, and `__toString()` for admin labels.
- Commit messages: `Mx: subject` for milestones, otherwise conventional-ish prefixes.

## Quality gates (all run in CI on every push)

```bash
cd app && vendor/bin/php-cs-fixer fix --dry-run --diff
```

```bash
cd app && bin/console cache:warmup --env=dev && vendor/bin/phpstan analyse
```

```bash
cd app && bin/phpunit
```

```bash
cd ai-service && .venv/Scripts/python.exe -m ruff check . && .venv/Scripts/python.exe -m pytest -q
```

PHPStan is at **level 8** and must stay there. The PHPStan step needs a warmed dev cache
because the Symfony extension reads the compiled container.

Repository tests need a seeded test database:

```bash
cd app && php bin/console doctrine:migrations:migrate --env=test --no-interaction && php bin/console doctrine:fixtures:load --env=test --no-interaction
```

## Gotchas already paid for

- `interval` is a **reserved word in MySQL** — `MembershipPlan` maps it to `billing_interval`.
- EasyAdmin **5** differs from 4: `MenuItem::linkTo(controller, label, icon)` replaced
  `linkToCrud()`, and `FieldInterface::new()` takes `TranslatableInterface|string|bool|null`.
- Doctrine **DBAL 4** removed `Type::getName()` and the `ConversionException` factories —
  use `Exception\InvalidType::new()` and `Exception\ValueNotConvertible::new()`.
- Symfony **8 requires PHP 8.4**, so this project stays on **7.4 LTS**.
- Files committed from Windows need `git update-index --chmod=+x` for anything Linux CI executes.
- `assets/vendor/` is git-ignored, so CI has no downloaded JS until `composer install` fires
  `importmap:install` from `post-install-cmd`. Nothing extra is needed in the workflow.
- Leaflet's default marker is a PNG the stylesheet resolves relative to itself, and AssetMapper
  digests that filename, so it 404s. `assets/map.js` uses a `divIcon` instead - which is also
  how the pins get the brand colour.
- Anything a `<g role="button">` visibly contains becomes part of the label axe compares against
  its accessible name. Adornments inside the group (the floor plan's kit counts) trip
  `label-content-name-mismatch`; `aria-hidden` on them is **not** honoured for SVG text, so keep
  them outside the group and let `pointer-events-none` drop clicks through.
- `.gitattributes` enforces **LF**. Python's `pathlib.write_text` emits CRLF on Windows unless
  you pass `newline='
'`, and php-cs-fixer then rewrites the whole file. Check with
  `grep -qU $'
'` before committing.
- `bin/console cache:clear` regularly exceeds two minutes on this machine. `rm -rf var/cache/dev`
  followed by `cache:warmup --env=dev` does the same job in seconds.
- `lines` is a **reserved word in MySQL 8.4**, like `interval`. Alias it in ad-hoc `dbal:run-sql`.
- The working copy lives at **`C:\dev\gym-proj`**, deliberately outside OneDrive — `vendor/`,
  `.venv/` and the 107 MB Tailwind binary in `app/var/` come to ~350 MB and used to sync on
  every build. Do not move it back under `OneDrive\Desktop`.
- `UserInterface::eraseCredentials()` is deprecated as of Symfony 7.3, and `AuthenticatorManager`
  triggers that deprecation unless the method carries **`#[\Deprecated]`**. With
  `failOnDeprecation="true"` in phpunit.dist.xml, forgetting it fails every form-login test.
- **Constraint messages live in the `validators` domain**, never in `messages`. The Validator
  component translates violations itself, so a key left in `messages` renders on screen as the
  raw key. `translations/validators.*.yaml` holds them.
- Symfony answers an **invalid form submission with 422**, not 200. Controller tests must assert
  `assertResponseStatusCodeSame(422)`, not `assertResponseIsSuccessful()`.
- **Stateless CSRF** (`config/packages/csrf.yaml`) validates same-origin signals, so
  `csrf_token('...')` renders the literal string `csrf-token` and the optional JS swaps in a
  random value. In BrowserKit tests a `Referer` only exists once the history is non-empty, so a
  synthesised POST needs a GET before it.
- A **Twig comment inside a hash literal** is a parse error. Put `{# ... #}` above the expression,
  not between a hash's keys.
- Anything that renders a **password field wants a username field beside it**, even a hidden one,
  or Chrome logs a console warning. Name it outside the form type's namespace so the form does
  not see it as an extra field.
- `order` is a **reserved word in MySQL** too, like `interval` and `lines` - the orders table is
  `customer_order` and the lines are `customer_order_item`.
- A **pluralised message rendered with a bare `|trans` emits the raw string**, pipes and all
  (`{0}empty|{1}1 item|]1,Inf[ %count% items`). Symfony only selects a plural when `%count%` is
  in the parameters, so write `|trans({'%count%': n})`. Nothing fails - it renders on the page.
- EasyAdmin 5 wants **`#[AdminRoute]` on any custom CRUD action**, or the index page 500s when it
  tries to generate the URL. Test admin pages by requesting them, not by constructing controllers.
- **Disabling an EasyAdmin action is not the same as removing it.** `remove()` only hides the
  button and leaves `/admin/<entity>/new` working; `disable()` makes it 403. Orders use `disable()`
  so no one can hand-write a paid order.
- **Symfony's `TimeType` stays a compound widget** even with `with_minutes: false`: its label
  points at the wrapping div, so the `<select>` inside it has no label and Lighthouse fails
  `select-name`. A `ChoiceType` plus a model transformer gives one control with one real label.
  See `App\Form\DataTransformer\HourToTimeTransformer`.
- A **generic `@implements DataTransformerInterface<A, B>` narrows `mixed`**, so defensive
  `instanceof`/`is_string` checks inside the transformer are dead code and PHPStan says so. Trust
  the generic and delete them rather than reaching for an ignore.
- **A unique index keyed on the wrong column quietly takes something off sale forever.** Keying
  bookings on `(trainer_id, starts_at)` means a declined hour keeps its row, so the index keeps
  refusing it while the slot picker keeps offering it. Key on a nullable `held_slot_at` that goes
  null when the booking lets go - MySQL allows any number of nulls in a unique index.
- **Two mails about the same event are not the same mail.** The coach's "new session request" and
  the member's "we passed it on" shared a subject line at first, which made a member's inbox read
  as though somebody wanted an hour from *them*.

- **A docblock cannot fail a build.** Four enums mirror `ai-service/app/schemas.py`, and each one
  asked the next person to keep them in step. `tests/Domain/PythonContractTest.php` reads the
  Pydantic file as text and asserts it, so a drift committed by somebody who never ran the Python
  service still fails. `TranslationParityTest` does the same for the three catalogues.
- **A missing translation key fails nothing at runtime** - Symfony falls back, and if the fallback
  misses too, the raw dotted key renders on the page. Only somebody browsing in Latvian or Russian
  ever sees it, which is nobody on the team. Hence the parity test.
- **Unescaped double quotes inside a double-quoted YAML scalar** break the whole catalogue.
  `bin/console lint:yaml translations` catches it; run it after every translation edit.
- reportlab's default Helvetica **cannot draw Cyrillic** - it silently emits nothing rather than
  failing. DejaVu is vendored in `ai-service/app/fonts/`, and the tests extract text back out of a
  generated PDF rather than concluding from the absence of an exception.

- **A test that passes only because something is not running is not a passing test.** The
  plan-service-down tests originally relied on nothing listening on port 8001. That is true in CI
  and false for anyone running the whole stack, so the suite went red on a developer machine and
  green on the server. Inject a `MockHttpClient` that refuses the connection instead; the suite now
  gives the same result with the service up or down, and both are checked.


## Status

| | Milestone | State |
|---|---|---|
| M0 | Foundation — framework, assets, i18n, Docker, CI | done |
| M1 | Domain model, migrations, fixtures, back office | done |
| M2 | Public site, Leaflet branch map, SVG floor plan | done |
| M3 | Accounts, memberships, Stripe test checkout | done |
| M4 | Shop — catalogue, cart, orders | done |
| M5 | Trainers, availability, booking, messaging | done |
| M6 | Assessment, rule engine, LLM layer, PDF export | done |
| M7 | Coverage, docs, screenshots | **next** |

### Decisions already made

- **Docker:** compose files verified in CI now; Docker Desktop installed locally later.
- **Payments:** Stripe **test mode**. Andrii creates the account and puts keys in
  `app/.env.local` himself — never handle the keys directly.
- **Training plans:** deterministic Python rule engine decides every number; the LLM layer
  only writes prose and must never change sets, reps or loads. The site must work with no
  API key configured. PAR-Q+ red-flag screening gates every plan and is already live in
  `ai-service`.
- **Gym map:** both a Leaflet/OpenStreetMap map of Riga branches and a clickable SVG floor
  plan driven by `FloorZone.svgId`. The plan is **generated, not drawn**: `FloorPlanBuilder`
  tiles one storey's rooms, `ZoneLayoutBuilder` places every individual machine inside a room.
  Hand-authored coordinates were rejected - they would only describe the branches that existed
  the day somebody typed them, and there is no editor to move them afterwards.
- **Floor zones:** every room is a `FloorZone`, changing rooms and reception included, so all
  of them are clickable, translated and editable. `floor` picks the storey (lounge and spa are
  upstairs) and `kind` separates training floors from amenity rooms. Only the entrance is a
  fixed marker, because a doorway is not a room. `EquipmentType::FIXTURE` is the one case that
  is not exercise equipment - it is how amenity rooms list lockers and saunas.

### Accounts and payments (M3)

- **One firewall** covers the whole site. Staff are ordinary `User` rows carrying
  `ROLE_ADMIN`; `/admin` is gated by `access_control`, not by a second login. The M1
  in-memory provider, the `admin` firewall and `ADMIN_PASSWORD_HASH` are all gone.
- **`User`** is table `app_user` (`user` is a keyword in too many engines). `getRoles()`
  always appends `ROLE_USER`, so the column stores extra roles only.
- **`UserMembership`** is a membership somebody holds, as opposed to `MembershipPlan`,
  which is the tier on the price list. It copies the price in at purchase, so repricing a
  tier never rewrites what past members were charged.
- **Only the Stripe webhook may activate a membership.** Checkout writes a PENDING row and
  hands off; `/webhook/stripe` verifies the signature and promotes it. The success page can
  legitimately show PENDING, because the webhook is a separate connection that can land
  after the browser redirect.
- **Empty Stripe keys are a supported state**, not a broken one - it is how a fresh clone
  and CI both run. `StripeCheckout::isConfigured()` gates every call, and the membership
  page says checkout is unavailable instead of rendering a dead button. Test it that way;
  a live-key run is Andrii's alone.
- Fixtures seed `admin@speks.lv`, `member@speks.lv` and `prospect@speks.lv`, all with
  password `speks-dev`.

### The training-plan generator (M6)

- **The rule engine computes every number.** Split, weekly set volume, rep ranges, RIR and the
  mesocycle all come out of `ai-service/app/engine`. The LLM layer writes prose and nothing else,
  and that is enforced by the types: `PlanProse` holds a string and a map of strings, parses with
  `extra="ignore"`, and `apply_prose` is the only writer of model output. The model is never asked
  for a plan, so there is nothing to trust and then verify.
- **Plans generate with no API key.** `llm_used` reports the truth and the coaching notes fall back
  to a deterministic localized string. This is how CI and a fresh clone run, and it is tested.
- **`training_plan.payload` stores the whole document the service returned.** The engine owns that
  shape and versions it in `engine_version`; normalising it into tables here would mean a schema
  migration every time the engine learned a field, and would make this side the authority on a
  structure it does not decide.
- **The PAR-Q+ gate runs on both sides.** Symfony refuses to call the service at all when an
  assessment carries a red flag - the service would refuse anyway, and not making the call is the
  point. The eight answers are columns rather than a JSON blob: they are a fixed, safety-critical
  set, so a missing one should be a schema error.
- **The plan service being down is a supported state**, exactly like empty Stripe keys. Every
  transport failure leaves `PlanService` as one `PlanServiceUnavailable`; the controller flashes it
  and keeps the member's answers on the form, so "nothing was lost" is true rather than reassuring.
  Nothing is listening on port 8001 in CI, so that is the path the whole suite runs.
- Times, money and enums aside, this is the one feature where **the two runtimes have to agree**.
  See ADR 0001, and the contract test that enforces it.

### Trainers and booking (M5)

- **The coach owns the diary.** A booking starts REQUESTED because a member asking for an hour is
  not a coach agreeing to work it. Only the coach may confirm or decline; either side may cancel
  something that has not happened yet.
- **Times are stored UTC and shown in `Europe/Riga`.** `SlotFinder` expands availability windows in
  Riga wall clock - a coach saying "09:00" means 09:00 in Riga - then converts each slot to UTC.
  `trainer_availability.start_time`/`end_time` are naive local times by design, which is what keeps
  them right across both clock changes a year. `SlotFinder::TIMEZONE` is the single source of that
  string and reaches Twig as `gym_timezone()`.
- **`SlotFinder` takes a `ClockInterface`.** Slot generation that reads the wall clock directly is
  untestable; with `MockClock` the lead time, the past-slot cut-off and the booked-slot exclusion
  are all assertable.
- **The price is snapshotted onto the booking**, pro-rated from the coach's hourly rate. Same
  promise `UserMembership` and `OrderItem` make.
- **There is no coach firewall and no `ROLE_TRAINER`.** A coach is a `User` some `Trainer` row
  points at. Ownership - "is this booking yours" - cannot be written as a path pattern, so it lives
  in `CoachController` next to the code it guards rather than in `security.yaml`.
- Mail goes out in the **recipient's** profile locale, not the request locale: a booking can send a
  Latvian mail to the member and a Russian one to the coach from the same click.

### The shop (M4)

- **`Order` snapshots what was bought.** Name, SKU and unit price are copied onto the line, and
  the product and variant references are nullable `ON DELETE SET NULL`. Repricing or deleting a
  product must never rewrite what somebody was charged - the same reason `UserMembership` copies
  its price in.
- **The cart stores ids and quantities in the session, never prices.** Every render re-reads the
  catalogue, so a stale session cannot underpay; lines whose product has since been deactivated
  drop out silently and quantities clamp to stock.
- **Only the Stripe webhook may mark an order PAID**, exactly as with memberships. One endpoint
  now serves both, and which one a session belongs to is decided by its `order_id` metadata -
  never guessed from the amount.
- **Stock is drawn down on payment, not at checkout**, so an abandoned checkout does not hold
  stock hostage. It is floored at zero: two people can pay for the last item in the same second,
  and refusing money Stripe has already taken would be worse than overselling one unit.
- **`ProductVariant` is optional.** A product with no variants sells on its own price and stock;
  one with variants sells only through them. Both paths are seeded so both stay tested.
- Cart mutations carry their own CSRF token id (`cart`), separate from `checkout`. Adding to a
  basket is not handing money to Stripe and the two should not share a token.
