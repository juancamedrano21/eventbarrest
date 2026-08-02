<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Domains\Identity\Queries\HomeForUser;
use App\Domains\Platform\Enums\TenantStatus;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * La entrada única de la plataforma (ADR-007): una sola pantalla, y cada
 * quien acaba en SU puerta — la cuenta en /panel, el comercio en
 * /comercio, la caja en /pos, el staff en /admin.
 *
 * Acepta correo o usuario: el equipo de la cuenta entra con su email y el
 * personal de comercio con el nombre corto que ya usa en el POS.
 */
class LoginController extends Controller
{
    public function show(Request $request): View|RedirectResponse
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return view('auth.login');
        }

        $destino = app(HomeForUser::class)($user);

        // HomeForUser devuelve esta misma pantalla cuando un rol no abre
        // ninguna puerta. Redirigir aquí sería un bucle: se le dice qué pasa
        // y se cierra la sesión, que es lo único que puede hacer por sí solo.
        if ($destino === '/entrar') {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return view('auth.login')->with(
                'aviso',
                'Tu usuario no tiene ninguna pantalla asignada todavía. Pídele a quien administra la cuenta que te dé un rol.',
            );
        }

        return redirect($destino);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'usuario' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string'],
        ]);

        // Por identidad Y por IP: ni fuerza bruta contra una cuenta ni
        // barrido de usuarios desde un mismo origen.
        $llave = mb_strtolower($data['usuario']).'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($llave, 5)) {
            throw ValidationException::withMessages([
                'usuario' => 'Demasiados intentos. Espera '.RateLimiter::availableIn($llave).' segundos.',
            ]);
        }

        $campo = filter_var($data['usuario'], FILTER_VALIDATE_EMAIL) ? 'email' : 'username';

        if (! Auth::attempt([$campo => $data['usuario'], 'password' => $data['password']], $request->boolean('recordarme'))) {
            RateLimiter::hit($llave);

            // Un solo mensaje para usuario inexistente y clave errada: la
            // pantalla no dice quién existe.
            throw ValidationException::withMessages([
                'usuario' => 'Esas credenciales no coinciden con ninguna cuenta.',
            ]);
        }

        RateLimiter::clear($llave);
        $request->session()->regenerate();

        $user = $request->user();

        // Suspender corta el acceso de todo el equipo, aquí también.
        if (! $user instanceof User
            || $user->tenant === null && ! $user->isPlatformStaff()
            || $user->tenant?->status === TenantStatus::Suspended) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            throw ValidationException::withMessages([
                'usuario' => 'Esta cuenta no está disponible. Habla con el administrador.',
            ]);
        }

        return redirect()->intended(app(HomeForUser::class)($user));
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/entrar');
    }
}
