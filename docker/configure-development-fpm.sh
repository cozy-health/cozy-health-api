#!/bin/sh
set -eu

# The optional destination supports isolated configuration checks. Entrypoint
# always uses the fixed image-local default; no application endpoint calls this.
destination="${1:-/usr/local/etc/php-fpm.d/zzz-development.conf}"
case "${DEVELOPMENT_FPM_ONDEMAND:-false}" in
    false)
        rm -f "$destination"
        ;;
    true)
        if [ "${RAILWAY_PROJECT_ID:-}" != '33f9a938-49e3-42c5-83c7-633ee85760fa' ] \
            || [ "${RAILWAY_ENVIRONMENT_ID:-}" != '56b85cc3-e80b-4cb9-a0d4-b47abe8586f6' ] \
            || [ "${APP_URL:-}" != 'https://api-dev.cozyhealth.org' ]; then
            echo 'Development on-demand FPM requires the approved development identity.' >&2
            exit 1
        fi
        printf '[www]\npm = ondemand\npm.process_idle_timeout = 10s\n' > "$destination"
        ;;
    *)
        echo 'DEVELOPMENT_FPM_ONDEMAND must be true or false.' >&2
        exit 1
        ;;
esac
