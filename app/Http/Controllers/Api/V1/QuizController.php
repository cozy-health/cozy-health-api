<?php
namespace App\Http\Controllers\Api\V1;
use App\Http\Requests\PaginationRequest;
use App\Services\{AssessmentService, QuizSharingService};
use Illuminate\Http\Request;
class QuizController extends ApiController
{
    public function index(PaginationRequest $request) {
        $query = \App\Models\QuizAttempt::where('user_id', $request->user()->id);
        if ($request->boolean('include_deleted')) $query->withTrashed(); else $query->where('is_deleted', false);
        return $this->success($query->orderByDesc('client_created_at')->orderBy('id')->paginate($request->input('per_page', 20)));
    }
    public function show(Request $request, string $id) { return app(QuizCatalogController::class)->attempt($request, $id); }
    public function store(Request $request) { return $this->success(app(AssessmentService::class)->submit($request->user(), $request->all())); }
    public function update(Request $request, string $id) { abort(405, 'Completed assessments cannot be edited.'); }
    public function destroy(Request $request, string $id)
    {
        app(QuizSharingService::class)->deleteAttempt($request->user()->id, $id);
        return $this->success(['id' => $id, 'is_deleted' => true]);
    }
}
