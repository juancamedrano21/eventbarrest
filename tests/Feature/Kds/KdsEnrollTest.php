<?php

declare(strict_types=1);

use App\Domains\EventManagement\Actions\CreateEvent;
use App\Domains\EventManagement\Actions\CreateVendor;
use App\Domains\EventManagement\Actions\InviteVendorToEvent;
use App\Domains\EventManagement\Enums\EventStatus;
use App\Domains\EventManagement\Models\Event;
use App\Domains\EventManagement\Models\Vendor;
use App\Domains\EventManagement\VendorContext;
use App\Domains\Kitchen\Actions\EnrollKdsDevice;
use App\Domains\Kitchen\Actions\RotateOutletKdsPin;
use App\Domains\Kitchen\Models\KdsDevice;
use App\Domains\Operations\Enums\OperatingUnitKind;
use App\Domains\Operations\Models\OperatingUnit;
use App\Domains\Platform\Actions\CreateTenant;
use App\Domains\Platform\Enums\TenantType;
use App\Domains\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\Support\ContadorDeHashes;

/**
 * Cuánta CPU regala el alta pública, y —sobre todo— a quién NO puede dejar
 * fuera el remedio.
 *
 * Aquí se fijan las dos mitades del mismo encargo, que tiran en sentidos
 * contrarios y que dos rondas anteriores resolvieron mal:
 *
 * 1) EL GASTO. Una petición anónima abría un abanico de un bcrypt POR PUESTO
 *    candidato del comercio —ocho bcrypt en una sola petición contra un
 *    comercio de ocho barras—, y el código del comercio está impreso y pegado
 *    en el puesto a la vista de todo el recinto. Ese abanico ya no existe: el
 *    índice ciego del PIN localiza el puesto y el bcrypt se gasta UNA vez.
 *
 *    Y el índice NACE CON EL PIN, en `RotateOutletKdsPin`, no con la primera
 *    alta correcta. Cuando solo lo escribía el alta, el día del montaje —cuando
 *    todos los puestos son nuevos y ninguno se ha usado— no había índice para
 *    ninguno y el abanico seguía entero justo el día que importa. Por eso el
 *    comercio de treinta barras de estos tests se emite y no se toca.
 *
 * 2) QUIEN TECLEA BIEN, ENTRA. Los intentos anteriores de racionar el abanico
 *    produjeron denegaciones de servicio nuevas contra gente legítima: un techo
 *    por origen que dejaba en 429 a un comercio que no había fallado nunca, y un
 *    contador por puesto que dejaba al comercio ENTERO sin poder colgar tabletas
 *    con el PIN CORRECTO, sin caducar jamás y por cinco peticiones anónimas.
 *
 *    La tercera versión de lo mismo venía de HEAD y no de ninguna ronda: un PIN
 *    que no acertaba ningún puesto gastaba intento en TODOS, así que diez
 *    peticiones anónimas con un PIN inventado dejaban las treinta barras del
 *    comercio quince minutos sin poder colgar nada. Ahora el intento se apunta
 *    solo al puesto que el PIN SEÑALA. Los cinco primeros tests son exactamente
 *    esas averías medidas, convertidas en red.
 *
 * En un festival a las dos de la madrugada, una cocina que no puede colgar su
 * tablet es peor que un servidor lento. Por eso el montaje va el primero.
 */
beforeEach(function (): void {
    $this->organizer = app(CreateTenant::class)('Bocao Food Fest', null, TenantType::Organizer);

    app(TenantContext::class)->runAs($this->organizer, function (): void {
        $this->evento = app(CreateEvent::class)(
            'Bocao 2026', now()->subDay(), now()->addDay(), null, EventStatus::Active,
        );

        $this->tacos = app(CreateVendor::class)('Tacos del Puerto');
        app(InviteVendorToEvent::class)($this->evento, $this->tacos, 1000);

        $this->norte = outletFor($this->evento, 'Puesto Norte', OperatingUnitKind::Mixed, $this->tacos);
        $this->pinNorte = app(RotateOutletKdsPin::class)($this->norte);
    });
});

afterEach(function (): void {
    app(TenantContext::class)->clear();
    app(VendorContext::class)->clear();
});

/** Un PIN que seguro no es el del puesto, sin jugársela a una entre un millón. */
function pinQueNoEs(string $bueno): string
{
    return $bueno === '000000' ? '111111' : '000000';
}

/**
 * El estado del puesto leído de la base sin contexto de cuenta.
 *
 * Se lee así y no con `refresh()` porque OperatingUnit lleva BelongsToTenant y
 * fuera de una petición HTTP no hay contexto: el scope fallaría cerrado y el
 * test mentiría diciendo que la fila no existe.
 */
function puestoEnLaBase(int $id): OperatingUnit
{
    /** @var OperatingUnit $unidad */
    $unidad = OperatingUnit::query()->withoutTenancy()->findOrFail($id);

    return $unidad;
}

/**
 * El estado del COMERCIO leído de la base, que es donde vive la racha de
 * intentos a ciegas: un intento fallido no identifica ningún puesto —el índice
 * ciego se deriva del propio PIN— y sí identifica el comercio, que trae su
 * código.
 */
function comercioEnLaBase(int $id): Vendor
{
    /** @var Vendor $comercio */
    $comercio = Vendor::query()->withoutTenancy()->findOrFail($id);

    return $comercio;
}

/**
 * Un comercio nuevo del mismo evento con `$puestos` puestos, cada uno con su
 * PIN recién emitido por el panel y nada más: NINGUNO se ha usado todavía.
 *
 * Eso es exactamente la mañana del montaje, y es el estado en el que el índice
 * ciego se caía antes de este arreglo: como solo lo escribía un alta correcta,
 * treinta puestos recién creados eran treinta puestos sin índice.
 *
 * Devuelve el comercio y el PIN del ÚLTIMO puesto.
 *
 * @return array{0: Vendor, 1: string}
 */
