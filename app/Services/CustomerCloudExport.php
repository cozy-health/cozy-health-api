<?php

namespace App\Services;

use App\Models\AudioAttachment;
use App\Models\EmergencyEmailContact;
use App\Models\NextOfKinContact;
use App\Models\PrivacyRequest;
use App\Models\ProviderMessage;
use App\Models\StudentApplication;
use App\Models\SupportMessage;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/** Explicit inventory. Staff-only/provider-internal records are never bulk-exported. */
class CustomerCloudExport
{
    public const TABLES = [
        'user_profiles', 'mood_entries', 'journal_entries', 'journals', 'chat_conversations', 'chat_messages',
        'safety_plans', 'quiz_attempts', 'quiz_sessions', 'quiz_results', 'saved_articles', 'app_notifications',
        'next_of_kin_contacts', 'user_affirmation_assignments', 'user_affirmation_reads', 'user_achievement_awards',
        'streak_accounts', 'streak_completions', 'subscription_statuses', 'subscriptions', 'subscription_events',
        'challenge_enrollments', 'challenge_completions', 'community_posts', 'community_comments',
        'community_memberships', 'community_reactions', 'community_blocks', 'provider_client_links',
        'journal_shares', 'quiz_shares', 'quiz_friend_shares', 'quiz_invitations', 'consent_records', 'user_consents', 'user_consent_events',
        'student_applications', 'student_email_claims', 'store_accounts', 'store_purchases',
        'emergency_email_contacts', 'emergency_email_alerts', 'support_reports', 'privacy_requests',
    ];

    private function safe(array $row): array
    {
        foreach (array_keys($row) as $key) {
            if (preg_match('/password|token|secret|hash|fingerprint|proof|receipt|purchase_reference|payload|storage_key|object_key|file_path|evidence_path|admin_notes|review_note|ip_address|device_id|queue_key|message_key/i', $key)) {
                unset($row[$key]);
            }
        }

        return $row;
    }

