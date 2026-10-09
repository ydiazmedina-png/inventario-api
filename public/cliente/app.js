'use strict';

const API = '/api';
const $ = (id) => document.getElementById(id);

// El token vive en sessionStorage: se borra al cerrar la pestaña.
let token = sessionStorage.getItem('token');
let modoRegistro = false;
let pagina = 1;
let totalPaginas = 1;
let editandoId = null;
let temporizadorBusqueda = null;

const dinero = new Intl.NumberFormat('es-CO', {
  style: 'currency',
  currency: 'COP',
  maximumFractionDigits: 0,
});

/** Llama a la API; lanza un Error con el mensaje (y detalles) si falla. */
async function api(ruta, { method = 'GET', body } = {}) {
  const headers = {};
  if (body !== undefined) headers['Content-Type'] = 'application/json';
  if (token) headers.Authorization = `Bearer ${token}`;

  const res = await fetch(API + ruta, {
    method,
    headers,
    body: body !== undefined ? JSON.stringify(body) : undefined,
  });
  if (res.status === 204) return null;

  const data = await res.json().catch(() => ({}));
  if (!res.ok) {
    if (res.status === 401 && token) cerrarSesion();
    const detalles = data.detalles ? ' ' + Object.values(data.detalles).join(' ') : '';
    throw new Error((data.error || 'Ocurrió un error.') + detalles);
  }
  return data;
}

function mostrar(el, texto) {
  el.textContent = texto;
  el.hidden = false;
}

function celda(texto, clase) {
  const td = document.createElement('td');
  td.textContent = texto;
  if (clase) td.className = clase;
  return td;
}

/* ---------- Acceso ---------- */

function actualizarModoAcceso() {
  $('campo-nombre').hidden = !modoRegistro;
  $('acceso-nombre').required = modoRegistro;
  $('acceso-enviar').textContent = modoRegistro ? 'Crear cuenta' : 'Entrar';
  $('alternar-modo').textContent = modoRegistro
    ? '¿Ya tienes cuenta? Inicia sesión'
    : '¿No tienes cuenta? Regístrate';
  $('acceso-error').hidden = true;
}

$('alternar-modo').addEventListener('click', (ev) => {
  ev.preventDefault();
  modoRegistro = !modoRegistro;
  actualizarModoAcceso();
});

$('form-acceso').addEventListener('submit', async (ev) => {
  ev.preventDefault();
  const email = $('acceso-email').value;
  const password = $('acceso-password').value;
  try {
    if (modoRegistro) {
      await api('/auth/register', {
        method: 'POST',
        body: { nombre: $('acceso-nombre').value, email, password },
      });
    }
    const data = await api('/auth/login', { method: 'POST', body: { email, password } });
    token = data.token;
    sessionStorage.setItem('token', token);
    $('form-acceso').reset();
    entrar();
  } catch (error) {
    mostrar($('acceso-error'), error.message);
  }
});

function entrar() {
  $('vista-acceso').hidden = true;
  $('vista-app').hidden = false;
  pagina = 1;
  refrescar();
}

async function cerrarSesion() {
  const anterior = token;
  token = null;
  sessionStorage.removeItem('token');
  $('vista-app').hidden = true;
  $('vista-acceso').hidden = false;
  if (anterior) {
    // Invalida el token en el servidor (si falla, no importa: ya salimos).
    fetch(`${API}/auth/logout`, {
      method: 'POST',
      headers: { Authorization: `Bearer ${anterior}` },
    }).catch(() => {});
  }
}

$('btn-salir').addEventListener('click', cerrarSesion);

/* ---------- Resumen y listado ---------- */

async function cargarResumen() {
  const { resumen } = await api('/resumen');
  const items = [
    ['Productos', resumen.productos],
    ['Unidades', resumen.unidades],
    ['Valor del inventario', dinero.format(resumen.valor_inventario)],
    [`Stock bajo (≤ ${resumen.umbral_stock_bajo})`, resumen.stock_bajo],
  ];
  $('resumen').replaceChildren(
    ...items.map(([titulo, valor]) => {
      const div = document.createElement('div');
      div.className = 'stat';
      const v = document.createElement('strong');
      v.textContent = valor;
      const t = document.createElement('span');
      t.textContent = titulo;
      div.append(v, t);
      return div;
    })
  );
}

