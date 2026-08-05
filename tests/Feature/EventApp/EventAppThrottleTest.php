<?php

declare(strict_types=1);

use App\Domains\EventManagement\Actions\CreateEvent;
use App\Domains\EventManagement\Enums\EventStatus;
use App\Domains\Platform\Actions\CreateTenant;
use App\Domains\Platform\Enums\TenantType;
use App\Domains\Tenancy\TenantContext;

/**
 * El freno de la puerta anónima, que es no tener freno por IP.
 *
 * Esta puerta tuvo uno —600 por minuto por (evento, IP)— y se quitó midiendo,
 * no opinando. Mientras `trustProxies(at: '*')` siga abierto, la IP la escribe
 * quien llama: contra quien ataca el cubo no cuenta —estrena IP en cada
 * petición— y contra el público sí, porque un festival entero sale por el NAT
 * de dos operadores. Los dos tests de aquí son las dos mitades de eso, y los
 * dos se ponían rojos con el limitador puesto.
 *
 * Lo que hace barata esta puerta no es un contador: es que es de solo lectura,
 * lleva ETag y no tiene nada que escribir detrás. El techo de volumen va en el
 * borde, que es el único sitio donde la IP todavía es cierta.
 */
beforeEach(function (): void {
    $this->organizador = app(CreateTenant::class)('Bocao Food Fest', null, TenantType::Organizer);

    app(TenantContext::class)->runAs($this->organizador, function (): void {
        $this->evento = app(CreateEvent::class)(
            'Bocao 2026', now()->subDay(), now()->addDay(), null, EventStatus::Active,
        );
    });
});

afterEach(function (): void {
    app(TenantContext::class)->clear();
});

it('never denies a phone because the whole festival shares one carrier NAT', function (): void {
    $codigo = (string) $this->evento->public_code;

    // Setecientas peticiones del MISMO origen: no es un ataque, es la cola
    // del sábado a las nueve. Un arranque de app son dos peticiones y cada
    // carta que alguien mira suma otra, así que doscientos asistentes detrás
    // del NAT de su operador pasan de aquí sin proponérselo.
    for ($i = 0; $i < 700; $i++) {
        $respuesta = $this->getJson("/api/event-app/eventos/{$codigo}/manifiesto");
    }

    // Un freno nunca puede negar un acierto, y este es el acierto que negaba.
    $respuesta->assertOk()->assertJsonPath('evento.nombre', 'Bocao 2026');

    // Y no queda ni el rastro del cubo: si algún día vuelve a haber uno, que
    // sea una decisión y no un descuido que se cuela con una cabecera.
    expect($respuesta->headers->has('X-RateLimit-Limit'))->toBeFalse();
});

it('cannot be turned into a shutdown button by forging the caller IP', function (): void {
    $codigo = (string) $this->evento->public_code;

    // La IP del operador por el que sale medio recinto, escrita a mano en la
    // cabecera: con trustProxies('*') esto es todo lo que hacía falta para
    // llenar el cubo de OTRO. Un contador que sube quien ataca, sobre algo
    // que él elige, es un botón de apagado con otro nombre.
    $delOperador = ['X-Forwarded-For' => '200.88.128.1'];

    for ($i = 0; $i < 600; $i++) {
        $this->getJson("/api/event-app/eventos/{$codigo}/manifiesto", $delOperador);
    }

    // Y el teléfono honesto que sale por esa misma IP sigue teniendo app.
    $this->getJson("/api/event-app/eventos/{$codigo}/manifiesto", $delOperador)
        ->assertOk()
        ->assertJsonPath('evento.codigo', $codigo);
});
