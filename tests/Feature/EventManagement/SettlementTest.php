<?php

declare(strict_types=1);

use App\Domains\Catalog\Enums\DispatchArea;
use App\Domains\Catalog\Enums\ProductType;
use App\Domains\Catalog\Models\Category;
use App\Domains\Catalog\Models\Product;
use App\Domains\EventManagement\Actions\CreateEvent;
use App\Domains\EventManagement\Actions\CreateEventOutlet;
use App\Domains\EventManagement\Actions\CreateVendor;
use App\Domains\EventManagement\Actions\InviteVendorToEvent;
use App\Domains\EventManagement\Actions\SettleEvent;
use App\Domains\EventManagement\Enums\CommissionBase;
use App\Domains\EventManagement\Enums\EventStatus;
use App\Domains\EventManagement\Exceptions\VendorException;
use App\Domains\EventManagement\Models\EventSettlement;
use App\Domains\EventManagement\Queries\SettlementFigures;
use App\Domains\EventManagement\VendorContext;
use App\Domains\Identity\Actions\CreateTenantUser;
use App\Domains\Identity\Enums\Role;
use App\Domains\Operations\Enums\OperatingUnitKind;
use App\Domains\Platform\Actions\CreateTenant;
use App\Domains\Platform\Enums\TenantType;
use App\Domains\Sales\Actions\CloseCashSession;
use App\Domains\Sales\Actions\OpenCashSession;
use App\Domains\Sales\Actions\PayOrder;
use App\Domains\Sales\Actions\PlaceOrder;
use App\Domains\Sales\Actions\RefundOrder;
use App\Domains\Sales\Enums\PaymentMethod;
use App\Domains\Tenancy\TenantContext;

/**
 * La liquidación de un evento: lo que cada comercio vendió y lo que de eso le
 * toca al organizador. Es dinero que dos partes miran y sobre el que se paga,
 * así que la aritmética se fija aquí con números a mano.
 */
beforeEach(function (): void {
    $this->organizer = app(CreateTenant::class)('Bocao Food Fest', null, TenantType::Organizer);

    app(TenantContext::class)->runAs($this->organizer, function (): void {
        $this->event = app(CreateEvent::class)('Bocao 2026', now()->subDays(2), now()->subDay());
        $this->vendor = app(CreateVendor::class)('Tacos del Puerto');
        // 10 % de comisión pactada.
        app(InviteVendorToEvent::class)($this->event, $this->vendor, 1000);
        $this->puesto = app(CreateEventOutlet::class)(
            $this->event, $this->vendor, 'Puesto Norte', OperatingUnitKind::Kitchen,
        );

        app(VendorContext::class)->runAs($this->vendor, function (): void {
            $cat = Category::create(['name' => 'Comida', 'dispatch' => DispatchArea::Kitchen]);
            // 1.000,00 con el ITBIS incluido: el caso del ejemplo con el que
            // se decidió la regla de la comisión.
            $this->producto = Product::create([
                'category_id' => $cat->id, 'name' => 'Tacos',
                'type' => ProductType::Simple, 'price_cents' => 100000,
            ]);
            $this->caja = app(OpenCashSession::class)($this->puesto, null, 0);
        });
    });

    $this->ref = 0;
});

afterEach(function (): void {
    app(TenantContext::class)->clear();
    app(VendorContext::class)->clear();
});

/** Vende y cobra una unidad en el puesto del comercio. */
function ventaDelPuesto(bool $conPropina = true): object
{
    return app(TenantContext::class)->runAs(test()->organizer, fn () => app(VendorContext::class)->runAs(
        test()->vendor,
        function () use ($conPropina) {
            $orden = app(PlaceOrder::class)(
                test()->caja,
                [['product_id' => test()->producto->id, 'quantity' => 1]],
                'pos-'.str_pad((string) ++test()->ref, 4, '0', STR_PAD_LEFT),
                null,
                $conPropina,
            );

            return app(PayOrder::class)($orden, PaymentMethod::Cash, $orden->total_cents);
        },
    ));
}

function cerrarCaja(): void
{
    app(TenantContext::class)->runAs(test()->organizer, fn () => app(VendorContext::class)->runAs(
        test()->vendor,
        fn () => app(CloseCashSession::class)(test()->caja, 0),
    ));
}

it('charges commission on the whole ticket when that is the rule', function (): void {
    // Regla por defecto: todo lo cobrado, propina e impuesto incluidos.
    $orden = ventaDelPuesto();

    // 1.000,00 con ITBIS incluido: la propina legal es el 10 % de la base
    // sin impuesto (847,46) = 84,75. Total cobrado = 1.084,75.
    expect($orden->total_cents)->toBe(108475)
        ->and($orden->tip_cents)->toBe(8475)
        ->and($orden->itbis_cents)->toBe(15254);

    $cifras = app(TenantContext::class)->runAs(
        $this->organizer,
        fn () => app(SettlementFigures::class)->forEvent($this->event),
    );

    // 10 % de 1.084,75 = 108,48 — el comercio paga sobre la propina de sus
    // meseros y sobre el impuesto que le debe a la DGII.
    expect($cifras->first()->commissionCents)->toBe(10848)
        ->and($cifras->first()->commissionBase)->toBe(CommissionBase::Total);
});

