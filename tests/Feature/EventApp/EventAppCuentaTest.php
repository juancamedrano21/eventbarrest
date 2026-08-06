<?php

declare(strict_types=1);

use App\Domains\EventApp\Correo\TransporteDeCodigos;
use App\Domains\EventApp\Models\EventAppAccount;
use App\Domains\EventApp\Models\EventAppLoginCode;
use App\Domains\EventApp\Models\EventAppSession;
use App\Domains\EventManagement\VendorContext;
use App\Domains\Identity\Actions\CreateTenantUser;
use App\Domains\Identity\Enums\Role;
use App\Domains\Platform\Actions\CreateTenant;
use App\Domains\Platform\Enums\TenantType;
use App\Domains\Tenancy\TenantContext;
use App\Http\Controllers\EventApp\EventAppAccountController;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Testing\TestResponse;

/**
 * La cuenta del asistente: el primer actor de plataforma que no es el
 * superadmin. Lo que se prueba aquí son las promesas del contrato que, si se
 * rompen, no las ve ningún test de pantalla: que el 202 no es un oráculo de
 * existencia, que los fallos queman el CÓDIGO y jamás la cuenta, que solo
 * hay un código vigente por buzón, que borrar borra de verdad — y que esta
 * puerta y las del staff no se abren con las llaves de la otra.
 */
beforeEach(function (): void {
    // El transporte real es un detalle de otro slice; aquí se sustituye por
    // un espía que recuerda el último código de cada buzón. Es el mismo
    // binding que en producción ocupará el proveedor de correo — si este
    // reemplazo dejara de funcionar, también dejaría de funcionar aquel.
    $this->correo = new class implements TransporteDeCodigos
    {
        /** @var array<string, string> */
        public array $enviados = [];

        public function enviar(string $email, string $codigo): void
        {
            $this->enviados[$email] = $codigo;
        }
    };

    $this->app->instance(TransporteDeCodigos::class, $this->correo);
});

afterEach(function (): void {
    app(TenantContext::class)->clear();
    app(VendorContext::class)->clear();
});

function pedirCodigoDeEntrada(string $email): TestResponse
{
    return test()->postJson('/api/event-app/cuenta/codigo', ['email' => $email]);
}

/** El código que el «correo» dejó en el espía para ese buzón. */
function codigoQueLlegoA(string $email): string
{
    return test()->correo->enviados[$email];
}

function entrarConElCodigo(string $email, string $codigo, ?string $nombre = null): TestResponse
{
    $cuerpo = ['email' => $email, 'codigo' => $codigo];

    if ($nombre !== null) {
        $cuerpo['nombre'] = $nombre;
    }

    return test()->postJson('/api/event-app/cuenta/entrar', $cuerpo);
}

/** El flujo entero —pedir, leer el correo, canjear— y de vuelta el token. */
function sesionDelAsistente(string $email, ?string $nombre = null): string
{
    pedirCodigoDeEntrada($email)->assertStatus(202);

    $respuesta = entrarConElCodigo($email, codigoQueLlegoA($email), $nombre)->assertOk();

    return (string) $respuesta->json('token');
}

it('registers a new attendee on first entry and reuses the account on re-entry', function (): void {
    // El email se normaliza: mayúsculas y espacios del teclado del teléfono
    // no pueden fabricar una segunda cuenta del mismo buzón.
    pedirCodigoDeEntrada('  Ana@Ejemplo.com ')->assertStatus(202);

    $respuesta = entrarConElCodigo('ana@ejemplo.com', codigoQueLlegoA('ana@ejemplo.com'), 'Ana')
        ->assertOk()
        ->assertJsonStructure(['token', 'cuenta' => ['id', 'nombre', 'email']]);

    $respuesta->assertJsonPath('cuenta.nombre', 'Ana')
        ->assertJsonPath('cuenta.email', 'ana@ejemplo.com');

    expect(EventAppAccount::query()->count())->toBe(1);

    // Reingreso: mismo buzón, otro código. El nombre que venga se IGNORA —
    // cambiarse el nombre es un PATCH con sesión, no un efecto lateral de
    // teclear un código.
    $id = $respuesta->json('cuenta.id');

    pedirCodigoDeEntrada('ana@ejemplo.com')->assertStatus(202);
    $reingreso = entrarConElCodigo('ana@ejemplo.com', codigoQueLlegoA('ana@ejemplo.com'), 'Impostora')
        ->assertOk();

    $reingreso->assertJsonPath('cuenta.id', $id)
        ->assertJsonPath('cuenta.nombre', 'Ana');

    expect(EventAppAccount::query()->count())->toBe(1);
});

