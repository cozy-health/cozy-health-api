<?php
namespace App\Http\Controllers\Api\V1;
use Illuminate\Http\Request;
class SyncController extends ApiController {
    public function batch(Request $request) { 
        $operations = $request->input('operations', []);
        $results = [];
        \DB::beginTransaction();
        try {
            foreach ($operations as $op) {
                $type = $op['type'] ?? null;
                $action = $op['action'] ?? null;
                $data = $op['data'] ?? [];
                
                if (!$type || !$action || empty($data) || empty($data['id'])) {
                    $results[] = ['success' => false, 'error' => 'Invalid operation format'];
                    continue;
                }
                
                $data['user_id'] = $request->user()->id;
                
                $modelClass = '\App\Models\\' . str_replace(' ', '', ucwords(str_replace('_', ' ', $type)));
                if (!class_exists($modelClass)) {
                    $results[] = ['success' => false, 'type' => $type, 'id' => $data['id'], 'error' => 'Unknown type'];
                    continue;
                }
                
                if ($action === 'upsert') {
                    $modelClass::updateOrCreate(['id' => $data['id']], $data);
                    $results[] = ['success' => true, 'type' => $type, 'id' => $data['id']];
                } else if ($action === 'delete') {
                    $modelClass::where('id', $data['id'])->delete();
                    $results[] = ['success' => true, 'type' => $type, 'id' => $data['id']];
                } else {
                    $results[] = ['success' => false, 'type' => $type, 'id' => $data['id'], 'error' => 'Unknown action'];
                }
            }
            \DB::commit();
        } catch (\Exception $e) {
            \DB::rollBack();
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
        return $this->success($results); 
    }
}
