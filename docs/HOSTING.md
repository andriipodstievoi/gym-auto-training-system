# Hosting SPĒKS on a free tier

**Researched:** 2 September 2026. Free tiers move constantly; every limit below
carries the URL it came from, and anything I could not confirm from a primary
source is marked as unverified rather than smoothed over.

The thing being placed is two runtimes in one repository — Symfony 7.4 on
PHP 8.3 and a FastAPI service on Python 3.14 — plus MySQL 8.4 and Redis 7. See
[ADR 0001](adr/0001-two-runtimes.md) for why the split exists. `infra/` already
contains a working `compose.yaml` and two Dockerfiles, and CI builds both images
on every push, so anything that accepts a container starts from a real advantage.

## The recommendation

**An Oracle Cloud Always Free ARM instance in `eu-frankfurt-1`, running the
existing `infra/compose.yaml`.**

It is the only genuinely free option that runs the whole stack as designed. The
Always Free tier gives 1,500 OCPU hours and 9,000 GB hours per month of Ampere
A1 compute — "for Always Free tenancies, this is equivalent to 2 OCPUs and 12 GB
of memory" — along with 200 GB of block storage and 10 TB of outbound transfer
per month ([Oracle docs][ora-free]). That is an ordinary Linux VM, so PHP-FPM,
uvicorn, MySQL 8.4 and Redis 7 all run as containers exactly as they do locally.
Nothing sleeps, nothing cold-starts, the database does not expire, Redis stays
Redis, the migrations stay MySQL, and Frankfurt is roughly 20 ms from Riga. The
repository's Docker assets stop being a CI artefact and become the deployment.

**What it costs in effort.** This is a VM, not a platform, so the work is
sysadmin work and it is real: create the instance (retrying past "out of host
capacity", which is common enough to have spawned [tooling][ora-capacity], though
EU regions are reported to provision faster than US ones), open ports in both the
OCI security list *and* the instance's own iptables rules, install Docker and
Compose, put a TLS terminator in front (Caddy is the least work), write a systemd
unit so the stack survives reboot, and own your own backups. Call it an evening
for someone who has done it before, a weekend for someone who has not. There is
no `git push` deploy; you write a small deploy script or use a GitHub Actions SSH
step.

**What it gives up.** Three honest things. First, Oracle halved this allowance
from 4 OCPU/24 GB to 2 OCPU/12 GB effective 15 June 2026 and did so by quietly
editing the documentation, with instances over the new limit terminated from
18 August 2026 ([InfoQ][ora-cut]). Whatever the tier is today is not a promise
about next year. Second, and more pointed for a low-traffic portfolio site, the
documented policy is that "idle Always Free compute instances may be reclaimed by
Oracle" — judged over a 7-day window on CPU 95th-percentile, network, and memory
all being under 20% ([Oracle docs][ora-free]). A site nobody visits is, by that
definition, idle. Third, a credit card is required at signup for identity
verification, even though Always Free resources never bill.

If the reclamation risk is unacceptable, the correct answer is not another free
tier — it is [Hetzner][hetzner], where a CX22 in Falkenstein or Helsinki removes
every problem in this document for something in the region of €4-6/month.

## Comparison

