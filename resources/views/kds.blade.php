<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#090e1a">
    <title>{{ $titulo }} — EventBarRest</title>
    <link rel="manifest" href="/event-kds-manifest.webmanifest">
    {{-- El tema se fija ANTES del primer pintado. El POS lo aplica en un
         watchEffect posterior al montaje y hace un destello blanco en cada
         arranque; delante de una plancha, a oscuras, eso deslumbra. --}}
    <script>document.documentElement.dataset.theme = 'dark';</script>
    @vite(['resources/js/kds/main.js'])
</head>
<body>
    {{-- Sin service worker, a diferencia del POS. El suyo se registra sin
         opción de scope, así que controla TODO el origen: un segundo
         competiría por la misma registración y ambos se desregistrarían,
         rompiendo de forma intermitente el arranque sin señal del POS — la
         pieza más delicada del sistema. Y el KDS no gana nada offline: su
         trabajo es enseñar lo que el servidor sabe. La contrapartida honesta
         es que Chrome no ofrecerá «instalar»: esto es una página a pantalla
         completa, no una PWA instalable. --}}
    <div id="kds"></div>
</body>
</html>
