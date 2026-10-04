=== Bridgeframe ===
Contributors: juankdegorvet
Tags: headless, REST API, frontend, token, gframe
Requires at least: 5.5
Tested up to: 6.9.4
Requires PHP: 7.1
Stable tag: 2.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

API headless para WordPress con contenido, ACF, taxonomías, menús, comentarios y credenciales por consumidor.

== Descripción ==

Bridgeframe 2.1.0 implementa el contrato 2.0 de GFrame bajo /wp-json/bridgeframe/v2.

- Lectura de contenido HTML/JSON, ACF, metadatos, taxonomías e imágenes.
- Consulta de menús y esquema.
- Creación y moderación de comentarios con scopes separados.
- Credenciales Bearer almacenadas mediante hash, con caducidad, rotación y revocación.
- Caché interna, límite de consumo y auditoría por consumidor.
- Modo headless y configuración de CORS.

== Instalación ==

1. Copia bridgeframe a wp-content/plugins/.
2. Activa el plugin.
3. Abre Ajustes > Bridgeframe.
4. Crea un consumidor con los permisos necesarios y copia su credencial.
5. Configura tu consumidor mediante HTTPS y Authorization: Bearer.

== API v2 ==

Base: /wp-json/bridgeframe/v2

GET /html, /list, /terms, /menu y /schema requieren content.read.
private=true exige además private.read.
POST /comments requiere comments.write.
GET /comments y POST /comments/{id}/moderate requieren comments.moderate.

La cabecera X-BridgeFrame-Contract, si se envía, debe ser 2.0.
El parámetro token de las URL no autentica solicitudes v2.
Las respuestas incluyen status, code, data y meta con contract_version y request_id.
El límite es de 120 solicitudes por consumidor y minuto.

== Migración ==

El token de versiones anteriores se convierte a hash y se elimina su almacenamiento en claro. Permite lectura pública v2. Las rutas v1 conservan los permisos originales solo para ese token migrado. Las credenciales nuevas de v2 no funcionan en v1.

Rotar o revocar el consumidor migrado invalida también su acceso v1. Crea credenciales por consumidor para completar la migración.

== Documentación ==

https://github.com/gorvet/bridgeframe

Consulta README.md, DOCUMENTACION.md y docs/CONTRATO-GFRAME.md en el repositorio.

== Changelog ==

= 2.1.0 =
API v2 compatible con GFrame, scopes, consumidores, hash, caducidad, rotación, revocación, rate limiting, auditoría y pruebas de integración.

= 2.0.2 =
Publicación inicial de la API v1 en GitHub.
