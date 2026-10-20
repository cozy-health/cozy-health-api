# Privacy-safe analytics

`AnalyticsService::track` is the only event writer. It rejects unknown names and **all properties**, even for allowed events. Storage contains only id, event and created_at. No user_id, names, email addresses, IPs, mood values, journal bodies, messages, scores or crisis interaction details are accepted. Collection defaults off and is controlled by `flags:set analytics true`.

## Enforced allowlist

| Event | Emission |
|---|---|
| user_registered | Successful registration or first social-account creation |
| user_logged_in | Successful password/social login, anonymous aggregate row |
| mood_logged | New mood record through API or sync; updates do not count |
| journal_created | New journal record through API or sync; updates do not count |
| provider_linked | Successful provider-code verification |
| quiz_completed | New quiz attempt through sync |
| app_opened | Reserved, deliberately not instrumented |
| crisis_hub_opened | Reserved, deliberately not instrumented |

The last two events cannot be reliably observed server-side. No mobile emissions, config-poll counts, or invented estimates are used. Placeholder quiz endpoints do not emit completion events until they actually persist data. Draft creation counts as journal creation; an update/publish does not create a second event. Queued sync retries do not duplicate events for an existing record.

## Aggregates and limitations

Administrator GET `/admin/metrics/analytics?range=7d|30d|90d` (also `/api/v1/admin/metrics/analytics`) returns only aggregate counts. Counts below five are rendered as `<5`; event counts are also suppressed for a population below five. Because events contain no identifiers, distinct participants for an individual event cannot be calculated: the threshold is an aggregate count/population safeguard, not proof of five distinct event participants. No per-user or event-property segmentation is exposed.

Active users are distinct active-user Sanctum token owners with a recent last_used_at (or newly created token) in the selected window. Expired tokens and soft-deleted users are excluded. Logout removes tokens, so this is a conservative session-based measure, not a permanent activity history. New signups use users.created_at. Neither query attaches identities to analytics events.

Retain events at most fourteen months. `analytics:prune --older-than=14months` runs daily via Laravel's scheduler. Railway must run `php artisan schedule:work` in a supervised service, or invoke `schedule:run` every minute. Pruning works even when collection is disabled. Anonymous aggregate events are not linked back to deleted accounts.

Future contributors: extend the allowlist only after privacy review; never add content or identity properties. Do not add client emission endpoints or bypass AnalyticsService.
