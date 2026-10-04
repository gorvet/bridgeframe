# Documentación de Bridgeframe

Esta guía describe la API v2 del plugin 2.1.0 y el contrato 2.0 de Bridgeframe para cualquier aplicación consumidora. Consulta [el contrato y la migración](docs/CONTRATO-BRIDGEFRAME.md).

## Base de la API

```text
https://tusitio.com/wp-json/bridgeframe/v2
```

## Autenticación

Todas las rutas requieren token.

Usa exclusivamente la cabecera Bearer. El parámetro `token` de las URL no autentica solicitudes v2.

```http
Authorization: Bearer TU_TOKEN
X-BridgeFrame-Contract: 2.0
```

Crea credenciales en `Ajustes > Bridgeframe`, con nombre, permisos y caducidad. Solo se muestran al crearlas o rotarlas; se almacenan mediante SHA-256. Una rotación invalida la credencial anterior y una revocación bloquea al consumidor.

- `content.read`: contenido público, listados, términos, menús y esquema.
- `private.read`: permiso adicional para `private=true`; también exige `content.read`.
- `comments.write`: crear comentarios.
- `comments.moderate`: consultar y moderar comentarios.

Las respuestas usan `status`, `code`, `data` y `meta`. Los ejemplos de datos de esta guía corresponden al interior de `data`:

```json
{
  "status": "success",
  "code": "content_loaded",
  "data": { "id": 7, "html": "<p>Contenido</p>" },
  "meta": { "contract_version": "2.0", "request_id": "identificador-unico" }
}
```

Los errores usan el mismo sobre, con `status=error` y `data.message`, conservando el estado HTTP. Credenciales inválidas devuelven 401; permisos insuficientes, 403; recursos ausentes, 404; exceso de consumo, 429 con `Retry-After`. Si se envía `X-BridgeFrame-Contract`, debe ser `2.0`.

## Endpoints

### `GET /html`

Obtiene un contenido por `id` o `slug`.

Parámetros principales:

- `id`
- `slug`
- `type`
- `format=html|json`
- `fields`
- `acf_fields`
- `lang`
- `private=true`

Campos disponibles en `fields`:

```text
id,title,slug,type,status,date,modified,link,excerpt,html,image,meta,acf,taxonomies,private
```

Ejemplo:

```bash
curl -H "Authorization: Bearer TU_TOKEN" \
"https://tusitio.com/wp-json/bridgeframe/v2/html?slug=mi-articulo&type=post&fields=id,title,html,image,acf,taxonomies"
```

### `GET /list`

Lista contenidos con filtros avanzados.

Parámetros principales:

- `type`
- `limit`
- `page`
- `s`
- `fields`
- `acf_fields`
- `orderby`
- `order`
- `ids`
- `exclude`
- `author`
- `parent`
- `taxonomy`
- `term`
- `terms`
- `tax_query`
- `meta_key`
- `meta_value`
- `meta_compare`
- `meta_type`
- `meta_query`
- `date_after`
- `date_before`
- `modified_after`
- `modified_before`
- `private=true`

Ejemplos:

```bash
curl -H "Authorization: Bearer TU_TOKEN" \
"https://tusitio.com/wp-json/bridgeframe/v2/list?type=post&limit=6&page=1&fields=id,title,slug,excerpt,image,date"
```

```bash
curl -H "Authorization: Bearer TU_TOKEN" \
"https://tusitio.com/wp-json/bridgeframe/v2/list?type=post&taxonomy=category&term=noticias&limit=10"
```

```bash
curl -H "Authorization: Bearer TU_TOKEN" \
"https://tusitio.com/wp-json/bridgeframe/v2/list?type=proyecto&meta_key=destacado&meta_value=1"
```

Respuesta:

```json
{
  "items": [],
  "total": 24,
  "pages": 3,
  "current": 1
}
```

### `GET /terms`

Lista términos de una taxonomía.

Parámetros principales:

- `taxonomy`
- `limit`
- `page`
- `hide_empty`
- `fields`
- `s`
- `parent`
- `orderby`
- `order`
- `with_total`
- `include`
- `exclude`

Ejemplo:

```bash
curl -H "Authorization: Bearer TU_TOKEN" \
"https://tusitio.com/wp-json/bridgeframe/v2/terms?taxonomy=category&limit=20&with_total=true"
```

### `GET /menu`

