<?php

declare(strict_types=1);

namespace App\Http\Controllers\EventPanel;

use App\Domains\EventManagement\Models\EventOutlet;
use App\Domains\EventManagement\Models\Vendor;
use App\Domains\Identity\Enums\Permission;
use App\Domains\Kitchen\Actions\IssueVendorKdsCode;
use App\Domains\Kitchen\Actions\RevokeKdsDevice;
use App\Domains\Kitchen\Actions\RotateOutletKdsPin;
use App\Domains\Kitchen\Actions\UnlockOutletKdsPin;
use App\Domains\Kitchen\Models\KdsDevice;
use App\Http\Controllers\Controller;
use App\Http\Controllers\EventPanel\Concerns\AuthorizesOrganizerPanel;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Lo único que el organizador toca del KDS: el código que se dicta por
 * teléfono, el PIN que se teclea una vez y la lista de tabletas colgadas.
 *
 * Vive aparte de VendorProfileController porque las tres capacidades que
 * gobierna son de tres permisos distintos —comercios, puestos y
 * dispositivos— y meterlas en el controlador del perfil habría escondido
 * esa frontera dentro de un archivo que ya nadie lee entero.
 *
 * Los tres verbos NO son intercambiables y por eso son tres botones:
 * regenerar el código y rotar el PIN cierran las altas FUTURAS, revocar
 * mata las sesiones VIVAS. Ninguno hace lo del otro. El botón rojo de abajo
 * existe porque el caso real —«se perdió una tablet y no sé cuál era»—
 * necesita los dos a la vez y en un solo clic.
 */
class VendorKdsController extends Controller
{
    use AuthorizesOrganizerPanel;

    /**
     * Emite un código nuevo para el comercio. El viejo deja de servir en el
     * acto para dar de alta tabletas, pero NO apaga ninguna de las que ya
     * están colgadas: cada una vive de su token desde el momento en que
     * entró. Quien quiera apagarlas tiene el botón rojo.
     */
    public function regenerateCode(Request $request, int $vendor): RedirectResponse
    {
        $this->authorizeOrganizer($request, Permission::VendorsManage);

        $record = Vendor::query()->findOrFail($vendor);

        // IssueVendorKdsCode es idempotente a conciencia —si el comercio ya
        // tiene código devuelve el suyo, para no dejar inservible el papel
        // pegado en el puesto—, así que regenerar es exactamente vaciar el
        // campo antes de pedírselo. La diferencia es de intención: «asegúrate
        // de que tiene código» frente a «este código lo vio quien no debía».
        $record->setAttribute('kds_code', null);

        $codigo = app(IssueVendorKdsCode::class)($record);

        return back()->with(
            'status',
            "Código nuevo: {$codigo}. El anterior ya no da de alta tabletas; las que están colgadas siguen funcionando.",
        );
    }

    /**
     * PIN nuevo para un puesto. Se devuelve en claro UNA vez, en el flash de
     * la redirección: en la base solo queda su hash y ni nosotros podemos
     * leerlo después.
     */
    public function rotatePin(Request $request, int $vendor, int $outlet): RedirectResponse
    {
        $this->authorizeOrganizer($request, Permission::EventOutletsManage);

        $record = $this->puestoDelComercio($vendor, $outlet);

        $pin = app(RotateOutletKdsPin::class)($record);

        return back()
            ->with('kdsPins', [$record->id => $pin])
            ->with('status', 'PIN nuevo para '.$record->name.'. Las tabletas ya enroladas siguen entrando; el PIN solo abre la puerta a la siguiente.');
    }

