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
 * las dos «Cocina 1», se verían aquí como una. Como el error que se pagaría es
 * dejar ciego un puesto en pleno servicio, la conjetura viene con tres frenos.
 *
 * PRIMERO: LO QUE SIGUE DANDO SEÑAL NO SE TOCA. Una fila que ha latido en la
 * última hora no es un fantasma, es una pantalla encendida en un puesto, y no
 * hay conjetura de nombres que valga contra eso. Si dos filas del mismo grupo
 * están vivas a la vez, son DOS TABLETAS: la segunda se queda quieta y la
 * lista lo dice, en vez de fundirlas y apagarle la pantalla al cocinero que la
 * estaba usando. Nótese lo que esto NO hace: no impide limpiar el grupo — las
 * cuatro filas mudas que las acompañan se revocan igual, que era el destrozo
 * que había que barrer.
 *
 * SEGUNDO: LO QUE TIENE IDENTIDAD NO SE TOCA. Esas filas ya no se duplican
 * solas —el alta las reconoce y les devuelve la suya— así que si hay una en el
 * grupo es la buena, y sea cual sea el resultado de la conjetura no hay ningún
 * motivo para apagarla. Sí participa del grupo, para poder quedarse ella como
 * la superviviente y para que quien lea la lista la vea.
 *
 * TERCERO: SE MIRA ANTES DE ESCRIBIR, Y SE CONTESTA. En seco enseña la lista y
 * no toca nada; con --aplicar enseña la MISMA lista, fila por fila y con lo que
 * le va a pasar a cada una escrito al lado, y pregunta. La pregunta sale con el
 * «no» por defecto, así que un `--aplicar --no-interaction` de un script no
 * revoca nada: esto se corre a mano, mirando, o no se corre.
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
        {--aplicar : Enseña la lista, pregunta, y solo entonces revoca}
        {--tenant= : Solo esta cuenta, por id}';

    protected $description = 'Junta las filas duplicadas de la misma tablet en un puesto (en seco salvo --aplicar)';

    /**
     * Cuánto silencio hace falta para dar una fila por fantasma.
     *
     * Una hora, y es a propósito mucho más de lo que tarda una tablet en
     * contestar —pregunta cada tres segundos y su latido se anota cada minuto—.
     * El margen no es para el sondeo: es para el corte de wifi del recinto, el
     * cambio de turno con la pantalla en la mochila y el rato que la tablet
     * pasa cargando en la oficina antes de abrir. Cualquiera de esas tres, con
     * una ventana corta, se leería como «esta fila está muerta» y acabaría con
     * el token apagado de una pantalla que se iba a colgar en diez minutos.
     * Pasarse por arriba, en cambio, solo cuesta que una fila fantasma se
     * quede otro día sin barrer, que no le hace daño a nadie.
     */
    public const MINUTOS_DE_VIDA = 60;

    /**
     * La pregunta, palabra por palabra y en una constante, porque las pruebas
     * la contestan por su texto: si cambia aquí y no allí, la prueba se cae en
     * vez de aprobar en silencio un comando que ya no pregunta lo mismo.
     */
    public const PREGUNTA = '¿Revoco las filas marcadas arriba como «se revoca»?';

    public function handle(RevokeKdsDevice $revocar): int
    {
        $grupos = $this->duplicados();

        [$filas, $condenadas] = $this->plan($grupos);

        if ($condenadas === []) {
            $this->info($grupos->isEmpty()
                ? 'No hay nada que fusionar: cada puesto tiene una fila viva por nombre.'
                : 'Hay nombres repetidos, pero ninguna fila se puede fusionar: o siguen dando señal '
                    .'—y entonces son tabletas de verdad, no copias— o ya tienen identidad.');

            return self::SUCCESS;
        }

        $this->table(
            ['Cuenta', 'Puesto', 'Nombre', 'Fila', 'Último latido', 'Identidad', 'Qué le pasa'],
            $filas,
        );

        // El resumen va DEBAJO de la tabla y no encima: es lo último que se lee
        // antes de contestar, y tiene que decir el número y también lo que el
        // comando NO ha mirado, que es de dónde vendría el susto.
        $this->newLine();
        $this->line('  Se revocarían <options=bold>'.count($condenadas).'</> fila(s) de '
            .count($filas).' que hay en los grupos de arriba.');
        $this->line('  La conjetura es «mismo puesto y mismo nombre»: el comando no sabe si eran el mismo');
        $this->line('  aparato, solo que se llamaban igual. Mira la columna del último latido antes de decir que sí.');

        if (! $this->option('aplicar')) {
            $this->newLine();
            $this->warn('En seco: no se ha tocado nada. Repite con --aplicar y te preguntará.');

            return self::SUCCESS;
        }

        $this->newLine();

        if (! $this->confirm(self::PREGUNTA, false)) {
            $this->warn('No se ha tocado nada.');

            return self::SUCCESS;
        }

        foreach ($condenadas as $fila) {
            $revocar($fila);
        }

        $this->info('Revocadas '.count($condenadas).' fila(s) duplicada(s). '
            .'Las comandas que tocaron siguen apuntando a ellas.');

        return self::SUCCESS;
    }

    /**
     * Qué le pasa a cada fila de cada grupo, y qué filas se van a revocar.
     *
     * Se devuelve el grupo ENTERO —la que se queda, las que se revocan y las
     * que no se tocan, con el motivo— y no solo las condenadas. La diferencia
     * importa: una lista de seis «se revoca» a secas empuja a teclear que sí,
     * mientras que ver las siete filas del grupo con la que sobrevive marcada
     * es lo único que permite decir «un momento, esas dos están vivas».
     *
     * Los grupos donde no se revoca nada no salen: son ruido que enterraría la
     * media docena de líneas que sí hay que leer.
     *
     * @param  Collection<string, Collection<int, KdsDevice>>  $grupos
     * @return array{0: array<int, array<int, string>>, 1: array<int, KdsDevice>}
     */
    private function plan(Collection $grupos): array
    {
        $filas = [];
        $condenadas = [];

        foreach ($grupos as $grupo) {
            // La que se queda va primera: es la que la tablet tiene en la
            // mano. Ver cualSobrevive().
            $sobrevive = $grupo->first();

            $filasDelGrupo = [];
            $condenadasDelGrupo = [];

            foreach ($grupo as $fila) {
                if ($fila->getKey() === $sobrevive?->getKey()) {
                    // «La del último latido» y no «la buena»: es lo que el
                    // comando sabe de verdad, y si resulta que era la mala, esa
                    // frase es la que hace que quien mira la lista lo note.
                    $veredicto = 'SE QUEDA (la del último latido)';
                } elseif (($motivo = $this->porQueNoSeToca($fila)) !== null) {
                    $veredicto = 'no se toca: '.$motivo;
                } else {
                    $veredicto = 'se revoca';
                    $condenadasDelGrupo[] = $fila;
                }

                $filasDelGrupo[] = [
                    (string) $fila->getAttribute('tenant_id'),
                    (string) $fila->getAttribute('operating_unit_id'),
                    (string) $fila->getAttribute('name'),
                    (string) $fila->getAttribute('id'),
                    $this->latido($fila),
                    $fila->getAttribute('device_identity') === null ? '—' : 'sí',
                    $veredicto,
                ];
            }

            if ($condenadasDelGrupo === []) {
                continue;
            }

            $filas = array_merge($filas, $filasDelGrupo);
            $condenadas = array_merge($condenadas, $condenadasDelGrupo);
        }

        return [$filas, $condenadas];
    }

    /**
     * Por qué esta fila se queda como está, o null si de verdad sobra.
     *
     * Son los dos frenos de la conjetura, escritos donde se aplican. El texto
     * que devuelve se imprime tal cual en la tabla: quien la lee tiene que
     * poder entender por qué el comando dejó viva una fila sin ir a buscar
     * este archivo.
     */
    private function porQueNoSeToca(KdsDevice $fila): ?string
    {
        if ($fila->getAttribute('device_identity') !== null) {
            return 'tiene identidad, no se duplica sola';
        }

        if ($this->sigueViva($fila)) {
            return 'ha dado señal, es otra tablet';
        }

        return null;
    }

    /** ¿Ha latido esta fila dentro de la ventana que la da por encendida? */
    private function sigueViva(KdsDevice $fila): bool
    {
        $latido = $fila->getAttribute('last_seen_at');

        // El umbral se calcula sobre now() y NUNCA sobre $latido: Carbon es
        // mutable, y un $latido->addMinutes(...) reescribiría el atributo del
        // modelo en memoria justo antes de que revocarlo lo persistiera.
        return $latido !== null
            && $latido->greaterThan(now()->subMinutes(self::MINUTOS_DE_VIDA));
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