function comercioConPuestos(Event $evento, string $nombre, int $puestos): array
{
    $vendor = app(CreateVendor::class)($nombre);
    app(InviteVendorToEvent::class)($evento, $vendor, 1000);

    $pin = '';

    for ($i = 1; $i <= $puestos; $i++) {
        $unidad = outletFor($evento, $nombre.' '.$i, OperatingUnitKind::Mixed, $vendor);
        $pin = app(RotateOutletKdsPin::class)($unidad);
    }

    // El PIN devuelto es el del ÚLTIMO puesto: con el abanico de antes era el
    // peor caso, el que obligaba a recorrer la lista entera antes de acertar.
    return [$vendor, $pin];
}

it('lets the twenty tablets of one kitchen enrol from the same wifi', function (): void {
    $codigo = (string) $this->tacos->kds_code;

    // El montaje, tal cual: veinte pantallas colgadas la misma mañana, todas
    // con el código que está impreso en la hoja del puesto y todas saliendo
    // por el router del recinto. Con el techo de antes —diez por minuto y
    // código+IP, contando también los aciertos— la undécima recibía un 429 sin
    // que nadie se hubiera equivocado, y a esa hora no hay a quién llamar.
    for ($tablet = 1; $tablet <= 20; $tablet++) {
        $this->postJson('/api/kds/enrolar', [
            'codigo' => $codigo,
            'pin' => $this->pinNorte,
            'device_name' => 'Tablet '.$tablet,
            'area' => null,
        ])->assertCreated();
    }

    expect(KdsDevice::query()->withoutTenancy()->count())->toBe(20);
});

it('still hangs a tablet with the right pin after six strangers missed it', function (): void {
    // La avería que tumbó la ronda anterior, medida por los dos refutadores:
    // seis desconocidos fallando el PIN de un comercio —cada uno desde su
    // origen, así que el freno por código+origen no se mete— dejaban al
    // comercio ENTERO sin poder colgar tabletas con el PIN CORRECTO. Y no
    // caducaba: a la semana seguía en 422.
    $codigo = (string) $this->tacos->kds_code;
    $malo = pinQueNoEs($this->pinNorte);

    for ($desconocido = 1; $desconocido <= 6; $desconocido++) {
        $this->withHeader('X-Forwarded-For', '203.0.113.'.$desconocido)
            ->postJson('/api/kds/enrolar', [
                'codigo' => $codigo,
                'pin' => $malo,
                'device_name' => 'Tablet',
                'area' => null,
            ])->assertStatus(422);
    }

    // Y ahora el cocinero, con SU PIN, desde su tablet. Tiene que entrar.
    $this->withHeader('X-Forwarded-For', '198.51.100.7')
        ->postJson('/api/kds/enrolar', [
            'codigo' => $codigo,
            'pin' => $this->pinNorte,
            'device_name' => 'Tablet de la cocina',
            'area' => null,
        ])->assertCreated();
});

it('still hangs a tablet with the right pin after a hundred invented ones', function (): void {
    // La denegación de servicio que venía de HEAD, y que es lo que este encargo
    // viene a cerrar. El fallo se repartía entre TODOS los puestos candidatos
    // del comercio —«un PIN que no acierta no dice contra cuál iba»— y al décimo
    // el puesto se queda quince minutos en penitencia. O sea: DIEZ peticiones
    // anónimas con un PIN inventado, cada una desde otro origen para que ningún
    // limitador por IP se meta, y el comercio entero deja de aceptar su PIN
    // CORRECTO. El código del comercio está impreso y pegado en el puesto.
    //
    // Cien intentos, que es diez veces el techo y más que las «diez, veinte o
    // cien» del encargo. El cocinero tiene que entrar igual.
    $codigo = (string) $this->tacos->kds_code;

    for ($intento = 1; $intento <= 100; $intento++) {
        $inventado = str_pad((string) $intento, 6, '0', STR_PAD_LEFT);

        // Uno entre un millón, pero el test no puede depender de la suerte.
        if ($inventado === $this->pinNorte) {
            $inventado = '999999';
        }

        $this->withHeader('X-Forwarded-For', '203.0.113.'.$intento)
            ->postJson('/api/kds/enrolar', [
                'codigo' => $codigo,
                'pin' => $inventado,
                'device_name' => 'Tablet',
                'area' => null,
            ])->assertStatus(422);
    }

    // La racha SÍ se cuenta —el freno tiene que ser alcanzable o es código
    // muerto— y a estas alturas el comercio lleva rato en penitencia. Se cuenta
    // EN EL COMERCIO: mientras se replicaba en las treinta barras, el panel
    // pintaba «Bloqueado» en las treinta sin que ninguna lo estuviera.
    expect(comercioEnLaBase((int) $this->tacos->id)->getAttribute('kds_blind_pause_until'))->not->toBeNull();

    // Y ni una sola de sus barras dice nada: no hay ninguna columna de bloqueo
    // que un desconocido pueda encender con diez peticiones.
    expect(array_keys(puestoEnLaBase((int) $this->norte->id)->getAttributes()))
        ->not->toContain('kds_pin_locked_until')
        ->not->toContain('kds_pin_failed_attempts');

    // Y aun así, lo que de verdad importa a las dos de la madrugada: la tablet
    // se cuelga. La penitencia no filtra candidatos ni decide quién entra; lo
    // único que hace es dejar de gastar bcrypt en contestar que no. Con el
    // reparto de HEAD aquí llegaba un 422 con el PIN CORRECTO en la mano.
    $this->withHeader('X-Forwarded-For', '198.51.100.7')
        ->postJson('/api/kds/enrolar', [
            'codigo' => $codigo,
            'pin' => $this->pinNorte,
            'device_name' => 'Tablet de la cocina',
            'area' => null,
        ])->assertCreated();

    // Y entrar bien rompe la racha, que es lo que hace que el siguiente dedo
    // torpe no arranque ya contado.
    $comercio = comercioEnLaBase((int) $this->tacos->id);

    expect((int) $comercio->getAttribute('kds_blind_attempts'))->toBe(0)
        ->and($comercio->getAttribute('kds_blind_pause_until'))->toBeNull();
});