    /**
     * Apaga a mano el aviso de «alguien está probando PIN contra este código».
     *
     * Ya no rescata a nadie, y por eso el panel no ofrece botón: la racha no
     * cierra ninguna puerta —el PIN correcto cuelga tabletas igual mientras
     * dura— y caduca sola. Antes sí hacía falta, cuando la racha bloqueaba los
     * puestos y cualquiera que leyera el código impreso en la pared podía
     * dejar una cocina sin colgar tabletas el día del montaje; eso ya no
     * ocurre.
     *
     * La ruta se conserva porque apagar un aviso que se sabe falso —el
     * organizador que acaba de ver a alguien tecleando en la tablet— es
     * razonable, y porque quitarla es romper una URL. Recibe un puesto por
     * compatibilidad con esa URL, pero lo que limpia es la racha del COMERCIO:
     * una fila, no una de treinta.
     */
    public function unlockPin(Request $request, int $vendor, int $outlet): RedirectResponse
    {
        $this->authorizeOrganizer($request, Permission::EventOutletsManage);

        $record = $this->puestoDelComercio($vendor, $outlet);

        app(UnlockOutletKdsPin::class)($record);

        return back()->with('status', 'Aviso apagado. Nada había cambiado para las tabletas: el PIN sigue siendo el mismo y seguía funcionando.');
    }

    /** Apagar una tablet concreta: la de la ventanilla que se mojó. */
    public function revokeDevice(Request $request, int $vendor, int $device): RedirectResponse
    {
        $this->authorizeOrganizer($request, Permission::PosDevicesManage);

        // El comercio se comprueba en la misma consulta que el dispositivo:
        // VendorScope falla ABIERTO, así que sin este where la tablet de otro
        // comercio de la misma cuenta sería revocable desde esta URL.
        $record = KdsDevice::query()
            ->where('vendor_id', $vendor)
            ->findOrFail($device);

        app(RevokeKdsDevice::class)($record);

        return back()->with('status', $record->name.' queda fuera. Su rastro en las comandas se conserva.');
    }

    /**
     * El martillo. Se usa cuando falta una tablet y nadie sabe cuál era: se
     * apagan todas y se cambian los PIN, y luego se vuelven a colgar las que
     * sí están. Rotar sin revocar dejaría viva la perdida; revocar sin rotar
     * dejaría que quien la tenga la enrole otra vez con el PIN que ya vio.
     */
    public function revokeAll(Request $request, int $vendor): RedirectResponse
    {
        $this->authorizeOrganizer($request, Permission::PosDevicesManage);

        $record = Vendor::query()->findOrFail($vendor);

        /** @var array{0: int, 1: array<int, string>} $resultado */
        $resultado = DB::transaction(function () use ($record): array {
            $tabletas = KdsDevice::query()
                ->where('vendor_id', $record->id)
                ->whereNull('revoked_at')
                ->get();

            foreach ($tabletas as $tableta) {
                app(RevokeKdsDevice::class)($tableta);
            }

            // Solo se rotan los puestos que YA tenían PIN. Ponerle uno a un
            // puesto que nunca colgó nada no cierra ninguna puerta y le
            // regala al organizador seis dígitos que no pidió.
            $pines = [];

            $puestos = EventOutlet::query()
                ->where('vendor_id', $record->id)
                ->whereNotNull('kds_pin_hash')
                ->orderBy('name')
                ->get();

            foreach ($puestos as $puesto) {
                $pines[$puesto->id] = app(RotateOutletKdsPin::class)($puesto);
            }

            return [$tabletas->count(), $pines];
        });

        [$apagadas, $pines] = $resultado;

        return back()
            ->with('kdsPins', $pines)
            ->with('status', sprintf(
                '%d tableta(s) fuera y %d PIN nuevo(s). Hay que volver a enrolar una por una las que sigan en pie.',
                $apagadas,
                count($pines),
            ));
    }

    /**
     * Un puesto de ESTE comercio. Las dos fronteras van en la misma consulta
     * a propósito: pedir el PIN del puesto del comercio vecino tiene que ser
     * indistinguible de pedir uno que no existe.
     */
    private function puestoDelComercio(int $vendor, int $outlet): EventOutlet
    {
        return EventOutlet::query()
            ->where('vendor_id', $vendor)
            ->findOrFail($outlet);
    }
}
