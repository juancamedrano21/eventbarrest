<?php

declare(strict_types=1);

use App\Domains\Catalog\Enums\DispatchArea;
use App\Domains\Catalog\Enums\ProductType;
use App\Domains\Catalog\Models\Category;
use App\Domains\Catalog\Models\Product;
use App\Domains\EventManagement\Actions\CreateEvent;
use App\Domains\EventManagement\Enums\EventStatus;
use App\Domains\EventManagement\Enums\VendorStatus;
use App\Domains\EventManagement\Models\Event;
use App\Domains\EventManagement\Models\Vendor;
use App\Domains\EventManagement\VendorContext;
use App\Domains\Operations\Enums\OperatingUnitKind;
use App\Domains\Platform\Actions\CreateTenant;
use App\Domains\Platform\Enums\TenantType;
use App\Domains\Sales\Enums\ItbisMode;
use App\Domains\Tenancy\TenantContext;
use Illuminate\Testing\TestResponse;

/**
 * La pantalla de Menús: quién vende y qué vende cada uno.
 *
 * Lo que se prueba aquí es lo que no se descubre mirando una respuesta buena:
 * que la carta de un comercio no puede leerse desde el evento equivocado
 * cambiando un número en la URL —VendorScope falla ABIERTO, así que sin el
 * filtro por participación esa petición sería un 200 perfectamente válido—,
 * que un producto desactivado desaparece de verdad, y que el precio que se
 * publica es el que va a cobrar la caja y no el de la etiqueta.
 */
beforeEach(function (): void {
    $this->organizador = app(CreateTenant::class)('Bocao Food Fest', null, TenantType::Organizer);

    app(TenantContext::class)->runAs($this->organizador, function (): void {
        $this->evento = app(CreateEvent::class)(
            'Bocao 2026', now()->subDay(), now()->addDay(), 'Sambil', EventStatus::Active,
        );

        $this->tacos = vendorIn($this->evento, 'Tacos del Puerto');
        $this->norte = outletFor($this->evento, 'Puesto Norte', OperatingUnitKind::Kitchen, $this->tacos);

        $this->pizzas = vendorIn($this->evento, 'Pizzas Doña Ana');
        outletFor($this->evento, 'Puesto Sur', OperatingUnitKind::Bar, $this->pizzas);

        app(VendorContext::class)->runAs($this->tacos, function (): void {
            $comida = Category::create(['name' => 'Comida', 'dispatch' => DispatchArea::Kitchen]);

            $this->taco = Product::create([
                'category_id' => $comida->id, 'name' => 'Taco al pastor',
                'type' => ProductType::Simple, 'price_cents' => 25000,
            ]);
        });

        app(VendorContext::class)->runAs($this->pizzas, function (): void {
            $comida = Category::create(['name' => 'Pizzas', 'dispatch' => DispatchArea::Kitchen]);

            $this->pizza = Product::create([
                'category_id' => $comida->id, 'name' => 'Pizza margarita',
                'type' => ProductType::Simple, 'price_cents' => 40000,
            ]);
        });
    });

    $this->codigo = (string) $this->evento->public_code;
});

afterEach(function (): void {
    app(TenantContext::class)->clear();
    app(VendorContext::class)->clear();
});

function pedirLosComercios(string $codigo, ?string $etag = null): TestResponse
{
    $cabeceras = $etag === null ? [] : ['If-None-Match' => $etag];

    return test()->getJson("/api/event-app/eventos/{$codigo}/comercios", $cabeceras);
}

function pedirLaCarta(string $codigo, int $comercio, ?string $etag = null): TestResponse
{
    $cabeceras = $etag === null ? [] : ['If-None-Match' => $etag];

    return test()->getJson("/api/event-app/eventos/{$codigo}/comercios/{$comercio}/menu", $cabeceras);
}

it('lists the vendors of the event with their outlets', function (): void {
    $respuesta = pedirLosComercios($this->codigo)->assertOk();

    $respuesta->assertJsonCount(2, 'comercios')
        // Por nombre, para que la lista no baile entre peticiones.
        ->assertJsonPath('comercios.0.nombre', 'Pizzas Doña Ana')
        ->assertJsonPath('comercios.1.nombre', 'Tacos del Puerto')
        ->assertJsonPath('comercios.1.id', $this->tacos->id)
        ->assertJsonPath('comercios.1.logo_url', null)
        ->assertJsonPath('comercios.1.puestos.0.nombre', 'Puesto Norte')
        // El vocabulario que publica el contrato va en español: la app
        // compara contra estas cadenas.
        ->assertJsonPath('comercios.1.puestos.0.tipo', 'cocina')
        ->assertJsonPath('comercios.0.puestos.0.tipo', 'barra');
});

it('drops a vendor the organiser suspended mid afternoon', function (): void {
    app(TenantContext::class)->runAs($this->organizador, function (): void {
        $this->pizzas->update(['status' => VendorStatus::Suspended]);
    });

    pedirLosComercios($this->codigo)->assertOk()
        ->assertJsonCount(1, 'comercios')
        ->assertJsonPath('comercios.0.nombre', 'Tacos del Puerto');

    // Y su carta deja de existir en la misma petición: no hay token que
    // revocar en esta puerta, así que lo único que la apaga es preguntar.
    pedirLaCarta($this->codigo, $this->pizzas->id)
        ->assertNotFound()
        ->assertJsonPath('code', 'comercio_desconocido');
});

