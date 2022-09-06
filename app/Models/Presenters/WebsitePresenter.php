<?php

namespace App\Models\Presenters;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Support\Str;

trait WebsitePresenter
{
    /**
     * Return name lowercase
     *
     * @return \Illuminate\Database\Eloquent\Casts\Attribute
     */
    public function name(): Attribute
    {
        return Attribute::make(
            get: fn ($name) => Str::lower($name),
        );
    }
}