it('does not punish the outlets of a vendor for the clumsy fingers of the others', function (): void {
    // Seis barras del MISMO comercio, seis cocineros, un dedo torpe cada uno y
    // a continuación su PIN bueno. Ningún atacante en ningún momento. Con el
    // contador que cerraba el abanico, el quinto y el sexto recibían 422 con
    // su PIN CORRECTO, porque los fallos de sus compañeros contaban en el
    // contador de puestos que nunca fueron objetivo de nadie.
    [$comercio, $puestos] = app(TenantContext::class)->runAs($this->organizer, function (): array {
        $vendor = app(CreateVendor::class)('Cocina de Seis Barras');
        app(InviteVendorToEvent::class)($this->evento, $vendor, 1000);

        $pines = [];

        for ($barra = 1; $barra <= 6; $barra++) {
            $unidad = outletFor($this->evento, 'Barra '.$barra, OperatingUnitKind::Mixed, $vendor);
            $pines[] = app(RotateOutletKdsPin::class)($unidad);
        }

        return [$vendor, $pines];
    });

    $codigo = (string) $comercio->kds_code;
    $estados = [];

    foreach ($puestos as $indice => $pinBueno) {
        $origen = '10.0.0.'.($indice + 1);

        $this->withHeader('X-Forwarded-For', $origen)
            ->postJson('/api/kds/enrolar', [
                'codigo' => $codigo,
                'pin' => pinQueNoEs($pinBueno),
                'device_name' => 'Tablet '.($indice + 1),
                'area' => null,
            ])->assertStatus(422);

        $estados[] = $this->withHeader('X-Forwarded-For', $origen)
            ->postJson('/api/kds/enrolar', [
                'codigo' => $codigo,
                'pin' => $pinBueno,
                'device_name' => 'Tablet '.($indice + 1),
                'area' => null,
            ])->getStatusCode();
    }

    expect($estados)->toBe([201, 201, 201, 201, 201, 201]);
});

it('still takes the right pin the minute after five wrong ones from the same wifi', function (): void {
    // El dedo torpe de siempre, sin falsificar ninguna cabecera: cinco intentos
    // malos desde el mismo router. El freno del controlador —cinco fallos por
    // código+origen— corta el sexto, así que se espera el minuto y se teclea
    // bien. Eso tiene que abrir. Medido contra la ronda anterior: 422, y a la
    // hora, y al día siguiente.
    $codigo = (string) $this->tacos->kds_code;
    $malo = pinQueNoEs($this->pinNorte);

    for ($intento = 1; $intento <= 5; $intento++) {
        $this->postJson('/api/kds/enrolar', [
            'codigo' => $codigo,
            'pin' => $malo,
            'device_name' => 'Tablet',
            'area' => null,
        ])->assertStatus(422);
    }

    $this->travel(61)->seconds();

    $this->postJson('/api/kds/enrolar', [
        'codigo' => $codigo,
        'pin' => $this->pinNorte,
        'device_name' => 'Tablet',
        'area' => null,
    ])->assertCreated();

    // Y el acierto rompe la racha del comercio, que es lo que hace que el
    // siguiente dedo torpe no arranque ya contado.
    expect((int) comercioEnLaBase((int) $this->tacos->id)->getAttribute('kds_blind_attempts'))->toBe(0);
});

