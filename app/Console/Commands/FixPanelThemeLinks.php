<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Pone al día los enlaces del tema Preline Pro.
 *
 * El tema es código licenciado y vive FUERA de git: se restaura desde el ZIP
 * comprado. Sus enlaces se escribieron cuando las puertas se llamaban
 * `/panel` y `/app`, así que cada restauración los devuelve rotos.
 *
 * Editarlos a mano funciona una vez. Este comando lo hace siempre igual, se
 * versiona con el proyecto y es lo que hay que ejecutar después de volver a
 * poner el tema.
 */
class FixPanelThemeLinks extends Command
{
    protected $signature = 'panel:fix-theme-links';

    protected $description = 'Actualiza los enlaces del tema Preline Pro a las puertas actuales';

    /**
     * Enlaces viejos y su destino de hoy. Un valor nulo BORRA el enlace: es
     * el caso de `/app`, que dejó de existir.
     *
     * @var array<string, string|null>
     */
    private const ENLACES = [
        '/app' => null,
        '/panel/eventos' => '/event-panel/eventos',
        '/panel/comercios' => '/event-panel/comercios',
        '/panel' => '/event-panel',
    ];

    /**
     * Pantallas que nacieron DESPUÉS de comprar el tema y que su menú no
     * puede conocer. Cada una dice detrás de qué enlace se cuelga.
     *
     * @var array<string, array{texto: string, despues_de: string}>
     */
    private const ENTRADAS = [
        // Se mira DURANTE el evento, no para configurarlo: por eso va pegada
        // a Eventos y no al final con los ajustes.
        '/event-panel/comandas' => ['texto' => 'Comandas', 'despues_de' => '/event-panel/eventos'],
    ];

    /** Las del tema, copiadas tal cual para que la entrada nueva no desentone. */
    private const CLASES = 'flex gap-x-3 py-2 px-3 text-sm text-sidebar-nav-foreground rounded-lg hover:bg-sidebar-nav-hover focus:outline-hidden focus:bg-sidebar-nav-focus ';

    public function handle(): int
    {
        $layout = resource_path('panel-theme/views/layout.blade.php');

        if (! File::exists($layout)) {
            $this->warn('El tema no está instalado: no hay nada que actualizar.');

            // No es un fallo: en CI y en un clon fresco el tema no está y el
            // panel usa su layout simple.
            return self::SUCCESS;
        }

        $html = File::get($layout);
        $original = $html;

        // El enlace muerto se quita ENTERO, con su etiqueta: dejar solo el
        // href apuntando a otro sitio pondría «Panel clásico» en una pantalla
        // que no lo es.
        $html = preg_replace(
            '#<a\b[^>]*href="/app"[^>]*>.*?</a>#s',
            '',
            $html,
        ) ?? $html;

        foreach (self::ENLACES as $viejo => $nuevo) {
            if ($nuevo === null) {
                continue;
            }

            // El límite de palabra evita que `/panel` se coma `/panel-theme`.
            $html = preg_replace(
                '#href="'.preg_quote($viejo, '#').'(?![\w-])#',
                'href="'.$nuevo,
                $html,
            ) ?? $html;
        }

        $html = $this->ponerLasEntradasQueFaltan($html);

        if ($html === $original) {
            $this->info('El menú del tema ya estaba al día.');

            return self::SUCCESS;
        }

        File::put($layout, $html);
        $this->info('Menú del tema actualizado.');

        foreach (self::ENLACES as $viejo => $nuevo) {
            $this->line("  {$viejo} → ".($nuevo ?? 'eliminado'));
        }

        return self::SUCCESS;
    }

    /**
     * Añade al menú las puertas que nacieron después de comprar el tema.
     *
     * Es idempotente a conciencia: si el enlace ya está, no hace nada. Este
     * comando se ejecuta cada vez que alguien restaura el ZIP, y también
     * cuando ya estaba puesto — duplicar la entrada del menú en cada pasada
     * sería peor que no tenerla.
     */
    private function ponerLasEntradasQueFaltan(string $html): string
    {
        foreach (self::ENTRADAS as $href => $entrada) {
            if (str_contains($html, 'href="'.$href.'"')) {
                continue;
            }

            $ancla = '</a>';
            $posicion = mb_strpos($html, 'href="'.$entrada['despues_de'].'"');

            if ($posicion === false) {
                $this->warn("No encontré dónde colgar «{$entrada['texto']}»: el tema cambió de forma.");

                continue;
            }

            // Se cuelga justo después del enlace de referencia, cerrando su
            // etiqueta: así hereda su sitio en la lista sin tocar el marcado
            // de alrededor, que es de otro y puede cambiar en cualquier
            // versión del tema.
            $cierre = mb_strpos($html, $ancla, $posicion);

            if ($cierre === false) {
                continue;
            }

            $nuevo = sprintf(
                '<a class="%s" href="%s">%s%s</a>',
                self::CLASES,
                $href,
                PHP_EOL.'                      ',
                $entrada['texto'].PHP_EOL.'                    ',
            );

            $html = mb_substr($html, 0, $cierre + mb_strlen($ancla))
                .$nuevo
                .mb_substr($html, $cierre + mb_strlen($ancla));

            $this->line("  + {$entrada['texto']} → {$href}");
        }

        return $html;
    }
}
