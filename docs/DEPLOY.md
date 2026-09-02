# Deploying SPEKS

The runbook for putting this repository on the host [HOSTING.md](HOSTING.md)
recommends: an Oracle Cloud Always Free Ampere A1 instance in `eu-frankfurt-1`,
running `infra/compose.prod.yaml`.

It is split into what a person has to do and what a script can do, because the
division is real rather than tidy. Creating a cloud account needs a credit card
for identity verification, creating an instance needs a console session that
may have to be retried past "out of host capacity", and pointing a domain at an
IP needs the registrar's login. None of that can be delegated to a script. Once
the VM exists and answers SSH, everything else can be, and
`infra/deploy/provision.sh` does it.

Read [HOSTING.md](HOSTING.md) first if you have not. It is where the choice of
host is argued, and it lists what this project had to change to be deployable
at all - most of which is now in the files this document tells you to run.

## Before you start

You need:

- a credit card, for Oracle's identity check. Always Free resources do not
  bill, but the card is required at signup.
- a domain name you control, or a subdomain of one. Caddy gets a certificate
  from Let's Encrypt by solving a challenge against the public DNS record, so
  there is no HTTPS without a name.
- an SSH key pair.

Budget an evening. Most of it is Oracle's console.

---

## Part one: what a human must do

### 1. Create the Oracle Cloud account

Sign up at `cloud.oracle.com`. Choose a home region close to the audience -
`eu-frankfurt-1` for Riga, roughly 20 ms away - because **the home region
cannot be changed afterwards** and Always Free compute lives in it.

The card is for verification. Keep the account on the Always Free tier and it
does not charge; upgrading to Pay As You Go is a deliberate, separate action.

### 2. Create the instance

Compute, Instances, Create instance.

- **Image**: Ubuntu 24.04 LTS (or the current LTS).
- **Shape**: Ampere, `VM.Standard.A1.Flex`. Give it 2 OCPU and 12 GB, which is
  the whole Always Free allowance in one instance.
- **Networking**: assign a public IPv4 address.
- **SSH keys**: upload your public key.

Expect `Out of host capacity`. It is common enough on A1 shapes to have spawned
retry tooling; EU regions are reported to provision faster than US ones. Wait
and try again rather than switching shape - an AMD E2 micro instance will not
run this stack comfortably.

