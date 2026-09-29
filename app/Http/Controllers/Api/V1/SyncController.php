<?php
namespace App\Http\Controllers\Api\V1;
use Illuminate\Http\Request;
class SyncController extends ApiController {
    public function batch(Request $request) { return $this->success([]); }
}
