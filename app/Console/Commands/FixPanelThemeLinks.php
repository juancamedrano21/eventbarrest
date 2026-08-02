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

        if ($html === $original) {
            $this->info('Los enlaces del tema ya estaban al día.');

            return self::SUCCESS;
        }

        File::put($layout, $html);
        $this->info('Enlaces del tema actualizados.');

        foreach (self::ENLACES as $viejo => $nuevo) {
            $this->line("  {$viejo} → ".($nuevo ?? 'eliminado'));
        }

        return self::SUCCESS;
    }
}
