document.addEventListener('DOMContentLoaded', () => {
    const listaContactos      = document.querySelectorAll('.contacto');
    const chatVacio           = document.getElementById('chat-vacio');
    const chatActivo          = document.getElementById('chat-activo');
    const chatNombre          = document.getElementById('chat-nombre');
    const chatRol             = document.getElementById('chat-rol');
    const chatAvatar          = document.getElementById('chat-avatar');
    const chatMensajes        = document.getElementById('chat-mensajes');
    const formMensaje         = document.getElementById('form-mensaje');
    const inputIdReceptor     = document.getElementById('input-id-receptor');
    const inputTitulo         = document.getElementById('input-titulo');
    const inputMensaje        = document.getElementById('input-mensaje');
    const inputArchivo        = document.getElementById('input-archivo');
    const archivoSeleccionado = document.getElementById('archivo-seleccionado');
    const formError           = document.getElementById('form-error');

    let contactoActualId       = null;
    let intervaloActualizacion = null;
    const EXTENSIONES_PERMITIDAS = ['xls', 'xlsx'];

    // Evita inyección de HTML al insertar texto del usuario en el DOM
    function escapeHtml(texto) {
        const div = document.createElement('div');
        div.textContent = texto ?? '';
        return div.innerHTML;
    }

    function formatearFecha(fechaStr) {
        const fecha = new Date(fechaStr.replace(' ', 'T'));
        if (isNaN(fecha.getTime())) return fechaStr;
        return fecha.toLocaleString('es-PE', {
            day: '2-digit', month: '2-digit', year: 'numeric',
            hour: '2-digit', minute: '2-digit'
        });
    }

    function renderMensaje(msg) {
        const clase = msg.es_propio ? 'mensaje propio' : 'mensaje recibido';
        let html = `<div class="${clase}">`;

        if (msg.titulo) {
            html += `<div class="mensaje-titulo">${escapeHtml(msg.titulo)}</div>`;
        }
        if (msg.mensaje) {
            html += `<div class="mensaje-texto">${escapeHtml(msg.mensaje).replace(/\n/g, '<br>')}</div>`;
        }
        if (msg.archivo) {
            // El archivo físico vive en /uploads, en la BD solo se guardó el nombre
            html += `<a class="mensaje-adjunto" href="../uploads/${encodeURIComponent(msg.archivo)}" download target="_blank" rel="noopener">📎 Descargar Excel</a>`;
        }
        html += `<div class="mensaje-fecha">${formatearFecha(msg.fecha_y_hora)}</div>`;
        html += `</div>`;
        return html;
    }

    async function cargarMensajes(idContacto) {
        try {
            const respuesta = await fetch(`mensaje.php?accion=listar_mensajes&id_contacto=${idContacto}`);
            const data = await respuesta.json();
            if (!data.ok) return;

            chatMensajes.innerHTML = data.mensajes.length
                ? data.mensajes.map(renderMensaje).join('')
                : '<p class="sin-mensajes">Aún no hay mensajes con este usuario.</p>';

            chatMensajes.scrollTop = chatMensajes.scrollHeight;
        } catch (error) {
            console.error('Error al cargar mensajes:', error);
        }
    }

    function seleccionarContacto(elemento) {
        listaContactos.forEach(c => c.classList.remove('activo'));
        elemento.classList.add('activo');

        contactoActualId = elemento.dataset.id;
        const nombre = elemento.dataset.nombre;
        const rol    = elemento.dataset.rol;

        chatVacio.style.display  = 'none';
        chatActivo.style.display = 'flex';

        chatNombre.textContent = nombre;
        chatRol.textContent    = rol;
        chatAvatar.textContent = nombre.split(' ').map(p => p[0]).slice(0, 2).join('').toUpperCase();

        inputIdReceptor.value = contactoActualId;
        formError.textContent = '';

        cargarMensajes(contactoActualId);

        // Refresca la conversación cada 5s mientras esté abierta (estilo chat)
        if (intervaloActualizacion) clearInterval(intervaloActualizacion);
        intervaloActualizacion = setInterval(() => cargarMensajes(contactoActualId), 5000);
    }

    listaContactos.forEach(elemento => {
        elemento.addEventListener('click', () => seleccionarContacto(elemento));
    });

    inputArchivo.addEventListener('change', () => {
        const archivo = inputArchivo.files[0];
        formError.textContent = '';

        if (!archivo) {
            archivoSeleccionado.textContent = '';
            return;
        }

        const extension = archivo.name.split('.').pop().toLowerCase();
        if (!EXTENSIONES_PERMITIDAS.includes(extension)) {
            formError.textContent = 'Solo se permiten archivos Excel (.xls o .xlsx).';
            inputArchivo.value = '';
            archivoSeleccionado.textContent = '';
            return;
        }

        archivoSeleccionado.textContent = `📎 ${archivo.name}`;
    });

    formMensaje.addEventListener('submit', async (evento) => {
        evento.preventDefault();
        formError.textContent = '';

        if (!contactoActualId) {
            formError.textContent = 'Selecciona un destinatario primero.';
            return;
        }
        if (!inputMensaje.value.trim() && !inputArchivo.files.length) {
            formError.textContent = 'Escribe un mensaje o adjunta un archivo.';
            return;
        }

        const datosFormulario = new FormData(formMensaje);
        datosFormulario.append('accion', 'enviar_mensaje');

        const btnEnviar = formMensaje.querySelector('.btn-enviar');
        btnEnviar.disabled = true;
        btnEnviar.textContent = 'Enviando...';

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
                await cargarMensajes(contactoActualId);
            } else {
                formError.textContent = data.error || 'No se pudo enviar el mensaje.';
            }
        } catch (error) {
            console.error('Error al enviar mensaje:', error);
            formError.textContent = 'Error de conexión con el servidor.';
        } finally {
            btnEnviar.disabled = false;
            btnEnviar.textContent = 'Enviar';
        }
    });
});