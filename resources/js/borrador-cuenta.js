/**
 * Registrar cuenta: aviso de desconexión y borrador local.
 *
 * - Si se va el internet (o expira la sesión) se muestra un modal con "Reintentar" y se bloquea la captura.
 * - Lo capturado se guarda en este navegador (localStorage) y se puede retomar si la página se recarga o se cierra.
 *   Los borradores duran 2 días y se separan por usuario y por cuenta (nueva o la que se edita).
 */

const PREFIJO = 'ml:borrador-cuenta:';
const VERSION = 1;
const VIGENCIA_MS = 2 * 24 * 60 * 60 * 1000;
const ESPERA_GUARDADO_MS = 300;
const SONDEO_MS = 5000;
const TIEMPO_PING_MS = 6000;

function almacenamiento() {
    try {
        return window.localStorage;
    } catch {
        return null;
    }
}

function esVigente(borrador) {
    return Boolean(borrador)
        && borrador.version === VERSION
        && Number.isFinite(borrador.guardadoEn)
        && Date.now() - borrador.guardadoEn <= VIGENCIA_MS;
}

function leer(clave) {
    try {
        const crudo = almacenamiento()?.getItem(clave);

        return crudo ? JSON.parse(crudo) : null;
    } catch {
        return null;
    }
}

function purgarVencidos() {
    const storage = almacenamiento();

    if (!storage) {
        return;
    }

    try {
        Object.keys(storage)
            .filter((clave) => clave.startsWith(PREFIJO))
            .forEach((clave) => {
                if (!esVigente(leer(clave))) {
                    storage.removeItem(clave);
                }
            });
    } catch {
        // Sin acceso al almacenamiento: la captura sigue funcionando sin borrador.
    }
}

