#!/bin/sh
set -eu
umask 077
test "${RAILWAY_PROJECT_ID:-}" = '33f9a938-49e3-42c5-83c7-633ee85760fa'
test "${RAILWAY_ENVIRONMENT_ID:-}" = '56b85cc3-e80b-4cb9-a0d4-b47abe8586f6'
test "${RAILWAY_PRIVATE_DOMAIN:-}" = 'mysql.railway.internal'
test -n "${MYSQL_ROOT_PASSWORD:-}"
source_db=cozy_health
sql() { MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql -u root -N -e "$1"; }
unsupported="$(sql "SELECT (SELECT COUNT(*) FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA='cozy_health')+(SELECT COUNT(*) FROM information_schema.EVENTS WHERE EVENT_SCHEMA='cozy_health')+(SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA='cozy_health')+(SELECT COUNT(*) FROM information_schema.VIEWS WHERE TABLE_SCHEMA='cozy_health')+(SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='cozy_health' AND ENGINE <> 'InnoDB')")"
test "$unsupported" = 0 || { printf 'Stored objects require an explicit isolated restore procedure.\n' >&2; exit 1; }
size="$(sql "SELECT COALESCE(SUM(DATA_LENGTH+INDEX_LENGTH),0) FROM information_schema.TABLES WHERE TABLE_SCHEMA='cozy_health'")"
test "$size" -lt 104857600 || { printf 'Database exceeds the bounded 100 MB drill limit.\n' >&2; exit 1; }
set -- $(df -Pk /var/lib/mysql | tail -n 1)
available_kb="$4"
required_kb="$((size * 4 / 1024 + 65536))"
test "$available_kb" -gt "$required_kb" || { printf 'Insufficient free space for isolated restore copies.\n' >&2; exit 1; }
token="$(openssl rand -hex 8)"
first="e08_restore_$token"
second="${first}_verify"
printf '%s\n' "$first" | grep -Eq '^e08_restore_[0-9a-f]{16}$'
work="/var/lib/mysql/cozy-recovery-$token"
mkdir -m 700 "$work"
created_first=0
created_second=0
cleanup() {
  status=$?
  if [ "$created_second" = 1 ]; then
    if ! sql "DROP DATABASE \`$second\`"; then status=1; fi
  fi
  if [ "$created_first" = 1 ]; then
    if ! sql "DROP DATABASE \`$first\`"; then status=1; fi
  fi
  rm -f "$work/snapshot.sql" "$work/tables.txt" "$work/first.txt" "$work/second.txt" "$work/schema-first.sql" "$work/schema-second.sql"
  if ! rmdir "$work"; then status=1; fi
  if [ "$status" = 0 ]; then printf 'Task-owned restore schemas and private snapshot removed.\n'; fi
  exit "$status"
}
trap cleanup EXIT
start="$(date +%s)"
sql "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA='cozy_health' AND TABLE_TYPE='BASE TABLE' ORDER BY TABLE_NAME" > "$work/tables.txt"
tables="$(wc -l < "$work/tables.txt" | tr -d ' ')"
test "$tables" -gt 10
MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysqldump -u root --single-transaction --quick --skip-lock-tables --no-tablespaces --set-gtid-purged=OFF --skip-comments --skip-routines --skip-events --skip-triggers --hex-blob "$source_db" > "$work/snapshot.sql"
if grep -Eq '^(USE |CREATE DATABASE|DROP DATABASE)' "$work/snapshot.sql"; then printf 'Unexpected database-switch statement in snapshot.\n' >&2; exit 1; fi
sql "CREATE DATABASE \`$first\`"
created_first=1
sql "CREATE DATABASE \`$second\`"
created_second=1
MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql -u root "$first" < "$work/snapshot.sql"
MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql -u root "$second" < "$work/snapshot.sql"
MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysqldump -u root --no-data --skip-comments --no-tablespaces --set-gtid-purged=OFF "$first" > "$work/schema-first.sql"
MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysqldump -u root --no-data --skip-comments --no-tablespaces --set-gtid-purged=OFF "$second" > "$work/schema-second.sql"
test "$(openssl dgst -sha256 -r "$work/schema-first.sql" | sed 's/ .*//')" = "$(openssl dgst -sha256 -r "$work/schema-second.sql" | sed 's/ .*//')" || { printf 'Restored schemas differ.\n' >&2; exit 1; }
total_rows=0
while IFS= read -r table; do
  printf '%s\n' "$table" | grep -Eq '^[A-Za-z0-9_]+$'
  first_rows="$(sql "SELECT COUNT(*) FROM \`$first\`.\`$table\`")"
  second_rows="$(sql "SELECT COUNT(*) FROM \`$second\`.\`$table\`")"
  test "$first_rows" = "$second_rows" || { printf 'Restored row counts differ.\n' >&2; exit 1; }
  total_rows="$((total_rows + first_rows))"
  sql "CHECKSUM TABLE \`$first\`.\`$table\` EXTENDED" | sed "s/^$first\./snapshot./" >> "$work/first.txt"
  sql "CHECKSUM TABLE \`$second\`.\`$table\` EXTENDED" | sed "s/^$second\./snapshot./" >> "$work/second.txt"
done < "$work/tables.txt"
if grep -Eq '[[:space:]]NULL$' "$work/first.txt" "$work/second.txt"; then
  printf 'A table checksum was unavailable; restore comparison is incomplete.\n' >&2
  exit 1
fi
test "$(openssl dgst -sha256 -r "$work/first.txt" | sed 's/ .*//')" = "$(openssl dgst -sha256 -r "$work/second.txt" | sed 's/ .*//')" || { printf 'Restored data checksums differ.\n' >&2; exit 1; }
test "$total_rows" -gt 0 || { printf 'Snapshot contains no rows; populated-data recovery was not established.\n' >&2; exit 1; }
elapsed="$(( $(date +%s) - start ))"
printf 'Consistent development snapshot restored twice: %s tables, %s rows; all schemas/row counts/checksums matched; elapsed=%s seconds.\n' "$tables" "$total_rows" "$elapsed"
