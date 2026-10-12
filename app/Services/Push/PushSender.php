<?php

namespace App\Services\Push;

use App\Models\PushDevice;
use App\Models\AppNotification;

interface PushSender
{
    /** accepted, transient, invalid, failed or unknown; never return token/payload details. */
    public function send(PushDevice $device, AppNotification $notification): string;
}
