# API Reference

## Contract

Authenticated requests use `Authorization: Bearer <token>`. JSON is used for requests and responses. Validation errors use HTTP 422.

## Endpoints

### Authentication
- `POST /register`
- `POST /login`
- `POST /logout`
- `GET /me`
- `PUT /me`

### Mood
- `GET /feelings`
- `GET /feeling-expressions`
- `GET /feeling-causes`
- `GET /coping-mechanisms`
- `GET /mood-checkins`
- `GET /mood-checkins/today`
- `POST /mood-checkins`

Mood payload:
```json
{"feeling_exp_id":1,"intensity":7,"reason_ids":[1,2],"coping_mechanism_ids":[2,4],"journal":"Optional note"}
```

### Journals
- `GET /journals`
- `POST /journals`
- `PUT /journals/{id}`
- `DELETE /journals/{id}`

### Quizzes
- `GET /quizzes`
- `GET /quizzes/{id}`
- `POST /quizzes/{id}/submit`
- `GET /quiz-results`

Quiz submission:
```json
{"answers":[{"question_id":1,"option_id":2}]}
```

### Activity
- `GET /activity/overview`
- `GET /activity/mood-chart?days=7`
- `GET /activity/common-triggers`
- `GET /activity/journal-stats`
- `GET /activity/recommendations`

### Notifications
- `GET /notifications`
- `GET /notifications/unread-count`
- `POST /notifications/read-all`
- `POST /notifications/{id}/read`
- `DELETE /notifications/{id}`

### Provider
- `GET /provider`
- `POST /provider/request`
- `POST /provider/verify`
- `DELETE /provider`

### Subscription
- `GET /subscription`
- `GET /subscription/packages`
- `POST /subscription/activate`
- `POST /subscription/cancel`

### Support
- `POST /support/report-problem`
- `GET /support/my-reports`

The canonical machine-readable definitions and examples are in `openapi.yaml`.
