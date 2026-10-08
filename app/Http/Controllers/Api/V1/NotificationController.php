<?php
namespace App\Http\Controllers\Api\V1;
use Illuminate\Http\Request;
class NotificationController extends ApiController {
    public function index(\App\Http\Requests\PaginationRequest $request) {
        $entries = \App\Models\AppNotification::orderBy('client_created_at', 'desc')->paginate($request->input('per_page', 20));
        return $this->success($entries);
    }
    public function store(Request $request) { return $this->success([]); }
    public function show($id) {
        $entry = \App\Models\AppNotification::find($id);
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
    public function markAsRead(Request $request, $id) {
        $notification = \App\Models\AppNotification::where('user_id', $request->user()->id)
            ->where('id', $id)
            ->firstOrFail();

        $notification->update([
            'is_read' => true,
            'read_at' => now(),
        ]);

        return response()->json(['message' => 'Notification marked as read']);
    }

    public function markAllAsRead(Request $request) {
        \App\Models\AppNotification::where('user_id', $request->user()->id)
            ->where('is_read', false)
            ->update([
                'is_read' => true,
                'read_at' => now(),
            ]);

        return response()->json(['message' => 'All notifications marked as read']);
    }
}