| Option | Long-lived PHP? | Second service? | Free database | Free Redis | Sleeps? | Card? | Verdict |
| --- | --- | --- | --- | --- | --- | --- | --- |
| **Oracle Cloud Always Free** | Yes — full VM | Yes, both in one Compose stack | Self-hosted MySQL 8.4 on the VM; also a separate Always Free MySQL HeatWave with 50 GB ([docs][ora-free]) | Yes, self-hosted | No | Yes, for verification | **Recommended** |
| Render | Yes, via Dockerfile ([docs][render-docker]) | Yes, but two services share 750 free instance hours/month ([docs][render-free]) | Postgres only, 1 GB, **expires 30 days after creation** ([changelog][render-pg]) | "Key Value", one per workspace, in-memory only, data lost on restart ([docs][render-free]) | Yes — 15 min idle, ~1 min to wake ([docs][render-free]) | Not to start; needed to avoid suspension on overage | Runner-up |
| Alwaysdata free | Yes — real PHP shared hosting, EU (France) | Unverified for the free plan (needs the "user program" site type) | MySQL included; DB disk is 1/10 of the pack, so ~100 MB | No | No | No | Plausible, unverified |
| Koyeb | Yes, container | One free service only | Postgres, 1 GB, 5 h active time/month ([docs][koyeb-pricing]) | No | Free instance does not scale to zero | Yes | **Closed to new signups** — Starter plan "will soon be removed" after the Mistral AI acquisition, 17 Feb 2026 ([Koyeb][koyeb-mistral], [TechCrunch][tc-koyeb]) |
| Fly.io | Yes, container | N/A | N/A | N/A | N/A | Card ends the trial | **No free tier.** Trial is "2 hours of machine runtime or 7 days of access, whichever comes first" ([docs][fly-trial]) |
| Railway | Yes, container | Yes | Yes | Yes | No | Yes, since Aug 2023 | Not free — $5 one-time trial credit, then $1/month credit ([docs][railway-plans]) |
| Zeabur | Yes, container | Yes | Yes | Yes | **Yes**, sleeps on inactivity ([docs][zeabur-free]) | No | Free plan is a $5/month credit, not an allowance |
| Clever Cloud | Yes, first-class PHP, EU | Yes | "free-tier plan available for some databases" (DEV plans, no SLA, no backups) ([FAQ][cc-faq]) | Same | No | Yes | No free application hosting; from ~€5/month |
| Google Cloud Run | Yes, container | Yes, two services | **No** always-free Cloud SQL ([docs][gcp-free]) | No | Yes, scale-to-zero cold starts | Billing account required ([docs][gcp-free]) | Free compute only, and no free MySQL to pair with it |
| Upsun / Platform.sh | Yes, first-class PHP | Yes | Yes | Yes | No | Yes | 15-day trial, then "your project will be suspended" ([support][psh-trial]) |
| Vercel / Netlify / GitHub Pages | **No** | — | — | — | — | — | **Cannot host Symfony.** See below |
| *Hetzner CX22 (paid baseline)* | Yes — full VM | Yes | Self-hosted | Self-hosted | No | Yes | ~€4-6/month removes every constraint in this table |

### On the static and serverless hosts, plainly

**Vercel, Netlify and GitHub Pages cannot host this application.** GitHub Pages
serves static files only and has no server-side runtime at all. Netlify Functions
natively support JavaScript, TypeScript and Go. Vercel's official runtimes are
Node.js, Bun, Python, Rust, Go, Ruby, Wasm and Edge — PHP appears only as a
community runtime, `vercel-php` ([Vercel docs][vercel-runtimes]).

Even setting the runtime question aside, the model is wrong. Vercel Functions
have "a read-only filesystem with writable `/tmp` scratch space" and a maximum
duration, and are archived when not invoked ([Vercel docs][vercel-runtimes]).
Symfony expects a writable `var/`, a warm compiled container, a persistent
session store and a long-lived process. You could force a demo through the
community runtime; you could not run EasyAdmin, Doctrine migrations and Redis
sessions behind it without rebuilding the application around a different set of
assumptions. The FastAPI half, being stateless, *would* fit Vercel's Python
runtime — but splitting the two halves across two providers to save nothing is
not an improvement.

## What this project would have to change

On the recommended option the application changes very little, because the
environment matches the one it was written for. Most of this list is production
hygiene that any host would require.

**`APP_SECRET`.** `app/.env` currently commits a real-looking secret
(`fd56255170...`). That value must be treated as burned. Generate a new one
(`php -r 'echo bin2hex(random_bytes(16)), "\n";'`) and inject it as a genuine
environment variable — an untracked `.env.local` on the VM, or an `env_file:` on
the `php` service that is not in the repository. It must never be committed.

