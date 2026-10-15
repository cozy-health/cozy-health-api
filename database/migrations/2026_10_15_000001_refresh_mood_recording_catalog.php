<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('feeling_causes', fn (Blueprint $table) => $table->string('group_name', 100)->nullable());
        $catalog = json_decode(file_get_contents(database_path('data/mood_recording_v2.json')), true, 512, JSON_THROW_ON_ERROR);
        DB::transaction(function () use ($catalog) {
            // Retain retired rows for historical references and older clients.
            foreach (['feelings', 'feeling_expressions', 'feeling_causes', 'coping_mechanisms'] as $table) {
                DB::table($table)->update(['is_active' => false]);
            }
            $ids = [];
            foreach ($catalog['moods'] as $row) {
                $sourceId = $row['id'];
                unset($row['id']);
                $this->upsert('feelings', $row);
                $ids[$sourceId] = DB::table('feelings')->where('slug', $row['slug'])->value('id');
            }
            foreach ($catalog['expressions'] as $row) {
                $row['feeling_id'] = $ids[$row['feeling_id']];
                $this->upsert('feeling_expressions', $row);
            }
            foreach ($catalog['triggers'] as $row) $this->upsert('feeling_causes', $row);
            foreach ($catalog['coping'] as $row) $this->upsert('coping_mechanisms', $row);
        });
    }

    private function upsert(string $table, array $row): void
    {
        DB::table($table)->insertOrIgnore([...$row, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        DB::table($table)->where('slug', $row['slug'])->update([...$row, 'is_active' => true, 'updated_at' => now()]);
    }

    public function down(): void
    {
        // Keep catalog rows: saved entries may already reference their stable keys.
        Schema::table('feeling_causes', fn (Blueprint $table) => $table->dropColumn('group_name'));
    }
};
