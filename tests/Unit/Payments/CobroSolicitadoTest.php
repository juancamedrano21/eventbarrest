<?php

declare(strict_types=1);

use App\Domains\Payments\CobroSolicitado;
use App\Domains\Payments\Enums\ModoDeCobro;
use App\Domains\Payments\Exceptions\PaymentsException;

it('formats cents as two decimals with a dot', function (int $cents, string $esperado): void {
    $cobro = CobroSolicitado::conTarjetaNueva('REF-1', $cents, 'jwt', 'llave');

    expect($cobro->importeFormateado())->toBe($esperado);
})->with([
    'one cent' => [1, '0.01'],
    'under a peso' => [45, '0.45'],
    'exact peso' => [100, '1.00'],
    'ten cents, not one' => [110, '1.10'],
    'a beer' => [25_000, '250.00'],
    // 8014.35 en float es 8014.349999… — con number_format sobre el
    // resultado de una división esto es donde aparece el céntimo perdido.
    'an amount that floats badly' => [801_435, '8014.35'],
    'large' => [123_456_789, '1234567.89'],
]);

it('refuses an amount that is not a positive integer of cents', function (int $cents): void {
    CobroSolicitado::conTarjetaNueva('REF-1', $cents, 'jwt', 'llave');
})->with([0, -1, -25_000])->throws(PaymentsException::class);

it('refuses a charge without a reference to reconcile against', function (): void {
    CobroSolicitado::conTarjetaNueva('   ', 1_000, 'jwt', 'llave');
})->throws(PaymentsException::class, 'referencia');

it('refuses an idempotency key cybersource would truncate', function (string $llave): void {
    CobroSolicitado::conTarjetaNueva('REF-1', 1_000, 'jwt', $llave);
})->with([
    'empty' => '',
    'over 64 chars' => str_repeat('x', 65),
])->throws(PaymentsException::class);

// Una credencial en blanco NO se queda fuera del cuerpo: sale como cadena
// vacía, y Cybersource rechaza el campo vacío en vez de ignorarlo (lección 7
// de §0.2). El filtro `sinVacios()` de la acción solo cubre el bloque `card`,
// así que estas pasaban enteras y volvían como un INVALID_REQUEST que se podía
// haber evitado sin gastar una ida a la red.

it('refuses a stored card charge with a blank customer token', function (string $token): void {
    CobroSolicitado::conTarjetaGuardada('REF-1', 1_000, $token, 'llave');
})->with(['empty' => '', 'spaces' => '   '])->throws(PaymentsException::class, 'vacía');

it('refuses a new card charge with a blank transient jwt', function (string $jwt): void {
    CobroSolicitado::conTarjetaNueva('REF-1', 1_000, $jwt, 'llave');
})->with(['empty' => '', 'tab' => "\t"])->throws(PaymentsException::class, 'vacía');

it('refuses a payment instrument that was passed but is blank', function (): void {
    CobroSolicitado::conTarjetaGuardada('REF-1', 1_000, 'CUSTOMER-TOKEN', 'llave', '');
})->throws(PaymentsException::class, 'vacía');

it('still accepts a stored card without a payment instrument', function (): void {
    // El instrumento es opcional de verdad: se cobra solo con el customer
    // cuando el asistente tiene una sola tarjeta.
    $cobro = CobroSolicitado::conTarjetaGuardada('REF-1', 1_000, 'CUSTOMER-TOKEN', 'llave');

    expect($cobro->paymentInstrumentId)->toBeNull();
});

it('asks for tokenisation only on the first charge', function (): void {
    $nueva = CobroSolicitado::conTarjetaNueva('REF-1', 1_000, 'jwt', 'llave');
    $guardada = CobroSolicitado::conTarjetaGuardada('REF-2', 1_000, 'token', 'llave');

    expect($nueva->modo)->toBe(ModoDeCobro::TarjetaNueva)
        ->and($nueva->guardarTarjeta)->toBeTrue()
        ->and($guardada->modo)->toBe(ModoDeCobro::TarjetaGuardada)
        ->and($guardada->guardarTarjeta)->toBeFalse();
});
