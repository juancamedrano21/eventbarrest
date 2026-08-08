<?php

declare(strict_types=1);

use App\Domains\Payments\Actions\AnularCobro;
use App\Domains\Payments\Actions\BorrarClienteDeLaBoveda;
use App\Domains\Payments\Actions\BorrarTarjetaDeLaBoveda;
use App\Domains\Payments\Actions\BuscarTarjetaEnLaBoveda;
use App\Domains\Payments\Actions\CobrarConTarjeta;
use App\Domains\Payments\CobroSolicitado;
use App\Domains\Payments\Enums\MarcaDeTarjeta;
use App\Domains\Payments\ResultadoDeCobro;
use App\Domains\Payments\Services\CybersourceClient;
use Illuminate\Support\Str;

/**
 * La bóveda de tokens (TMS) contra el SANDBOX REAL de Cybersource.
 *
 * Estas pruebas existen porque el borrado de una tarjeta es lo único de este
 * slice que no se puede probar con un doble sin probarse a sí mismo: lo que
 * hay que demostrar es que el token DEJA DE EXISTIR en Cybersource, y eso
 * solo lo sabe Cybersource.
 *
 * SE SALTAN SOLAS sin credenciales, como el resto de las de sandbox: una
 * suite que exige las credenciales de un integrador de pagos es una suite que
 * no puede correr nadie más. Y se saltan también fuera del entorno de
 * pruebas, porque estas llamadas cobran de verdad.
 */
beforeEach(function (): void {
    if (! CybersourceClient::hayCredenciales()) {
        $this->markTestSkipped('Sin credenciales de PortalDOM: define PORTALDOM_ORG_ID, PORTALDOM_KEY_ID y PORTALDOM_SHARED_SECRET.');
    }

    if (! app(CybersourceClient::class)->esSandbox()) {
        $this->markTestSkipped('PORTALDOM_ENV no es de pruebas: estas llamadas cobran de verdad.');
    }
});

/** La Visa de prueba que documenta Cybersource para el sandbox. */
function tarjetaParaLaBoveda(): array
{
    return [
        'number' => '4111111111111111',
        'exp_month' => '12',
        'exp_year' => '2031',
        'cvv' => '123',
        'type' => '001',
    ];
}

function facturacionParaLaBoveda(): array
{
    return [
        'firstName' => 'Juan',
        'lastName' => 'Perez',
        'address1' => 'Av. John F. Kennedy 1',
        'locality' => 'Santo Domingo',
        'administrativeArea' => 'Distrito Nacional',
        'postalCode' => '10100',
        'country' => 'DO',
        'email' => 'pruebas@eventbarrest.test',
        'phoneNumber' => '8095550100',
    ];
}

function referenciaParaLaBoveda(): string
{
    return 'EBR-TARJ-'.Str::upper(Str::random(10));
}

/**
 * Tokeniza la Visa de prueba y devuelve el resultado del cobro.
 *
 * Va por el modo PAN DE SANDBOX porque la captura real (Unified Checkout en
 * webview, cuarto slice) todavía no existe y sin ella no hay
 * `transientTokenJwt` que mandar. Lo que se prueba aquí no es de dónde salió
 * la tarjeta sino qué pasa con el token una vez existe, y eso es idéntico por
 * los dos caminos.
 */
function tokenizarEnElSandbox(int $importeCents = 100): ResultadoDeCobro
{
    return app(CobrarConTarjeta::class)(
        CobroSolicitado::conPanDeSandbox(
            referencia: referenciaParaLaBoveda(),
            importeCents: $importeCents,
            tarjeta: tarjetaParaLaBoveda(),
            idempotencyKey: (string) Str::uuid(),
            facturacion: facturacionParaLaBoveda(),
            guardarTarjeta: true,
        )
    );
}

