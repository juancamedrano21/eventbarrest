<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domains\EventApp\Actions\IssueEventPublicCode;
use App\Domains\EventManagement\Models\Event;
use Illuminate\Console\Command;

/**
 * Reparte códigos públicos a los eventos que no tengan.
 *
 * La migración ya rellenó los que existían y CreateEvent emite el de cada
 * evento nuevo, así que en un sistema sano este comando no encuentra nada
 * que hacer. Existe para los dos caminos que se saltan la acción —un seeder,
 * un import— y, sobre todo, para el código de vanidad: el día que marketing
 * quiere que el cartel diga BOCAO26 en vez de las ocho letras que salieron
 * del generador, esto es la puerta.
 *
 * Cambiar el código de un evento que ya tiene apps instaladas las deja sin
 * servidor: llevan el viejo compilado dentro. Por eso `--codigo` exige un
 * evento concreto y por eso reemplazar solo ocurre cuando alguien lo pide.
 */
class EmitirCodigosPublicosCommand extends Command
{
    protected $signature = 'event-app:codigos
        {--evento= : Solo este evento, por id}
        {--codigo= : Fija este código a mano en vez de generarlo (exige --evento)}';

    protected $description = 'Emite el código público con el que la app del asistente reconoce cada evento';

    public function handle(IssueEventPublicCode $emitir): int
    {
        $deseado = $this->option('codigo');
        $evento = $this->option('evento');

        if ($deseado !== null && $evento === null) {
            $this->error('Fijar un código a mano exige decir a qué evento: usa --evento.');

            return self::FAILURE;
        }

        // Sin el scope de cuenta: un comando corre sin cuenta activa y
        // Event::query() no vería ni una fila.
        $eventos = Event::query()->withoutTenancy()
            ->when($evento !== null, fn ($query) => $query->whereKey($evento))
            // Los que ya tienen código se saltan salvo que se pida uno
            // concreto: la acción es idempotente, pero recorrer miles de
            // filas para no hacer nada tampoco tiene gracia.
            ->when($deseado === null, fn ($query) => $query->whereNull('public_code'))
            ->orderBy('id')
            ->get();

        if ($eventos->isEmpty()) {
            $this->warn('No hay eventos a los que emitir código.');

            return self::SUCCESS;
        }

        foreach ($eventos as $uno) {
            $codigo = $emitir($uno, is_string($deseado) ? $deseado : null);

            $this->line("  [{$uno->name}] → {$codigo}");
        }

        $this->info("Emitidos {$eventos->count()} código(s).");

        return self::SUCCESS;
    }
}
