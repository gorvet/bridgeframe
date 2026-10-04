# Bridgeframe

Bridgeframe convierte WordPress en una API headless para consumir contenido, taxonomías, menús y esquemas desde interfaces externas. Incluye creación, consulta y moderación de comentarios autenticadas.

La versión del plugin es **2.0.2**, pero su API es **v1**. Todavía no es compatible con el cliente actual `GFrame\Headless\WordPressClient`, que exige el contrato 2.0 en `bridgeframe/v2`. Consulta [la revisión del contrato](docs/CONTRATO-GFRAME.md).

## Características

- Autenticación por token.
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
4. Genera o copia tu token.
5. Configura caché, CORS y modo headless si lo necesitas.

## Endpoints

Base:

```text
/wp-json/bridgeframe/v1
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
- [Contrato de GFrame y diferencias pendientes](docs/CONTRATO-GFRAME.md)

## Acceso

La API v1 utiliza un token compartido. Ese token permite solicitar contenido privado y escribir o moderar comentarios; no hay scopes independientes. Consérvalo en el backend. Regenerarlo invalida el anterior. Usa HTTPS y `Authorization: Bearer` para evitar incluir credenciales en las URL.

## Requisitos

- WordPress 5.5+
- PHP 7.1+
- ACF opcional

## Licencia

GPL v2 o superior.