window.borradorCuenta = (usuarioId, urlPing, nombresSucursal = {}) => ({
    usuarioId,
    urlPing,
    nombresSucursal,

    sinConexion: false,
    sesionExpirada: false,
    reintentando: false,
    falloReintento: false,
    guardadoLocal: false,

    ofrecerBorrador: false,
    borradorOfrecido: null,
    restaurando: false,

    base: null,
    bloqueado: false,
    saliendo: false,
    temporizadorGuardado: null,
    temporizadorSondeo: null,
    limpiezas: [],

    get clave() {
        return `${PREFIJO}${this.usuarioId}:${this.$wire.cuentaEditandoId ?? 'nueva'}`;
    },

    get bloqueaPantalla() {
        // El aviso de borrador es un banner informativo: no bloquea la captura.
        return this.sinConexion || this.sesionExpirada;
    },

    init() {
        purgarVencidos();

        const alPerderRed = () => this.mostrarSinConexion();
        const alVolverRed = () => {
            if (this.sinConexion) {
                this.verificarYRecuperar();
            }
        };
        const alSalir = () => {
            this.guardarAhora();
            this.saliendo = true;
        };
        const alVolverAPagina = () => {
            this.saliendo = false;
        };
        const alOcultar = () => {
            if (document.visibilityState === 'hidden') {
                this.guardarAhora();
            }
        };

        window.addEventListener('offline', alPerderRed);
        window.addEventListener('online', alVolverRed);
        window.addEventListener('pagehide', alSalir);
        window.addEventListener('pageshow', alVolverAPagina);
        document.addEventListener('visibilitychange', alOcultar);

        this.limpiezas.push(
            () => window.removeEventListener('offline', alPerderRed),
            () => window.removeEventListener('online', alVolverRed),
            () => window.removeEventListener('pagehide', alSalir),
            () => window.removeEventListener('pageshow', alVolverAPagina),
            () => document.removeEventListener('visibilitychange', alOcultar),
        );

        this.limpiezas.push(this.$wire.interceptRequest(({ onFailure, onError }) => {
            onFailure(() => this.alFallarPeticion());
            onError(({ response, preventDefault }) => {
                if (response.status === 419 || response.status === 401) {
                    preventDefault();
                    this.mostrarSesionExpirada();
                }
            });
        }));

        ['items', 'gastos', 'mermas', 'efectivoEntregado', 'tarjeta', 'step'].forEach((propiedad) => {
            this.$wire.$watch(propiedad, () => this.programarGuardado());
        });

        // El formulario "arranca" cuando se presenta la existencia (o de inicio, al editar una cuenta).
        this.$wire.$watch('buttonsDisabled', (deshabilitado) => {
            if (!deshabilitado) {
                this.tomarBase();

                // Si la persona empieza una cuenta nueva por su cuenta, el aviso de "retomar" ya no aplica.
                if (!this.$wire.cuentaEditandoId) {
                    this.ofrecerBorrador = false;
                    this.borradorOfrecido = null;
                }
            }
        });

        if (!this.$wire.buttonsDisabled) {
            this.tomarBase();
        }

        this.revisarBorradorGuardado();
    },

    destroy() {
        clearTimeout(this.temporizadorGuardado);
        clearInterval(this.temporizadorSondeo);
        this.limpiezas.forEach((limpiar) => limpiar());
        this.limpiezas = [];
        document.body.classList.remove('overflow-hidden');
    },

    // ---------- Conexión ----------

    async hayConexion() {
        const control = new AbortController();
        const limite = setTimeout(() => control.abort(), TIEMPO_PING_MS);

        try {
            const respuesta = await fetch(`${this.urlPing}?t=${Date.now()}`, {
                cache: 'no-store',
                signal: control.signal,
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
            });

            return respuesta.ok;
        } catch {
            return false;
        } finally {
            clearTimeout(limite);
        }
    },

    async alFallarPeticion() {
        if (this.saliendo || this.sinConexion) {
            return;
        }

        // Una petición abortada por navegar o recargar no es una desconexión: se confirma con un ping.
        if (!(await this.hayConexion()) && !this.saliendo) {
            this.mostrarSinConexion();
        }
    },

    mostrarSinConexion() {
        if (this.sinConexion || this.saliendo) {
            return;
        }

        this.guardarAhora();
        this.restaurando = false;
        this.falloReintento = false;
        this.sinConexion = true;
        this.cerrarCargandoGuardado();

        clearInterval(this.temporizadorSondeo);
        this.temporizadorSondeo = setInterval(() => this.verificarYRecuperar(true), SONDEO_MS);
    },

    mostrarSesionExpirada() {
        this.guardarAhora();
        this.sinConexion = false;
        this.sesionExpirada = true;
        this.cerrarCargandoGuardado();
    },

    cerrarCargandoGuardado() {
        if (window.Swal?.isVisible() && window.Swal.isLoading()) {
            window.Swal.close();
        }
    },

    async reintentar() {
        if (this.reintentando) {
            return;
        }

        this.reintentando = true;
        this.falloReintento = false;
        await this.verificarYRecuperar();
        this.reintentando = false;
    },

    async verificarYRecuperar(silencioso = false) {
        if (await this.hayConexion()) {
            clearInterval(this.temporizadorSondeo);
            this.sinConexion = false;
            this.falloReintento = false;

            try {
                await this.$wire.recuperarConexion();
            } catch {
                // Si vuelve a fallar, el interceptor mostrará de nuevo el aviso.
            }

            return;
        }

        if (!silencioso) {
            this.falloReintento = true;
        }
    },

    recargar() {
        window.location.reload();
    },

    // ---------- Borrador ----------

    estado() {
        const wire = this.$wire;

        return {
            items: (wire.items ?? []).map((item) => ({
                producto_id: item.producto_id,
                precio: item.precio,
                cantidad_entrada: item.cantidad_entrada,
                cantidad_sobrante: item.cantidad_sobrante,
            })),
            gastos: (wire.gastos ?? []).map((gasto) => ({ concepto: gasto.concepto, precio: gasto.precio })),
            mermas: (wire.mermas ?? []).map((merma) => ({ concepto: merma.concepto, precio: merma.precio })),
            efectivoEntregado: wire.efectivoEntregado,
            tarjeta: wire.tarjeta,
        };
    },

    tomarBase() {
        this.base = JSON.stringify(this.estado());
    },

    programarGuardado() {
        clearTimeout(this.temporizadorGuardado);
        this.temporizadorGuardado = setTimeout(() => this.guardarAhora(), ESPERA_GUARDADO_MS);
    },

    guardarAhora() {
        clearTimeout(this.temporizadorGuardado);

        const storage = almacenamiento();

        if (!storage || this.bloqueado || this.base === null || this.$wire.buttonsDisabled) {
            return;
        }

        const estado = this.estado();
        const clave = this.clave;

        // Sin cambios respecto al inicio y sin borrador previo: no hay nada que guardar.
        if (JSON.stringify(estado) === this.base && storage.getItem(clave) === null) {
            return;
        }

        try {
            storage.setItem(clave, JSON.stringify({
                version: VERSION,
                usuarioId: this.usuarioId,
                guardadoEn: Date.now(),
                sucursalId: this.$wire.sucursalId,
                fechaVenta: this.$wire.fechaVenta,
                step: this.$wire.step,
                maxStepReached: this.$wire.maxStepReached,
                ...estado,
            }));
            this.guardadoLocal = true;
        } catch {
            // Almacenamiento lleno o bloqueado: la captura sigue normal, solo sin borrador.
        }
    },

    revisarBorradorGuardado() {
        const borrador = leer(this.clave);

        if (!esVigente(borrador)) {
            return;
        }

        this.guardadoLocal = true;

        if (this.$wire.buttonsDisabled || this.$wire.cuentaEditandoId) {
            this.borradorOfrecido = borrador;
            this.ofrecerBorrador = true;
        }
    },

    get resumenBorrador() {
        const borrador = this.borradorOfrecido;

        if (!borrador) {
            return '';
        }

        const partes = [];
        const sucursal = this.nombresSucursal[borrador.sucursalId];

        if (sucursal) {
            partes.push(sucursal);
        }

        if (borrador.fechaVenta) {
            const [anio, mes, dia] = String(borrador.fechaVenta).split('-');
            partes.push(`Venta del ${dia}/${mes}/${anio}`);
        }

        const paso = this.$wire.steps?.[borrador.step];

        if (paso) {
            partes.push(`Paso ${borrador.step}: ${paso}`);
        }

        return partes.join(' · ');
    },

    get antiguedadBorrador() {
        const minutos = Math.max(0, Math.round((Date.now() - (this.borradorOfrecido?.guardadoEn ?? Date.now())) / 60000));

        if (minutos < 1) {
            return 'hace unos segundos';
        }

        if (minutos < 60) {
            return `hace ${minutos} min`;
        }

        const horas = Math.round(minutos / 60);

        return horas < 24 ? `hace ${horas} h` : `hace ${Math.round(horas / 24)} d`;
    },

    async retomar() {
        if (this.restaurando || !this.borradorOfrecido) {
            return;
        }

        this.restaurando = true;

        try {
            const valido = await this.$wire.restaurarBorrador(JSON.parse(JSON.stringify(this.borradorOfrecido)));

            if (valido === false) {
                this.eliminarBorrador();
            }

            this.ofrecerBorrador = false;
            this.borradorOfrecido = null;
        } catch {
            // Sin conexión: el interceptor muestra el aviso y el borrador se conserva para reintentar.
        } finally {
            this.restaurando = false;
        }
    },

    descartar() {
        this.eliminarBorrador();
        this.ofrecerBorrador = false;
        this.borradorOfrecido = null;
    },

    eliminarBorrador() {
        try {
            almacenamiento()?.removeItem(this.clave);
        } catch {
            // Nada que limpiar.
        }

        this.guardadoLocal = false;
    },

    /** Se llama al guardar la cuenta con éxito: el borrador ya no hace falta. */
    limpiarBorrador() {
        clearTimeout(this.temporizadorGuardado);
        this.bloqueado = true;
        this.eliminarBorrador();
    },
});
