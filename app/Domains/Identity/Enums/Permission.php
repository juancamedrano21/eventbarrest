<?php

declare(strict_types=1);

namespace App\Domains\Identity\Enums;

/**
 * Permisos de la matriz del documento 04. Los módulos que aún no existen
 * (catálogo, inventario, ventas, eventos) ya tienen su permiso definido
 * para que los roles nazcan completos y no haya que re-sembrar después.
 */
enum Permission: string
{
    case UsersManage = 'users.manage';
    case CatalogManage = 'catalog.manage';
    case OperatingUnitsManage = 'operating_units.manage';
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
}
