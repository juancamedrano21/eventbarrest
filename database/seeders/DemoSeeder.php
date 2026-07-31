<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domains\Business\Actions\CreateBranch;
use App\Domains\Catalog\Enums\DispatchArea;
use App\Domains\Catalog\Enums\ProductType;
use App\Domains\Catalog\Models\Category;
use App\Domains\Catalog\Models\Product;
use App\Domains\EventManagement\Actions\CreateEvent;
use App\Domains\EventManagement\Actions\CreateEventOutlet;
use App\Domains\EventManagement\Actions\CreateVendor;
use App\Domains\EventManagement\Actions\InviteVendorToEvent;
use App\Domains\EventManagement\Enums\EventStatus;
use App\Domains\EventManagement\VendorContext;
use App\Domains\Identity\Actions\CreateTenantUser;
use App\Domains\Identity\Enums\Role;
use App\Domains\Inventory\Actions\RegisterPurchase;
use App\Domains\Inventory\Enums\MeasurementUnit;
use App\Domains\Inventory\Models\InventoryItem;
use App\Domains\Operations\Enums\OperatingUnitKind;
use App\Domains\Platform\Actions\CreateTenant;
use App\Domains\Platform\Enums\TenantStatus;
use App\Domains\Platform\Enums\TenantType;
use App\Domains\Platform\Models\Tenant;
use App\Domains\Tenancy\TenantContext;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Entorno de prueba completo: una cuenta de cada mundo con su equipo, su
 * catálogo y stock real (comprado por el ledger, así el costo promedio y el
 * margen salen de datos vivos). Solo para local: nunca en producción.
 *
 * Idempotente: si la cuenta demo ya existe, no duplica nada.
 */
// Sin WithoutModelEvents a propósito: este seeder siembra por las puertas
// legítimas del dominio, y esas puertas SON los eventos de modelo (tipo de
// cuenta, tenant_id, guards de mundo, ledger).
class DemoSeeder extends Seeder
{
    public const PASSWORD = 'Demo-2026';

