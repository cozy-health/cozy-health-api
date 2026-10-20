# Phase 10 request validation audit

Read methods without client inputs need no payload validation. Route IDs are constrained by owner-scoped lookups; missing resources return 404. Existing placeholder write methods ignore the input and remain unchanged for the dedicated endpoint phase.

| Controller | Methods | Before | After / remaining behavior |
|---|---|---|---|
| Auth | register, login | FormRequests | Shared friendly messages; role cannot be assigned by registration |
| Auth | googleSignIn, appleSignIn | Inline rules | GoogleSignInRequest, AppleSignInRequest |
| Auth | logout | No payload | No payload consumed |
| PasswordReset | forgotPassword, resetPassword | Inline rules | ForgotPasswordRequest, ResetPasswordRequest |
| Profile | update | UpdateProfileRequest | Shared friendly messages |
| Profile | show | No payload | Authenticated user only |
| UserProfile | update, updatePreferences, updateNotificationPreferences | Inline rules | UserProfileRequest, UserDisplayPreferencesRequest, NotificationPreferencesRequest |
| UserProfile | show | No payload | Authenticated user only |
| MoodEntry | store | Unvalidated all() | MoodEntryRequest; only validated fields persisted |
| MoodEntry | index | Unbounded pagination | PaginationRequest, 1–100 items |
| MoodEntry | show, update, destroy | Lookup / placeholder | Owner scope / no input persisted by placeholder writes |
| JournalEntry | store, update | Inline rules | JournalEntryRequest |
| JournalEntry | index, show, destroy | Unbounded pagination / lookup | PaginationRequest; owner-scoped lookup/deletion |
| Journal (legacy) | store, update | FormRequests | Shared friendly messages |
| Journal (legacy) | index, destroy | No payload | Existing user-scoped queries |
| Chat | index, messages | Unbounded index / inline message pagination | PaginationRequest |
| Chat | show, store, update, destroy | Lookup / placeholders | Existing behavior, placeholders consume no input |
| SafetyPlan | index | Unbounded pagination | PaginationRequest |
| SafetyPlan | show, store, update, destroy | Lookup / placeholders | Existing behavior; writes occur through sync |
| Quiz | index | Unbounded pagination | PaginationRequest |
| Quiz | show, store, update, destroy | Lookup / placeholders | Existing behavior; writes occur through sync |
| Content | index | Unbounded pagination | PaginationRequest |
| Content | show, store, update, destroy | Lookup / placeholders | Existing behavior; currently not routed |
| Notification | index | Unbounded pagination | PaginationRequest |
| Notification | show, markAsRead, markAllAsRead | No payload | Authenticated owner scope |
| Notification | store, update, destroy | Placeholders | No input persisted |
| Subscription | index | Unbounded pagination | PaginationRequest |
| Subscription | show, store, update, destroy | Lookup / placeholders | Existing behavior |
| Provider | requestCode, verify | FormRequests | Shared friendly messages |
| Provider | show, destroy | No payload | Existing authenticated owner scope |
| Support | store | SupportReportRequest | Shared friendly messages |
| Support | index | No payload | Existing authenticated owner scope |
| Sync | batch | Inline envelope + per-operation checks | SyncBatchRequest; per-operation type/action/ownership checks and preferences service validation retained |
| Insights | weekly, triggers, sleepMood | Inline query rules | WeeklyInsightsRequest, DailyInsightsRequest |
| Dashboard, Affirmation | show / today | No client filters | Authenticated read-only queries |
| Account | destroy | No payload | Authenticated permanent-erasure path; demo protected |
| Health | invoke | No payload | Bounded dependency checks, no sensitive details |

## Soft deletion

User, MoodEntry, JournalEntry, ChatMessage, ChatConversation, AppNotification, QuizAttempt, SavedArticle and SafetyPlan now use Laravel SoftDeletes with deleted_at and its default global scope. Laravel's normal reads exclude deleted rows. Explicit account deletion uses forceDelete; foreign-key cascades erase even previously soft-deleted user-owned rows. Tokens and reset records still hard-delete. `is_deleted` is a legacy mobile tombstone and is not a replacement for deleted_at.
