<?php
namespace App\Http\Controllers\Api\V1;
use Illuminate\Http\Request;
class ChatController extends ApiController {
    public function index(\App\Http\Requests\PaginationRequest $request) {
        $entries = \App\Models\ChatConversation::orderBy('updated_at', 'desc')->paginate($request->input('per_page', 20));
        return $this->success($entries);
    }
    public function store(Request $request) { return $this->success([]); }
    public function messages(\App\Http\Requests\PaginationRequest $request, string $id) {
        $conversation = \App\Models\ChatConversation::where('user_id', $request->user()->id)->find($id);
        if (! $conversation) {
            return $this->error('Resource not found.', 'NOT_FOUND', [], 404);
        }
        $validated = $request->validated();
        $messages = \App\Models\ChatMessage::where('user_id', $request->user()->id)
            ->where('conversation_id', $conversation->id)
            ->orderByRaw('COALESCE(client_created_at, created_at) ASC')->orderBy('id')
            ->paginate($validated['per_page'] ?? 50);

        return $this->success($messages);
    }
    public function show($id) {
        $entry = \App\Models\ChatConversation::find($id);
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
