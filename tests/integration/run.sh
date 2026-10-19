#!/usr/bin/env bash
# Testes de integração do módulo Pagou para Perfex.
# Usa CodeIgniter 3 (só a camada de banco), MariaDB em Docker e um servidor simulado da API Pagou.
# Nenhuma chamada vai para a API real.
set -euo pipefail
cd "$(dirname "$0")"

if [ ! -d .ci3/CodeIgniter-3.1.13 ]; then
    mkdir -p .ci3
    curl -sL -o .ci3/ci3.zip https://github.com/bcit-ci/CodeIgniter/archive/refs/tags/3.1.13.zip
    (cd .ci3 && unzip -q ci3.zip)
fi

if ! docker ps --format '{{.Names}}' | grep -q '^pagou-perfex-test-db$'; then
    docker rm -f pagou-perfex-test-db >/dev/null 2>&1 || true
    docker run -d --name pagou-perfex-test-db -e MARIADB_ROOT_PASSWORD=test -e MARIADB_DATABASE=perfex -p 33071:3306 mariadb:11 >/dev/null
    until docker exec pagou-perfex-test-db mariadb -uroot -ptest -e 'select 1' >/dev/null 2>&1; do sleep 1; done
fi

PORT="${PAGOU_MOCK_PORT:-8098}"
export PAGOU_MOCK_PORT="$PORT"
if curl -s -o /dev/null "http://127.0.0.1:$PORT/"; then
    echo "A porta $PORT já está em uso; defina PAGOU_MOCK_PORT com outra porta." >&2
    exit 2
fi
php -S "127.0.0.1:$PORT" mock_api.php >/dev/null 2>&1 &
MOCK_PID=$!
stop_mock() {
    kill "$MOCK_PID" 2>/dev/null || true
    # No Windows (Git Bash) o php.exe do mock não morre com o kill do shell
    if command -v powershell >/dev/null 2>&1; then
        powershell -NoProfile -Command "Get-CimInstance Win32_Process -Filter \"Name='php.exe'\" | Where-Object { \$_.CommandLine -like '*127.0.0.1:$PORT*' } | ForEach-Object { Stop-Process -Id \$_.ProcessId -Force }" >/dev/null 2>&1 || true
    fi
}
trap stop_mock EXIT
sleep 1

rm -f calls.json state.json
PHP="php"
php -m | grep -qi '^mysqli$' || PHP="php -d extension=mysqli"

status=0
for test in test_events test_pix_gateway test_boleto_gateway test_card test_split; do
    echo "== $test"
    $PHP "$test.php" || status=1
done
exit $status
