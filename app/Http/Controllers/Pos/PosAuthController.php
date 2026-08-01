<?php

declare(strict_types=1);

namespace App\Http\Controllers\Pos;

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
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'device_name' => ['required', 'string', 'max:80'],
        ]);

        $user = User::query()->where('email', $data['email'])->first();

        if ($user === null || ! Hash::check($data['password'], (string) $user->password)) {
            throw ValidationException::withMessages([
                'email' => 'Credenciales incorrectas.',
            ]);
        }

        abort_unless($user->canOperateThePos(), 403, 'Este usuario no opera el punto de venta.');

        return response()->json([
            'token' => $user->createToken($data['device_name'], ['pos'])->plainTextToken,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
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
