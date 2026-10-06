#!/bin/bash
# Runs Test/portability-probe.php against throwaway MariaDB + Postgres containers:
# the flags probe on db "kanboard", the v1->v2 upgrade on db "kb_up". Needs Docker.
# Usage: MARIA_IMAGE=mariadb:10.11 PG_IMAGE=postgres:16 Test/run-probe.sh
MARIA_IMAGE=${MARIA_IMAGE:-mariadb:11.4}
PG_IMAGE=${PG_IMAGE:-postgres:16}
A=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)
docker network create wip-probe >/dev/null
docker run -d --rm --name wip-probe-maria --network wip-probe -e MARIADB_ROOT_PASSWORD=pw -e MARIADB_DATABASE=kanboard "$MARIA_IMAGE" >/dev/null
docker run -d --rm --name wip-probe-pg --network wip-probe -e POSTGRES_PASSWORD=pw -e POSTGRES_DB=kanboard "$PG_IMAGE" >/dev/null
until docker exec wip-probe-maria mariadb -uroot -ppw -e 'select 1' >/dev/null 2>&1 && docker exec wip-probe-pg pg_isready -U postgres >/dev/null 2>&1; do sleep 2; done
sleep 3
docker exec wip-probe-maria mariadb -uroot -ppw -e 'select version(); create database kb_up'
docker exec wip-probe-pg psql -U postgres -tAc 'select version()' ; docker exec wip-probe-pg createdb -U postgres kb_up
run(){ # driver host user db mode
  docker run --rm --network wip-probe --entrypoint php \
    -v "$A":/var/www/app/plugins/Agents:ro \
    -e DB_DRIVER=$1 -e DB_HOSTNAME=$2 -e DB_USERNAME=$3 -e DB_PASSWORD=pw -e DB_NAME=$4 -e LOG_DRIVER=stderr \
    kanboard/kanboard:latest /var/www/app/plugins/Agents/Test/portability-probe.php $5; echo "exit=$?"; }
for spec in "mysql wip-probe-maria root" "postgres wip-probe-pg postgres"; do set -- $spec
  echo "=== $1: flags probe"; run $1 $2 $3 kanboard flags
  echo "=== $1: seed v1";     run $1 $2 $3 kb_up seed-v1
  echo "=== $1: upgrade v2";  run $1 $2 $3 kb_up upgrade
done
docker stop wip-probe-maria wip-probe-pg >/dev/null; docker network rm wip-probe >/dev/null; echo cleaned
