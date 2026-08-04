<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Support\Facades\Hash;
use SensitiveParameter;

/**
 * El hasher de verdad, envuelto para contar cuántos bcrypt gasta una petición.
 *
 * Se CUENTAN las llamadas y no se cronometra el reloj porque en los tests las
 * rondas de bcrypt están bajadas a cuatro (phpunit.xml): el cronómetro no
 * distingue uno de ocho, y el contador sí. Y lo que se está fijando es
 * exactamente eso — cuántos bcrypt regala una sola petición anónima.
 *
 * `make` se cuenta aparte de `check` porque generar un hash cuesta lo mismo
 * que comprobarlo: el amplificador del login del POS era precisamente un
 * `Hash::make` metido dentro del argumento de un `Hash::check`.
 */
final class ContadorDeHashes implements Hasher
{
    public int $comprobaciones = 0;

    public int $generaciones = 0;

    private function __construct(private readonly Hasher $real) {}

    /** Se instala en el contenedor y se devuelve para poder leerlo después. */
    public static function instalar(): self
    {
        /** @var Hasher $real */
        $real = app('hash')->driver();

        $contador = new self($real);

        Hash::swap($contador);

        return $contador;
    }

    public function aCero(): void
    {
        $this->comprobaciones = 0;
        $this->generaciones = 0;
    }

    /**
     * @param  string  $hashedValue
     * @return array<string, mixed>
     */
    public function info($hashedValue): array
    {
        return $this->real->info($hashedValue);
    }

    /**
     * @param  array<string, mixed>  $options
     */
    public function make(#[SensitiveParameter] $value, array $options = []): string
    {
        $this->generaciones++;

        return $this->real->make($value, $options);
    }

    /**
     * @param  array<string, mixed>  $options
     */
    public function check(#[SensitiveParameter] $value, $hashedValue, array $options = []): bool
    {
        $this->comprobaciones++;

        return $this->real->check($value, $hashedValue, $options);
    }

    /**
     * @param  array<string, mixed>  $options
     */
    public function needsRehash($hashedValue, array $options = []): bool
    {
        return $this->real->needsRehash($hashedValue, $options);
    }

    /**
     * El cast `hashed` de Eloquent llama a `Hash::isHashed()`, que no está en
     * el contrato: sin este reenvío, envolver el hasher rompería cualquier
     * `$user->password = ...` que ocurra durante el test.
     *
     * @param  array<int, mixed>  $argumentos
     */
    public function __call(string $metodo, array $argumentos): mixed
    {
        return $this->real->{$metodo}(...$argumentos);
    }
}
