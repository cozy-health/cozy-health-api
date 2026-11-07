# Development database TLS

This procedure applies only to project `33f9a938-49e3-42c5-83c7-633ee85760fa`,
environment `56b85cc3-e80b-4cb9-a0d4-b47abe8586f6`, and its MySQL service
`9fff2b71-9ceb-4686-ae1f-6f7ba43fb78c`. Production is excluded.

## Trust and custody

`resources/security/development-mysql-ca.pem` is the public CA certificate copied
from that development database through authenticated SSH. It contains no private
key. The CA private key and server private key remain on the database volume with
mode 600. Certificate renewal retains original key/certificate files in a mode-700
task directory on that same private volume; never copy those keys into Git or logs.

The server certificate identifies `mysql.railway.internal` in its subject and DNS
SAN. The 8 November 2026 development repair was validated with MySQL
`VERIFY_IDENTITY` and independent strict PHP connections from API and worker:
TLS 1.3 negotiated, and an unrelated CA was rejected. The installed leaf expires
7 November 2027 at 23:04:01 UTC. The public CA expires 18 September 2036.

## Client rollout

First deploy the public CA file to API and worker. Then set these variables on
both development service instances, retaining the existing connection details:

```dotenv
MYSQL_SSL_CA=/var/www/html/resources/security/development-mysql-ca.pem
DB_TLS_ENFORCE=true
DB_REQUIRE_TLS=true
```

Verify the effective connector requires TLS, its CA is readable and certificate
verification is true. Query `SHOW SESSION STATUS LIKE 'Ssl_cipher'` and
`SHOW SESSION STATUS LIKE 'Ssl_version'` through each service's actual application
connection. An empty cipher is a failure. Do not substitute private networking for
verified database TLS or disable certificate checks to obtain a pass.

## Renewal

Run `scripts/renew-development-mysql-tls.sh` only inside the identified development
database service, using its existing credential environment. It checks the project,
environment and private hostname; backs up the current files privately; generates
a new 3072-bit server key and a 365-day hostname-valid certificate; reloads the TLS
context without restarting MySQL; and verifies a TLS connection with identity
checking. On failed validation it restores the original files and TLS context.

The CA remains unchanged during ordinary leaf renewal, so application trust need
not change. Review expiry at least 30 days before the installed leaf expires.
Named operational ownership, monitoring coverage and long-term backup/key retention
remain E10 decisions; this procedure does not invent those approvals. Before a CA
rotation, deploy overlapping trust and prove both services can connect before
removing the old CA. Preserve existing encrypted records and job keys.
