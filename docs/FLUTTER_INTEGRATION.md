# Flutter Integration

The API matches `ApiConstants.baseUrl = http://10.0.2.2:8000/api/v1` for Android emulator development.

The login and registration response places the token at `data.token`. Store it using `flutter_secure_storage`, then attach it as a bearer token.

Client response keys intentionally match the existing screens:
- Activity: `overview`, `chart`, `triggers`, `stats`, `recommendations`
- Lookups: `feelings`, `expressions`, `causes`, `coping_mechanisms`
- Journals: `journals`
- Quizzes: `quizzes`, `quiz`, `results`
- Notifications: `notifications`, `unread_count`
- Provider: `provider`
- Subscription: `subscription`, `packages`

The global Flutter `ApiClient` should clear its token on HTTP 401.
