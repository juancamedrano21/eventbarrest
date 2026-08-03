<?php

declare(strict_types=1);

use App\Domains\Catalog\Enums\DispatchArea;
use App\Domains\Catalog\Enums\ProductType;
use App\Domains\Catalog\Models\Category;
use App\Domains\Catalog\Models\Product;
use App\Domains\EventManagement\Actions\CreateEvent;
use App\Domains\EventManagement\Actions\CreateVendor;
use App\Domains\EventManagement\Actions\InviteVendorToEvent;
use App\Domains\EventManagement\Enums\EventStatus;
use App\Domains\EventManagement\Models\EventOutlet;
use App\Domains\EventManagement\VendorContext;
use App\Domains\Kitchen\Actions\EnrollKdsDevice;
use App\Domains\Kitchen\Actions\RevokeKdsDevice;
use App\Domains\Kitchen\Actions\RotateOutletKdsPin;
use App\Domains\Kitchen\EnrolledDevice;
use App\Domains\Kitchen\Exceptions\KitchenException;
use App\Domains\Kitchen\Models\KdsDevice;
use App\Domains\Kitchen\Models\KitchenTicket;
use App\Domains\Operations\Enums\OperatingUnitKind;
use App\Domains\Platform\Actions\CreateTenant;
use App\Domains\Platform\Enums\TenantType;
use App\Domains\Sales\Actions\OpenCashSession;
use App\Domains\Sales\Actions\PayOrder;
use App\Domains\Sales\Actions\PlaceOrder;
use App\Domains\Sales\Enums\PaymentMethod;
use App\Domains\Sales\Models\Order;
use App\Domains\Tenancy\TenantContext;

/**
 * Que la misma tablet no se duplique.
 *
 * El destrozo que esto persigue es real y está en la base de pruebas: seis
 * filas «Cocina 1» en el mismo puesto, todas la misma Galaxy Tab, una por cada
 * vez que perdió su token o alguien la descolgó y la volvió a colgar. Con la
 * batería en el panel se ve peor todavía —cinco pantallas fantasma con una
 * lectura congelada de hace horas— y el organizador no sabe cuál mirar.
 *
 * Lo que se fija aquí son las dos mitades del arreglo. Que reconocer al
 * aparato reutilice SU fila, con su rastro de qué comandas despachó. Y que
 * reconocerlo no abra NADA: la identidad es una etiqueta, no una credencial,
 * y quien la presenta sigue teniendo que teclear el código del comercio y el
 * PIN del puesto igual que la primera vez.
 */
beforeEach(function (): void {
    $this->organizer = app(CreateTenant::class)('Bocao Food Fest', null, TenantType::Organizer);

    app(TenantContext::class)->runAs($this->organizer, function (): void {
        $evento = app(CreateEvent::class)(
            'Bocao 2026', now()->subDay(), now()->addDay(), null, EventStatus::Active,
        );

        $this->tacos = app(CreateVendor::class)('Tacos del Puerto');
        app(InviteVendorToEvent::class)($evento, $this->tacos, 1000);

        $this->norte = outletFor($evento, 'Puesto Norte', OperatingUnitKind::Mixed, $this->tacos);
        // El segundo puesto del MISMO comercio: es donde se ve que una tablet
        // física está en un sitio a la vez.
        $this->este = outletFor($evento, 'Puesto Este', OperatingUnitKind::Kitchen, $this->tacos);

        $this->pinNorte = app(RotateOutletKdsPin::class)($this->norte);
        $this->pinEste = app(RotateOutletKdsPin::class)($this->este);

        app(VendorContext::class)->runAs($this->tacos, function (): void {
            $cocina = Category::create(['name' => 'Comida', 'dispatch' => DispatchArea::Kitchen]);

            $this->taco = Product::create([
                'category_id' => $cocina->id, 'name' => 'Taco al pastor',
                'type' => ProductType::Simple, 'price_cents' => 25000,
            ]);
        });
    });

    // La cadena que devuelve el puente del APK: dieciséis hex, opaca y
    // estable. No es secreta —el aparato se la da a quien la pida— y por eso
    // no abre ninguna puerta por sí sola.
    $this->galaxyTab = 'a1b2c3d4e5f6a7b8';

    $this->cajas = [];
});

afterEach(function (): void {
    app(TenantContext::class)->clear();
    app(VendorContext::class)->clear();
});

