<?php

namespace App\Http\Controllers\Api\V1\Provider;

use App\Http\Controllers\Controller;
use App\Models\JournalEntry;
use App\Models\MoodEntry;
use App\Models\Provider;
use App\Models\ProviderClientLink;
use App\Models\ProviderMessage;
use App\Models\ProviderNote;
use App\Models\QuizAttempt;
use App\Services\Provider\ProviderAudit;
use App\Services\QuizSharingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProviderMessageController extends Controller
{
    private function provider(Request $request): bool
    {
        return $request->user() instanceof Provider;
    }

    private function links(Request $request)
    {
        return ProviderClientLink::active()->whereNotNull('provider_id')->whereHas('user')->whereHas('provider', fn ($q) => $q->where('is_active', true))
            ->where($this->provider($request) ? 'provider_id' : 'user_id', $request->user()->id);
    }

    public function index(Request $request)
    {
        return DB::transaction(function () use ($request) {
            return response()->json($this->links($request)->with(['user', 'provider'])->lockForUpdate()->get()->map(function ($link) use ($request) {
                $messages = ProviderMessage::where('provider_client_link_id', $link->id);

                return ['id' => $link->id, 'name' => $this->provider($request) ? preg_split('/\\s+/u', trim($link->user->name))[0] : $link->provider->name,
                    'user_id' => $link->user_id, 'consent_flags' => $link->consentFlags(),
                    'unread' => (clone $messages)->where('sender', $this->provider($request) ? 'patient' : 'provider')->whereNull('read_at')->count(),
                    'last_message_at' => $messages->max('created_at')];
            }));
        });
    }

    private function query($link, string $type, bool $grant = true)
    {
        $query = match ($type) {
            'moods' => MoodEntry::withoutGlobalScope('user')->where('is_deleted', false),
            'journals' => JournalEntry::withoutGlobalScope('user')->where('is_deleted', false)->where('is_draft', false),
            'quizzes' => QuizAttempt::withoutGlobalScope('user')->where('is_deleted', false),
            'notes' => ProviderNote::where('provider_id', $link->provider_id),
        };
        if ($type === 'quizzes' && ! $grant) {
            $query->whereNotNull('validated_at');
        }
        if ($type === 'quizzes' && $grant) {
            app(QuizSharingService::class)->filterProvider($query, $link);
        }

        return $query->where('user_id', $link->user_id);
    }

    private function record($link, array $ref, bool $grant = true): array
    {
        $type = $ref['type'];
        $name = preg_split('/\\s+/u', trim($link->user->name))[0] ?: 'Patient';
        $base = ['type' => $type, 'id' => $ref['id'], 'owner' => ['name' => $name, 'initials' => mb_strtoupper(mb_substr($name, 0, 1))]];
        if (! $link->{'consent_'.$type}) {
            return $base + ['unavailable' => true];
        }
        $entry = $this->query($link, $type, $grant)->whereKey($ref['id'])->first();
        if (! $entry) {
            return $base + ['unavailable' => true];
        }
        // Preserve existing provider field restrictions for journals and voice files.
        $fields = match ($type) {
            'moods' => ['mood', 'intensity', 'note', 'created_at'],
            'journals' => ['title', 'type', 'word_count', 'created_at'],
            'quizzes' => ['quiz_title', 'quiz_slug', 'score', 'maximum_score', 'interpretation', 'completed_at'],
            'notes' => ['body', 'created_at'],
        };

        return $base + ['data' => $entry->only($fields)];
    }

    public function records(Request $request, string $linkId)
    {
        abort_if($this->provider($request), 403);
        $request->validate(['type' => 'required|in:moods,journals,quizzes,notes', 'page' => 'sometimes|integer|min:1']);

        return DB::transaction(function () use ($request, $linkId) {
            $link = $this->links($request)->whereKey($linkId)->lockForUpdate()->firstOrFail();
            $type = $request->input('type');
            abort_unless($link->{'consent_'.$type}, 403, 'Enable sharing for this category first.');
            $page = $this->query($link, $type, false)->orderByDesc('created_at')->paginate(20);
            $page->through(fn ($entry) => $this->record($link, ['type' => $type, 'id' => (string) $entry->id], false));

            return response()->json($page);
        });
    }

    public function messages(Request $request, string $linkId)
    {
        $request->validate(['before' => 'sometimes|integer|min:1', 'since' => 'sometimes|integer|min:1']);

        return DB::transaction(function () use ($request, $linkId) {
            $link = $this->links($request)->whereKey($linkId)->lockForUpdate()->firstOrFail();
            $query = ProviderMessage::where('provider_client_link_id', $link->id);
            if ($request->filled('before')) {
                $query->where('id', '<', $request->integer('before'));
            }
            $limit = $request->filled('since') ? 1000 : 50;
            if ($request->filled('since')) {
                $query->where('id', '>=', $request->integer('since'));
            }
            $rows = $query->orderByDesc('id')->limit($limit + 1)->get();
            $more = $rows->count() > $limit || ($rows->isNotEmpty() && ProviderMessage::where('provider_client_link_id', $link->id)->where('id', '<', $rows->last()->id)->exists());
            $rows = $rows->take($limit)->reverse()->values()->map(function ($message) use ($link) {
                $data = $message->toArray();
            $data['records'] = array_map(fn ($ref) => $this->record($link, $ref), $message->records ?? []);
                $audio = $message->audio_attachment_id ? \App\Models\AudioAttachment::find($message->audio_attachment_id) : null;
                $data['audio'] = $audio ? ['id' => $audio->id, 'duration' => $audio->duration, 'media_type' => $audio->media_type] : null;

                return $data;
            });
            if ($this->provider($request)) {
                ProviderAudit::record($request, $link, 'view', 'message');
            }

            return response()->json(['data' => $rows, 'has_more' => $more]);
        });
    }

    public function store(Request $request, string $linkId)
    {
        $data = $request->validate(['client_id' => 'required|uuid', 'content' => 'nullable|string|max:5000',
            'audio_attachment_id' => 'sometimes|nullable|uuid', 'records' => 'sometimes|array|max:10', 'records.*.type' => 'required|in:moods,journals,quizzes,notes', 'records.*.id' => 'required|string|max:100']);
        $data['content'] = trim($data['content'] ?? '');
        abort_if($data['content'] === '' && empty($data['records']) && empty($data['audio_attachment_id']), 422, 'Write a message or select a record.');
        abort_if($this->provider($request) && ! empty($data['records']), 403);

        return DB::transaction(function () use ($request, $linkId, $data) {
            $link = $this->links($request)->whereKey($linkId)->lockForUpdate()->firstOrFail();
            $sender = $this->provider($request) ? 'provider' : 'patient';
            $existing = ProviderMessage::where('provider_client_link_id', $link->id)->where('sender', $sender)->where('client_id', $data['client_id'])->first();
            if ($existing) {
                return response()->json(['id' => $existing->id]);
            }
                if (! empty($data['audio_attachment_id'])) {
                $audio = app(\App\Services\AudioAttachmentService::class)->reference($request->user(), $data['audio_attachment_id'], 'message', $link->id);
                abort_if(ProviderMessage::where('audio_attachment_id', $audio->id)->exists(), 422, 'This recording has already been sent.');
            }
            $data['records'] = array_map(fn ($ref) => ['type' => $ref['type'], 'id' => $ref['id']], $data['records'] ?? []);
            foreach ($data['records'] as $ref) {
                abort_if(isset($this->record($link, $ref, false)['unavailable']), 422, 'This record is unavailable or sharing is disabled.');
                if ($ref['type'] === 'quizzes') {
                    abort_if($request->user()->is_demo, 403);
                    $old = DB::table('quiz_shares')->where('quiz_attempt_id', $ref['id'])->where('provider_id', $link->provider_id)->first();
                    if (! $old || $old->revoked_at || (int) $old->link_id !== (int) $link->id) {
                        DB::table('quiz_shares')->updateOrInsert(
                            ['quiz_attempt_id' => $ref['id'], 'provider_id' => $link->provider_id],
                            ['user_id' => $link->user_id, 'link_id' => $link->id, 'consented_at' => now(), 'revoked_at' => null, 'read_at' => null,
                                'revision' => ($old?->revision ?? 0) + 1, 'created_at' => now(), 'updated_at' => now()]);
                    }
                }
            }
            $message = ProviderMessage::create($data + ['provider_client_link_id' => $link->id, 'sender' => $sender]);
            if ($this->provider($request)) {
                ProviderAudit::record($request, $link, 'create', 'message', (string) $message->id);
            }

            return response()->json(['id' => $message->id], 201);
        });
    }

    public function read(Request $request, string $linkId)
    {
        $request->validate(['through' => 'required|integer|min:1']);

        return DB::transaction(function () use ($request, $linkId) {
            $link = $this->links($request)->whereKey($linkId)->lockForUpdate()->firstOrFail();
            ProviderMessage::where('provider_client_link_id', $link->id)->where('sender', $this->provider($request) ? 'patient' : 'provider')
                ->where('id', '<=', $request->integer('through'))->whereNull('read_at')->update(['read_at' => now()]);

            return response()->json(['message' => 'Read.']);
        });
    }
}
