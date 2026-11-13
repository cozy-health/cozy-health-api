#!/bin/sh
set -eu

mode="${1:-full}"
case "$mode" in full|configuration-only) ;; *) exit 2 ;; esac
helper="${FPM_CONFIG_HELPER:-/usr/local/bin/configure-development-fpm.sh}"
work="$(mktemp -d)"
cleanup() {
    rm -f "$work/override.conf" "$work/fpm.conf" "$work/parser.log" "$work/rejection.log"
    rmdir "$work"
}
trap cleanup EXIT HUP INT TERM
destination="$work/override.conf"

export RAILWAY_PROJECT_ID=33f9a938-49e3-42c5-83c7-633ee85760fa
export RAILWAY_ENVIRONMENT_ID=56b85cc3-e80b-4cb9-a0d4-b47abe8586f6
export APP_URL=https://api-dev.cozyhealth.org
unset DEVELOPMENT_FPM_ONDEMAND
sh "$helper" "$destination"
test ! -e "$destination"

export DEVELOPMENT_FPM_ONDEMAND=true
sh "$helper" "$destination"
grep -qx 'pm = ondemand' "$destination"
grep -qx 'pm.process_idle_timeout = 10s' "$destination"

if [ "$mode" = full ]; then
    printf '[global]\ninclude = /usr/local/etc/php-fpm.d/*.conf\ninclude = %s\n' "$destination" > "$work/fpm.conf"
    php-fpm -tt -y "$work/fpm.conf" > "$work/parser.log" 2>&1
    grep -q 'pm = ondemand' "$work/parser.log"
    grep -q 'pm.max_children = 10' "$work/parser.log"
    grep -q 'pm.max_requests = 500' "$work/parser.log"
    grep -q 'pm.process_idle_timeout = 10' "$work/parser.log"
fi

export DEVELOPMENT_FPM_ONDEMAND=false
sh "$helper" "$destination"
test ! -e "$destination"
if [ "$mode" = full ]; then
    php-fpm -tt > "$work/parser.log" 2>&1
    grep -q 'pm = dynamic' "$work/parser.log"
    grep -q 'pm.start_servers = 2' "$work/parser.log"
fi

reject() {
    if sh "$helper" "$destination" > "$work/rejection.log" 2>&1; then
        echo 'Unsafe FPM configuration was accepted.' >&2
        exit 1
    fi
    test ! -e "$destination"
}
export DEVELOPMENT_FPM_ONDEMAND=true
RAILWAY_ENVIRONMENT_ID=9ffd3fd1-ae52-4cc7-903e-35b529a151ae reject
RAILWAY_PROJECT_ID=another-project reject
APP_URL=https://api.cozyhealth.org reject
APP_URL= reject
DEVELOPMENT_FPM_ONDEMAND=invalid reject

echo "Development FPM checks passed ($mode): default, on-demand, stale override removal and five unsafe-configuration rejections."
