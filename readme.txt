=== Bridgeframe ===
Contributors: juankdegorvet
Tags: headless, REST API, frontend, token, gframe
Requires at least: 5.5
Tested up to: 6.5
Requires PHP: 7.1
Stable tag: 2.0.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Convierte WordPress en un backend headless con una API HTML/JSON que permite consumir contenido desde frameworks como Gframe, React, Vue, etc.

== Descripción ==

Bridgeframe transforma tu sitio WordPress en una fuente headless limpia y segura, usando endpoints personalizados que devuelven contenido en HTML procesado o JSON plano.

**Características destacadas:**
- Exposición del contenido vía REST API.
- Soporte para ACF, taxonomías, metadatos e imagen destacada.
- Modo Headless para bloquear el frontend y operar como backend.
- Protección con token autogenerado.
- Configuración simple desde el admin.
- Cache con transients para alto rendimiento.

== Instalación ==

1. Sube la carpeta `bridgeframe` a `/wp-content/plugins/`.
2. Activa el plugin desde el panel de administración.
3. Ve a "Ajustes > Bridgeframe" para configurar:

    - Obtener o regenerar el token de acceso.
    - Activar el modo headless.
    - Definir la URL de redirección del frontend si deseas redirigir el tráfico.

== Uso de la API ==

**Autenticación:**  
Todas las llamadas deben incluir un token, ya sea como parámetro `?token=XXX` o como header HTTP `Authorization: Bearer XXX`.

=== `GET /wp-json/bridgeframe/v1/html` ===  
Devuelve el contenido de un post/página.

**Parámetros:**
- `slug` (requerido): slug del post o página.
- `type` (opcional, por defecto: `post`): tipo de contenido.
- `lang` (opcional): cambiar idioma (requiere Polylang).
- `format` (`html` | `json`, por defecto: `html`): formato del campo `html`.
- `fields` (opcional): campos a incluir, separados por coma. Si se omite, se devuelven todos: `title`, `html`, `image`, `meta`, `acf`, `taxonomies`.
- `private` (`true|false`): incluir contenidos privados.
- `token`: requerido.

**Respuesta:**
{ "title": "...", "html": "...", "private": true }

Si el post existe pero es privado y `private=true` no fue enviado, devuelve:
{ "error": "Contenido privado", "private": true }

=== `GET /wp-json/bridgeframe/v1/list` ===  
Lista entradas según filtros.

**Parámetros:**
- `type`: tipo de contenido (`post`, `page`, etc.).
- `taxonomy`, `term`: filtrar por taxonomía y término.
- `s`: búsqueda por texto.
- `limit`: número de resultados por página (default: 10).
- `page`: número de página (default: 1).
- `private`: `true` para incluir contenido privado.
- `token`: requerido.

**Respuesta:**
{ "items": [...], "total": ..., "pages": ..., "current": ... }

== Configuración del modo Headless ==

Desde el panel de opciones:

- **Token**: visible y regenerable.
- **Modo headless**: si está activado, se bloquea todo acceso al frontend salvo:
  - `/wp-login.php`
  - `/wp-admin/`
  - `/wp-json/`
- **Redirección**: puedes redirigir tráfico no permitido a una URL externa.

== Licencia ==

GPL v2 o superior.

== Autor ==

Juank de Gorvet  
[Contactar vía WhatsApp](https://api.whatsapp.com/send/?phone=5353779424)
