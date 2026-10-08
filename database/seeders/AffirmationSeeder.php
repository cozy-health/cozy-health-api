<?php

namespace Database\Seeders;

use App\Models\Affirmation;
use Illuminate\Database\Seeder;

class AffirmationSeeder extends Seeder
{
    public function run(): void
    {
        $bodies = [
            "You showed up today. That matters.",
            "Rest is not something you have to earn.",
            "Feelings are visitors. Let them come and go.",
            "Progress isn't always visible. Trust the process.",
            "It's okay to not be okay.",
            "You're doing better than you think.",
            "One small step still counts as moving forward.",
            "You don't have to have it all figured out.",
            "Being kind to yourself is not selfish.",
            "This feeling will pass, even if it doesn't feel like it.",
            "Your best today is different from your best yesterday. Both are enough.",
            "You're allowed to take up space.",
            "Slowing down is not falling behind.",
            "You've survived every hard day so far.",
            "Asking for help is a strength.",
            "You are not your worst moment.",
            "Some days, getting through is the win.",
            "You're learning. That's not the same as failing.",
            "Breathing is enough for right now.",
            "You're allowed to want more, and also to want less.",
            "You can pause before deciding what comes next.",
            "Your feelings deserve care, even when they are hard to name.",
            "You do not have to carry everything alone.",
            "A difficult day does not define your whole life.",
            "You can set a boundary and still care about someone.",
            "There is room for you to begin again.",
            "You deserve patience while you find your way.",
            "You can be grateful and still need support.",
            "Taking care of yourself can start with something small.",
            "You are allowed to move at your own pace.",
        ];

        foreach ($bodies as $body) {
            // Re-running seeds must not duplicate copy or reactivate disabled entries.
            Affirmation::firstOrCreate(['body' => $body], ['is_active' => true]);
        }
    }
}