it('answers 202 with the exact same body whether the account exists or not', function (): void {
    EventAppAccount::query()->create(['email' => 'existe@ejemplo.com', 'name' => 'Ana']);

    $conCuenta = pedirCodigoDeEntrada('existe@ejemplo.com')->assertStatus(202);
    $sinCuenta = pedirCodigoDeEntrada('nadie@ejemplo.com')->assertStatus(202);

    // El mismo cuerpo byte a byte: un 202 no dice «te registraste» ni «ya
    // existías», dice «si ese buzón es tuyo, tienes un código».
    expect($conCuenta->json())->toBe($sinCuenta->json());
});

it('burns the code after five failures without ever touching the account', function (): void {
    EventAppAccount::query()->create(['email' => 'ana@ejemplo.com', 'name' => 'Ana']);

    pedirCodigoDeEntrada('ana@ejemplo.com')->assertStatus(202);
    $bueno = codigoQueLlegoA('ana@ejemplo.com');

    foreach (range(1, 5) as $intento) {
        entrarConElCodigo('ana@ejemplo.com', '000001')
            ->assertUnprocessable()
            ->assertJsonPath('code', 'codigo_invalido');
    }

    // El quinto fallo mató al CÓDIGO: ni siquiera el bueno lo revive, y la
    // respuesta es la misma que la del código equivocado — sin distinguir.
    entrarConElCodigo('ana@ejemplo.com', $bueno)
        ->assertUnprocessable()
        ->assertJsonPath('code', 'codigo_invalido');

    // Y la cuenta ni se enteró: no existe «cuenta bloqueada». Pedir otro
    // código es gratis y el nuevo nace entero.
    expect(EventAppAccount::query()->where('email', 'ana@ejemplo.com')->exists())->toBeTrue();

    pedirCodigoDeEntrada('ana@ejemplo.com')->assertStatus(202);
    entrarConElCodigo('ana@ejemplo.com', codigoQueLlegoA('ana@ejemplo.com'))->assertOk();
});

it('rejects an expired code with the same 422 as a wrong one', function (): void {
    pedirCodigoDeEntrada('ana@ejemplo.com')->assertStatus(202);
    $codigo = codigoQueLlegoA('ana@ejemplo.com');

    $this->travel(11)->minutes();

    entrarConElCodigo('ana@ejemplo.com', $codigo)
        ->assertUnprocessable()
        ->assertJsonPath('code', 'codigo_invalido');
});

it('keeps a single live code per mailbox: asking again kills the previous one', function (): void {
    pedirCodigoDeEntrada('ana@ejemplo.com')->assertStatus(202);
    $primero = codigoQueLlegoA('ana@ejemplo.com');

    pedirCodigoDeEntrada('ana@ejemplo.com')->assertStatus(202);
    $segundo = codigoQueLlegoA('ana@ejemplo.com');

    // Una sola fila por buzón: el índice único es quien lo garantiza.
    expect(EventAppLoginCode::query()->where('email', 'ana@ejemplo.com')->count())->toBe(1);

    entrarConElCodigo('ana@ejemplo.com', $primero)
        ->assertUnprocessable()
        ->assertJsonPath('code', 'codigo_invalido');

    entrarConElCodigo('ana@ejemplo.com', $segundo)->assertOk();
});

it('spends a code on success: the same code cannot enter twice', function (): void {
    pedirCodigoDeEntrada('ana@ejemplo.com')->assertStatus(202);
    $codigo = codigoQueLlegoA('ana@ejemplo.com');

    entrarConElCodigo('ana@ejemplo.com', $codigo)->assertOk();

    entrarConElCodigo('ana@ejemplo.com', $codigo)
        ->assertUnprocessable()
        ->assertJsonPath('code', 'codigo_invalido');
});

