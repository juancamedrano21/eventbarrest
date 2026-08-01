import { defineStore } from 'pinia';
import { api, setToken, hasToken } from './api';
import { db, kvGet, kvSet } from './db';

// El precio ya incluye el ITBIS; el desglose y la propina espejan el calculo
// del servidor, que es la fuente de la verdad al sincronizar.
function totals(cart, withTip) {
    const subtotal = cart.reduce((sum, line) => sum + line.price_cents * line.quantity, 0);
    const itbis = Math.round((subtotal * 18) / 118);
    const tip = withTip ? Math.round((subtotal - itbis) * 0.1) : 0;
    return { subtotal, itbis, tip, total: subtotal + tip };
}

export const usePos = defineStore('pos', {
    state: () => ({
        screen: hasToken() ? 'till' : 'login',
        user: null,
        units: [],
        session: null,
        categories: [],
        products: [],
        cart: [],
        withTip: false,
        pending: 0,
        online: navigator.onLine,
        closing: null,
        error: null,
        busy: false,
    }),

    getters: {
        totals: (state) => totals(state.cart, state.withTip),
    },

    actions: {
        fail(error) {
            this.error = error?.message ?? 'Algo salio mal.';
            if (error?.status === 401) {
                setToken(null);
                this.screen = 'login';
            }
        },

        async login(email, password) {
            this.busy = true;
            this.error = null;
            try {
                const device = await kvGet('device', `pos-${crypto.randomUUID().slice(0, 8)}`);
                await kvSet('device', device);
                const data = await api.login(email, password, device);
                setToken(data.token);
                this.user = data.user;
                await this.arrive();
            } catch (error) {
                this.fail(error);
            } finally {
                this.busy = false;
            }
        },

        // Al entrar (login o recarga): estado del servidor si hay senal,
        // lo cacheado si no. Vender nunca depende de la red.
        async arrive() {
            try {
                const boot = await api.bootstrap();
                const catalog = await api.catalog();
                this.units = boot.units;
                this.session = boot.open_sessions[0] ?? null;
                this.categories = catalog.categories;
                this.products = catalog.products;
                await kvSet('cache', { units: boot.units, session: this.session, catalog });
            } catch (error) {
                if (error?.status === 401 || error?.status === 403) return this.fail(error);
                const cache = await kvGet('cache');
                if (cache) {
                    this.units = cache.units;
                    this.session = cache.session;
                    this.categories = cache.catalog.categories;
                    this.products = cache.catalog.products;
                }
            }
            this.pending = await db.outbox.where('status').equals('pendiente').count();
            this.screen = this.session ? 'sale' : 'till';
        },

        async openTill(unitId, openingCents) {
            this.busy = true;
            this.error = null;
            try {
                this.session = await api.openSession(unitId, openingCents);
                await kvSet('cache', { ...(await kvGet('cache', {})), session: this.session });
                this.screen = 'sale';
            } catch (error) {
                this.fail(error);
            } finally {
                this.busy = false;
            }
        },

        addToCart(product) {
            const line = this.cart.find((item) => item.product_id === product.id);
            if (line) {
                line.quantity += 1;
            } else {
                this.cart.push({ product_id: product.id, name: product.name, price_cents: product.price_cents, quantity: 1 });
            }
        },

        removeFromCart(productId) {
            const index = this.cart.findIndex((item) => item.product_id === productId);
            if (index === -1) return;
            if (this.cart[index].quantity > 1) {
                this.cart[index].quantity -= 1;
            } else {
                this.cart.splice(index, 1);
            }
        },

        // La venta se COBRA aqui, en el dispositivo: va a la bandeja local y
        // se sincroniza cuando haya senal. La referencia nace unica.
        async charge(method, tenderedCents) {
            const seq = (await kvGet('seq', 0)) + 1;
            await kvSet('seq', seq);
            const device = await kvGet('device');
            const sale = {
                client_ref: `${device}-${String(seq).padStart(6, '0')}`.slice(0, 40),
                cash_session_id: this.session.id,
                with_tip: this.withTip,
                lines: this.cart.map((line) => ({ product_id: line.product_id, quantity: line.quantity })),
                payment: { method, tendered_cents: tenderedCents },
                display: { ...this.totals, method },
                status: 'pendiente',
                created_at: Date.now(),
            };
            await db.outbox.add(sale);
            this.cart = [];
            this.withTip = false;
            this.pending += 1;
            this.syncOutbox();
        },

        // Reenviar jamas duplica: el servidor es idempotente por referencia.
        // Se decide por CODIGO: solo la falta de red deja la venta pendiente.
        async syncOutbox() {
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
                    await db.outbox.update(sale.id, { status: 'sincronizada', server: result });
                } catch (error) {
                    if (error?.code) {
                        await db.outbox.update(sale.id, { status: 'error', error_code: error.code, error_message: error.message });
                    }
                    // Sin codigo = sin red o error de servidor: sigue pendiente.
                }
            }
            this.pending = await db.outbox.where('status').equals('pendiente').count();
        },

        async closeTill(countedCents) {
            this.busy = true;
            this.error = null;
            try {
                await this.syncOutbox();
                if (this.pending > 0) {
                    this.error = `Hay ${this.pending} venta(s) sin sincronizar: conecta el dispositivo antes de cerrar.`;
                    return;
                }
                this.closing = await api.closeSession(this.session.id, countedCents);
                this.session = null;
                await kvSet('cache', { ...(await kvGet('cache', {})), session: null });
                this.screen = 'till';
            } catch (error) {
                this.fail(error);
            } finally {
                this.busy = false;
            }
        },

        async logout() {
            try { await api.logout(); } catch { /* el token muere igual */ }
            setToken(null);
            this.user = null;
            this.session = null;
            this.screen = 'login';
        },
    },
});
