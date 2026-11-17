<?php
namespace App\Console\Commands;

use App\Models\{GuardianAuthorization, User};
use App\Services\AgeEligibility;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PruneGuardianData extends Command
{
    protected $signature = 'guardians:prune {--execute : Apply approved retention; otherwise preview}';
    protected $description = 'Preview or apply guardian invitation and approved authorization retention';
    public function handle(): int
    {
        $eligibility = app(AgeEligibility::class);
        $execute = $this->option('execute');
        if ($execute && (! config('age.retention_approval') || config('age.record_days') < 1)) {
            $this->error('Record the retention approval and record-days limit before execution.'); return 1;
        }
        $count = 0;
        GuardianAuthorization::orderBy('id')->chunkById(100, function ($rows) use ($execute, $eligibility, &$count) {
            foreach ($rows as $authorization) {
                if ($authorization->status === 'expired') continue;
                $user = User::find($authorization->user_id);
                $adult = $user && ($eligibility->age($user) ?? 0) >= 18;
                $expired = $authorization->erase_at?->isPast();
                if (! $adult && ! $expired) continue;
                $count++;
                if (! $execute) continue;
                DB::transaction(function () use ($authorization, $user, $adult, $eligibility) {
                    if ($user) User::whereKey($user->id)->lockForUpdate()->firstOrFail();
                    $record = GuardianAuthorization::whereKey($authorization->id)->lockForUpdate()->firstOrFail();
                    // Open cases are a bounded hold; team must resolve and review them.
                    if (DB::table('age_support_cases')->where('user_id', $record->user_id)->where('status', 'open')->exists()) return;
                    if ($adult && in_array($record->status, ['pending', 'confirmed', 'authorized', 'withdrawal_confirmed'])) {
                        $eligibility->endAuthorizations($user, 'adulthood');
                        $eligibility->event($user, 'guardian_authority_ended_at_adulthood');
                    }
                    if ($record->erase_at?->isPast()) {
                        $record->update(['guardian_name' => null, 'guardian_email' => null, 'token_hash' => null,
                            'email_hash' => str_repeat('0', 64), 'session_hash' => null, 'status' => 'expired', 'ended_at' => $record->ended_at ?? now()]);
                    }
                });
            }
        });
        if ($execute) {
            $cutoff = now()->subDays(config('age.record_days'));
            $held = DB::table('age_support_cases')->where('status', 'open')->pluck('user_id');
            DB::table('age_authorization_events')->where('created_at', '<', $cutoff)->whereNotIn('user_id', $held)->delete();
            DB::table('age_support_cases')->where('status', 'resolved')->where('updated_at', '<', $cutoff)->delete();
            GuardianAuthorization::where('status', 'expired')->where('updated_at', '<', now()->subDays(30))->whereNotIn('user_id', $held)->delete();
        }
        $this->info(($execute ? 'Reviewed' : 'Would review').' '.$count.' guardian records. No private content printed.');
        return 0;
    }
}
