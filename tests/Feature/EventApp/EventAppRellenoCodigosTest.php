<?php

declare(strict_types=1);

use App\Domains\Platform\Actions\CreateTenant;
use App\Domains\Platform\Enums\TenantType;
use Illuminate\Support\Facades\DB;

/**
 * El relleno de la migración que le puso código público a los eventos que ya
 * existían.
 *
 * Se prueba con MÁS DE MIL eventos a propósito: por debajo del tamaño del
 * trozo el fallo no existe, y era justo esa la razón de que nadie lo viera.
 * Un evento sin código no es un evento a medias: es una app que no puede
 * llegar a su servidor, y como el único global admite tantos NULL como quiera,
 * la migración pasaba verde y el hueco solo se descubría en el festival.
 */
beforeEach(function (): void {
    $this->organizador = app(CreateTenant::class)('Bocao Food Fest', null, TenantType::Organizer);
});

/** La migración se ejecuta tal cual está escrita, no una copia del bucle. */
function relleno(): object
{
    return require database_path('migrations/2026_08_09_100001_event_management_add_public_code_to_events.php');
}

function eventosSinCodigo(int $cuantos, int $tenantId): void
{
    $filas = [];

    for ($i = 0; $i < $cuantos; $i++) {
        $filas[] = [
            'tenant_id' => $tenantId,
            'name' => 'Festival '.$i,
            'venue' => null,
            'starts_at' => now(),
            'ends_at' => now()->addDay(),
            'status' => 'active',
            'public_code' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    // Por trozos: mil quinientas filas en un solo insert se comen el tope de
    // parámetros de SQLite y el test moriría por el andamio, no por el fallo.
    foreach (array_chunk($filas, 100) as $trozo) {
        DB::table('events')->insert($trozo);
    }
}

it('hands a code to every single event, not just to the first chunk', function (): void {
    eventosSinCodigo(1500, (int) $this->organizador->id);

    expect(DB::table('events')->whereNull('public_code')->count())->toBe(1500);

    relleno()->up();

    // Cero, no «casi todos». Paginar por OFFSET sobre el filtro que el propio
    // bucle va borrando dejaba aquí quinientos.
    expect(DB::table('events')->whereNull('public_code')->count())->toBe(0);

    // Y ninguno repetido: el índice es único y GLOBAL, así que una colisión
    // no sería un código feo sino una migración que revienta a medias.
    $codigos = DB::table('events')->pluck('public_code');

    expect($codigos->unique()->count())->toBe($codigos->count());
});

it('leaves alone the code an event already had', function (): void {
    DB::table('events')->insert([
        'tenant_id' => $this->organizador->id,
        'name' => 'Bocao 2026',
        'starts_at' => now(),
        'ends_at' => now()->addDay(),
        'status' => 'active',
        'public_code' => 'BOCAO26',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    relleno()->up();

    // El código viaja compilado en binarios ya publicados: reemplazarlo al
    // volver a correr una migración dejaría esas apps sin servidor.
    expect(DB::table('events')->where('name', 'Bocao 2026')->value('public_code'))->toBe('BOCAO26');
});
