<?php

declare(strict_types=1);

use App\Domains\EventApp\Actions\GuardarTarjetaDelAsistente;
use App\Domains\EventApp\Exceptions\EventAppException;
use App\Domains\EventApp\Models\EventAppAccount;
use App\Domains\EventApp\Models\EventAppCard;
use App\Domains\EventApp\Models\EventAppSession;
use App\Domains\Payments\Actions\AnularCobro;
use App\Domains\Payments\Actions\BorrarClienteDeLaBoveda;
use App\Domains\Payments\Actions\BorrarTarjetaDeLaBoveda;
use App\Domains\Payments\Actions\BuscarTarjetaEnLaBoveda;
use App\Domains\Payments\Actions\CobrarConTarjeta;
use App\Domains\Payments\CobroSolicitado;
use App\Domains\Payments\Services\CybersourceClient;
use App\Domains\Payments\Services\MensajeDeCybersource;
use CyberSource\ApiException;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/**
 * Las tarjetas guardadas del asistente.
 *
 * Lo que se prueba aquí son las promesas que, si se rompen, no las ve ningún
 * test de pantalla y se pagan en la tarjeta de alguien: que sin
 * consentimiento no se sale a la red, que el borrado llega a la bóveda ANTES
 * que a esta base, que una fila no desaparece con el token vivo, que una
 * marca desconocida no se convierte en visa, que el desenlace incierto no se
 * disfraza de rechazo — y que una cuenta no puede tocar la tarjeta de otra.
 *
 * Cybersource se sustituye por un doble que recuerda lo que se le pidió. Se
 * sustituye SOLO EL CABLE (`enviar()`), como en CobrarConTarjetaTest: quien
 * clasifica lo que vuelve sigue siendo el código de producción, que es lo que
 * hay que probar. Las llamadas de verdad viven en EventAppTarjetasBovedaTest,
 * contra el sandbox.
 */

/**
 * La bóveda y la pasarela de mentira: lo que contestan y lo que se les pidió.
 */
final class CybersourceDeMentira
{
    /** @var array<string, array<string, mixed>> paymentInstrumentId => cuerpo del TMS */
    public array $boveda = [];

    /** @var array<string, string> paymentInstrumentId => customerTokenId */
    public array $duenoDelInstrumento = [];

    /** @var list<CobroSolicitado> */
    public array $cobros = [];

    /** @var list<string> */
    public array $instrumentosBorrados = [];

    /** @var list<string> */
    public array $clientesBorrados = [];

    /** @var list<string> */
    public array $cobrosAnulados = [];

    public string $estado = 'AUTHORIZED';

    public string $tipoDeTarjeta = '001';

    public string $ultimos4 = '1111';

    public string $venceMes = '12';

    public string $venceAno = '2031';

    public ?Throwable $cobroRevienta = null;

    public ?Throwable $borradoRevienta = null;

    public ?Throwable $borradoDelClienteRevienta = null;

    public ?Throwable $lecturaRevienta = null;

    public ?Throwable $anulacionRevienta = null;

    private int $siguiente = 0;

    /**
     * @return array{0: array<string, mixed>, 1: int}
     */
    public function cobrar(CobroSolicitado $cobro): array
    {
        $this->cobros[] = $cobro;

        if ($this->cobroRevienta !== null) {
            throw $this->cobroRevienta;
        }

        $n = ++$this->siguiente;

        if ($this->estado !== 'AUTHORIZED') {
            return [[
                'id' => "TXN-{$n}",
                'status' => $this->estado,
                'errorInformation' => [
                    'reason' => 'INVALID_ACCOUNT',
                    'message' => 'Decline - Invalid account number',
                ],
            ], 201];
        }

        $customer = "CUSTOMER-{$n}";
        $instrumento = "INSTRUMENTO-{$n}";
        $identificador = "IDENTIFICADOR-{$n}";

        $this->duenoDelInstrumento[$instrumento] = $customer;
        $this->boveda[$instrumento] = [
            'id' => $instrumento,
            'default' => true,
            'state' => 'ACTIVE',
            'card' => [
                'expirationMonth' => $this->venceMes,
                'expirationYear' => $this->venceAno,
                'type' => $this->tipoDeTarjeta,
            ],
            'instrumentIdentifier' => ['id' => $identificador],
            '_embedded' => [
                'instrumentIdentifier' => ['card' => ['number' => '411111XXXXXX'.$this->ultimos4]],
            ],
        ];

        return [[
            'id' => "TXN-{$n}",
            'status' => 'AUTHORIZED',
            'processorInformation' => ['networkTransactionId' => "RED-{$n}"],
            'paymentInformation' => ['card' => ['type' => $this->tipoDeTarjeta]],
            'tokenInformation' => [
                'customer' => ['id' => $customer],
                'paymentInstrument' => ['id' => $instrumento],
                'instrumentIdentifier' => ['id' => $identificador],
            ],
        ], 201];
    }

    /**
     * @return array<string, mixed>
     */
    public function leer(string $instrumento): array
    {
        if ($this->lecturaRevienta !== null) {
            throw $this->lecturaRevienta;
        }

        if (! array_key_exists($instrumento, $this->boveda)) {
            // El 410 que devuelve el TMS de verdad cuando el token existió y
            // ya no está. Medido contra apitest el 2026-08-07.
            throw new ApiException('Token not available', 410, [], ['errors' => [['type' => 'notAvailable']]]);
        }

        return $this->boveda[$instrumento];
    }

