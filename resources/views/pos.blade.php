<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#090e1a">
    <title>{{ $titulo }} — EventBarRest</title>
    <link rel="manifest" href="{{ $manifest }}">
    @vite(['resources/js/pos/main.js'])
</head>
<body>
    <div id="pos" data-modalidad="{{ $modalidad }}"></div>
    <script>
        if ('serviceWorker' in navigator) {
            window.addEventListener('load', () => navigator.serviceWorker.register('/pos-sw.js'));
        }
    </script>
</body>
</html>