it('counts both failures when two wrong attempts race on the same code', function (): void {
    pedirCodigoDeEntrada('ana@ejemplo.com')->assertStatus(202);

    // Dos peticiones en vuelo: cada trabajador de php-fpm cargó SU
    // instancia de la fila antes de que ninguno escribiera. Es exactamente
    // la carrera que una tanda de adivinanzas en paralelo provoca.
    $enUnProceso = EventAppLoginCode::query()->where('email', 'ana@ejemplo.com')->firstOrFail();
    $enOtroProceso = EventAppLoginCode::query()->where('email', 'ana@ejemplo.com')->firstOrFail();

    $enUnProceso->registrarFallo();
    $enOtroProceso->registrarFallo();

    // Dos fallos tienen que costar DOS intentos. Con el valor absoluto
    // (leer, sumar en PHP, guardar) la base acababa en 1: cada tanda
    // concurrente costaba un intento y el tope de cinco se multiplicaba por
    // el número de trabajadores — la aritmética del ADR-011 dejaba de ser
    // cierta.
    expect((int) EventAppLoginCode::query()->where('email', 'ana@ejemplo.com')->value('failed_attempts'))
        ->toBe(2);
});

it('issues a single session when two redemptions race on the same good code', function (): void {
    pedirCodigoDeEntrada('ana@ejemplo.com')->assertStatus(202);

    // Dos /entrar simultáneos con el código BUENO: ambos cargaron la fila y
    // ambos pasaron la comparación antes de que ninguno gastara.
    $enUnProceso = EventAppLoginCode::query()->where('email', 'ana@ejemplo.com')->firstOrFail();
    $enOtroProceso = EventAppLoginCode::query()->where('email', 'ana@ejemplo.com')->firstOrFail();

    // El gasto es la operación que DECIDE (filas afectadas), no una lectura
    // previa: solo uno de los dos puede haber borrado la fila, y solo ese
    // emite sesión. El delete() del modelo contestaba true a los dos.
    expect($enUnProceso->gastar())->toBeTrue();
    expect($enOtroProceso->gastar())->toBeFalse();
});

it('throttles code requests per destination mailbox, never per caller', function (): void {
    // Las seis primeras pasan — con mayúsculas y espacios variados, porque
    // el cubo es del buzón NORMALIZADO: si cada variante estrenara cubo, el
    // freno se saltaría cambiando una letra.
    foreach (['ana@ejemplo.com', 'ANA@ejemplo.com', ' ana@Ejemplo.com ', 'ana@ejemplo.com', 'ana@ejemplo.com', 'ana@ejemplo.com'] as $variante) {
        pedirCodigoDeEntrada($variante)->assertStatus(202);
    }

    $frenada = pedirCodigoDeEntrada('ana@ejemplo.com')
        ->assertStatus(429)
        ->assertJsonPath('code', 'codigo_pedido_demasiado');

    // Con Retry-After en SEGUNDOS: la app lo lee para saber cuánto callar
    // antes de reintentar, en vez de adivinarlo.
    expect((int) $frenada->headers->get('Retry-After'))
        ->toBeGreaterThan(0)
        ->toBeLessThanOrEqual(3600);

    // El freno es POR DESTINO: otro buzón no comparte cubo con el frenado.
    pedirCodigoDeEntrada('otro@ejemplo.com')->assertStatus(202);

    // Y no puede dejar fuera a la persona legítima: el último código que
    // llegó al buzón frenado sigue entrando — el 429 raciona correos
    // nuevos, jamás la entrada.
    entrarConElCodigo('ana@ejemplo.com', codigoQueLlegoA('ana@ejemplo.com'))->assertOk();
});

