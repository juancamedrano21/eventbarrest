<?php

declare(strict_types=1);

use App\Domains\Business\Actions\CreateBranch;
use App\Domains\Catalog\Models\Category;
use App\Domains\Catalog\Models\Product;
use App\Domains\EventManagement\Actions\CreateEvent;
use App\Domains\EventManagement\Actions\CreateVendor;
use App\Domains\Identity\Actions\CreateTenantUser;
use App\Domains\Identity\Enums\Role;
use App\Domains\Inventory\Models\InventoryItem;
use App\Domains\Platform\Actions\CreateTenant;
use App\Domains\Platform\Enums\TenantType;
use App\Domains\Tenancy\TenantContext;

/**
 * ARNÉS TEMPORAL DE AUDITORÍA — se borra al terminar.
 * Un test por (mundo, rol): cada uno arranca con aplicación y sesión
 * limpias, como una visita real. Los resultados se acumulan en un fichero.
 */
const MATRIX_FILE = '/tmp/coverage-matrix.json';

it('probes every /app route', function (string $world, string $roleName): void {
    $ctx = app(TenantContext::class);

    $type = $world === 'business' ? TenantType::Business : TenantType::Organizer;
    $tenant = app(CreateTenant::class)('Cuenta '.$world, null, $type);

    $records = $ctx->runAs($tenant, function () use ($world): array {
        $r = [
            'product' => Product::factory()->create()->getKey(),
            'category' => Category::factory()->create()->getKey(),
            'item' => InventoryItem::factory()->create()->getKey(),
        ];

        if ($world === 'business') {
            $r['branch'] = app(CreateBranch::class)('Sucursal Centro')->getKey();
        } else {
            $r['event'] = app(CreateEvent::class)('Bocao 2026', now()->addWeek(), now()->addWeeks(2))->getKey();
            $r['vendor'] = app(CreateVendor::class)('Bar Manolo')->getKey();
        }

        return $r;
    });
    $ctx->clear();

    $user = app(CreateTenantUser::class)(
        $tenant, ucfirst($roleName), $roleName.'@'.$world.'.test', 'Secreta-2026', Role::from($roleName),
    );

    $routes = [
        'dashboard' => '/app',
        'branches' => '/app/branches',
        'branches.create' => '/app/branches/create',
        'branches.edit' => isset($records['branch']) ? '/app/branches/'.$records['branch'].'/edit' : null,
        'events' => '/app/events',
        'events.create' => '/app/events/create',
        'events.edit' => isset($records['event']) ? '/app/events/'.$records['event'].'/edit' : null,
        'vendors' => '/app/vendors',
        'categories' => '/app/categories',
        'products' => '/app/products',
        'products.create' => '/app/products/create',
        'products.edit' => '/app/products/'.$records['product'].'/edit',
        'inventory-items' => '/app/inventory-items',
        'stock-levels' => '/app/stock-levels',
        'stock-movements' => '/app/stock-movements',
        'users' => '/app/users',
        'users.create' => '/app/users/create',
    ];

    $row = [];
    $nav = [];

    foreach ($routes as $label => $url) {
        if ($url === null) {
            $row[$label] = '--';

            continue;
        }

        // Sesión limpia por petición: si no, AuthenticateSession invalida
        // la sesión al cambiar de usuario y contamina el resultado.
        $this->app['session']->flush();
        $response = $this->actingAs($user->fresh())->get($url);
        $code = $response->getStatusCode();
        $row[$label] = (string) $code.($code === 302 ? '->'.substr((string) $response->headers->get('Location'), -22) : '');

        if ($label === 'dashboard' && $code === 200) {
            preg_match_all('#href="[^"]*?(/app/[a-z\-]+)"#', $response->getContent(), $m);
            $nav = array_values(array_diff(array_unique($m[1]), ['/app/logout']));
        }
    }

    $all = file_exists(MATRIX_FILE) ? json_decode((string) file_get_contents(MATRIX_FILE), true) : [];
    $all[$world][$roleName] = ['routes' => $row, 'nav' => $nav];
    file_put_contents(MATRIX_FILE, json_encode($all, JSON_PRETTY_PRINT));

    expect(true)->toBeTrue();
})->with([
    ['business', 'owner'], ['business', 'admin'], ['business', 'event_manager'],
    ['business', 'unit_manager'], ['business', 'warehouse'], ['business', 'cashier'],
    ['organizer', 'owner'], ['organizer', 'admin'], ['organizer', 'event_manager'],
    ['organizer', 'unit_manager'], ['organizer', 'warehouse'], ['organizer', 'cashier'],
]);
