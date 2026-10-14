<?php
namespace App\Mail\Concerns;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Support\Str;
trait TracksDelivery
{
    public string $deliveryId;
    protected function initializeDelivery(): void { $this->deliveryId = (string) Str::uuid(); }
    public function headers(): Headers
    {
        // Jobs queued before this release do not have a tracking ID yet.
        if (!isset($this->deliveryId)) $this->initializeDelivery();
        return new Headers(text: ['X-Cozy-Delivery' => $this->deliveryId]);
    }
}
