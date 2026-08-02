{{-- La pestaña sobrevive al guardado.

     Guardar cualquier formulario del perfil termina en un back(), o sea
     una recarga: sin esto, quien editaba el menú volvía siempre al
     Resumen y tenía que buscar dónde estaba. El fragmento de la URL no
     viaja al servidor —el back() lo pierde—, así que se recuerda por
     pantalla en sessionStorage y el hash queda para compartir el enlace.

     Vive aquí y no en panel.js porque /panel usa el layout del tema, que
     carga su propio bundle. --}}
<script>
    (function () {
        var clave = 'panel:tab:' + window.location.pathname;

        function recordar(id) {
            try { sessionStorage.setItem(clave, id); } catch (e) { /* modo privado */ }
            window.history.replaceState(null, '', '#' + id);
        }

        function restaurar() {
            var destino = window.location.hash.replace(/^#/, '');

            if (destino === '') {
                try { destino = sessionStorage.getItem(clave) || ''; } catch (e) { destino = ''; }
            }

            if (destino === '' || !/^[\w-]+$/.test(destino)) return;

            var boton = document.querySelector('[data-hs-tab="#tab-' + destino + '"]');
            // El click hace justo lo que Preline espera (activa el panel,
            // marca el botón, dispara sus eventos) sin depender de su API.
            if (boton) boton.click();
        }

        document.addEventListener('click', function (evento) {
            var boton = evento.target.closest('[data-hs-tab]');
            if (!boton) return;
            var id = (boton.getAttribute('data-hs-tab') || '').replace('#tab-', '');
            if (id !== '') recordar(id);
        });

        // Tras el autoInit de Preline (que en el tema corre en load).
        window.addEventListener('load', function () { setTimeout(restaurar, 60); });
    })();
</script>
