<?php

declare(strict_types=1);

namespace App\Http\Controllers\EventPanel;

use App\Domains\Catalog\Models\Category;
use App\Domains\Catalog\Models\Product;
use App\Domains\EventManagement\Actions\CreateEventOutlet;
use App\Domains\EventManagement\Actions\InviteVendorToEvent;
use App\Domains\EventManagement\Actions\RemoveVendorFromEvent;
use App\Domains\EventManagement\Actions\UpdateEventOutlet;
use App\Domains\EventManagement\Exceptions\VendorException;
use App\Domains\EventManagement\Models\Event;
use App\Domains\EventManagement\Models\EventOutlet;
use App\Domains\EventManagement\Models\EventVendor;
use App\Domains\EventManagement\Models\Vendor;
use App\Domains\EventManagement\VendorContext;
use App\Domains\Identity\Actions\AssignTenantRole;
use App\Domains\Identity\Actions\CreateTenantUser;
use App\Domains\Identity\Enums\Permission;
use App\Domains\Identity\Exceptions\LastOwnerException;
use App\Domains\Identity\Exceptions\RoleTemplateException;
use App\Domains\Identity\Models\RoleTemplate;
use App\Domains\Inventory\Models\InventoryItem;
use App\Domains\Inventory\Models\StockLevel;
use App\Domains\Operations\Enums\OperatingUnitKind;
use App\Domains\Operations\Enums\OperatingUnitStatus;
use App\Domains\Platform\Models\FoodType;
use App\Domains\Platform\Models\VendorType;
use App\Domains\Sales\Enums\CashSessionStatus;
use App\Domains\Sales\Enums\OrderStatus;
use App\Domains\Sales\Models\CashSession;
use App\Domains\Sales\Models\Order;
use App\Domains\Sales\Models\OrderLine;
use App\Domains\Sales\Models\Payment;
use App\Domains\Sales\Models\Refund;
use App\Domains\Sales\Queries\NetSales;
use App\Domains\Sales\Queries\ResolveItbisMode;
use App\Http\Controllers\Controller;
use App\Http\Controllers\EventPanel\Concerns\AuthorizesOrganizerPanel;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Spatie\Activitylog\Models\Activity;

/**
 * El perfil del comercio en el panel nuevo (Blade + Preline): la vista del
 * organizador. Toda la lógica vive en las acciones de dominio de siempre —
 * este controlador solo autoriza, delega y presenta.
 */
class VendorProfileController extends Controller
{
    use AuthorizesOrganizerPanel;