it('never lists the vendors of another festival of the same organiser', function (): void {
    [$otroEvento, $ajeno] = app(TenantContext::class)->runAs($this->organizador, function (): array {
        $otro = app(CreateEvent::class)(
            'Bocao Navidad', now()->addMonth(), now()->addMonth()->addDay(), null, EventStatus::Active,
        );

        return [$otro, vendorIn($otro, 'Chimis del Malecón')];
    });

    // Misma cuenta: TenantScope no separa nada aquí. Lo que separa es la
    // participación en el evento.
    expect($ajeno->tenant_id)->toBe($this->tacos->tenant_id);

    $respuesta = pedirLosComercios($this->codigo)->assertOk();

    expect(collect($respuesta->json('comercios'))->pluck('id')->all())
        ->not->toContain($ajeno->id);

    pedirLosComercios((string) $otroEvento->public_code)->assertOk()
        ->assertJsonCount(1, 'comercios')
        ->assertJsonPath('comercios.0.nombre', 'Chimis del Malecón');
});

it('refuses to serve the menu of a vendor from another event', function (): void {
    $ajeno = app(TenantContext::class)->runAs($this->organizador, function (): Vendor {
        $otro = app(CreateEvent::class)(
            'Bocao Navidad', now()->addMonth(), now()->addMonth()->addDay(), null, EventStatus::Active,
        );

        return vendorIn($otro, 'Chimis del Malecón');
    });

    // El id existe, la cuenta es la misma y el comercio está activo: sin el
    // filtro por participación esto sería un 200 con su carta dentro.
    pedirLaCarta($this->codigo, $ajeno->id)
        ->assertNotFound()
        ->assertJsonPath('code', 'comercio_desconocido');
});

it('refuses to serve the menu of a vendor from another account', function (): void {
    $otroOrganizador = app(CreateTenant::class)('Otro Organizador', null, TenantType::Organizer);

    $ajeno = app(TenantContext::class)->runAs($otroOrganizador, function (): Vendor {
        $evento = app(CreateEvent::class)(
            'Otro Festival', now(), now()->addDay(), null, EventStatus::Active,
        );

        return vendorIn($evento, 'Comercio Ajeno');
    });

    pedirLaCarta($this->codigo, $ajeno->id)
        ->assertNotFound()
        ->assertJsonPath('code', 'comercio_desconocido');
});

it('serves only the menu of the vendor in the url', function (): void {
    $respuesta = pedirLaCarta($this->codigo, $this->tacos->id)->assertOk();

    $respuesta->assertJsonPath('comercio.id', $this->tacos->id)
        ->assertJsonPath('comercio.nombre', 'Tacos del Puerto')
        ->assertJsonCount(1, 'categorias')
        ->assertJsonPath('categorias.0.nombre', 'Comida')
        ->assertJsonCount(1, 'categorias.0.productos')
        ->assertJsonPath('categorias.0.productos.0.nombre', 'Taco al pastor')
        ->assertJsonPath('categorias.0.productos.0.moneda', 'DOP')
        ->assertJsonPath('categorias.0.productos.0.disponible', true)
        ->assertJsonPath('categorias.0.productos.0.foto_url', null);

    // Nada del vecino del mismo festival, ni su categoría ni su producto.
    $respuesta->assertJsonMissing(['nombre' => 'Pizza margarita']);
});

it('publishes the price the till would charge when the vendor adds ITBIS', function (): void {
    // Con el impuesto INCLUIDO —la modalidad heredada de la cuenta— el
    // precio de carta es el que se cobra y no crece.
    pedirLaCarta($this->codigo, $this->tacos->id)->assertOk()
        ->assertJsonPath('categorias.0.productos.0.precio_cents', 25000);

    app(TenantContext::class)->runAs($this->organizador, function (): void {
        $this->tacos->update(['itbis_mode' => ItbisMode::Added]);
    });

    // La carta se sirve de una caché corta, así que un cambio del catálogo
    // tarda en verse lo que dure su ventana y ni un segundo más. El salto
    // está aquí y no escondido en un flush a propósito: es el precio de que
    // la puerta sea barata, y tiene que ser visible en un test.
    $this->travel(11)->seconds();

    // Y con el impuesto POR FUERA, el precio de carta es la base: publicar
    // 250 pesos cuando la caja va a cobrar 295 sería un menú que miente por
    // un 18 % delante de una cola.
    pedirLaCarta($this->codigo, $this->tacos->id)->assertOk()
        ->assertJsonPath('categorias.0.productos.0.precio_cents', 29500);
});

