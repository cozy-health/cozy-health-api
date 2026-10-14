<?php
namespace App\Services;
use Illuminate\Support\Facades\DB;
class AdminAudit
{
    public static function record(int $actor, string $action, string $resource, string $id, array $fields = []): void
    {
        DB::table('admin_audit_logs')->insert(['actor_id' => $actor, 'action' => $action,
            'resource' => $resource, 'resource_id' => $id, 'changed_fields' => json_encode($fields), 'created_at' => now()]);
    }
}
