#!/usr/bin/env bash
# Prueba de humo de la API. Requiere el servidor en marcha y curl.
#   php -S localhost:8000 -t public public/router-dev.php
#   bash tests/smoke.sh [http://localhost:8000]
#
# Crea usuarios de prueba con correos únicos en cada ejecución.

BASE="${1:-http://localhost:8000}"
RUN="$(date +%s)$RANDOM"
FALLOS=0
TOTAL=0

# check <descripción> <esperado> <obtenido>
check() {
  TOTAL=$((TOTAL + 1))
  if [ "$2" = "$3" ]; then
    echo "  ok   $1"
  else
    echo "  FALLA $1 (esperado: $2, obtenido: $3)"
    FALLOS=$((FALLOS + 1))
  fi
}

# req <método> <ruta> <token|-> [json]   -> imprime "<código> <cuerpo>"
req() {
  local method="$1" path="$2" token="$3" body="$4"
  local args=(-s -w '\n%{http_code}' -X "$method" "$BASE$path")
  [ "$token" != "-" ] && args+=(-H "Authorization: Bearer $token")
  [ -n "$body" ] && args+=(-H 'Content-Type: application/json' -d "$body")
  local out; out="$(curl "${args[@]}")"
  echo "$(echo "$out" | tail -n1) $(echo "$out" | sed '$d')"
}
code() { echo "$1" | cut -d' ' -f1; }
body() { echo "$1" | cut -d' ' -f2-; }
campo() { echo "$1" | grep -oE "\"$2\":\"?[^,\"}]*" | head -1 | sed -E "s/\"$2\":\"?//"; }

echo "== Autenticación"
r=$(req POST /auth/register - "{\"nombre\":\"Ana\",\"email\":\"ana$RUN@test.com\",\"password\":\"clave12345\"}")
check "registro devuelve 201" 201 "$(code "$r")"
r=$(req POST /auth/register - "{\"nombre\":\"Ana\",\"email\":\"ana$RUN@test.com\",\"password\":\"clave12345\"}")
check "correo repetido devuelve 409" 409 "$(code "$r")"
r=$(req POST /auth/register - '{"nombre":"","email":"mal","password":"123"}')
check "datos inválidos devuelven 422" 422 "$(code "$r")"
r=$(req POST /auth/login - "{\"email\":\"ana$RUN@test.com\",\"password\":\"incorrecta\"}")
check "clave incorrecta devuelve 401" 401 "$(code "$r")"
r=$(req POST /auth/login - "{\"email\":\"ana$RUN@test.com\",\"password\":\"clave12345\"}")
check "login devuelve 200" 200 "$(code "$r")"
TOKEN="$(campo "$(body "$r")" token)"
check "login entrega un token de 64 caracteres" 64 "${#TOKEN}"

echo "== Acceso protegido"
r=$(req GET /productos -); check "sin token devuelve 401" 401 "$(code "$r")"
r=$(req GET /productos "tokenfalso123"); check "token falso devuelve 401" 401 "$(code "$r")"

echo "== Productos (CRUD)"
r=$(req POST /productos "$TOKEN" '{"nombre":"Teclado","sku":"TEC-001","categoria":"Perif\u00e9ricos","precio":85000,"stock":12}')
check "crear devuelve 201" 201 "$(code "$r")"
ID="$(campo "$(body "$r")" id)"
r=$(req POST /productos "$TOKEN" '{"nombre":"Otro","sku":"TEC-001","precio":1,"stock":1}')
check "SKU repetido devuelve 409" 409 "$(code "$r")"
r=$(req POST /productos "$TOKEN" '{"nombre":"","sku":"x y","precio":-5,"stock":1.5}')
check "validación devuelve 422" 422 "$(code "$r")"
r=$(req POST /productos "$TOKEN" '{"nombre":"Mouse","sku":"MOU-001","categoria":"Perif\u00e9ricos","precio":40000,"stock":3}')
check "crear segundo producto" 201 "$(code "$r")"
r=$(req GET "/productos/$ID" "$TOKEN"); check "ver uno devuelve 200" 200 "$(code "$r")"
r=$(req PATCH "/productos/$ID" "$TOKEN" '{"stock":20}')
check "PATCH parcial devuelve 200" 200 "$(code "$r")"
check "PATCH actualizó el stock" 20 "$(campo "$(body "$r")" stock)"
r=$(req PUT "/productos/$ID" "$TOKEN" '{"stock":1}')
check "PUT incompleto devuelve 422" 422 "$(code "$r")"
r=$(req GET "/productos?q=mou" "$TOKEN")
check "búsqueda por texto devuelve 200" 200 "$(code "$r")"
check "búsqueda encontró 1 producto" 1 "$(campo "$(body "$r")" total)"
r=$(req GET "/productos?por_pagina=1&pagina=2" "$TOKEN")
check "paginación: 2 páginas en total" 2 "$(campo "$(body "$r")" paginas)"
r=$(req GET /resumen "$TOKEN")
check "resumen: 2 productos" 2 "$(campo "$(body "$r")" productos)"
check "resumen: 1 con stock bajo" 1 "$(campo "$(body "$r")" stock_bajo)"

echo "== Aislamiento entre usuarios"
req POST /auth/register - "{\"nombre\":\"Beto\",\"email\":\"beto$RUN@test.com\",\"password\":\"clave12345\"}" >/dev/null
r=$(req POST /auth/login - "{\"email\":\"beto$RUN@test.com\",\"password\":\"clave12345\"}")
TOKEN2="$(campo "$(body "$r")" token)"
r=$(req GET "/productos/$ID" "$TOKEN2"); check "otro usuario no ve el producto (404)" 404 "$(code "$r")"
r=$(req DELETE "/productos/$ID" "$TOKEN2"); check "otro usuario no puede borrarlo (404)" 404 "$(code "$r")"
r=$(req GET /productos "$TOKEN2"); check "otro usuario ve su lista vacía" 0 "$(campo "$(body "$r")" total)"

echo "== Eliminar y cerrar sesión"
r=$(req DELETE "/productos/$ID" "$TOKEN"); check "eliminar devuelve 204" 204 "$(code "$r")"
r=$(req GET "/productos/$ID" "$TOKEN"); check "ya no existe (404)" 404 "$(code "$r")"
r=$(req POST /auth/logout "$TOKEN"); check "logout devuelve 200" 200 "$(code "$r")"
r=$(req GET /productos "$TOKEN"); check "token invalidado tras logout (401)" 401 "$(code "$r")"

echo "== Rutas"
r=$(req GET /no-existe "$TOKEN2"); check "ruta inexistente devuelve 404" 404 "$(code "$r")"
r=$(req DELETE /resumen "$TOKEN2"); check "método incorrecto devuelve 405" 405 "$(code "$r")"

echo
echo "Resultado: $((TOTAL - FALLOS))/$TOTAL pruebas correctas"
[ "$FALLOS" -eq 0 ]
