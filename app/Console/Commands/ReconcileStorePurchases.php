<?php
namespace App\Console\Commands;
use App\Http\Controllers\Api\V1\StoreBillingController;
use App\Models\User;
use App\Services\StorePurchaseVerifier;
use Illuminate\Console\Command;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
class ReconcileStorePurchases extends Command
{
    protected $signature = 'stores:reconcile';
    protected $description = 'Refresh server-authoritative subscription renewals, cancellations and refunds';
    public function handle(StoreBillingController $controller, StorePurchaseVerifier $verifier): int
    {
        if (!config('launch.billing_enabled')) { $this->info('Store billing disabled; no requests sent.'); return self::SUCCESS; }
        $failed = 0;
        DB::table('store_purchases')->where(fn ($query) => $query->where('status', 'active')->orWhere('expires_at', '>=', now()->subDays(60)))->orderBy('id')->chunkById(100, function ($purchases) use ($controller, $verifier, &$failed) {
            foreach ($purchases as $purchase) {
                $user = User::find($purchase->user_id); if (!$user) continue;
                $request = Request::create('/internal/store-reconcile', 'POST', ['platform' => $purchase->platform, 'reference' => Crypt::decryptString($purchase->purchase_reference)]);
                $request->setUserResolver(fn () => $user);
                try { $controller->verify($request, $verifier); } catch (\Throwable) { $failed++; }
            }
        });
        $this->info('Reconciliation complete. Failed requests: '.$failed);
        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
