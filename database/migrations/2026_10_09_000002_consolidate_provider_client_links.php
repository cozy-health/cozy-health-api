<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // provider_connections is deprecated after this copy, but retained as a safety net until PD-6.
        // Run with linking writes paused: MySQL DDL is not transactional.
        $rows = DB::table('provider_connections')->orderBy('id')->get();
        $keys = [];
        $emails = [];
        foreach (DB::table('provider_client_links')->join('providers', 'providers.id', '=', 'provider_client_links.provider_id')
            ->get(['provider_client_links.user_id', 'providers.email']) as $link) {
            $emails[$link->user_id.':'.strtolower(trim($link->email))] = true;
        }
        foreach ($rows as $row) {
            $email = strtolower(trim($row->provider_email));
            if (!DB::table('users')->where('id', $row->user_id)->exists()
                || !filter_var($email, FILTER_VALIDATE_EMAIL)
                || (!$row->verified_at && !$row->created_at)
                || ($row->provider_id && !DB::table('providers')->where('id', $row->provider_id)->exists())) {
                throw new RuntimeException("Unsafe legacy provider connection {$row->id}; migration stopped before schema changes.");
            }
            $key = $row->user_id.':'.($row->provider_id ?? $email);
            $emailKey = $row->user_id.':'.$email;
            if (isset($keys[$key]) || isset($emails[$emailKey]) || ($row->provider_id && DB::table('provider_client_links')
                ->where('user_id', $row->user_id)->where('provider_id', $row->provider_id)->exists())) {
                throw new RuntimeException("Conflicting legacy provider connection {$row->id}; explicit reconciliation required.");
            }
            $keys[$key] = true;
            $emails[$emailKey] = true;
        }

        Schema::table('provider_client_links', function (Blueprint $table) {
            $table->unsignedBigInteger('provider_id')->nullable()->change();
            $table->string('provider_email')->nullable()->index();
            $table->string('provider_name', 191)->nullable();
            $table->string('provider_phone', 32)->nullable();
            $table->string('verification_code', 255)->nullable();
            $table->timestamp('verification_expires_at')->nullable();
            $table->timestamp('verified_at')->nullable();
            // Provenance lets down() remove only copied rows, leaving pre-existing links alone.
            $table->unsignedBigInteger('legacy_connection_id')->nullable()->unique();
            $table->unique(['user_id', 'provider_email']);
        });

        DB::transaction(function () use ($rows) {
            // PD-2 links were already active. Preserve their existing consent and verification.
            DB::table('provider_client_links')->update(['verified_at' => DB::raw('linked_at')]);
            foreach (DB::table('providers')->get(['id', 'email']) as $provider) {
                DB::table('provider_client_links')->where('provider_id', $provider->id)
                    ->update(['provider_email' => strtolower(trim($provider->email))]);
            }
            foreach ($rows as $row) {
                DB::table('provider_client_links')->insert([
                    'provider_id' => $row->provider_id, 'user_id' => $row->user_id,
                    'provider_email' => strtolower(trim($row->provider_email)),
                    'provider_name' => $row->provider_name, 'provider_phone' => $row->provider_phone,
                    'verification_code' => $row->verification_code,
                    'verification_expires_at' => $row->verification_expires_at,
                    'verified_at' => $row->verified_at,
                    'linked_at' => $row->verified_at ?? $row->created_at,
                    'revoked_at' => null, 'legacy_connection_id' => $row->id,
                    'consent_moods' => false, 'consent_journals' => false,
                    'consent_quizzes' => false, 'consent_notes' => false,
                ]);
            }
            if (DB::table('provider_client_links')->whereNotNull('legacy_connection_id')->count() !== $rows->count()) {
                throw new RuntimeException('Legacy link copy count mismatch.');
            }
        });
    }

    public function down(): void
    {
        if (DB::table('provider_client_links')->whereNull('legacy_connection_id')->whereNull('provider_id')->exists()) {
            throw new RuntimeException('Cannot roll back while new email-only invitations exist; export/reconcile them first.');
        }
        DB::table('provider_client_links')->whereNotNull('legacy_connection_id')->delete();
        Schema::table('provider_client_links', function (Blueprint $table) {
            $table->dropUnique(['user_id', 'provider_email']);
            $table->dropUnique(['legacy_connection_id']);
            $table->dropIndex(['provider_email']);
            $table->dropColumn(['provider_email', 'provider_name', 'provider_phone', 'verification_code',
                'verification_expires_at', 'verified_at', 'legacy_connection_id']);
            $table->unsignedBigInteger('provider_id')->nullable(false)->change();
        });
    }
};