it('spends one bcrypt on an enrolment attempt whether the vendor has one outlet or thirty', function (): void {
    // El grave original, medido en el peor día: TREINTA barras cuyos PIN acaba
    // de emitir el panel y que NADIE ha usado todavía. Es la mañana del montaje
    // literal, y era el agujero por el que se colaba el arreglo anterior: como
    // el índice solo lo escribía un alta correcta, treinta puestos nuevos eran
    // treinta puestos sin índice, y cada petición anónima volvía a comprar
    // treinta bcrypt con un código que está pegado en la pared.
    [$grande, $pinGrande] = app(TenantContext::class)->runAs(
        $this->organizer,
        fn (): array => comercioConPuestos($this->evento, 'Cocina Grande', 30),
    );

    // Ninguno se ha usado y los treinta están indexados: el índice nace con el
    // PIN, en RotateOutletKdsPin, y no con la primera tablet que acierte.
    expect(KdsDevice::query()->withoutTenancy()->count())->toBe(0);

    expect(
        OperatingUnit::query()->withoutTenancy()
            ->where('vendor_id', $grande->id)
            ->whereNull('kds_pin_index')
            ->count()
    )->toBe(0);

    $contador = ContadorDeHashes::instalar();
    $gasto = [];

    // Cada intento dice venir de otra IP: hoy la cabecera se cree (ver
    // bootstrap/app.php), así que el freno por código+origen estrena cubo en
    // cada petición y no estorba. Es justo el escenario en el que ningún freno
    // por IP sirve de nada, y por eso el gasto hay que cerrarlo en la fuente.
    for ($intento = 1; $intento <= 6; $intento++) {
        $contador->aCero();

        $this->withHeader('X-Forwarded-For', '203.0.113.'.$intento)
            ->postJson('/api/kds/enrolar', [
                'codigo' => (string) $grande->kds_code,
                'pin' => '000000',
                'device_name' => 'Tablet',
                'area' => null,
            ])->assertStatus(422)->assertJsonPath('code', 'kds_enrollment_rejected');

        $gasto[] = $contador->comprobaciones;
    }

    // Uno. Siempre. Sin contadores, sin cubos y sin nada que nadie pueda dejar
    // encendido: el índice ciego dice a qué puesto preguntar y se pregunta una
    // sola vez. Antes de este arreglo aquí había treinta.
    expect($gasto)->toBe([1, 1, 1, 1, 1, 1]);

    // Y el acierto cuesta lo mismo que el fallo, que es lo que hace que el
    // reloj no delate si un código de comercio existe.
    $contador->aCero();

    $this->withHeader('X-Forwarded-For', '198.51.100.30')
        ->postJson('/api/kds/enrolar', [
            'codigo' => (string) $grande->kds_code,
            'pin' => $pinGrande,
            'device_name' => 'Tablet buena',
            'area' => null,
        ])->assertCreated();

    expect($contador->comprobaciones)->toBe(1);

    // Y un comercio de UN puesto gasta exactamente lo mismo: el coste dejó de
    // depender de cuántas barras tiene nadie.
    $contador->aCero();

    $this->withHeader('X-Forwarded-For', '198.51.100.31')
        ->postJson('/api/kds/enrolar', [
            'codigo' => (string) $this->tacos->kds_code,
            'pin' => '000000',
            'device_name' => 'Tablet',
            'area' => null,
        ])->assertStatus(422);

    expect($contador->comprobaciones)->toBe(1);

    // Un código que no existe, igual: un bcrypt contra el hash tonto.
    $contador->aCero();

    $this->withHeader('X-Forwarded-For', '198.51.100.32')
        ->postJson('/api/kds/enrolar', [
            'codigo' => 'ZZZZ9999',
            'pin' => '000000',
            'device_name' => 'Tablet',
            'area' => null,
        ])->assertStatus(422);

    expect($contador->comprobaciones)->toBe(1);

    // Y la localización la hace LA BASE, por (vendor_id, kds_pin_index), que es
    // el índice que crea la migración. Mientras se traían todas las filas del
    // comercio y se filtraba en PHP, ese índice no se ejecutaba nunca: el bcrypt
    // ya no dependía del número de barras, pero hidratar treinta modelos para
    // descartar veintinueve sí. El acierto no puede leer más que su fila.
    $consultas = [];

    DB::listen(function ($consulta) use (&$consultas): void {
        $consultas[] = str_replace(['"', '`'], '', (string) $consulta->sql);
    });

    $this->withHeader('X-Forwarded-For', '198.51.100.33')
        ->postJson('/api/kds/enrolar', [
            'codigo' => (string) $grande->kds_code,
            'pin' => $pinGrande,
            'device_name' => 'Tablet localizada',
            'area' => null,
        ])->assertCreated();

    expect(array_values(array_filter(
        $consultas,
        fn (string $sql): bool => str_contains($sql, 'kds_pin_index = ?'),
    )))->not->toBeEmpty();

    // Y ni una sola consulta que traiga los puestos del comercio SIN acotar por
    // índice: esa es la firma del camino a ciegas, y el que teclea bien no tiene
    // por qué pasar por ahí ni hidratar las otras veintinueve filas.
    expect(array_values(array_filter(
        $consultas,
        fn (string $sql): bool => str_contains($sql, 'kds_pin_hash is not null')
            && ! str_contains($sql, 'kds_pin_index = ?'),
    )))->toBe([]);
});

it('stops buying cpu for a blind streak once the vendor is in penitence', function (): void {
    // El freno con estado, medido: tiene que ser ALCANZABLE —si no, es código
    // muerto y un mando del panel sin función— y tiene que no poder negarle la
    // entrada a nadie. Las dos cosas a la vez, que es lo que costó cuatro
    // vueltas.
    $codigo = (string) $this->tacos->kds_code;
    $malo = pinQueNoEs($this->pinNorte);

    $contador = ContadorDeHashes::instalar();
    $gasto = [];

    // Cada intento desde otro origen, para que el limitador del controlador no
    // se meta y lo que se mida sea el freno de la base.
    for ($intento = 1; $intento <= 12; $intento++) {
        $contador->aCero();

        $this->withHeader('X-Forwarded-For', '203.0.113.'.$intento)
            ->postJson('/api/kds/enrolar', [
                'codigo' => $codigo,
                'pin' => $malo,
                'device_name' => 'Tablet',
                'area' => null,
            ])->assertStatus(422);

        $gasto[] = $contador->comprobaciones;
    }

    // Los diez primeros pagan su bcrypt contra el hash tonto, que es lo que hace
    // que un código inexistente no se distinga de un PIN equivocado. Del décimo
    // en adelante la penitencia deja de comprar CPU para contestar que no a lo
    // que ya se sabe que es que no.
    expect($gasto)->toBe([1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 0, 0]);

    expect(comercioEnLaBase((int) $this->tacos->id)->getAttribute('kds_blind_pause_until'))->not->toBeNull();

    // Y con la penitencia encendida, el cocinero con su PIN entra a la primera y
    // gastando un solo bcrypt: su PIN localiza su puesto por el índice y ese
    // camino ni mira esta columna. Es la condición dura del encargo.
    $contador->aCero();

    $this->withHeader('X-Forwarded-For', '198.51.100.9')
        ->postJson('/api/kds/enrolar', [
            'codigo' => $codigo,
            'pin' => $this->pinNorte,
            'device_name' => 'Tablet de la cocina',
            'area' => null,
        ])->assertCreated();

    expect($contador->comprobaciones)->toBe(1);
});

