<?php
namespace App\Http\Controllers;
use App\Services\{AdminAudit, AdminEmailRegistry};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
class AdminEmailController extends Controller
{
    public function index(Request $request)
    {
        $data = $request->validate(['page' => 'sometimes|integer|min:1', 'per_page' => 'sometimes|integer|in:10,20,50', 'search' => 'sometimes|string|max:100']);
        $query = DB::table('email_delivery_logs');
        if (!empty($data['search'])) $query->where(fn ($q) => $q->where('event', 'like', '%'.$data['search'].'%')->orWhere('status', 'like', '%'.$data['search'].'%'));
        return response()->json(['data' => $query->select('id', 'event', 'status', 'created_at', 'updated_at')->orderByDesc('created_at')->paginate($data['per_page'] ?? 10),
            'events' => collect(AdminEmailRegistry::EVENTS)->map(fn ($value, $key) => ['id' => $key, 'name' => $value[1]])->values(),
            'queue' => app(AdminQueueHealthController::class)()->getData(true)])->header('Cache-Control', 'private, no-store');
    }
    public function preview(string $event)
    {
        return response()->json(['data' => ['html' => AdminEmailRegistry::preview($event)->render()]])->header('Cache-Control', 'private, no-store');
    }
    public function test(Request $request)
    {
        $data = $request->validate(['email' => 'required|email|max:190']);
        \App\Jobs\SendTestEmail::dispatch($data['email']);
        AdminAudit::record($request->user()->id, 'test-send', 'email', 'queue-test');
        return response()->json(['message' => 'Test email queued.'], 202);
    }
}
