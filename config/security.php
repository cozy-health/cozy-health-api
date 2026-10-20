<?php

return [
    'require_https' => env('REQUIRE_HTTPS', ! in_array(env('APP_ENV', 'production'), ['local', 'testing'], true)),
];
