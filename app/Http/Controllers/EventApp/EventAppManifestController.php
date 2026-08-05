<?php

declare(strict_types=1);

namespace App\Http\Controllers\EventApp;

use App\Domains\EventApp\Models\EventAppManifest;
use App\Domains\EventApp\Support\VocabularioPublico;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lo primero que pide la app al arrancar, y lo único sin lo cual no puede
 * pintarse: qué evento es, con qué marca y con qué módulos.
 *
 * NUNCA CONTESTA 404 POR FALTA DE CONFIGURACIÓN. Un evento sin manifiesto
 * configurado —el caso normal el día que se crea— recibe la marca de fábrica
 * y el módulo de menús encendido. La alternativa, un 404 «este evento no
 * tiene manifiesto», sería una app que no arranca porque nadie entró todavía
 * a elegir un color, y el 404 de esta puerta significa otra cosa: que el
 * código no existe.
 */
class EventAppManifestController extends EventAppController
{
    public function __invoke(Request $request): Response
    {
        $evento = $this->evento($request);
        $manifiesto = EventAppManifest::paraEvento($evento);

        return $this->responder($request, [
            'evento' => [
                // El código que se devuelve es el de la FILA, no el que vino
                // en la URL: quien llame con «bocao-26» recibe «BOCAO26», y
                // la app guarda la forma canónica.
                'codigo' => $evento->public_code,
                'nombre' => $evento->name,
                'lugar' => $evento->venue,
                'empieza_en' => $this->fecha($evento->starts_at),
                'termina_en' => $this->fecha($evento->ends_at),
                'estado' => VocabularioPublico::estado($evento->status),
            ],
            'marca' => $manifiesto->marca($evento),
            'modulos' => $manifiesto->modulos(),
            // Objeto y no lista, siempre. Un diccionario vacío en PHP es un
            // array vacío, y json_encode lo escribiría como `[]`: la app lo
            // lee como mapa y un `[]` donde espera `{}` la revienta al
            // arrancar, que es el peor momento posible para reventar.
            'textos' => (object) $manifiesto->textos(),
        ]);
    }
}
