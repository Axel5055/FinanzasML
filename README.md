# ML Grupo — Sistema de Control de Cuentas

Sistema interno para el control diario de caja de las sucursales de ML Grupo (pollería). Cada sucursal registra su "cuenta" del día (existencia, entradas, salidas entre sucursales, gastos, merma y sobrante), el sistema calcula automáticamente el total de venta esperado y lo compara contra el efectivo/tarjeta entregado para detectar faltantes o sobrantes de caja.

Construido con **Laravel 13** + **Livewire 4** (componentes de un solo archivo) + **Flux UI** + **Tailwind CSS 4**.

---

## Tabla de contenido

- [Características principales](#características-principales)
- [Roles y permisos](#roles-y-permisos)
- [Stack tecnológico](#stack-tecnológico)
- [Requisitos previos](#requisitos-previos)
- [Instalación](#instalación)
- [Variables de entorno](#variables-de-entorno)
- [Comandos útiles](#comandos-útiles)
- [Estructura del proyecto](#estructura-del-proyecto)
- [Pruebas](#pruebas)
- [Despliegue](#despliegue)

---

## Características principales

- **Registro de cuenta diaria** — asistente de 7 pasos (Existencia → Entradas → Salidas → Gastos → Merma → Sobrante → Totales) que calcula el total de venta y la diferencia de caja en vivo.
- **Llenado automático con IA** — el capturista puede subir una foto o PDF del formulario en papel y el sistema (Google Gemini) extrae entradas, sobrantes, gastos, merma, efectivo y tarjeta automáticamente. Se puede habilitar o deshabilitar globalmente desde *Configuración* (solo Super Admin).
- **Detalle de cuenta editable** — cada cuenta registrada se puede consultar y corregir: productos, salidas a otra sucursal, gastos, merma, status y efectivo/tarjeta.
- **Reportes en PDF** — reporte individual por cuenta y reporte por rango de fechas/sucursal, descargables desde el sistema.
- **Dashboard** — ventas del día/semana/mes, faltantes y sobrantes, top de sucursales y gráfica anual (Highcharts).
- **Catálogos administrables** — sucursales, productos (con precios) y usuarios.
- **Bitácora de actividad** — registra quién hizo qué (creación/edición de cuentas, cambios de configuración, etc.).

## Roles y permisos

El sistema usa [Spatie Permission](https://spatie.be/docs/laravel-permission) con tres roles:

| Rol | Puede hacer |
|---|---|
| **Capturista** | Registrar la cuenta diaria de su propia sucursal y editar precios de productos. |
| **Admin** | Todo lo de Capturista, más: ver/editar cualquier cuenta, gestionar sucursales, productos y usuarios. |
| **Super Admin** | Todo lo de Admin, más acceso a *Configuración* (activar/desactivar el llenado con IA). |

## Stack tecnológico

- **Backend:** PHP 8.3, Laravel 13, Laravel Fortify (autenticación, 2FA, passkeys)
- **Frontend reactivo:** Livewire 4, Alpine.js, Flux UI (Free)
- **Estilos:** Tailwind CSS 4, Vite
- **Base de datos:** MySQL (SQLite soportado para desarrollo)
- **PDFs:** barryvdh/laravel-dompdf
- **Tablas de datos:** PowerGrid
- **IA (opcional):** Google Gemini API (`gemini-flash-latest`, con respaldo automático a `gemini-flash-lite-latest`)
- **Pruebas:** Pest 4
- **Calidad de código:** Laravel Pint, Larastan (PHPStan)

## Requisitos previos

- PHP >= 8.3 con las extensiones `pdo_mysql` (o `pdo_sqlite`) y `mbstring`
- Composer
- Node.js 18+ y npm
- MySQL 8+ (o SQLite para desarrollo rápido)

## Instalación

```bash
git clone <url-del-repositorio>
cd FinanzasML

composer install
npm install

cp .env.example .env
php artisan key:generate
```

Configura la base de datos en `.env` (ver [Variables de entorno](#variables-de-entorno)) y luego:

```bash
php artisan migrate --seed
npm run build
php artisan serve
```

El seeder inicial crea las sucursales, categorías/productos base, los roles (Admin, Super Admin, Capturista) y usuarios de ejemplo. Las contraseñas de estos usuarios se generan aleatoriamente y se imprimen en la consola al correr el seeder — guárdalas en ese momento, ya que no quedan registradas en ningún archivo.

Para desarrollo con recarga en caliente, usa en su lugar:

```bash
composer run dev
```

Esto levanta el servidor de Laravel, el worker de colas y Vite al mismo tiempo.

## Variables de entorno

Además de las variables estándar de Laravel, este proyecto usa:

| Variable | Descripción |
|---|---|
| `DB_CONNECTION`, `DB_HOST`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` | Conexión a la base de datos (MySQL recomendado; SQLite funciona para desarrollo). |
| `GEMINI_API_KEY` | Llave de la API de Google Gemini, necesaria para el llenado automático con IA. Gratuita en [aistudio.google.com/apikey](https://aistudio.google.com/apikey). Si se deja vacía, la opción de IA simplemente no funcionará (el resto del sistema opera con normalidad). |
| `GEMINI_MODEL` | Modelo principal a usar (por defecto `gemini-flash-latest`). |
| `GEMINI_FALLBACK_MODEL` | Modelo de respaldo si el principal está saturado (por defecto `gemini-flash-lite-latest`). |

## Comandos útiles

```bash
# Formatear el código según el estilo del proyecto
vendor/bin/pint

# Correr las pruebas
php artisan test

# Correr un archivo o filtro de pruebas específico
php artisan test --compact --filter=NombreDeLaPrueba

# Análisis estático
vendor/bin/phpstan analyse

# Compilar assets para producción
npm run build
```

## Estructura del proyecto

```
app/
  Livewire/            Componentes Livewire de clase completa (tablas, etc.)
  Models/              Modelos Eloquent
  Http/Controllers/    Controladores (principalmente para generar PDFs)
src/Domain/            Lógica de negocio organizada por dominio (p. ej. Cuentas\Actions)
resources/views/pages/ Páginas Livewire de un solo archivo (Volt), una carpeta por sección
resources/views/reportes/ Plantillas Blade de los PDFs
database/migrations/   Esquema de base de datos
database/seeders/      Datos iniciales (roles, catálogos, usuarios de ejemplo)
tests/Feature/         Pruebas Pest, organizadas por sección
```

Las páginas principales viven en `resources/views/pages/`:

- `cuentas/⚡registrar.blade.php` — asistente de captura de cuenta diaria
- `cuentas/⚡show.blade.php` — detalle y edición de una cuenta
- `cuentas/⚡index.blade.php` — listado/filtro de cuentas
- `configuracion/⚡index.blade.php` — interruptor de IA (solo Super Admin)
- `⚡dashboard.blade.php` — panel principal con estadísticas y gráfica

## Pruebas

El proyecto usa [Pest](https://pestphp.com/). Las pruebas corren contra una base de datos SQLite en memoria (configurada en `phpunit.xml`), independiente de la base de datos de desarrollo.

```bash
php artisan test --compact
```

Antes de dar por terminado un cambio, corre las pruebas relacionadas y `vendor/bin/pint --dirty` sobre los archivos modificados.

## Despliegue

1. Sube el código al servidor y corre `composer install --no-dev --optimize-autoloader` y `npm run build`.
2. Configura el `.env` de producción (base de datos, `APP_ENV=production`, `APP_DEBUG=false`, y `GEMINI_API_KEY` si se usará el llenado con IA).
3. Corre las migraciones: `php artisan migrate --force`.
   - Si el hosting **no permite ejecutar comandos** (hosting compartido sin SSH), importa el esquema y los datos directamente vía phpMyAdmin usando un dump `.sql` exportado de la base de datos de desarrollo.
4. Cachea la configuración de la aplicación: `php artisan optimize`.
