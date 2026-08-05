<?php

declare(strict_types=1);

use App\Domains\EventManagement\Actions\CreateEvent;
use App\Domains\EventManagement\Enums\EventStatus;
use App\Domains\EventManagement\Models\Event;
use App\Domains\Platform\Actions\CreateTenant;
use App\Domains\Platform\Enums\TenantType;
use App\Domains\Tenancy\TenantContext;
use Illuminate\Support\Facades\RateLimiter;

/**
 * El freno de la puerta anónima.
 *
 * Lo que importa de este limitador no es el número —600 por minuto es un
 * techo de volumen, no una defensa: lo que hace baratos estos endpoints es
 * que son de solo lectura y llevan ETag— sino las dos cosas que se prueban
 * aquí. Que cuando salta contesta con el código y el mensaje de esta casa, en
 * español, y no con el «Too Many Attempts.» de Laravel que ningún asistente
 * sabe qué hacer con él. Y que el cubo es POR EVENTO: dos festivales que
 * comparten el NAT de un operador móvil no pueden apagarse el uno al otro.
 */
beforeEach(function (): void {
    $this->organizador = app(CreateTenant::class)('Bocao Food Fest', null, TenantType::Organizer);

    app(TenantContext::class)->runAs($this->organizador, function (): void {
        $this->uno = app(CreateEvent::class)(
            'Bocao 2026', now()->subDay(), now()->addDay(), null, EventStatus::Active,
        );
        $this->otro = app(CreateEvent::class)(
            'Bocao Navidad', now()->addMonth(), now()->addMonth()->addDay(), null, EventStatus::Active,
        );
    });
});

afterEach(function (): void {
    app(TenantContext::class)->clear();
});

/**
 * Llena el cubo de un evento sin gastar seiscientas peticiones.
 *
 * La llave se compone igual que la compone ThrottleRequests para un
 * limitador con nombre: md5(nombre . lo que devuelve ->by()). Depender de esa
 * fórmula es el precio de no atar el test a un bucle de seiscientas
 * respuestas HTTP; si Laravel la cambia, este test se pone rojo y se
 * arregla, que es exactamente lo que tiene que pasar.
 */
function llenarElCubo(Event $evento): void
{
    $llave = md5('event-app'.$evento->public_code.'|127.0.0.1');

    for ($i = 0; $i < 600; $i++) {
        RateLimiter::hit($llave, 60);
    }
}

it('answers its own 429, in spanish, when the ceiling is reached', function (): void {
    llenarElCubo($this->uno);

    $respuesta = $this->getJson("/api/event-app/eventos/{$this->uno->public_code}/manifiesto")
        ->assertStatus(429)
        ->assertJsonPath('code', 'event_app_demasiadas_peticiones');

    expect($respuesta->json('message'))->toContain('Espera un minuto');

    // Sin perder lo que trae el 429 de Laravel: es lo que un cliente honesto
    // usa para dejar de insistir sin tener que leer el cuerpo.
    expect($respuesta->headers->get('Retry-After'))->not->toBeNull();
});

it('never locks one festival out because of another one on the same carrier', function (): void {
    llenarElCubo($this->uno);

    $this->getJson("/api/event-app/eventos/{$this->uno->public_code}/manifiesto")
        ->assertStatus(429);

    // Mismo origen, otro evento: un freno que puede negar un acierto está
    // mal, y con la IP colapsada por el NAT del operador este es el acierto
    // que estaría negando.
    $this->getJson("/api/event-app/eventos/{$this->otro->public_code}/manifiesto")
        ->assertOk()
        ->assertJsonPath('evento.nombre', 'Bocao Navidad');
});
