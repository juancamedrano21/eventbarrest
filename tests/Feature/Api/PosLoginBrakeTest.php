<?php

declare(strict_types=1);

use App\Domains\Identity\Actions\CreateTenantUser;
use App\Domains\Identity\Enums\Role;
use App\Domains\Platform\Actions\CreateTenant;
use App\Domains\Platform\Enums\TenantType;
use App\Domains\Tenancy\TenantContext;
use Illuminate\Support\Facades\Schema;
use Tests\Support\ContadorDeHashes;

/**
 * El freno del login del POS: lo que cuesta un intento, lo que hace falta para
 * esquivarlo, y a quién NO puede dejar fuera.
 *
 * Los tests de aquí abajo son agujeros medidos, convertidos en red:
 *
 * 1) El freno de cinco por minuto se saltaba cambiando las MAYÚSCULAS del
 *    usuario, porque la llave usaba el valor crudo mientras el controlador
 *    resolvía con `mb_strtolower(trim())`. Sesenta adivinanzas por minuto
 *    contra una cajera en vez de cinco.
 * 2) Un intento con usuario inexistente costaba DOS bcrypt —un `Hash::make`
 *    dentro del argumento del `Hash::check`—, el mejor amplificador de la
 *    aplicación y, de propina, un oráculo de temporización al revés: la
 *    respuesta lenta significaba «ese usuario no existe».
 *
 * Y la restricción dura, que es la que hace peligroso arreglar esto mal: una
 * cajera con su contraseña buena tiene que abrir su caja AUNQUE alguien lleve
 * un rato tecleando mal contra su cuenta.
 *
 * AQUÍ VIVÍA UN TEST DE UN CONTADOR DE FALLOS POR CUENTA EN LA BASE —diez
 * fallos, `pos_locked_until`, 429 'pos_cuenta_frenada'— y se quitó a la vez que
 * el contador. El porqué se escribe aquí y no en el mensaje del commit porque
 * es lo único que impedirá que alguien lo vuelva a proponer dentro de seis
 * meses con el mismo argumento razonable de la primera vez: (a) ABRÍA UN ORÁCULO
 * DE ENUMERACIÓN DE USUARIOS, porque solo una cuenta que EXISTE tiene fila que
 * contar y por tanto solo ella llegaba al 429 — once peticiones bastaban para
 * confirmar cada nombre de usuario, y el resto del fichero está dedicado
 * precisamente a que no se pueda distinguir quién existe ni por cuerpo, ni por
 * código, ni por reloj; (b) NO CAPABA NI UNA ADIVINANZA, porque quien acierta
 * tiene que entrar siempre —o se deja a una cajera sin abrir su caja desde un
 * móvil, que es peor que el ataque— así que el contador solo cambiaba el código
 * de estado del rechazo: 120 adivinanzas en un minuto se sirven las 120 con
 * contador y sin él; (c) COBRABA UN UPDATE a `users` por cada intento fallido,
 * escritura gratis y serializada por fila justo en el escenario de inundación
 * que decía acotar. O sea: pagaba con una fuga de nombres y una escritura por
 * intento algo que no frenaba nada. Lo que sí frena es `throttle:pos-login`
 * (cinco por minuto, usuario+origen), y lo que ese limitador no alcanza —la IP
 * la escribe quien llama— se arregla acotando `trustProxies(at:)` a los rangos
 * del borde, que es una decisión de despliegue y no un contador más.
 *
 * (Vive en un fichero propio y no dentro de los tests del alta del KDS, donde
 * una ronda anterior los dejó a sabiendas: es otra puerta.)
 */
beforeEach(function (): void {
    $this->negocio = app(CreateTenant::class)('Bar Demo', null, TenantType::Business);

    app(TenantContext::class)->runAs($this->negocio, function (): void {
        app(CreateTenantUser::class)(
            $this->negocio, 'Caro', 'caro@bar.test', 'Secreta-2026', Role::Cashier, username: 'caro',
        );
    });
});

afterEach(function (): void {
    app(TenantContext::class)->clear();
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

it('lets the cashier open her till with her own password while her account is being guessed at', function (): void {
    // Once fallos contra la cuenta de Caro, cada uno desde otro origen, que es
    // lo que hoy hace inútil cualquier llave por IP: la cabecera se cree.
    for ($intento = 1; $intento <= 11; $intento++) {
        $this->withHeader('X-Forwarded-For', '203.0.113.'.$intento)
            ->postJson('/api/pos/login', [
                'username' => 'caro', 'password' => 'mala'.$intento, 'device_name' => 'Caja 1',
            ]);
    }

    // Y Caro llega a su caja, desde su tablet, con SU contraseña. Tiene que
    // entrar. Un freno que rechaza sin mirar la contraseña deja a una cajera
    // sin trabajar en mitad del servicio, gratis y desde un móvil, y eso es
    // peor que las adivinanzas que evitaría.
    $this->withHeader('X-Forwarded-For', '198.51.100.44')
        ->postJson('/api/pos/login', [
            'username' => 'caro', 'password' => 'Secreta-2026', 'device_name' => 'Caja de Caro',
        ])->assertCreated();

    // Y esos once fallos no dejaron rastro en su fila. Aquí había dos
    // aserciones sobre `pos_failed_attempts` y `pos_locked_until`: pasaban por
    // accidente, porque `getAttribute` de una columna que no existe devuelve
    // null y `(int) null` es 0. Lo que de verdad hay que fijar es que la tabla
    // de usuarios no se toque en un intento fallido, y eso se mide leyendo el
    // esquema, no un atributo fantasma.
    expect(Schema::hasColumn('users', 'pos_failed_attempts'))->toBeFalse()
        ->and(Schema::hasColumn('users', 'pos_locked_until'))->toBeFalse();
});

it('answers a pos username sent as an array with a 422 and not a 500', function (): void {
    // El limitador corre ANTES de validar y compone su llave con `username`:
    // un array ahí revienta el cast a string y contesta 500.
    $this->postJson('/api/pos/login', [
        'username' => ['caro', 'luis'],
        'password' => 'loquesea',
        'device_name' => 'Caja 1',
    ])->assertStatus(422)->assertJsonValidationErrors('username');
});
