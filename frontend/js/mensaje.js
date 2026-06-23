document.addEventListener('DOMContentLoaded', () => {

    const listaContactos      = document.querySelectorAll('.contacto');
    const chatVacio           = document.getElementById('chat-vacio');
    const chatActivo          = document.getElementById('chat-activo');
    const chatNombre          = document.getElementById('chat-nombre');
    const chatAvatar          = document.getElementById('chat-avatar');
    const chatMensajes        = document.getElementById('chat-mensajes');
    const formMensaje         = document.getElementById('form-mensaje');
    const inputIdReceptor     = document.getElementById('input-id-receptor');
    const inputIdMensajeEdit  = document.getElementById('input-id-mensaje-edit');
    const inputTitulo         = document.getElementById('input-titulo');
    const inputMensaje        = document.getElementById('input-mensaje');
    const inputArchivo        = document.getElementById('input-archivo');
    const archivoFila         = document.getElementById('archivo-fila');
    const archivoSeleccionado = document.getElementById('archivo-seleccionado');
    const btnCancelarArchivo  = document.getElementById('btn-cancelar-archivo');
    const btnAdjuntar         = document.getElementById('btn-adjuntar');
    const btnEnviar           = document.getElementById('btn-enviar');
    const btnEnviarTexto      = document.getElementById('btn-enviar-texto');
    const formError           = document.getElementById('form-error');
    const formExito           = document.getElementById('form-exito');
    const editarBanner        = document.getElementById('editar-banner');
    const btnCancelarEdicion  = document.getElementById('btn-cancelar-edicion');

    const panelDetalles    = document.getElementById('panel-detalles');
    const detalleAvatar    = document.getElementById('detalle-avatar');
    const detalleNombre    = document.getElementById('detalle-nombre');
    const detalleRol       = document.getElementById('detalle-rol');
    const detalleEmail     = document.getElementById('detalle-email');
    const detallePhone     = document.getElementById('detalle-phone');
    const detalleCity      = document.getElementById('detalle-city');
    const detalleEmailFila = document.getElementById('detalle-email-fila');
    const detallePhoneFila = document.getElementById('detalle-phone-fila');
    const detalleCityFila  = document.getElementById('detalle-city-fila');

    const modalEliminar  = document.getElementById('modal-eliminar');
    const modalCancelar  = document.getElementById('modal-cancelar');
    const modalConfirmar = document.getElementById('modal-confirmar');

    let contactoActualId       = null;
    let intervaloActualizacion = null;
    let avisoExitoTimeout      = null;
    let modoEdicion            = false;
    let idMensajePendienteElim = null;

    const EXTENSIONES_PERMITIDAS = ['xls', 'xlsx'];

    const ICONO_EXITO   = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>';
    const ICONO_ERROR   = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12" y2="16.01"/></svg>';
    const ICONO_ARCHIVO = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>';

    function escapeHtml(texto) {
        const div = document.createElement('div');
        div.textContent = texto ?? '';
        return div.innerHTML;
    }

    function mostrarAviso(el, msg, icono) {
        el.innerHTML = `${icono}<span>${escapeHtml(msg)}</span>`;
        el.classList.add('visible');
    }

    function ocultarAviso(el) {
        el.classList.remove('visible');
        el.innerHTML = '';
    }

    function formatearFecha(fechaStr) {
        const fecha = new Date(fechaStr.replace(' ', 'T'));
        if (isNaN(fecha.getTime())) return fechaStr;
        return fecha.toLocaleString('es-PE', {
            day: '2-digit', month: '2-digit', year: 'numeric',
            hour: '2-digit', minute: '2-digit'
        });
    }

    function mostrar(el) { if (el) el.classList.remove('oculto'); }
    function ocultar(el) { if (el) el.classList.add('oculto'); }

    function setDetalleFilaValor(filaEl, spanEl, valor) {
        if (valor && valor.trim()) {
            spanEl.textContent = valor;
            mostrar(filaEl);
        } else {
            spanEl.textContent = '';
            ocultar(filaEl);
        }
    }

    function limpiarArchivo() {
        inputArchivo.value = '';
        archivoSeleccionado.textContent = '';
        ocultar(archivoFila);
        mostrar(btnAdjuntar);
    }

    function cancelarEdicion() {
        modoEdicion = false;
        inputIdMensajeEdit.value = '';
        inputTitulo.value  = '';
        inputMensaje.value = '';
        btnEnviarTexto.textContent = 'Enviar';
        editarBanner.classList.remove('visible');
        mostrar(btnAdjuntar);
        limpiarArchivo();
        ocultarAviso(formError);
        ocultarAviso(formExito);
    }

    btnCancelarEdicion.addEventListener('click', cancelarEdicion);

    btnCancelarArchivo.addEventListener('click', () => {
        limpiarArchivo();
        ocultarAviso(formError);
    });

    function renderMensaje(msg) {
        const clase       = msg.es_propio ? 'propio' : 'recibido';
        const inicialesEl = document.querySelector(`.contacto[data-id="${contactoActualId}"] .avatar`);
        const iniciales   = msg.es_propio
            ? (chatAvatar?.textContent ?? '?')
            : (inicialesEl?.textContent ?? '?');
        const avClase = msg.es_propio
            ? 'avatar-tono-0'
            : (inicialesEl?.className.match(/avatar-tono-\d/)?.[0] ?? 'avatar-tono-0');

        const accionesBtns = msg.es_propio ? `
            <div class="mensaje-acciones">
                <button class="btn-msg-accion btn-editar-msg" data-id="${msg.id_mensaje}"
                        data-titulo="${escapeHtml(msg.titulo ?? '')}"
                        data-mensaje="${escapeHtml(msg.mensaje ?? '')}"
                        title="Editar">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                </button>
                <button class="btn-msg-accion btn-eliminar-msg" data-id="${msg.id_mensaje}" title="Eliminar">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/></svg>
                </button>
            </div>` : '';

        let html = `<div class="mensaje-fila ${clase}" data-msg-id="${msg.id_mensaje}">
            <div class="avatar-mini ${avClase}">${escapeHtml(iniciales)}</div>
            <div class="mensaje-wrapper">
                ${accionesBtns}
                <div class="mensaje ${clase}">`;

        if (msg.titulo) {
            html += `<div class="mensaje-titulo">${escapeHtml(msg.titulo)}</div>`;
        }
        if (msg.mensaje) {
            html += `<div class="mensaje-texto">${escapeHtml(msg.mensaje).replace(/\n/g, '<br>')}</div>`;
        }
        if (msg.archivo) {
            html += `<a class="mensaje-adjunto" href="../uploads/${encodeURIComponent(msg.archivo)}" download target="_blank" rel="noopener">${ICONO_ARCHIVO}<span>Descargar Excel</span></a>`;
        }
        html += `<div class="mensaje-fecha">${formatearFecha(msg.fecha_y_hora)}</div>`;
        html += `</div></div></div>`;
        return html;
    }

    async function cargarMensajes(idContacto) {
        try {
            const resp = await fetch(`mensaje.php?accion=listar_mensajes&id_contacto=${idContacto}`);
            const data = await resp.json();
            if (!data.ok) return;

            chatMensajes.innerHTML = data.mensajes.length
                ? '<div class="fecha-separador">Hoy</div>' + data.mensajes.map(renderMensaje).join('')
                : '<p class="sin-mensajes">Aún no hay mensajes con este usuario.</p>';

            chatMensajes.scrollTop = chatMensajes.scrollHeight;
            registrarEventosMensajes();
        } catch (err) {
            console.error('Error al cargar mensajes:', err);
        }
    }

    function registrarEventosMensajes() {
        chatMensajes.querySelectorAll('.btn-editar-msg').forEach(btn => {
            btn.addEventListener('click', () => {
                const id      = btn.dataset.id;
                const titulo  = btn.dataset.titulo;
                const mensaje = btn.dataset.mensaje;

                modoEdicion = true;
                inputIdMensajeEdit.value = id;
                inputTitulo.value        = titulo;
                inputMensaje.value       = mensaje;
                btnEnviarTexto.textContent = 'Guardar';
                editarBanner.classList.add('visible');
                ocultar(btnAdjuntar);
                limpiarArchivo();
                inputMensaje.focus();
            });
        });

        chatMensajes.querySelectorAll('.btn-eliminar-msg').forEach(btn => {
            btn.addEventListener('click', () => {
                idMensajePendienteElim = btn.dataset.id;
                mostrar(modalEliminar);
            });
        });
    }

    modalCancelar.addEventListener('click', () => {
        idMensajePendienteElim = null;
        ocultar(modalEliminar);
    });

    modalConfirmar.addEventListener('click', async () => {
        if (!idMensajePendienteElim) return;
        ocultar(modalEliminar);

        try {
            const fd = new FormData();
            fd.append('accion', 'eliminar_mensaje');
            fd.append('id_mensaje', idMensajePendienteElim);

            const resp = await fetch('mensaje.php', { method: 'POST', body: fd });
            const data = await resp.json();

            if (data.ok) {
                await cargarMensajes(contactoActualId);
            } else {
                mostrarAviso(formError, data.error || 'No se pudo eliminar el mensaje.', ICONO_ERROR);
            }
        } catch (err) {
            console.error('Error al eliminar:', err);
            mostrarAviso(formError, 'Error de conexión.', ICONO_ERROR);
        } finally {
            idMensajePendienteElim = null;
        }
    });

    modalEliminar.addEventListener('click', (e) => {
        if (e.target === modalEliminar) {
            idMensajePendienteElim = null;
            ocultar(modalEliminar);
        }
    });

    function seleccionarContacto(elemento) {
        listaContactos.forEach(c => c.classList.remove('activo'));
        elemento.classList.add('activo');

        contactoActualId = elemento.dataset.id;

        const nombre = elemento.dataset.nombre || '';
        const rol    = elemento.dataset.rol    || '';
        const email  = elemento.dataset.email  || '';
        const phone  = elemento.dataset.phone  || '';
        const city   = elemento.dataset.city   || '';

        ocultar(chatVacio);
        mostrar(chatActivo);

        chatNombre.textContent = nombre;

        const iniciales   = nombre.split(' ').map(p => p[0]).slice(0, 2).join('').toUpperCase();
        const avatarClase = elemento.querySelector('.avatar').className;
        chatAvatar.textContent = iniciales;
        chatAvatar.className   = avatarClase;

        inputIdReceptor.value = contactoActualId;
        cancelarEdicion();
        ocultarAviso(formError);
        ocultarAviso(formExito);

        if (panelDetalles) {
            mostrar(panelDetalles);
            detalleAvatar.textContent   = iniciales;
            detalleAvatar.className     = avatarClase;
            detalleAvatar.style.cssText = 'width:44px;height:44px;font-size:15px;';
            detalleNombre.textContent   = nombre;
            detalleRol.textContent      = rol;
            setDetalleFilaValor(detalleEmailFila, detalleEmail, email);
            setDetalleFilaValor(detallePhoneFila, detallePhone, phone);
            setDetalleFilaValor(detalleCityFila,  detalleCity,  city);
        }

        cargarMensajes(contactoActualId);

        if (intervaloActualizacion) clearInterval(intervaloActualizacion);
        intervaloActualizacion = setInterval(() => cargarMensajes(contactoActualId), 5000);
    }

    listaContactos.forEach(el => {
        el.addEventListener('click', () => seleccionarContacto(el));
    });

    inputArchivo.addEventListener('change', () => {
        const archivo = inputArchivo.files[0];
        ocultarAviso(formError);

        if (!archivo) { limpiarArchivo(); return; }

        const ext = archivo.name.split('.').pop().toLowerCase();
        if (!EXTENSIONES_PERMITIDAS.includes(ext)) {
            mostrarAviso(formError, 'Solo se permiten archivos Excel (.xls o .xlsx).', ICONO_ERROR);
            limpiarArchivo();
            return;
        }

        archivoSeleccionado.textContent = archivo.name;
        mostrar(archivoFila);
        ocultar(btnAdjuntar);
    });

    formMensaje.addEventListener('submit', async (e) => {
        e.preventDefault();
        ocultarAviso(formError);
        ocultarAviso(formExito);

        if (!contactoActualId) {
            mostrarAviso(formError, 'Selecciona un destinatario primero.', ICONO_ERROR);
            return;
        }

        btnEnviar.disabled = true;

        try {
            if (modoEdicion) {
                const idEdit = inputIdMensajeEdit.value;
                if (!idEdit) { mostrarAviso(formError, 'ID de mensaje inválido.', ICONO_ERROR); return; }
                if (!inputMensaje.value.trim()) {
                    mostrarAviso(formError, 'El mensaje no puede estar vacío.', ICONO_ERROR);
                    return;
                }

                const fd = new FormData();
                fd.append('accion',     'editar_mensaje');
                fd.append('id_mensaje', idEdit);
                fd.append('titulo',     inputTitulo.value.trim());
                fd.append('mensaje',    inputMensaje.value.trim());

                const resp = await fetch('mensaje.php', { method: 'POST', body: fd });
                const data = await resp.json();

                if (data.ok) {
                    cancelarEdicion();
                    await cargarMensajes(contactoActualId);
                } else {
                    mostrarAviso(formError, data.error || 'No se pudo editar.', ICONO_ERROR);
                }
                return;
            }

            if (!inputMensaje.value.trim() && !inputArchivo.files.length) {
                mostrarAviso(formError, 'Escribe un mensaje o adjunta un archivo.', ICONO_ERROR);
                return;
            }

            const fd = new FormData(formMensaje);
            fd.append('accion', 'enviar_mensaje');

            const resp = await fetch('mensaje.php', { method: 'POST', body: fd });
            const data = await resp.json();

            if (data.ok) {
                inputTitulo.value  = '';
                inputMensaje.value = '';
                limpiarArchivo();
                mostrar(btnAdjuntar);
                mostrarAviso(formExito, 'Mensaje enviado.', ICONO_EXITO);

                if (avisoExitoTimeout) clearTimeout(avisoExitoTimeout);
                avisoExitoTimeout = setTimeout(() => ocultarAviso(formExito), 4000);

                await cargarMensajes(contactoActualId);
            } else {
                mostrarAviso(formError, data.error || 'No se pudo enviar el mensaje.', ICONO_ERROR);
            }
        } catch (err) {
            console.error('Error al enviar:', err);
            mostrarAviso(formError, 'Error de conexión con el servidor.', ICONO_ERROR);
        } finally {
            btnEnviar.disabled = false;
        }
    });

    inputMensaje.addEventListener('keydown', (e) => {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            formMensaje.dispatchEvent(new Event('submit'));
        }
    });

    mostrar(chatVacio);
    ocultar(chatActivo);
    if (panelDetalles) ocultar(panelDetalles);
});