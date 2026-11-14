# AGENT.md — FinanzasML (ML Grupo Comercial)

> Léelo completo al iniciar cualquier sesión. Complementa a `CLAUDE.md` (guías de Laravel Boost, que siguen vigentes).
> Última auditoría completa: 2026-11-13. Si cambias algo estructural, actualiza este archivo.

## 1. Rol

Eres el **ingeniero de software principal y guardián de la integridad financiera** de FinanzasML.
Esto no es un CRUD cualquiera: el sistema guarda ventas, existencias, gastos, mermas y cortes de caja de
las sucursales de un negocio de pollo. **Un número mal calculado es dinero mal contado.**

Prioridades, en orden:
1. **Integridad de los datos financieros** (totales, diferencias, existencias encadenadas día a día).
2. **Seguridad y permisos** (el servidor nunca confía en lo que manda el navegador).
3. **Que la captura diaria nunca se bloquee** (los capturistas usan celular/PWA, a veces sin internet).
4. Consistencia con el código existente, y luego el estilo.

Cómo trabajar con el usuario (Hugo):
- Habla **español**, directo y conciso. Nada de relleno.
- Cuando se pide "analizar/revisar", **reporta hallazgos antes de cambiar nada** y espera el visto bueno.
- Antes de operaciones destructivas (TRUNCATE, borrar datos, tocar la BD de `finanzas`) **pide confirmación**.
- Prueba todo cambio con tests Pest. No inventes que algo funciona: verifícalo y reporta lo que no pudiste verificar.

## 2. Qué es el sistema

Captura y control diario de cuentas por sucursal:
- **Capturista** (por sucursal): registra la cuenta del día en 7 pasos (Existencia → Entradas → Salidas → Gastos → Merma → Sobrante → Totales).
- **Admin / Super Admin**: ven y editan cuentas, sucursales, productos, usuarios, reportes (PDF/CSV), auditoría y configuración.
- Llenado semi-automático desde foto/PDF de la hoja en papel con **Gemini** (se puede apagar en Configuración).
- **PWA** instalable (service worker `public/sw.js`, `manifest.webmanifest`, `/app-version`).
- Borrador local de la captura (`resources/js/borrador-cuenta.js`): localStorage, 2 días, aviso de sin conexión / sesión expirada.

### Fórmula de negocio (núcleo)
```
total_venta = existencia + entradas − sobrante − salidas − gastos − mermas
diferencia  = total_venta − (efectivo_entregado + tarjeta)      // > 0 = faltante de caja
existencia(hoy) = sobrante(ayer) de la MISMA sucursal            // se encadena día con día
```
- Cada producto (`item_cuentas`): `importe_* = precio × cantidad_*` (3 decimales, `CalcularImporte`).
- Una **salida** de la sucursal A crea una **entrada espejo** en la sucursal B (`entradas.salida_id`); ambas crean la cuenta del día en origen/destino si no existe (`firstOrCreate`).
- `efectivo_pollo` / `efectivo_marinado` = suma de `importe_sobrante` por categoría. **Los ids de categoría 1 (pollo) y 2 (marinado) están escritos a mano en el código.**

## 3. Stack

PHP 8.3 · Laravel 13 · Livewire 4 (componentes de archivo único `⚡*.blade.php`) · Flux UI free v2 · Tailwind 4 ·
Alpine (incluido en Livewire) · Vite · Fortify (2FA + passkeys, **registro público desactivado**) ·
spatie/laravel-permission · PowerGrid (tabla de cuentas) · DomPDF (reportes) · Pest 4 · Larastan nivel 7 · Pint · Boost MCP.

- BD local: MySQL `finanzas` (root, sin contraseña) en 127.0.0.1:3306. Tests: SQLite en memoria (`phpunit.xml`).
- La BD local fue cargada con el dump `mlgrupoc_demo.sql` (producción demo): ~1 051 cuentas, 55 692 `item_cuentas`, 8 sucursales, 72 productos, 5 usuarios.
- Servidor local típico: `php artisan serve` (Laragon, PHP en `C:\laragon\bin\php\...`). Node vive en `C:\Program Files\nodejs`.
- PowerShell bloquea `npm.ps1`: usar `npm.cmd` o `Set-ExecutionPolicy -Scope CurrentUser RemoteSigned`.
- `public/build` está ignorado por git; el usuario sube el build (hay un `public/build.zip` sin versionar). **Tras cambiar JS/CSS/Blade con clases nuevas, hay que correr `npm run build` y subir el build**, o producción queda con assets viejos.

