<?php

declare(strict_types=1);

use App\Domains\Payments\EntornoDePortalDom;
use App\Domains\Payments\Exceptions\PaymentsException;

it('lets the sandbox boot anywhere', function (): void {
    EntornoDePortalDom::comprobar('local', 'test', 'apitest.cybersource.com');
    EntornoDePortalDom::comprobar('production', 'live', 'api.cybersource.com');
})->throwsNoExceptions();

it('refuses live credentials outside production', function (): void {
    EntornoDePortalDom::comprobar('local', 'live', 'api.cybersource.com');
})->throws(PaymentsException::class, 'PORTALDOM_ENV=live');

it('refuses a production host outside production even when the label says test', function (): void {
    // El agujero: `PORTALDOM_ENV` es una etiqueta y `PORTALDOM_API_HOST` es la
    // variable que decide a dónde va el dinero. Con esta combinación todos los
    // seguros que miran la etiqueta daban luz verde mientras los cobros salían
    // contra producción — incluido el modo PAN, que es alcance SAQ D.
    EntornoDePortalDom::comprobar('local', 'test', 'api.cybersource.com');
})->throws(PaymentsException::class, 'PORTALDOM_API_HOST=api.cybersource.com');

it('refuses a label and a host that contradict each other', function (string $env, string $host): void {
    EntornoDePortalDom::comprobar('production', $env, $host);
})->with([
    'live pointing at the sandbox' => ['live', 'apitest.cybersource.com'],
    'test pointing at production' => ['test', 'api.cybersource.com'],
])->throws(PaymentsException::class);

it('treats a host it does not recognise as production, never as the sandbox', function (): void {
    // El defecto seguro: dar por sandbox lo desconocido es lo que dejaría
    // salir un PAN en claro contra un host de verdad.
    expect(EntornoDePortalDom::esHostDeSandbox('api.cybersource.com'))->toBeFalse()
        ->and(EntornoDePortalDom::esHostDeSandbox('proxy-interno.example'))->toBeFalse()
        ->and(EntornoDePortalDom::esHostDeSandbox(''))->toBeFalse()
        ->and(EntornoDePortalDom::esHostDeSandbox(null))->toBeFalse()
        ->and(EntornoDePortalDom::esHostDeSandbox('APITEST.CYBERSOURCE.COM'))->toBeTrue();
});

it('derives the host from the label when nobody sets it', function (): void {
    expect(EntornoDePortalDom::hostPorDefecto('test'))->toBe('apitest.cybersource.com')
        ->and(EntornoDePortalDom::hostPorDefecto('live'))->toBe('api.cybersource.com')
        ->and(EntornoDePortalDom::hostPorDefecto(null))->toBe('apitest.cybersource.com');
});