it('lets in the cook of an outlet without index while the vendor is in penitence', function (): void {
    // LA CONDICIÓN DURA, en el estado en el que se escapa. La penitencia deja de
    // comprar el bcrypt del hash tonto, y la tentación es adelantarla al camino
    // entero para que un comercio de treinta barras sin índice deje de gastar
    // treinta por intento. Eso rechazaría SIN COMPROBAR, y a quien rechazaría es
    // a este cocinero: puesto cuyo PIN es anterior a la columna —o parque recién
    // desplegado, que hoy es TODO el parque— con su PIN CORRECTO en la mano.
    //
    // Aquí se fijan las dos mitades a la vez: que entra, y cuánto cuesta de
    // verdad, para que el docblock no pueda volver a prometer lo que no da.
    [$comercio, $pines] = app(TenantContext::class)->runAs($this->organizer, function (): array {
        $vendor = app(CreateVendor::class)('Cocina Sin Indice');
        app(InviteVendorToEvent::class)($this->evento, $vendor, 1000);

        $pines = [];

        for ($barra = 1; $barra <= 3; $barra++) {
            $unidad = outletFor($this->evento, 'Barra Vieja '.$barra, OperatingUnitKind::Mixed, $vendor);

            // El hash a pelo y las columnas del índice vacías: la fila tal cual
            // la deja hoy la aplicación para todo PIN anterior a esta tanda.
            $pin = str_pad((string) (200000 + $barra), 6, '0', STR_PAD_LEFT);

            $unidad->setAttribute('kds_pin_hash', Hash::make($pin));
            $unidad->setAttribute('kds_pin_index', null);
            $unidad->setAttribute('kds_pin_indexed_hash', null);
            $unidad->save();

            $pines[(int) $unidad->id] = $pin;
        }

        return [$vendor, $pines];
    });

    $codigo = (string) $comercio->kds_code;

    $contador = ContadorDeHashes::instalar();
    $gasto = [];

    for ($intento = 1; $intento <= 12; $intento++) {
        $contador->aCero();

        $this->withHeader('X-Forwarded-For', '203.0.113.'.$intento)
            ->postJson('/api/kds/enrolar', [
                'codigo' => $codigo,
                'pin' => '999999',
                'device_name' => 'Tablet',
                'area' => null,
            ])->assertStatus(422);

        $gasto[] = $contador->comprobaciones;
    }

    // La penitencia entra al décimo y NO baja el gasto: tres puestos sin índice
    // son tres bcrypt, con ella encendida y con ella apagada. No es un olvido,
    // es que el bcrypt ES la comprobación —saltárselo sería contestar que no sin
    // haber mirado— y está escrito así en `anotarFallo`.
    expect(comercioEnLaBase((int) $comercio->id)->getAttribute('kds_blind_pause_until'))->not->toBeNull()
        ->and($gasto)->toBe([3, 3, 3, 3, 3, 3, 3, 3, 3, 3, 3, 3]);

    // Y ahora lo único que importa a las dos de la madrugada: los tres cocineros
    // entran, empezando por el primero, que lo hace con la penitencia encendida.
    $estados = [];
    $origen = 60;

    foreach ($pines as $puestoId => $pin) {
        $estados[] = $this->withHeader('X-Forwarded-For', '198.51.100.'.$origen++)
            ->postJson('/api/kds/enrolar', [
                'codigo' => $codigo,
                'pin' => $pin,
                'device_name' => 'Tablet de la barra',
                'area' => null,
            ])->getStatusCode();

        // Y cada uno sale indexado, que es como este estado mengua solo.
        expect(puestoEnLaBase((int) $puestoId)->getAttribute('kds_pin_index'))
            ->toBe(EnrollKdsDevice::indiceDelPin((int) $comercio->id, $pin));
    }

    expect($estados)->toBe([201, 201, 201]);
});

it('does not trust an index whose fingerprint was derived for another vendor', function (): void {
    // La huella ata las TRES entradas del índice —comercio, hash del PIN y
    // llave—, no dos. Mientras solo ataba el hash y la llave, un índice derivado
    // con OTRO vendor_id daba `indiceAlDia() === true`: el puesto quedaba fuera
    // del camino a ciegas, la búsqueda por índice no lo encontraba nunca porque
    // el índice no casa, y el cocinero con su PIN CORRECTO recibía 422 para
    // siempre, sin nada en el panel y sin camino de curación —el único sitio que
    // reindexa exige entrar primero—. Es la forma exacta de la avería de la
    // llave, con el otro ingrediente.
    [$puestoId, $comercio] = app(TenantContext::class)->runAs($this->organizer, function (): array {
        $vecino = app(CreateVendor::class)('Comercio Vecino');
        app(InviteVendorToEvent::class)($this->evento, $vecino, 1000);

        $vendor = app(CreateVendor::class)('Comercio Con Huella Ajena');
        app(InviteVendorToEvent::class)($this->evento, $vendor, 1000);

        $unidad = outletFor($this->evento, 'Barra Con Huella Ajena', OperatingUnitKind::Mixed, $vendor);

        $unidad->setAttribute('kds_pin_hash', Hash::make('808080'));
        // Índice y huella cuadrados ENTRE SÍ pero derivados con el comercio
        // vecino: es la única forma en que este estado podría existir, y la
        // huella tiene que rechazarlo ella sola, sin depender de que el
        // vendor_id de un puesto sea inmutable en otro fichero.
        $unidad->setAttribute('kds_pin_index', EnrollKdsDevice::indiceDelPin((int) $vecino->id, '808080'));
        $unidad->setAttribute('kds_pin_indexed_hash', EnrollKdsDevice::huellaDelIndice(
            (int) $vecino->id, (string) $unidad->getAttribute('kds_pin_hash'),
        ));
        $unidad->save();

        return [(int) $unidad->id, $vendor];
    });

    // El cocinero entra: la huella no cuadra, así que ese índice no se usa y el
    // puesto cae al camino a ciegas, que es más caro y está abierto.
    $this->postJson('/api/kds/enrolar', [
        'codigo' => (string) $comercio->kds_code,
        'pin' => '808080',
        'device_name' => 'Tablet',
        'area' => null,
    ])->assertCreated();

    // Y sale con el índice y la huella de SU comercio, o sea curado.
    $puesto = puestoEnLaBase($puestoId);

    expect($puesto->getAttribute('kds_pin_index'))
        ->toBe(EnrollKdsDevice::indiceDelPin((int) $comercio->id, '808080'))
        ->and($puesto->getAttribute('kds_pin_indexed_hash'))
        ->toBe(EnrollKdsDevice::huellaDelIndice(
            (int) $comercio->id, (string) $puesto->getAttribute('kds_pin_hash'),
        ));
});

