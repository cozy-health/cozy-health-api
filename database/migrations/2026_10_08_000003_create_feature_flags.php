<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('feature_flags', function (Blueprint $table) {
            $table->id(); $table->string('key')->unique(); $table->boolean('enabled');
            $table->string('description'); $table->timestamps();
        });
        foreach (\App\Services\AppConfigService::DEFAULT_FLAGS as $key => $enabled) {
            DB::table('feature_flags')->insert(['key' => $key, 'enabled' => $enabled, 'description' => str_replace('_', ' ', $key), 'created_at' => now(), 'updated_at' => now()]);
        }
    }
    public function down(): void { Schema::dropIfExists('feature_flags'); }
};
