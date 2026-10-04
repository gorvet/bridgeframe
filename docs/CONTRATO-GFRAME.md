# Revisión del contrato de GFrame

Fecha: 4 de octubre de 2026. Plugin revisado: 2.0.2.

Fuentes locales contrastadas en `C:/xampp/htdocs/gframe-framework`: `docs/wordpress-headless.md`, `maintenance/bridgeframe-pending.md`, `src/GFrame/Headless/WordPressClient.php` y `tests/WordPressHeadlessTest.php`. La revisión incluye el estado local del framework, que contiene cambios sin confirmar; no presupone que todo esté publicado en GitHub.

## Resultado

El plugin actual no cumple el nuevo contrato. El número de versión 2.0.2 del plugin no equivale al contrato 2.0 de la API. Esta revisión no modifica el framework ni implementa la migración del plugin.

| Aspecto | GFrame actual | Bridgeframe actual |
| --- | --- | --- |
| Namespace | `bridgeframe/v2` | `bridgeframe/v1` |
| Transporte | HTTPS con certificado y host verificados | Depende del sitio WordPress |
| Credencial | `Authorization: Bearer` | Bearer o parámetro `token` |
| Cabecera de contrato | Envía `X-BridgeFrame-Contract: 2.0` | No se valida |
| Autorización REST | Verificar en `permission_callback` | `__return_true`; se verifica dentro del callback |
| Sobre de éxito | `status`, `code`, `data`, `meta` | Estructuras diferentes por endpoint |
| Metadatos | `meta.contract_version = 2.0` y `request_id` | Ausentes |
| Lectura privada | Scope `private.read` | Token compartido y `private=true` |
| Lectura normal | Scope `content.read` | Token compartido |
| Credenciales | Hash, consumidores, revocación, rotación y expiración recomendados | Un token almacenado en claro en opciones |
| Comentarios | Scopes de escritura y moderación recomendados | El mismo token permite ambas acciones |
| Protección de consumo | Rate limiting y auditoría por consumidor recomendados | Sin control específico por consumidor |

El cliente de GFrame consulta `/html`, `/list`, `/terms`, `/menu` y `/schema`. No consume los endpoints de comentarios. Con este plugin encontrará rutas v2 inexistentes; las respuestas v1 tampoco pasarían su validación del sobre.

## Sobre esperado

```json
{
  "status": "success",
  "code": "content_loaded",
  "data": { "id": 7, "html": "<p>Contenido</p>" },
  "meta": { "contract_version": "2.0", "request_id": "identificador-unico" }
}
```

El cliente valida `status=success`, un `code` de texto, `data` y `meta` como arrays, y la versión exacta `2.0`. Aunque el contrato documentado exige `request_id`, el cliente revisado no comprueba su presencia.

Los errores deben conservar el estado HTTP y códigos estables: `invalid_token`, `insufficient_scope`, `content_not_found`, `term_not_found`, `menu_not_found`, `invalid_request`, `invalid_content_type`, `invalid_taxonomy` y `rate_limited`. GFrame los transforma en sus códigos locales.

## Trabajo pendiente para la migración

1. Implementar los cinco endpoints v2 con autorización previa y autenticación exclusivamente Bearer.
2. Homogeneizar éxitos y errores, incluida la versión del contrato y un identificador por solicitud, también al recuperar caché.
3. Separar lectura pública y privada mediante scopes y proteger los comentarios con permisos propios.
4. Incorporar credenciales por consumidor con hash, expiración, rotación y revocación; definir una migración explícita del token actual.
5. Añadir rate limiting y auditoría sin registrar credenciales.
6. Actualizar CORS para el namespace v2 y la cabecera de contrato.
7. Verificar autorización, caché, errores y respuestas con WordPress real y el cliente de GFrame. Decidir el período de coexistencia de v1 antes de retirarla.

## Configuración futura del consumidor

```dotenv
WORDPRESS_HEADLESS_URL="https://cms.example.com"
WORDPRESS_HEADLESS_TOKEN="credencial-con-content.read"
```

Esta configuración funcionará cuando el plugin implemente v2. La URL identifica el sitio WordPress, sin añadir `/wp-json/bridgeframe/v2`. El token se conserva en el servidor de GFrame.