This is aarch64. Everything in `infra/` builds for it - see
[ARM notes](#arm-notes) below.

### 3. Open ports 80 and 443, in both places

**This is the step that makes a working VM look dead**, and it is two separate
firewalls that both have to allow the traffic.

**The security list**, in Oracle's console. Networking, Virtual Cloud Networks,
your VCN, Security Lists, the default list, Add Ingress Rules:

| Source CIDR | Protocol | Destination port |
| --- | --- | --- |
| `0.0.0.0/0` | TCP | 80 |
| `0.0.0.0/0` | TCP | 443 |

Add UDP 443 as well if you want HTTP/3; clients fall back to TCP without it.

**The instance's own firewall.** Oracle's Ubuntu images ship with iptables
rules that accept SSH and reject everything else, and that rule set survives
reboots. Over SSH:

```bash
sudo iptables -L INPUT --line-numbers
```

Find the line number of the final `REJECT` rule and insert above it:

```bash
sudo iptables -I INPUT 6 -m state --state NEW -p tcp --dport 80 -j ACCEPT
sudo iptables -I INPUT 6 -m state --state NEW -p tcp --dport 443 -j ACCEPT
sudo netfilter-persistent save
```

Adjust `6` to whatever line number the `REJECT` was on. Without
`netfilter-persistent save` the rules are gone after the next reboot, which is
the same failure a week later.

One honest caveat, so that a confusing result does not send you down the wrong
path: Docker publishes ports by DNAT, and that traffic traverses the `FORWARD`
chain rather than `INPUT`. A published container port can therefore answer even
while `INPUT` still rejects. Add the rules anyway - they are what makes the
host reachable for anything not published by Docker, and the security list
above is unconditional in either case.

### 4. Point a domain at it

At your registrar or DNS host, an `A` record for the name you intend to use,
pointing at the instance's public IPv4 address. If you want the apex and `www`,
add both and list both in `SITE_DOMAIN`.

Wait for it to resolve before going further:

```bash
dig +short speks.example.com
```

That has to return the instance's IP. Caddy's ACME challenge is answered over
HTTP on port 80 at that name; if the record is missing or still cached
elsewhere, issuance fails, and failed attempts count against Let's Encrypt's
rate limits.

---

## Part two: what can be automated

`infra/deploy/provision.sh` does all of it: installs Docker from Docker's own
apt repository, clones the repository, writes `infra/deploy/.env.prod` with
freshly generated secrets, builds the images, starts the stack and runs
migrations. It is written to be re-run - every step checks what it is about to
create - so it is also the update path.

Over SSH, on a machine that has done nothing yet:

```bash
sudo apt-get update && sudo apt-get install -y git
git clone https://github.com/andriipodstievoi/gym-auto-training-system.git /tmp/speks-bootstrap
sudo SITE_DOMAIN=speks.example.com ACME_EMAIL=you@example.com \
    bash /tmp/speks-bootstrap/infra/deploy/provision.sh --seed
```

It clones into `/opt/speks` and works there; the bootstrap copy in `/tmp` is
only there to get the script onto the box and can be deleted afterwards.

`--seed` loads the demo content. It purges the database first, so it runs once,
on first provision, behind a marker file in `/var/lib/speks/seeded`. Leave it
off on every later run.

The first build takes several minutes on two Ampere cores: the PHP image
installs Composer dependencies, downloads the Tailwind binary, compiles the
stylesheet and digests the asset map, all inside the image.

### Doing it by hand instead

The script is not magic and there is no harm in running the steps yourself.
Install Docker (Docker's own repository publishes `arm64` packages), then:

```bash
git clone https://github.com/andriipodstievoi/gym-auto-training-system.git /opt/speks
cd /opt/speks
cp infra/deploy/.env.prod.example infra/deploy/.env.prod
chmod 600 infra/deploy/.env.prod
```

Edit `infra/deploy/.env.prod`. Every variable is documented in the example
file; the ones without a usable default are `SITE_DOMAIN`, `ACME_EMAIL`,
`DEFAULT_URI`, `APP_SECRET`, and the four database values, which must agree
with each other. `ACME_EMAIL` in particular cannot be left blank: Caddy's
`email` option takes an argument, and an empty one is a Caddyfile parse error
that shows up as Caddy restarting in a loop while everything else looks
healthy. Generate the secret with:

```bash
openssl rand -hex 16
```

Then:

```bash
docker compose -f infra/compose.prod.yaml up -d --build
docker compose -f infra/compose.prod.yaml run --rm php \
    bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration
docker compose -f infra/compose.prod.yaml --profile seed run --rm seed
```

Migrations are a release step, deliberately: never in the Docker build, where
there is no database, and never in a container's `CMD`, where two replicas
would race. Fixtures are the third command and are separate because they purge
the database. Run them once and never again.

---

## Verifying it worked

### The stack itself

```bash
cd /opt/speks
docker compose -f infra/compose.prod.yaml ps
```

Every service should be `running`, and `caddy`, `nginx`, `php`, `mysql` and
`redis` should be `(healthy)`. `nginx` is the one worth watching after a
deploy: its health check asks for a real page through php-fpm rather than
touching a socket, so it goes red on an image that starts perfectly well and
serves nothing.

Nothing but Caddy should have a published port. Check rather than assume:

```bash
docker compose -f infra/compose.prod.yaml ps --format '{{.Service}}  {{.Ports}}'
```

Only `caddy` may show `0.0.0.0:80` and `0.0.0.0:443`. `mysql`, `redis` and
`ai-service` must show container-internal ports only.

### The site

```bash
curl -sS -o /dev/null -w '%{http_code} -> %{redirect_url}\n' http://speks.example.com/
```

`308 -> https://speks.example.com/` - Caddy's automatic redirect to HTTPS.

```bash
curl -sS -o /dev/null -w '%{http_code} -> %{redirect_url}\n' https://speks.example.com/
```

`302 -> https://speks.example.com/en`. **The scheme in that Location header is
the trusted-proxy check.** Symfony generates it from what it believes the
request was; if it says `http://`, `TRUSTED_PROXIES` is not reaching the
container and the session cookie will be missing its `Secure` flag too.

```bash
curl -sS https://speks.example.com/en | grep -o '/assets/styles/app-[^"]*\.css'
```

One digested filename, something like `/assets/styles/app-jQSQAqW.css`. If this
prints nothing the image was built without the AssetMapper step and the site is
unstyled - which is not obvious from a status code.

```bash
curl -sS https://speks.example.com/en | grep -c sf-toolbar
```

`0`. Anything else means the container is running `dev` and the debug toolbar
is in front of visitors.

```bash
curl -sSI https://speks.example.com/en | grep -i strict-transport
```

The HSTS header from the Caddyfile, which only appears on a real TLS response.

### That the private services are private

From your own machine, not from the VM:

```bash
nc -z -w3 <public-ip> 3306 ; echo "mysql:      $?"
nc -z -w3 <public-ip> 6379 ; echo "redis:      $?"
nc -z -w3 <public-ip> 8001 ; echo "ai-service: $?"
```

All three must be non-zero - a refused or timed-out connection. `ai-service`
has no authentication of its own and holds the Anthropic key; it is reachable
only at `http://ai-service:8001` on the compose network.

### The database

```bash
docker compose -f infra/compose.prod.yaml run --rm php \
    bin/console doctrine:migrations:status
```

`Already at latest version`, and an executed count matching the files in
`app/migrations/`.

### The plan generator

The one path that crosses both runtimes. Sign in as a seeded member
(`member@speks.lv`, password `speks-dev` - change or delete these on anything
public) and submit an assessment. A plan should come back with a split, five
weeks and an engine version. With `AI_ANTHROPIC_API_KEY` empty it will report
`llm_used: false` and use the deterministic coaching notes, which is a
supported state and not a failure.

---

## Verifying the stack locally

Worth doing before touching the VM, and the only way to be sure a change to
`compose.prod.yaml` still starts.

Make a throwaway environment file - `infra/deploy/.env.prod.local` is
git-ignored by the same rule as `.env.prod`:

```bash
cp infra/deploy/.env.prod.example infra/deploy/.env.prod.local
```

Set in it:

```
SPEKS_ENV_FILE=deploy/.env.prod.local
SITE_DOMAIN=localhost
ACME_EMAIL=you@example.com
DEFAULT_URI=https://localhost
APP_SECRET=<openssl rand -hex 16>
MYSQL_ROOT_PASSWORD=<anything>
MYSQL_PASSWORD=<anything>
DATABASE_URL=mysql://gym:<the same>@mysql:3306/gym_app?serverVersion=8.4.3-mysql&charset=utf8mb4
```

`SITE_DOMAIN=localhost` is what keeps Let's Encrypt out of it: Caddy issues
from its own internal CA for `localhost`, `*.localhost`, `.local` names and
bare IP addresses, and never opens an ACME connection for them. If you want to
test with a hostname Caddy would otherwise treat as public, set
`CADDY_TLS_POLICY=tls internal` instead and it will do the same thing.

The stack runs under its own project name, so it does not disturb the dev
stack from `infra/compose.yaml`:

```bash
docker compose -p speks-prod -f infra/compose.prod.yaml \
    --env-file infra/deploy/.env.prod.local up -d --build
docker compose -p speks-prod -f infra/compose.prod.yaml \
    --env-file infra/deploy/.env.prod.local --profile seed run --rm seed
```

Then run the checks above against `https://localhost`, with `curl -k` because
the certificate is from Caddy's internal CA and your machine has not been asked
to trust it. Tear it down completely afterwards:

```bash
docker compose -p speks-prod -f infra/compose.prod.yaml \
    --env-file infra/deploy/.env.prod.local down -v
rm infra/deploy/.env.prod.local
```

---

## Deploying an update

```bash
sudo bash /opt/speks/infra/deploy/provision.sh
```

Same script, no `--seed`. It fast-forwards the working copy, rebuilds, restarts
and migrates. Expect a few seconds of downtime while the PHP container is
replaced; on a portfolio site that is not worth engineering around.

Two things that follow from how the images are built:

- **The stylesheet is compiled into the image**, and nginx serves it from a
  volume that the PHP container refills on every start. That is why a rebuild
  is enough and there is no separate asset step - and why a bind mount of
  `app/` over the document root, which is what the dev stack used to do, breaks
  it.
- **`APP_SECRET` must not change** on an update. It signs the "remember me"
  cookie and the CSRF hashes; rotating it signs everybody out. `provision.sh`
  never rewrites an existing `.env.prod`, which is what protects it.

## Rolling back, and starting over

**Take a dump first.** Migrations do not undo themselves as part of a rollback,
and `doctrine:migrations:migrate prev` runs `down()` methods that drop columns:

```bash
docker compose -f infra/compose.prod.yaml exec -T mysql \
    sh -c 'MYSQL_PWD=$MYSQL_ROOT_PASSWORD mysqldump -u root --single-transaction gym_app' \
    > ~/speks-$(date +%F-%H%M).sql
```

To go back to an earlier build:

```bash
cd /opt/speks
git log --oneline -10
git checkout <sha>
docker compose -f infra/compose.prod.yaml up -d --build
```

If that commit predates a migration that has already run, the schema is ahead
of the code. Restore the dump rather than migrating down, unless you have read
the `down()` methods involved.

To stop everything but keep the data:

```bash
docker compose -f infra/compose.prod.yaml down
```

To start completely over:

```bash
docker compose -f infra/compose.prod.yaml down -v
rm -f /var/lib/speks/seeded
```

`down -v` deletes the named volumes - the database, the Redis append-only file,
**and Caddy's certificates**. Let's Encrypt allows five duplicate certificates
per registered domain per week, so doing this repeatedly on a live domain will
leave the site without a certificate until the window rolls forward. If you
only want a clean database, remove that one volume:

```bash
docker compose -f infra/compose.prod.yaml down
docker volume rm speks-prod_db_data
```

## Backups

Nothing here backs anything up. On a VM you own, that is your job. The whole
state is three named volumes, and only one of them matters:

```bash
docker compose -f infra/compose.prod.yaml exec -T mysql \
    sh -c 'MYSQL_PWD=$MYSQL_ROOT_PASSWORD mysqldump -u root --single-transaction --routines gym_app' \
    | gzip > /var/backups/speks-$(date +%F).sql.gz
```

A cron entry and somewhere off the box to copy it to is the minimum. Redis holds
cache and sessions and can be lost; Caddy's volume re-issues itself.

Also worth knowing, from [HOSTING.md](HOSTING.md): Oracle's documented policy is
that idle Always Free instances may be reclaimed, judged over a seven-day window
on CPU, network and memory all staying under 20 per cent. A portfolio site that
nobody visits meets that definition.

## ARM notes

The target is `aarch64`. `php:8.3-fpm-alpine`, `mysql:8.4`, `redis:7-alpine`,
`nginx:1.29-alpine`, `caddy:2.11-alpine` and `python:3.14-slim` all publish
`arm64` variants, so the stack builds natively and nothing needs emulation.

The one component that has been known to be awkward is Tailwind.
`symfonycasts/tailwind-bundle` downloads a pinned standalone binary during the
image build and detects the architecture itself, and that detection is
documented as imperfect. If `tailwind:build` fails on the VM with a download or
exec-format error, pin it explicitly in
`app/config/packages/symfonycasts_tailwind.yaml`:

```yaml
symfonycasts_tailwind:
    binary_version: 'v4.3.3'
    binary_platform: 'linux-arm64'
    process_timeout: 300
```

That file is shared with local development, so if you add it, add it as an
override rather than committing an architecture the Windows toolchain cannot
run. The alternative HOSTING.md offers is to build the CSS in CI and ship the
compiled `public/assets` into the image.

The build also needs outbound network and a writable `app/var/tailwind`, both
of which it has inside the image.

## What is where

| Path | What it is |
| --- | --- |
| `infra/compose.prod.yaml` | The production stack |
| `infra/caddy/Caddyfile` | TLS termination and the reverse proxy to nginx |
| `infra/nginx/default.conf` | Shared with the dev stack; the document root and front controller |
| `infra/php/Dockerfile` | Shared with the dev stack; builds the production image |
| `infra/deploy/.env.prod.example` | Every variable, documented, with no real values |
| `infra/deploy/.env.prod` | The real values. Git-ignored. Only on the server |
| `infra/deploy/provision.sh` | The automatable half of this document |
| `app/.env.prod` | Committed defaults for `APP_ENV=prod` |