it('charges only on the vendor sale when the organizer picks that rule', function (): void {
    $this->organizer->update(['commission_base' => CommissionBase::NetSale]);

    ventaDelPuesto();

    $cifras = app(TenantContext::class)->runAs(
        $this->organizer,
        fn () => app(SettlementFigures::class)->forEvent($this->event),
    );

    // Base: 1.084,75 − 84,75 de propina − 152,54 de ITBIS = 847,46.
    // El 10 % son 84,75, que es un 28 % menos que sobre el total.
    expect($cifras->first()->commissionBaseCents)->toBe(84746)
        ->and($cifras->first()->commissionCents)->toBe(8475);
});

it('respects the rule each sale was charged with, not the one in force today', function (): void {
    // Una venta con la regla vieja...
    ventaDelPuesto();

    // ...el organizador cambia el ajuste a media fiesta...
    $this->organizer->update(['commission_base' => CommissionBase::NetSale]);

    // ...y otra venta con la nueva.
    ventaDelPuesto();

    $cifras = app(TenantContext::class)->runAs(
        $this->organizer,
        fn () => app(SettlementFigures::class)->forEvent($this->event),
    );

    // 108,48 de la primera + 84,75 de la segunda. Cambiar el ajuste NO
    // reescribe lo que ya se cobró.
    expect($cifras->first()->commissionCents)->toBe(10848 + 8475)
        ->and($cifras->first()->ordersCount)->toBe(2);
});

it('never charges commission on money the vendor gave back', function (): void {
    $orden = ventaDelPuesto();

    // Se devuelve la mitad exacta de la venta.
    app(TenantContext::class)->runAs($this->organizer, fn () => app(VendorContext::class)->runAs(
        $this->vendor,
        fn () => app(RefundOrder::class)($orden, $this->caja, 54238, 'Cliente insatisfecho'),
    ));

    $cifras = app(TenantContext::class)->runAs(
        $this->organizer,
        fn () => app(SettlementFigures::class)->forEvent($this->event),
    );

    $fila = $cifras->first();

    // La base baja en la misma proporción que lo devuelto, y con ella la
    // comisión: cobrarle al comercio por dinero que devolvió sería indebido.
    expect($fila->refundedCents)->toBe(54238)
        ->and($fila->commissionCents)->toBeLessThan(10848)
        ->and($fila->commissionCents)->toBe((int) round(
            (int) round(108475 * (108475 - 54238) / 108475) * 1000 / 10000
        ))
        // Y lo que le queda al comercio es lo cobrado menos lo devuelto
        // menos la comisión.
        ->and($fila->netCents)->toBe(108475 - 54238 - $fila->commissionCents);
});

it('freezes the figures instead of recomputing them later', function (): void {
    ventaDelPuesto();
    cerrarCaja();

    $creadas = app(TenantContext::class)->runAs(
        $this->organizer,
        fn (): int => app(SettleEvent::class)($this->event),
    );

    expect($creadas)->toBe(1)
        ->and($this->event->fresh()->status)->toBe(EventStatus::Settled);

    $liquidacion = EventSettlement::query()->withoutGlobalScopes()
        ->where('event_id', $this->event->id)->sole();

    expect($liquidacion->gross_cents)->toBe(108475)
        ->and($liquidacion->commission_cents)->toBe(10848)
        ->and($liquidacion->commission_bps)->toBe(1000)
        ->and($liquidacion->net_cents)->toBe(108475 - 10848)
        ->and($liquidacion->settled_at)->not->toBeNull()
        ->and($liquidacion->isPaid())->toBeFalse();
});

it('refuses to settle while a till is still open', function (): void {
    ventaDelPuesto();

    // La caja sigue abierta: todavía puede entrar dinero.
    app(TenantContext::class)->runAs(
        $this->organizer,
        fn () => expect(fn () => app(SettleEvent::class)($this->event))
            ->toThrow(VendorException::class),
    );

    expect($this->event->fresh()->status)->not->toBe(EventStatus::Settled);
});

it('refuses to settle the same event twice', function (): void {
    ventaDelPuesto();
    cerrarCaja();

    app(TenantContext::class)->runAs($this->organizer, function (): void {
        app(SettleEvent::class)($this->event);

        expect(fn () => app(SettleEvent::class)($this->event->fresh()))
            ->toThrow(VendorException::class);
    });

    expect(EventSettlement::query()->withoutGlobalScopes()
        ->where('event_id', $this->event->id)->count())->toBe(1);
});