test('a tokenised card is read back from the vault and then deleted for good', function (): void {
    $alta = tokenizarEnElSandbox();

    expect($alta->esAprobado())->toBeTrue()
        ->and($alta->customerTokenId)->not->toBeNull()
        ->and($alta->paymentInstrumentId)->not->toBeNull();

    $customer = (string) $alta->customerTokenId;
    $instrumento = (string) $alta->paymentInstrumentId;

    // 1. La bóveda sabe pintar la tarjeta. Es de aquí de donde salen los
    //    cuatro dígitos y el vencimiento: la respuesta del cobro NO los trae.
    $tarjeta = app(BuscarTarjetaEnLaBoveda::class)($customer, $instrumento);

    fwrite(STDERR, "\n[sandbox] tarjeta en la bóveda → ".json_encode([
        'marca' => $tarjeta?->marca->value,
        'ultimos4' => $tarjeta?->ultimos4,
        'vence' => $tarjeta?->venceMes.'/'.$tarjeta?->venceAno,
    ])."\n");

    expect($tarjeta)->not->toBeNull()
        ->and($tarjeta?->marca)->toBe(MarcaDeTarjeta::Visa)
        ->and($tarjeta?->ultimos4)->toBe('1111')
        ->and($tarjeta?->venceMes)->toBe(12)
        ->and($tarjeta?->venceAno)->toBe(2031)
        ->and($tarjeta?->instrumentIdentifierId)->not->toBeNull();

    // 2. Y ahora el borrado DE VERDAD, que es lo que este fichero existe para
    //    demostrar: una fila local que se borra sin que el token muera es una
    //    tarjeta que el asistente cree haber quitado y se le sigue cobrando.
    app(BorrarTarjetaDeLaBoveda::class)($customer, $instrumento);

    $despues = app(BuscarTarjetaEnLaBoveda::class)($customer, $instrumento);

    fwrite(STDERR, '[sandbox] tras borrar el instrumento → '.($despues === null ? 'YA NO ESTÁ' : 'SIGUE AHÍ')."\n");

    expect($despues)->toBeNull();

    // 3. Y el cliente que la agrupaba, explícitamente. Borrar el customer
    //    parece arrastrar sus instrumentos, pero eso NO está documentado y
    //    este slice no construye encima de una observación.
    app(BorrarClienteDeLaBoveda::class)($customer);

    fwrite(STDERR, "[sandbox] cliente borrado\n");
})->group('cybersource');

test('deleting a card the vault never had counts as already gone', function (): void {
    // La otra mitad del borrado, y la que decide si una fila local se puede
    // quitar: «ya no está» tiene que ser éxito. Si fuera error, un token que
    // alguien borró por fuera dejaría la fila atascada para siempre y el
    // asistente vería una tarjeta fantasma que no consigue quitar.
    //
    // Son DOS códigos y no uno, y aquí se prueba el 404 (id que nunca
    // existió); el 410 (existió y se borró) lo prueba el test de arriba, que
    // vuelve a preguntar por el instrumento ya borrado.
    $alta = tokenizarEnElSandbox();

    $customer = (string) $alta->customerTokenId;

    app(BorrarTarjetaDeLaBoveda::class)($customer, 'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA');

    fwrite(STDERR, "\n[sandbox] borrar un instrumento inexistente → sin error\n");

    // Limpieza: el cobro de esta prueba dejó una tarjeta de verdad.
    app(BorrarTarjetaDeLaBoveda::class)($customer, (string) $alta->paymentInstrumentId);
    app(BorrarClienteDeLaBoveda::class)($customer);

    expect(app(BuscarTarjetaEnLaBoveda::class)($customer, (string) $alta->paymentInstrumentId))->toBeNull();
})->group('cybersource');

test('the verification charge of a card alta is undone right after tokenising', function (): void {
    // El alta cobra porque Cybersource tokeniza DENTRO de una autorización.
    // Ese peso se devuelve, y esto lo comprueba contra el sandbox: sin la
    // anulación, guardar una tarjeta le costaría dinero al asistente.
    $referencia = referenciaParaLaBoveda();

    $alta = app(CobrarConTarjeta::class)(
        CobroSolicitado::conPanDeSandbox(
            referencia: $referencia,
            importeCents: 100,
            tarjeta: tarjetaParaLaBoveda(),
            idempotencyKey: (string) Str::uuid(),
            facturacion: facturacionParaLaBoveda(),
            guardarTarjeta: true,
        )
    );

    expect($alta->esAprobado())->toBeTrue();

    app(AnularCobro::class)((string) $alta->transactionId, $referencia);

    fwrite(STDERR, "\n[sandbox] cobro de verificación anulado → txn {$alta->transactionId}\n");

    // Limpieza de la bóveda: esta prueba también tokenizó.
    app(BorrarTarjetaDeLaBoveda::class)((string) $alta->customerTokenId, (string) $alta->paymentInstrumentId);
    app(BorrarClienteDeLaBoveda::class)((string) $alta->customerTokenId);
})->group('cybersource');
