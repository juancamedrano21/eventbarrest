// Preline 4 exporta las clases y cuelga HSStaticMethods de window, pero NO
// inicializa nada por su cuenta: sin este autoInit, tabs, modales y menús
// son HTML muerto. (En /panel lo hace el bundle del tema Pro; esto es para
// los layouts propios — /comercio y el fallback del panel.)
import { HSOverlay, HSStaticMethods, HSTabs } from 'preline';

// El script que reabre el modal del ítem tras un error de validación los
// usa por window, igual que en el layout del tema.
window.HSOverlay = HSOverlay;
window.HSTabs = HSTabs;

const init = () => HSStaticMethods.autoInit();

// Los módulos corren tras el parseo y antes de DOMContentLoaded, pero el
// guard cubre cualquier orden de carga.
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
} else {
    init();
}
