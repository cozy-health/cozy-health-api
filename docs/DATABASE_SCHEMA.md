# Database Schema

Core tables: `users`, `feelings`, `feeling_expressions`, `feeling_causes`, `coping_mechanisms`, `mood_checkins`, mood pivot tables, `journals`, `quizzes`, `quiz_questions`, `quiz_options`, `quiz_results`, `providers`, `provider_connections`, `subscription_packages`, `user_subscriptions`, `notifications`, and `support_reports`.

Ownership rules:
- Mood check-ins, journals, quiz results, provider connections, subscriptions, notifications, and reports belong to a user.
- Journal mutation checks ownership and returns 404 for another user's record.
- Lookup records are retained and deactivated rather than deleted after use.

Important indexes:
- User/date indexes on mood check-ins, journals, and quiz results.
- Unique slugs for lookup and package records.
- Unique user provider connection.