    public function show(Request $request, int $vendor): View
    {
        $this->authorizeOrganizer($request, Permission::VendorsManage);

        $record = Vendor::query()->with(['users.roles'])->findOrFail($vendor);

        $orderIds = Order::query()
            ->where('vendor_id', $record->id)
            ->select('id');

        // Netas de reembolsos: lo devuelto no es venta (se resta el día en
        // que salió de la gaveta, como en el arqueo).
        $tz = (string) config('app.business_timezone');
        $inicioHoy = today($tz)->utc();
        $inicio30 = today($tz)->subDays(29)->utc();
        $inicioSerie = today($tz)->subDays(13)->utc();
        $devuelto = app(NetSales::class);

        $cobradas = fn () => Order::query()
            ->where('vendor_id', $record->id)
            ->where('status', OrderStatus::Paid->value);

        // Agrupación por día LOCAL (RD), igual que el dashboard.
        $diaLocal = DB::connection()->getDriverName() === 'sqlite'
            ? sprintf("DATE(paid_at, '%+d minutes')", now($tz)->utcOffset())
            : sprintf("DATE(CONVERT_TZ(paid_at, '+00:00', '%s'))", now($tz)->format('P'));

        $ventasPorDia = $cobradas()
            ->where('paid_at', '>=', $inicioSerie)
            ->selectRaw("{$diaLocal} as dia, SUM(total_cents) as total")
            ->groupBy('dia')
            ->pluck('total', 'dia');

        $devueltoPorDia = Refund::query()
            ->where('refunds.vendor_id', $record->id)
            ->where('refunds.created_at', '>=', $inicioSerie)
            ->selectRaw(str_replace('paid_at', 'refunds.created_at', $diaLocal).' as dia, SUM(amount_cents) as total')
            ->groupBy('dia')
            ->pluck('total', 'dia');

        $serie = collect(range(13, 0))->map(function (int $atras) use ($tz, $ventasPorDia, $devueltoPorDia) {
            $dia = today($tz)->subDays($atras)->toDateString();

            return [
                'dia' => today($tz)->subDays($atras)->format('d M'),
                'total' => round(((int) ($ventasPorDia[$dia] ?? 0) - (int) ($devueltoPorDia[$dia] ?? 0)) / 100, 2),
            ];
        });

        // Lo vendido de verdad, por producto: de las líneas congeladas.
        $lineasDeVentas = OrderLine::query()
            ->join('orders as o', 'o.id', '=', 'order_lines.order_id')
            ->where('o.vendor_id', $record->id)
            ->where('o.status', OrderStatus::Paid->value)
            ->where('o.paid_at', '>=', $inicio30);

        $topProductos = (clone $lineasDeVentas)
            ->selectRaw('order_lines.product_name as nombre, SUM(order_lines.quantity) as unidades, SUM(order_lines.total_cents) as importe')
            ->groupBy('order_lines.product_name')
            ->orderByDesc('importe')
            ->limit(5)
            ->toBase()
            ->get();

        $ordenes30 = (clone $cobradas())->where('paid_at', '>=', $inicio30);
        $conteo30 = (clone $ordenes30)->count();
        $bruto30 = (int) (clone $ordenes30)->sum('total_cents');

        return view('event-panel.vendors.show', [
            'serie' => $serie,
            'topProductos' => $topProductos,
            'conteo30' => $conteo30,
            'bruto30' => $bruto30,
            'devuelto30' => $devuelto->refundedBetween((string) $inicio30, null, $record->id),
            'ticketPromedio' => $conteo30 > 0 ? (int) round($bruto30 / $conteo30) : 0,
            'itbis30' => (int) (clone $ordenes30)->sum('itbis_cents'),
            'propinas30' => (int) (clone $ordenes30)->sum('tip_cents'),
            'porMetodo' => Payment::query()
                ->whereIn('order_id', (clone $ordenes30)->select('orders.id'))
                ->selectRaw('method, COUNT(*) as veces, SUM(amount_cents) as total')
                ->groupBy('method')
                ->toBase()
                ->get(),
            // Quién tocó qué en el menú: precios, estado, fiscalidad.
            'actividad' => Activity::query()
                ->where('log_name', 'catalogo')
                ->where('subject_type', Product::class)
                ->whereIn('subject_id', Product::query()
                    ->withoutGlobalScopes()
                    ->where('vendor_id', $record->id)
                    ->select('id'))
                ->with('causer')
                ->latest()
                ->limit(12)
                ->get(),
            'tz' => $tz,
            'salesToday' => (int) Order::query()
                ->where('vendor_id', $record->id)
                ->where('status', OrderStatus::Paid->value)
                ->where('paid_at', '>=', $inicioHoy)
                ->sum('total_cents')
                - $devuelto->refundedBetween((string) $inicioHoy, null, $record->id),
            'salesTotal' => (int) Order::query()
                ->where('vendor_id', $record->id)
                ->where('status', OrderStatus::Paid->value)
                ->sum('total_cents')
                - $devuelto->refundedBetween(null, null, $record->id),
            'recentOrders' => Order::query()
                ->where('vendor_id', $record->id)
                ->with('operatingUnit')
                ->orderByDesc('id')
                ->limit(15)
                ->get(),
            'recentPayments' => Payment::query()
                ->whereIn('order_id', $orderIds)
                ->with('order')
                ->orderByDesc('id')
                ->limit(15)
                ->get(),
            'stockLevels' => StockLevel::query()
                ->whereHas('operatingUnit', fn ($q) => $q->where('vendor_id', $record->id))
                ->with(['operatingUnit', 'inventoryItem'])
                ->orderBy('inventory_item_id')
                ->get(),
            'menuCategories' => app(VendorContext::class)->runAs(
                $record,
                fn () => Category::query()
                    ->with([
                        'products' => fn ($q) => $q->orderBy('name'),
                        'products.inventoryItem',
                        'products.recipeItems.inventoryItem',
                    ])
                    ->orderBy('name')
                    ->get(),
            ),
            'vendorItems' => app(VendorContext::class)->runAs(
                $record,
                fn () => InventoryItem::query()->orderBy('name')->pluck('name', 'id'),
            ),
            // Para el selector «Como la cuenta (…)» de la pestaña config.
            'modoCuenta' => app(ResolveItbisMode::class)->forTenant((int) $record->tenant_id),
            // El que rige HOY para este comercio: el copy de los precios
            // depende de él (con o sin ITBIS dentro).
            'modoVigente' => app(ResolveItbisMode::class)->forVendor($record->id, (int) $record->tenant_id),
            'vendorTypes' => VendorType::query()->orderBy('name')->pluck('name', 'id'),
            'foodTypes' => FoodType::query()->orderBy('name')->pluck('name', 'id'),
            'vendor' => $record,
            // Los parciales compartidos con /comercio reciben sus acciones
            // por aquí: cada puerta pone las suyas.
            'urls' => [
                'categorias' => route('event-panel.vendors.categories.store', $record),
                'productos' => route('event-panel.vendors.products.store', $record),
                'producto' => fn ($product) => route('event-panel.vendors.products.update', [$record, $product]),
                'receta' => fn ($product) => route('event-panel.vendors.recipe.store', [$record, $product]),
                'recetaQuitar' => fn ($product, $ingrediente) => route('event-panel.vendors.recipe.destroy', [$record, $product, $ingrediente]),
                'venta' => fn ($order) => route('event-panel.vendors.sales.show', [$record, $order]),
                'insumos' => route('event-panel.vendors.items.store', $record),
                'compras' => route('event-panel.vendors.purchases.store', $record),
            ],
            'participations' => $record->events()->orderBy('starts_at')->get(),
            'outlets' => EventOutlet::query()
                ->where('vendor_id', $record->id)
                ->with('event')
                ->orderBy('name')
                ->get(),
            'products' => app(VendorContext::class)->runAs(
                $record,
                fn () => $record->products()->with('category')->orderBy('name')->get(),
            ),
            'vendorRoles' => RoleTemplate::optionsForVendorStaff(),
            'invitableEvents' => Event::query()
                ->whereNotIn('id', $record->events()->select('events.id'))
                ->orderBy('starts_at')
                ->pluck('name', 'id'),
            'roleLabels' => RoleTemplate::query()->pluck('label', 'name'),
        ]);
    }

