<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Process\Process;

class AudioTranscoder
{
    /** Returns a validated AAC/M4A file so browser audio also plays on iOS. Caller removes the file. */
    public function convert(UploadedFile $input): array
    {
        $output = tempnam(sys_get_temp_dir(), 'cozy-audio-');
        try {
            $process = new Process([
                config('filesystems.audio_ffmpeg', 'ffmpeg'), '-nostdin', '-hide_banner', '-loglevel', 'error',
                '-protocol_whitelist', 'file,pipe', '-i', $input->getRealPath(), '-map', '0:a:0',
                '-vn', '-c:a', 'aac', '-ac', '1', '-ar', '44100', '-b:a', '64k', '-t', '301',
                '-movflags', '+faststart', '-f', 'mp4', '-y', $output,
            ]);
            $process->setTimeout(45)->run();
            if (! $process->isSuccessful() || ! filesize($output)) {
                throw ValidationException::withMessages(['audio' => ['This file cannot be read as an audio recording.']]);
            }
            $probe = new Process([config('filesystems.audio_ffprobe', 'ffprobe'), '-v', 'error', '-show_entries', 'format=duration', '-of', 'json', $output]);
            $probe->setTimeout(10)->mustRun();
            $duration = (float) (json_decode($probe->getOutput(), true)['format']['duration'] ?? 0);
            if ($duration <= 0 || $duration > 300.5) {
                throw ValidationException::withMessages(['audio' => ['Record between 1 second and 5 minutes.']]);
            }
            return ['path' => $output, 'duration' => min(300, max(1, (int) round($duration)))];
        } catch (\Throwable $e) {
            @unlink($output);
            throw $e;
        }
    }
}
