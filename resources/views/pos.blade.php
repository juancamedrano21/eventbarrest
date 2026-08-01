<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#0f172a">
    <title>POS — EventBarRest</title>
    <link rel="manifest" href="/pos/manifest.webmanifest">
    @vite(['resources/js/pos/main.js'])
</head>
<body>
    <div id="pos"></div>
    <script>
        if ('serviceWorker' in navigator) {
            window.addEventListener('load', () => navigator.serviceWorker.register('/pos-sw.js'));
        }
    </script>
</body>
</html>
