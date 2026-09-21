# Cozy Health API

A standalone Laravel 12 REST API built to match the current Cozy Health Flutter client. The React administration application is intentionally excluded and can be built later against separate admin endpoints.

## Requirements

- PHP 8.3+
- Composer 2
- MySQL 8+

## Installation

```bash
cp .env.example .env
composer install
php artisan key:generate
php artisan migrate --seed
php artisan serve
```

Base URL: `http://127.0.0.1:8000/api/v1`

Run tests:

```bash
php artisan test
```

## Documentation

- `docs/API_REFERENCE.md`
- `docs/DATABASE_SCHEMA.md`
- `docs/FLUTTER_INTEGRATION.md`
- `openapi.yaml`
- `postman/Cozy-Health-API.postman_collection.json`
- `postman/Local.postman_environment.json`

## Important

Provider verification returns `development_code` only in a local environment. Connect a mail or SMS provider before production. Subscription activation records an internal subscription; it does not charge a payment method.