    public function storeUser(Request $request, int $vendor): RedirectResponse
    {
        $this->authorizeOrganizer($request, Permission::UsersManage);

        $record = Vendor::query()->findOrFail($vendor);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'username' => ['nullable', 'string', 'max:30', 'regex:/^[a-z0-9._-]+$/i', 'unique:users,username'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8'],
            'role' => ['required', 'string'],
        ]);

        app(CreateTenantUser::class)(
            $record->tenant,
            $data['name'],
            $data['email'],
            $data['password'],
            $data['role'],
            $record,
            $request->user(),
            $data['username'] ?? null,
        );

        return back()->with('status', 'Usuario del comercio creado.');
    }

    public function invite(Request $request, int $vendor): RedirectResponse
    {
        $this->authorizeOrganizer($request, Permission::EventsManage);

        $record = Vendor::query()->findOrFail($vendor);

        $data = $request->validate([
            'event_id' => ['required', 'integer'],
            'commission' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ]);

        app(InviteVendorToEvent::class)(
            Event::query()->findOrFail((int) $data['event_id']),
            $record,
            (int) round(((float) ($data['commission'] ?? 0)) * 100),
        );

        return back()->with('status', 'Comercio invitado al evento.');
    }

    public function storeOutlet(Request $request, int $vendor): RedirectResponse
    {
        $this->authorizeOrganizer($request, Permission::EventOutletsManage);

        $record = Vendor::query()->findOrFail($vendor);

        $data = $request->validate([
            'event_id' => ['required', 'integer'],
            'name' => ['required', 'string', 'max:255'],
            'kind' => ['required', 'string'],
        ]);

        app(CreateEventOutlet::class)(
            Event::query()->findOrFail((int) $data['event_id']),
            $record,
            $data['name'],
            OperatingUnitKind::from($data['kind']),
        );

        return back()->with('status', 'Puesto creado.');
    }

    /**
     * Renegociar la comisión de una participación que ya existe. La acción
     * de dominio es idempotente y la actualiza; lo único que faltaba era
     * poder pedírselo desde una pantalla.
     *
     * Lo ya cobrado no cambia: cada orden guarda su comisión congelada.
     */
    public function updateCommission(Request $request, int $vendor, int $event): RedirectResponse
    {
        $this->authorizeOrganizer($request, Permission::EventsManage);

        $record = Vendor::query()->findOrFail($vendor);

        $data = $request->validate([
            'commission' => ['required', 'numeric', 'min:0', 'max:100'],
        ], [], ['commission' => 'comisión']);

        // RENEGOCIAR, no invitar: la acción de dominio es crear-o-actualizar,
        // así que sin esta comprobación esta URL daría de alta al comercio en
        // un evento al que nadie lo invitó — y lo metería en su liquidación.
        abort_unless(
            EventVendor::query()
                ->where('event_id', $event)
                ->where('vendor_id', $record->id)
                ->exists(),
            404,
        );

        app(InviteVendorToEvent::class)(
            Event::query()->findOrFail($event),
            $record,
            (int) round(((float) $data['commission']) * 100),
        );

        return back()->with('status', 'Comisión actualizada. Las ventas ya cobradas conservan la suya.');
    }

    /** Sacar a un comercio de un evento: sus puestos dejan de operar. */
    public function removeFromEvent(Request $request, int $vendor, int $event): RedirectResponse
    {
        $this->authorizeOrganizer($request, Permission::EventsManage);

        try {
            app(RemoveVendorFromEvent::class)(
                Event::query()->findOrFail($event),
                Vendor::query()->findOrFail($vendor),
            );
        } catch (VendorException $e) {
            // Una regla del dominio es algo que el organizador puede
            // resolver, no un fallo del servidor: se le cuenta en su panel.
            return back()->withErrors(['vendor' => $e->getMessage()]);
        }

        return back()->with('status', 'Comercio retirado del evento y sus puestos cerrados.');
    }

    /** Cambiar el puesto de sitio no: renombrarlo, o cerrarlo, sí. */
    public function updateOutlet(Request $request, int $vendor, int $outlet): RedirectResponse
    {
        $this->authorizeOrganizer($request, Permission::EventOutletsManage);

        $record = EventOutlet::query()
            ->where('vendor_id', $vendor)
            ->findOrFail($outlet);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'kind' => ['required', Rule::enum(OperatingUnitKind::class)],
            'status' => ['required', Rule::in([
                OperatingUnitStatus::Active->value,
                OperatingUnitStatus::Closed->value,
            ])],
        ], [], ['name' => 'nombre', 'status' => 'estado']);

        if ($data['status'] === OperatingUnitStatus::Closed->value
            && CashSession::query()
                ->where('operating_unit_id', $record->id)
                ->where('status', CashSessionStatus::Open->value)
                ->exists()) {
            return back()->withErrors([
                'status' => 'Este puesto tiene una caja abierta. Ciérrala desde el POS antes de cerrarlo.',
            ]);
        }

        app(UpdateEventOutlet::class)(
            $record,
            $data['name'],
            OperatingUnitKind::from($data['kind']),
            OperatingUnitStatus::from($data['status']),
        );

        return back()->with('status', 'Puesto actualizado.');
    }

    /**
     * Cambiar el rol de alguien del comercio. Hasta ahora el panel nuevo
     * solo sabía crear usuarios: corregir un rol mal dado obligaba a entrar
     * al Filament viejo.
     */
    public function updateUserRole(Request $request, int $vendor, int $user): RedirectResponse
    {
        $this->authorizeOrganizer($request, Permission::UsersManage);

        $target = $this->usuarioDelComercio($vendor, $user);

        $data = $request->validate([
            'role' => ['required', 'string', Rule::in(array_keys(RoleTemplate::optionsForVendorStaff()))],
        ], [], ['role' => 'rol']);

        // El actor va para que aplique el techo antiescalada, y la Action
        // revoca los tokens del POS si el rol nuevo ya no puede operar caja.
        try {
            app(AssignTenantRole::class)($target, $data['role'], $request->user());
        } catch (LastOwnerException|RoleTemplateException|VendorException $e) {
            return back()->withErrors(['role' => $e->getMessage()]);
        }

        return back()->with('status', 'Rol actualizado.');
    }

    /** Corregir nombre, correo, usuario del POS o contraseña. */
    public function updateUser(Request $request, int $vendor, int $user): RedirectResponse
    {
        $this->authorizeOrganizer($request, Permission::UsersManage);

        $target = $this->usuarioDelComercio($vendor, $user);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'username' => ['nullable', 'string', 'max:30', 'regex:/^[a-z0-9._-]+$/i',
                Rule::unique('users', 'username')->ignore($target->id)],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($target->id)],
            'password' => ['nullable', 'string', 'min:8'],
        ], [
            'username.regex' => 'El usuario del POS solo admite letras, números, punto, guion y guion bajo.',
        ], ['name' => 'nombre', 'password' => 'contraseña']);

        $target->name = $data['name'];
        $target->email = $data['email'];
        $target->username = $data['username'] ?? null;

        if (filled($data['password'] ?? null)) {
            $target->password = Hash::make($data['password']);
        }

        $target->save();

        return back()->with('status', 'Usuario actualizado.');
    }

    public function destroyUser(Request $request, int $vendor, int $user): RedirectResponse
    {
        $this->authorizeOrganizer($request, Permission::UsersManage);

        $target = $this->usuarioDelComercio($vendor, $user);

        if ($target->id === $request->user()?->id) {
            return back()->withErrors(['user' => 'No puedes eliminar tu propia cuenta.']);
        }

        $target->delete();

        return back()->with('status', 'Usuario eliminado.');
    }

    /**
     * Alguien de ESTE comercio. `User` no lleva scope de cuenta —el login
     * ocurre antes de que haya una—, así que las dos fronteras, la de la
     * cuenta y la del comercio, se ponen a mano.
     */
    private function usuarioDelComercio(int $vendor, int $user): User
    {
        $record = Vendor::query()->findOrFail($vendor);

        return User::query()
            ->where('tenant_id', $record->tenant_id)
            ->where('vendor_id', $record->id)
            ->where('is_platform_admin', false)
            ->findOrFail($user);
    }
}
