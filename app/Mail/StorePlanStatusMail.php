<?php

namespace App\Mail;

use App\Services\StorePlanPresentation;

class StorePlanStatusMail extends BrandedNoticeMail
{
    public function __construct(array $details)
    {
        $message = StorePlanPresentation::message($details);
        parent::__construct($message['title'], $message['title'], $message['paragraphs'], $message['actions']);
    }
}