    public function borrarInstrumento(string $instrumento): void
    {
        if ($this->borradoRevienta !== null) {
            throw $this->borradoRevienta;
        }

        if (! array_key_exists($instrumento, $this->boveda)) {
            throw new ApiException('Token not found', 404, [], ['errors' => [['type' => 'notFound']]]);
        }

        unset($this->boveda[$instrumento]);
        $this->instrumentosBorrados[] = $instrumento;
    }

    public function borrarCliente(string $customer): void
    {
        if ($this->borradoDelClienteRevienta !== null) {
            throw $this->borradoDelClienteRevienta;
        }

        $this->clientesBorrados[] = $customer;

        foreach ($this->duenoDelInstrumento as $instrumento => $dueno) {
            if ($dueno === $customer) {
                unset($this->boveda[$instrumento]);
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function anular(string $transactionId): array
    {
        if ($this->anulacionRevienta !== null) {
            throw $this->anulacionRevienta;
        }

        $this->cobrosAnulados[] = $transactionId;

        return ['id' => 'ANULACION', 'status' => 'VOIDED'];
    }

    /** ¿Sigue estando ese token en la bóveda? */
    public function tiene(string $instrumento): bool
    {
        return array_key_exists($instrumento, $this->boveda);
    }
}

beforeEach(function (): void {
    $this->falso = new CybersourceDeMentira;
    $falso = $this->falso;

    // Solo el cable. El cliente del SDK se construye igual —no pide
    // credenciales hasta que hay que firmar— y nada de esto sale a la red.
    $this->app->instance(CobrarConTarjeta::class, new class(new CybersourceClient, $falso) extends CobrarConTarjeta
    {
        public function __construct(CybersourceClient $cliente, private readonly CybersourceDeMentira $falso)
        {
            parent::__construct($cliente);
        }

        protected function enviar(CobroSolicitado $cobro, array $cuerpo): array
        {
            return $this->falso->cobrar($cobro);
        }
    });

    $this->app->instance(BuscarTarjetaEnLaBoveda::class, new class(new CybersourceClient, $falso) extends BuscarTarjetaEnLaBoveda
    {
        public function __construct(CybersourceClient $cliente, private readonly CybersourceDeMentira $falso)
        {
            parent::__construct($cliente);
        }

        protected function enviar(string $customerTokenId, string $paymentInstrumentId): array
        {
            return $this->falso->leer($paymentInstrumentId);
        }
    });

    $this->app->instance(BorrarTarjetaDeLaBoveda::class, new class(new CybersourceClient, $falso) extends BorrarTarjetaDeLaBoveda
    {
        public function __construct(CybersourceClient $cliente, private readonly CybersourceDeMentira $falso)
        {
            parent::__construct($cliente);
        }

        protected function enviar(string $customerTokenId, string $paymentInstrumentId): void
        {
            $this->falso->borrarInstrumento($paymentInstrumentId);
        }
    });

    $this->app->instance(BorrarClienteDeLaBoveda::class, new class(new CybersourceClient, $falso) extends BorrarClienteDeLaBoveda
    {
        public function __construct(CybersourceClient $cliente, private readonly CybersourceDeMentira $falso)
        {
            parent::__construct($cliente);
        }

        protected function enviar(string $customerTokenId): void
        {
            $this->falso->borrarCliente($customerTokenId);
        }
    });

    $this->app->instance(AnularCobro::class, new class(new CybersourceClient, $falso) extends AnularCobro
    {
        public function __construct(CybersourceClient $cliente, private readonly CybersourceDeMentira $falso)
        {
            parent::__construct($cliente);
        }

        protected function enviar(string $transactionId, string $referencia): array
        {
            return $this->falso->anular($transactionId);
        }
    });
});

/** Una cuenta con su token de sesión, sin pasar por el código de entrada. */
function asistenteConSesion(string $email = 'ana@ejemplo.com'): string
{
    $cuenta = EventAppAccount::query()->create(['email' => $email, 'name' => 'Ana']);

    $claro = Str::random(64);

    EventAppSession::query()->create([
        'event_app_account_id' => $cuenta->id,
        'token_hash' => hash('sha256', $claro),
    ]);

    return $claro;
}

/**
 * @param  array<string, mixed>  $cuerpo
 */
function conToken(string $token, string $metodo, string $url, array $cuerpo = []): TestResponse
{
    return test()->json($metodo, $url, $cuerpo, ['Authorization' => "Bearer {$token}"]);
}

function guardarUnaTarjeta(string $token): TestResponse
{
    return conToken($token, 'POST', '/api/event-app/cuenta/tarjetas', [
        'transient_token' => 'el.jwt.de.la.webview',
        'consentimiento' => true,
    ]);
}

/**
 * Los dos tokens con la forma REAL que devuelve el TMS: hexadecimal en
 * mayúsculas de 32 caracteres. Copiados de una corrida contra
 * apitest.cybersource.com, porque una credencial de mentira con otra forma
 * probaría otra cosa.
 */
const CUSTOMER_COMO_EL_DE_VERDAD = '588DE8933E18D582E063AF598E0A5129';

const INSTRUMENTO_COMO_EL_DE_VERDAD = '588DE3F763A2DD45E063AF598E0A4D5C';

/**
 * El fallo tal y como lo construye el SDK: `ApiClient::callApi()` arma el
 * mensaje como «[código] Error connecting to the API ($url)», y cuando la
 * llamada es al TMS esa URL lleva DENTRO las dos piezas de la credencial.
 */
function fallaLaBoveda(int $codigo = 401): ApiException
{
    return new ApiException(
        "[{$codigo}] Error connecting to the API (https://apitest.cybersource.com/tms/v2/customers/"
        .CUSTOMER_COMO_EL_DE_VERDAD.'/payment-instruments/'.INSTRUMENTO_COMO_EL_DE_VERDAD.')',
        $codigo,
        [],
        null,
    );
}

/**
 * Todo lo que se escribió por el Log facade durante `$hacer`, aplanado a
 * texto: el mensaje, el contexto y el mensaje de cualquier excepción que
 * viaje dentro del contexto —que es como el handler de Laravel registra un
 * 500.
 */
function loQueSalioPorElLog(Closure $hacer): string
{
    $lineas = [];

    Log::listen(function (MessageLogged $evento) use (&$lineas): void {
        $texto = $evento->level.' | '.$evento->message;

        foreach ($evento->context as $clave => $valor) {
            $texto .= ' | '.$clave.'='.($valor instanceof Throwable
                ? $valor->getMessage()
                : (string) json_encode($valor, JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        }

        $lineas[] = $texto;
    });

    $hacer();

    return implode("\n", $lineas);
}

// ── El listado ──────────────────────────────────────────────────────────

it('serves an empty list of cards without calling it an error', function (): void {
    $token = asistenteConSesion();

    conToken($token, 'GET', '/api/event-app/cuenta/tarjetas')
        ->assertOk()
        ->assertJsonPath('tarjetas', [])
        ->assertJsonStructure(['tarjetas', 'server_time']);
});

it('lists saved cards in the shape the contract promises', function (): void {
    $token = asistenteConSesion();

    guardarUnaTarjeta($token)->assertCreated();

    $respuesta = conToken($token, 'GET', '/api/event-app/cuenta/tarjetas')->assertOk();

    $respuesta->assertJsonCount(1, 'tarjetas')
        ->assertJsonPath('tarjetas.0.marca', 'visa')
        ->assertJsonPath('tarjetas.0.ultimos4', '1111')
        ->assertJsonPath('tarjetas.0.vence_mes', 12)
        ->assertJsonPath('tarjetas.0.vence_ano', 2031)
        // La primera nace por defecto: una cuenta con tarjeta y ninguna
        // elegida es un estado que no le sirve a nadie.
        ->assertJsonPath('tarjetas.0.por_defecto', true)
        ->assertJsonPath('tarjetas.0.vencida', false);
});

// ── El alta ─────────────────────────────────────────────────────────────

it('saves a card and answers 201 with the same shape as the list', function (): void {
    $token = asistenteConSesion();

    guardarUnaTarjeta($token)
        ->assertCreated()
        ->assertJsonStructure([
            'tarjeta' => ['id', 'marca', 'ultimos4', 'vence_mes', 'vence_ano', 'por_defecto', 'vencida'],
            'server_time',
        ])
        ->assertJsonPath('tarjeta.marca', 'visa');

    $tarjeta = EventAppCard::query()->firstOrFail();

    expect($tarjeta->payment_instrument_id)->toBe('INSTRUMENTO-1')
        ->and($tarjeta->customer_token_id)->toBe('CUSTOMER-1')
        ->and($tarjeta->instrument_identifier_id)->toBe('IDENTIFICADOR-1');

    // Y NADA MÁS. Las columnas de esta tabla se enumeran a mano a propósito:
    // añadir una que guarde PAN, CVV o el JWT de captura —o el cuerpo entero
    // de la respuesta de Cybersource, que es lo que hace Boletu— tiene que
    // costar tocar esta lista y explicarlo. Un dato de tarjeta que se cuela
    // aquí no da error: mete a la plataforma en alcance SAQ D en silencio.
    expect(array_keys($tarjeta->getAttributes()))->toEqualCanonicalizing([
        'id',
        'event_app_account_id',
        'customer_token_id',
        'payment_instrument_id',
        'instrument_identifier_id',
        'brand',
        'last4',
        'exp_month',
        'exp_year',
        'is_default',
        // El cobro de verificación y si ya se devolvió. Son ids de nuestro
        // lado y de la transacción, no credenciales: con ellos se reconcilia,
        // no se cobra.
        'verification_reference',
        'verification_transaction_id',
        'verification_voided_at',
        'consent_at',
        'consent_version',
        'consent_ip',
        'created_at',
        'updated_at',
    ]);
});

it('translates every card brand cybersource can send, and invents none', function (): void {
    $token = asistenteConSesion();

    // Los códigos son los de la tabla de Cybersource; el `042` (Maestro
    // internacional) y el `007` (JCB) son marcas REALES que no están en el
    // vocabulario público del contrato, y por eso salen `desconocida` en vez
    // de convertirse en la más parecida.
    $esperado = [
        '001' => 'visa',
        '002' => 'mastercard',
        '003' => 'amex',
        '004' => 'discover',
        '005' => 'diners',
        '007' => 'desconocida',
        '042' => 'desconocida',
        '' => 'desconocida',
    ];

    foreach ($esperado as $codigo => $marca) {
        $this->falso->tipoDeTarjeta = (string) $codigo;

        guardarUnaTarjeta($token)
            ->assertCreated()
            ->assertJsonPath('tarjeta.marca', $marca);
    }
});

it('refuses to save without explicit consent, before touching cybersource', function (): void {
    $token = asistenteConSesion();

    // Sin el campo y con el campo en falso: las dos son lo mismo —no
    // consintió— y tienen que contestar lo mismo.
    foreach ([[], ['consentimiento' => false]] as $extra) {
        conToken($token, 'POST', '/api/event-app/cuenta/tarjetas', ['transient_token' => 'jwt'] + $extra)
            ->assertUnprocessable()
            ->assertJsonPath('code', 'consentimiento_requerido');
    }

    // Y lo que importa de verdad: NO se salió a la red. Cobrarle una
    // verificación a quien no dijo que sí y avisarle después con un 422 no
    // se arregla con el 422.
    expect($this->falso->cobros)->toBe([])
        ->and(EventAppCard::query()->count())->toBe(0);
});

it('records the consent with its moment, text version and declared ip', function (): void {
    $token = asistenteConSesion();

    guardarUnaTarjeta($token)->assertCreated();

    $tarjeta = EventAppCard::query()->firstOrFail();

    expect($tarjeta->consent_at)->not->toBeNull()
        ->and($tarjeta->consent_version)->toBe(GuardarTarjetaDelAsistente::VERSION_DEL_CONSENTIMIENTO)
        // La IP se guarda tal como la declara quien llama —con
        // trustProxies('*') la escribe él— y por eso vale como rastro, no
        // como prueba. Lo que este test fija es que se guarda ALGO.
        ->and($tarjeta->consent_ip)->not->toBeNull();
});

it('charges a token-creating verification of one peso and undoes it', function (): void {
    $token = asistenteConSesion();

    guardarUnaTarjeta($token)->assertCreated();

    $cobro = $this->falso->cobros[0];

    expect($cobro->importeCents)->toBe(GuardarTarjetaDelAsistente::IMPORTE_DE_VERIFICACION_CENTS)
        ->and($cobro->guardarTarjeta)->toBeTrue()
        ->and($cobro->transientTokenJwt)->toBe('el.jwt.de.la.webview')
        // Y se deshace: el asistente no paga por guardar una tarjeta.
        ->and($this->falso->cobrosAnulados)->toBe(['TXN-1']);
});

it('keeps an unknown card brand unknown instead of calling it visa', function (): void {
    // 042 es Maestro internacional: una marca real que no está en el
    // vocabulario público. Boletu la convertiría en visa (doc 12 §0.3) y
    // aquí eso decidiría mal la regla de encadenado del networkTransactionId.
    $this->falso->tipoDeTarjeta = '042';

    $token = asistenteConSesion();

    guardarUnaTarjeta($token)
        ->assertCreated()
        ->assertJsonPath('tarjeta.marca', 'desconocida');
});

it('answers 422 tarjeta_rechazada with a reason a person can read', function (): void {
    $this->falso->estado = 'DECLINED';

    $token = asistenteConSesion();

    guardarUnaTarjeta($token)
        ->assertUnprocessable()
        ->assertJsonPath('code', 'tarjeta_rechazada')
        // El motivo sale de errorInformation.message, que es texto para
        // humanos, y no del `reason`, que es un código crudo.
        ->assertJsonPath('motivo', 'Decline - Invalid account number');

    expect(EventAppCard::query()->count())->toBe(0);
});

it('answers 409 verificacion_incierta when the charge is uncertain, never a rejection', function (): void {
    // Un corte de transporte de verdad: el SDK lanza ApiException con código
    // 0 y sin cuerpo cuando curl no completa la llamada. Ahí la tarjeta PUEDE
    // estar cobrada, así que la respuesta no puede invitar a reintentar.
    $this->falso->cobroRevienta = new ApiException('Could not resolve host', 0, [], null);

    $token = asistenteConSesion();

    guardarUnaTarjeta($token)
        ->assertStatus(409)
        ->assertJsonPath('code', 'verificacion_incierta');

    // Y no queda fila: una tarjeta guardada con tokens que no sabemos si
    // existen sería peor que no tener tarjeta.
    expect(EventAppCard::query()->count())->toBe(0);
});

it('treats a status it does not understand as uncertain, not as a rejection', function (): void {
    // Un estado que Cybersource añada mañana no puede heredar el beneficio
    // de la duda ni por arriba (aprobar) ni por abajo (invitar a reintentar).
    $this->falso->estado = 'ALGO_NUEVO_DE_2027';

    $token = asistenteConSesion();

    guardarUnaTarjeta($token)
        ->assertStatus(409)
        ->assertJsonPath('code', 'verificacion_incierta');
});

it('still saves the card when the vault cannot be read back, with what it does know', function (): void {
    // La tarjeta ya está tokenizada y es cobrable: tirar la fila dejaría un
    // token vivo del que nadie sabría ni que existe. Se guarda con la marca
    // —que sí viene en la respuesta del cobro— y sin los dígitos.
    $this->falso->lecturaRevienta = new ApiException('Service Unavailable', 503, [], 'boom');

    $token = asistenteConSesion();

    guardarUnaTarjeta($token)
        ->assertCreated()
        ->assertJsonPath('tarjeta.marca', 'visa')
        ->assertJsonPath('tarjeta.ultimos4', null)
        ->assertJsonPath('tarjeta.vence_mes', null)
        // Sin vencimiento conocido no se puede afirmar que caducó.
        ->assertJsonPath('tarjeta.vencida', false);
});

it('calls a card expired against the current month, and never before its month ends', function (): void {
    $token = asistenteConSesion();

    // Vence este mismo mes: sigue siendo buena hasta que el mes acabe.
    $this->falso->venceMes = (string) now()->setTimezone((string) config('app.business_timezone'))->month;
    $this->falso->venceAno = (string) now()->setTimezone((string) config('app.business_timezone'))->year;

    guardarUnaTarjeta($token)
        ->assertCreated()
        ->assertJsonPath('tarjeta.vencida', false);

    $this->travel(2)->months();

    conToken($token, 'GET', '/api/event-app/cuenta/tarjetas')
        ->assertOk()
        ->assertJsonPath('tarjetas.0.vencida', true);
});

// ── La de por defecto ───────────────────────────────────────────────────

it('leaves the first card default and lets the second take the place on demand', function (): void {
    $token = asistenteConSesion();

    guardarUnaTarjeta($token)->assertCreated();
    $segunda = guardarUnaTarjeta($token)->assertCreated()->json('tarjeta.id');

    // Añadir no roba el puesto: elegir es un gesto del asistente.
    conToken($token, 'GET', '/api/event-app/cuenta/tarjetas')
        ->assertOk()
        ->assertJsonPath('tarjetas.0.por_defecto', true)
        ->assertJsonPath('tarjetas.1.por_defecto', false);

    // Y el PATCH devuelve LA LISTA ENTERA, porque marcar una desmarca otra
    // y la app tiene que reflejar las dos.
    $respuesta = conToken($token, 'PATCH', "/api/event-app/cuenta/tarjetas/{$segunda}", ['por_defecto' => true])
        ->assertOk()
        ->assertJsonCount(2, 'tarjetas');

    $respuesta->assertJsonPath('tarjetas.0.id', $segunda)
        ->assertJsonPath('tarjetas.0.por_defecto', true)
        ->assertJsonPath('tarjetas.1.por_defecto', false);

    expect(EventAppCard::query()->where('is_default', true)->count())->toBe(1);
});

// ── El borrado ──────────────────────────────────────────────────────────

it('deletes the card from the vault before deleting it here', function (): void {
    $token = asistenteConSesion();

    $id = guardarUnaTarjeta($token)->assertCreated()->json('tarjeta.id');

    conToken($token, 'DELETE', "/api/event-app/cuenta/tarjetas/{$id}")->assertNoContent();

    expect($this->falso->instrumentosBorrados)->toBe(['INSTRUMENTO-1'])
        ->and($this->falso->tiene('INSTRUMENTO-1'))->toBeFalse()
        // Y el cliente que la agrupaba, que ya no tiene ninguna tarjeta
        // nuestra: se hace explícito, sin confiar en ninguna cascada.
        ->and($this->falso->clientesBorrados)->toBe(['CUSTOMER-1'])
        ->and(EventAppCard::query()->count())->toBe(0);
});

it('keeps the row when the vault refuses to forget the card', function (): void {
    $token = asistenteConSesion();

    $id = guardarUnaTarjeta($token)->assertCreated()->json('tarjeta.id');

    // La bóveda no contesta. Aquí NO se borra: una fila que desaparece con
    // el token vivo es una tarjeta que el asistente cree haber quitado y se
    // le sigue pudiendo cobrar — y encima ya no queda dónde mirar el id.
    $this->falso->borradoRevienta = new ApiException('Service Unavailable', 503, [], 'boom');

    conToken($token, 'DELETE', "/api/event-app/cuenta/tarjetas/{$id}")->assertStatus(500);

    expect(EventAppCard::query()->count())->toBe(1)
        ->and($this->falso->tiene('INSTRUMENTO-1'))->toBeTrue();

    // Y la app la sigue viendo, que es la verdad. Reintentar es seguro.
    conToken($token, 'GET', '/api/event-app/cuenta/tarjetas')
        ->assertOk()
        ->assertJsonCount(1, 'tarjetas');

    $this->falso->borradoRevienta = null;

    conToken($token, 'DELETE', "/api/event-app/cuenta/tarjetas/{$id}")->assertNoContent();

    expect(EventAppCard::query()->count())->toBe(0);
});

it('treats a card the vault no longer has as already deleted', function (): void {
    $token = asistenteConSesion();

    $id = guardarUnaTarjeta($token)->assertCreated()->json('tarjeta.id');

    // Alguien la borró por fuera: el TMS contesta 404. Eso NO es un fallo —
    // esa credencial ya no cobra— y tratarlo como tal dejaría la fila
    // atascada para siempre, con el asistente viendo una tarjeta fantasma
    // que no consigue quitar.
    unset($this->falso->boveda['INSTRUMENTO-1']);

    conToken($token, 'DELETE', "/api/event-app/cuenta/tarjetas/{$id}")->assertNoContent();

    expect(EventAppCard::query()->count())->toBe(0);
});

it('promotes another card to default when the default one goes', function (): void {
    $token = asistenteConSesion();

    $primera = guardarUnaTarjeta($token)->assertCreated()->json('tarjeta.id');
    $segunda = guardarUnaTarjeta($token)->assertCreated()->json('tarjeta.id');

    conToken($token, 'DELETE', "/api/event-app/cuenta/tarjetas/{$primera}")->assertNoContent();

    // Una cuenta con tarjetas y ninguna elegida es un estado que no le sirve
    // a nadie: la app tendría que elegir por su cuenta y elegiría distinto.
    conToken($token, 'GET', '/api/event-app/cuenta/tarjetas')
        ->assertOk()
        ->assertJsonPath('tarjetas.0.id', $segunda)
        ->assertJsonPath('tarjetas.0.por_defecto', true);
});

// ── Borrar la cuenta ────────────────────────────────────────────────────

it('wipes every payment instrument and every customer token when the account goes', function (): void {
    $token = asistenteConSesion();

    guardarUnaTarjeta($token)->assertCreated();
    guardarUnaTarjeta($token)->assertCreated();

    conToken($token, 'DELETE', '/api/event-app/cuenta')->assertNoContent();

    // Cada instrumento, uno a uno, y después cada cliente: sin depender de
    // la cascada de Cybersource, que no está documentada.
    expect($this->falso->instrumentosBorrados)->toBe(['INSTRUMENTO-1', 'INSTRUMENTO-2'])
        ->and($this->falso->clientesBorrados)->toBe(['CUSTOMER-1', 'CUSTOMER-2'])
        ->and($this->falso->boveda)->toBe([])
        ->and(EventAppCard::query()->count())->toBe(0)
        ->and(EventAppAccount::query()->count())->toBe(0);
});

it('does not delete the account while a card is still alive in the vault', function (): void {
    $token = asistenteConSesion();

    guardarUnaTarjeta($token)->assertCreated();

    $this->falso->borradoRevienta = new ApiException('Service Unavailable', 503, [], 'boom');

    conToken($token, 'DELETE', '/api/event-app/cuenta')->assertStatus(500);

    // Quedarse sin cuenta y con la tarjeta cobrable es infinitamente peor
    // que ver un error y volver a intentarlo.
    expect(EventAppAccount::query()->count())->toBe(1)
        ->and(EventAppCard::query()->count())->toBe(1)
        ->and($this->falso->tiene('INSTRUMENTO-1'))->toBeTrue();

    $this->falso->borradoRevienta = null;

    conToken($token, 'DELETE', '/api/event-app/cuenta')->assertNoContent();

    expect(EventAppAccount::query()->count())->toBe(0)
        ->and(EventAppCard::query()->count())->toBe(0);
});

// ── Aislamiento entre asistentes ────────────────────────────────────────

it('never lets one account see, choose or delete the card of another', function (): void {
    $ana = asistenteConSesion('ana@ejemplo.com');
    $beto = asistenteConSesion('beto@ejemplo.com');

    $deAna = guardarUnaTarjeta($ana)->assertCreated()->json('tarjeta.id');
    guardarUnaTarjeta($beto)->assertCreated();

    // Ver: la de Ana no está en la lista de Beto, y son ids distintos.
    $listaDeBeto = conToken($beto, 'GET', '/api/event-app/cuenta/tarjetas')->assertOk();
    expect($listaDeBeto->json('tarjetas'))->toHaveCount(1)
        ->and($listaDeBeto->json('tarjetas.0.id'))->not->toBe($deAna);

    // Marcar la de otro: 404, el mismo que si no existiera. La respuesta no
    // puede contar si un id existe en la plataforma.
    conToken($beto, 'PATCH', "/api/event-app/cuenta/tarjetas/{$deAna}", ['por_defecto' => true])
        ->assertNotFound()
        ->assertJsonPath('code', 'tarjeta_desconocida');

    // Borrar la de otro: 404, y sin tocar la bóveda. Si esto llegara a la
    // bóveda, el 404 de después no devolvería la tarjeta de Ana.
    conToken($beto, 'DELETE', "/api/event-app/cuenta/tarjetas/{$deAna}")
        ->assertNotFound()
        ->assertJsonPath('code', 'tarjeta_desconocida');

    expect($this->falso->instrumentosBorrados)->toBe([])
        ->and($this->falso->tiene('INSTRUMENTO-1'))->toBeTrue();

    // Y la de Ana sigue entera y suya.
    conToken($ana, 'GET', '/api/event-app/cuenta/tarjetas')
        ->assertOk()
        ->assertJsonPath('tarjetas.0.id', $deAna)
        ->assertJsonPath('tarjetas.0.por_defecto', true);
});

it('does not take another account cards down when one account is deleted', function (): void {
    $ana = asistenteConSesion('ana@ejemplo.com');
    $beto = asistenteConSesion('beto@ejemplo.com');

    guardarUnaTarjeta($ana)->assertCreated();
    guardarUnaTarjeta($beto)->assertCreated();

    conToken($ana, 'DELETE', '/api/event-app/cuenta')->assertNoContent();

    expect($this->falso->instrumentosBorrados)->toBe(['INSTRUMENTO-1'])
        ->and($this->falso->tiene('INSTRUMENTO-2'))->toBeTrue();

    conToken($beto, 'GET', '/api/event-app/cuenta/tarjetas')
        ->assertOk()
        ->assertJsonCount(1, 'tarjetas');
});

it('closes the four card endpoints to anyone without a live session', function (): void {
    $token = asistenteConSesion();
    $id = guardarUnaTarjeta($token)->assertCreated()->json('tarjeta.id');

    foreach ([
        ['GET', '/api/event-app/cuenta/tarjetas', []],
        ['POST', '/api/event-app/cuenta/tarjetas', ['transient_token' => 'jwt', 'consentimiento' => true]],
        ['PATCH', "/api/event-app/cuenta/tarjetas/{$id}", ['por_defecto' => true]],
        ['DELETE', "/api/event-app/cuenta/tarjetas/{$id}", []],
    ] as [$metodo, $url, $cuerpo]) {
        $this->json($metodo, $url, $cuerpo)
            ->assertStatus(401)
            ->assertJsonPath('code', 'sesion_invalida');

        $this->json($metodo, $url, $cuerpo, ['Authorization' => 'Bearer inventado'])
            ->assertStatus(401)
            ->assertJsonPath('code', 'sesion_invalida');
    }

    // Y nada de eso llegó a la bóveda ni cambió nada.
    expect($this->falso->cobros)->toHaveCount(1)
        ->and($this->falso->instrumentosBorrados)->toBe([]);
});

// ── La credencial no sale entera por ningún lado ────────────────────────
//
// La garantía transversal dice «ninguna credencial entera en el log: ni
// customer.id, ni paymentInstrument.id…», y el mensaje del SDK la lleva dentro
// de una URL. El barrido va por los TRES caminos porque el fallo original era
// un olvido —el mismo array truncaba un token y escribía el otro entero— y un
// olvido se repite en el camino que nadie miró.

it('never writes a whole vault credential to the log when saving a card', function (): void {
    $token = asistenteConSesion();

    // El peor camino, porque NO es un error visible: la tarjeta se tokeniza,
    // la lectura del TMS falla, el alta contesta 201 y el asistente no ve
    // nada raro — con su credencial escrita en laravel.log.
    $this->falso->lecturaRevienta = fallaLaBoveda(503);

    $log = loQueSalioPorElLog(function () use ($token): void {
        guardarUnaTarjeta($token)->assertCreated();
    });

    expect($log)->not->toContain(CUSTOMER_COMO_EL_DE_VERDAD)
        ->and($log)->not->toContain(INSTRUMENTO_COMO_EL_DE_VERDAD)
        // Y no es que no se registre nada: se registra con la huella, que es
        // lo que deja reconciliar con soporte sin dejar la llave escrita.
        ->and($log)->toContain('…5129')
        ->and($log)->toContain('…4D5C');
});

it('never writes a whole vault credential to the log when deleting a card', function (): void {
    $token = asistenteConSesion();

    $id = guardarUnaTarjeta($token)->assertCreated()->json('tarjeta.id');

    $this->falso->borradoRevienta = fallaLaBoveda(401);

    $respuesta = null;

    $log = loQueSalioPorElLog(function () use ($token, $id, &$respuesta): void {
        // Con APP_DEBUG=true, que es como corre el backend hoy tras el túnel,
        // el mensaje de la excepción viaja además en el cuerpo del 500 hasta
        // el teléfono.
        config(['app.debug' => true]);

        $respuesta = conToken($token, 'DELETE', "/api/event-app/cuenta/tarjetas/{$id}")->assertStatus(500);
    });

    expect($log)->not->toContain(CUSTOMER_COMO_EL_DE_VERDAD)
        ->and($log)->not->toContain(INSTRUMENTO_COMO_EL_DE_VERDAD)
        ->and($respuesta->getContent())->not->toContain(CUSTOMER_COMO_EL_DE_VERDAD)
        ->and($respuesta->getContent())->not->toContain(INSTRUMENTO_COMO_EL_DE_VERDAD)
        // Y el fallo SÍ se registró y SÍ viajó en el cuerpo del 500: si no,
        // este barrido pasaría por no haber mirado nada.
        ->and($log)->toContain('…5129')
        ->and((string) $respuesta->json('message'))->toContain('…5129');
});

it('never writes a whole vault credential to the log when deleting the account', function (): void {
    $token = asistenteConSesion();

    guardarUnaTarjeta($token)->assertCreated();

    // El borrado del cliente es el paso BLANDO: la cuenta se borra igual y
    // contesta 204, así que aquí nadie va a mirar nunca — y sin embargo es
    // donde quedaba el customer entero escrito.
    $this->falso->borradoDelClienteRevienta = fallaLaBoveda(500);

    $log = loQueSalioPorElLog(function () use ($token): void {
        conToken($token, 'DELETE', '/api/event-app/cuenta')->assertNoContent();
    });

    expect($log)->not->toContain(CUSTOMER_COMO_EL_DE_VERDAD)
        ->and($log)->not->toContain(INSTRUMENTO_COMO_EL_DE_VERDAD)
        ->and($log)->toContain('…5129');
});

it('redacts a vault credential wherever it appears, not only inside a url', function (): void {
    // La regla por posición cubre la URL del TMS; la regla por forma cubre el
    // token que aparezca en el cuerpo de un error o en cualquier otro sitio,
    // que es de donde vino el olvido la primera vez.
    $mensaje = MensajeDeCybersource::redactado(
        'Token '.INSTRUMENTO_COMO_EL_DE_VERDAD.' not available for customer '.CUSTOMER_COMO_EL_DE_VERDAD
    );

    expect($mensaje)->toBe('Token …4D5C not available for customer …5129');

    // Lo que NO se toca: el id de transacción de un cobro no es una
    // credencial —con él no se cobra a nadie— y es lo único con lo que se
    // reconcilia un cargo atascado.
    expect(MensajeDeCybersource::redactado('[401] Error connecting to the API (https://apitest.cybersource.com/pts/v2/payments/7231234567890123456789/voids)'))
        ->toContain('7231234567890123456789');

    // Y los marcadores de las rutas que la casa escribe a mano sobreviven:
    // son el mensaje útil, no una credencial.
    expect(MensajeDeCybersource::redactado('DELETE /tms/v2/customers/{c}/payment-instruments/{pi}'))
        ->toBe('DELETE /tms/v2/customers/{c}/payment-instruments/{pi}');
});

// ── Marcar por defecto es un `set`, no un interruptor ───────────────────

it('marks as default a card that already was, and changes nothing', function (): void {
    $token = asistenteConSesion();

    $primera = guardarUnaTarjeta($token)->assertCreated()->json('tarjeta.id');
    guardarUnaTarjeta($token)->assertCreated();

    // El gesto más normal que hay: tocar la tarjeta que ya está elegida.
    // Antes esto la DESMARCABA y dejaba la cuenta con dos tarjetas y ninguna
    // por defecto — el estado que ni la app ni el servidor saben resolver, y
    // pegajoso: ni añadir otra ni borrar una lo arreglaban.
    conToken($token, 'PATCH', "/api/event-app/cuenta/tarjetas/{$primera}", ['por_defecto' => true])
        ->assertOk()
        ->assertJsonPath('tarjetas.0.id', $primera)
        ->assertJsonPath('tarjetas.0.por_defecto', true)
        ->assertJsonPath('tarjetas.1.por_defecto', false);

    // Y otra vez, porque el fallo era justo que el segundo toque sí hacía
    // algo: un endpoint que alterna en vez de fijar.
    conToken($token, 'PATCH', "/api/event-app/cuenta/tarjetas/{$primera}", ['por_defecto' => true])
        ->assertOk()
        ->assertJsonPath('tarjetas.0.id', $primera)
        ->assertJsonPath('tarjetas.0.por_defecto', true);

    expect(EventAppCard::query()->where('is_default', true)->count())->toBe(1);
});

it('keeps the only card of an account default when it is marked again', function (): void {
    $token = asistenteConSesion();

    $unica = guardarUnaTarjeta($token)->assertCreated()->json('tarjeta.id');

    conToken($token, 'PATCH', "/api/event-app/cuenta/tarjetas/{$unica}", ['por_defecto' => true])
        ->assertOk()
        ->assertJsonPath('tarjetas.0.por_defecto', true);

    expect(EventAppCard::query()->where('is_default', true)->count())->toBe(1);
});

it('refuses to unmark a default card, because no card chosen is not a state', function (): void {
    $token = asistenteConSesion();

    $id = guardarUnaTarjeta($token)->assertCreated()->json('tarjeta.id');

    // Para desmarcar una se marca otra. `false` es un cuerpo mal formado y no
    // un no-op silencioso.
    conToken($token, 'PATCH', "/api/event-app/cuenta/tarjetas/{$id}", ['por_defecto' => false])
        ->assertStatus(422);

    conToken($token, 'GET', '/api/event-app/cuenta/tarjetas')
        ->assertOk()
        ->assertJsonPath('tarjetas.0.por_defecto', true);
});

// ── El peso de la verificación deja rastro durable ──────────────────────

it('records the verification charge and marks it undone when the void goes through', function (): void {
    $token = asistenteConSesion();

    guardarUnaTarjeta($token)->assertCreated();

    $tarjeta = EventAppCard::query()->firstOrFail();

    expect($tarjeta->verification_reference)->toStartWith('EBR-TARJ-')
        ->and($tarjeta->verification_transaction_id)->toBe('TXN-1')
        ->and($tarjeta->verification_voided_at)->not->toBeNull()
        ->and(EventAppCard::query()->pendientesDeAnular()->count())->toBe(0);
});

it('leaves a durable trace of a verification charge it could not give back', function (): void {
    $token = asistenteConSesion();

    // La anulación falla: transacción ya liquidada al cruzar el corte del
    // día, TMS caído, corte de red. El alta NO falla por eso —la tarjeta
    // quedó guardada, que es lo que el asistente pidió— pero queda un cargo
    // real de RD$1 pegado a su tarjeta.
    $this->falso->anulacionRevienta = new ApiException('Invalid transaction', 400, [], 'boom');

    guardarUnaTarjeta($token)->assertCreated();

    $pendientes = EventAppCard::query()->pendientesDeAnular()->get();

    // Y el rastro es una FILA, no un Log::warning que hay que estar mirando
    // ese día: con la referencia se pregunta a /tss/v2/searches y con el id
    // de transacción se reintenta la anulación.
    expect($pendientes)->toHaveCount(1)
        ->and($pendientes->first()->verification_reference)->toStartWith('EBR-TARJ-')
        ->and($pendientes->first()->verification_transaction_id)->toBe('TXN-1');
});

// ── La pareja de la bóveda no se desempareja ────────────────────────────

it('refuses to rewrite the vault credential of a saved card', function (): void {
    $token = asistenteConSesion();

    guardarUnaTarjeta($token)->assertCreated();

    $tarjeta = EventAppCard::query()->firstOrFail();
    $tarjeta->customer_token_id = 'OTRO-CUSTOMER';

    // Con la pareja desemparejada, un 404 del TMS querría decir «ese customer
    // no existe» y el borrado lo leería como «esta tarjeta ya no está»: fila
    // borrada, token vivo y nada que lo nombre.
    expect(fn () => $tarjeta->save())
        ->toThrow(EventAppException::class);

    expect(EventAppCard::query()->firstOrFail()->customer_token_id)->toBe('CUSTOMER-1');
});

// ── Un customer compartido no se borra por la cuenta de otro ────────────

it('does not delete a shared customer token that another account still uses', function (): void {
    $ana = asistenteConSesion('ana@ejemplo.com');
    $beto = asistenteConSesion('beto@ejemplo.com');

    guardarUnaTarjeta($ana)->assertCreated();
    guardarUnaTarjeta($beto)->assertCreated();

    // Hoy cada alta estrena su propio customer, así que este estado hay que
    // fabricarlo. Mañana —doc 12 §2— será el normal: un customer por
    // asistente. Borrar el customer en el TMS deja sus instrumentos
    // inalcanzables (410, medido), así que sin esta comprobación borrar una
    // cuenta mataría la tarjeta de la otra dejándole la fila puesta.
    EventAppCard::query()->update(['customer_token_id' => 'CUSTOMER-COMPARTIDO']);

    conToken($ana, 'DELETE', '/api/event-app/cuenta')->assertNoContent();

    expect($this->falso->clientesBorrados)->toBe([])
        ->and($this->falso->tiene('INSTRUMENTO-2'))->toBeTrue();

    // Y cuando se va el último dueño, entonces sí.
    conToken($beto, 'DELETE', '/api/event-app/cuenta')->assertNoContent();

    expect($this->falso->clientesBorrados)->toBe(['CUSTOMER-COMPARTIDO']);
});
