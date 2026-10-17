# Sistema Web de Evaluación Psicológica Laboral mediante el Test de Kostick

Aplicación web en PHP para registrar participantes, aplicar el Test de Kostick y consultar resultados. Incluye administración de puestos y códigos de acceso, exportación de resultados a PDF y Excel, y validación CAPTCHA mediante Cloudflare Turnstile.

## Funcionalidades

- Registro de participantes y acceso a la evaluación mediante códigos.
- Aplicación del cuestionario y consulta de resultados.
- Inicio de sesión administrativo y gestión de puestos y códigos de acceso.
- Exportación de resultados a PDF y Excel.
- Protección de formularios con tokens CSRF y CAPTCHA Turnstile.

## Requisitos

- PHP 8.1 o posterior, con las extensiones `pdo_mysql`, `mbstring`, `dom` y `xml` para la configuración MySQL y la generación de PDF.
- MySQL/MariaDB o PostgreSQL, según el esquema que se utilice.
- Apache u otro servidor web compatible con PHP.

## Instalación y configuración

1. Coloca o clona el proyecto en el directorio publicado por tu servidor web.
2. Crea una base de datos e importa el esquema correspondiente desde `database/`:
   - `kostick_db.sql` para MySQL/MariaDB.
   - `kostick_db_postgres.sql` para PostgreSQL.
3. Define en el entorno del proceso PHP los datos de conexión: `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER` y `DB_PASS`.
4. Define `TURNSTILE_SITE_KEY` y `TURNSTILE_SECRET_KEY` con las claves de tu sitio de Cloudflare Turnstile.
5. Abre `index.php` desde el servidor web para acceder al formulario de registro.

`.env.example` enumera variables de referencia, pero la aplicación no carga archivos `.env` automáticamente. No guardes credenciales reales en el repositorio. Si TLS termina en un proxy y PHP recibe la conexión como HTTP, configura `SESSION_COOKIE_SECURE=1` para conservar el atributo `Secure` en la cookie de sesión.

La aplicación puede crear o actualizar tablas auxiliares durante la ejecución. El usuario de base de datos necesita permisos para esos cambios. La administración se accede desde `pages/admin_login.php`; las credenciales administrativas deben configurarse de acuerdo con la lógica de autenticación del proyecto y no se proporcionan credenciales predeterminadas en este README.

## Estructura del proyecto

- `index.php`: formulario de registro.
- `pages/`: evaluación, autenticación administrativa, resultados y exportaciones.
- `includes/`: conexión a base de datos, sesiones, autenticación, validaciones y lógica compartida.
- `database/`: esquemas para MySQL/MariaDB y PostgreSQL.
- `css/`, `js/`, `img/`: estilos, scripts y recursos visuales.
- `dompdf/`: biblioteca Dompdf utilizada para generar PDF.

## Configuración sensible

No se incluyen secretos ni un archivo `.env` operativo. Mantén las claves de Turnstile, credenciales de base de datos y cualquier configuración del servidor fuera del control de versiones.
