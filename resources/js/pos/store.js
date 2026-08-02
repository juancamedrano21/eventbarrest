import { defineStore } from 'pinia';
import { api, setToken, hasToken } from './api';
import { db, kvGet, kvSet } from './db';

// El desglose y la propina espejan el calculo del servidor (redondeo POR
// LINEA incluido): el servidor manda al sincronizar. Los productos exentos
// (itbis_exempt) no aportan; un catalogo cacheado sin el flag cuenta como
// gravado, igual que el default del servidor. El modo lo fija el negocio:
// 'included' extrae el 18 % del precio, 'added' lo suma al cobrar.
function totals(cart, withTip, mode = 'included') {
    const added = mode === 'added';
    let subtotal = 0;
    let itbis = 0;
    for (const line of cart) {
        const total = Math.round(line.price_cents * line.quantity);
        subtotal += total;
        if (!line.itbis_exempt) {
            itbis += added ? Math.round(total * 0.18) : Math.round((total * 18) / 118);
        }
    }
    // La propina legal siempre sobre la base sin impuesto.
    const base = added ? subtotal : subtotal - itbis;
    const tip = withTip ? Math.round(base * 0.1) : 0;
    return { subtotal, itbis, tip, total: (added ? subtotal + itbis : subtotal) + tip };
}