it('does not add ITBIS to an exempt product', function (): void {
    app(TenantContext::class)->runAs($this->organizador, function (): void {
        $this->tacos->update(['itbis_mode' => ItbisMode::Added]);

        app(VendorContext::class)->runAs($this->tacos, function (): void {
            $this->taco->update(['itbis_exempt' => true]);
        });
    });

    pedirLaCarta($this->codigo, $this->tacos->id)->assertOk()
        ->assertJsonPath('categorias.0.productos.0.precio_cents', 25000);
});

it('hides an inactive product and the category it leaves empty', function (): void {
    app(TenantContext::class)->runAs($this->organizador, function (): void {
        app(VendorContext::class)->runAs($this->tacos, function (): void {
            $bebidas = Category::create(['name' => 'Bebidas', 'dispatch' => DispatchArea::Bar]);

            Product::create([
                'category_id' => $bebidas->id, 'name' => 'Morir soñando',
                'type' => ProductType::Simple, 'price_cents' => 12000, 'active' => false,
            ]);
        });
    });

    $respuesta = pedirLaCarta($this->codigo, $this->tacos->id)->assertOk();

    // Un producto desactivado DESAPARECE; no se marca agotado. Y la
    // categoría que se queda sin nada publicable tampoco viaja: sería un
    // apartado vacío en la carta, que se lee como un fallo.
    $respuesta->assertJsonMissing(['nombre' => 'Morir soñando'])
        ->assertJsonCount(1, 'categorias')
        ->assertJsonPath('categorias.0.nombre', 'Comida');
});

it('publishes photo urls a phone can actually reach', function (): void {
    app(TenantContext::class)->runAs($this->organizador, function (): void {
        app(VendorContext::class)->runAs($this->tacos, function (): void {
            $this->taco->update(['image_path' => 'products/taco.jpg']);
        });
    });

    $url = pedirLaCarta($this->codigo, $this->tacos->id)->assertOk()
        ->json('categorias.0.productos.0.foto_url');

    // Absoluta: al otro lado hay un widget de imagen sin ninguna página de
    // la que colgar una ruta relativa.
    expect($url)->toBeString()->toStartWith('http')->toContain('products/taco.jpg');
});

it('serves a 304 for the menu while nothing changes', function (): void {
    $primera = pedirLaCarta($this->codigo, $this->tacos->id)->assertOk();
    $etag = $primera->headers->get('ETag');

    $this->travel(2)->seconds();

    // El ETag no lleva server_time dentro: si lo llevara, el 304 no
    // ocurriría jamás y cada teléfono se bajaría la carta entera. Y este 304
    // sale de la caché: no vuelve a consultar el catálogo, solo lo compara.
    pedirLaCarta($this->codigo, $this->tacos->id, (string) $etag)->assertStatus(304);

    app(TenantContext::class)->runAs($this->organizador, function (): void {
        app(VendorContext::class)->runAs($this->tacos, function (): void {
            $this->taco->update(['price_cents' => 27000]);
        });
    });

    // Pasada la ventana de la caché, el precio nuevo. Que el 304 pare cuando
    // algo cambia sigue siendo la garantía; lo que la caché añade es un techo
    // de diez segundos a cuándo se entera.
    $this->travel(11)->seconds();

    pedirLaCarta($this->codigo, $this->tacos->id, (string) $etag)->assertOk()
        ->assertJsonPath('categorias.0.productos.0.precio_cents', 27000);
});

it('answers 404 for the menu of an event nobody owns', function (): void {
    pedirLaCarta('NOEXISTE', $this->tacos->id)
        ->assertNotFound()
        ->assertJsonPath('code', 'evento_desconocido');
});

it('does not fall over when the vendor id is not a number', function (): void {
    // La URL la escribe quien llama: un id que no es un id tiene que ser un
    // 404, no un 500 con la traza puesta.
    test()->getJson("/api/event-app/eventos/{$this->codigo}/comercios/nada/menu")
        ->assertNotFound()
        ->assertJsonPath('code', 'comercio_desconocido');
});

it('keeps a vendor without outlets on the list', function (): void {
    $sinPuestos = app(TenantContext::class)->runAs(
        $this->organizador,
        fn (): Vendor => vendorIn($this->evento, 'Aún Montando'),
    );

    pedirLosComercios($this->codigo)->assertOk()
        ->assertJsonCount(3, 'comercios')
        ->assertJsonPath('comercios.0.nombre', 'Aún Montando')
        ->assertJsonPath('comercios.0.puestos', []);

    expect($sinPuestos->id)->toBeInt();
});

/** El evento de otra cuenta no puede ni asomarse por el código de esta. */
it('never crosses accounts through the event code', function (): void {
    $otro = app(CreateTenant::class)('Otro Organizador', null, TenantType::Organizer);

    $ajeno = app(TenantContext::class)->runAs($otro, fn (): Event => app(CreateEvent::class)(
        'Otro Festival', now(), now()->addDay(), null, EventStatus::Active,
    ));

    app(TenantContext::class)->runAs($otro, function () use ($ajeno): void {
        vendorIn($ajeno, 'Comercio Del Otro');
    });

    $respuesta = pedirLosComercios((string) $ajeno->public_code)->assertOk();

    expect(collect($respuesta->json('comercios'))->pluck('nombre')->all())
        ->toBe(['Comercio Del Otro']);
});
