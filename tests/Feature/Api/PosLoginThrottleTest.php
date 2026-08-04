<?php

declare(strict_types=1);

use App\Domains\Identity\Actions\CreateTenantUser;
use App\Domains\Identity\Enums\Role;
use App\Domains\Platform\Actions\CreateTenant;
use App\Domains\Platform\Enums\TenantType;
use App\Domains\Tenancy\TenantContext;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Support\ContadorDeHashes;

/**
 * El freno del login del POS: a quién NO puede dejar fuera, qué no puede
 * contar y cuánto cuesta un intento.
 *
 * La llave se compone con el usuario, no con la IP a secas: en un festival
 * TODAS las cajas salen por el mismo router, y un limitador que solo mira la
 * IP castiga a la caja de al lado por los errores de otro — o directamente por
 * sus aciertos, porque el middleware de Laravel cuenta también los intentos
 * que salen bien.
 *
 * Y ese limitador es el ÚNICO freno de esta puerta, a propósito. Hubo un
 * contador de fallos por cuenta en la base —diez fallos, 429 durante quince
 * minutos— y se quitó porque lo medido fue que no capaba ni una adivinanza (la
 * contraseña se comprueba antes y quien acierta entra siempre), no ahorraba ni
 * el bcrypt, escribía en `users` en cada intento fallido, y a cambio convertía
 * el login en un contador de usuarios existentes: la cuenta que existe llegaba
 * a 429 y la que no existe se quedaba en 422 para siempre. Los tests de abajo
 * son esas cosas, una por una.
 */
beforeEach(function (): void {
    RateLimiter::clear('pos-login');

    $this->negocio = app(CreateTenant::class)('Bar Demo', null, TenantType::Business);

    app(TenantContext::class)->runAs($this->negocio, function (): void {
        app(CreateTenantUser::class)(
            $this->negocio, 'Caro', 'caro@bar.test', 'Secreta-2026', Role::Cashier, username: 'caro',
        );
        app(CreateTenantUser::class)(
            $this->negocio, 'Luis', 'luis@bar.test', 'Secreta-2026', Role::Cashier, username: 'luis',
        );
    });
});

afterEach(function (): void {
    app(TenantContext::class)->clear();
});

it('never locks one cashier out because of another one at the same venue', function (): void {
    // Cinco intentos fallidos de Caro agotan SU cupo.
    for ($i = 0; $i < 5; $i++) {
        $this->postJson('/api/pos/login', [
            'username' => 'caro', 'password' => 'mala', 'device_name' => 'Tablet 1',
        ])->assertStatus(422);
    }

    $this->postJson('/api/pos/login', [
        'username' => 'caro', 'password' => 'mala', 'device_name' => 'Tablet 1',
    ])->assertStatus(429);

    // Y Luis, en la misma IP, entra sin enterarse. Antes de arreglar la
    // llave esto era un 429: la sexta tablet del evento no podía abrir.
    $this->postJson('/api/pos/login', [
        'username' => 'luis', 'password' => 'Secreta-2026', 'device_name' => 'Tablet 2',
    ])->assertCreated();
});

it('answers exactly the same to an account that exists and one that does not', function (): void {
    // El oráculo que abrió el contador en base de la ronda anterior, medido
    // igual que lo midieron los dos refutadores: doce intentos contra una
    // cuenta que EXISTE y doce contra una que no, cada uno desde otro origen
    // para que el freno de cinco por minuto no se meta. Con aquel contador,
    // 'caro' pasaba a 429 'pos_cuenta_frenada' en el undécimo y 'fantasma' se
    // quedaba en 422 para siempre: once peticiones bastaban para confirmar
    // cada nombre de usuario, y una cada quince minutos para reconfirmarlo.
    $deCaro = [];
    $deFantasma = [];

    for ($intento = 1; $intento <= 12; $intento++) {
        $deCaro[] = $this->withHeader('X-Forwarded-For', '203.0.113.'.$intento)
            ->postJson('/api/pos/login', [
                'username' => 'caro', 'password' => 'mala'.$intento, 'device_name' => 'Caja 1',
            ])->getStatusCode();

        $deFantasma[] = $this->withHeader('X-Forwarded-For', '198.51.100.'.$intento)
            ->postJson('/api/pos/login', [
                'username' => 'fantasma', 'password' => 'mala'.$intento, 'device_name' => 'Caja 1',
            ])->getStatusCode();
    }

    expect($deCaro)->toBe($deFantasma);
    expect(array_values(array_unique($deCaro)))->toBe([422]);
});