**`APP_ENV` and the image.** `infra/compose.yaml` sets `APP_ENV: dev`. Production
needs `APP_ENV=prod` and `APP_DEBUG=0`. The PHP Dockerfile also installs dev
dependencies — it runs `composer install` with no `--no-dev` — which ships
`web-profiler-bundle` and `maker-bundle` into the image. Add `--no-dev` and keep
`--optimize-autoloader`. A production compose file (`infra/compose.prod.yaml`)
that overrides the dev environment block, drops the source bind-mounts, and drops
the Mailpit service is cleaner than editing the dev one.

**Database: MySQL, and it should stay MySQL.** `DATABASE_URL` points at the
MySQL container on the Compose network; nothing changes. This matters more than
it looks. The six files in `app/migrations/` are raw MySQL DDL — `AUTO_INCREMENT`,
`TINYINT`, `DEFAULT CHARACTER SET utf8mb4`, inline `UNIQUE INDEX` inside
`CREATE TABLE`, and `ALTER TABLE ... DROP FOREIGN KEY` in every `down()`. None of
that is valid PostgreSQL. Moving to a Postgres-only free tier (Render, Neon,
Supabase) means deleting all six migrations and regenerating them with
`doctrine:migrations:diff` against an empty Postgres schema — which throws away
the hand-written index fix recorded in [EXTRA-CHANGES.md](EXTRA-CHANGES.md), the
one re-keyed on a nullable `held_slot_at`, unless it is deliberately re-applied
and re-tested. It also means re-verifying `App\Doctrine\Type\TranslatedStringType`
and every `JSON` column against Postgres `json`/`jsonb` semantics. `doctrine.yaml`
already sets `identity_generation_preferences` for `PostgreSQLPlatform`, so the
groundwork is there, but the migration set is a day of work and a fresh source of
bugs in the one part of the schema that carries money (`customer_order`,
`billing_interval`). Do not take that on to save nothing.

If a managed MySQL is ever wanted instead of the container, two hold up:
[Aiven's free MySQL][aiven-mysql] (1 GB storage, 1 GB RAM, 1 CPU, single node,
no credit card, and you can pick Europe as a region group — but the service
"powers off after a period of inactivity"), and [TiDB Cloud Starter][tidb]
(MySQL-compatible, 5 GiB row storage and 50 M request units per month, no card).
Both are strictly worse than a local container on a VM you already have.

**Redis: keep it.** On a VM, Redis 7 is one more container, so `cache.yaml` and
`framework.yaml` stay exactly as they are. The fallback is only needed on hosts
with no free Redis, and it is a pragmatic answer there: set
`framework.cache.app: cache.adapter.filesystem` and
`framework.session.handler_id: null` with
`storage_factory_id: session.storage.factory.native`, which puts sessions on
PHP's native file handler ([Symfony docs][sf-session]). At this traffic the
performance cost is irrelevant. The real cost is durability — on Render, where
free services have no persistent disk and spin down after 15 minutes, filesystem
sessions mean every logged-in member is logged out on every sleep and every
deploy. On a VM with a real disk, filesystem sessions would be perfectly fine;
there is just no reason to accept them.

**Migrations on deploy.** Run them as an explicit release step against the
running database, never in the Docker build (there is no database at build time)
and never in the container `CMD` (it would race if the service were ever scaled):

```
docker compose -f infra/compose.prod.yaml run --rm php \
    bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration
```

Fixtures are separate and must stay manual. A portfolio site does need the demo
content, so `doctrine:fixtures:load --no-interaction` runs **once**, deliberately,
on first provision. It purges the database; it must never be part of the deploy
path.

**Tailwind.** `symfonycasts/tailwind-bundle` downloads a pinned standalone binary
(`binary_version: 'v4.3.3'`) at build time, so the build needs outbound network
and a writable `app/var/tailwind`. The current PHP Dockerfile never builds the
CSS at all — it runs `composer install --no-scripts` and `dump-autoload`, and CI
builds the CSS in a separate job. The image therefore needs the build steps added:

```
RUN php bin/console tailwind:build --minify \
 && php bin/console asset-map:compile
```

