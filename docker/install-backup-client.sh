#!/bin/sh
set -eu
export DEBIAN_FRONTEND=noninteractive

# Oracle's rolling APT index can retire an older version while its release files
# remain available. Pin the original release artifacts and their SHA-256 hashes.
# The plugins package also requires server-core; pin that dependency as well.
# This installs core binaries only, not the mysql-community-server daemon package.
if [ "$(dpkg --print-architecture)" != amd64 ]; then
    echo "The pinned MySQL backup client currently requires amd64." >&2
    exit 1
fi
apt-get update
apt-get install -y --no-install-recommends ca-certificates curl
version=9.7.4-1debian12
base=https://repo.mysql.com/apt/debian/pool/mysql-9.7-lts/m/mysql-community
work_dir="$(mktemp -d /tmp/cozy-mysql-client.XXXXXX)"
chmod 755 "$work_dir"
trap 'rm -f "$work_dir"/*.deb; rmdir "$work_dir"' EXIT
for package in mysql-community-client-core mysql-community-client-plugins mysql-community-server-core; do
    curl --fail --silent --show-error --location --retry 3 --connect-timeout 15 --max-time 180 \
        --proto '=https' --proto-redir '=https' \
        "$base/${package}_${version}_amd64.deb" -o "$work_dir/${package}.deb"
done
printf '%s  %s\n' \
    24f222e06e28dc2e1edaac501904795c009c41c4fcadca3074a7eb6e81cd24f4 "$work_dir/mysql-community-client-core.deb" \
    404a1e5f88a85123901c466d1ad2a8a304f179e857e173bb778217ca6502c43f "$work_dir/mysql-community-client-plugins.deb" \
    cd840712980c9c35bdd204643070c28dfef21f952179ed2b1082f9f7ed9fdaeb "$work_dir/mysql-community-server-core.deb" \
    | sha256sum --check --strict
apt-get install -y --no-install-recommends \
    "$work_dir/mysql-community-client-core.deb" "$work_dir/mysql-community-client-plugins.deb" "$work_dir/mysql-community-server-core.deb"
for package in mysql-community-client-core mysql-community-client-plugins mysql-community-server-core; do
    test "$(dpkg-query --show --showformat='${Version}' "$package")" = "$version"
done
mysqldump --version
rm -rf /var/lib/apt/lists/*