async function cargarProductos() {
  const q = $('buscar').value.trim();
  const params = new URLSearchParams({ pagina, por_pagina: 8 });
  if (q) params.set('q', q);

  const { datos, paginacion } = await api(`/productos?${params}`);
  totalPaginas = Math.max(1, paginacion.paginas);

  $('tabla').replaceChildren(
    ...datos.map((p) => {
      const tr = document.createElement('tr');
      tr.append(
        celda(p.sku),
        celda(p.nombre),
        celda(p.categoria || '—'),
        celda(dinero.format(p.precio), 'num'),
        celda(p.stock, 'num')
      );

      const editar = document.createElement('button');
      editar.type = 'button';
      editar.className = 'ghost';
      editar.textContent = 'Editar';
      editar.addEventListener('click', () => abrirDialogo(p));

      const borrar = document.createElement('button');
      borrar.type = 'button';
      borrar.className = 'danger';
      borrar.textContent = 'Eliminar';
      borrar.addEventListener('click', () => eliminar(p));

      const td = document.createElement('td');
      td.className = 'acciones';
      td.append(editar, borrar);
      tr.append(td);
      return tr;
    })
  );

  $('vacio').hidden = datos.length > 0;
  $('pagina-info').textContent = `Página ${paginacion.pagina} de ${totalPaginas} · ${paginacion.total} productos`;
  $('btn-anterior').disabled = pagina <= 1;
  $('btn-siguiente').disabled = pagina >= totalPaginas;
}

async function refrescar() {
  try {
    await Promise.all([cargarResumen(), cargarProductos()]);
  } catch (error) {
    mostrar($('mensaje'), error.message);
  }
}

$('buscar').addEventListener('input', () => {
  clearTimeout(temporizadorBusqueda); // espera a que termine de escribir
  temporizadorBusqueda = setTimeout(() => {
    pagina = 1;
    refrescar();
  }, 300);
});
$('btn-anterior').addEventListener('click', () => { pagina--; refrescar(); });
$('btn-siguiente').addEventListener('click', () => { pagina++; refrescar(); });

/* ---------- Crear, editar y eliminar ---------- */

function abrirDialogo(producto) {
  editandoId = producto ? producto.id : null;
  $('dialogo-titulo').textContent = producto ? 'Editar producto' : 'Nuevo producto';
  $('p-nombre').value = producto?.nombre ?? '';
  $('p-sku').value = producto?.sku ?? '';
  $('p-categoria').value = producto?.categoria ?? '';
  $('p-precio').value = producto?.precio ?? 0;
  $('p-stock').value = producto?.stock ?? 0;
  $('dialogo-error').hidden = true;
  $('dialogo').showModal();
}

$('btn-nuevo').addEventListener('click', () => abrirDialogo(null));
$('p-cancelar').addEventListener('click', () => $('dialogo').close());

$('form-producto').addEventListener('submit', async (ev) => {
  // method="dialog" cierra el diálogo solo; lo evitamos hasta que la API responda bien.
  ev.preventDefault();
  const body = {
    nombre: $('p-nombre').value,
    sku: $('p-sku').value,
    categoria: $('p-categoria').value,
    precio: Number($('p-precio').value),
    stock: Number($('p-stock').value),
  };
  try {
    if (editandoId === null) {
      await api('/productos', { method: 'POST', body });
    } else {
      await api(`/productos/${editandoId}`, { method: 'PUT', body });
    }
    $('dialogo').close();
    await refrescar();
  } catch (error) {
    mostrar($('dialogo-error'), error.message);
  }
});

async function eliminar(producto) {
  if (!confirm(`¿Eliminar "${producto.nombre}"?`)) return;
  try {
    await api(`/productos/${producto.id}`, { method: 'DELETE' });
    if (pagina > 1 && $('tabla').children.length === 1) pagina--;
    await refrescar();
  } catch (error) {
    mostrar($('mensaje'), error.message);
  }
}

/* ---------- Inicio ---------- */

actualizarModoAcceso();
if (token) entrar();
