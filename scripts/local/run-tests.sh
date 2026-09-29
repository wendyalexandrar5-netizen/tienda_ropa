#!/usr/bin/env bash
# =============================================================================
# Ejecuta toda la batería de pruebas sobre el entorno local:
#   1. Pruebas RLS / reglas de negocio en PostgreSQL   (supabase/tests/rls_test.sql)
#   2. Pruebas unitarias del verificador JWT           (tests/unit)
#   3. Pruebas de seguridad de la API y la web         (tests/security)
#   4. Prueba E2E en navegador (si Playwright existe)  (tests/e2e)
#
# Uso:  scripts/local/run-tests.sh [--reset]
#   --reset  reconstruye la BD desde las migraciones y regenera los datos demo
# Requiere el servidor PHP en BASE_URL (por defecto http://127.0.0.1:8080).
# =============================================================================
set -uo pipefail
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$ROOT"
PSQL="psql -h ${PG_SOCK:-/var/run/postgresql} -p ${PG_PORT:-54322} -U postgres -v ON_ERROR_STOP=1"
FAILED=0

if [ "${1:-}" = "--reset" ]; then
  scripts/local/dev-env.sh reset > .local/reset.log 2>&1 || { echo "✘ reset falló (ver .local/reset.log)"; exit 1; }
  php scripts/seed_demo.php || exit 1
fi

run() { echo; echo "════ $1 ════"; shift; "$@" || FAILED=$((FAILED + 1)); }
clear_limits() { $PSQL -qc "truncate app_private.rate_limits" >/dev/null; }

run "1. RLS y reglas de negocio (PostgreSQL)" bash -c "$PSQL -f supabase/tests/rls_test.sql 2>&1 | sed 's/^psql:[^ ]* NOTICE:  //' | grep -E 'OK|FALLO|ERROR|PASARON'; test \${PIPESTATUS[0]} -eq 0"
run "2. Unitarias: verificación de JWT" php tests/unit/jwt_verifier_test.php
clear_limits
run "3. Seguridad de API y web" php tests/security/api_security_test.php
clear_limits
if node -e "require('playwright')" 2>/dev/null || [ -d "$(npm root -g 2>/dev/null)/playwright" ]; then
  run "4. E2E en navegador" node tests/e2e/flujo_completo.js
else
  echo; echo "════ 4. E2E omitida (Playwright no instalado) ════"
fi
clear_limits

echo
if [ "$FAILED" -eq 0 ]; then echo "✔ Todas las suites pasaron"; else echo "✘ $FAILED suite(s) con fallos"; fi
exit "$FAILED"
