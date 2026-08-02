<?php

namespace App\Enums;

use App\Models\CareAlliance;
use App\Models\Group;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

enum MembershipAccountType: string
{
    case Organization = 'Organization';
    case UnityImpactAlliance = 'UnityImpactAlliance';
    case Group = 'Group';

    /**
     * @return class-string<Model>
     */
    public function modelClass(): string
    {
        return match ($this) {
            self::Organization => Organization::class,
            self::UnityImpactAlliance => CareAlliance::class,
            self::Group => Group::class,
        };
    }

    public static function fromModel(Model $model): self
    {
        return match ($model::class) {
            Organization::class => self::Organization,
            CareAlliance::class => self::UnityImpactAlliance,
            Group::class => self::Group,
            default => throw new InvalidArgumentException('Unsupported membership account model: '.$model::class),
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