## 4. Estructura

```
app/Models/            Cuenta, ItemCuenta, Entrada, Salida, Gasto, Merma, Producto, Categoria, Sucursal,
                       Direccion, StatusCuenta, Execution, Configuracion, ActivityLog, User
app/Actions/           Fortify/, Permisos/SeccionesDelAdmin, Reportes/GenerarReporte
app/Livewire/          CuentaTable.php (PowerGrid)
app/Http/Controllers/  ReporteController (PDF/CSV), AppVersionController
app/Console/Commands/  ImportLegacyCuentas (legacy:import-cuentas)
src/Domain/Cuentas/    Actions: CalcularImporte, ProcesarItemAction, InterpretarFormularioCuentaAction (Gemini)
resources/views/pages/ Páginas Livewire (⚡registrar, ⚡show, ⚡dashboard, usuarios, sucursales, productos, reportes, auditoria, configuracion, settings, auth)
resources/js/          app.js, borrador-cuenta.js
routes/                web.php, cuentas.php, catalogos.php, settings.php
tests/Feature/         ~27 archivos Pest (142 tests)
.claude/skills/        Skills de Boost (fluxui, livewire, pest, tailwind, fortify, laravel-best-practices)
```
Namespace extra: `Domain\` → `src/Domain/`.

### Roles y permisos
- Roles: `Super Admin` (todo), `Admin`, `Capturista`. Permisos tipo `admin.<sección>.index|show`.
- `Admin` recibe permisos por sección; el Super Admin los concede/quita en Configuración (`SeccionesDelAdmin`).
- `admin.auditoria.index` y `admin.configuracion.index` son **solo Super Admin**.
- Capturista: ruta `capturista/registrar` (middleware `role:Capturista`) y Productos (solo pestaña de precios). Su `sucursal_id` es su sucursal.
- `User.activo=false` impide iniciar sesión (pero ver hallazgo M4).
- Cada acción relevante debe llamar `ActivityLog::log('...', $modelo)` (bitácora/auditoría).

## 5. Comandos

```bash
php artisan test --compact                       # suite completa (≈23 s, 142 tests)
php artisan test --compact --filter=Nombre       # un test
vendor/bin/pint --dirty --format agent           # OBLIGATORIO tras tocar PHP
vendor/bin/phpstan analyse --memory-limit=1G     # nivel 7 (hoy hay 19 errores de tipos)
composer test                                    # pint --test + phpstan + tests
composer audit ; npm audit                       # dependencias
npm run build                                    # assets (Node en C:\Program Files\nodejs)
```

## 6. Reglas del proyecto

### Obligatorias
1. **Nunca confíes en propiedades públicas de Livewire.** Todo lo que el navegador no deba tocar
   (`esAdmin`, `sucursalId`, `cuentaEditandoId`, `canManage*`, totales como `sumExistencia`, `totalSalidas`…)
   va con `#[Locked]` o se **recalcula/valida en el servidor** dentro de cada acción. Los `middleware` de ruta
   no bastan: cada acción que escribe debe **autorizar** (`abort_unless(auth()->user()->can(...), 403)`) usando
   `auth()`, nunca una propiedad del componente.
2. Un capturista solo opera sobre **su** sucursal y sobre el día permitido; el servidor lo impone.
3. Toda acción que escribe: `validate()` con reglas (numéricos `min`/`max`, `exists`, fechas) y transacción si toca varias tablas.
4. No usar `catch (\Throwable)` mudo: **loguear** (`logger()->error`) y avisar con `Flux::toast`.
5. Cálculos monetarios en un solo lugar (Actions de `src/Domain/Cuentas`), no duplicados entre `registrar` y `show`.
6. Una vista **no debe escribir** en la base (ver H3).
7. Cualquier cambio de lógica → test Pest nuevo o actualizado, y `pint` al final.
8. Antes de tocar datos reales o la BD `finanzas`: confirmar con el usuario. Para pruebas de UI usar una BD SQLite aparte
   (`DB_CONNECTION=sqlite DB_DATABASE=<archivo temporal>` + `migrate` + `db:seed`).
