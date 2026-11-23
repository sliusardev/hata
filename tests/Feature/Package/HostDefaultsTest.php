<?php

use Illuminate\Support\Carbon;
use Illuminate\Validation\Rules\Password;

it('does not change the host date class', function () {
    expect(now())->toBeInstanceOf(Carbon::class);
});

it('does not set password defaults', function () {
    expect((new ReflectionProperty(Password::class, 'defaultCallback'))->getValue())->toBeNull()
        ->and(Password::default())->toEqual(Password::min(8));
});
