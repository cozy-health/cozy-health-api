#!/bin/sh
set -eu
umask 077

test "${RAILWAY_PROJECT_ID:-}" = '33f9a938-49e3-42c5-83c7-633ee85760fa'
test "${RAILWAY_ENVIRONMENT_ID:-}" = '56b85cc3-e80b-4cb9-a0d4-b47abe8586f6'
test "${RAILWAY_PRIVATE_DOMAIN:-}" = 'mysql.railway.internal'
test -n "${MYSQL_ROOT_PASSWORD:-}"

data=/var/lib/mysql
task="$data/cozy-tls-$(date -u +%Y%m%dT%H%M%SZ)"
mkdir -m 700 "$task"
cp -p "$data/server-key.pem" "$task/original-server-key.pem"
cp -p "$data/server-cert.pem" "$task/original-server-cert.pem"
replaced=0
complete=0
sql() { MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql -u root -N -e "$1"; }
rollback() {
  status=$?
  if [ "$replaced" = 1 ] && [ "$complete" = 0 ]; then
    cp -p "$task/original-server-key.pem" "$data/server-key.pem"
    cp -p "$task/original-server-cert.pem" "$data/server-cert.pem"
    if sql 'ALTER INSTANCE RELOAD TLS'; then
      printf 'Original certificate files and TLS context restored after failed validation.\n' >&2
    else
      printf 'Original files restored, but TLS reload failed; manual recovery is required.\n' >&2
    fi
  fi
  if [ "$status" != 0 ] && [ -f "$task/key-generation.log" ]; then
    tail -n 8 "$task/key-generation.log" >&2
  fi
  exit "$status"
}
trap rollback EXIT

openssl genpkey -algorithm RSA -pkeyopt rsa_keygen_bits:3072 -out "$task/server-key.pem" 2>"$task/key-generation.log"
openssl req -new -key "$task/server-key.pem" -out "$task/server.csr" -subj '/CN=mysql.railway.internal/O=Cozy Health Development'
printf '%s\n' 'basicConstraints=critical,CA:FALSE' 'keyUsage=critical,digitalSignature,keyEncipherment' 'extendedKeyUsage=serverAuth' 'subjectAltName=DNS:mysql.railway.internal' > "$task/server.ext"
serial="$(openssl rand -hex 16)"
openssl x509 -req -in "$task/server.csr" -CA "$data/ca.pem" -CAkey "$data/ca-key.pem" -set_serial "0x$serial" -days 365 -sha256 -extfile "$task/server.ext" -out "$task/server-cert.pem"
openssl verify -CAfile "$data/ca.pem" -verify_hostname mysql.railway.internal "$task/server-cert.pem"

install -o mysql -g mysql -m 600 "$task/server-key.pem" "$data/server-key.pem.next"
install -o mysql -g mysql -m 644 "$task/server-cert.pem" "$data/server-cert.pem.next"
replaced=1
mv "$data/server-key.pem.next" "$data/server-key.pem"
mv "$data/server-cert.pem.next" "$data/server-cert.pem"
sql 'ALTER INSTANCE RELOAD TLS'
MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql --host=mysql.railway.internal --ssl-mode=VERIFY_IDENTITY --ssl-ca="$data/ca.pem" -u root -N -e "SHOW SESSION STATUS LIKE 'Ssl_cipher'; SHOW SESSION STATUS LIKE 'Ssl_version';"
complete=1
printf 'Development MySQL certificate installed; private backup retained at %s\n' "$task"
openssl x509 -in "$data/server-cert.pem" -noout -subject -dates -ext subjectAltName