9. Los diálogos que bloquean pantalla deben **nacer ocultos** (`style="display:none"` + `x-show`), no depender de `x-cloak`.
10. No instalar/actualizar dependencias sin aprobación. No crear documentación extra salvo petición.
13. **Commits con explicación.** Todo commit (en `Axel` y en `main`) lleva un título corto y un cuerpo con una breve explicación, en español, de **qué cambió y por qué**; nunca un mensaje vacío o de una sola palabra.
12. **Rama de trabajo: `Axel`.** Los cambios se hacen sobre la rama `Axel`. **Nunca hacer commit, push ni merge — ni en `Axel` ni en `main` — sin autorización expresa del usuario en ese momento** (una autorización vale solo para esa vez). Sin permiso, dejar los cambios sin commitear y avisarlo.
11. **Cambios de base de datos = dos caminos.** En la BD **local** se aplican solos (migración + `php artisan migrate`; antes, respaldo si borra o altera datos).
    **En producción NO se corre nada desde aquí:** cada vez que haya una migración o cambio de datos nuevo, **entrega al usuario en el chat el SQL equivalente**
    (compatible con MySQL 5.7, que es la versión de producción), probado antes contra la BD local, incluyendo el `INSERT` en la tabla `migrations` para que
    `php artisan migrate` no lo vuelva a intentar, y avisa si conviene respaldar primero. Anótalo también en la sección 9 de este archivo.

### Convenciones
- Nombres de dominio y UI en **español** (cuentas, sucursales, existencia, sobrante, merma…); código PHP descriptivo.
- Páginas = componentes Livewire de archivo único `resources/views/pages/**/⚡*.blade.php`; rutas con `Route::livewire(...)`.
- UI con componentes Flux y los propios (`x-page-header`, `x-stat-card`…); Tailwind 4; respetar modo oscuro.
- Dinero: columnas `decimal(16,3)`; en pantalla sin decimales si es entero, 2 si no (`formatearImporte`).
- Zona horaria y fechas: ver H5 antes de tocar cualquier `now()`.

### Trampas conocidas del entorno
- Herramientas `mcp__laravel-boost__*` pueden no estar conectadas; usar `php artisan` y lectura de archivos.
- Con el panel del navegador de Claude oculto, `requestAnimationFrame` se pausa y los `x-show` de Alpine no se actualizan: inyectar `window.requestAnimationFrame = cb => setTimeout(cb, 0)` al probar.
- Seeder `UsuariosSeeder` imprime contraseñas temporales aleatorias; en pruebas locales cámbialas con tinker.

## 7. Hallazgos de la auditoría 2026-11-13 (los críticos ya se corrigieron; ver al final)

Estado base: 142 tests (140 ok, 2 skipped), Pint limpio, Larastan nivel 7 con 19 errores.
**Confirmados con pruebas reales** (marcados ✔) o con datos de la BD.

### Críticos / Altos
- **H1 ✔ Registrar cuenta: el cliente controla datos de autoridad.** Un capturista puede, por Livewire, fijar `sucursalId` (guardar en otra sucursal),
  `esAdmin=true` (saltarse el límite de 1 registro/día), `sumExistencia`/`sumSobrante`/`totalSalidas` (falsear `total_venta`),
  `fechaVenta` (sobrescribir cuentas antiguas con `updateOrCreate`) y `cuentaEditandoId`. `guardar()` no valida ni autoriza.
  Archivo: `resources/views/pages/cuentas/⚡registrar.blade.php`. Además `ProcesarItemAction` pasa todas las llaves del item del cliente a `ItemCuenta::updateOrCreate` (`cuenta_id` es fillable).
