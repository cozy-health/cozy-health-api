<?php
namespace App\Http\Controllers\Api\V1;
use Illuminate\Http\Request;
class SubscriptionController extends ApiController {
    public function index(Request $request) { return $this->success([]); }
    public function store(Request $request) { return $this->success([]); }
    public function show($id) { return $this->success([]); }
    public function update(Request $request, $id) { return $this->success([]); }
    public function destroy($id) { return $this->success([]); }
}
