#!/usr/bin/env bash
#
# Provision or update the SPEKS production stack on an Ubuntu LTS VM.
#
# This is the automatable half of docs/DEPLOY.md. It installs Docker, fetches
# the repository, writes infra/deploy/.env.prod if it is not there yet, builds
# and starts the stack, and runs migrations. It does not create the cloud
# account, the instance, the firewall rules or the DNS record - those need a
# person, and DEPLOY.md says so.
#
# It is written to be re-run. Every step checks the state it is about to
# create: Docker is installed only if absent, the repository is cloned only if
# absent, .env.prod is generated only if absent and is never rewritten, and
# migrations are safe to repeat. Fixtures are not, so they run behind an
# explicit flag and a marker file.
#
# Usage, on the VM:
#
#     sudo SITE_DOMAIN=speks.example.com ACME_EMAIL=you@example.com \
#         bash infra/deploy/provision.sh --seed
#
# Environment:
#
#     SITE_DOMAIN   required on the first run; the name Caddy answers on
#     ACME_EMAIL    required on the first run; contact for the ACME account
#     APP_DIR       where the working copy lives, default /opt/speks
#     REPO_URL      git remote to clone from
#     BRANCH        branch to track, default main
#
# Flags:
#
#     --seed        load demo fixtures, once, on a database that has none
#     --no-build    start what is already built instead of rebuilding
#     --help

set -euo pipefail

APP_DIR="${APP_DIR:-/opt/speks}"
REPO_URL="${REPO_URL:-https://github.com/andriipodstievoi/gym-auto-training-system.git}"
BRANCH="${BRANCH:-main}"
STATE_DIR="${STATE_DIR:-/var/lib/speks}"

DO_SEED=0
BUILD_ARGS=(--build)

for arg in "$@"; do
    case "$arg" in
        --seed)     DO_SEED=1 ;;
        --no-build) BUILD_ARGS=() ;;
        --help|-h)  sed -n '2,40p' "$0" | sed 's/^# \{0,1\}//'; exit 0 ;;
        *)          echo "unknown argument: $arg" >&2; exit 64 ;;
    esac
done

log()  { printf '\n== %s\n' "$*"; }
note() { printf '   %s\n' "$*"; }
die()  { printf '\nprovision.sh: %s\n' "$*" >&2; exit 1; }

[ "$(id -u)" -eq 0 ] || die "run this with sudo - it installs packages and writes to $APP_DIR"

COMPOSE_FILE="$APP_DIR/infra/compose.prod.yaml"
ENV_FILE="$APP_DIR/infra/deploy/.env.prod"
ENV_EXAMPLE="$APP_DIR/infra/deploy/.env.prod.example"

compose() { docker compose -f "$COMPOSE_FILE" "$@"; }

# Replace one KEY=value line in place, or append it if the key is absent.
# Written with awk rather than sed because the values include "/", "&" and
# "?", all of which mean something to sed.
set_var() {
    local file=$1 key=$2 value=$3 tmp
    tmp="$(mktemp)"
    awk -v key="$key" -v value="$value" '
        index($0, key "=") == 1 { print key "=" value; found = 1; next }
        { print }
        END { if (!found) print key "=" value }
    ' "$file" >"$tmp"
    cat "$tmp" >"$file"
    rm -f "$tmp"
}


# --- Docker ------------------------------------------------------------------

log "Docker"
if command -v docker >/dev/null 2>&1 && docker compose version >/dev/null 2>&1; then
    note "already installed: $(docker --version)"
else
    note "installing from Docker's own apt repository (it publishes arm64)"
    export DEBIAN_FRONTEND=noninteractive
    apt-get update -qq
    apt-get install -y -qq ca-certificates curl git
    install -m 0755 -d /etc/apt/keyrings
    curl -fsSL https://download.docker.com/linux/ubuntu/gpg -o /etc/apt/keyrings/docker.asc
    chmod a+r /etc/apt/keyrings/docker.asc
    printf 'deb [arch=%s signed-by=/etc/apt/keyrings/docker.asc] https://download.docker.com/linux/ubuntu %s stable\n' \
        "$(dpkg --print-architecture)" \
        "$(. /etc/os-release && echo "$VERSION_CODENAME")" \
        >/etc/apt/sources.list.d/docker.list
    apt-get update -qq
    apt-get install -y -qq docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin
    systemctl enable --now docker
    note "installed: $(docker --version)"
fi

# So the ordinary login user can run docker without sudo next time. Takes
# effect on their next login, not in this shell.
if [ -n "${SUDO_USER:-}" ] && ! id -nG "$SUDO_USER" | tr ' ' '\n' | grep -qx docker; then
    usermod -aG docker "$SUDO_USER"
    note "added $SUDO_USER to the docker group - log out and back in for it to apply"
fi

command -v git >/dev/null 2>&1 || apt-get install -y -qq git


# --- Working copy ------------------------------------------------------------

log "Repository"
if [ -d "$APP_DIR/.git" ]; then
    note "updating $APP_DIR"
    git -C "$APP_DIR" fetch --quiet origin "$BRANCH"
    git -C "$APP_DIR" checkout --quiet "$BRANCH"
    git -C "$APP_DIR" merge --ff-only --quiet "origin/$BRANCH"
