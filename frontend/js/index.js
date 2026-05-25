document.addEventListener('DOMContentLoaded', () => {
    const userMenuBtn     = document.getElementById('userMenuBtn');
    const dropdownMenu    = document.getElementById('dropdownMenu');
    const openModalBtn    = document.getElementById('openModalBtn');
    const logoutModal     = document.getElementById('logoutModal');
    const cancelBtn       = document.getElementById('cancelBtn');
    const confirmBtn      = document.getElementById('confirmBtn');
    const navLinks        = document.querySelectorAll('.nav-link');
    const contentSections = document.querySelectorAll('.content-section');
    const alertBtn        = document.getElementById('alertBtn');
    const alertPanel      = document.getElementById('alertPanel');
    const alertPanelClose = document.getElementById('alertPanelClose');

    navLinks.forEach(link => {
        link.addEventListener('click', (e) => {
            e.preventDefault();

            navLinks.forEach(item => item.classList.remove('active'));
            contentSections.forEach(section => section.classList.remove('active'));

            link.classList.add('active');
            const target = link.getAttribute('data-target');
            const targetSection = document.getElementById(`content-${target}`);
            if (targetSection) targetSection.classList.add('active');

            if (target === 'pedidos') {
                const iframePedidos = document.getElementById('iframe-pedidos');
                if (iframePedidos) iframePedidos.contentWindow.location.reload();
            }

            alertPanel?.classList.remove('open');
        });
    });

    userMenuBtn?.addEventListener('click', (e) => {
        e.stopPropagation();
        userMenuBtn.classList.toggle('active');
        dropdownMenu.classList.toggle('show');
    });

    document.addEventListener('click', () => {
        if (dropdownMenu?.classList.contains('show')) {
            userMenuBtn.classList.remove('active');
            dropdownMenu.classList.remove('show');
        }
    });

    openModalBtn?.addEventListener('click', (e) => {
        e.stopPropagation();
        logoutModal.classList.add('active');
        userMenuBtn.classList.remove('active');
        dropdownMenu.classList.remove('show');
    });

    cancelBtn?.addEventListener('click',  () => logoutModal.classList.remove('active'));
    confirmBtn?.addEventListener('click', () => { window.location.href = 'login/logout.php'; });

    async function cargarBadgeAlertas() {
        try {
            const res  = await fetch('alerta.php?_count=1');
            const data = await res.json();
            if (!data.success) return;

            const n = data.pendientes || 0;

            const badgeNav   = document.getElementById('alertNavBadge');
            const badgeFloat = document.getElementById('alertFloatBadge');

            if (badgeNav) {
                badgeNav.textContent   = n > 99 ? '99+' : n;
                badgeNav.style.display = n > 0 ? 'flex' : 'none';
            }
            if (badgeFloat) {
                badgeFloat.textContent   = n > 99 ? '99+' : n;
                badgeFloat.style.display = n > 0 ? 'flex' : 'none';
            }

            if (alertPanel?.classList.contains('open')) {
                cargarAlertasPanel();
            }

        } catch { }
    }

    async function cargarAlertasPanel() {
        const list = document.getElementById('alertPanelList');
        if (!list) return;

        list.innerHTML = `
            <div class="fp-empty">
                <i class="bx bx-loader-alt bx-spin"></i>
                <p>Cargando...</p>
            </div>`;

        try {
            const res  = await fetch('alerta.php?_panel=1');
            const data = await res.json();

            if (!data.success || !data.alertas || !data.alertas.length) {
                list.innerHTML = `
                    <div class="fp-empty">
                        <i class="bx bx-check-circle"></i>
                        <p>Sin alertas pendientes</p>
                    </div>`;
                return;
            }

            list.innerHTML = data.alertas.map(a => {
                const ico = a.tipo === 'critica'     ? 'bx-error-circle'
                          : a.tipo === 'advertencia' ? 'bx-error'
                          :                            'bx-info-circle';
                const cls = a.tipo === 'critica'     ? 'fp-critica'
                          : a.tipo === 'advertencia' ? 'fp-advertencia'
                          :                            'fp-info';
                const fecha = a.fecha
                    ? a.fecha.substring(0, 16).replace('T', ' ')
                    : '';
                return `
                <div class="fp-item ${cls}" id="fp-${a.id_alerta}">
                    <div class="fp-icon"><i class="bx ${ico}"></i></div>
                    <div class="fp-body">
                        <p class="fp-msg">${escapeHtml(a.mensaje)}</p>
                        ${a.id_op ? `<span class="fp-op">OP #${a.id_op}${a.op_estilo ? ' — ' + escapeHtml(a.op_estilo) : ''}</span>` : ''}
                        <span class="fp-fecha">${fecha}</span>
                    </div>
                    <button class="fp-atencion" title="Marcar como atendida"
                            onclick="atenderDesdePanel(${a.id_alerta})">
                        <i class="bx bx-check"></i>
                    </button>
                </div>`;
            }).join('');

        } catch {
            list.innerHTML = `
                <div class="fp-empty">
                    <i class="bx bx-error-circle"></i>
                    <p>Error al cargar alertas</p>
                </div>`;
        }
    }

    alertBtn?.addEventListener('click', (e) => {
        e.stopPropagation();
        const estaAbierto = alertPanel?.classList.contains('open');

        if (estaAbierto) {
            alertPanel.classList.remove('open');
        } else {
            alertPanel?.classList.add('open');
            cargarAlertasPanel();
        }
    });

    alertPanelClose?.addEventListener('click', () => {
        alertPanel?.classList.remove('open');
    });

    document.addEventListener('click', (e) => {
        if (alertPanel?.classList.contains('open') &&
            !alertPanel.contains(e.target) &&
            !alertBtn?.contains(e.target)) {
            alertPanel.classList.remove('open');
        }
    });

    cargarBadgeAlertas();
    setInterval(cargarBadgeAlertas, 60000);

    window.addEventListener('pageshow', (event) => {
        if (event.persisted ||
            (window.performance && window.performance.navigation.type === 2)) {
            window.location.reload(true);
        }
    });
});

async function atenderDesdePanel(id_alerta) {
    const item = document.getElementById(`fp-${id_alerta}`);
    if (item) {
        item.style.transition = 'opacity 0.3s, transform 0.3s';
        item.style.opacity    = '0';
        item.style.transform  = 'translateX(40px)';
        setTimeout(() => item.remove(), 300);
    }

    const fd = new FormData();
    fd.append('accion',    'marcar_atendida');
    fd.append('id_alerta', id_alerta);

    try {
        await fetch('alerta.php', { method: 'POST', body: fd });

        const res  = await fetch('alerta.php?_count=1');
        const data = await res.json();
        const n    = data.pendientes || 0;

        const b1 = document.getElementById('alertNavBadge');
        const b2 = document.getElementById('alertFloatBadge');
        if (b1) { b1.textContent = n; b1.style.display = n > 0 ? 'flex' : 'none'; }
        if (b2) { b2.textContent = n; b2.style.display = n > 0 ? 'flex' : 'none'; }

        const list = document.getElementById('alertPanelList');
        if (list && n === 0) {
            list.innerHTML = `
                <div class="fp-empty">
                    <i class="bx bx-check-circle"></i>
                    <p>Sin alertas pendientes</p>
                </div>`;
        }
    } catch { }
}

function escapeHtml(str) {
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}