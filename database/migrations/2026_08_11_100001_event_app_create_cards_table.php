<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Las tarjetas guardadas del asistente: cuelgan de la CUENTA, no del evento.
 *
 * SIN tenant_id, igual que las tres tablas de la cuenta y por el mismo
 * motivo: la cuenta del asistente es de PLATAFORMA —quien guardó su tarjeta
 * en Bocao la tiene en el próximo festival, con otro organizador— y un
 * tenant_id la partiría en una tarjeta por organizador. Está registrada como
 * excepción en SchemaConventionTest y su modelo en ModelConventionTest, con
 * este mismo argumento. ADR-011.
 *
 * ─────────────────────────────────────────────────────────────────────────
 * AQUÍ NO HAY PAN NI CVV. NUNCA. NI CIFRADOS.
 * ─────────────────────────────────────────────────────────────────────────
 * Lo que se guarda son IDS DE TOKEN de Cybersource —con los que se cobra pero
 * que no son un número de tarjeta— más lo justo para que el asistente
 * reconozca la suya: marca, cuatro dígitos y vencimiento. El PAN vive en la
 * bóveda de Cybersource y no toca este servidor (alcance SAQ A, doc 12 §1);
 * un PAN aquí metería a la plataforma entera en SAQ D, que es exactamente lo
 * que Boletu hace y lo que la doc 12 §0.3 marca como «no copiar».
 *
 * Y tampoco se guarda el cuerpo de la respuesta de Cybersource. Boletu
 * persiste `gateway_response` entero, con el token dentro, y lo esconde en
 * `$hidden` del modelo: cualquiera que lea la tabla ve la credencial de
 * cobro. Aquí cada token va a SU columna y del resto no queda nada.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_app_cards', function (Blueprint $table): void {
            $table->id();

            // cascadeOnDelete como las sesiones: borrar la cuenta es el único
            // borrado real de la plataforma y no puede dejar filas huérfanas.
            // OJO: la cascada de la base NO sustituye al borrado en la
            // bóveda. Una fila que desaparece con el token vivo es una
            // tarjeta que el asistente cree haber quitado y sigue siendo
            // cobrable, así que quien borra la cuenta va PRIMERO a
            // Cybersource. La foreign key es la red por debajo, no el plan.
            $table->foreignId('event_app_account_id')
                ->constrained('event_app_accounts')
                ->cascadeOnDelete();

            // Las dos piezas de la credencial. El `customer` agrupa las
            // tarjetas del asistente en la bóveda; el `paymentInstrument` es
            // la tarjeta concreta, y por sí solo ya sirve para cobrar en
            // /pts/v2/payments — de ahí que ninguno de los dos salga entero a
            // ningún log.
            $table->string('customer_token_id', 64);
            $table->string('payment_instrument_id', 64);

            // El identificador del NÚMERO de tarjeta, estable entre
            // instrumentos: es lo que permitirá mañana saber que dos altas
            // son la misma tarjeta física. Anulable porque es un dato de la
            // bóveda que puede no volver, y perderlo no rompe nada hoy.
            $table->string('instrument_identifier_id', 64)->nullable();

            // ÚNICO GLOBAL, y no compuesto con la cuenta: un payment
            // instrument es una credencial de cobro concreta de la bóveda y
            // no puede estar en dos cuentas a la vez. El único global es
            // ESTRICTAMENTE más restrictivo que el compuesto —hace la
            // colisión imposible en vez de solo detectable dentro de una
            // cuenta—, que es justo lo que hace falta cuando lo que colisiona
            // es la llave con la que se le cobra a alguien.
            $table->unique('payment_instrument_id', 'event_app_cards_payment_instrument_unique');

            // Por él se agrupan las tarjetas al borrar la cuenta: primero
            // cada instrumento, después cada cliente distinto.
            $table->index('customer_token_id', 'event_app_cards_customer_index');

            // La marca en el vocabulario público (`visa`, `mastercard`, …).
            // NO se admite null y NO hay defecto de conveniencia: lo que no
            // reconocemos entra como `desconocida`, que es un valor con
            // significado. Ver MarcaDeTarjeta para por qué caer a `visa`
            // —lo que hace Boletu— sería un fallo silencioso aquí.
            $table->string('brand', 20);

            // Los cuatro dígitos que la app enseña. Anulables porque salen de
            // un número ya enmascarado por Cybersource y hay emisores que
            // tapan también el final: mejor sin dígitos que con «XXXX»
            // pintado como si fueran los de la tarjeta.
            $table->char('last4', 4)->nullable();

            // Anulables por lo mismo: si la bóveda no los devuelve, la app
            // pinta la tarjeta sin vencimiento en vez de con uno inventado.
            $table->unsignedTinyInteger('exp_month')->nullable();
            $table->unsignedSmallInteger('exp_year')->nullable();

            // Cuál se usa cuando el asistente no elige. La invariante «como
            // mucho una por cuenta» la sostiene la acción dentro de una
            // transacción y no un índice: un único parcial no existe en
            // MySQL, y uno compuesto (cuenta, is_default) prohibiría tener
            // dos tarjetas NO marcadas, que es el caso normal.
            $table->boolean('is_default')->default(false);

            // ── El cobro de verificación, y si ya se devolvió ────────────
            // Guardar una tarjeta obliga a cobrar algo (Cybersource tokeniza
            // DENTRO de una autorización), y ese peso se anula en el acto. La
            // anulación puede fallar —transacción ya liquidada al cruzar el
            // corte del día, TMS caído, corte de red— y entonces el asistente
            // se queda con un cargo real que hay que devolverle.
            //
            // Estas tres columnas existen porque el único rastro era un
            // `Log::warning` con una referencia que se generaba dentro de la
            // acción y moría ahí: recuperar un cargo atascado exigía grepear
            // ficheros de log, y `BuscarCobroPorReferencia` —que ya existe—
            // no tenía por dónde empezar. Con esto, «qué cobros quedan por
            // devolver» es una consulta (`EventAppCard::pendientesDeAnular()`)
            // y no una arqueología.
            $table->string('verification_reference', 40);
            $table->string('verification_transaction_id', 64)->nullable();

            // NULL = pendiente de anular. Se llena SOLO cuando Cybersource
            // contestó VOIDED: un cobro que no se sabe si se devolvió cuenta
            // como no devuelto, igual que en el resto del dominio de pagos.
            $table->timestamp('verification_voided_at')->nullable();

            // El caso normal es que esté puesta, así que el índice sirve para
            // lo raro, que es justo lo que hay que poder encontrar barato.
            $table->index('verification_voided_at', 'event_app_cards_verification_pending_index');

            // ── El consentimiento ────────────────────────────────────────
            // Guardar la tarjeta de alguien exige que lo haya dicho, y decirlo
            // no basta: hay que poder demostrar CUÁNDO, ante QUÉ TEXTO y
            // desde dónde. Es el mismo patrón que Boletu usa en cuotas.
            $table->timestamp('consent_at');

            // La versión del texto que se le enseñó. Sin ella, el día que el
            // texto cambie no habría forma de saber a qué consintió quien
            // consintió antes — y un consentimiento sin saber a qué no es un
            // consentimiento.
            $table->string('consent_version', 40);

            // La IP tal como la DECLARA quien llama, no como verificada: con
            // `trustProxies(at: '*')` la escribe el propio cliente en una
            // cabecera. Se guarda igual porque en el caso normal es cierta y
            // ayuda a reconstruir un alta, pero no es prueba de nada por sí
            // sola. 45 caracteres para que quepa una IPv6.
            $table->string('consent_ip', 45)->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_app_cards');
    }
};