it('keeps two legal mailboxes apart in the throttle even when only an accent differs', function (): void {
    // Seis peticiones agotan el cubo de jose@ — el buzón SIN tilde.
    foreach (range(1, 6) as $i) {
        pedirCodigoDeEntrada('jose@ejemplo.com')->assertStatus(202);
    }

    pedirCodigoDeEntrada('jose@ejemplo.com')->assertStatus(429);

    // josé@ es OTRO buzón, legal, y su dueño no pidió nada: tiene que
    // pasar. Con el email en crudo en la llave, el htmlentities del
    // RateLimiter colapsaba «josé» y «jose» al mismo cubo, y josé recibía
    // el 429 con la bandeja vacía — el único caso en que este freno negaba
    // a una persona legítima.
    pedirCodigoDeEntrada('josé@ejemplo.com')->assertStatus(202);
});

it('trips the global circuit breaker across distinct mailboxes, never the entry with a live code', function (): void {
    // Un código vivo ANTES del apagón, para probar al final la regla de la
    // casa.
    pedirCodigoDeEntrada('ana@ejemplo.com')->assertStatus(202);

    // El cubo global se llena a mano con la llave y la ventana del
    // controlador: llegar al techo con cientos de POST reales mediría lo
    // mismo, un minuto más lento.
    foreach (range(1, EventAppAccountController::EMISIONES_GLOBALES_POR_MINUTO) as $i) {
        RateLimiter::hit(EventAppAccountController::LLAVE_GLOBAL, 60);
    }

    // El techo es GLOBAL: frena buzones que jamás pidieron nada, con su
    // code propio — no es «tu buzón pidió de más», es «la puerta está
    // saturada un momento» — y con el Retry-After que la app ya lee.
    $saturada = pedirCodigoDeEntrada('recien-llegada@ejemplo.com')
        ->assertStatus(429)
        ->assertJsonPath('code', 'emision_saturada');

    expect((int) $saturada->headers->get('Retry-After'))
        ->toBeGreaterThan(0)
        ->toBeLessThanOrEqual(60);

    // Y la regla de la casa se sostiene: el cortacircuitos raciona EMITIR,
    // jamás entrar — quien ya tiene su código en el buzón entra igual.
    entrarConElCodigo('ana@ejemplo.com', codigoQueLlegoA('ana@ejemplo.com'))->assertOk();
});

it('sweeps expired codes of any mailbox on the way of issuing a new one', function (): void {
    pedirCodigoDeEntrada('olvidado@ejemplo.com')->assertStatus(202);

    // Caduca sin que nadie lo canjee: la fila queda huérfana.
    $this->travel(11)->minutes();
    expect(EventAppLoginCode::query()->where('email', 'olvidado@ejemplo.com')->exists())->toBeTrue();

    // La siguiente emisión —de OTRO buzón— la barre de camino: el
    // crecimiento de la tabla tiene dueño, y es el propio camino que la
    // engorda.
    pedirCodigoDeEntrada('nueva@ejemplo.com')->assertStatus(202);

    expect(EventAppLoginCode::query()->where('email', 'olvidado@ejemplo.com')->exists())->toBeFalse();
    expect(EventAppLoginCode::query()->where('email', 'nueva@ejemplo.com')->exists())->toBeTrue();
});

it('serves the profile and updates the name behind the token', function (): void {
    $token = sesionDelAsistente('ana@ejemplo.com', 'Ana');

    $this->getJson('/api/event-app/cuenta', ['Authorization' => "Bearer {$token}"])
        ->assertOk()
        ->assertJsonPath('cuenta.nombre', 'Ana')
        ->assertJsonPath('cuenta.email', 'ana@ejemplo.com');

    $this->patchJson('/api/event-app/cuenta', ['nombre' => 'Ana María'], ['Authorization' => "Bearer {$token}"])
        ->assertOk()
        ->assertJsonPath('cuenta.nombre', 'Ana María');

    $this->getJson('/api/event-app/cuenta', ['Authorization' => "Bearer {$token}"])
        ->assertOk()
        ->assertJsonPath('cuenta.nombre', 'Ana María');
});

