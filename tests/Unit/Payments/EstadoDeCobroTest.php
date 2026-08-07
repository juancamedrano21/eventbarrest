<?php

declare(strict_types=1);

use App\Domains\Payments\Enums\DesenlaceDeCobro;
use App\Domains\Payments\Enums\EstadoDeCobro;

it('treats only AUTHORIZED as approved', function (): void {
    $aprobados = array_values(array_filter(
        EstadoDeCobro::cases(),
        fn (EstadoDeCobro $estado): bool => $estado->esAprobado(),
    ));

    expect($aprobados)->toBe([EstadoDeCobro::Autorizado]);
});

it('declines a risk-declined charge even when the processor said 00', function (): void {
    // El caso que cuesta dinero: el emisor aprobó, Decision Manager rechazó, y
    // el cuerpo trae responseCode "00" con código de aprobación válido. Quien
    // lea el código en vez del estado despacha comida que nadie pagó.
    $estado = EstadoDeCobro::desde('AUTHORIZED_RISK_DECLINED');

    expect($estado)->toBe(EstadoDeCobro::RechazadoPorRiesgo)
        ->and($estado->esAprobado())->toBeFalse()
        ->and($estado->esRechazado())->toBeTrue()
        ->and($estado->desenlace())->toBe(DesenlaceDeCobro::Rechazado);
});

it('maps every status cybersource documents for a payment', function (string $status, DesenlaceDeCobro $desenlace): void {
    expect(EstadoDeCobro::desde($status)->desenlace())->toBe($desenlace);
})->with([
    ['AUTHORIZED', DesenlaceDeCobro::Aprobado],
    ['PARTIAL_AUTHORIZED', DesenlaceDeCobro::Pendiente],
    ['AUTHORIZED_PENDING_REVIEW', DesenlaceDeCobro::Pendiente],
    ['PENDING_AUTHENTICATION', DesenlaceDeCobro::Pendiente],
    ['PENDING_REVIEW', DesenlaceDeCobro::Pendiente],
    ['DECLINED', DesenlaceDeCobro::Rechazado],
    ['AUTHORIZED_RISK_DECLINED', DesenlaceDeCobro::Rechazado],
    ['INVALID_REQUEST', DesenlaceDeCobro::Error],
]);

it('keeps "no answer" apart from every answer', function (): void {
    // La distinción cara: un rechazo es una respuesta —no se cobró, reintenta
    // tranquilo— y el silencio es la duda —puede que la tarjeta esté cobrada—.
    // Si los dos contestaran igual a alguna de estas preguntas, quien llama
    // podría confundirlos, que es como se llega al doble cobro.
    $silencio = EstadoDeCobro::SinRespuesta;

    expect($silencio->desenlace())->toBe(DesenlaceDeCobro::Incierto)
        ->and($silencio->esIncierto())->toBeTrue()
        ->and($silencio->esAprobado())->toBeFalse()
        ->and($silencio->esRechazado())->toBeFalse()
        ->and($silencio->esPendiente())->toBeFalse()
        ->and(EstadoDeCobro::Rechazado->esIncierto())->toBeFalse();
});

it('is the only status a body can never produce', function (): void {
    // El sentinel lo pone quien hizo la llamada, porque es el único que sabe
    // que hubo silencio. Un cuerpo con esa cadena literal sería un estado
    // ajeno que no conocemos, o sea `Desconocido`.
    expect(EstadoDeCobro::desde('SIN_RESPUESTA'))->toBe(EstadoDeCobro::Desconocido)
        ->and(EstadoDeCobro::desde('SIN_RESPUESTA')->esIncierto())->toBeFalse();
});

it('never approves a status it does not know', function (mixed $status): void {
    $estado = EstadoDeCobro::desde($status);

    expect($estado)->toBe(EstadoDeCobro::Desconocido)
        ->and($estado->esAprobado())->toBeFalse()
        ->and($estado->desenlace())->toBe(DesenlaceDeCobro::Error);
})->with([
    'a status invented tomorrow' => 'AUTHORIZED_SOMETHING_NEW',
    'lowercase' => 'authorized',
    'missing' => null,
    'not a string' => 1,
]);
