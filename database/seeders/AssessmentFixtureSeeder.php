<?php
namespace Database\Seeders;
use App\Models\Quiz;
use Illuminate\Database\Seeder;

/** Synthetic definitions for engineering checks only. Never loaded by DatabaseSeeder. */
class AssessmentFixtureSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) throw new \LogicException('Assessment fixtures are forbidden outside local/testing.');
        foreach (['phq-9' => 9, 'gad-7' => 7, 'pss-10' => 10, 'sleep' => 8] as $slug => $count) {
            $quiz = Quiz::firstOrCreate(['slug' => $slug], ['title' => strtoupper($slug).' engineering fixture', 'type' => $slug === 'sleep' ? 'wellness' : 'clinical',
                'description' => 'Synthetic engineering fixture. Not for clinical use.', 'is_active' => true, 'approved_at' => now(),
                'disclaimer' => 'Engineering fixture. Not a substitute for professional care.',
                'scoring_rules' => ['bands' => [['max' => $count * ($slug === 'pss-10' ? 4 : 3), 'label' => 'Fixture result']], 'timeframe' => 'Engineering fixture timeframe']]);
            if ($quiz->questions()->exists()) continue;
            for ($i = 0; $i < $count; $i++) {
                $question = $quiz->questions()->create(['question' => 'Synthetic question '.($i + 1), 'sort_order' => $i]);
                $maximum = $slug === 'pss-10' ? 4 : 3;
                for ($j = 0; $j <= $maximum; $j++) $question->options()->create(['label' => 'Fixture option '.$j,
                    'score' => $slug === 'pss-10' && in_array($i + 1, [4, 5, 7, 8]) ? 4 - $j : $j, 'sort_order' => $j]);
            }
        }
    }
}
