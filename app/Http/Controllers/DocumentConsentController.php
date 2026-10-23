<?php

namespace App\Http\Controllers;

use App\Models\Provider;
use App\Services\DocumentConsentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class DocumentConsentController extends Controller
{
    public function configuration(Request $request, DocumentConsentService $service)
    {
        return response()->json(['consent' => $service->configuration($request->user() instanceof Provider)]);
    }

    public function index(Request $request)
    {
        $owner = $request->user() instanceof Provider ? 'provider' : 'user';
        return response()->json(['owner_id' => $request->user()->id, 'data' => DB::table($owner.'_consents')->where($owner.'_id', $request->user()->id)->orderBy('id')->get()]);
    }

    public function store(Request $request, DocumentConsentService $service)
    {
        $provider = $request->user() instanceof Provider;
        $owner = $provider ? 'provider' : 'user';
        $documents = $service->configuration($provider)['documents'];
        $data = $request->validate([
            'document_type' => ['required', Rule::in(array_keys($documents))],
            'version' => ['required', 'string', 'max:80'], 'accepted' => ['required', 'boolean'],
            'pre_acceptance' => ['sometimes', 'boolean'], 'actor_id' => ['sometimes', 'integer'],
        ]);
        abort_if(isset($data['actor_id']) && $data['actor_id'] !== $request->user()->id, 409, 'Your account changed. Review again.');
        $document = $documents[$data['document_type']];
        if ($data['version'] !== $document['version']) throw ValidationException::withMessages(['version' => ['Review the current document version.']]);
        $pre = $data['pre_acceptance'] ?? false;
        $marketing = $data['document_type'] === 'marketing';
        if ($pre && ($data['accepted'] || $document['published'] || $marketing)) throw ValidationException::withMessages(['pre_acceptance' => ['Only unpublished, non-marketing documents allow draft tracking.']]);
        if ($data['accepted'] && (! $document['published'] || blank($document['content']) || str_starts_with($document['version'], 'draft-'))) throw ValidationException::withMessages(['accepted' => ['This document is not published yet.']]);
        if (! $data['accepted'] && ! $pre && ! $marketing && $data['document_type'] !== 'health_data') throw ValidationException::withMessages(['accepted' => ['Use draft tracking for unpublished documents.']]);
        $status = $pre ? 'pre_acceptance' : ($data['accepted'] ? 'accepted' : 'withdrawn');
        $record = DB::transaction(function () use ($request, $owner, $data, $status) {
            $request->user()->newQuery()->whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            $key = [$owner.'_id' => $request->user()->id, 'document_type' => $data['document_type'], 'version' => $data['version']];
            $existing = DB::table($owner.'_consents')->where($key)->first();
            if ($existing?->status === $status) return $existing;
            $metadata = ['ip_address' => $request->ip(), 'user_agent' => mb_substr($request->userAgent() ?? '', 0, 500)];
            DB::table($owner.'_consents')->updateOrInsert($key, [...$metadata, 'status' => $status, 'accepted_at' => $status === 'accepted' ? now() : null, 'recorded_at' => now()]);
            DB::table($owner.'_consent_events')->insert([...$key, ...$metadata, 'status' => $status, 'created_at' => now()]);
            return DB::table($owner.'_consents')->where($key)->first();
        });
        return response()->json(['owner_id' => $request->user()->id, 'data' => $record]);
    }
}