else
    note "cloning into $APP_DIR"
    mkdir -p "$(dirname "$APP_DIR")"
    git clone --quiet --branch "$BRANCH" "$REPO_URL" "$APP_DIR"
fi
note "at $(git -C "$APP_DIR" rev-parse --short HEAD)"

[ -f "$COMPOSE_FILE" ] || die "no $COMPOSE_FILE - wrong branch or wrong APP_DIR?"


# --- Secrets -----------------------------------------------------------------

log "Environment file"
if [ -f "$ENV_FILE" ]; then
    note "$ENV_FILE exists - leaving it alone"
    note "nothing here rewrites it; edit it by hand and re-run to apply changes"
else
    [ -f "$ENV_EXAMPLE" ] || die "no $ENV_EXAMPLE to copy from"
    [ -n "${SITE_DOMAIN:-}" ] || die "SITE_DOMAIN is required on the first run, e.g. SITE_DOMAIN=speks.example.com"
    # Caddy's "email" option takes an argument, so an empty one is a parse
    # error and the whole site never comes up. Better to stop here.
    [ -n "${ACME_EMAIL:-}" ] || die "ACME_EMAIL is required on the first run - Caddy will not parse an empty one"

    note "generating $ENV_FILE"
    umask 077
    cp "$ENV_EXAMPLE" "$ENV_FILE"

    # Hex, so every one of these is safe inside a URL without escaping.
    app_secret="$(openssl rand -hex 16)"
    db_password="$(openssl rand -hex 24)"
    db_root_password="$(openssl rand -hex 24)"
    db_name=gym_app
    db_user=gym

    set_var "$ENV_FILE" SITE_DOMAIN "$SITE_DOMAIN"
    set_var "$ENV_FILE" DEFAULT_URI "https://$SITE_DOMAIN"
    set_var "$ENV_FILE" ACME_EMAIL "$ACME_EMAIL"
    set_var "$ENV_FILE" APP_SECRET "$app_secret"
    set_var "$ENV_FILE" MYSQL_ROOT_PASSWORD "$db_root_password"
    set_var "$ENV_FILE" MYSQL_DATABASE "$db_name"
    set_var "$ENV_FILE" MYSQL_USER "$db_user"
    set_var "$ENV_FILE" MYSQL_PASSWORD "$db_password"
    # Written from the same three values, which is the point of writing it
    # here: by hand these four lines drift apart and the failure is a
    # connection refused an hour later.
    set_var "$ENV_FILE" DATABASE_URL \
        "mysql://$db_user:$db_password@mysql:3306/$db_name?serverVersion=8.4.3-mysql&charset=utf8mb4"

    chmod 600 "$ENV_FILE"
    note "APP_SECRET and both database passwords generated; Stripe and Anthropic left empty"
fi


# --- Firewall check ----------------------------------------------------------
#
# Not a fix, on purpose - the security list half of this lives in the Oracle
# console and cannot be done from here, so a script that silently opened the
# local half would only make a half-open port look like a whole one.

log "Firewall"
if command -v iptables >/dev/null 2>&1; then
    for port in 80 443; do
        if iptables -C INPUT -p tcp --dport "$port" -j ACCEPT >/dev/null 2>&1; then
            note "iptables accepts tcp/$port"
        else
            note "WARNING: no iptables ACCEPT rule for tcp/$port."
            note "         Oracle's images drop everything but SSH. See docs/DEPLOY.md."
        fi
    done
else
    note "iptables not found - skipping the check"
fi


# --- Build and start ---------------------------------------------------------

log "Stack"
if [ ${#BUILD_ARGS[@]} -gt 0 ]; then
    note "building - the PHP image compiles Tailwind and the asset map, so this"
    note "takes several minutes on two Ampere cores the first time"
fi
compose up -d "${BUILD_ARGS[@]}"

log "Migrations"
note "run as a release step against the running database, never in the build"
compose run --rm php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration


# --- Demo content ------------------------------------------------------------

log "Fixtures"
mkdir -p "$STATE_DIR"
SEED_MARKER="$STATE_DIR/seeded"
if [ "$DO_SEED" -eq 0 ]; then
    note "skipped - pass --seed to load demo content on a fresh database"
elif [ -f "$SEED_MARKER" ]; then
    note "already loaded on $(cat "$SEED_MARKER") - refusing to purge the database"
    note "delete $SEED_MARKER to force it"
else
    note "loading demo content once; this purges the database first"
    compose --profile seed run --rm seed
    date -Is >"$SEED_MARKER"
fi


# --- Done --------------------------------------------------------------------

log "State"
compose ps

site_domain="$(awk -F= '$1 == "SITE_DOMAIN" { print $2 }' "$ENV_FILE")"
cat <<SUMMARY

Done. The site should answer at https://${site_domain:-<SITE_DOMAIN>}

Caddy requests its certificate on the first request for that name, so the
first page load can take a few seconds and will fail outright if the name does
not yet resolve to this VM or if tcp/80 is closed anywhere between here and
the internet. docs/DEPLOY.md has the checks worth running next.
SUMMARY