/** Un alta tal como llega de la tablet, con o sin identidad. */
function colgarTablet(
    string $pin,
    string $nombre = 'Cocina 1',
    ?string $identidad = null,
    ?DispatchArea $area = null,
): EnrolledDevice {
    return app(EnrollKdsDevice::class)(
        (string) test()->tacos->kds_code, $pin, $nombre, $area, $identidad,
    );
}

/** Ejecuta lo que debe fallar y devuelve el fallo, para poder mirarlo por dentro. */
function rechazoDeIdentidad(Closure $accion): KitchenException
{
    try {
        $accion();
    } catch (KitchenException $e) {
        return $e;
    }

    throw new RuntimeException('Se esperaba un KitchenException y la llamada pasó sin rechistar.');
}

/** Una venta cobrada en un puesto: solo lo cobrado llega a la cocina. */
function ventaConComanda(EventOutlet $puesto): Order
{
    return app(TenantContext::class)->runAs(
        test()->organizer,
        fn (): Order => app(VendorContext::class)->runAs(test()->tacos, function () use ($puesto): Order {
            $cajas = test()->cajas;
            $cajas[$puesto->id] ??= app(OpenCashSession::class)($puesto, null, 0);
            test()->cajas = $cajas;

            $orden = app(PlaceOrder::class)(
                $cajas[$puesto->id],
                [['product_id' => test()->taco->id, 'quantity' => 1]],
                'ident-'.$puesto->id,
            );

            return app(PayOrder::class)($orden, PaymentMethod::Cash, $orden->total_cents);
        }),
    );
}

it('gives the same tablet its own row back instead of a second one', function (): void {
    $primera = colgarTablet($this->pinNorte, 'Cocina 1', $this->galaxyTab);

    // Lo que pasa de verdad en el puesto: la tablet perdió el token y alguien
    // vuelve a teclear el código y el PIN. Hasta hoy, esto era la fila número
    // dos de las seis.
    $segunda = colgarTablet($this->pinNorte, 'Cocina 1', $this->galaxyTab);

    expect(KdsDevice::query()->withoutTenancy()->count())->toBe(1);
    expect($segunda->device->id)->toBe($primera->device->id);

    // Y el token de la primera deja de valer en el acto: es el mismo campo,
    // así que quien se lo hubiera copiado tampoco entra con él.
    expect($segunda->plainToken)->not->toBe($primera->plainToken);
    expect($segunda->device->token_hash)->toBe(hash('sha256', $segunda->plainToken));

    $primera->device->refresh();
    expect($primera->device->token_hash)->not->toBe(hash('sha256', $primera->plainToken));
});

it('brings a revoked tablet back to life on its own row', function (): void {
    $primera = colgarTablet($this->pinNorte, 'Cocina 1', $this->galaxyTab);

    app(TenantContext::class)->runAs($this->organizer, function () use ($primera): void {
        app(RevokeKdsDevice::class)($primera->device);
    });

    // Revocar apaga un token, no veta un aparato: quien vuelve teclea el
    // código y el PIN igual que la primera vez. Si la revocación fuese un veto
    // haría falta un botón de «desvetar» en el panel para el día que alguien
    // revoque la tablet equivocada en pleno servicio.
    $vuelta = colgarTablet($this->pinNorte, 'Cocina 1 (la de siempre)', $this->galaxyTab);

    expect($vuelta->device->id)->toBe($primera->device->id);
    expect($vuelta->device->revoked_at)->toBeNull();
    // El nombre y el área son los que se acaban de teclear.
    expect($vuelta->device->name)->toBe('Cocina 1 (la de siempre)');
    expect(KdsDevice::query()->withoutTenancy()->count())->toBe(1);
});

it('shuts the same tablet down at the outlet it left', function (): void {
    $enElNorte = colgarTablet($this->pinNorte, 'Cocina 1', $this->galaxyTab);

    // La misma tablet, descolgada y llevada al puesto de al lado.
    $enElEste = app(EnrollKdsDevice::class)(
        (string) $this->tacos->kds_code, $this->pinEste, 'Cocina 1', null, $this->galaxyTab,
    );

    // Dos filas, porque el rastro de lo que despachó en el norte es del norte
    // y no puede mudarse con el aparato.
    expect(KdsDevice::query()->withoutTenancy()->count())->toBe(2);
    expect($enElEste->device->id)->not->toBe($enElNorte->device->id);

    // Pero solo una viva: un aparato está en un sitio a la vez, y dejar viva
    // la del norte sería mantener abierta la puerta de un puesto donde esa
    // tablet ya no está.
    $enElNorte->device->refresh();
    expect($enElNorte->device->estaRevocada())->toBeTrue();
    expect($enElEste->device->estaRevocada())->toBeFalse();
});

