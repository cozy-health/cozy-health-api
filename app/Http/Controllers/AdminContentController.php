<?php
namespace App\Http\Controllers;

use App\Services\AdminAudit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AdminContentController extends Controller
{
    public const TABLES = ['moods' => 'feelings', 'expressions' => 'feeling_expressions', 'triggers' => 'feeling_causes',
        'coping' => 'coping_mechanisms', 'communities' => 'community_groups', 'challenges' => 'wellness_challenges', 'packages' => 'subscription_packages'];

    private function table(string $resource): string { abort_unless(isset(self::TABLES[$resource]), 404); return self::TABLES[$resource]; }

    public function index(Request $request, string $resource)
    {
        $table = $this->table($resource);
        $data = $request->validate(['search' => 'sometimes|string|max:100', 'page' => 'sometimes|integer|min:1', 'per_page' => 'sometimes|integer|in:10,20,50']);
        $column = $resource === 'packages' ? 'package_name' : 'name';
        $query = DB::table($table);
        if (! empty($data['search'])) $query->where($column, 'like', '%'.str_replace(['%', '_'], ['\\%', '\\_'], $data['search']).'%');
        $rows = $query->orderBy('id')->paginate($data['per_page'] ?? 10);
        $rows->through(function ($row) { foreach (['tasks', 'features'] as $field) if (isset($row->$field)) $row->$field = json_decode($row->$field, true); return $row; });
        return response()->json(['data' => $rows, ...($resource === 'expressions' ? ['lookups' => ['moods' => DB::table('feelings')->select('id', 'name')->orderBy('name')->get()]] : [])])->header('Cache-Control', 'private, no-store');
    }

    private function rules(string $resource, ?string $id): array
    {
        $table = $this->table($resource);
        $rules = [($resource === 'packages' ? 'package_name' : 'name') => 'required|string|max:100',
            'description' => 'nullable|string|max:5000', 'is_active' => 'required|boolean'];
        if ($resource !== 'communities') $rules += ['slug' => ['required', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', 'max:100', Rule::unique($table)->ignore($id)], 'sort_order' => 'required|integer|min:0|max:10000'];
        if ($resource === 'expressions') $rules += ['feeling_id' => 'required|integer|exists:feelings,id', 'emoji' => 'nullable|string|max:20'];
        if ($resource === 'moods') $rules['slug'][] = 'max:20';
        if (in_array($resource, ['triggers', 'coping'], true)) $rules += ['icon' => 'nullable|string|max:100'];
        if ($resource === 'triggers') unset($rules['description']);
        if ($resource === 'challenges') $rules += ['duration_days' => 'required|integer|min:1|max:90', 'tasks' => 'required|array|min:1|max:90', 'tasks.*' => 'required|string|max:500'];
        if ($resource === 'packages') $rules += ['price' => 'required|numeric|min:0|max:99999999', 'currency' => 'required|in:NGN', 'duration' => 'required|in:monthly,yearly,custom', 'duration_days' => 'required|integer|min:1|max:3660', 'features' => 'required|array|max:50', 'features.*' => 'required|string|max:200'];
        return $rules;
    }

    public function store(Request $request, string $resource) { return $this->save($request, $resource, null); }
    public function update(Request $request, string $resource, string $id) { return $this->save($request, $resource, $id); }
    private function save(Request $request, string $resource, ?string $id)
    {
        $table = $this->table($resource);
        $data = $request->validate($this->rules($resource, $id));
        if ($resource === 'challenges') abort_unless(count($data['tasks']) === $data['duration_days'], 422, 'Provide one task for each day.');
        foreach (['tasks', 'features'] as $field) if (isset($data[$field])) $data[$field] = json_encode(array_values($data[$field]));
        $result = DB::transaction(function () use ($request, $resource, $table, $id, $data) {
            if ($id) {
                $old = DB::table($table)->where('id', $id)->lockForUpdate()->firstOrFail();
                if (in_array($resource, ['triggers', 'coping'], true)) DB::table('wellness_catalog_aliases')->insertOrIgnore(['resource' => $resource, 'alias' => \Illuminate\Support\Str::slug($old->name), 'slug' => $old->slug]);
                // Stable keys keep old string-based check-ins resolvable after label changes.
                if (isset($data['slug'])) abort_unless(DB::table($table)->where('id', $id)->value('slug') === $data['slug'], 422, 'Published catalog keys cannot be changed.');
                DB::table($table)->where('id', $id)->update([...$data, 'updated_at' => now()]);
                $key = $id;
            } else $key = DB::table($table)->insertGetId([...$data, 'created_at' => now(), 'updated_at' => now()]);
            AdminAudit::record($request->user()->id, $id ? 'update' : 'create', $resource, (string) $key, array_keys($data));
            return DB::table($table)->where('id', $key)->first();
        });
        return response()->json(['data' => $result], $id ? 200 : 201)->header('Cache-Control', 'private, no-store');
    }

    public function archive(Request $request, string $resource, string $id)
    {
        $table = $this->table($resource);
        DB::transaction(function () use ($table, $id, $request, $resource) {
            DB::table($table)->where('id', $id)->lockForUpdate()->firstOrFail();
            DB::table($table)->where('id', $id)->update(['is_active' => false, 'updated_at' => now()]);
            AdminAudit::record($request->user()->id, 'archive', $resource, $id, ['is_active']);
        });
        return response()->json(['message' => 'Archived.']);
    }
}
