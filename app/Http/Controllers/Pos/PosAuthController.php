<?php

declare(strict_types=1);

namespace App\Http\Controllers\Pos;

use App\Domains\EventManagement\Models\OrganizerAccount;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * La puerta del POS: emite el token del dispositivo. Solo entra quien puede
 * operar el punto de venta (vender o manejar caja) en una cuenta y comercio
 * activos — el mismo criterio fail-closed del resto de la plataforma.
 *
 * UN SOLO BCRYPT POR PETICIÓN, PASE LO QUE PASE. Aquí había un
 * `Hash::make('nunca-coincide-'.$username)` DENTRO del argumento del
 * `Hash::check` para el caso de usuario inexistente. Costaba dos bcrypt por
 * petición fallida —el mejor amplificador de inundación que tenía la
 * aplicación, porque el usuario lo escribe quien llama y no hace falta acertar
 * nada para pedirlos— y encima conseguía justo lo contrario de lo que
 * pretendía: al tardar el DOBLE, la respuesta lenta significaba «ese usuario
 * no existe», que es el oráculo de temporización que el hash tonto venía a
 * tapar. El hash de abajo está precalculado, como el de EnrollKdsDevice, y por
 * el mismo motivo: generar un bcrypt cuesta lo mismo que comprobarlo.
 *
 * AQUÍ NO HAY CONTADOR DE FALLOS POR CUENTA, Y NO ES UN OLVIDO. Lo hubo, en la
 * base: diez fallos y la cuenta contestaba 429 durante quince minutos, con la
 * contraseña comprobada ANTES para que la dueña entrase igual. Se quitó porque
 * lo medido fue esto:
 *
 *   - ABRÍA UN ORÁCULO DE ENUMERACIÓN. Un usuario que EXISTE llegaba a 429; uno
 *     que no existe se quedaba en 422 para siempre, porque no hay fila que
 *     contar. Once peticiones bastaban para confirmar cada nombre de usuario, y
 *     una por cada quince minutos para reconfirmarlo. Es exactamente lo que el
 *     hash tonto de abajo venía a evitar, pero determinista y legible en la
 *     primera línea de la respuesta en vez de escondido en el cronómetro.
 *   - NO CAPABA LAS ADIVINANZAS. Como quien acierta entra siempre —y tenía que
 *     ser así, o se deja a una cajera sin abrir su caja desde un móvil—, el
 *     contador solo cambiaba el código de estado del rechazo. Medido: 120
 *     adivinanzas en un minuto rotando la cabecera del origen se sirven las
 *     120, con contador y sin él.
 *   - NO AHORRABA NI EL BCRYPT. Una cuenta frenada seguía gastando su
 *     Hash::check antes de contestar 429, así que ni siquiera compraba CPU.
 *   - Y COBRABA UN UPDATE a `users` por cada intento fallido: escritura gratis
 *     en la tabla de usuarios justo en el escenario de inundación que decía
 *     acotar, serializada por fila cuando la campaña va contra una sola cuenta.
 *
 * O sea: pagaba con una fuga de nombres de usuario y una escritura por intento
 * algo que no frenaba nada. El ritmo lo capa `throttle:pos-login` —cinco por
 * minuto y usuario+origen, con el usuario normalizado igual que la consulta de
 * abajo—, y lo que ese limitador no capa (la IP la escribe quien llama, ver
 * bootstrap/app.php) no se arregla con un contador que quien ataca sube a
 * voluntad sobre la cuenta que él elige: se arregla acotando `at:` a los rangos
 * del borde, que es una decisión de despliegue y está escrita allí.
 *
 * LO QUE SÍ SE MANTIENE ES QUE LA CAJERA ENTRE. Un fallo indistinguible, un
 * bcrypt, ningún estado que nadie pueda dejar encendido: una cajera con su
 * contraseña buena abre su caja aunque lleven una hora tecleando mal contra su
 * usuario.
 */
class PosAuthController extends Controller
{
    /**
     * Un bcrypt de verdad contra una frase que nadie conoce, para que el
     * camino del usuario inexistente cueste y tarde lo mismo que el del
     * usuario que sí existe. Constante y no `Hash::make` en caliente: generar
     * el hash cuesta lo mismo que comprobarlo, y hacerlo aquí duplicaría
     * ambas cosas.
     */
    private const HASH_TONTO = '$2y$12$Y4kQr5oPUeFeZVEZO/IphupQ73OzyP/aLVRDztKrGFr8W0DArdlZq';

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'username' => ['required', 'string', 'max:30'],
            'password' => ['required', 'string'],
            'device_name' => ['required', 'string', 'max:80'],
            // La puerta por la que entra: event-pos o pos.
            'modalidad' => ['nullable', 'in:event,business'],
        ]);

        // El usuario se normaliza AQUÍ y en un solo sitio, y el limitador de
        // la ruta compone su llave con esta misma normalización: si no, 'caro'
        // y 'CaRo' serían dos cubos para la misma cuenta y el freno de cinco
        // por minuto se saltaría cambiando una mayúscula.
        $usuario = mb_strtolower(trim((string) $data['username']));

        $user = User::query()->where('username', $usuario)->first();

        // Un solo fallo indistinguible para credencial mala, usuario
        // inexistente o usuario sin POS: nada que enumerar, ni por cuerpo, ni
        // por código de estado, ni por tiempo. El hash se comprueba SIEMPRE
        // —el tonto si no hay usuario— para que el reloj tampoco lo cuente.
        // `??` con semántica de isset: si no hay usuario, `$user->password` no
        // revienta, cae al hash tonto y se gasta el mismo bcrypt.
        $passwordOk = Hash::check(
            (string) $data['password'],
            (string) ($user->password ?? self::HASH_TONTO),
        );

        if ($user === null || ! $passwordOk) {
            throw $this->credencialesMalas();
        }

        // Que la cuenta no opere el POS no es adivinar una contraseña, pero se
        // contesta lo mismo: distinguirlo diría «esta cuenta existe y su
        // contraseña era esa».
        if (! $user->canOperateThePos()) {
            throw $this->credencialesMalas();
        }

        // Cada modalidad tiene su POS: el cajero de un festival no entra
        // por la puerta del negocio ni al revés. Mensaje explícito —aquí
        // ya está autenticado, no hay nada que enumerar— para que sepa a
        // qué app ir en vez de pensar que su clave falla.
        $suModalidad = $user->tenant instanceof OrganizerAccount ? 'event' : 'business';

        if (filled($data['modalidad'] ?? null) && $data['modalidad'] !== $suModalidad) {
            throw ValidationException::withMessages([
                'username' => $suModalidad === 'event'
                    ? 'Tu caja es de un evento: entra por el POS de eventos.'
                    : 'Tu caja es de un negocio: entra por el POS del negocio.',
            ]);
        }

        return response()->json([
            'token' => $user->createToken($data['device_name'], ['pos'])->plainTextToken,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'username' => $user->username,
                'tenant' => $user->tenant?->name,
                'vendor_id' => $user->vendor_id,
            ],
        ], 201);
    }

    public function logout(Request $request): JsonResponse
    {
        $token = $request->user()?->currentAccessToken();

        if ($token instanceof PersonalAccessToken) {
            $token->delete();
        }

        return response()->json(['message' => 'Sesión cerrada.']);
    }

    private function credencialesMalas(): ValidationException
    {
        return ValidationException::withMessages([
            'username' => 'Credenciales incorrectas.',
        ]);
    }
}
