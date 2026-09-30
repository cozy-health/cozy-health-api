<?php
namespace App\Http\Controllers\Api\V1;
use Illuminate\Http\Request;
class UserProfileController extends ApiController {
    public function index(Request $request) { 
        $entries = \App\Models\UserProfile::orderBy('client_created_at', 'desc')->paginate($request->input('per_page', 20));
        return $this->success($entries); 
    }
    public function store(Request $request) { return $this->success([]); }
    public function show($id) { 
        $entry = \App\Models\UserProfile::find($id);
        if (!$entry) {
            return response()->json([
                'error' => [
                    'code' => 'NOT_FOUND',
                    'message' => 'Resource not found.'
                ]
            ], 404);
        }
        return $this->success($entry); 
    }
    public function update(Request $request, $id) { return $this->success([]); }
    public function destroy($id) { return $this->success([]); }
}
