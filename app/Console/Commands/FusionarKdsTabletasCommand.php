<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domains\Kitchen\Actions\RevokeKdsDevice;
use App\Domains\Kitchen\Models\KdsDevice;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Junta las tabletas duplicadas que ya están en la base.
 *
 * A partir de ahora el alta no duplica: la tablet manda su identidad y se le
 * devuelve su fila. Pero lo que ya se duplicó sigue ahí —seis filas «Cocina 1»
 * en el mismo puesto, todas la misma Galaxy Tab— y esas filas NO tienen
 * identidad con la que reconocerse, porque nacieron antes de que existiera la
 * columna. Este comando es la pasada de limpieza que se hace una vez.
 *
 * EL CRITERIO ES UNA CONJETURA, y hay que decirlo antes que nada: sin
 * identidad, lo único que queda para adivinar que dos filas son el mismo
 * aparato es que estén en el MISMO puesto y se llamen IGUAL. Casi siempre
 * acierta —los duplicados nacen de reenrolar la misma tablet, y quien la
 * enrola vuelve a teclear el mismo nombre— pero puede equivocarse: dos
 * pantallas de verdad, colgadas a la vez en la misma ventanilla y bautizadas
 * las dos «Cocina 1», se verían aquí como una. De ahí las dos decisiones que
 * siguen.
 *
 * VA EN SECO POR DEFECTO. Sin --aplicar no escribe nada: enseña la lista de lo
 * que haría, con la hora del último latido de cada fila, para que quien la lea
 * pueda distinguir a la tablet viva de las cinco fantasmas antes de tocar
 * nada. Una tablet que sigue preguntando cada tres segundos se ve en esa
 * columna.
 *
 * Y REVOCA, NO BORRA. `kitchen_tickets.started_by_device_id` y su gemela
 * ready_by_device_id apuntan a estas filas sin foreign key: son el rastro de
 * qué aparato tocó cada comanda. Borrarlas dejaría ese rastro apuntando al
 * vacío justo cuando hace falta —al reclamar un plato que nunca salió—.
 * Revocar solo apaga el token, y si el comando se equivocó de fila, el
 * arreglo es volver a enrolar esa tablet con el código y el PIN: treinta
 * segundos en el puesto, no una restauración de la base.
 */
class FusionarKdsTabletasCommand extends Command
{
    protected $signature = 'kds:fusionar-tabletas
        {--aplicar : Revoca de verdad. Sin esta bandera solo enseña lo que haría}
        {--tenant= : Solo esta cuenta, por id}';

    protected $description = 'Junta las filas duplicadas de la misma tablet en un puesto (en seco salvo --aplicar)';

    public function handle(RevokeKdsDevice $revocar): int
    {
        $aplicar = (bool) $this->option('aplicar');
        $duplicados = $this->duplicados();

        if ($duplicados->isEmpty()) {
            $this->info('No hay tabletas duplicadas: cada puesto tiene una fila por nombre.');

            return self::SUCCESS;
        }

        $filas = [];
        $revocadas = 0;

        foreach ($duplicados as $grupo) {
            // La que se queda va primera: es la que la tablet tiene en la
            // mano. Ver ordenDeSupervivencia().
            $sobrevive = $grupo->first();

            foreach ($grupo->skip(1) as $sobra) {
                $filas[] = [
                    $sobra->getAttribute('tenant_id'),
                    $sobra->getAttribute('operating_unit_id'),
                    $sobra->getAttribute('name'),
                    (string) $sobra->getAttribute('id'),
                    $this->latido($sobra),
                    (string) $sobrevive->getAttribute('id'),
                ];

                if ($aplicar) {
                    $revocar($sobra);
                    $revocadas++;
                }
            }
        }

        $this->table(
            ['Cuenta', 'Puesto', 'Nombre', 'Se revoca', 'Último latido', 'Se queda'],
            $filas,
        );

        if (! $aplicar) {
            $this->warn(count($filas).' fila(s) se revocarían. Nada se ha tocado: repite con --aplicar.');
            $this->line('  Mira antes la columna del latido: la que sigue preguntando es la tablet de verdad.');

            return self::SUCCESS;
        }

        $this->info("Revocadas {$revocadas} fila(s) duplicada(s). Las comandas que tocaron siguen apuntando a ellas.");

        return self::SUCCESS;
    }

    /**
     * Los grupos de filas VIVAS que se sospecha que son el mismo aparato:
     * mismo puesto y mismo nombre, y más de una.
     *
     * Las revocadas no entran: ya están apagadas y revocarlas otra vez no
     * cambiaría nada salvo el ruido de la lista.
     *
     * @return Collection<string, Collection<int, KdsDevice>>
     */
    private function duplicados(): Collection
    {
        /** @var Collection<int, KdsDevice> $vivas */
        $vivas = KdsDevice::query()->withoutTenancy()
            ->whereNull('revoked_at')
            ->when($this->option('tenant'), fn ($query, $id) => $query->where('tenant_id', (int) $id))
            ->get();

        return $vivas
            ->groupBy(fn (KdsDevice $device): string => implode('|', [
                $device->getAttribute('tenant_id'),
                $device->getAttribute('operating_unit_id'),
                // En minúscula y sin espacios de los bordes: «Cocina 1» y
                // «cocina 1 » salen del mismo dedo en dos días distintos.
                mb_strtolower(trim((string) $device->getAttribute('name'))),
            ]))
            ->filter(fn (Collection $grupo): bool => $grupo->count() > 1)
            ->map(fn (Collection $grupo): Collection => $grupo->sort(
                fn (KdsDevice $una, KdsDevice $otra): int => $this->cualSobrevive($una, $otra),
            )->values());
    }

    /**
     * Cuál de las dos se queda. Delante, la que preguntó más recientemente;
     * entre las que nunca preguntaron, la más nueva.
     *
     * El latido manda sobre la fecha de alta porque responde a la pregunta
     * correcta: de las seis filas, la única que la tablet está usando ahora
     * mismo es aquella cuyo token tiene guardado, y esa es la que aparece
     * cada tres segundos. Las otras cinco se quedaron mudas el día que la
     * tablet perdió el suyo.
     */
    private function cualSobrevive(KdsDevice $una, KdsDevice $otra): int
    {
        $suyo = $una->getAttribute('last_seen_at')?->getTimestamp() ?? 0;
        $ajeno = $otra->getAttribute('last_seen_at')?->getTimestamp() ?? 0;

        // El id decide el empate: entre dos filas que nunca preguntaron, la
        // más nueva es la del último intento de enrolar, que es lo más
        // parecido a «la buena» que hay sin latidos.
        return $suyo === $ajeno
            ? (int) $otra->getAttribute('id') <=> (int) $una->getAttribute('id')
            : $ajeno <=> $suyo;
    }

    private function latido(KdsDevice $device): string
    {
        $latido = $device->getAttribute('last_seen_at');

        return $latido === null ? 'nunca' : $latido->diffForHumans();
    }
}