- **H2 ✔ Productos: `canManageCatalogo` es pública** → un capturista la pone en `true` y crea/edita/borra productos. `guardarPrecios()` acepta ids y precios arbitrarios (negativos, texto). Archivo: `pages/productos/⚡index.blade.php`.
- **H3 ✔ Abrir el detalle de una cuenta escribe en la BD**: `⚡show.blade.php::mount()` llama `recalcularTotales()` que hace `$cuenta->update(total_venta, diferencia)`, con una fórmula distinta a la de captura
  (`abs(existencia+entrada)`, existencia = `sobrante` de ayer). En la BD hay **59 cuentas** cuyo `total_venta` guardado no coincide con la fórmula (varias con signo invertido) y 3 con `sobrante` ≠ suma de items.
- **H4 ✔ Un Admin puede editar y deshabilitar a un Super Admin** (cambiar rol/contraseña/correo): `usuarios/⚡index` no protege al usuario destino. Toma de cuenta por un Admin.
- **H5 Zona horaria = UTC** (`config/app.php`), negocio en México (UTC-6). Después de las 18:00 locales `now()` ya es "mañana": la fecha de venta por defecto, "un registro por día", dashboard "hoy", auditoría y PDFs se desfasan. (Verificado: a las 18:14 locales PHP marcaba 00:14 del día siguiente.)
- **H6 ✔ Editar una cuenta no borra gastos/mermas quitados** en la pantalla (solo hace `updateOrCreate`); cambiar el concepto duplica. Los totales siguen contando el gasto "eliminado".

### Medios
- M1 `item_cuentas` sin índice UNIQUE (`cuenta_id`,`producto_id`): **825 cuentas con productos duplicados** (745 con MOLE en ceros; 84 con valores distintos → doble conteo). Existen 8 entradas sin salida con `precio_envio>0`, 1 salida sin entrada espejo y 2 espejos con total distinto.
- M2 `⚡show`: `guardarGasto/Merma/Item/Efectivo/Status` sin `validate()`; hacen `update($this->gastoForm)` con arreglos públicos.
- M3 `Usuarios`: `sucursal_id` sin `exists`; contraseña mínima 8 (la política de producción es 12); `Sucursales` no valida nombre único al editar. `catch (\Throwable)` mudos en usuarios/sucursales/salidas.
- M4 ✔ Un usuario deshabilitado **con sesión abierta** sigue usando la app (solo se bloquea al iniciar sesión).
- M5 Dependencias con avisos: `composer audit` (livewire/livewire authorization bypass en `#[Authorize]`, symfony/http-foundation ReDoS y otros, league/commonmark, symfony/yaml…) y `npm audit` (vite alto; concurrently/shell-quote críticos).
- M6 Migración `item_cuentas.fecha_venta` usa `default(now())`: queda **fijada** la fecha del día de migración (`'2026-11-13'`).
- M7 Total de pollo/marinado usa categorías con id fijo 1 y 2; el listado de cuentas oculta cuentas con `efectivo_pollo` y `efectivo_marinado` en 0.
- M8 69 cuentas cuya existencia no coincide con el sobrante del día anterior (cadena rota); 262 con `fecha_captura < fecha_venta`.

### Bajos
- L1 Larastan: 19 errores (tipos en `GenerarReporte`, `fopen` sin comprobar en `ReporteController`, `nullsafe` innecesario en `AppServiceProvider`).
- L2 `.env.example` con `APP_DEBUG=true` y `SESSION_ENCRYPT=false` (asegurar `APP_DEBUG=false` en producción).
- L3 El mensaje de error de la IA (`procesarFotoFormulario`) muestra `$e->getMessage()` al usuario.
- L4 `public/build.zip` sin versionar en la raíz pública.

### Corregido el 2026-11-13 (sin commit; 165 tests, 163 ok + 2 omitidos; tests en `tests/Feature/SeguridadYCapturaTest.php`)
- **H1** `registrar`: `esAdmin`, `cuentaEditandoId`, `iaCapturaHabilitada`, `sumExistencia/Entrada/Sobrante` y `totalSalidas` son `#[Locked]`; `sucursalId` solo lo cambia un admin (hook `updatingSucursalId`) y la del capturista se fuerza desde `Auth`.
  `guardar()` valida en servidor (`errorDeCaptura`: presentó, sucursal, fecha, productos del catálogo sin repetir, números ≥ 0, un registro por día, cuenta ya capturada), normaliza los renglones (`normalizarItems`) y **recalcula importes y totales**. Capturista: fecha entre hoy y 3 días atrás.
  `ProcesarItemAction` usa lista blanca de columnas.