it('answers 401 sesion_invalida for a revoked token, and only for that one', function (): void {
    $enUnTelefono = sesionDelAsistente('ana@ejemplo.com', 'Ana');
    $enOtroTelefono = sesionDelAsistente('ana@ejemplo.com');

    $this->postJson('/api/event-app/cuenta/salir', [], ['Authorization' => "Bearer {$enUnTelefono}"])
        ->assertNoContent();

    // El token revocado muere en la petición siguiente, con el código
    // exacto del contrato: la app lo usa para volver al estado anónimo.
    $this->getJson('/api/event-app/cuenta', ['Authorization' => "Bearer {$enUnTelefono}"])
        ->assertStatus(401)
        ->assertJsonPath('code', 'sesion_invalida');

    // Salir es un gesto del APARATO, no de la cuenta: el otro teléfono
    // sigue dentro.
    $this->getJson('/api/event-app/cuenta', ['Authorization' => "Bearer {$enOtroTelefono}"])
        ->assertOk();
});

it('deletes the account for real and kills every token with it', function (): void {
    $enUnTelefono = sesionDelAsistente('ana@ejemplo.com', 'Ana');
    $enOtroTelefono = sesionDelAsistente('ana@ejemplo.com');

    $this->deleteJson('/api/event-app/cuenta', [], ['Authorization' => "Bearer {$enUnTelefono}"])
        ->assertNoContent();

    // Borrar borra DE VERDAD: hoy nada cuelga de la cuenta, y mientras eso
    // sea cierto no hay nada que anonimizar. La fila desaparece, y con ella
    // todas las sesiones — también la del otro teléfono.
    expect(EventAppAccount::query()->count())->toBe(0);
    expect(EventAppSession::query()->count())->toBe(0);

    $this->getJson('/api/event-app/cuenta', ['Authorization' => "Bearer {$enOtroTelefono}"])
        ->assertStatus(401)
        ->assertJsonPath('code', 'sesion_invalida');
});

it('kills the live login code of the mailbox when the account is deleted', function (): void {
    $token = sesionDelAsistente('ana@ejemplo.com', 'Ana');

    // Un código pedido y NO usado, medio minuto antes de borrar.
    pedirCodigoDeEntrada('ana@ejemplo.com')->assertStatus(202);
    $huerfano = codigoQueLlegoA('ana@ejemplo.com');

    $this->deleteJson('/api/event-app/cuenta', [], ['Authorization' => "Bearer {$token}"])
        ->assertNoContent();

    // El código murió CON la cuenta: canjearlo no la resucita. Un borrado
    // con un deshacer que nadie pidió no es el borrado que exige Apple.
    entrarConElCodigo('ana@ejemplo.com', $huerfano)
        ->assertUnprocessable()
        ->assertJsonPath('code', 'codigo_invalido');

    expect(EventAppAccount::query()->count())->toBe(0);
});

it('refuses staff sanctum tokens at the attendee door', function (): void {
    $cuenta = app(CreateTenant::class)('Bocao Food Fest', null, TenantType::Organizer);
    $staff = app(CreateTenantUser::class)(
        $cuenta, 'Caro', 'caro@staff.test', 'Secreta-2026', Role::Owner, username: 'caro',
    );

    // Un token de Sanctum perfectamente válido del staff no es una sesión
    // del asistente: las dos puertas tienen tablas distintas a propósito, y
    // el rechazo es el mismo que el de un token inventado — sin contar nada.
    $token = $staff->createToken('pos')->plainTextToken;

    $this->getJson('/api/event-app/cuenta', ['Authorization' => "Bearer {$token}"])
        ->assertStatus(401)
        ->assertJsonPath('code', 'sesion_invalida');
});

it('keeps the attendee token out of the staff, pos and kds doors', function (): void {
    $token = sesionDelAsistente('ana@ejemplo.com', 'Ana');

    // La puerta del POS es auth:sanctum y este token no vive ahí.
    $this->getJson('/api/pos/bootstrap', ['Authorization' => "Bearer {$token}"])
        ->assertStatus(401);

    // La del KDS busca en kds_devices, donde tampoco existe.
    $this->getJson('/api/kds/comandas', ['Authorization' => "Bearer {$token}"])
        ->assertStatus(401);
});