it('gives every participating vendor its own account', function (): void {
    ventaDelPuesto();
    cerrarCaja();

    // Un segundo comercio que participó pero no vendió nada no aparece: su
    // cuenta sería una fila de ceros.
    app(TenantContext::class)->runAs($this->organizer, function (): void {
        $otro = app(CreateVendor::class)('Cervecería');
        app(InviteVendorToEvent::class)($this->event, $otro, 1500);
    });

    $cifras = app(TenantContext::class)->runAs(
        $this->organizer,
        fn () => app(SettlementFigures::class)->forEvent($this->event),
    );

    expect($cifras)->toHaveCount(1)
        ->and($cifras->first()->vendorName)->toBe('Tacos del Puerto');
});

// ───────────────────────── La puerta ─────────────────────────

it('shows a live draft before settling and the frozen figures after', function (): void {
    $owner = app(CreateTenantUser::class)(
        $this->organizer, 'Ana', 'ana@x.test', 'Secreta-2026', Role::Owner,
    );

    ventaDelPuesto();

    // Antes: borrador, con el botón de liquidar.
    $this->actingAs($owner)
        ->get("/event-panel/eventos/{$this->event->id}/liquidacion")
        ->assertOk()
        ->assertSee('Tacos del Puerto')
        ->assertSee('Borrador')
        ->assertSee('Liquidar evento');

    cerrarCaja();

    $this->actingAs($owner)
        ->post("/event-panel/eventos/{$this->event->id}/liquidar")
        ->assertRedirect();

    // Después: documento cerrado, sin botón de liquidar.
    $this->actingAs($owner)
        ->get("/event-panel/eventos/{$this->event->id}/liquidacion")
        ->assertOk()
        ->assertSee('Cerrada el')
        ->assertDontSee('Liquidar evento');
});

it('needs the settle permission to close the accounts', function (): void {
    ventaDelPuesto();
    cerrarCaja();

    $gestor = app(CreateTenantUser::class)(
        $this->organizer, 'Eva', 'eva@x.test', 'Secreta-2026', Role::VendorManager, $this->vendor,
    );

    // Personal de comercio: la frontera de mundo del panel lo corta.
    $this->actingAs($gestor)
        ->post("/event-panel/eventos/{$this->event->id}/liquidar")
        ->assertForbidden();

    expect($this->event->fresh()->status)->not->toBe(EventStatus::Settled);
});

it('records who collected each commission and when', function (): void {
    $owner = app(CreateTenantUser::class)(
        $this->organizer, 'Ana', 'ana@x.test', 'Secreta-2026', Role::Owner,
    );

    ventaDelPuesto();
    cerrarCaja();

    app(TenantContext::class)->runAs($this->organizer, fn () => app(SettleEvent::class)($this->event));

    $liquidacion = EventSettlement::query()->withoutGlobalScopes()
        ->where('event_id', $this->event->id)->sole();

    $this->actingAs($owner)
        ->post("/event-panel/eventos/{$this->event->id}/liquidacion/{$liquidacion->id}/cobrada", [
            'payment_note' => 'Transferencia ref. 88213',
        ])
        ->assertRedirect();

    $fresca = $liquidacion->fresh();

    expect($fresca->isPaid())->toBeTrue()
        ->and($fresca->payment_note)->toBe('Transferencia ref. 88213')
        ->and($fresca->paid_by)->toBe($owner->id);

    // Y no se cobra dos veces la misma comisión.
    $this->actingAs($owner)
        ->from("/event-panel/eventos/{$this->event->id}/liquidacion")
        ->post("/event-panel/eventos/{$this->event->id}/liquidacion/{$liquidacion->id}/cobrada")
        ->assertSessionHasErrors('payment');
});

it('closes a settled event to refunds', function (): void {
    $orden = ventaDelPuesto();
    cerrarCaja();

    app(TenantContext::class)->runAs($this->organizer, fn () => app(SettleEvent::class)($this->event));

    // La cuenta ya está cerrada y probablemente pagada: devolver ahora
    // movería un número que las dos partes firmaron.
    app(TenantContext::class)->runAs($this->organizer, fn () => app(VendorContext::class)->runAs(
        $this->vendor,
        fn () => expect(fn () => app(RefundOrder::class)($orden, $this->caja, 1000, 'Tardío'))
            ->toThrow(VendorException::class),
    ));
});

it('never lets the status dropdown settle an event behind the accounts', function (): void {
    $owner = app(CreateTenantUser::class)(
        $this->organizer, 'Ana', 'ana@x.test', 'Secreta-2026', Role::Owner,
    );

    ventaDelPuesto();
    cerrarCaja();

    // Marcar «liquidado» a mano dejaría el evento cerrado sin una sola
    // cuenta calculada.
    $this->actingAs($owner)
        ->from("/event-panel/eventos/{$this->event->id}")
        ->post("/event-panel/eventos/{$this->event->id}", [
            'name' => 'Bocao 2026', 'venue' => null,
            'starts_at' => $this->event->starts_at->format('Y-m-d\TH:i'),
            'ends_at' => $this->event->ends_at->format('Y-m-d\TH:i'),
            'status' => EventStatus::Settled->value,
        ])
        ->assertSessionHasErrors('status');

    expect($this->event->fresh()->status)->not->toBe(EventStatus::Settled)
        ->and(EventSettlement::query()->withoutGlobalScopes()->count())->toBe(0);
});
