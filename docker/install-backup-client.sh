#!/bin/sh
set -eu
apt-get update
apt-get install -y --no-install-recommends ca-certificates curl gnupg
curl --fail --silent --show-error --location https://repo.mysql.com/RPM-GPG-KEY-mysql-2025 -o /tmp/mysql-release.asc
fingerprint="$(gpg --show-keys --with-colons /tmp/mysql-release.asc | sed -n 's/^fpr:::::::::\([^:]*\):.*/\1/p' | head -n 1)"
test "$fingerprint" = BCA43417C3B485DD128EC6D4B7B3B788A8D3785C
gpg --batch --yes --dearmor -o /usr/share/keyrings/cozy-mysql.gpg /tmp/mysql-release.asc
printf '%s\n' 'deb [signed-by=/usr/share/keyrings/cozy-mysql.gpg] https://repo.mysql.com/apt/debian/ bookworm mysql-9.7-lts' > /etc/apt/sources.list.d/cozy-mysql.list
apt-get update
apt-get install -y --no-install-recommends mysql-community-client-core=9.7.4-1debian12 mysql-community-client-plugins=9.7.4-1debian12
mysqldump --version
rm -f /tmp/mysql-release.asc
rm -rf /var/lib/apt/lists/*
