# Phase 6.5 preferences sync

Before this change SyncController mapped mood_entry, journal_entry,
chat_conversation, chat_message, safety_plan, user_profile, quiz_attempt,
saved_article, app_notification, subscription, and subscription_status to their
Eloquent models (both subscription names use SubscriptionStatus). UserProfile
already existed with a unique user_id; there was no UserPreferences model.

Migration `2026_10_08_130000_add_preferences_to_user_profiles.php` adds nullable
JSON `user_profiles.preferences`. Registering `user_preferences` dispatches to
`App\Services\UserPreferencesSync`, which validates answers, normalizes Phase 6
display labels to wire enums, and creates or updates one profile per authenticated
user. Existing profile fields and UUID are preserved. Completed time also updates
onboarding_completed_at. Client IDs identify operation acknowledgments, not
profile ownership. Delete clears the current user's answers, retaining the profile.

Seven tests in `tests/Feature/SyncTest.php` cover acceptance, updates, new profiles,
invalid fields and mixed-batch isolation, actual mobile labels, ownership, and
deletion. `php artisan test`: 254 passed, 1599 assertions (247 existing + 7 new).

Commit: `feat(sync): accept user_preferences sync type`, kept unpushed.
No mobile changes. Important: deployed Phase 6 mobile still explicitly holds this
type in its queue; a later authorized mobile change must remove that hold after
this backend change is deployed. Backend support alone does not drain that queue.
