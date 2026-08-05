<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrganizationAnnouncementHistory extends Model
{
    protected $fillable = [
        'organization_id',
        'announcement_id',
        'edited_by',
        'title',
        'message',
        'meta',
    ];

    protected $casts = [
        'meta' => 'array',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function announcement(): BelongsTo
    {
        return $this->belongsTo(OrganizationAnnouncement::class, 'announcement_id');
    }

    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'edited_by');
    }
}
