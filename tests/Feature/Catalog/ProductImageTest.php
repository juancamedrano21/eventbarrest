<?php

declare(strict_types=1);

use App\Domains\Business\Actions\CreateBranch;
use App\Domains\Catalog\Enums\DispatchArea;
use App\Domains\Catalog\Enums\ProductType;
use App\Domains\Catalog\Models\Category;
use App\Domains\Catalog\Models\Product;
use App\Domains\EventManagement\VendorContext;
use App\Domains\Identity\Actions\CreateTenantUser;
use App\Domains\Identity\Enums\Role;
use App\Domains\Platform\Actions\CreateTenant;
use App\Domains\Platform\Enums\TenantType;
use App\Domains\Tenancy\TenantContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

/**
 * La foto del producto: se sube, se reemplaza sin dejar basura en el disco,
 * se quita, y llega al POS por su URL.
 */
beforeEach(function (): void {
    Storage::fake('public');

    $this->negocio = app(CreateTenant::class)('Bar del Puerto', null, TenantType::Business);

    app(TenantContext::class)->runAs($this->negocio, function (): void {
        app(CreateBranch::class)('Sucursal Centro');
        $this->categoria = Category::query()->create(['name' => 'Bebidas', 'dispatch' => DispatchArea::Bar]);
    });

    $this->dueno = app(CreateTenantUser::class)(
        $this->negocio, 'Juan', 'juan@bar.test', 'Secreta-2026', Role::Owner,
    );
});

afterEach(function (): void {
    app(TenantContext::class)->clear();
    app(VendorContext::class)->clear();
});

it('uploads a photo when the product is created', function (): void {
    $this->actingAs($this->dueno)
        ->post('/business/productos', [
            'name' => 'Presidente', 'price' => '250.00',
            'category_id' => $this->categoria->id, 'kind' => 'simple',
            'image' => UploadedFile::fake()->image('presidente.jpg', 600, 600),
        ])
        ->assertRedirect();

    $producto = Product::query()->withoutGlobalScopes()->where('name', 'Presidente')->sole();

    expect($producto->image_path)->not->toBeNull()
        ->and($producto->imageUrl())->toContain($producto->image_path);

    Storage::disk('public')->assertExists($producto->image_path);
});

it('deletes the old photo when it is replaced, instead of leaving it behind', function (): void {
    $producto = app(TenantContext::class)->runAs($this->negocio, fn () => Product::create([
        'category_id' => $this->categoria->id, 'name' => 'Presidente',
        'type' => ProductType::Simple, 'price_cents' => 25000,
        'image_path' => UploadedFile::fake()->image('vieja.jpg')->store('product-images', 'public'),
    ]));

    $vieja = $producto->image_path;
    Storage::disk('public')->assertExists($vieja);

    $this->actingAs($this->dueno)
        ->post("/business/productos/{$producto->id}", [
            'image' => UploadedFile::fake()->image('nueva.jpg'),
        ])
        ->assertRedirect();

    $nueva = $producto->fresh()->image_path;

    expect($nueva)->not->toBe($vieja);
    Storage::disk('public')->assertExists($nueva);
    Storage::disk('public')->assertMissing($vieja);
});

it('removes the photo when asked, and leaves it alone when the form says nothing', function (): void {
    $producto = app(TenantContext::class)->runAs($this->negocio, fn () => Product::create([
        'category_id' => $this->categoria->id, 'name' => 'Presidente',
        'type' => ProductType::Simple, 'price_cents' => 25000,
        'image_path' => UploadedFile::fake()->image('foto.jpg')->store('product-images', 'public'),
    ]));

    $ruta = $producto->image_path;

    // Editar el precio no toca la foto.
    $this->actingAs($this->dueno)
        ->post("/business/productos/{$producto->id}", ['price' => '300.00'])
        ->assertRedirect();

    expect($producto->fresh()->image_path)->toBe($ruta);

    // Y pedir que se quite, la borra del disco.
    $this->actingAs($this->dueno)
        ->post("/business/productos/{$producto->id}", ['remove_image' => '1'])
        ->assertRedirect();

    expect($producto->fresh()->image_path)->toBeNull();
    Storage::disk('public')->assertMissing($ruta);
});

it('refuses a file that is not an image', function (): void {
    $this->actingAs($this->dueno)
        ->from('/business/menu')
        ->post('/business/productos', [
            'name' => 'Presidente', 'price' => '250.00',
            'category_id' => $this->categoria->id, 'kind' => 'simple',
            'image' => UploadedFile::fake()->create('factura.pdf', 100, 'application/pdf'),
        ])
        ->assertSessionHasErrors('image');

    expect(Product::query()->withoutGlobalScopes()->where('name', 'Presidente')->exists())->toBeFalse();
});

it('hands the photo and the availability to the pos catalog', function (): void {
    app(TenantContext::class)->runAs($this->negocio, function (): void {
        Product::create([
            'category_id' => $this->categoria->id, 'name' => 'Presidente',
            'type' => ProductType::Simple, 'price_cents' => 25000, 'active' => true,
            'image_path' => UploadedFile::fake()->image('foto.jpg')->store('product-images', 'public'),
        ]);
        Product::create([
            'category_id' => $this->categoria->id, 'name' => 'Agotado',
            'type' => ProductType::Simple, 'price_cents' => 10000, 'active' => false,
        ]);
    });

    $cajero = app(CreateTenantUser::class)(
        $this->negocio, 'Luis', 'luis@bar.test', 'Secreta-2026', Role::Cashier, null, null, 'luis',
    );

    // La API va por token con la habilidad 'pos', no por sesión.
    Sanctum::actingAs($cajero, ['pos']);

    $catalogo = $this->getJson('/api/pos/catalog')
        ->assertOk()
        ->json('products');

    $conFoto = collect($catalogo)->firstWhere('name', 'Presidente');
    $agotado = collect($catalogo)->firstWhere('name', 'Agotado');

    expect($conFoto['image_url'])->toContain('product-images')
        ->and($conFoto['active'])->toBeTrue()
        // El inactivo VIAJA: el POS lo pinta en gris. Esconderlo dejaría al
        // cajero preguntándose si el plato existe.
        ->and($agotado)->not->toBeNull()
        ->and($agotado['active'])->toBeFalse()
        ->and($agotado['image_url'])->toBeNull();
});
