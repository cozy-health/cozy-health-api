<?php

namespace App\Http\Controllers\Api\V1;

use App\Services\ProfileAvatarService;
use Illuminate\Http\Request;

class ProfileAvatarController extends ApiController
{
    private function checkOwner(Request $request): void
    {
        $request->validate(['expected_user_id' => ['sometimes', 'nullable', 'integer', 'min:1']]);
        if ($request->filled('expected_user_id')) {
            abort_unless((string) $request->input('expected_user_id') === (string) $request->user()->id, 409, 'Your account changed. Please reopen your profile.');
        }
    }

    public function show(Request $request)
    {
        $profile = $request->user()->profile()->first();

        return $this->success(['avatar_url' => $profile?->avatar_url, 'avatar_revision' => (int) ($profile?->avatar_revision ?? 0)]);
    }

    public function upload(Request $request, ProfileAvatarService $service)
    {
        $this->checkOwner($request);
        $request->validate(['photo' => ['required', 'file', 'mimes:jpg,jpeg,png', 'max:5120']]);
        $service->upload($request->user(), $request->file('photo'));

        return $this->show($request);
    }

    public function preset(Request $request, ProfileAvatarService $service)
    {
        $this->checkOwner($request);
        $data = $request->validate(['preset_id' => ['required', 'string']]);
        $service->replace($request->user(), $data['preset_id']);

        return $this->show($request);
    }

    public function destroy(Request $request, ProfileAvatarService $service)
    {
        $this->checkOwner($request);
        $service->replace($request->user(), null);

        return $this->show($request);
    }
}
