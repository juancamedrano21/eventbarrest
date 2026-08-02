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
 */
class PosAuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'username' => ['required', 'string', 'max:30'],
            'password' => ['required', 'string'],
            'device_name' => ['required', 'string', 'max:80'],
            // La puerta por la que entra: event-pos o pos.
            'modalidad' => ['nullable', 'in:event,business'],
        ]);

        $user = User::query()->where('username', mb_strtolower(trim($data['username'])))->first();

        // Un solo fallo indistinguible para credencial mala, usuario
        // inexistente o usuario sin POS: nada que enumerar. El hash se
        // comprueba SIEMPRE (dummy si no hay usuario) para no filtrar por
        // tiempo de respuesta.
        $passwordOk = Hash::check(
            $data['password'],
            (string) ($user->password ?? Hash::make('nunca-coincide-'.$data['username'])),
        );

        if ($user === null || ! $passwordOk || ! $user->canOperateThePos()) {
            throw ValidationException::withMessages([
                'username' => 'Credenciales incorrectas.',
            ]);
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
}