- **H2** `productos`: `canManageCatalogo` es `#[Locked]` y la autorización consulta `auth()->user()->can()` (`puedeGestionarCatalogo()`); `guardarPrecios` valida ids y precios (≥ 0, numérico).
- **H3** `show`: `mount()` ya no escribe (`recalcularTotales(guardar: false)`); `guardarEfectivo` recalcula total y diferencia al guardar. Validaciones añadidas a status, efectivo/tarjeta, gasto, merma y producto (M2).
- **H4** `usuarios`: un Admin no puede abrir, editar ni deshabilitar a un Super Admin (403). `sucursal_id` con `exists`; contraseña con `Password::defaults()` (M3 parcial).
- **H5** Zona horaria de negocio: `config('app.business_timezone')` (`America/Mexico_City`, env `APP_BUSINESS_TIMEZONE`) y `App\Actions\Fechas\DiaDeNegocio` (`hoy/ahora/inicioUtc/finUtc/zona`).
  Usado en registrar (fecha por defecto, un registro por día), dashboard, reportes, sucursales, auditoría (filtros y hora mostrada) y PDFs. **Las marcas de tiempo siguen en UTC en la base.**
- **H6** Al editar una cuenta, gastos/mermas quitados en pantalla se borran; conceptos repetidos se suman en uno.
- **M4** Middleware `EnsureUserIsActive` (grupo web) expulsa a un usuario deshabilitado con sesión abierta.
- Diálogos de borrador/sesión expirada en `registrar` nacen ocultos (no bloquean la pantalla si Alpine/JS falla).

### Corregido después (misma fecha)
- **M1** Migración `2026_11_14_003113_*`: limpia renglones duplicados de `item_cuentas` (conserva el de más movimiento) y crea el índice UNIQUE (`cuenta_id`,`producto_id`); también quita el DEFAULT fijo de `fecha_venta` (M6). Aplicada en la BD local `finanzas`: se borraron 827 renglones; copia en la tabla `item_cuentas_respaldo_duplicados` (borrarla cuando ya no haga falta). Los totales guardados no se recalcularon.
- **Fórmula única** `Domain\Cuentas\Actions\CalcularTotalesCuenta` usada en `registrar::guardar` y en `show::recalcularTotales` (existencia y sobrante salen de los `item_cuentas` de la cuenta).
- **M3** Todos los `catch (\Throwable)` de usuarios/sucursales/registrar/show/productos registran con `logger()->error(__METHOD__, ...)`; sucursales valida nombre único al editar.
- **M7** Constantes `Categoria::POLLO` y `Categoria::MARINADO` en lugar de ids sueltos.
- **M5** `composer update` (livewire 4.4.8, laravel 13.42, symfony 7.4.23, commonmark…) y `npm audit fix`: `composer audit` y `npm audit` sin hallazgos. Assets reconstruidos con `npm run build`.
- **L1–L4** Larastan nivel 7 en 0 errores (`treatPhpDocTypesAsCertain: false`), `.env.example` con `APP_DEBUG=false`, el error de la IA ya no muestra detalles técnicos, `/public/build.zip` en `.gitignore`.
- **Aviso de borrador**: ya no es modal; es un banner ámbar arriba de la pantalla ("Encontramos una cuenta sin terminar … Continuar con lo último / Descartar") que no bloquea la captura y se oculta al presentar una cuenta nueva.

### Rendimiento (auditado 2026-11-14, con 1 051 cuentas y 54 865 renglones)
- Pantallas: 17–80 ms de servidor y 3–24 consultas; índices correctos. Guardar cuenta: de 163 a **20 consultas** (`ItemCuenta::upsert` sobre el índice único `cuenta_id,producto_id`).
- **PDF de reportes limitado a `GenerarReporte::MAX_FILAS_PDF` (250 filas)**: DomPDF usaba ≈290 MB con 786 filas. Con más filas el botón PDF se deshabilita (aviso en pantalla) y el controlador responde 422; el CSV no tiene límite.
- El dashboard saca los años con un `SELECT DISTINCT` en vez de traer todas las fechas.
- En producción: `php artisan optimize` en cada despliegue, OPcache activo y `APP_DEBUG=false`.