it('keeps the pin index inside its own vendor', function (): void {
    // El aislamiento del índice. Dos comercios distintos con EL MISMO PIN: el
    // índice va salado con el comercio, así que ni se parecen en la tabla ni el
    // de uno encuentra el puesto del otro. Si el índice fuera global, teclear
    // el código de un comercio con el PIN de otro colgaría una tablet en el
    // puesto equivocado — o lo delataría por el tiempo de respuesta.
    [$unoId, $otroId, $uno, $otro] = app(TenantContext::class)->runAs($this->organizer, function (): array {
        $vendorUno = app(CreateVendor::class)('Arepas Uno');
        app(InviteVendorToEvent::class)($this->evento, $vendorUno, 1000);
        $puestoUno = outletFor($this->evento, 'Arepas Uno Barra', OperatingUnitKind::Mixed, $vendorUno);

        $vendorOtro = app(CreateVendor::class)('Arepas Otro');
        app(InviteVendorToEvent::class)($this->evento, $vendorOtro, 1000);
        $puestoOtro = outletFor($this->evento, 'Arepas Otro Barra', OperatingUnitKind::Mixed, $vendorOtro);

        // El mismo PIN a mano en los dos, que es el caso que hay que fijar.
        foreach ([$puestoUno, $puestoOtro] as $puesto) {
            $puesto->setAttribute('kds_pin_hash', Hash::make('424242'));
            $puesto->setAttribute('kds_pin_index', EnrollKdsDevice::indiceDelPin(
                (int) $puesto->getAttribute('vendor_id'), '424242',
            ));
            $puesto->setAttribute('kds_pin_indexed_hash', EnrollKdsDevice::huellaDelIndice(
                (int) $puesto->getAttribute('vendor_id'),
                (string) $puesto->getAttribute('kds_pin_hash'),
            ));
            $puesto->save();
        }

        return [$puestoUno->id, $puestoOtro->id, $vendorUno, $vendorOtro];
    });

    expect($uno->kds_code)->not->toBe($otro->kds_code);

    // Índices distintos en la base para el mismo PIN.
    $indices = OperatingUnit::query()->withoutTenancy()
        ->whereIn('id', [$unoId, $otroId])
        ->pluck('kds_pin_index');

    expect($indices->unique()->count())->toBe(2);

    // Y el alta con el código de UNO cuelga en el puesto de UNO.
    $alta = $this->postJson('/api/kds/enrolar', [
        'codigo' => (string) $uno->kds_code,
        'pin' => '424242',
        'device_name' => 'Tablet',
        'area' => null,
    ])->assertCreated();

    expect($alta->json('outlet.id'))->toBe($unoId);
});

it('still enrols an outlet whose pin predates the index, and indexes it on the way', function (): void {
    // El residuo, que es lo único que el índice NO puede resolver de golpe: el
    // índice se deriva del PIN EN CLARO, y de los PIN emitidos antes de que la
    // columna existiera solo queda el bcrypt. Ni una migración puede rellenarlo.
    // Un puesto así tiene que SEGUIR ENTRANDO —dejarlo fuera sería la misma
    // avería invisible que se está quitando— y quedar indexado en cuanto alguien
    // teclee bien.
    [$viejo, $comercio, $pin] = app(TenantContext::class)->runAs($this->organizer, function (): array {
        $vendor = app(CreateVendor::class)('Comercio de Antes');
        app(InviteVendorToEvent::class)($this->evento, $vendor, 1000);

        $unidad = outletFor($this->evento, 'Barra de Antes', OperatingUnitKind::Mixed, $vendor);

        // El hash a pelo y las dos columnas nuevas vacías: la fila tal cual la
        // dejó la aplicación de antes de esta tanda. Se escribe a mano y no con
        // `RotateOutletKdsPin` a propósito, porque esa acción YA indexa —es
        // justo el arreglo— y con ella este escenario dejaría de existir.
        $unidad->setAttribute('kds_pin_hash', Hash::make('314159'));
        $unidad->setAttribute('kds_pin_index', null);
        $unidad->setAttribute('kds_pin_indexed_hash', null);
        $unidad->save();

        return [$unidad, $vendor, '314159'];
    });

    expect(puestoEnLaBase((int) $viejo->id)->getAttribute('kds_pin_index'))->toBeNull();

    $this->postJson('/api/kds/enrolar', [
        'codigo' => (string) $comercio->kds_code,
        'pin' => $pin,
        'device_name' => 'Tablet',
        'area' => null,
    ])->assertCreated();

    // Y ya está indexado: la primera tablet cierra la transición de ese puesto
    // sin que nadie toque el panel.
    expect(puestoEnLaBase((int) $viejo->id)->getAttribute('kds_pin_index'))
        ->toBe(EnrollKdsDevice::indiceDelPin((int) $comercio->id, $pin));
});

