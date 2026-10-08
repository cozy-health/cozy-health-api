# Queue worker

Use `QUEUE_CONNECTION=database` for the API and a second Railway service built from the same repository. Share the database, cache, encryption key and mail settings. Start the worker with:

```sh
php artisan queue:work --queue=high,default,email,notifications,moderation,content --tries=3 --timeout=90
```

The database connection's `retry_after` must exceed the worker timeout (use 120 seconds). `high` takes priority, followed by general work and email. Reserved queues support future notifications, moderation and content refresh; those features do not dispatch jobs yet.

Run migrations from the deployment service, not concurrently from workers. Restart workers on deployment (`php artisan queue:restart`). Monitor failed jobs with `queue:failed`, retry with `queue:retry`, and monitor `/up` for queue backlog. `/up` checks storage/backlog, not worker liveness; supervise the worker service in Railway.

Authenticated administrators can POST `/api/v1/admin/queue/test-email` with `{"email":"your-test-address"}` to dispatch `SendTestEmail`. The endpoint is throttled and unavailable to ordinary users.