    public function run(): void
    {
        if (! app()->environment('local', 'testing')) {
            throw new RuntimeException('DemoSeeder es solo para entornos locales.');
        }

        if (Tenant::query()->where('name', 'Bar Demo')->exists()) {
            $this->command?->info('El entorno demo ya existe; nada que hacer.');

            return;
        }

        $context = app(TenantContext::class);

        // ── Mundo negocios ────────────────────────────────────────────────
        $bar = app(CreateTenant::class)('Bar Demo', '131111119', TenantType::Business, TenantStatus::Active);

        app(CreateTenantUser::class)($bar, 'Dueño Bar', 'dueno@bar.demo', self::PASSWORD, Role::Owner);
        app(CreateTenantUser::class)($bar, 'Gerente Bar', 'gerente@bar.demo', self::PASSWORD, Role::UnitManager);
        app(CreateTenantUser::class)($bar, 'Cajero Bar', 'cajero@bar.demo', self::PASSWORD, Role::Cashier);

        $context->runAs($bar, function (): void {
            $centro = app(CreateBranch::class)('Sucursal Centro');

            $cervezas = Category::create(['name' => 'Cervezas', 'dispatch' => DispatchArea::Bar]);
            $cocteles = Category::create(['name' => 'Cócteles', 'dispatch' => DispatchArea::Bar]);
            Category::create(['name' => 'Picadera', 'dispatch' => DispatchArea::Kitchen]);

            $ron = InventoryItem::create(['name' => 'Ron blanco', 'base_unit' => MeasurementUnit::Milliliter, 'cost_cents' => 0]);
            $limon = InventoryItem::create(['name' => 'Limón', 'base_unit' => MeasurementUnit::Unit, 'cost_cents' => 0]);
            $presidenteUnd = InventoryItem::create(['name' => 'Presidente (unidad)', 'base_unit' => MeasurementUnit::Unit, 'cost_cents' => 0]);

            Product::create([
                'category_id' => $cervezas->id,
                'name' => 'Presidente',
                'type' => ProductType::Simple,
                'price_cents' => 35000,
                'track_stock' => true,
                'inventory_item_id' => $presidenteUnd->id,
            ]);

            $mojito = Product::create([
                'category_id' => $cocteles->id,
                'name' => 'Mojito',
                'type' => ProductType::Recipe,
                'price_cents' => 45000,
            ]);
            $mojito->recipeItems()->createMany([
                ['inventory_item_id' => $ron->id, 'quantity' => 60],
                ['inventory_item_id' => $limon->id, 'quantity' => 1],
            ]);

            // Stock por la puerta legítima: compras reales fijan el costo.
            app(RegisterPurchase::class)($centro, $ron, 5000, 80, 'Compra inicial');
            app(RegisterPurchase::class)($centro, $limon, 200, 1500, 'Compra inicial');
            app(RegisterPurchase::class)($centro, $presidenteUnd, 120, 9000, 'Compra inicial');
        });

        // ── Mundo eventos ─────────────────────────────────────────────────
        // El organizador arma el evento e invita a los comercios; cada
        // comercio tiene su gente, su catálogo y su stock por separado.
        $productora = app(CreateTenant::class)('Producciones Demo', null, TenantType::Organizer, TenantStatus::Active);

        app(CreateTenantUser::class)($productora, 'Productor', 'productor@eventos.demo', self::PASSWORD, Role::Owner);
        app(CreateTenantUser::class)($productora, 'Gerente Eventos', 'gerente@eventos.demo', self::PASSWORD, Role::EventManager);

        $context->runAs($productora, function () use ($productora): void {
            $festival = app(CreateEvent::class)(
                'Festival del Mar 2026',
                now()->addWeeks(2)->setTime(16, 0),
                now()->addWeeks(2)->addDays(2)->setTime(2, 0),
                'Malecón de Santo Domingo',
                EventStatus::Active,
            );

            $cerveceria = app(CreateVendor::class)('La Cervecería del Malecón');
            $tacos = app(CreateVendor::class)('Tacos del Puerto');

            app(InviteVendorToEvent::class)($festival, $cerveceria, 1000); // 10 %
            app(InviteVendorToEvent::class)($festival, $tacos, 1500);      // 15 %

            $barra = app(CreateEventOutlet::class)($festival, $cerveceria, 'Barra principal', OperatingUnitKind::Bar);
            $puestoTacos = app(CreateEventOutlet::class)($festival, $tacos, 'Puesto de tacos', OperatingUnitKind::Kitchen);

            app(CreateTenantUser::class)($productora, 'Encargada Cervecería', 'encargada@cerveceria.demo', self::PASSWORD, Role::VendorManager, $cerveceria);
            app(CreateTenantUser::class)($productora, 'Encargado Tacos', 'encargado@tacos.demo', self::PASSWORD, Role::VendorManager, $tacos);

            $vendors = app(VendorContext::class);

            $vendors->runAs($cerveceria, function () use ($barra): void {
                $tragos = Category::create(['name' => 'Tragos', 'dispatch' => DispatchArea::Bar]);
                $ron = InventoryItem::create(['name' => 'Ron añejo', 'base_unit' => MeasurementUnit::Milliliter, 'cost_cents' => 0]);

                $cubaLibre = Product::create([
                    'category_id' => $tragos->id,
                    'name' => 'Cuba Libre',
                    'type' => ProductType::Recipe,
                    'price_cents' => 40000,
                ]);
                $cubaLibre->recipeItems()->create(['inventory_item_id' => $ron->id, 'quantity' => 60]);

                app(RegisterPurchase::class)($barra, $ron, 10000, 95, 'Aprovisionamiento festival');
            });

            $vendors->runAs($tacos, function () use ($puestoTacos): void {
                $comida = Category::create(['name' => 'Tacos', 'dispatch' => DispatchArea::Kitchen]);
                $carne = InventoryItem::create(['name' => 'Carne al pastor', 'base_unit' => MeasurementUnit::Gram, 'cost_cents' => 0]);

                $taco = Product::create([
                    'category_id' => $comida->id,
                    'name' => 'Taco al pastor',
                    'type' => ProductType::Recipe,
                    'price_cents' => 25000,
                ]);
                $taco->recipeItems()->create(['inventory_item_id' => $carne->id, 'quantity' => 120]);

                app(RegisterPurchase::class)($puestoTacos, $carne, 20000, 45, 'Aprovisionamiento festival');
            });
        });

        $this->command?->info('Entorno demo creado. Contraseña de todos: '.self::PASSWORD);
    }
}
