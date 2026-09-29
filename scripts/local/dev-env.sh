#!/usr/bin/env bash
# =============================================================================
# Entorno local SIN Docker: PostgreSQL + Supabase Auth (GoTrue) reales.
# -----------------------------------------------------------------------------
# Alternativa recomendada si tienes Docker:  `npx supabase start`
# (usa supabase/migrations y supabase/seed.sql automáticamente).
#
# Este script sirve para desarrollo/CI sin Docker. Requisitos:
#   * PostgreSQL 15+ (initdb, pg_ctl, psql) en el PATH o en PG_BIN
#   * Binario de Supabase Auth (GoTrue):
#       https://github.com/supabase/auth/releases  → auth-vX.Y.Z-x86.tar.gz
#     descomprimido en GOTRUE_DIR (contiene ./auth y ./migrations)
#
# Uso:
#   scripts/local/dev-env.sh start   # crea cluster, aplica todo y arranca Auth
#   scripts/local/dev-env.sh reset   # borra la BD y la reconstruye desde cero
#   scripts/local/dev-env.sh stop
# =============================================================================
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
PG_BIN="${PG_BIN:-$(dirname "$(command -v pg_ctl 2>/dev/null || echo /usr/lib/postgresql/16/bin/pg_ctl)")}"
[ -x "$PG_BIN/pg_ctl" ] || PG_BIN=/usr/lib/postgresql/16/bin
PG_DATA="${PG_DATA:-/var/lib/postgresql/fc/data}"
PG_PORT="${PG_PORT:-54322}"
PG_SOCK="${PG_SOCK:-/var/run/postgresql}"
GOTRUE_DIR="${GOTRUE_DIR:-$ROOT/.local/gotrue}"
GOTRUE_PORT="${GOTRUE_PORT:-9999}"
SITE_URL="${SITE_URL:-http://127.0.0.1:8080}"
JWT_SECRET="${JWT_SECRET:-super-secret-jwt-token-with-at-least-32-characters-long}"
RUN_AS="${RUN_AS:-postgres}"

psql_db() { psql -h "$PG_SOCK" -p "$PG_PORT" -U postgres -v ON_ERROR_STOP=1 -q "$@"; }
as_pg()   { if [ "$(id -un)" = "$RUN_AS" ]; then bash -c "$1"; else su "$RUN_AS" -c "$1"; fi; }

start_pg() {
  if [ ! -d "$PG_DATA" ]; then
    mkdir -p "$(dirname "$PG_DATA")" && chown "$RUN_AS" "$(dirname "$PG_DATA")" 2>/dev/null || true
    as_pg "$PG_BIN/initdb -D '$PG_DATA' -U postgres --auth=trust -E UTF8 >/dev/null"
  fi
  mkdir -p "$PG_SOCK" && chown "$RUN_AS" "$PG_SOCK" 2>/dev/null || true
  if ! as_pg "$PG_BIN/pg_ctl -D '$PG_DATA' status" >/dev/null 2>&1; then
    as_pg "$PG_BIN/pg_ctl -D '$PG_DATA' -o '-p $PG_PORT -k $PG_SOCK' -l '$PG_DATA/../postgres.log' start" >/dev/null
    sleep 2
  fi
  echo "✔ PostgreSQL en puerto $PG_PORT"
}

gotrue_env() {
  cat <<EOF
GOTRUE_DB_DRIVER=postgres
DATABASE_URL=postgres://supabase_auth_admin:auth-admin-local-dev@127.0.0.1:$PG_PORT/postgres?sslmode=disable
GOTRUE_DB_NAMESPACE=auth
GOTRUE_DB_MIGRATIONS_PATH=$GOTRUE_DIR/migrations
GOTRUE_API_HOST=127.0.0.1
PORT=$GOTRUE_PORT
API_EXTERNAL_URL=http://127.0.0.1:$GOTRUE_PORT
GOTRUE_SITE_URL=$SITE_URL
GOTRUE_URI_ALLOW_LIST=$SITE_URL/**
GOTRUE_JWT_SECRET=$JWT_SECRET
GOTRUE_JWT_EXP=3600
GOTRUE_JWT_AUD=authenticated
GOTRUE_JWT_DEFAULT_GROUP_NAME=authenticated
GOTRUE_JWT_ADMIN_ROLES=service_role
GOTRUE_DISABLE_SIGNUP=false
GOTRUE_EXTERNAL_EMAIL_ENABLED=true
GOTRUE_MAILER_AUTOCONFIRM=true
GOTRUE_SMTP_HOST=127.0.0.1
GOTRUE_SMTP_PORT=${SMTP_PORT:-2525}
GOTRUE_SMTP_ADMIN_EMAIL=no-reply@firecat.test
GOTRUE_SMTP_SENDER_NAME="FIRE CAT"
GOTRUE_RATE_LIMIT_EMAIL_SENT=1000
GOTRUE_LOG_LEVEL=warn
EOF
}

build_db() {
  psql_db -f "$ROOT/supabase/local/00_supabase_shim.sql"
  (set -a; eval "$(gotrue_env)"; set +a; cd "$GOTRUE_DIR" && ./auth migrate) >/dev/null
  for f in "$ROOT"/supabase/migrations/*.sql; do
    psql_db -f "$f" >/dev/null 2>&1 || { echo "✘ Error en $f"; psql_db -f "$f"; exit 1; }
    echo "✔ $(basename "$f")"
  done
  psql_db -f "$ROOT/supabase/seed.sql" >/dev/null
  echo "✔ seed.sql"
}

start_gotrue() {
  if curl -fs "http://127.0.0.1:$GOTRUE_PORT/health" >/dev/null 2>&1; then
    echo "✔ Supabase Auth ya está activo en :$GOTRUE_PORT"; return
  fi
  (set -a; eval "$(gotrue_env)"; set +a; cd "$GOTRUE_DIR" && nohup ./auth serve < /dev/null > "$ROOT/.local/gotrue.log" 2>&1 &)
  for _ in $(seq 1 20); do
    curl -fs "http://127.0.0.1:$GOTRUE_PORT/health" >/dev/null 2>&1 && { echo "✔ Supabase Auth en :$GOTRUE_PORT"; return; }
    sleep 0.5
  done
  echo "✘ No arrancó GoTrue; revisa .local/gotrue.log"; exit 1
}

case "${1:-start}" in
  start)
    mkdir -p "$ROOT/.local"
    start_pg
    if ! psql_db -tAc "select 1 from pg_namespace where nspname='app_private'" | grep -q 1; then build_db; fi
    start_gotrue
    ;;
  reset)
    mkdir -p "$ROOT/.local"
    start_pg
    pkill -f "auth serve" 2>/dev/null || true
    psql -h "$PG_SOCK" -p "$PG_PORT" -U postgres -d template1 -q -c "drop database if exists postgres with (force)" -c "create database postgres"
    build_db
    start_gotrue
    ;;
  stop)
    pkill -f "auth serve" 2>/dev/null || true
    as_pg "$PG_BIN/pg_ctl -D '$PG_DATA' stop" >/dev/null 2>&1 || true
    echo "✔ Detenido"
    ;;
  *) echo "Uso: $0 {start|reset|stop}"; exit 1 ;;
esac
