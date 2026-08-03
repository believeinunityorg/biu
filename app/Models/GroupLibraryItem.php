<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class GroupLibraryItem extends Model
{
    public const TYPE_PHOTO = 'photo';

    public const TYPE_VIDEO = 'video';

    public const TYPE_DOCUMENT = 'document';

    protected $fillable = [
        'group_id',
        'uploader_user_id',
        'type',
        'path',
        'original_name',
        'mime',
        'size',
        'title',
        'caption',
        'visibility_status',
    ];

    protected $casts = [
        'size' => 'integer',
    ];

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploader_user_id');
    }

    public function url(): string
    {
        return asset('storage/'.$this->path);
    }

    public function deleteFile(): void
    {
        if (filled($this->path)) {
            Storage::disk('public')->delete($this->path);
        }
    }
}
