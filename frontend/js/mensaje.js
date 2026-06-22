document.addEventListener('DOMContentLoaded', () => {
    const listaContactos          = document.querySelectorAll('.contacto');
    const chatVacio               = document.getElementById('chat-vacio');
    const chatActivo              = document.getElementById('chat-activo');
    const chatNombre              = document.getElementById('chat-nombre');
    const chatAvatar              = document.getElementById('chat-avatar');
    const chatMensajes            = document.getElementById('chat-mensajes');
    const formMensaje             = document.getElementById('form-mensaje');
    const inputIdReceptor         = document.getElementById('input-id-receptor');
    const inputTitulo             = document.getElementById('input-titulo');
    const inputMensaje            = document.getElementById('input-mensaje');
    const inputArchivo            = document.getElementById('input-archivo');
    const archivoSeleccionado     = document.getElementById('archivo-seleccionado');
    const formError               = document.getElementById('form-error');
    const formExito               = document.getElementById('form-exito');
    const correoBanner            = document.getElementById('correo-banner');
    const checkCorreo             = document.getElementById('check-correo');
    const correoDestinatarioInfo  = document.getElementById('correo-destinatario-info');

    // Panel de detalles
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

    let contactoActualId       = null;
    let correoClaveActual      = '';
    let intervaloActualizacion = null;
    let avisoExitoTimeout      = null;
    const EXTENSIONES_PERMITIDAS = ['xls', 'xlsx'];

    const ICONO_EXITO   = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>';
    const ICONO_ERROR   = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12" y2="16.01"/></svg>';
    const ICONO_ARCHIVO = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>';

    // ── Helpers ──────────────────────────────────────────────────────────────

    function escapeHtml(texto) {
        const div = document.createElement('div');
        div.textContent = texto ?? '';
        return div.innerHTML;
    }

    function mostrarAviso(elemento, mensaje, icono) {
        elemento.innerHTML = `${icono}<span>${escapeHtml(mensaje)}</span>`;
        elemento.classList.add('visible');
    }

    function ocultarAviso(elemento) {
        elemento.classList.remove('visible');
        elemento.innerHTML = '';
    }

    function formatearFecha(fechaStr) {
        const fecha = new Date(fechaStr.replace(' ', 'T'));
        if (isNaN(fecha.getTime())) return fechaStr;
        return fecha.toLocaleString('es-PE', {
            day: '2-digit', month: '2-digit', year: 'numeric',
            hour: '2-digit', minute: '2-digit'
        });
    }

    // FIX: mostrar/ocultar con clase .oculto en vez de style.display
    // Evita el conflicto con display:flex !important del CSS
    function mostrar(el) { el.classList.remove('oculto'); }
    function ocultar(el) { el.classList.add('oculto'); }

    // FIX: el banner correo también usa clase, no style inline
    function mostrarBanner() { correoBanner.classList.add('visible-flex'); }
    function ocultarBanner() { correoBanner.classList.remove('visible-flex'); }

    // FIX: filas del panel de detalles — ocultar si el dato está vacío
    function setDetalleFilaValor(filaEl, spanEl, valor) {
        if (valor && valor.trim() !== '') {
            spanEl.textContent = valor;
            mostrar(filaEl);
        } else {
            spanEl.textContent = '';
            ocultar(filaEl);
        }
    }

    // ── Render mensajes ──────────────────────────────────────────────────────

    function renderMensaje(msg) {
        const clase       = msg.es_propio ? 'propio' : 'recibido';
        const inicialesEl = document.querySelector(`.contacto[data-id="${contactoActualId}"] .avatar`);
        const iniciales   = msg.es_propio
            ? (chatAvatar?.textContent ?? '?')
            : (inicialesEl?.textContent ?? '?');
        const avClase = msg.es_propio
            ? 'avatar-tono-0'
            : (inicialesEl?.className.match(/avatar-tono-\d/)?.[0] ?? 'avatar-tono-0');

        let html = `<div class="mensaje-fila ${clase}">
            <div class="avatar-mini ${avClase}">${escapeHtml(iniciales)}</div>
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
        html += `</div></div>`;
        return html;
    }

    async function cargarMensajes(idContacto) {
        try {
            const respuesta = await fetch(`mensaje.php?accion=listar_mensajes&id_contacto=${idContacto}`);
            const data      = await respuesta.json();
            if (!data.ok) return;

            const separador = '<div class="fecha-separador">Hoy</div>';

            chatMensajes.innerHTML = data.mensajes.length
                ? separador + data.mensajes.map(renderMensaje).join('')
                : '<p class="sin-mensajes">Aún no hay mensajes con este usuario.</p>';

            chatMensajes.scrollTop = chatMensajes.scrollHeight;
        } catch (error) {
            console.error('Error al cargar mensajes:', error);
        }
    }

    // ── Seleccionar contacto ─────────────────────────────────────────────────

    function seleccionarContacto(elemento) {
        listaContactos.forEach(c => c.classList.remove('activo'));
        elemento.classList.add('activo');

        contactoActualId  = elemento.dataset.id;
        correoClaveActual = elemento.dataset.correo || '';

        const nombre = elemento.dataset.nombre || '';
        const rol    = elemento.dataset.rol    || '';
        const email  = elemento.dataset.email  || '';
        const phone  = elemento.dataset.phone  || '';
        const city   = elemento.dataset.city   || '';

        // FIX: usar clases en vez de style.display para evitar conflicto !important
        ocultar(chatVacio);
        mostrar(chatActivo);

        chatNombre.textContent = nombre;

        const iniciales   = nombre.split(' ').map(p => p[0]).slice(0, 2).join('').toUpperCase();
        const avatarClase = elemento.querySelector('.avatar').className;
        chatAvatar.textContent = iniciales;
        chatAvatar.className   = avatarClase;

        inputIdReceptor.value = contactoActualId;
        ocultarAviso(formError);
        ocultarAviso(formExito);

        checkCorreo.checked = false;
        if (correoClaveActual) {
            correoDestinatarioInfo.textContent = `Se enviará a ${nombre}`;
            mostrarBanner();
        } else {
            ocultarBanner();
        }

        // Panel de detalles
        if (panelDetalles) {
            mostrar(panelDetalles);

            detalleAvatar.textContent = iniciales;
            detalleAvatar.className   = avatarClase;
            detalleAvatar.style.cssText = 'width:44px;height:44px;font-size:15px;';

            detalleNombre.textContent = nombre;
            detalleRol.textContent    = rol;

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

    // ── Archivo ──────────────────────────────────────────────────────────────

    inputArchivo.addEventListener('change', () => {
        const archivo = inputArchivo.files[0];
        ocultarAviso(formError);

        if (!archivo) {
            archivoSeleccionado.textContent = '';
            return;
        }

        const extension = archivo.name.split('.').pop().toLowerCase();
        if (!EXTENSIONES_PERMITIDAS.includes(extension)) {
            mostrarAviso(formError, 'Solo se permiten archivos Excel (.xls o .xlsx).', ICONO_ERROR);
            inputArchivo.value = '';
            archivoSeleccionado.textContent = '';
            return;
        }

        archivoSeleccionado.textContent = archivo.name;
    });

    // ── Enviar mensaje ───────────────────────────────────────────────────────

    formMensaje.addEventListener('submit', async (evento) => {
        evento.preventDefault();
        ocultarAviso(formError);
        ocultarAviso(formExito);

        if (!contactoActualId) {
            mostrarAviso(formError, 'Selecciona un destinatario primero.', ICONO_ERROR);
            return;
        }
        if (!inputMensaje.value.trim() && !inputArchivo.files.length) {
            mostrarAviso(formError, 'Escribe un mensaje o adjunta un archivo.', ICONO_ERROR);
            return;
        }

        const datosFormulario = new FormData(formMensaje);
        datosFormulario.append('accion', 'enviar_mensaje');

        if (checkCorreo.checked && correoClaveActual) {
            datosFormulario.append('correo_destino', correoClaveActual);
        }

        const btnEnviar = formMensaje.querySelector('.btn-enviar');
        btnEnviar.disabled = true;

        try {
            const respuesta = await fetch('mensaje.php', {
                method: 'POST',
                body: datosFormulario
            });
            const data = await respuesta.json();

            if (data.ok) {
                inputTitulo.value = '';
                inputMensaje.value = '';
                inputArchivo.value = '';
                archivoSeleccionado.textContent = '';
                checkCorreo.checked = false;

                if (data.correo_nombre_destino) {
                    if (data.correo_enviado) {
                        mostrarAviso(formExito, `Mensaje enviado y correo entregado a ${data.correo_nombre_destino}.`, ICONO_EXITO);
                    } else {
                        mostrarAviso(formError, `Mensaje enviado, pero el correo a ${data.correo_nombre_destino} no se pudo entregar.`, ICONO_ERROR);
                    }

                    if (avisoExitoTimeout) clearTimeout(avisoExitoTimeout);
                    avisoExitoTimeout = setTimeout(() => {
                        ocultarAviso(formExito);
                        ocultarAviso(formError);
                    }, 6000);
                }

                await cargarMensajes(contactoActualId);
            } else {
                mostrarAviso(formError, data.error || 'No se pudo enviar el mensaje.', ICONO_ERROR);
            }
        } catch (error) {
            console.error('Error al enviar mensaje:', error);
            mostrarAviso(formError, 'Error de conexión con el servidor.', ICONO_ERROR);
        } finally {
            btnEnviar.disabled = false;
        }
    });

    // Enter para enviar (Shift+Enter = nueva línea)
    inputMensaje.addEventListener('keydown', (e) => {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            formMensaje.dispatchEvent(new Event('submit'));
        }
    });

    // FIX: estado inicial — chat-vacio visible, chat-activo y panel ocultos
    mostrar(chatVacio);
    ocultar(chatActivo);
    if (panelDetalles) ocultar(panelDetalles);
});