Two notes. Those commands boot the kernel, so the build needs `APP_ENV` and a
placeholder `DATABASE_URL` present as build args or `ENV`. And on Oracle's Ampere
hardware the build is aarch64: the bundle does publish a `linux-arm64` binary and
detects the architecture itself, but that detection is [documented as
imperfect][tw-bundle], so if the build fails, pin it explicitly with
`binary_platform: 'linux-arm64'` in `config/packages/symfonycasts_tailwind.yaml`.
The `mysql:8.4` and `php:8.3-fpm-alpine` images both publish arm64 variants, so
the rest of the stack is fine. If you would rather not think about any of it,
build the CSS in CI and ship the compiled `public/assets` into the image.

**`AI_SERVICE_URL`.** Stays `http://ai-service:8001` — the Compose network keeps
the FastAPI service private, which is what you want; it should not be publicly
routable. `AI_SERVICE_TIMEOUT` is unchanged.

**`DEFAULT_URI` and proxy headers.** `DEFAULT_URI` must become the real
`https://` origin so that CLI-generated URLs and mail links are correct. Behind
Caddy, set `framework.trusted_proxies` and forward `X-Forwarded-Proto`, otherwise
`cookie_secure: auto` will not resolve to secure cookies.

**`MAILER_DSN`.** Mailpit is local-only and the compose Mailpit service should
not be deployed. Free outbound SMTP is the weakest link on every option here; the
usual answer is [Brevo's free plan][brevo] at 300 emails/day with SMTP relay
access and no card. If mail is out of scope for the demo, `MAILER_DSN=null://null`
disables sending without breaking anything that calls the mailer.

**Stripe and Anthropic keys.** Nothing to do. Leaving `STRIPE_*` and
`AI_ANTHROPIC_API_KEY` empty is a supported state by design, and no option in
this document requires otherwise.

## Runner-up, and why not

**Render for both services, plus Aiven's free MySQL, plus filesystem sessions.**

This is the best pure-PaaS combination and it is genuinely free. Render deploys
from a Dockerfile ([docs][render-docker]), so both images the repository already
builds can be deployed as two web services. Pairing it with [Aiven free
MySQL][aiven-mysql] instead of Render's own Postgres sidesteps the entire
migration-rewrite problem, and Aiven asks for no credit card. Cache and sessions
fall back to the filesystem. There is a real `git push` deploy and no server to
administer.

Two things rule it out. The first is cold starts: free services "spin down after
15 minutes of inactivity" and take about a minute to come back
([docs][render-free]). For a portfolio piece that is the whole ballgame — a
recruiter opens the link and looks at a blank tab for the better part of a minute
before the site appears, and Aiven's free database also "powers off after a period
of inactivity", so the first request may have to wake two things in series. The
second is that free services have no persistent disk, so filesystem sessions and
the compiled Symfony cache are wiped on every spin-down. It works; it does not
present well, which is the entire purpose of this deployment.

Render's own free Postgres is worse still and should be discounted regardless:
1 GB, no backups, and it "expires 30 days after creation" with a 14-day grace
period ([changelog][render-pg]). A portfolio site that quietly deletes its own
database every month is not a portfolio site.

**Honourable mention: [Alwaysdata's free plan][ad-pricing]** — 1 GB SSD, 256 MB
RAM, ¼ CPU, "for life", French (EU) hosting, real PHP, MySQL included, custom
domains, no card. For the Symfony half alone this is a serious option and the
closest thing left to classic free PHP hosting. I am not recommending it because
I could not verify two things that decide it: whether the free plan permits the
"user program" site type needed to run a long-lived uvicorn process alongside the
website, and which PHP extensions (`redis`, `apcu`, `intl`, `zip`) are available.
Its documentation pages for free-plan restrictions returned 404 during this
research. Worth thirty minutes of someone's time before dismissing it; if the
second service turns out to be allowed, it moves up. Note that 256 MB RAM for
PHP-FPM plus Python is tight, the database gets roughly 100 MB, and none of the
repository's Docker work is used — deployment becomes SSH and rsync.

## What is genuinely not possible on a free tier

- **Both services always-on, with a real database, with no card on file
  anywhere.** Alwaysdata is the only card-free option that runs long-lived PHP,
  and its ability to run the second service is unconfirmed. Everything else
  either wants a card (Oracle, Koyeb, Railway, Google Cloud, Upsun) or sleeps
  (Render, Zeabur).
- **A managed PHP platform with a free tier.** This category has closed. Fly.io
  ended free allowances and now offers a trial of "2 hours of machine runtime or
  7 days" ([docs][fly-trial]). Railway removed its free tier in July 2023 and has
  required a card since August 2023 ([docs][railway-plans]). PlanetScale stopped
  new Hobby databases on 6 March 2024 and slept the rest on 8 April 2024
  ([PlanetScale][ps-hobby]). Koyeb's Starter plan closed to new signups after the
  Mistral AI acquisition announced 17 February 2026 ([Koyeb][koyeb-mistral]).
  Clever Cloud and Upsun never had a permanent free application tier.
- **A free managed MySQL that is always warm.** Aiven's free service powers off
  when idle; TiDB Starter denies new connections once the monthly quota is spent
  ([docs][tidb]); Google has no always-free Cloud SQL ([docs][gcp-free]).
  Self-hosting on a VM is the only way to get a MySQL that is simply always there.
- **Free managed Redis that can carry both cache and sessions comfortably.**
  Upstash's free tier is 256 MB, 500 K commands/month, and one database
  ([docs][upstash]) — survivable at this traffic, but Symfony's cache alone is
  chatty and there is no headroom. Render's free Key Value loses all data on
  restart ([docs][render-free]).
- **Zero operational work.** The recommendation trades platform convenience for
  capability. You get the stack you designed; you also get the pager.
- **A guarantee.** Oracle halved this tier mid-2026 without an announcement
  ([InfoQ][ora-cut]) and reserves the right to reclaim idle instances
  ([docs][ora-free]). Free hosting for a portfolio piece is worth doing, and the
  repository should stay portable enough that moving to a €5 VPS is an afternoon,
  not a rewrite. Keeping the database on MySQL is the single biggest thing that
  preserves that portability.

[ora-free]: https://docs.oracle.com/en-us/iaas/Content/FreeTier/freetier_topic-Always_Free_Resources.htm
[ora-cut]: https://www.infoq.com/news/2026/07/oracle-cloud-free-tier-limits/
[ora-capacity]: https://github.com/oeufmeister/oci-arm-host-capacity
[render-free]: https://render.com/docs/free
[render-pg]: https://render.com/changelog/free-postgresql-instances-now-expire-after-30-days-previously-90
[render-docker]: https://render.com/docs/docker
[koyeb-pricing]: https://www.koyeb.com/docs/faqs/pricing
[koyeb-mistral]: https://www.koyeb.com/blog/koyeb-is-joining-mistral-ai-to-build-the-future-of-ai-infrastructure
[tc-koyeb]: https://techcrunch.com/2026/02/17/mistral-ai-buys-koyeb-in-first-acquisition-to-back-its-cloud-ambitions/
[fly-trial]: https://fly.io/docs/about/free-trial/
[railway-plans]: https://docs.railway.com/pricing/plans
[zeabur-free]: https://zeabur.com/docs/en-US/pricing/free-plan
[cc-faq]: https://www.clever.cloud/developers/doc/find-help/faq/
[gcp-free]: https://docs.cloud.google.com/free/docs/free-cloud-features
[psh-trial]: https://support.platform.sh/hc/en-us/articles/8133960022162-Can-I-try-Platform-sh-for-free
[vercel-runtimes]: https://vercel.com/docs/functions/runtimes
[aiven-mysql]: https://aiven.io/free-mysql-database
[tidb]: https://docs.pingcap.com/tidbcloud/select-cluster-tier/
[ps-hobby]: https://planetscale.com/docs/plans/hobby-plan-deprecation-faq
[upstash]: https://upstash.com/docs/redis/overall/pricing
[ad-pricing]: https://www.alwaysdata.com/en/pricing/
[sf-session]: https://symfony.com/doc/current/session.html
[tw-bundle]: https://github.com/SymfonyCasts/tailwind-bundle
[brevo]: https://www.brevo.com/free-smtp-server/
[hetzner]: https://www.hetzner.com/cloud/