### Pendiente real
- **M8** Datos históricos: 69 cuentas con existencia distinta al sobrante del día anterior y 262 con `fecha_captura < fecha_venta`; no se pueden corregir con código, hay que revisarlas con el dueño del negocio.
- El listado de cuentas (`CuentaTable`) oculta cuentas con `efectivo_pollo` y `efectivo_marinado` en 0 (decisión de diseño original; revisar si debe mostrarlas).
- Subir el build (`public/build`) al servidor: cambió JS y CSS.

## 8. Qué verificar antes de dar algo por terminado
- [ ] `php artisan test --compact` en verde (y el test nuevo del cambio).
- [ ] `vendor/bin/pint --dirty --format agent`.
- [ ] Si tocaste cuentas/totales: probar con un capturista **y** un admin, y comparar contra la fórmula de la sección 2.
- [ ] Si tocaste JS/CSS/clases Tailwind: `npm run build` (avisar al usuario que suba el build).
- [ ] Nada que escriba datos reales de `finanzas` sin confirmación.

## 9. SQL entregado para producción (historial)

> Producción usa MySQL 5.7 (`phpMyAdmin`, dump `mlgrupoc_demo.sql`). Ejecutar en orden, con respaldo previo de la base.

### 2026-11-14 — migración `2026_11_14_003113_add_unique_cuenta_producto_and_drop_fecha_default_on_item_cuentas`
Limpia renglones repetidos de `item_cuentas` (conserva el de más movimiento y, a igualdad, el de id mayor), crea el índice UNIQUE y quita el DEFAULT fijo de `fecha_venta`.
Probado en local (MySQL 8.4): 1 652 filas con duplicados → se borran 827, quedan 825, 0 grupos repetidos.

```sql
-- 1) Respaldo de las filas involucradas
CREATE TABLE item_cuentas_respaldo_duplicados AS
SELECT i.*
FROM item_cuentas i
JOIN (
    SELECT cuenta_id, producto_id
    FROM item_cuentas
    GROUP BY cuenta_id, producto_id
    HAVING COUNT(*) > 1
) d ON d.cuenta_id = i.cuenta_id AND d.producto_id = i.producto_id;

-- 2) Ids a borrar (de cada grupo repetido se conserva uno)
CREATE TEMPORARY TABLE tmp_borrar AS
SELECT i.id
FROM item_cuentas i
JOIN (
    SELECT cuenta_id, producto_id,
           SUBSTRING_INDEX(GROUP_CONCAT(id ORDER BY (importe_existencia + importe_entrada + importe_sobrante) DESC, id DESC), ',', 1) AS conservar
    FROM item_cuentas
    GROUP BY cuenta_id, producto_id
    HAVING COUNT(*) > 1
) g ON g.cuenta_id = i.cuenta_id AND g.producto_id = i.producto_id
WHERE i.id <> g.conservar;

-- 3) Borrar los repetidos
DELETE FROM item_cuentas WHERE id IN (SELECT id FROM tmp_borrar);

-- 4) Índice único y quitar el DEFAULT fijo de fecha_venta
ALTER TABLE item_cuentas
    ADD UNIQUE INDEX item_cuentas_cuenta_producto_unique (cuenta_id, producto_id),
    ALTER COLUMN fecha_venta DROP DEFAULT;

-- 5) Registrar la migración para que `php artisan migrate` no la repita
INSERT INTO migrations (migration, batch)
VALUES (
    '2026_11_14_003113_add_unique_cuenta_producto_and_drop_fecha_default_on_item_cuentas',
    (SELECT MAX(batch) + 1 FROM (SELECT batch FROM migrations) AS m)
);

-- 6) (Opcional, más adelante) borrar el respaldo cuando ya no haga falta
-- DROP TABLE item_cuentas_respaldo_duplicados;
```
