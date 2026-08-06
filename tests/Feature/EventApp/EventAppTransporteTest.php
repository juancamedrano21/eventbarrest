<?php

declare(strict_types=1);

use App\Domains\EventApp\Correo\TransporteDeCodigos;
use App\Domains\EventApp\Correo\TransporteDeCodigosAlLog;
use App\Domains\EventApp\Correo\TransporteDeCodigosSinProveedor;

/**
 * La puerta de entorno del transporte de códigos. La promesa de que
 * producción no escribe el OTP en claro en el log no puede vivir en un
 * comentario: tiene que ser el binding quien la sostenga, y esto es lo que
 * se prueba — el mismo contenedor elige el log fuera de producción y el
 * transporte que falla ruidoso dentro.
 */
it('binds the log transport outside production and the loud-failing one in production', function (): void {
    // Fuera de producción (testing aquí, local en la máquina): el log.
    expect($this->app->make(TransporteDeCodigos::class))
        ->toBeInstanceOf(TransporteDeCodigosAlLog::class);

    // En producción, SIN proveedor real configurado, el binding no puede
    // ser el log. Se cambia el entorno y se vuelve a resolver: la decisión
    // es del binding AL RESOLVER, no de un comentario.
    $entorno = $this->app['env'];
    $this->app['env'] = 'production';

    try {
        expect($this->app->make(TransporteDeCodigos::class))
            ->toBeInstanceOf(TransporteDeCodigosSinProveedor::class);
    } finally {
        $this->app['env'] = $entorno;
    }
});

it('fails loud with an operable message instead of delivering when there is no provider', function (): void {
    $sinProveedor = new TransporteDeCodigosSinProveedor;

    // El mensaje es la parte operable: quien despliegue tiene que leer QUÉ
    // falta, no un 500 mudo.
    expect(fn () => $sinProveedor->enviar('ana@ejemplo.com', '123456'))
        ->toThrow(RuntimeException::class, 'No hay proveedor de correo configurado');
});
