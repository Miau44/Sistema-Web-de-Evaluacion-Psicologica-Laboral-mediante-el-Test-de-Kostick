# Sistema Web de Evaluación Psicológica Laboral mediante el Test de Kostick

Aplicación web en PHP para registrar participantes, aplicar el Test de Kostick y consultar resultados. Incluye administración de puestos y códigos de acceso, exportación a PDF y Excel, y validación CAPTCHA con Cloudflare Turnstile.

## Tecnologías

- PHP nativo y PDO.
- MySQL/MariaDB en la configuración actual de `includes/db.php`.
- HTML, CSS y JavaScript sin proceso de compilación.
- Dompdf incluido en `dompdf/` para generar PDF.

## Requisitos

- PHP 8.1 o posterior con `pdo_mysql`, `mbstring`, `dom` y `xml`.
- MySQL o MariaDB.
- Servidor web Apache o equivalente.

## Configuración local

1. Copie `.env.example` como referencia para configurar las variables de entorno del servidor. No suba `.env` ni credenciales al repositorio.
2. Cree la base de datos e importe `database/kostick_db.sql`.
3. Configure `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER` y `DB_PASS`.
4. Configure `TURNSTILE_SITE_KEY` y `TURNSTILE_SECRET_KEY` con claves propias de Cloudflare Turnstile.
5. Sirva la carpeta del proyecto con Apache/PHP y abra `index.php`.

La aplicación lee estas opciones desde el entorno del proceso PHP; no carga archivos `.env` automáticamente. Si TLS termina en un proxy y PHP recibe la conexión como HTTP, configure `SESSION_COOKIE_SECURE=1` para conservar el atributo `Secure` en la cookie de sesión.

La aplicación crea o actualiza algunas tablas auxiliares al ejecutarse; el usuario de base de datos debe tener permisos para esos cambios.

## Estructura

- `index.php`: formulario de registro.
- `includes/`: conexión, autenticación, validaciones y lógica compartida.
- `pages/`: test, administración, resultados y exportaciones.
- `database/`: esquemas SQL disponibles.
- `dompdf/`: dependencia para los reportes PDF.
- `css/`, `js/`, `img/`: recursos de interfaz.

No se incluye un archivo `.env` con secretos. `.env.example` documenta los nombres de configuración esperados.
