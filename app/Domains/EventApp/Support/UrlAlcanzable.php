<?php

declare(strict_types=1);

namespace App\Domains\EventApp\Support;

/**
 * Deja absoluta una URL que puede venir relativa.
 *
 * Existe por una diferencia que no se ve desde el navegador: los paneles y el
 * POS piden sus imágenes desde una página que el propio servidor sirvió, así
 * que `/storage/fotos/x.jpg` se completa sola con el origen. La app del
 * asistente no: recibe JSON, guarda la cadena y luego se la da a un widget de
 * imagen que no tiene ninguna página de la que colgar. Una ruta relativa ahí
 * no es una foto rota bonita, es la pantalla de menús del festival entera sin
 * fotos, y solo se descubre en un teléfono.
 *
 * `Storage::disk('public')->url()` ya devuelve absoluta mientras APP_URL esté
 * puesta. Esto es la red por debajo: la configuración del disco es de
 * despliegue, y el día que alguien arranque el servidor sin APP_URL —o la
 * ponga vacía— la URL sale como `/storage/...` sin que nada reviente en los
 * tests ni en el navegador.
 */
final class UrlAlcanzable
{
    public static function desde(?string $url): ?string
    {
        if ($url === null || $url === '') {
            return null;
        }

        if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) {
            return $url;
        }

        return url($url);
    }
}
