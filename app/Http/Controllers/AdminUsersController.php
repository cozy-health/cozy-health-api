<?php
namespace App\Http\Controllers;
use App\Models\User;
use Illuminate\Http\Request;
class AdminUsersController extends Controller
{
    public function index(Request $request)
    {
        $data = $request->validate(['search' => 'sometimes|string|max:100', 'page' => 'sometimes|integer|min:1', 'per_page' => 'sometimes|integer|in:10,20,50']);
        $query = User::where('role', 'user')->where('is_demo', false)->select('id', 'name', 'email', 'is_active', 'email_verified_at', 'created_at');
        if (! empty($data['search'])) $query->where(fn ($q) => $q->where('name', 'like', '%'.$data['search'].'%')->orWhere('email', 'like', '%'.$data['search'].'%'));
        return response()->json(['data' => $query->orderBy('id')->paginate($data['per_page'] ?? 10)])->header('Cache-Control', 'private, no-store');
    }
}