it('does not shut out an outlet whose index belongs to an older pin', function (): void {
    // La huella del hash, que es la red por debajo del índice. Aquí se fabrica
    // a mano el peor estado posible —índice del PIN VIEJO al lado del hash del
    // NUEVO— porque es lo que dejaría cualquier escritura que se acordara del
    // hash y se olvidara del índice. Con la huella, ese índice no se usa y el
    // puesto vuelve al camino de antes; sin ella, el cocinero que teclea BIEN
    // recibe «revisa el código y el PIN» y nada en el panel lo explica.
    [$puestoId, $comercio] = app(TenantContext::class)->runAs($this->organizer, function (): array {
        $vendor = app(CreateVendor::class)('Comercio Descuadrado');
        app(InviteVendorToEvent::class)($this->evento, $vendor, 1000);

        $unidad = outletFor($this->evento, 'Barra Descuadrada', OperatingUnitKind::Mixed, $vendor);

        $unidad->setAttribute('kds_pin_hash', Hash::make('777777'));
        // Índice y huella del PIN de antes: los dos mienten a la vez, que es
        // como mienten de verdad.
        $unidad->setAttribute('kds_pin_index', EnrollKdsDevice::indiceDelPin((int) $vendor->id, '111111'));
        $unidad->setAttribute('kds_pin_indexed_hash', EnrollKdsDevice::huellaDelIndice(
            (int) $vendor->id, Hash::make('111111'),
        ));
        $unidad->save();

        return [(int) $unidad->id, $vendor];
    });

    $this->postJson('/api/kds/enrolar', [
        'codigo' => (string) $comercio->kds_code,
        'pin' => '777777',
        'device_name' => 'Tablet',
        'area' => null,
    ])->assertCreated();

    // Y el alta deja el índice cuadrado con el PIN que de verdad tiene puesto.
    expect(puestoEnLaBase($puestoId)->getAttribute('kds_pin_index'))
        ->toBe(EnrollKdsDevice::indiceDelPin((int) $comercio->id, '777777'));
});

it('does not lock the outlet out when its pin is rotated after being indexed', function (): void {
    // El índice se deriva del PIN, así que un índice del PIN VIEJO al lado del
    // hash del NUEVO deja al cocinero con el PIN correcto recibiendo «revisa el
    // código y el PIN», y nada en el panel lo explica. Es la avería invisible
    // que este arreglo viene a quitar, así que no puede entrar por la puerta de
    // atrás. Aquí se rota con la acción REAL y con una instancia que ya venía en
    // memoria, que es como lo hace el panel.
    $codigo = (string) $this->tacos->kds_code;

    // Primero se indexa el puesto: un alta buena con el PIN de siempre.
    $this->postJson('/api/kds/enrolar', [
        'codigo' => $codigo,
        'pin' => $this->pinNorte,
        'device_name' => 'Tablet uno',
        'area' => null,
    ])->assertCreated();

    expect(puestoEnLaBase((int) $this->norte->id)->getAttribute('kds_pin_index'))->not->toBeNull();

    $nuevo = app(TenantContext::class)->runAs(
        $this->organizer,
        fn (): string => app(RotateOutletKdsPin::class)($this->norte),
    );

    // La rotación deja el índice del PIN NUEVO, en la misma escritura que el
    // hash: el puesto no pasa ni un instante por el camino caro.
    expect(puestoEnLaBase((int) $this->norte->id)->getAttribute('kds_pin_index'))
        ->toBe(EnrollKdsDevice::indiceDelPin((int) $this->tacos->id, $nuevo));

    // Y el PIN nuevo cuelga la tablet a la primera.
    $this->postJson('/api/kds/enrolar', [
        'codigo' => $codigo,
        'pin' => $nuevo,
        'device_name' => 'Tablet dos',
        'area' => null,
    ])->assertCreated();

    // Y el PIN viejo, el que está en la hoja que hay que tirar, ya no vale.
    $this->postJson('/api/kds/enrolar', [
        'codigo' => $codigo,
        'pin' => $this->pinNorte,
        'device_name' => 'Tablet tardía',
        'area' => null,
    ])->assertStatus(422);
});

it('lets every cook in with the right pin after the app key is rotated', function (): void {
    // La avería que los dos refutadores encontraron por separado, y la peor de
    // todas las de esta tanda porque es GLOBAL y silenciosa. El índice va
    // llaveado con la APP_KEY; mientras su huella solo ataba el índice al hash
    // del PIN, cambiar la llave dejaba todas las huellas cuadrando: ningún
    // puesto caía al camino a ciegas, el índice recalculado con la llave nueva
    // no casaba con ninguno, y TODOS los cocineros de TODA la plataforma
    // recibían 422 con su PIN CORRECTO, para siempre y sin nada en el panel que
    // lo explicara. La cura era rotar el PIN de cada puesto a mano y reimprimir
    // las hojas del montaje.
    [$comercio, $pines] = app(TenantContext::class)->runAs($this->organizer, function (): array {
        $vendor = app(CreateVendor::class)('Cocina de la Llave');
        app(InviteVendorToEvent::class)($this->evento, $vendor, 1000);

        $pines = [];

        for ($barra = 1; $barra <= 3; $barra++) {
            $unidad = outletFor($this->evento, 'Barra Llave '.$barra, OperatingUnitKind::Mixed, $vendor);
            $pines[(int) $unidad->id] = app(RotateOutletKdsPin::class)($unidad);
        }

        return [$vendor, $pines];
    });

    $codigo = (string) $comercio->kds_code;
    $primero = (string) reset($pines);

    // El escenario parte de sano: con la llave de siempre, el PIN emitido por el
    // panel cuelga la tablet a la primera.
    $this->withHeader('X-Forwarded-For', '198.51.100.40')
        ->postJson('/api/kds/enrolar', [
            'codigo' => $codigo,
            'pin' => $primero,
            'device_name' => 'Tablet antes de la llave',
            'area' => null,
        ])->assertCreated();

    // Y ahora la llave nueva. Es lo que deja `php artisan key:generate` en un
    // entorno nuevo, un contenedor cuya APP_KEY no está fijada, o restaurar un
    // volcado en otra instalación. Laravel documenta la rotación como segura y
    // hoy no hay nada cifrado en esta base (`Crypt::` no sale en app/), así que
    // no hay ninguna señal que avise de que esto apagaba el KDS del recinto.
    config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);

    $estados = [];
    $origen = 41;

    foreach ($pines as $pin) {
        $estados[] = $this->withHeader('X-Forwarded-For', '198.51.100.'.$origen++)
            ->postJson('/api/kds/enrolar', [
                'codigo' => $codigo,
                'pin' => $pin,
                'device_name' => 'Tablet tras la llave',
                'area' => null,
            ])->getStatusCode();
    }

    // Los tres entran. Con la huella sin llavear aquí salían [422, 422, 422].
    // El camino por el que entran es el de a ciegas —un bcrypt por puesto, más
    // caro— y eso es exactamente lo que se quiere: que un cambio de llave
    // degrade el alta en vez de apagarla.
    expect($estados)->toBe([201, 201, 201]);

    // Y salen reindexados con la llave de hoy, que es lo que hace que el recinto
    // vuelva solo al camino barato según entra cada tablet.
    foreach ($pines as $puestoId => $pin) {
        $puesto = puestoEnLaBase((int) $puestoId);

        expect($puesto->getAttribute('kds_pin_index'))
            ->toBe(EnrollKdsDevice::indiceDelPin((int) $comercio->id, $pin))
            ->and($puesto->getAttribute('kds_pin_indexed_hash'))
            ->toBe(EnrollKdsDevice::huellaDelIndice(
                (int) $comercio->id, (string) $puesto->getAttribute('kds_pin_hash'),
            ));
    }
});

