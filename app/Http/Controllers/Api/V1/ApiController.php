<?php
namespace App\Http\Controllers\Api\V1;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Validation\ValidatesRequests;
class ApiController extends BaseController {
    use AuthorizesRequests, ValidatesRequests;
    protected function success($data, $meta = []) {
        $response = ['data' => $data];
        if (!empty($meta)) $response['meta'] = $meta;
        return response()->json($response);
    }
    protected function error($message, $code = 'ERROR', $details = [], $status = 400) {
        return response()->json(['error' => ['code' => $code, 'message' => $message, 'details' => $details]], $status);
    }
}