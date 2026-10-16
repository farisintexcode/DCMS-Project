<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

trait HasPublicId
{
    public static function bootHasPublicId(): void
    {
        static::creating(function (Model $model): void {
            $model->public_id ??= (string) Str::uuid7();
        });
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }
}
