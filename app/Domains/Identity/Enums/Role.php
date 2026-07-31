<?php

declare(strict_types=1);

namespace App\Domains\Identity\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Roles de negocio del documento 04. Se crean por tenant (spatie/permission
 * en modo teams), así que cada negocio tiene su propio juego de roles y
 * nunca comparte asignaciones con otro.
 */
enum Role: string implements HasLabel
{
    case Owner = 'owner';
    case Admin = 'admin';
    case EventManager = 'event_manager';
    case VendorManager = 'vendor_manager';
    case UnitManager = 'unit_manager';
    case Warehouse = 'warehouse';
    case Cashier = 'cashier';

    /**
     * Los formularios de Filament devuelven el enum ya convertido cuando el
     * campo se declara con ->options(self::class), pero un string cuando el
     * valor viene de la petición. Aceptamos ambos.
     */
    public static function coerce(self|string $value): self
    {
        return $value instanceof self ? $value : self::from($value);
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::Owner => 'Dueño',
            self::Admin => 'Administrador',
            self::EventManager => 'Gerente de eventos',
            self::VendorManager => 'Encargado de comercio',
            self::UnitManager => 'Gerente de unidad',
            self::Warehouse => 'Almacén',
            self::Cashier => 'Cajero',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Owner => 'Control total de la cuenta. Siempre debe existir al menos uno.',
            self::Admin => 'Gestión operativa completa: da de alta negocios, catálogo y equipo.',
            self::EventManager => 'Crea y liquida eventos, e invita a ellos los negocios ya dados de alta.',
            self::VendorManager => 'Dirige su comercio dentro del evento: catálogo, inventario y venta. Solo ve lo suyo.',
            self::UnitManager => 'Administra su sucursal o punto de venta: inventario, personal, cierres.',
            self::Warehouse => 'Compras, recepción, transferencias y conteos. Sin acceso a ventas.',
            self::Cashier => 'Opera el POS: órdenes, cobros y su propia caja.',
        };
    }

    /**
     * Roles del equipo de la cuenta. El encargado de comercio no está aquí:
     * ese rol solo existe adscrito a un comercio.
     *
     * @return array<int, self>
     */
    public static function forAccountStaff(): array
    {
        return [self::Owner, self::Admin, self::EventManager, self::UnitManager, self::Warehouse, self::Cashier];
    }

    /**
     * Roles que puede tener el personal de un comercio del evento. Los roles
     * de cuenta (dueño, administrador, gerente de eventos) se quedan en la
     * cuenta: un comercio nunca administra el evento ni el equipo.
     *
     * @return array<int, self>
     */
    public static function forVendorStaff(): array
    {
        return [self::VendorManager, self::Warehouse, self::Cashier];
    }

    public function isForVendorStaff(): bool
    {
        return in_array($this, self::forVendorStaff(), true);
    }

    /**
     * @param  array<int, self>  $roles
     * @return array<string, string>
     */
    public static function options(array $roles): array
    {
        return collect($roles)
            ->mapWithKeys(fn (self $role): array => [$role->value => $role->getLabel()])
            ->all();
    }

    /**
     * @return array<int, string>
     */
    public function permissions(): array
    {
        return match ($this) {
            self::Owner, self::Admin => Permission::values(),
            self::EventManager => [
                Permission::EventsManage->value,
                Permission::EventsSettle->value,
                Permission::EventOutletsManage->value,
                Permission::InventoryAllocateToEvent->value,
                Permission::ReportsViewUnit->value,
            ],
            self::VendorManager => [
                Permission::CatalogManage->value,
                Permission::InventoryManage->value,
                Permission::InventoryTransfer->value,
                Permission::InventoryAdjust->value,
                Permission::ReportsViewUnit->value,
                Permission::SalesOperate->value,
                Permission::SalesVoid->value,
                Permission::SalesDiscount->value,
                Permission::CashSessionManage->value,
                Permission::PosDevicesManage->value,
            ],
            self::UnitManager => [
                Permission::InventoryManage->value,
                Permission::InventoryTransfer->value,
                Permission::InventoryAdjust->value,
                Permission::ReportsViewUnit->value,
                Permission::SalesOperate->value,
                Permission::SalesVoid->value,
                Permission::SalesDiscount->value,
                Permission::CashSessionManage->value,
                Permission::PosDevicesManage->value,
            ],
            self::Warehouse => [
                Permission::InventoryManage->value,
                Permission::InventoryTransfer->value,
                Permission::InventoryAdjust->value,
                Permission::InventoryAllocateToEvent->value,
            ],
            self::Cashier => [
                Permission::SalesOperate->value,
                Permission::CashSessionManage->value,
            ],
        };
    }
}
