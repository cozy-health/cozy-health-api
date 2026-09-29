<?php
namespace App\Http\Controllers\Api\V1;
use Illuminate\Http\Request;
use App\Models\MoodEntry;

class MoodEntryController extends ApiController {
    public function index(Request $request) { 
        $entries = MoodEntry::where('user_id', $request->user()->id)->get();
        return $this->success($entries); 
    }
    public function store(Request $request) { 
        $data = $request->all();
        $data['user_id'] = $request->user()->id;
        $entry = MoodEntry::updateOrCreate(
            ['id' => $data['id'], 'user_id' => $request->user()->id],
            $data
        );
        return $this->success($entry); 
    }
    public function show($id) { return $this->success([]); }
    public function update(Request $request, $id) { return $this->success([]); }
    public function destroy($id) { return $this->success([]); }
}