it('still creates a row per enrolment when no identity comes in', function (): void {
    // La misma pantalla abierta en un navegador cualquiera: no hay puente y
    // no hay con qué distinguirse. Se enrola como siempre.
    $una = colgarTablet($this->pinNorte, 'Cocina 1');
    $otra = colgarTablet($this->pinNorte, 'Cocina 1');

    expect(KdsDevice::query()->withoutTenancy()->count())->toBe(2);
    expect($otra->device->id)->not->toBe($una->device->id);

    // El hueco es honesto y se guarda como hueco: dos NULL no chocan en el
    // índice único, que es lo que deja convivir a las tabletas sin identidad.
    expect($una->device->device_identity)->toBeNull();
    expect($otra->device->device_identity)->toBeNull();
});

it('keeps two different tablets in the same outlet apart', function (): void {
    $una = colgarTablet($this->pinNorte, 'Cocina 1', 'a1b2c3d4e5f6a7b8');
    $otra = colgarTablet($this->pinNorte, 'Cocina 2', '0f0e0d0c0b0a0908');

    expect(KdsDevice::query()->withoutTenancy()->count())->toBe(2);
    expect($otra->device->id)->not->toBe($una->device->id);

    // Y ninguna apaga a la otra: están en el mismo puesto, que es donde
    // tienen que estar las dos.
    $una->device->refresh();
    expect($una->device->estaRevocada())->toBeFalse();
});

it('refuses a known identity when the pin is wrong', function (): void {
    $legitima = colgarTablet($this->pinNorte, 'Cocina 1', $this->galaxyTab);

    // Lo que hace el vecino: se aprende la identidad de la tablet de al lado
    // —dieciséis caracteres que el propio aparato reparte— y prueba con ella.
    $error = rechazoDeIdentidad(fn () => colgarTablet('000000', 'Cocina 1', $this->galaxyTab));

    expect($error->errorCode)->toBe('kds_enrollment_rejected');

    // No entró, no le dieron token y no tocó la fila de nadie: la identidad
    // se mira DESPUÉS del PIN, nunca en su lugar. El día que valga por sí
    // sola, entrar en un puesto ajeno cuesta averiguar una cadena que no es
    // secreta.
    expect(KdsDevice::query()->withoutTenancy()->count())->toBe(1);

    $legitima->device->refresh();
    expect($legitima->device->estaRevocada())->toBeFalse();
    expect($legitima->device->token_hash)->toBe(hash('sha256', $legitima->plainToken));
});

it('never lets an identity move a tablet to another vendor', function (): void {
    $mia = colgarTablet($this->pinNorte, 'Cocina 1', $this->galaxyTab);

    $otro = app(TenantContext::class)->runAs($this->organizer, function (): array {
        $evento = app(CreateEvent::class)(
            'Sabores del Este', now()->subDay(), now()->addDay(), null, EventStatus::Active,
        );
        $vendor = app(CreateVendor::class)('Pizzas Doña Ana');
        app(InviteVendorToEvent::class)($evento, $vendor, 1000);
        $puesto = outletFor($evento, 'Puesto Sur', OperatingUnitKind::Kitchen, $vendor);

        return ['vendor' => $vendor, 'pin' => app(RotateOutletKdsPin::class)($puesto), 'puesto' => $puesto];
    });

    // La misma tablet, prestada al comercio de al lado, con el código y el PIN
    // de ESE comercio: entra, y entra en una fila propia.
    $prestada = app(EnrollKdsDevice::class)(
        (string) $otro['vendor']->kds_code, $otro['pin'], 'Cocina 1', null, $this->galaxyTab,
    );

    expect($prestada->device->vendor_id)->toBe($otro['vendor']->id);
    expect($prestada->device->id)->not->toBe($mia->device->id);

    // Y la fila del comercio original NO se apaga. El barrido por identidad se
    // queda dentro del comercio cuyo PIN se acaba de acertar: si cruzara de
    // comercio, presentar la identidad de una tablet ajena apagaría la
    // pantalla de otro sin haber tecleado un solo PIN suyo.
    $mia->device->refresh();
    expect($mia->device->estaRevocada())->toBeFalse();
});

