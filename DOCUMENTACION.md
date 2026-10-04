# Documentación de Bridgeframe

Esta guía describe la API v1 del plugin 2.0.2. El cliente actual de GFrame requiere API v2; consulta [la revisión de compatibilidad](docs/CONTRATO-GFRAME.md).

## Base de la API

```text
https://tusitio.com/wp-json/bridgeframe/v1
```

## Autenticación

Todas las rutas requieren token.

Opciones:

### Query string

```text
?token=TU_TOKEN
```

### Header recomendado

```http
Authorization: Bearer TU_TOKEN
```

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
"https://tusitio.com/wp-json/bridgeframe/v1/html?slug=mi-articulo&type=post&fields=id,title,html,image,acf,taxonomies"
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
"https://tusitio.com/wp-json/bridgeframe/v1/list?type=post&limit=6&page=1&fields=id,title,slug,excerpt,image,date"
```

```bash
curl -H "Authorization: Bearer TU_TOKEN" \
"https://tusitio.com/wp-json/bridgeframe/v1/list?type=post&taxonomy=category&term=noticias&limit=10"
```

```bash
curl -H "Authorization: Bearer TU_TOKEN" \
"https://tusitio.com/wp-json/bridgeframe/v1/list?type=proyecto&meta_key=destacado&meta_value=1"
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
"https://tusitio.com/wp-json/bridgeframe/v1/terms?taxonomy=category&limit=20&with_total=true"
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
"https://tusitio.com/wp-json/bridgeframe/v1/menu?location=primary"
```

```bash
curl -H "Authorization: Bearer TU_TOKEN" \
"https://tusitio.com/wp-json/bridgeframe/v1/menu?slug=menu-principal"
```

```bash
curl -H "Authorization: Bearer TU_TOKEN" \
"https://tusitio.com/wp-json/bridgeframe/v1/menu?id=12&flat=true"
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
"https://tusitio.com/wp-json/bridgeframe/v1/schema"
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

Bridgeframe expone ACF solo en lectura. Los endpoints de comentarios admiten escritura y moderación con el mismo token compartido.

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

## Recomendaciones

- Usa `Authorization: Bearer`.
- Limita `fields` para no traer datos de más.
- Usa caché en producción.
- Usa `/schema` para construir clientes externos más robustos.
- Usa `location` en `/menu` cuando dependas del tema activo.