export const usePos = defineStore('pos', {
    state: () => ({
        screen: hasToken() ? 'till' : 'login',
        user: null,
        units: [],
        session: null,
        categories: [],
        products: [],
        itbisMode: 'included',
        cart: [],
        withTip: false,
        pending: 0,
        errored: 0,
        reviewing: false,
        reviewRows: [],
        online: navigator.onLine,
        closing: null,
        closingTill: false,
        error: null,
        busy: false,
        syncing: null,
    }),

    getters: {
        totals: (state) => totals(state.cart, state.withTip, state.itbisMode),
    },

    actions: {
        fail(error) {
            this.error = error?.message ?? 'Algo salio mal.';
            if (error?.status === 401) {
                setToken(null);
                this.screen = 'login';
            }
        },

        async recount() {
            // 'sin_caja' cuenta como pendiente: espera una caja nueva.
            this.pending = await db.outbox.where('status').anyOf('pendiente', 'sin_caja').count();
            this.errored = await db.outbox.where('status').equals('error').count();
        },

        async login(username, password) {
            // Guard interno ademas del disabled del boton: el doble Enter
            // no depende de un re-render de Vue.
            if (this.busy) return;
            this.busy = true;
            this.error = null;
            try {
                let device = await kvGet('device');
                if (!device) {
                    device = `pos-${crypto.randomUUID().slice(0, 8)}`;
                    await kvSet('device', device);
                }
                const data = await api.login(username, password, device);
                setToken(data.token);
                this.user = data.user;
                await kvSet('user', data.user);
                await this.arrive();
            } catch (error) {
                this.fail(error);
            } finally {
                this.busy = false;
            }
        },

        // Al entrar (login, recarga o vuelta de senal): estado del servidor si
        // hay red, lo cacheado si no. Vender nunca depende de la red.
        async arrive() {
            // El cajero sobrevive al reload: el chip de la barra no espera
            // al proximo login.
            if (this.user === null) {
                this.user = await kvGet('user');
            }

            try {
                const boot = await api.bootstrap();
                const catalog = await api.catalog();
                this.units = boot.units;
                this.categories = catalog.categories;
                this.products = catalog.products;
                this.itbisMode = catalog.settings?.itbis_mode ?? 'included';
                const saved = await kvGet('my_session_id');
                const sessions = boot.open_sessions ?? [];
                this.session = sessions.find((s) => s.id === saved)
                    ?? (sessions.length === 1 ? sessions[0] : null);
                await kvSet('cache', { units: boot.units, session: this.session, catalog });
            } catch (error) {
                if (error?.status === 401 || error?.status === 403) {
                    return this.fail(error);
                }
                const cache = await kvGet('cache');
                if (cache?.units) this.units = cache.units;
                if (cache?.catalog) {
                    this.categories = cache.catalog.categories;
                    this.products = cache.catalog.products;
                    this.itbisMode = cache.catalog.settings?.itbis_mode ?? 'included';
                }
                if (cache?.session) this.session = cache.session;
                if (!cache) {
                    this.error = 'Sin conexion y sin datos guardados: conecta el dispositivo e intenta de nuevo.';
                }
            } finally {
                const draft = await kvGet('draft');
                if (draft && this.cart.length === 0) {
                    this.cart = draft.cart;
                    this.withTip = draft.withTip;
                }
                await this.recount();
                this.screen = this.session ? 'sale' : 'till';
                this.syncOutbox();
            }
        },

        async openTill(unitId, openingCents) {
            if (this.busy) return;
            this.busy = true;
            this.error = null;
            try {
                this.session = await api.openSession(unitId, openingCents);
                await kvSet('my_session_id', this.session.id);

                // Inicio de turno: la unica llamada online garantizada del
                // dia. Se revalida el catalogo y con el la REGLA FISCAL —
                // si cambio, cobrar con la vieja seria cobrar de menos.
                try {
                    const catalog = await api.catalog();
                    this.categories = catalog.categories;
                    this.products = catalog.products;
                    this.itbisMode = catalog.settings?.itbis_mode ?? 'included';
                    await kvSet('cache', { ...(await kvGet('cache', {})), session: this.session, catalog });
                } catch {
                    await kvSet('cache', { ...(await kvGet('cache', {})), session: this.session });
                }

                // Ventas huerfanas de una caja anterior: renacen en esta.
                const parked = await db.outbox.where('status').equals('sin_caja').toArray();
                for (const sale of parked) {
                    await db.outbox.update(sale.id, {
                        status: 'pendiente',
                        cash_session_id: this.session.id,
                        client_ref: crypto.randomUUID(),
                    });
                }
                await this.recount();
                this.screen = 'sale';
                this.syncOutbox();
            } catch (error) {
                this.fail(error);
            } finally {
                this.busy = false;
            }
        },

        async saveDraft() {
            // kvSet ya desenvuelve los proxies reactivos: un solo punto.
            await kvSet('draft', { cart: this.cart, withTip: this.withTip });
        },

        addToCart(product) {
            const line = this.cart.find((item) => item.product_id === product.id);
            if (line) {
                line.quantity += 1;
            } else {
                this.cart.push({ product_id: product.id, name: product.name, price_cents: product.price_cents, itbis_exempt: !!product.itbis_exempt, quantity: 1 });
            }
            this.saveDraft();
        },

        removeFromCart(productId) {
            const index = this.cart.findIndex((item) => item.product_id === productId);
            if (index === -1) return;
            if (this.cart[index].quantity > 1) {
                this.cart[index].quantity -= 1;
            } else {
                this.cart.splice(index, 1);
            }
            this.saveDraft();
        },

        // La venta se COBRA aqui, en el dispositivo: va a la bandeja local y
        // se sincroniza cuando haya senal. La referencia es un UUID: unica
        // aunque haya dos pestanas o se pierda el almacenamiento.
        async charge(method, tenderedCents) {
            if (this.closingTill || !this.session) {
                this.error = 'La caja se esta cerrando: no se puede cobrar.';
                return;
            }
            const sale = {
                client_ref: crypto.randomUUID(),
                cash_session_id: this.session.id,
                with_tip: this.withTip,
                lines: this.cart.map((line) => ({ product_id: line.product_id, quantity: line.quantity })),
                payment: { method, tendered_cents: tenderedCents },
                display: { ...this.totals, method },
                status: 'pendiente',
                created_at: Date.now(),
            };
            // Mismo saneo que kvSet: hoy la venta es toda primitivos, pero
            // el dia que una linea gane un campo anidado copiado por
            // referencia, seria un proxy reactivo y IndexedDB no los clona.
            await db.outbox.add(JSON.parse(JSON.stringify(sale)));
            this.cart = [];
            this.withTip = false;
            await kvSet('draft', null);
            await this.recount();
            this.syncOutbox();
        },

        // Un solo vuelo a la vez: intervalo, reconexion, post-venta y cierre
        // comparten la MISMA corrida en curso. Se decide por status y codigo:
        // sin red sigue pendiente; 5xx/429 transitorio; 4xx definitivo va a
        // revision; caja cerrada se reasigna a la caja abierta o se aparca.
        syncOutbox() {
            if (this.syncing) return this.syncing;
            this.syncing = this.runSync().finally(() => { this.syncing = null; });
            return this.syncing;
        },

        async runSync() {
            if (!navigator.onLine) return;
            const pending = await db.outbox.where('status').equals('pendiente').toArray();
            for (const sale of pending) {
                try {
                    const result = await api.syncOrder({
                        cash_session_id: sale.cash_session_id,
                        client_ref: sale.client_ref,
                        with_tip: sale.with_tip,
                        lines: sale.lines,
                        payment: sale.payment,
                    });
                    // El total que el cliente pago debe ser el que el
                    // servidor registro: si el dispositivo tenia una regla
                    // fiscal vieja, la venta va a revision en vez de pasar
                    // como buena con un faltante silencioso en la caja.
                    const cobrado = sale.display?.total;
                    if (typeof cobrado === 'number' && cobrado !== result.total_cents) {
                        await db.outbox.update(sale.id, {
                            status: 'error',
                            error_code: 'total_divergente',
                            error_message: `Se cobro ${(cobrado / 100).toFixed(2)} y el sistema registro `
                                + `${(result.total_cents / 100).toFixed(2)}: revisa con tu encargado.`,
                            server: result,
                        });
                        // La regla fiscal del dispositivo quedo vieja: la del
                        // servidor manda desde ya.
                        if (result.itbis_mode) this.itbisMode = result.itbis_mode;
                        continue;
                    }
                    await db.outbox.update(sale.id, { status: 'sincronizada', server: result });
                } catch (error) {
                    if (!error?.status) {
                        break; // sin red: todo lo demas puede esperar
                    }
                    if (error.status === 401 || error.status === 403) {
                        this.fail(error);
                        break;
                    }
                    if (error.code === 'session_not_open') {
                        if (this.session && this.session.id !== sale.cash_session_id) {
                            // La caja original cerro: la venta renace en la abierta.
                            await db.outbox.update(sale.id, {
                                cash_session_id: this.session.id,
                                client_ref: crypto.randomUUID(),
                            });
                        } else {
                            await db.outbox.update(sale.id, { status: 'sin_caja', error_message: 'Su caja cerro: abre una caja para reenviarla.' });
                        }
                        continue;
                    }
                    if (error.status === 429 || error.status >= 500) {
                        continue; // transitorio: reintento en la proxima corrida
                    }
                    await db.outbox.update(sale.id, {
                        status: 'error',
                        error_code: error.code ?? `http_${error.status}`,
                        error_message: error.message,
                    });
                }
            }
            await this.recount();
        },

        async openReview() {
            const abiertas = await db.outbox.where('status').anyOf('error', 'sin_caja', 'pendiente').toArray();

            // Y las ultimas ya sincronizadas: es donde el cajero encuentra
            // el NUMERO de la venta para decirselo al cliente (P0041). Sin
            // esto, la bandeja solo hablaria en UUID.
            const recientes = (await db.outbox.where('status').equals('sincronizada').toArray())
                .sort((a, b) => b.created_at - a.created_at)
                .slice(0, 10);

            this.reviewRows = [...abiertas, ...recientes];
            this.reviewing = true;
        },

        async retryRow(id) {
            await db.outbox.update(id, { status: 'pendiente', error_code: null, error_message: null });
            await this.recount();
            this.reviewing = false;
            this.syncOutbox();
        },

        async discardRow(id) {
            // Descartar es decision del supervisor: la venta cobrada al
            // cliente se saca de la bandeja a sabiendas.
            await db.outbox.update(id, { status: 'descartada' });
            await this.recount();
            this.reviewing = false;
        },

        async closeTill(countedCents) {
            this.busy = true;
            this.closingTill = true;
            this.error = null;
            try {
                await this.syncOutbox();
                if (this.pending > 0 || this.errored > 0) {
                    this.error = this.errored > 0
                        ? `Hay ${this.errored} venta(s) en revision: resuelvelas antes de cerrar.`
                        : `Hay ${this.pending} venta(s) sin sincronizar: conecta el dispositivo antes de cerrar.`;
                    return;
                }
                this.closing = await api.closeSession(this.session.id, countedCents);
                this.session = null;
                await kvSet('my_session_id', null);
                await kvSet('cache', { ...(await kvGet('cache', {})), session: null });
                this.screen = 'till';
            } catch (error) {
                this.fail(error);
            } finally {
                this.closingTill = false;
                this.busy = false;
            }
        },

        async logout() {
            await this.syncOutbox();
            if (this.pending > 0 || this.errored > 0) {
                this.error = 'Hay ventas sin sincronizar o en revision: no se puede salir todavia.';
                return;
            }
            try { await api.logout(); } catch { /* el token muere igual */ }
            setToken(null);
            await kvSet('cache', null);
            await kvSet('draft', null);
            await kvSet('user', null);
            this.user = null;
            this.session = null;
            this.screen = 'login';
        },
    },
});
