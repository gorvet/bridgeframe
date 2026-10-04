# Bridgeframe

Bridgeframe convierte WordPress en una API headless para consumir contenido, taxonomías, menús y esquemas desde interfaces externas. Incluye creación, consulta y moderación de comentarios autenticadas.

La versión **2.1.0** implementa el contrato **2.0** en `bridgeframe/v2`, compatible con `GFrame\Headless\WordPressClient`. Consulta [el contrato y la migración](docs/CONTRATO-GFRAME.md).

## Características

- Autenticación Bearer con credenciales por consumidor, scopes, caducidad, rotación y revocación.
- Endpoints de lectura para contenido, listados, términos y menús.
- Soporte de lectura para ACF.
- Caché configurable.
- CORS configurable.
- Modo headless opcional para bloquear el frontend público.
- Endpoint de esquema para descubrir tipos, taxonomías, tamaños de imagen y ACF.

## Instalación

1. Copia el plugin en `wp-content/plugins/`.
2. Actívalo desde WordPress.
3. Ve a `Ajustes > Bridgeframe`.
4. Crea una credencial de consumidor y copia el token mostrado una sola vez.
5. Configura caché, CORS y modo headless si lo necesitas.

## Endpoints

Base:

```text
/wp-json/bridgeframe/v2
```

Disponibles:

- `GET /html`
- `GET /list`
- `GET /terms`
- `GET /menu`
- `GET /schema`
- `GET /comments`
- `POST /comments`
- `POST /comments/{id}/moderate`

## Documentación

La guía completa está en:

- [DOCUMENTACION.md](DOCUMENTACION.md)
- [Contrato de GFrame y migración desde v1](docs/CONTRATO-GFRAME.md)

## Acceso

La API v2 exige `Authorization: Bearer`. Para lectura normal usa `content.read`; para incluir contenido privado añade `private.read`. Los comentarios requieren `comments.write` o `comments.moderate`. Usa HTTPS y conserva las credenciales en el backend. Se admiten 120 solicitudes por consumidor y minuto.

## GFrame

```dotenv
WORDPRESS_HEADLESS_URL="https://cms.example.com"
WORDPRESS_HEADLESS_TOKEN="credencial-del-consumidor"
```

```php
$wordpress = \GFrame\Headless\WordPressClient::fromEnvironment();
$result = $wordpress->content('mi-articulo', 'post');
```

## Verificación

En una instalación de prueba con el plugin activo:

```sh
php tests/integration.php /ruta/wordpress/wp-load.php /ruta/gframe-framework
```

La prueba crea y elimina sus contenidos y consumidores temporales. Comprueba los cinco endpoints de lectura, comentarios, permisos, caché, migración, rotación, caducidad, revocación, rate limiting y las respuestas con el cliente real de GFrame. No verifica el transporte HTTPS mediante red.

## Requisitos

- WordPress 5.5+
- PHP 7.1+
- ACF opcional

## Licencia

GPL v2 o superior.