Obtiene un menú por ubicación, slug o ID.

Parámetros principales:

- `location`
- `slug`
- `id`
- `flat=true` para devolver la lista plana

Uso recomendado:

- `location` cuando el tema registra ubicaciones como `primary`, `footer`, `mobile`
- `slug` o `id` cuando quieres un menú concreto

Ejemplos:

```bash
curl -H "Authorization: Bearer TU_TOKEN" \
"https://tusitio.com/wp-json/bridgeframe/v2/menu?location=primary"
```

```bash
curl -H "Authorization: Bearer TU_TOKEN" \
"https://tusitio.com/wp-json/bridgeframe/v2/menu?slug=menu-principal"
```

```bash
curl -H "Authorization: Bearer TU_TOKEN" \
"https://tusitio.com/wp-json/bridgeframe/v2/menu?id=12&flat=true"
```

Respuesta:

```json
{
  "menu": {
    "id": 12,
    "slug": "menu-principal",
    "name": "Menú principal",
    "count": 6
  },
  "items": [
    {
      "id": 101,
      "title": "Inicio",
      "url": "https://tusitio.com/",
      "target": "",
      "description": "",
      "attr_title": "",
      "parent": 0,
      "order": 1,
      "object_id": 25,
      "object": "page",
      "type": "post_type",
      "classes": [],
      "children": []
    }
  ]
}
```

### `GET /schema`

Devuelve información para descubrir cómo consumir la API.

Parámetros:

- `type` opcional

Devuelve:

- `contentFields`
- `filters`
- `imageSizes`
- `menuLocations`
- `types`
- taxonomías por tipo
- grupos y campos ACF por tipo si ACF está activo

Ejemplo:

```bash
curl -H "Authorization: Bearer TU_TOKEN" \
"https://tusitio.com/wp-json/bridgeframe/v2/schema"
```

## Caché

Desde `Ajustes > Bridgeframe` puedes:

- activar o desactivar caché
- definir TTL
- limpiar caché manualmente

La caché se invalida cuando:

- guardas contenido
- borras contenido
- cambias términos
- ACF guarda cambios

## Modo de consumo

Modos disponibles:

- `Abierto con token`
- `Frontend desde dominios permitidos`
- `Solo backend con token, sin CORS`

Todos los modos exigen token. Si el consumo se hace directo desde navegador, usa `Frontend desde dominios permitidos` y configura los orígenes permitidos.

## ACF

Bridgeframe expone ACF solo en lectura. Los endpoints de comentarios usan permisos independientes.

Soporta:

- campos simples
- imágenes
- galerías
- relaciones
- post object
- taxonomías
- grupos y estructuras anidadas como arrays

Puedes pedir todos los campos ACF:

```text
fields=acf
```

O solo algunos:

```text
fields=acf&acf_fields=hero,banner,galeria
```

## Comentarios

- `GET /comments`: exige `comments.moderate`; admite `post_id`, `slug`, `type`, `status`, `limit`, `page`, `s`, `parent`, `orderby` y `order`.
- `POST /comments`: exige `comments.write`; recibe `post_id` o `slug`, `author_name`, `author_email`, `content` y, opcionalmente, `author_url`, `parent` y `user_id`. El contenido debe estar publicado y admitir comentarios. Devuelve HTTP 201; WordPress decide si queda aprobado o pendiente.
- `POST /comments/{id}/moderate`: exige `comments.moderate`; recibe `action=approve|hold|reject|trash|spam|delete`.

## Consumo y auditoría

La API v2 admite 120 solicitudes autorizadas por consumidor y minuto. Superar el límite devuelve HTTP 429 y `Retry-After`. La autorización se evalúa antes de devolver datos de caché. Cada respuesta genera un `request_id` propio y usa `Cache-Control: no-store` para impedir que un intermediario comparta respuestas autenticadas.

Las solicitudes v2 se registran en el log de PHP con el prefijo `[Bridgeframe]`, consumidor, ruta, código, fecha e identificador de solicitud. No se registran tokens ni cuerpos. El hook `bridgeframe_audit` permite enviar esos metadatos a otro sistema.

## Recomendaciones de uso

- Usa `Authorization: Bearer`.
- Limita `fields` para no traer datos de más.
- Usa caché en producción.
- Usa `/schema` para construir clientes externos más robustos.
- Usa `location` en `/menu` cuando dependas del tema activo.
