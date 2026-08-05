<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChurchProfile extends Model
{
    protected $fillable = [
        'organization_id',
        'denomination',
        'senior_pastor_name',
        'service_times',
        'ministries',
        'worship_location',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