it('speaks spanish and carries a code when the route ceiling cuts in', function (): void {
    $codigo = (string) $this->tacos->kds_code;

    // Sesenta altas BUENAS seguidas —el triple del montaje del primer test—
    // agotan el techo de volumen de la ruta. Lo que se fija aquí no es el
    // número, es la respuesta: el KDS solo le enseña `data.message` al
    // cocinero, así que un 'Too Many Attempts.' en inglés y sin `code` es una
    // pantalla que no dice nada a las siete de la mañana.
    for ($tablet = 1; $tablet <= 60; $tablet++) {
        $this->postJson('/api/kds/enrolar', [
            'codigo' => $codigo,
            'pin' => $this->pinNorte,
            'device_name' => 'Tablet '.$tablet,
            'area' => null,
        ])->assertCreated();
    }

    $this->postJson('/api/kds/enrolar', [
        'codigo' => $codigo,
        'pin' => $this->pinNorte,
        'device_name' => 'Tablet 61',
        'area' => null,
    ])
        ->assertStatus(429)
        ->assertJsonPath('code', 'kds_demasiados_intentos')
        ->assertJsonPath('message', 'Demasiados intentos. Espera un minuto y vuelve a probar.')
        // Y sin perder lo que ya traía el 429 de Laravel: el APK reintenta
        // solo, y Retry-After es lo que le dice cuándo.
        ->assertHeader('Retry-After');
});

it('does not lock out a vendor that never failed because six others did', function (): void {
    // Seis comercios distintos del mismo evento tecleando mal su PIN cinco
    // veces cada uno: treinta fallos en un minuto, ninguno con mala intención.
    // Es una mañana de montaje cualquiera.
    $torpes = app(TenantContext::class)->runAs($this->organizer, function (): array {
        $comercios = [];

        for ($i = 1; $i <= 6; $i++) {
            [$vendor] = comercioConPuestos($this->evento, 'Comercio Torpe '.$i, 1);
            $comercios[] = $vendor;
        }

        return $comercios;
    });

    foreach ($torpes as $vendor) {
        for ($intento = 1; $intento <= 5; $intento++) {
            $this->postJson('/api/kds/enrolar', [
                'codigo' => (string) $vendor->kds_code,
                'pin' => '000000',
                'device_name' => 'Tablet',
                'area' => null,
            ])->assertStatus(422);
        }
    }

    // Y ahora el séptimo, que no ha fallado ni una vez, con su código y su PIN
    // buenos y por el mismo router. Con el freno por origen de dos rondas atrás
    // —treinta fallos por IP, o sea un cubo único de plataforma— recibía 429:
    // un cocinero que teclea todo bien no podía colgar su tablet porque seis
    // desconocidos se equivocaron.
    $this->postJson('/api/kds/enrolar', [
        'codigo' => (string) $this->tacos->kds_code,
        'pin' => $this->pinNorte,
        'device_name' => 'Tablet buena',
        'area' => null,
    ])->assertCreated();
});

it('brakes the blind streak against one vendor code from one origin', function (): void {
    $codigo = (string) $this->tacos->kds_code;
    $malo = pinQueNoEs($this->pinNorte);

    // El freno de siempre sigue en pie: cinco fallos contra el mismo código y
    // el mismo origen, y el sexto ya no llega a gastar bcrypt.
    for ($intento = 1; $intento <= 5; $intento++) {
        $this->postJson('/api/kds/enrolar', [
            'codigo' => $codigo,
            'pin' => $malo,
            'device_name' => 'Tablet',
            'area' => null,
        ])->assertStatus(422);
    }

    $this->postJson('/api/kds/enrolar', [
        'codigo' => $codigo,
        'pin' => $malo,
        'device_name' => 'Tablet',
        'area' => null,
    ])->assertStatus(429)->assertJsonPath('code', 'kds_demasiados_intentos');
});

it('answers a code sent as an array with a 422 and not a 500', function (): void {
    // El limitador de la ruta corre ANTES de validar, y lee `codigo` del
    // cuerpo para componer su llave: un array ahí revienta el cast a string y
    // contesta 500. Un cuerpo raro no puede ser la forma de tumbar el freno.
    $this->postJson('/api/kds/enrolar', [
        'codigo' => ['ABCD1234', 'EFGH5678'],
        'pin' => '000000',
        'device_name' => 'Tablet',
        'area' => null,
    ])->assertStatus(422)->assertJsonValidationErrors('codigo');
});
