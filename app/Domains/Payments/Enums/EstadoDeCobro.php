<?php

declare(strict_types=1);

namespace App\Domains\Payments\Enums;

/**
 * El `status` que devuelve Cybersource en el cuerpo de `/pts/v2/payments`.
 *
 * ─────────────────────────────────────────────────────────────────────────
 * REGLA DURA: `body.status` ES EL ÚNICO ÁRBITRO.
 * ─────────────────────────────────────────────────────────────────────────
 *
 * No se mira `processorInformation.responseCode` ni `approvalCode` para
 * decidir si se cobró. Puede llegar `responseCode: "00"` con un código de
 * aprobación perfectamente válido del emisor y, aun así,
 * `status: AUTHORIZED_RISK_DECLINED` — el banco aprobó pero Decision Manager
 * rechazó, y la transacción se anula. Leer el código en vez del estado es
 * despachar comida que nadie pagó, y en un festival eso no se recupera.
 *
 * De ahí que la clasificación viva EN EL ENUM y no en un `if` del que llama:
 * cada sitio que decida por su cuenta es un sitio donde se puede volver a
 * cometer el mismo error.
 */
enum EstadoDeCobro: string
{
    case Autorizado = 'AUTHORIZED';

    case AutorizadoParcial = 'PARTIAL_AUTHORIZED';

    case AutorizadoEnRevision = 'AUTHORIZED_PENDING_REVIEW';

    case PendienteDeAutenticacion = 'PENDING_AUTHENTICATION';

    case EnRevision = 'PENDING_REVIEW';

    case Rechazado = 'DECLINED';

    case RechazadoPorRiesgo = 'AUTHORIZED_RISK_DECLINED';

    case PeticionInvalida = 'INVALID_REQUEST';

    /**
     * Cualquier estado que Cybersource añada mañana, o una respuesta sin
     * `status`. Cae en el cubo que NO despacha: un estado desconocido jamás
     * puede heredar el beneficio de la duda de un cobro aprobado.
     */
    case Desconocido = 'DESCONOCIDO';

    /**
     * NO es un estado de Cybersource: es el nuestro para «la llamada no llegó
     * a ser una respuesta» (timeout, DNS, conexión cortada, 5xx sin cuerpo).
     *
     * Existe porque `Desconocido` significa «contestaron algo que no entiendo»
     * y esto significa «no contestaron», que en pagos es lo contrario: en el
     * primer caso sabemos que hubo una decisión, en este puede haber un cobro
     * hecho y perdido. Solo lo pone `ResultadoDeCobro::sinRespuesta()`; nunca
     * sale de `desde()`.
     */
    case SinRespuesta = 'SIN_RESPUESTA';

    public static function desde(mixed $status): self
    {
        if (! is_string($status)) {
            return self::Desconocido;
        }

        $estado = self::tryFrom($status) ?? self::Desconocido;

        // `SIN_RESPUESTA` es un sentinel de la casa, no un status del API. Si
        // llegara literalmente en un cuerpo sería un estado ajeno que no
        // conocemos —o sea `Desconocido`—, jamás la certeza de que no hubo
        // respuesta: eso solo lo sabe quien hizo la llamada.
        return $estado === self::SinRespuesta ? self::Desconocido : $estado;
    }

    public function desenlace(): DesenlaceDeCobro
    {
        return match ($this) {
            // Lo único que significa «hay dinero cobrado».
            self::Autorizado => DesenlaceDeCobro::Aprobado,

            // Aprobados con condiciones: NO son despacho.
            // - Parcial: el emisor autorizó MENOS de lo pedido; falta dinero,
            //   y hay que decidir entre devolver o cobrar la diferencia.
            // - En revisión / pendiente de autenticación: la decisión final
            //   todavía no existe y puede caer para cualquier lado.
            self::AutorizadoParcial,
            self::AutorizadoEnRevision,
            self::PendienteDeAutenticacion,
            self::EnRevision => DesenlaceDeCobro::Pendiente,

            // Rechazos. RechazadoPorRiesgo es el que engaña: viene con
            // responseCode "00" y código de aprobación del emisor.
            self::Rechazado,
            self::RechazadoPorRiesgo => DesenlaceDeCobro::Rechazado,

            // No hubo respuesta: el dinero puede haberse movido. Es el único
            // desenlace que prohíbe reintentar sin conciliar antes.
            self::SinRespuesta => DesenlaceDeCobro::Incierto,

            // Ni siquiera llegó a ser una decisión de pago.
            self::PeticionInvalida,
            self::Desconocido => DesenlaceDeCobro::Error,
        };
    }

    /** El único predicado que autoriza a despachar. */
    public function esAprobado(): bool
    {
        return $this->desenlace() === DesenlaceDeCobro::Aprobado;
    }

    public function esPendiente(): bool
    {
        return $this->desenlace() === DesenlaceDeCobro::Pendiente;
    }

    public function esRechazado(): bool
    {
        return $this->desenlace() === DesenlaceDeCobro::Rechazado;
    }

    /** ¿Puede haberse cobrado sin que lo sepamos? Si sí, no se reintenta a ciegas. */
    public function esIncierto(): bool
    {
        return $this->desenlace() === DesenlaceDeCobro::Incierto;
    }
}
