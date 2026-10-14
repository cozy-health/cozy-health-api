<?php
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
config(['services.provider.portal_url' => 'https://portal.example.test/provider']);
Illuminate\Support\Carbon::setTestNow('2026-10-14 10:00:00 UTC');
$target = __DIR__.'/../validation/provider-invitation-email';
if (!is_dir($target)) mkdir($target, 0777, true);
foreach (['registered' => true, 'new-provider' => false] as $name => $registered) {
    $mail = App\Mail\ProviderInvitationMail::example($registered);
    file_put_contents($target.'/'.$name.'.html', $mail->render());
    $mail->build();
    file_put_contents($target.'/'.$name.'.txt', view('emails.provider-invitation-text', array_merge($mail->viewData, ['code' => $mail->code]))->render());
}
echo "Synthetic previews generated; no mail sent.\n";
