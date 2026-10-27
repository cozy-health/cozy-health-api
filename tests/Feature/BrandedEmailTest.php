<?php

use App\Mail\WelcomeMail;
use App\Mail\BrandedNoticeMail;
use App\Models\User;
use App\Services\AdminEmailRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

it('uses the Cozy logo and all four brand colours in every registered customer email', function () {
    foreach (array_keys(AdminEmailRegistry::EVENTS) as $event) {
        $html = AdminEmailRegistry::preview($event)->render();
        foreach (['alt="Cozy Health"', '#0460D8', '#00BC4C', '#F25C05', '#F2CB05'] as $brand) {
            expect($html, $event)->toContain($brand);
        }
        expect($html, $event)->toContain('data:image/png;base64,');
    }
});

it('escapes service-notice content and preserves the action in both email alternatives', function () {
    $mail = new BrandedNoticeMail('Cozy notice', 'A <script>test</script> update',
        ['Hello <img src=x onerror=alert(1)> & welcome.'],
        [['label' => 'Confirm <account>', 'url' => 'https://example.test/confirm?id=example&token=synthetic']]);
    $html = $mail->render();
    expect($html)->not->toContain('<script>', '<img src=x')->toContain('&lt;script&gt;', '&lt;img', 'id=example&amp;token=synthetic');
    $mail->assertSeeInText('Hello <img src=x onerror=alert(1)> & welcome.');
    $mail->assertSeeInText('https://example.test/confirm?id=example&token=synthetic');
    expect($mail)->not->toBeInstanceOf(\Illuminate\Contracts\Queue\ShouldQueue::class);
});

it('embeds the brand logo in delivered emails and keeps a plain text alternative', function () {
    config(['mail.default' => 'array', 'mail.mailers.array' => ['transport' => 'array']]);
    $mail = new WelcomeMail(User::factory()->create(['name' => 'Amina <script>alert(1)</script>']));
    $checked = false;
    $mail->withSymfonyMessage(function ($message) use (&$checked) {
        $checked = true;
        expect($message->getHtmlBody())->toContain('cid:')->not->toContain('data:image/');
        expect($message->getAttachments())->toHaveCount(1);
        expect($message->getAttachments()[0]->getDisposition())->toBe('inline');
        expect($message->getTextBody())->toContain('Cozy Health')->not->toContain('<html');
    });
    Mail::to('preview@example.test')->sendNow($mail);
    expect($checked)->toBeTrue();
});
