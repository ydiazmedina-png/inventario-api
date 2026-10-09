# Inventario API

API REST para gestionar un inventario de productos, construida con **PHP (sin frameworks)** y **MySQL**, con autenticación por token y un **cliente web en JavaScript** que la consume.

Proyecto personal para practicar el diseño de una API desde cero: enrutamiento, autenticación, validación, paginación, códigos HTTP correctos y pruebas.

## Características

- Registro e inicio de sesión con **tokens Bearer** (con vencimiento y cierre de sesión).
- CRUD de productos con **búsqueda**, **filtro por categoría** y **paginación**.
- Endpoint de **resumen**: total de productos, unidades, valor del inventario y productos con stock bajo.
- Cada usuario solo accede a **sus propios productos**.
- Cliente web sencillo (`/cliente`) que usa `fetch` contra la API.
- Pruebas de humo automáticas (30 verificaciones) con `curl`.

## Tecnologías

PHP 8 (PDO) · MySQL / MariaDB · JavaScript (`fetch`) · HTML y CSS

## Decisiones de diseño

- **Router propio** (`src/Router.php`): rutas con parámetros (`/productos/{id}`), y respuestas `404` y `405` distintas.
- **Errores como excepciones** (`ApiException`): los controladores lanzan el error y un único punto lo convierte en JSON con su código HTTP.
- **Tokens**: se generan con `random_bytes` y en la base de datos solo se guarda su hash SHA-256. Si la base se filtra, los tokens reales no quedan expuestos.
- **SQL seguro**: consultas preparadas en todas partes. Los nombres de columnas de los `UPDATE` salen de una lista blanca, nunca del usuario.
- **Login que no revela correos**: mismo mensaje y mismo costo de cálculo si el correo no existe o la clave es incorrecta.
- **Aislamiento**: toda consulta filtra por `usuario_id`; pedir un recurso ajeno devuelve `404`.
- **Validación** en el servidor, con errores por campo (`422`) y SKU duplicado (`409`).

## Estructura

```
inventario-api/
├── database/schema.sql          # usuarios, tokens y productos
├── src/
│   ├── Http.php                 # Request, ApiException y respond()
│   ├── Router.php               # Enrutador
│   ├── Database.php            # Conexión PDO y configuración
│   ├── AuthController.php       # Registro, login, logout, autenticación
│   └── ProductoController.php   # CRUD, búsqueda y resumen
├── public/
│   ├── index.php                # Punto de entrada de la API (/api/*)
│   ├── router-dev.php           # Router para el servidor de desarrollo
│   ├── .htaccess                # Reescritura para Apache
│   └── cliente/                 # Cliente web (HTML, CSS y JS)
├── tests/smoke.sh               # Pruebas de humo
└── config.example.php           # Plantilla de configuración
```

## Instalación

Requisitos: PHP 8.0 o superior con `pdo_mysql`, MySQL o MariaDB (por ejemplo, XAMPP) y `curl` para las pruebas.

```bash
# 1. Base de datos
mysql -u root -p < database/schema.sql

# 2. Configuración (ajusta las credenciales)
cp config.example.php config.php

# 3. Servidor de desarrollo
php -S localhost:8000 -t public public/router-dev.php
```

Abre http://localhost:8000 para usar el cliente web.

## Referencia de la API

Base: `/api`. Las rutas de productos y resumen requieren `Authorization: Bearer <token>`. Todas las respuestas son JSON.

| Método | Ruta | Descripción | Códigos |
|--------|------|-------------|---------|
| `POST` | `/auth/register` | Crea una cuenta `{nombre, email, password}` | 201, 409, 422 |
| `POST` | `/auth/login` | Devuelve un token | 200, 401 |
| `POST` | `/auth/logout` | Invalida el token | 200 |
| `GET` | `/productos` | Lista. Parámetros: `q`, `categoria`, `pagina`, `por_pagina` (máx. 100) | 200 |
| `POST` | `/productos` | Crea un producto | 201, 409, 422 |
| `GET` | `/productos/{id}` | Detalle | 200, 404 |
| `PUT` | `/productos/{id}` | Reemplazo completo | 200, 404, 409, 422 |
| `PATCH` | `/productos/{id}` | Actualización parcial | 200, 404, 409, 422 |
| `DELETE` | `/productos/{id}` | Elimina | 204, 404 |
| `GET` | `/resumen` | Totales del inventario | 200 |

### Ejemplo

```bash
# Iniciar sesión
curl -X POST http://localhost:8000/api/auth/login \
  -H "Content-Type: application/json" \
  -d '{"email":"ana@test.com","password":"clave12345"}'

# Crear un producto
curl -X POST http://localhost:8000/api/productos \
  -H "Authorization: Bearer TU_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"nombre":"Teclado","sku":"TEC-001","categoria":"Periféricos","precio":85000,"stock":12}'
```

Respuesta de un listado:

```json
{
  "datos": [
    { "id": 1, "nombre": "Teclado", "sku": "TEC-001", "categoria": "Periféricos",
      "precio": 85000, "stock": 12, "creado_en": "2026-10-09 09:23:10", "actualizado_en": "2026-10-09 09:23:10" }
  ],
  "paginacion": { "pagina": 1, "por_pagina": 10, "total": 1, "paginas": 1 }
}
```

## Pruebas

Con el servidor en marcha:

```bash
bash tests/smoke.sh http://localhost:8000/api
```

Verifica registro, login, acceso protegido, CRUD completo, búsqueda, paginación, resumen, aislamiento entre usuarios, cierre de sesión y códigos de error.

## Posibles mejoras

- Limitar intentos de inicio de sesión.
- Documentación OpenAPI/Swagger.
- Pruebas con PHPUnit y GitHub Actions.
- Movimientos de inventario (entradas y salidas) con historial.
- Contenedor Docker.

## Autora

**Yuliana Díaz Medina** – Ingeniera de Sistemas · yulianadiazmedina22@gmail.com
