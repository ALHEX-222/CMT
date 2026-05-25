const POLL_INTERVAL_MS  = 90_000;
let   _pollTimer        = null;
let   _lastTotalPend    = window.ALERT_TOTAL_PEND ?? 0;
let   _lastTotalCrit    = window.ALERT_TOTAL_CRIT ?? 0;

document.addEventListener('DOMContentLoaded', () => {

    const modalNueva       = document.getElementById('modalNueva');
    const btnNueva         = document.getElementById('btnNueva');
    const btnCerrarModal   = document.getElementById('btnCerrarModal');
    const btnCancelarModal = document.getElementById('btnCancelarModal');
    const btnGuardar       = document.getElementById('btnGuardarAlerta');

    btnNueva?.addEventListener('click', () => {
        modalNueva.classList.add('active');
        document.getElementById('inputMensaje').focus();
    });

    [btnCerrarModal, btnCancelarModal].forEach(btn =>
        btn?.addEventListener('click', () => {
            modalNueva.classList.remove('active');
            limpiarFormulario();
        })
    );

    modalNueva?.addEventListener('click', e => {
        if (e.target === modalNueva) {
            modalNueva.classList.remove('active');
            limpiarFormulario();
        }
    });

    btnGuardar?.addEventListener('click', async () => {
        const mensaje = document.getElementById('inputMensaje').value.trim();
        const tipo    = document.getElementById('inputTipo').value;
        const id_op   = document.getElementById('inputOp').value.trim();

        if (!mensaje) {
            showToast('El mensaje no puede estar vacío.', 'error');
            document.getElementById('inputMensaje').focus();
            return;
        }

        btnGuardar.disabled = true;
        btnGuardar.innerHTML = '<i class="bx bx-loader-alt bx-spin"></i> Guardando...';

        const fd = new FormData();
        fd.append('accion',  'crear');
        fd.append('mensaje', mensaje);
        fd.append('tipo',    tipo);
        if (id_op) fd.append('id_op', id_op);

        try {
            const res  = await fetch('alerta.php', { method: 'POST', body: fd });
            const data = await res.json();

            if (data.success) {
                showToast('Alerta creada correctamente.', 'success');
                modalNueva.classList.remove('active');
                limpiarFormulario();
                setTimeout(() => window.location.reload(), 700);
            } else {
                showToast(data.message || 'Error al crear la alerta.', 'error');
            }
        } catch {
            showToast('Error de conexión.', 'error');
        } finally {
            btnGuardar.disabled = false;
            btnGuardar.innerHTML = '<i class="bx bx-save"></i> Crear Alerta';
        }
    });

    document.getElementById('btnRefresh')?.addEventListener('click', () => {
        const btn = document.getElementById('btnRefresh');
        btn.classList.add('spinning');
        btn.disabled = true;
        setTimeout(() => window.location.reload(), 200);
    });

    iniciarPolling();

    if (_lastTotalCrit > 0) {
        iniciarTituloParpadeante();
    }
});

function iniciarPolling() {
    _pollTimer = setInterval(async () => {
        await checkNuevasAlertas();
    }, POLL_INTERVAL_MS);
}

async function checkNuevasAlertas() {
    try {
        const fd = new FormData();
        fd.append('accion', 'monitor');
        const res  = await fetch('alerta.php', { method: 'POST', body: fd });
        const data = await res.json();

        if (!data.success) return;

        const res2  = await fetch('alerta.php?_poll=1&t=' + Date.now());

        if (data.creadas > 0) {
            mostrarBannerNuevasAlertas(data.creadas);
        }

    } catch {
    }
}

function mostrarBannerNuevasAlertas(n) {
    const banner = document.getElementById('autoBanner');
    const msg    = document.getElementById('autoBannerMsg');
    if (!banner || !msg) return;

    msg.textContent = `El monitor detectó ${n} nueva${n > 1 ? 's' : ''} alerta${n > 1 ? 's' : ''} automática${n > 1 ? 's' : ''}. Actualiza para verla${n > 1 ? 's' : ''}.`;
    banner.style.display = 'flex';

    setTimeout(() => {
        window.location.reload();
    }, 5000);
}

async function accionAlerta(accion, id_alerta) {
    const card = document.getElementById(`card-${id_alerta}`);

    const fd = new FormData();
    fd.append('accion',    accion);
    fd.append('id_alerta', id_alerta);

    try {
        const res  = await fetch('alerta.php', { method: 'POST', body: fd });
        const data = await res.json();

        if (data.success) {
            const msg = accion === 'marcar_leida'    ? 'Marcada como leída.'
                      : accion === 'marcar_atendida' ? 'Marcada como atendida.'
                      :                                'Acción completada.';
            showToast(msg, 'success');

            if (card) {
                card.style.transition = 'all 0.35s ease';
                card.style.opacity    = '0';
                card.style.transform  = 'scale(0.92) translateY(-8px)';
            }
            setTimeout(() => window.location.reload(), 450);
        } else {
            showToast(data.message || 'Error al ejecutar la acción.', 'error');
        }
    } catch {
        showToast('Error de conexión.', 'error');
    }
}

let _idEliminar = null;

function confirmarEliminar(id_alerta) {
    _idEliminar = id_alerta;
    document.getElementById('modalEliminar').classList.add('active');

    document.getElementById('btnConfirmarEliminar').onclick = async () => {
        cerrarModalEliminar();
        await accionAlerta('eliminar', _idEliminar);
    };
}

function cerrarModalEliminar() {
    document.getElementById('modalEliminar').classList.remove('active');
    _idEliminar = null;
}

function iniciarTituloParpadeante() {
    const originalTitle = document.title;
    let toggle = false;
    setInterval(() => {
        document.title = toggle ? `🔴 ¡ALERTA CRÍTICA! — CMT` : originalTitle;
        toggle = !toggle;
    }, 2000);
}

function showToast(msg, type = 'success') {
    const toast = document.getElementById('toast');
    if (!toast) return;
    toast.textContent = msg;
    toast.className   = `toast toast-${type} show`;
    clearTimeout(toast._timer);
    toast._timer = setTimeout(() => toast.classList.remove('show'), 3200);
}

function limpiarFormulario() {
    const m = document.getElementById('inputMensaje');
    const t = document.getElementById('inputTipo');
    const o = document.getElementById('inputOp');
    if (m) m.value = '';
    if (t) t.value = 'info';
    if (o) o.value = '';
}