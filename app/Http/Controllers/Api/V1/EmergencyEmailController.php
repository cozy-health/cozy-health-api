<?php
namespace App\Http\Controllers\Api\V1;
use App\Http\Controllers\Controller;
use App\Jobs\SendEmergencyContactAlert;
use App\Models\EmergencyEmailContact;
use App\Services\EmergencyEmailService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
class EmergencyEmailController extends Controller
{
    public function show(Request $request)
    {
        return response()->json(['enabled' => (bool) config('launch.emergency_email.enabled'), 'contact' => EmergencyEmailContact::where('user_id', $request->user()->id)->first(), 'latest_alert' => DB::table('emergency_email_alerts')->where('user_id', $request->user()->id)->latest('created_at')->first(['id', 'status', 'created_at'])]);
    }
    public function store(Request $request, EmergencyEmailService $service)
    {
        $data = $request->validate(['email' => ['required', 'email:rfc', 'max:254'], 'acknowledged' => ['required', 'accepted']]);
        $token = Str::random(64); $unsubscribe = Str::random(64);
        $contact = DB::transaction(function () use ($request, $data, $service, $token, $unsubscribe) {
            $request->user()->newQuery()->whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            $service->reserve();
            // Replacing a contact invalidates queued alerts to its previous address.
            DB::table('emergency_email_alerts')->where('user_id', $request->user()->id)->where('status', 'queued')->update(['status' => 'failed', 'updated_at' => now()]);
            return EmergencyEmailContact::updateOrCreate(['user_id' => $request->user()->id], ['id' => EmergencyEmailContact::where('user_id', $request->user()->id)->value('id') ?? (string) Str::uuid(), 'email' => $data['email'], 'confirmed_at' => null, 'token_hash' => hash('sha256', $token), 'token_expires_at' => now()->addDay(), 'unsubscribe_hash' => hash('sha256', $unsubscribe), 'unsubscribe_token' => $unsubscribe]);
        });
        $url = rtrim(config('app.url'), '/').'/api/v1/emergency-email/confirm?id='.$contact->id.'&token='.urlencode($token);
        $stop = rtrim(config('app.url'), '/').'/api/v1/emergency-email/unsubscribe?id='.$contact->id.'&token='.urlencode($unsubscribe);
        try { Mail::raw($request->user()->name." has nominated you as a Cozy Health emergency contact. Confirm only if you recognize this arrangement and agree to receive support-request emails. This is not a monitored emergency service.\n\nConfirm: ".$url."\nDecline or stop alerts: ".$stop, fn ($mail) => $mail->to($contact->email)->subject('Confirm Cozy Health emergency contact')); }
        catch (\Throwable) { return response()->json(['message' => 'Confirmation email could not be sent. Use the message/call options.'], 503); }
        return response()->json(['contact' => $contact, 'message' => 'Confirmation requested. Alerts remain unavailable until your contact confirms.'], 201);
    }
    public function destroy(Request $request)
    {
        EmergencyEmailContact::where('user_id', $request->user()->id)->delete();
        return response()->noContent();
    }
    public function tokenPage(Request $request, string $action)
    {
        abort_unless(in_array($action, ['confirm', 'unsubscribe'], true), 404);
        $data = $request->validate(['id' => ['required', 'uuid'], 'token' => ['required', 'string', 'size:64']]);
        $label = $action === 'confirm' ? 'Confirm emergency-contact emails' : 'Stop emergency-contact emails';
        return response('<!doctype html><html lang="en"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Cozy Health contact choice</title><main><h1>'.e($label).'</h1><p>Cozy Health is not an emergency service. Confirm only if you recognize the person who nominated you.</p><form method="post"><input type="hidden" name="id" value="'.e($data['id']).'"><input type="hidden" name="token" value="'.e($data['token']).'"><button type="submit">'.e($label).'</button></form></main></html>')->header('Referrer-Policy', 'no-referrer')->header('Cache-Control', 'no-store');
    }
    public function tokenAction(Request $request, string $action)
    {
        abort_unless(in_array($action, ['confirm', 'unsubscribe'], true), 404);
        $data = $request->validate(['id' => ['required', 'uuid'], 'token' => ['required', 'string', 'size:64']]);
        $contact = EmergencyEmailContact::findOrFail($data['id']);
        $expected = $action === 'confirm' ? $contact->token_hash : $contact->unsubscribe_hash;
        abort_unless(hash_equals($expected, hash('sha256', $data['token'])), 404);
        if ($action === 'unsubscribe') $contact->delete();
        else { abort_unless($contact->token_expires_at->isFuture(), 410, 'Confirmation expired.'); $contact->update(['confirmed_at' => now()]); }
        return response()->json(['message' => $action === 'confirm' ? 'Contact confirmed.' : 'Emergency-contact emails stopped.'])->header('Cache-Control', 'no-store');
    }
    public function alert(Request $request, EmergencyEmailService $service)
    {
        $data = $request->validate(['request_id' => ['required', 'uuid']]);
        $alert = DB::transaction(function () use ($request, $service, $data) {
            $request->user()->newQuery()->whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            $existing = DB::table('emergency_email_alerts')->where('user_id', $request->user()->id)->where('request_id', $data['request_id'])->first();
            if ($existing) return $existing;
            $contact = EmergencyEmailContact::where('user_id', $request->user()->id)->whereNotNull('confirmed_at')->first();
            abort_unless($contact, 422, 'A confirmed email contact is required. Use message/call instead.');
            $recent = DB::table('emergency_email_alerts')->where('user_id', $request->user()->id);
            abort_if((clone $recent)->where('created_at', '>', now()->subSeconds(config('launch.emergency_email.cooldown_seconds')))->exists(), 429, 'An alert was requested recently. Use message/call for immediate follow-up.');
            abort_if((clone $recent)->where('created_at', '>=', now()->utc()->startOfDay())->count() >= config('launch.emergency_email.per_account_daily_limit'), 429, 'Email limit reached. Use message/call.');
            $service->reserve();
            $id = (string) Str::uuid();
            DB::table('emergency_email_alerts')->insert(['id' => $id, 'user_id' => $request->user()->id, 'contact_id' => $contact->id, 'request_id' => $data['request_id'], 'status' => 'queued', 'created_at' => now(), 'updated_at' => now()]);
            SendEmergencyContactAlert::dispatch($id)->afterCommit();
            return DB::table('emergency_email_alerts')->where('id', $id)->first();
        });
        return response()->json(['alert' => $alert, 'message' => 'An email request is not proof of sending or delivery. Message/call options remain available.'], 202);
    }
}