it('lets the cashier open her till with her own password while her account is being guessed at', function (): void {
    // Once fallos contra la cuenta de Caro, cada uno desde otro origen.
    for ($intento = 1; $intento <= 11; $intento++) {
        $this->withHeader('X-Forwarded-For', '203.0.113.'.$intento)
            ->postJson('/api/pos/login', [
                'username' => 'caro', 'password' => 'mala'.$intento, 'device_name' => 'Caja 1',
            ])->assertStatus(422);
    }

    // Y Caro llega a su caja, desde su tablet, con SU contraseña. Tiene que
    // entrar. Un freno que rechaza sin mirar la contraseña deja a una cajera
    // sin trabajar en mitad del servicio, gratis y desde un móvil, y eso es
    // peor que las adivinanzas que evitaría.
    $this->withHeader('X-Forwarded-For', '198.51.100.44')
        ->postJson('/api/pos/login', [
            'username' => 'caro', 'password' => 'Secreta-2026', 'device_name' => 'Caja de Caro',
        ])->assertCreated();
});

it('spends exactly one bcrypt on a login attempt whether the user exists or not', function (): void {
    $contador = ContadorDeHashes::instalar();

    $this->postJson('/api/pos/login', [
        'username' => 'nadie', 'password' => 'loquesea', 'device_name' => 'Caja 1',
    ])->assertStatus(422);

    // Uno, no dos. El `Hash::make('nunca-coincide-'.$username)` que había
    // dentro del argumento del `Hash::check` doblaba el coste de cada petición
    // fallida — y quien llama elige el usuario, así que pedir el doble de CPU
    // no exigía acertar nada.
    expect($contador->comprobaciones)->toBe(1);
    expect($contador->generaciones)->toBe(0);

    // Y el usuario que SÍ existe cuesta exactamente lo mismo: si no, la
    // respuesta lenta delata cuáles de los usuarios probados no existen.
    $contador->aCero();

    $this->postJson('/api/pos/login', [
        'username' => 'caro', 'password' => 'mala', 'device_name' => 'Caja 1',
    ])->assertStatus(422);

    expect($contador->comprobaciones)->toBe(1);
    expect($contador->generaciones)->toBe(0);
});

it('does not write to the users table on a failed login', function (): void {
    // El contador en base cobraba un UPDATE a `users` por cada intento
    // fallido: escritura gratis en la tabla de usuarios justo en el escenario
    // de inundación que decía acotar, y serializada por fila cuando la campaña
    // va contra una sola cajera.
    $escrituras = 0;

    DB::listen(function (QueryExecuted $consulta) use (&$escrituras): void {
        if (str_contains(mb_strtolower($consulta->sql), 'update "users"')) {
            $escrituras++;
        }
    });

    for ($intento = 1; $intento <= 3; $intento++) {
        $this->postJson('/api/pos/login', [
            'username' => 'caro', 'password' => 'mala'.$intento, 'device_name' => 'Caja 1',
        ])->assertStatus(422);
    }

    expect($escrituras)->toBe(0);
});

it('answers a pos username sent as an array with a 422 and not a 500', function (): void {
    // El limitador corre ANTES de validar y compone su llave con `username`:
    // un array ahí revienta el cast a string y contesta 500. Un cuerpo raro no
    // puede ser la forma de tumbar el freno que protege la puerta.
    $this->postJson('/api/pos/login', [
        'username' => ['caro', 'luis'],
        'password' => 'loquesea',
        'device_name' => 'Caja 1',
    ])->assertStatus(422)->assertJsonValidationErrors('username');
});

it('does not hand a fresh bucket to the same username typed in another case', function (): void {
    // Las cinco primeras variantes agotan el cupo de la cuenta. Con la llave
    // cruda cada una estrenaba cubo y ninguna contaba, y todas autenticaban
    // contra LA MISMA cajera: 'caro' y 'CaRo' son el mismo usuario para el
    // controlador, que busca con mb_strtolower(trim()).
    foreach (['caro', 'Caro', 'cARO', 'CARO', 'CaRo'] as $variante) {
        $this->postJson('/api/pos/login', [
            'username' => $variante, 'password' => 'mala', 'device_name' => 'Caja 1',
        ])->assertStatus(422);
    }

    $this->postJson('/api/pos/login', [
        'username' => 'cArO', 'password' => 'mala', 'device_name' => 'Caja 1',
    ])
        ->assertStatus(429)
        ->assertJsonPath('code', 'pos_demasiados_intentos')
        ->assertJsonPath('message', 'Demasiados intentos. Espera un minuto y vuelve a probar.');
});
