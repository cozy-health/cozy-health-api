<?php
namespace App\Http\Controllers;

use App\Services\AdminAudit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AdminContentController extends Controller
{
    private function priceVersion(object $row): string
    {
        return hash('sha256', json_encode(array_map(fn ($key) => (string) ($row->$key ?? ''),
            ['package_name', 'description', 'price', 'currency', 'country_code', 'tier', 'annual_discount', 'currency_decimals', 'features', 'is_active', 'updated_at'])));
    }
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
        $rows->through(function ($row) use ($resource) { if ($resource === 'packages') $row->version = $this->priceVersion($row); foreach (['tasks', 'features'] as $field) if (isset($row->$field)) $row->$field = json_decode($row->$field, true); return $row; });
        return response()->json(['data' => $rows, ...($resource === 'expressions' ? ['lookups' => ['moods' => DB::table('feelings')->select('id', 'name')->orderBy('name')->get()]] : [])])->header('Cache-Control', 'private, no-store');
    }

    public function show(Request $request, string $resource, string $id)
    {
        abort_unless($resource === 'packages', 404);
        $row = DB::table($this->table($resource))->where('id', $id)->firstOrFail();
        $row->version = $this->priceVersion($row);
        $row->features = json_decode($row->features, true);
        return response()->json(['data' => $row])->header('Cache-Control', 'private, no-store');
    }

    private function rules(string $resource, ?string $id): array
    {
        $table = $this->table($resource);
        $rules = [($resource === 'packages' ? 'package_name' : 'name') => 'required|string|max:100',
            'description' => 'nullable|string|max:5000', 'is_active' => 'required|boolean'];
        if (in_array($resource, ['communities', 'challenges'], true)) $rules['description'] = 'required|string|max:5000';
        if ($resource !== 'communities') $rules += ['slug' => ['required', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', 'max:100', Rule::unique($table)->ignore($id)], 'sort_order' => 'required|integer|min:0|max:10000'];
        if ($resource === 'expressions') $rules += ['feeling_id' => 'required|integer|exists:feelings,id', 'emoji' => 'nullable|string|max:20'];
        if ($resource === 'moods') $rules['slug'][] = 'max:20';
        if (in_array($resource, ['triggers', 'coping'], true)) $rules += ['icon' => 'nullable|string|max:100'];
        if ($resource === 'triggers') {
            unset($rules['description']);
            $rules['group_name'] = 'nullable|string|max:100';
        }
        if ($resource === 'challenges') $rules += ['duration_days' => 'required|integer|min:1|max:90', 'tasks' => 'required|array|min:1|max:90', 'tasks.*' => 'required|string|max:500'];
        if ($resource === 'packages') $rules += ['price' => 'required|numeric|min:0|max:99999999', 'currency' => ['required', 'regex:/^[A-Z]{3}$/'], 'duration' => 'required|in:monthly,yearly,custom', 'duration_days' => 'required|integer|min:1|max:3660', 'features' => 'required|array|max:50', 'features.*' => 'required|string|max:200',
            'audience' => 'sometimes|in:mobile', 'country_code' => ['nullable', 'regex:/^[A-Z]{2}$/'],
            'tier' => ['nullable', 'in:standard,student,premium', Rule::unique($table)->where(fn ($query) => $query->where('country_code', request('country_code'))->where('audience', 'mobile'))->ignore($id)],
            'annual_discount' => 'sometimes|integer|min:0|max:100', 'currency_decimals' => 'sometimes|integer|min:0|max:3', 'expected_updated_at' => 'sometimes|date', 'expected_version' => 'sometimes|string|size:64'];
        return $rules;
    }

    public function store(Request $request, string $resource) { return $this->save($request, $resource, null); }
    public function update(Request $request, string $resource, string $id) { return $this->save($request, $resource, $id); }
    private function save(Request $request, string $resource, ?string $id)
    {
        $table = $this->table($resource);
        $data = $request->validate($this->rules($resource, $id));
        if (! $id) unset($data['expected_updated_at'], $data['expected_version']);
        if ($resource === 'packages' && ! empty($data['tier'])) {
            abort_unless(! empty($data['country_code']) && $data['duration'] === 'monthly', 422, 'Regional tiers require a country and monthly base price.');
        }
        if ($resource === 'challenges') abort_unless(count($data['tasks']) === $data['duration_days'], 422, 'Provide one task for each day.');
        foreach (['tasks', 'features'] as $field) if (isset($data[$field])) $data[$field] = json_encode(array_values($data[$field]));
        $result = DB::transaction(function () use ($request, $resource, $table, $id, $data) {
            if ($id) {
                $old = DB::table($table)->where('id', $id)->lockForUpdate()->firstOrFail();
                if ($resource === 'packages' && isset($data['expected_version'])) abort_unless(hash_equals($this->priceVersion($old), $data['expected_version']), 409, 'This price changed. Reload before saving.');
                if ($resource === 'packages' && isset($data['expected_updated_at'])) {
                    abort_unless(\Carbon\Carbon::parse($data['expected_updated_at'])->equalTo(\Carbon\Carbon::parse($old->updated_at)), 409, 'This price changed. Reload before saving.');
                }
                unset($data['expected_updated_at']);
                unset($data['expected_version']);
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
