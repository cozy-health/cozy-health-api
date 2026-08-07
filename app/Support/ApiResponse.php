<?php
namespace App\Support;
use Illuminate\Http\JsonResponse;
use Illuminate\Pagination\LengthAwarePaginator;
trait ApiResponse {
    protected function success(mixed $data=null,string $message='Request successful.',int $status=200): JsonResponse { return response()->json(['success'=>true,'message'=>$message,'data'=>$data],$status); }
    protected function paginated(LengthAwarePaginator $p,string $message='Records retrieved successfully.'): JsonResponse { return response()->json(['success'=>true,'message'=>$message,'data'=>$p->items(),'meta'=>['current_page'=>$p->currentPage(),'last_page'=>$p->lastPage(),'per_page'=>$p->perPage(),'total'=>$p->total()],'links'=>['first'=>$p->url(1),'last'=>$p->url($p->lastPage()),'previous'=>$p->previousPageUrl(),'next'=>$p->nextPageUrl()]]); }
}