it('keeps the kitchen trail pointing at the tablet that came back', function (): void {
    $tablet = colgarTablet($this->pinNorte, 'Cocina 1', $this->galaxyTab);

    $orden = ventaConComanda($this->norte);

    // La tablet empieza la comanda: aquí se sella qué aparato la tocó.
    $this->withToken($tablet->plainToken)->postJson(
        "/api/kds/comandas/{$orden->id}/kitchen/estado",
        ['from' => 'pending', 'to' => 'in_progress'],
    )->assertOk();

    $comanda = KitchenTicket::query()->withoutTenancy()->where('order_id', $orden->id)->firstOrFail();
    expect($comanda->started_by_device_id)->toBe($tablet->device->id);

    // Se descuelga, se vuelve a colgar, se vuelve a teclear el PIN.
    $vuelta = colgarTablet($this->pinNorte, 'Cocina 1', $this->galaxyTab);

    // Y el rastro sigue teniendo a quién apuntar. Con una fila nueva por cada
    // reconexión, reclamar un plato que nunca salió acabaría enseñando una
    // tablet muerta que ya no cuelga en ninguna ventanilla.
    $comanda->refresh();
    expect($comanda->started_by_device_id)->toBe($vuelta->device->id);
    expect(KdsDevice::query()->withoutTenancy()->whereKey($comanda->started_by_device_id)->exists())->toBeTrue();
});

it('takes the identity over http and never echoes it back', function (): void {
    $alta = $this->postJson('/api/kds/enrolar', [
        'codigo' => (string) $this->tacos->kds_code,
        'pin' => $this->pinNorte,
        'device_name' => 'Cocina 1',
        'area' => null,
        'device_identity' => $this->galaxyTab,
    ])->assertCreated();

    // No vuelve en la respuesta a propósito: la tablet ya la sabe —se la
    // acaba de leer al aparato— y devolverla solo la repartiría más.
    $alta->assertJsonMissingPath('device.device_identity');

    $this->postJson('/api/kds/enrolar', [
        'codigo' => (string) $this->tacos->kds_code,
        'pin' => $this->pinNorte,
        'device_name' => 'Cocina 1',
        'area' => null,
        'device_identity' => $this->galaxyTab,
    ])->assertCreated();

    expect(KdsDevice::query()->withoutTenancy()->count())->toBe(1);
});

it('treats a blank identity as no identity at all', function (): void {
    // El puente devuelve '' cuando el sistema no le da el identificador. Si
    // eso aterrizara en la columna, TODAS las tabletas sin identidad de un
    // puesto colisionarían en el único y se fundirían en una sola fila.
    $una = colgarTablet($this->pinNorte, 'Cocina 1', '   ');
    $otra = colgarTablet($this->pinNorte, 'Cocina 2', '');

    expect($una->device->device_identity)->toBeNull();
    expect($otra->device->device_identity)->toBeNull();
    expect(KdsDevice::query()->withoutTenancy()->count())->toBe(2);
});

it('refuses to rewrite the identity of an existing row', function (): void {
    $tablet = colgarTablet($this->pinNorte, 'Cocina 1', $this->galaxyTab);

    app(TenantContext::class)->runAs($this->organizer, function () use ($tablet): void {
        // Una fila que cambiase de aparato se llevaría consigo el rastro de
        // las comandas que despachó el anterior.
        expect(fn () => $tablet->device->update(['device_identity' => '0f0e0d0c0b0a0908']))
            ->toThrow(KitchenException::class);
    });
});

it('merges the duplicates that were already in the database', function (): void {
    // Las seis «Cocina 1» de siempre: sin identidad, porque nacieron antes de
    // que la columna existiera.
    $filas = collect(range(1, 6))->map(fn (): KdsDevice => colgarTablet($this->pinNorte, 'Cocina 1')->device);

    // La que la tablet tiene en la mano es la que sigue preguntando.
    $viva = $filas->last();
    app(TenantContext::class)->runAs($this->organizer, function () use ($viva): void {
        $viva->setAttribute('last_seen_at', now());
        $viva->save();
    });

    // En seco por defecto: enseña y no toca, porque el criterio (puesto más
    // nombre) es una conjetura y quien lo lea tiene que poder desmentirla.
    $this->artisan('kds:fusionar-tabletas')->assertSuccessful();
    expect(KdsDevice::query()->withoutTenancy()->whereNull('revoked_at')->count())->toBe(6);

    $this->artisan('kds:fusionar-tabletas --aplicar')->assertSuccessful();

    $vivas = KdsDevice::query()->withoutTenancy()->whereNull('revoked_at')->get();
    expect($vivas)->toHaveCount(1);
    expect($vivas->first()->id)->toBe($viva->id);

    // Revocadas, NO borradas: las comandas guardan qué aparato las tocó y
    // borrar esas filas dejaría el rastro apuntando al vacío.
    expect(KdsDevice::query()->withoutTenancy()->count())->toBe(6);
});
