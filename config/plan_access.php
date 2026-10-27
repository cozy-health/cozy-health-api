<?php

return ['enabled' => filter_var(env('PLAN_ACCESS_ENABLED', false), FILTER_VALIDATE_BOOLEAN)];
