<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class JournalEntry extends Model
{
    use \Illuminate\Database\Eloquent\SoftDeletes;
    protected $table = 'journal_entries';
    protected $keyType = 'string';
    public $incrementing = false;
    protected $fillable = array (
  0 => 'id',
  1 => 'user_id',
  2 => 'type',
  3 => 'title',
  4 => 'body',
  5 => 'voice_url',
  6 => 'voice_duration',
  7 => 'transcription',
  8 => 'prompt_id',
  9 => 'prompt_text',
  10 => 'tags',
  11 => 'linked_mood_entry_id',
  12 => 'word_count',
  13 => 'is_crisis_flagged',
  14 => 'is_deleted',
  15 => 'client_created_at',
  16 => 'client_updated_at',
  17 => 'is_draft',
  18 => 'audio_attachment_id',
);
    protected $casts = array (
  'tags' => 'array',
  'is_draft' => 'boolean',
  'is_crisis_flagged' => 'boolean',
  'is_deleted' => 'boolean',
  'client_created_at' => 'datetime',
  'client_updated_at' => 'datetime',
);
    public function user() { return $this->belongsTo(User::class); }

    protected static function booted()
    {
        static::saving(function ($entry) {
            if (! $entry->isDirty('voice_url') && ! $entry->isDirty('audio_attachment_id')) return;
            if (preg_match('#^/audio-attachments/([a-f0-9-]{36})$#', $entry->voice_url ?? '', $match)) $entry->audio_attachment_id = $match[1];
            if ($entry->audio_attachment_id) {
                $audio = app(\App\Services\AudioAttachmentService::class)->reference(User::findOrFail($entry->user_id), $entry->audio_attachment_id, 'journal');
                $entry->voice_url = '/audio-attachments/'.$audio->id;
                $entry->voice_duration = $audio->duration;
            }
        });
        static::addGlobalScope('user', function ($query) {
            if (auth()->check()) {
                $query->where('user_id', auth()->id());
            }
        });
    }
}
