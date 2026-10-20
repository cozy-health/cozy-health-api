# Database Configuration

## Railway service env vars (production)

Set these in Railway dashboard -> backend service -> Variables. Reference the
existing MySQL service's variables; replace `MySQL` if its service name differs.

```dotenv
DB_CONNECTION=mysql
DB_HOST=${{MySQL.MYSQLHOST}}
DB_PORT=${{MySQL.MYSQLPORT}}
DB_DATABASE=${{MySQL.MYSQLDATABASE}}
DB_USERNAME=${{MySQL.MYSQLUSER}}
DB_PASSWORD=${{MySQL.MYSQLPASSWORD}}
DB_CONNECTION_TIMEOUT=5
```

| Variable | Explanation |
|---|---|
| DB_CONNECTION | Connection name; retain mysql in production |
| DB_HOST | Existing Railway-provided host, preferably private network |
| DB_PORT | Existing Railway-provided port |
| DB_DATABASE | Existing database name |
| DB_USERNAME | Existing database user |
| DB_PASSWORD | Existing password; keep secret |
| DB_URL | Optional connection URL; leave unset with individual credentials. A populated URL can override them |
| DB_SOCKET | Optional Unix socket; leave unset for Railway TCP |
| DB_CONNECTION_TIMEOUT | PDO MySQL connection timeout in seconds; default 5 |
| DB_POOL_MIN | Reserved proposed minimum pool size: 2; ignored today |
| DB_POOL_MAX | Reserved proposed maximum pool size: 10; ignored today |
| DB_POOL_WAIT_TIMEOUT | Reserved proposed pool checkout timeout: 5 seconds; ignored today |
| DB_QUERY_TIMEOUT | Reserved proposed query cap: 30 seconds; ignored today |

The requested future settings are listed below. Setting these in Railway now
does not enable pooling or enforce a query timeout; do not rely on them:

```dotenv
DB_POOL_MIN=2
DB_POOL_MAX=10
DB_POOL_WAIT_TIMEOUT=5
DB_QUERY_TIMEOUT=30
```

Live Railway variables have not been changed or verified. Deploy the updated
config for DB_CONNECTION_TIMEOUT to take effect. Clear/rebuild the Laravel
config cache after applying environment changes.

## Local dev

Copy .env.example to .env and set the six original DB_* variables for local
MySQL. Leave DB_URL and DB_SOCKET empty for TCP. The connection timeout is 5.

Phase 2.1 used disposable Docker MySQL; the Phase 2.2 audit records MySQL
8.0.46 on 127.0.0.1:3306. An equivalent local option is:

```shell
docker run --name cozy-health-mysql-local -e MYSQL_ROOT_PASSWORD=local-only-password -e MYSQL_DATABASE=cozy_health -e MYSQL_USER=cozy_health -e MYSQL_PASSWORD=local-app-password -p 127.0.0.1:3306:3306 -d mysql:8.0.46
```

Wait for MySQL readiness. Use DB_CONNECTION=mysql, DB_HOST=127.0.0.1,
DB_PORT=3306, DB_DATABASE=cozy_health, DB_USERNAME=cozy_health and
DB_PASSWORD=local-app-password. These are local example passwords only.
If 3306 is occupied, change the published host port and DB_PORT. Run
`php artisan migrate` against the intended local database.

## Pooling notes

The installed Laravel MySQL connector has no bounded pool or DB_POOL_* support.
The original config supplied no PDO options. Laravel defaults remain natural
column case, exception error mode, natural null handling, stringify fetches
false and emulate prepares false.

Railway selects Dockerfile, which uses php:8.2-cli and starts
`php artisan serve`. The alternative nixpacks.toml also starts artisan serve.
Neither uses PHP-FPM, Swoole or Octane; composer.json has no Octane dependency.
This describes repository configuration; dashboard overrides were not inspected.
The driver, host and runtime are unchanged.

PDO::ATTR_PERSISTENT is explicitly false. Persistence reuses connections within
a process; it is not a shared bounded pool. If moving to Octane, evaluate worker
lifecycle, transaction/session cleanup and concurrency before enabling
PDO::ATTR_PERSISTENT. Octane alone does not require persistent PDO.

If moving to PHP-FPM, pm.max_children limits concurrent web workers and helps
budget database connections. Include replicas, queue/scheduler workers and
multiple connection names in the total; DB_POOL_MAX does not limit them.
Keep headroom below MySQL max_connections. FPM settings do not apply to the
current artisan serve runtime.

## Timeouts

DB_CONNECTION_TIMEOUT is wired to PDO::ATTR_TIMEOUT with a five-second default
for fail-fast MySQL connection attempts. It does not cap query execution. DNS
delays and Laravel retries can make total failure time longer.

DB_QUERY_TIMEOUT=30 expresses the desired long-query cap only. No current code
reads it. PDO MySQL has no general statement timeout via ATTR_TIMEOUT. A query
cap needs a separate reviewed implementation. MySQL max_execution_time uses
milliseconds and applies to read-only SELECTs, not all queries. MySQL
wait_timeout governs idle sessions, not query execution or pool checkout.

## Sources

- Installed Laravel Connector.php and MySqlConnector.php in vendor/laravel/framework/src/Illuminate/Database/Connectors.
- [Railway MySQL variables](https://docs.railway.com/databases/mysql).
- [PDO connection lifecycle and persistence](https://www.php.net/manual/en/pdo.connections.php).
- [PDO timeout attribute](https://www.php.net/manual/en/pdo.setattribute.php).
- [MySQL max_execution_time](https://dev.mysql.com/doc/refman/8.0/en/server-system-variables.html#sysvar_max_execution_time).
