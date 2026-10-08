<?php
namespace App\Http\Requests;
class NotificationPreferencesRequest extends ApiFormRequest { public function rules(): array { return [
            'notifications_master' => 'sometimes|boolean',
            'daily_checkin_enabled' => 'sometimes|boolean',
            'daily_checkin_time' => 'sometimes|string|max:10',
            'journal_reminder_enabled' => 'sometimes|boolean',
            'comments_notifications' => 'sometimes|boolean',
            'achievements_notifications' => 'sometimes|boolean',
            'marketing_notifications' => 'sometimes|boolean',
        ]; } }
