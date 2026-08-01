<?php

declare(strict_types=1);

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * El dashboard del panel: la plantilla Preline Pro comprada, servida tal
 * cual (fase «pantalla idéntica»); iremos sustituyendo sus datos de ejemplo
 * por los reales. El HTML licenciado vive FUERA de git (resources/panel-theme,
 * ignorado): en un entorno nuevo se restaura desde el ZIP comprado.
 */
class DashboardController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $path = resource_path('panel-theme/dashboard.html');

        abort_unless(is_file($path), 503, 'Tema del panel no instalado: restaura resources/panel-theme desde el ZIP de Preline Pro.');

        return response((string) file_get_contents($path))
            ->header('Content-Type', 'text/html; charset=utf-8');
    }
}
