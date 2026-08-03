<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class GroupEvent extends Model
{
    protected $fillable = [
        'group_id',
        'created_by',
        'name',
        'description',
        'start_date',
        'end_date',
        'location',
        'location_url',
        'poster_image',
        'status',
    ];

    protected $casts = [
        'start_date' => 'datetime',
        'end_date' => 'datetime',
    ];

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isCancelled(): bool
    {
        return ($this->status ?? 'upcoming') === 'cancelled';
    }

    public function coverUrl(): ?string
    {
        return $this->poster_image ? asset('storage/'.$this->poster_image) : null;
    }

    public function deleteCover(): void
    {
        if (filled($this->poster_image)) {
            Storage::disk('public')->delete($this->poster_image);
        }
    }
}
