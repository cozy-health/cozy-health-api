<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
return new class extends Migration {
    public function up(): void
    {
        foreach (['good' => '😊', 'calm' => '😌', 'okay' => '😐', 'low' => '😔', 'anxious' => '😟', 'angry' => '😠', 'sad' => '😢', 'tired' => '😴', 'excited' => '🤩'] as $key => $emoji) {
            DB::table('feelings')->insertOrIgnore(['slug' => $key, 'name' => ucfirst($key), 'description' => '', 'is_active' => true, 'sort_order' => 0, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('feeling_expressions')->insertOrIgnore(['slug' => $key, 'name' => ucfirst($key), 'feeling_id' => DB::table('feelings')->where('slug', $key)->value('id'), 'emoji' => $emoji, 'description' => '', 'is_active' => true, 'sort_order' => 0, 'created_at' => now(), 'updated_at' => now()]);
        }
        foreach (['Work', 'Family', 'Friends', 'Health', 'School', 'Money', 'Sleep', 'Relationships', 'Body', 'Weather', 'News', 'Self'] as $i => $name) DB::table('feeling_causes')->insertOrIgnore(['slug' => Str::slug($name), 'name' => $name, 'sort_order' => $i, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        foreach (['Box breathing', '5-4-3-2-1 grounding', 'Quick journal'] as $i => $name) DB::table('coping_mechanisms')->insertOrIgnore(['slug' => Str::slug($name), 'name' => $name, 'description' => '', 'sort_order' => $i, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        foreach (\App\Models\Quiz::with('questions.options')->get() as $quiz) DB::table('admin_quiz_drafts')->insertOrIgnore(['quiz_id' => $quiz->id, 'title' => $quiz->title, 'definition' => json_encode($quiz->toArray()), 'status' => $quiz->is_active && $quiz->approved_at ? 'published' : 'draft', 'created_at' => now(), 'updated_at' => now()]);
    }
    public function down(): void { /* Retain seeded content referenced by existing check-ins. */ }
};
