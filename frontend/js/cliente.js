document.addEventListener('DOMContentLoaded', () => {
    const cliGrid        = document.getElementById('cliGrid');
    const cliEmpty       = document.getElementById('cliEmpty');
    const cliCount       = document.getElementById('cliCount');
    const filtroNombre   = document.getElementById('filtroNombre');
    const filtroRUC      = document.getElementById('filtroRUC');
    const filtroEstado   = document.getElementById('filtroEstado');
    const btnNuevo       = document.getElementById('btnNuevoCliente');
    const btnGrid        = document.getElementById('btnGrid');
    const btnList        = document.getElementById('btnList');

    const modalDetalle       = document.getElementById('modalDetalle');
    const detalleHeader      = document.getElementById('detalleHeader');
    const detalleAvatar      = document.getElementById('detalleAvatar');
    const detalleName        = document.getElementById('detalleName');
    const detalleRUC         = document.getElementById('detalleRUC');
    const detalleContacto    = document.getElementById('detalleContacto');
    const detalleKpis        = document.getElementById('detalleKpis');
    const detalleBody        = document.getElementById('detalleBody');
    const btnCerrarDetalle   = document.getElementById('btnCerrarDetalle');
    const btnEditarDesdeDetalle = document.getElementById('btnEditarDesdeDetalle');
    const tabPend            = document.getElementById('tabPend');
    const tabComp            = document.getElementById('tabComp');

    const modalForm      = document.getElementById('modalForm');
    const formTitle      = document.getElementById('formTitle');
    const formSubtitle   = document.getElementById('formSubtitle');
    const formId         = document.getElementById('formId');
    const fNombre        = document.getElementById('fNombre');
    const fRUC           = document.getElementById('fRUC');
    const fTelefono      = document.getElementById('fTelefono');
    const fCorreo        = document.getElementById('fCorreo');
    const fDireccion     = document.getElementById('fDireccion');
    const formAlert      = document.getElementById('formAlert');
    const btnSubmitForm  = document.getElementById('btnSubmitForm');
    const btnCerrarForm  = document.getElementById('btnCerrarForm');

    let currentTab        = 'pendientes';
    let currentPedidos    = [];
    let currentClienteId  = null;

    const AVATAR_COLORS = [
        ['#1565c0','#e3f2fd'], ['#6a1b9a','#f3e5f5'], ['#00695c','#e0f2f1'],
        ['#e65100','#fff3e0'], ['#880e4f','#fce4ec'], ['#37474f','#eceff1'],
        ['#1b5e20','#e8f5e9'], ['#bf360c','#fbe9e7'], ['#0d47a1','#e8eaf6'],
        ['#4a148c','#f8f0ff'],
    ];

    function getAvatarColor(id) {
        return AVATAR_COLORS[id % AVATAR_COLORS.length];
    }

    function iniciales(nombre) {
        return nombre.trim().toUpperCase().split(/\s+/).slice(0, 2).map(p => p[0] || '').join('') || '?';
    }

    function filtrar() {
        const valNombre  = filtroNombre.value.toLowerCase().trim();
        const valRUC     = filtroRUC.value.toLowerCase().trim();
        const valEstado  = filtroEstado.value;
        const cards      = cliGrid.querySelectorAll('.cli-card');
        let visible      = 0;

        cards.forEach(card => {
            const nombre    = card.dataset.nombre;
            const ruc       = card.dataset.ruc.toLowerCase();
            const tiene     = card.dataset.tiene === '1';
            const pendientes= parseInt(card.dataset.pendientes) > 0;

            const okNombre  = !valNombre || nombre.includes(valNombre);
            const okRUC     = !valRUC    || ruc.includes(valRUC);
            const okEstado  =
                valEstado === ''             ||
                (valEstado === 'con_pedidos'  && tiene)      ||
                (valEstado === 'sin_pedidos'  && !tiene)     ||
                (valEstado === 'pendientes'   && pendientes);

            const show = okNombre && okRUC && okEstado;
            card.style.display = show ? '' : 'none';
            if (show) visible++;
        });

        cliEmpty.style.display = visible === 0 ? 'flex' : 'none';
        cliCount.textContent = `${visible} cliente${visible !== 1 ? 's' : ''}`;
    }

    filtroNombre.addEventListener('input',  filtrar);
    filtroRUC.addEventListener('input',     filtrar);
    filtroEstado.addEventListener('change', filtrar);
    filtrar();

    btnGrid.addEventListener('click', () => {
        cliGrid.classList.remove('list-view');
        btnGrid.classList.add('active');
        btnList.classList.remove('active');
    });

    btnList.addEventListener('click', () => {
        cliGrid.classList.add('list-view');
        btnList.classList.add('active');
        btnGrid.classList.remove('active');
    });

    cliGrid.addEventListener('click', e => {
        const btnDetalle = e.target.closest('.btn-card-detalle');
        const btnEdit    = e.target.closest('.btn-card-edit');

        if (btnDetalle) {
            abrirDetalle(parseInt(btnDetalle.dataset.id));
        } else if (btnEdit) {
            abrirFormEditar(btnEdit.dataset);
        }
    });

    function abrirDetalle(idCliente) {
        currentTab       = 'pendientes';
        currentPedidos   = [];
        currentClienteId = idCliente;

        document.querySelectorAll('.tab-btn').forEach(t => t.classList.toggle('active', t.dataset.tab === 'pendientes'));
        detalleContacto.innerHTML = '';
        detalleKpis.innerHTML     = '';
        detalleBody.innerHTML     = `
            <div class="modal-loading">
                <i class="bx bx-loader-alt"></i>
                <span>Cargando información del cliente...</span>
            </div>`;

        modalDetalle.style.display  = 'flex';
        document.body.style.overflow = 'hidden';

        fetch(`cliente.php?action=get_cliente&id_cliente=${idCliente}&_=${Date.now()}`)
            .then(r => r.json())
            .then(data => {
                if (!data.success) {
                    detalleBody.innerHTML = `<div class="modal-empty"><i class="bx bx-error-circle"></i><p>${data.message}</p></div>`;
                    return;
                }
                renderDetalle(data);
            })
            .catch(() => {
                detalleBody.innerHTML = `<div class="modal-empty"><i class="bx bx-wifi-off"></i><p>Error de conexión.</p></div>`;
            });
    }

    function renderDetalle(data) {
        const { cliente, pedidos, total_ops, ops_pendientes, ops_completadas, total_prendas } = data;
        currentPedidos = pedidos;

        const col = getAvatarColor(cliente.id_cliente);
        const ini = iniciales(cliente.nombre_cliente);

        detalleAvatar.textContent = ini;
        detalleAvatar.style.background = col[1];
        detalleAvatar.style.color      = col[0];
        detalleHeader.style.borderBottom = `3px solid ${col[0]}`;

        detalleName.textContent = cliente.nombre_cliente;
        detalleRUC.innerHTML    = `<i class="bx bx-id-card"></i> RUC: ${cliente.ruc} &nbsp;·&nbsp; ID: #${cliente.id_cliente}`;

        btnEditarDesdeDetalle.onclick = () => {
            cerrarDetalle();
            setTimeout(() => abrirFormEditar({
                id:        cliente.id_cliente,
                nombre:    cliente.nombre_cliente,
                ruc:       cliente.ruc,
                telefono:  cliente.telefono  || '',
                correo:    cliente.correo    || '',
                direccion: cliente.direccion || ''
            }), 200);
        };

        detalleContacto.innerHTML = `
            ${cliente.telefono  ? `<div class="dc-item"><i class="bx bx-phone"></i><span>${cliente.telefono}</span></div>`  : ''}
            ${cliente.correo    ? `<div class="dc-item"><i class="bx bx-envelope"></i><a href="mailto:${cliente.correo}" class="dc-link">${cliente.correo}</a></div>` : ''}
            ${cliente.direccion ? `<div class="dc-item"><i class="bx bx-map"></i><span>${cliente.direccion}</span></div>` : ''}
        `;

        const pct = total_ops > 0 ? ((ops_completadas / total_ops) * 100).toFixed(0) : 0;
        detalleKpis.innerHTML = `
            <div class="dkpi">
                <i class="bx bx-cart-alt" style="color:${col[0]}"></i>
                <div><div class="dkpi-val">${total_ops}</div><div class="dkpi-label">Órdenes totales</div></div>
            </div>
            <div class="dkpi dkpi-amber">
                <i class="bx bx-time"></i>
                <div><div class="dkpi-val">${ops_pendientes}</div><div class="dkpi-label">En proceso</div></div>
            </div>
            <div class="dkpi dkpi-green">
                <i class="bx bx-check-circle"></i>
                <div><div class="dkpi-val">${ops_completadas}</div><div class="dkpi-label">Completadas</div></div>
            </div>
            <div class="dkpi dkpi-blue">
                <i class="bx bx-t-shirt"></i>
                <div><div class="dkpi-val">${Number(total_prendas).toLocaleString('en-US')}</div><div class="dkpi-label">Prendas total</div></div>
            </div>
            <div class="dkpi-progress">
                <div class="prog-label">
                    <span>Progreso general</span>
                    <strong>${pct}%</strong>
                </div>
                <div class="prog-track">
                    <div class="prog-fill" style="width:0%; background:${col[0]}" data-target="${pct}"></div>
                </div>
            </div>
        `;

        requestAnimationFrame(() => {
            const fill = detalleKpis.querySelector('.prog-fill');
            if (fill) setTimeout(() => { fill.style.width = fill.dataset.target + '%'; }, 100);
        });

        tabPend.textContent = ops_pendientes;
        tabComp.textContent = ops_completadas;

        renderTabPedidos(currentTab, pedidos, col[0]);
    }

    function renderTabPedidos(tab, pedidos, accentColor) {
        let lista = pedidos;
        if (tab === 'pendientes')  lista = pedidos.filter(p => p.estado === 'Pendiente');
        if (tab === 'completados') lista = pedidos.filter(p => p.estado === 'Completado');

        if (lista.length === 0) {
            detalleBody.innerHTML = `
                <div class="modal-empty">
                    <i class="bx ${tab === 'pendientes' ? 'bx-time' : 'bx-check-circle'}"></i>
                    <p>${tab === 'pendientes' ? 'No hay órdenes en proceso.' : tab === 'completados' ? 'No hay órdenes completadas.' : 'Este cliente no tiene órdenes registradas.'}</p>
                </div>`;
            return;
        }

        const wrap = document.createElement('div');
        wrap.className = 'pedidos-list';

        lista.forEach((p, i) => {
            const esPend = p.estado === 'Pendiente';
            const item   = document.createElement('div');
            item.className = `pedido-item ${esPend ? 'pend' : 'comp'}`;
            item.style.animationDelay = `${i * 0.04}s`;
            item.innerHTML = `
                <div class="pedido-left" style="border-left: 4px solid ${esPend ? '#f59e0b' : '#22c55e'};">
                    <div class="pedido-op">
                        <span class="op-num" style="color:${accentColor}">#${p.id_op}</span>
                        <span class="op-estilo">${p.estilo}</span>
                        <span class="status-dot ${esPend ? 'pend' : 'comp'}">
                            <i class="bx ${esPend ? 'bx-loader-alt bx-spin' : 'bx-check'}"></i>
                            ${p.estado}
                        </span>
                    </div>
                    <div class="pedido-desc">${p.descripcion}</div>
                    <div class="pedido-meta">
                        <span><i class="bx bx-calendar"></i> ${p.fecha_ingreso}</span>
                        <span><i class="bx bx-cut"></i> ${p.total_oc} OC</span>
                    </div>
                </div>
                <div class="pedido-right">
                    <div class="pedido-qty">${Number(p.cantidad_prendas).toLocaleString('en-US')}</div>
                    <div class="pedido-qty-label">prendas</div>
                </div>
            `;
            wrap.appendChild(item);
        });

        detalleBody.innerHTML = '';
        detalleBody.appendChild(wrap);
    }

    document.querySelectorAll('.tab-btn').forEach(btn => {
        btn.addEventListener('click', () => {
            document.querySelectorAll('.tab-btn').forEach(t => t.classList.remove('active'));
            btn.classList.add('active');
            currentTab = btn.dataset.tab;
            const col  = currentClienteId ? getAvatarColor(currentClienteId) : ['#2196f3','#e3f2fd'];
            renderTabPedidos(currentTab, currentPedidos, col[0]);
        });
    });

    function cerrarDetalle() {
        modalDetalle.style.display   = 'none';
        document.body.style.overflow = '';
        currentPedidos   = [];
        currentClienteId = null;
    }

    btnCerrarDetalle.addEventListener('click', cerrarDetalle);
    modalDetalle.addEventListener('click', e => { if (e.target === modalDetalle) cerrarDetalle(); });

    function abrirFormNuevo() {
        formTitle.textContent   = 'Nuevo Cliente';
        formSubtitle.textContent= 'Completa los datos para registrar';
        formId.value            = '';
        fNombre.value = fRUC.value = fTelefono.value = fCorreo.value = fDireccion.value = '';
        formAlert.style.display = 'none';
        btnSubmitForm.innerHTML = '<i class="bx bx-save"></i> GUARDAR CLIENTE';
        btnSubmitForm.disabled  = false;
        modalForm.style.display  = 'flex';
        document.body.style.overflow = 'hidden';
        fNombre.focus();
    }

    function abrirFormEditar(datos) {
        formTitle.textContent   = 'Editar Cliente';
        formSubtitle.textContent= `Modificando datos de ${datos.nombre || datos.nombre_cliente || ''}`;
        formId.value            = datos.id || datos.id_cliente || '';
        fNombre.value    = datos.nombre    || datos.nombre_cliente || '';
        fRUC.value       = datos.ruc       || '';
        fTelefono.value  = datos.telefono  || '';
        fCorreo.value    = datos.correo    || '';
        fDireccion.value = datos.direccion || '';
        formAlert.style.display = 'none';
        btnSubmitForm.innerHTML = '<i class="bx bx-save"></i> ACTUALIZAR CLIENTE';
        btnSubmitForm.disabled  = false;
        modalForm.style.display  = 'flex';
        document.body.style.overflow = 'hidden';
        fNombre.focus();
    }

    function cerrarForm() {
        modalForm.style.display  = 'none';
        document.body.style.overflow = '';
    }

    btnNuevo.addEventListener('click',     abrirFormNuevo);
    btnCerrarForm.addEventListener('click', cerrarForm);
    modalForm.addEventListener('click',    e => { if (e.target === modalForm) cerrarForm(); });

    btnSubmitForm.addEventListener('click', () => {
        const nombre    = fNombre.value.trim();
        const ruc       = fRUC.value.trim();
        const telefono  = fTelefono.value.trim();
        const correo    = fCorreo.value.trim();
        const direccion = fDireccion.value.trim();
        const id        = formId.value;

        if (!nombre) { showFormAlert('error', 'El nombre del cliente es obligatorio.'); fNombre.focus(); return; }
        if (!ruc)    { showFormAlert('error', 'El RUC es obligatorio.');               fRUC.focus();    return; }
        if (ruc.length < 8) { showFormAlert('error', 'El RUC debe tener al menos 8 caracteres.'); fRUC.focus(); return; }

        btnSubmitForm.disabled  = true;
        btnSubmitForm.innerHTML = "<i class='bx bx-loader-alt bx-spin'></i> Guardando...";

        const fd = new FormData();
        fd.append('action',         id ? 'editar_cliente' : 'crear_cliente');
        if (id) fd.append('id_cliente', id);
        fd.append('nombre_cliente', nombre);
        fd.append('ruc',            ruc);
        fd.append('telefono',       telefono);
        fd.append('correo',         correo);
        fd.append('direccion',      direccion);

        fetch('cliente.php', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    showFormAlert('success', data.message);
                    setTimeout(() => window.location.reload(), 1200);
                } else {
                    showFormAlert('error', data.message);
                    btnSubmitForm.disabled  = false;
                    btnSubmitForm.innerHTML = id
                        ? '<i class="bx bx-save"></i> ACTUALIZAR CLIENTE'
                        : '<i class="bx bx-save"></i> GUARDAR CLIENTE';
                }
            })
            .catch(() => {
                showFormAlert('error', 'Error de conexión con el servidor.');
                btnSubmitForm.disabled  = false;
                btnSubmitForm.innerHTML = '<i class="bx bx-save"></i> GUARDAR CLIENTE';
            });
    });

    function showFormAlert(tipo, msg) {
        formAlert.style.display = 'block';
        formAlert.className     = `form-alert ${tipo}`;
        formAlert.textContent   = msg;
    }

    document.addEventListener('keydown', e => {
        if (e.key !== 'Escape') return;
        if (modalForm.style.display    === 'flex') cerrarForm();
        else if (modalDetalle.style.display === 'flex') cerrarDetalle();
    });

});