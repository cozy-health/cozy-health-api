<?php
namespace App\Http\Requests;
class UserDisplayPreferencesRequest extends ApiFormRequest { public function rules(): array { return [
            'theme' => 'sometimes|string|in:light,dark,system',
            'accent_color' => 'sometimes|string|max:20',
            'language' => 'sometimes|string|max:10',
            'reduce_motion' => 'sometimes|boolean',
            'high_contrast' => 'sometimes|boolean',
            'haptics_enabled' => 'sometimes|boolean',
            'text_size' => 'sometimes|numeric|min:0.8|max:1.5',
            'show_stats' => 'sometimes|boolean',
            'show_username' => 'sometimes|boolean',
        ]; } }
