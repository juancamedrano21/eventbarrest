<?php

declare(strict_types=1);

namespace App\Domains\Identity\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Permisos de la matriz del documento 04. Los módulos que aún no existen
 * (catálogo, inventario, ventas, eventos) ya tienen su permiso definido
 * para que los roles nazcan completos y no haya que re-sembrar después.
 *
 * El catálogo es FIJO en código a propósito: cada permiso corresponde a una
 * capacidad implementada que alguien comprueba. Lo que el superadmin compone
 * libremente son los ROLES (plantillas), no los permisos.
 */
enum Permission: string implements HasLabel
{
    case UsersManage = 'users.manage';
    case CatalogManage = 'catalog.manage';
    case BranchesManage = 'branches.manage';
    case EventOutletsManage = 'event_outlets.manage';
    case VendorsManage = 'vendors.manage';
    case EventsManage = 'events.manage';
    case EventsSettle = 'events.settle';
    case InventoryManage = 'inventory.manage';
    case InventoryTransfer = 'inventory.transfer';
    case InventoryAdjust = 'inventory.adjust';
    case InventoryAllocateToEvent = 'inventory.allocate_to_event';
    case SalesOperate = 'sales.operate';
    case SalesVoid = 'sales.void';
    case SalesDiscount = 'sales.discount';
    case CashSessionManage = 'cash_session.manage';
    case PosDevicesManage = 'pos_devices.manage';
    case ReportsViewTenant = 'reports.view_tenant';
    case ReportsViewUnit = 'reports.view_unit';
    case FiscalManage = 'fiscal.manage';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::UsersManage => 'Equipo',
            self::CatalogManage => 'Catálogo',
            self::BranchesManage => 'Sucursales',
            self::EventOutletsManage => 'Puestos de evento',
            self::VendorsManage => 'Comercios',
            self::EventsManage => 'Eventos',
            self::EventsSettle => 'Liquidar eventos',
            self::InventoryManage => 'Inventario',
            self::InventoryTransfer => 'Traslados de stock',
            self::InventoryAdjust => 'Ajustes y mermas',
            self::InventoryAllocateToEvent => 'Asignar stock a eventos',
            self::SalesOperate => 'Vender (POS)',
            self::SalesVoid => 'Anular ventas',
            self::SalesDiscount => 'Descuentos',
            self::CashSessionManage => 'Caja',
            self::PosDevicesManage => 'Terminales POS',
            self::ReportsViewTenant => 'Reportes de la cuenta',
            self::ReportsViewUnit => 'Reportes de su unidad',
            self::FiscalManage => 'Fiscal (DGII)',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::UsersManage => 'Crear usuarios y asignar roles.',
            self::CatalogManage => 'Categorías, productos, recetas e insumos.',
            self::BranchesManage => 'Alta y gestión de sucursales del negocio.',
            self::EventOutletsManage => 'Puestos de venta dentro de un evento.',
            self::VendorsManage => 'Alta y gestión de los comercios del organizador.',
            self::EventsManage => 'Crear y administrar eventos.',
            self::EventsSettle => 'Cierre y liquidación financiera de un evento.',
            self::InventoryManage => 'Existencias y compras.',
            self::InventoryTransfer => 'Mover stock entre unidades del mismo comercio.',
            self::InventoryAdjust => 'Conteos físicos y pérdidas.',
            self::InventoryAllocateToEvent => 'Aprovisionar un evento desde el almacén.',
            self::SalesOperate => 'Operar el punto de venta.',
            self::SalesVoid => 'Anular ventas ya cobradas.',
            self::SalesDiscount => 'Aplicar descuentos.',
            self::CashSessionManage => 'Apertura y cierre de caja.',
            self::PosDevicesManage => 'Dispositivos del punto de venta.',
            self::ReportsViewTenant => 'Toda la cuenta.',
            self::ReportsViewUnit => 'Solo su sucursal o puesto.',
            self::FiscalManage => 'NCF y configuración fiscal.',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function labeledOptions(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $case): array => [$case->value => $case->getLabel()])
            ->all();
    }

    /**
     * @return array<string, string>
     */
    public static function descriptions(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $case): array => [$case->value => $case->description()])
            ->all();
    }
}