    public function archive(User $user): string
    {
        $archive = tempnam(sys_get_temp_dir(), 'cozy-export-');
        if ($archive === false) {
            throw new \RuntimeException('Export storage unavailable.');
        }
        @chmod($archive, 0600);
        $temporary = [];
        $zip = new \ZipArchive;
        try {
            if ($zip->open($archive, \ZipArchive::OVERWRITE) !== true) {
                throw new \RuntimeException('Export unavailable.');
            }
            $zip->addFromString('account.json', json_encode($this->safe($user->attributesToArray()), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
            $included = [];
            foreach (self::TABLES as $table) {
                if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'user_id')) {
                    continue;
                }
                $path = tempnam(sys_get_temp_dir(), 'cozy-data-');
                if ($path === false) {
                    throw new \RuntimeException('Export storage unavailable.');
                }
                @chmod($path, 0600);
                $temporary[] = $path;
                $stream = fopen($path, 'wb');
                if (! $stream) {
                    throw new \RuntimeException('Export storage unavailable.');
                }
                try {
                    fwrite($stream, '[');
                    $first = true;
                    foreach (DB::table($table)->where('user_id', $user->id)->cursor() as $record) {
                        $row = match ($table) {
                            'next_of_kin_contacts' => (new NextOfKinContact)->newFromBuilder($record)->attributesToArray(),
                            'student_applications' => (new StudentApplication)->newFromBuilder($record)->attributesToArray(),
                            'emergency_email_contacts' => (new EmergencyEmailContact)->newFromBuilder($record)->attributesToArray(),
                            'privacy_requests' => (new PrivacyRequest)->newFromBuilder($record)->attributesToArray(),
                            default => (array) $record,
                        };
                        fwrite($stream, ($first ? '' : ',').json_encode($this->safe($row), JSON_THROW_ON_ERROR));
                        $first = false;
                    }
                    fwrite($stream, ']');
                } finally {
                    fclose($stream);
                }
                if (! $zip->addFile($path, 'data/'.$table.'.json')) {
                    throw new \RuntimeException('Export unavailable.');
                }
                $included[] = $table;
            }
            $supportIds = DB::table('support_reports')->where('user_id', $user->id)->pluck('id');
            $zip->addFromString('data/support_messages.json', SupportMessage::whereIn('support_report_id', $supportIds)->get()->map(fn ($m) => $m->only(['id', 'support_report_id', 'author', 'body', 'created_at']))->toJson());
            $linkIds = DB::table('provider_client_links')->where('user_id', $user->id)->pluck('id');
            // Shared conversation content only; never provider notes or private reports.
            $messages = ProviderMessage::whereIn('provider_client_link_id', $linkIds)->get();
            $zip->addFromString('data/provider_messages.json', $messages->map(fn ($m) => $m->only(['id', 'provider_client_link_id', 'sender', 'content', 'records', 'created_at', 'read_at']))->toJson());
            $reports = DB::table('community_reports')->where('reporter_id', $user->id)->get(['id', 'target_type', 'reason', 'details', 'status', 'created_at', 'resolved_at']);
            $zip->addFromString('data/submitted_community_reports.json', $reports->toJson());
            $files = [];
            foreach (AudioAttachment::where('owner_type', 'patient')->where('owner_id', $user->id)->cursor() as $audio) {
                $files[] = ['disk' => $audio->disk, 'key' => $audio->storage_key, 'name' => 'audio/'.$audio->id];
            }
            $profile = $user->profile()->first();
            if ($profile?->avatar_object_key) {
                $files[] = ['disk' => config('avatars.disk'), 'key' => $profile->avatar_object_key, 'name' => 'avatar/photo'];
            }
            foreach (StudentApplication::where('user_id', $user->id)->cursor() as $application) {
                if ($application->evidence_path) {
                    $files[] = ['disk' => 'local', 'key' => $application->evidence_path, 'name' => 'student/'.$application->id];
                }
            }
            foreach (DB::table('community_posts')->where('user_id', $user->id)->whereNotNull('image_object_key')->cursor() as $post) {
                $files[] = ['disk' => config('avatars.disk'), 'key' => $post->image_object_key, 'name' => 'community/'.$post->id];
            }
            foreach ($files as $file) {
                $source = Storage::disk($file['disk'])->readStream($file['key']);
                if (! is_resource($source)) {
                    throw new \RuntimeException('An export file is unavailable. Please retry.');
                }
                $path = tempnam(sys_get_temp_dir(), 'cozy-file-');
                if ($path === false) {
                    fclose($source);
                    throw new \RuntimeException('Export storage unavailable.');
                }
                @chmod($path, 0600);
                $temporary[] = $path;
                $target = fopen($path, 'wb');
                try {
                    if (! $target || stream_copy_to_stream($source, $target) === false) {
                        throw new \RuntimeException('Export file unavailable.');
                    }
                } finally {
                    fclose($source);
                    if (is_resource($target)) {
                        fclose($target);
                    }
                }
                if (! $zip->addFile($path, 'files/'.$file['name'])) {
                    throw new \RuntimeException('Export unavailable.');
                }
            }
            $zip->addFromString('manifest.json', json_encode(['generated_at' => now()->toIso8601String(),
                'included_domains' => [...$included, 'support_messages', 'provider_messages', 'submitted_community_reports'],
                'file_count' => count($files), 'excluded' => ['Authentication secrets and transport metadata', 'Staff-only notes and internal investigations', 'Provider-owned private notes/reports and other people\'s records', 'Deleted data and data not retained by Cozy']], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
            if (! $zip->close()) {
                throw new \RuntimeException('Export unavailable.');
            }

            return $archive;
        } catch (\Throwable $error) {
            try {
                $zip->close();
            } catch (\Throwable) {
            }
            @unlink($archive);
            throw $error;
        } finally {
            foreach ($temporary as $path) {
                @unlink($path);
            }
        }
    }
}
