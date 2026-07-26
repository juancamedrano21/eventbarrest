<?php

declare(strict_types=1);

use App\Domains\Platform\Rules\ValidRnc;

function rncErrors(mixed $value): array
{
    $errors = [];
    (new ValidRnc)->validate('rnc', $value, function (string $message) use (&$errors): void {
        $errors[] = $message;
    });

    return $errors;
}

test('accepts a 9 digit RNC', function (): void {
    expect(rncErrors('131246809'))->toBeEmpty();
});

test('accepts an 11 digit cedula', function (): void {
    expect(rncErrors('00112345678'))->toBeEmpty();
});

test('accepts dashes and spaces as input formatting', function (): void {
    expect(rncErrors('1-31-24680-9'))->toBeEmpty()
        ->and(rncErrors('001 1234567 8'))->toBeEmpty();
});

test('rejects wrong lengths', function (string $value): void {
    expect(rncErrors($value))->not->toBeEmpty();
})->with(['12345678', '1234567890', '123456789012', '']);

test('rejects non numeric input', function (): void {
    expect(rncErrors('13124680A'))->not->toBeEmpty()
        ->and(rncErrors(12345))->not->toBeEmpty();
});

test('normalize strips formatting', function (): void {
    expect(ValidRnc::normalize('1-31-24680 9'))->toBe('131246809');
});
