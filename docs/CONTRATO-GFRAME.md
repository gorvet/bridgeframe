# Contrato 2.0 de Bridgeframe y GFrame

Implementado en Bridgeframe 2.1.0 el 4 de octubre de 2026. Contrastado con el cliente local `src/GFrame/Headless/WordPressClient.php` de `C:/xampp/htdocs/gframe-framework`. El framework no se modifica.

## API v2

Namespace: `/wp-json/bridgeframe/v2`. Endpoints de lectura: `GET /html`, `/list`, `/terms`, `/menu` y `/schema`. También se publican `GET /comments`, `POST /comments` y `POST /comments/{id}/moderate`.

La autenticación se ejecuta en `permission_callback`, exclusivamente mediante `Authorization: Bearer`. La cabecera opcional `X-BridgeFrame-Contract` acepta solamente `2.0`; GFrame la envía en todas sus peticiones. Las respuestas incluyen `status`, `code`, `data`, `meta.contract_version=2.0` y `meta.request_id`. Los datos recuperados de caché reciben un identificador nuevo por solicitud.

```json
{
  "status": "success",
  "code": "content_loaded",
  "data": { "id": 7, "html": "<p>Contenido</p>" },
  "meta": { "contract_version": "2.0", "request_id": "identificador-unico" }
}
```

| Operación | Scope |
| --- | --- |
| Contenido, listados, términos, menús y esquema | `content.read` |
| Incluir contenido privado | `content.read` y `private.read` |
| Crear comentarios | `comments.write` |
| Consultar y moderar comentarios | `comments.moderate` |

Las credenciales se almacenan mediante hash SHA-256 de tokens aleatorios de 256 bits. Cada consumidor tiene nombre, scopes, caducidad y estado de revocación. La rotación sustituye el hash e invalida inmediatamente el token anterior; conserva scopes y caducidad. La administración exige `manage_options` y nonce.

| HTTP | Código representativo |
| --- | --- |
| 400 | `invalid_request`, `invalid_content_type`, `invalid_taxonomy`, `contract_mismatch` |
| 401 | `invalid_token` |
| 403 | `insufficient_scope` |
| 404 | `content_not_found`, `menu_not_found`, `comment_not_found` |
| 429 | `rate_limited` |
| 500 | `internal_error` |

Los errores usan el mismo sobre y `data.message`. El cliente GFrame transforma estos códigos en sus códigos locales. El transporte de GFrame exige HTTPS y verifica certificado y host. El plugin conserva el soporte de WordPress para pruebas locales HTTP; la configuración de HTTPS corresponde al servidor.

## Migración desde 2.0.2

Al cargar el plugin, el token anterior se convierte a hash y se elimina la opción que lo almacenaba en claro. Ese mismo token se registra como consumidor «Migrado de v1», con `content.read` para v2 y sin caducidad, para permitir configurar GFrame sin perder la credencial existente. No obtiene permiso privado ni de comentarios en v2.

Las rutas v1 se conservan para consumidores anteriores y mantienen sus permisos originales con el token migrado. Las credenciales nuevas de v2 no son aceptadas por v1, por lo que no permiten eludir los scopes. Revocar o rotar el consumidor migrado invalida también su acceso v1. Generar la credencial principal invalida tanto la principal anterior como la migrada.

Para completar la transición de una aplicación:

1. Crea un consumidor con caducidad y los scopes necesarios en `Ajustes > Bridgeframe`.
2. Copia la credencial mostrada una sola vez a `WORDPRESS_HEADLESS_TOKEN`.
3. Configura `WORDPRESS_HEADLESS_URL` con la URL HTTPS del sitio, sin añadir el namespace.
4. Cambia los consumidores anteriores a v2 y revoca el consumidor migrado cuando ya no utilicen v1.

## Protección de consumo

V2 limita a 120 solicitudes autorizadas por consumidor y minuto mediante incrementos atómicos en la base de datos de WordPress. Devuelve 429 con `Retry-After`. Registra metadatos de auditoría en el log de PHP y ofrece el hook `bridgeframe_audit`. La caché interna no sustituye la autorización y las respuestas HTTP autenticadas incluyen `Cache-Control: no-store`.

CORS aplica también a v2 y permite `X-BridgeFrame-Contract`. El modo backend no publica cabeceras CORS de acceso; el modo restringido solo admite los orígenes configurados. CORS no reemplaza la autenticación.

## Verificación reproducible

```sh
php tests/integration.php /ruta/wordpress/wp-load.php /ruta/gframe-framework
```

La prueba utiliza el servidor REST real de WordPress y el archivo original del cliente GFrame, con transporte inyectado hacia dicho servidor. Comprueba endpoints, sobre, permisos, caché, comentarios, credenciales, migración y límite de consumo; no realiza una conexión HTTPS por red. Debe ejecutarse en una instalación de prueba: crea y elimina contenido temporal y restaura los consumidores previos.
