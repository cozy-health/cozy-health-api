<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema};
return new class extends Migration {
    public function up(): void
    {
        $keys = [];
        DB::table('user_profiles')->whereNotNull('username')->orderBy('id')->each(function ($profile) use (&$keys) {
            $key = strtolower(trim($profile->username));
            if (isset($keys[$key])) throw new \RuntimeException('Normalized usernames conflict. Resolve the conflicting profiles before migrating.');
            $keys[$key] = true;
        });
        Schema::table('user_profiles', fn (Blueprint $t) => $t->string('username_key', 50)->nullable()->unique());
        DB::table('user_profiles')->whereNotNull('username')->orderBy('id')->each(function ($profile) {
            DB::table('user_profiles')->where('id', $profile->id)->update(['username_key' => strtolower(trim($profile->username))]);
        });
    }
    public function down(): void {
        Schema::table('user_profiles', fn (Blueprint $t) => $t->dropUnique('user_profiles_username_key_unique'));
        Schema::table('user_profiles', fn (Blueprint $t) => $t->dropColumn('username_key'));
    }
};
