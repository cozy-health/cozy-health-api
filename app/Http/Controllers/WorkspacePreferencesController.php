<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class WorkspacePreferencesController extends Controller
{
    private const DEFAULTS = ['theme' => 'system', 'accent' => 'blue', 'layout' => 'overview',
        'density' => 'comfortable', 'sidebar_collapsed' => false, 'tour_state' => 'new',
        'tour_step' => 0, 'tour_version' => 1];

    public function adminShow(Request $request) { return $this->read('user_id', $request->user()->id); }
    public function providerShow(Request $request) { return $this->read('provider_id', $request->user('provider')->id); }
    public function adminUpdate(Request $request) { return $this->write($request, 'user_id', $request->user()->id); }
    public function providerUpdate(Request $request) { return $this->write($request, 'provider_id', $request->user('provider')->id); }

    private function read(string $column, int $id)
    {
        $row = DB::table('workspace_preferences')->where($column, $id)->first();
        $saved = $row ? json_decode($row->preferences, true, flags: JSON_THROW_ON_ERROR) : [];
        return response()->json(['data' => array_replace(self::DEFAULTS, $saved)])
            ->header('Cache-Control', 'private, no-store');
    }

    private function write(Request $request, string $column, int $id)
    {
        // Identity and storage keys are always selected by the authenticated route.
        $request->validate(['user_id' => 'prohibited', 'provider_id' => 'prohibited', 'id' => 'prohibited']);
        $patch = $request->validate([
            'theme' => ['sometimes', Rule::in(['system', 'light', 'dark'])],
            'accent' => ['sometimes', Rule::in(['blue', 'violet', 'teal'])],
            'layout' => ['sometimes', Rule::in(['overview', 'focus'])],
            'density' => ['sometimes', Rule::in(['comfortable', 'compact'])],
            'sidebar_collapsed' => 'sometimes|boolean',
            'tour_state' => ['sometimes', Rule::in(['new', 'dismissed', 'completed'])],
            'tour_step' => 'sometimes|integer|min:0|max:3',
            'tour_version' => 'sometimes|integer|in:1',
        ]);
        abort_if($patch === [], 422, 'Choose a workspace preference to update.');
        DB::transaction(function () use ($column, $id, $patch) {
            DB::table('workspace_preferences')->insertOrIgnore([
                $column => $id, 'preferences' => json_encode(self::DEFAULTS),
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $row = DB::table('workspace_preferences')->where($column, $id)->lockForUpdate()->first();
            $saved = json_decode($row->preferences, true, flags: JSON_THROW_ON_ERROR);
            DB::table('workspace_preferences')->where($column, $id)->update([
                'preferences' => json_encode(array_replace($saved, $patch), JSON_THROW_ON_ERROR),
                'updated_at' => now(),
            ]);
        }, 3);
        return $this->read($column, $id);
    }
}
