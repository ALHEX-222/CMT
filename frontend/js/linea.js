document.addEventListener('DOMContentLoaded', () => {

    const lineasGrid      = document.getElementById('lineasGrid');
    const emptyState      = document.getElementById('emptyState');
    const searchLinea     = document.getElementById('searchLinea');
    const filterBtns      = document.querySelectorAll('.filter-btn');
    const cardAgregar     = document.getElementById('cardAgregar');
    const btnNuevaLinea   = document.getElementById('btnNuevaLinea');

    const modalOverlay    = document.getElementById('modalOverlay');
    const modalTitle      = document.getElementById('modalTitle');
    const modalSubtitle   = document.getElementById('modalSubtitle');
    const modalNumGrande  = document.getElementById('modalNumGrande');
    const modalIconWrap   = document.getElementById('modalIconWrap');
    const modalKpis       = document.getElementById('modalKpis');
    const modalBody       = document.getElementById('modalBody');
    const btnCerrarModal  = document.getElementById('btnCerrarModal');
    const btnEditarLinea  = document.getElementById('btnEditarLinea');
    const tabBtns         = document.querySelectorAll('.tab-btn');
    const tabCountActivas = document.getElementById('tabCountActivas');

    const modalFormOverlay = document.getElementById('modalFormOverlay');
    const formTitle        = document.getElementById('formTitle');
    const formSubtitle     = document.getElementById('formSubtitle');
    const formIdLinea      = document.getElementById('formIdLinea');
    const formOperarios    = document.getElementById('formOperarios');
    const grupoEstado      = document.getElementById('grupoEstado');
    const formAlert        = document.getElementById('formAlert');
    const btnFormSubmit    = document.getElementById('btnFormSubmit');
    const btnCerrarForm    = document.getElementById('btnCerrarForm');
    const spinMinus        = document.getElementById('spinMinus');
    const spinPlus         = document.getElementById('spinPlus');

    let currentData       = null;
    let currentTab        = 'activas';
    let editandoIdLinea   = null;

    const LINE_COLORS = [
        '#2196f3','#e91e63','#ff9800','#4caf50','#9c27b0',
        '#00bcd4','#f44336','#8bc34a','#ff5722','#607d8b',
        '#3f51b5','#795548'
    ];
    function getColor(numLinea) {
        return LINE_COLORS[(numLinea - 1) % LINE_COLORS.length];
    }

    let filterActivo = 'all';

    function aplicarFiltros() {
        const texto   = searchLinea.value.toLowerCase().trim();
        const cards   = lineasGrid.querySelectorAll('.linea-card:not(.card-agregar)');
        let visible   = 0;

        cards.forEach(card => {
            const estado  = card.dataset.estado;
            const num     = card.dataset.num;
            const ocupada = card.dataset.ocupada === '1';

            const okFiltro =
                filterActivo === 'all'                     ||
                (filterActivo === 'Activa'   && estado === 'Activa')   ||
                (filterActivo === 'Inactiva' && estado === 'Inactiva') ||
                (filterActivo === 'ocupada'  && ocupada);

            const okTexto = texto === '' || `línea ${num}`.includes(texto) || num.includes(texto);

            if (okFiltro && okTexto) {
                card.style.display = '';
                visible++;
            } else {
                card.style.display = 'none';
            }
        });

        emptyState.style.display = visible === 0 ? 'flex' : 'none';
    }

    filterBtns.forEach(btn => {
        btn.addEventListener('click', () => {
            filterBtns.forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            filterActivo = btn.dataset.filter;
            aplicarFiltros();
        });
    });

    searchLinea.addEventListener('input', aplicarFiltros);

    lineasGrid.addEventListener('click', e => {
        const btnVer = e.target.closest('.btn-ver-linea');
        if (btnVer) {
            abrirDetalle(parseInt(btnVer.dataset.id));
            return;
        }

        if (e.target.closest('.card-agregar')) {
            abrirFormNueva();
            return;
        }
    });

    function abrirDetalle(idLinea) {
        currentTab = 'activas';
        tabBtns.forEach(t => t.classList.toggle('active', t.dataset.tab === 'activas'));

        modalNumGrande.textContent = '…';
        modalTitle.textContent     = 'Cargando...';
        modalSubtitle.textContent  = '';
        modalKpis.innerHTML        = '';
        modalBody.innerHTML        = `
            <div class="modal-loading">
                <i class="bx bx-loader-alt"></i>
                <span>Cargando información de la línea...</span>
            </div>`;
        modalOverlay.style.display  = 'flex';
        document.body.style.overflow = 'hidden';

        fetch(`linea.php?action=get_linea_detalle&id_linea=${idLinea}&_=${Date.now()}`)
            .then(r => r.json())
            .then(data => {
                if (!data.success) {
                    modalBody.innerHTML = `<div class="modal-empty"><i class="bx bx-error-circle"></i><p>${data.message}</p></div>`;
                    return;
                }
                currentData = data;
                renderModalDetalle(data);
            })
            .catch(() => {
                modalBody.innerHTML = `<div class="modal-empty"><i class="bx bx-wifi-off"></i><p>Error de conexión.</p></div>`;
            });
    }

    function renderModalDetalle(data) {
        const { linea, ocs_activas, total_historico, total_ops } = data;
        const color   = getColor(linea.num_linea);
        const ocupada = ocs_activas.length > 0;

        modalIconWrap.style.background = `linear-gradient(135deg, ${color}, color-mix(in srgb, ${color} 60%, #000))`;
        modalNumGrande.innerHTML = linea.num_linea;
        modalTitle.textContent   = `Línea de Producción #${linea.num_linea}`;
        modalSubtitle.textContent = `${linea.num_operarios} operarios · Estado: ${linea.estado}`;

        editandoIdLinea = linea.id_linea;
        btnEditarLinea.style.display = '';

        const prendasActivas = ocs_activas.reduce((s, o) => s + parseFloat(o.cantidad || 0), 0);

        modalKpis.innerHTML = `
            <div class="mkpi ${ocupada ? 'mkpi-amber' : 'mkpi-green'}">
                <i class="bx ${ocupada ? 'bx-time' : 'bx-check-shield'}"></i>
                <div>
                    <div class="mkpi-val">${ocupada ? 'En proceso' : 'Disponible'}</div>
                    <div class="mkpi-label">Estado operativo</div>
                </div>
            </div>
            <div class="mkpi">
                <i class="bx bx-package"></i>
                <div>
                    <div class="mkpi-val">${prendasActivas.toLocaleString('en-US')}</div>
                    <div class="mkpi-label">Prendas en proceso</div>
                </div>
            </div>
            <div class="mkpi">
                <i class="bx bx-cut"></i>
                <div>
                    <div class="mkpi-val">${ocs_activas.length}</div>
                    <div class="mkpi-label">OC asignadas</div>
                </div>
            </div>
            <div class="mkpi mkpi-blue">
                <i class="bx bx-history"></i>
                <div>
                    <div class="mkpi-val">${Number(total_historico).toLocaleString('en-US')}</div>
                    <div class="mkpi-label">Prendas históricas</div>
                </div>
            </div>
            <div class="mkpi">
                <i class="bx bx-cart"></i>
                <div>
                    <div class="mkpi-val">${total_ops}</div>
                    <div class="mkpi-label">OPs procesadas</div>
                </div>
            </div>
        `;

        tabCountActivas.textContent = ocs_activas.length;
        renderTab(currentTab, data, color);
    }

    function renderTab(tab, data, color) {
        const { ocs_activas, total_historico } = data;
        modalBody.innerHTML = '';

        if (tab === 'activas') {
            if (ocs_activas.length === 0) {
                modalBody.innerHTML = `
                    <div class="modal-empty">
                        <i class="bx bx-check-shield" style="color:#22c55e; font-size:44px; display:block; margin-bottom:10px;"></i>
                        <p>Esta línea no tiene órdenes de corte asignadas actualmente.</p>
                        <p style="font-size:12px; color:#94a3b8; margin-top:4px;">Está libre y disponible para recibir trabajo.</p>
                    </div>`;
                return;
            }

            const porOP = {};
            ocs_activas.forEach(oc => {
                if (!porOP[oc.id_op]) {
                    porOP[oc.id_op] = {
                        id_op: oc.id_op,
                        cliente: oc.nombre_cliente,
                        descripcion: oc.descripcion,
                        estilo: oc.estilo,
                        estado_op: oc.estado_op,
                        ocs: []
                    };
                }
                porOP[oc.id_op].ocs.push(oc);
            });

            Object.values(porOP).forEach(op => {
                const totalOP = op.ocs.reduce((s, o) => s + parseFloat(o.cantidad || 0), 0);
                const bloque  = document.createElement('div');
                bloque.className = 'op-bloque';

                const ocRows = op.ocs.map(oc => `
                    <tr>
                        <td><span class="oc-id-badge" style="border-color:${color}; color:${color};">OC #${oc.id_oc}</span></td>
                        <td><strong>${parseFloat(oc.cantidad).toLocaleString('en-US')}</strong> prendas</td>
                        <td>${oc.fecha_corte || '—'}</td>
                        <td class="${oc.observacion ? '' : 'text-muted'}">${oc.observacion || 'Sin obs.'}</td>
                    </tr>
                `).join('');

                bloque.innerHTML = `
                    <div class="op-bloque-header" style="border-left: 4px solid ${color};">
                        <div class="op-bloque-info">
                            <a class="op-link" href="#" data-op-id="${op.id_op}" onclick="irAOrdenPedido(${op.id_op}); return false;">
                                <i class="bx bx-arrow-back" style="transform:rotate(180deg);"></i> OP #${op.id_op}
                            </a>
                            <span class="op-cliente">${op.cliente}</span>
                            <span class="badge-estilo">${op.estilo}</span>
                            <span class="op-desc">${op.descripcion}</span>
                        </div>
                        <div class="op-bloque-meta">
                            <span class="op-total-badge" style="background:color-mix(in srgb, ${color} 12%, #fff); color:${color}; border:1px solid color-mix(in srgb, ${color} 30%, #fff);">
                                <i class="bx bx-package"></i>
                                ${totalOP.toLocaleString('en-US')} prendas
                            </span>
                            <span class="status-badge ${op.estado_op.toLowerCase()}">${op.estado_op}</span>
                        </div>
                    </div>
                    <div class="op-oc-table-wrap">
                        <table class="oc-mini-table">
                            <thead>
                                <tr>
                                    <th>OC</th><th>Cantidad</th><th>Fecha Corte</th><th>Observación</th>
                                </tr>
                            </thead>
                            <tbody>${ocRows}</tbody>
                        </table>
                    </div>
                `;
                modalBody.appendChild(bloque);
            });

        } else {
            modalBody.innerHTML = `
                <div class="historial-resumen">
                    <div class="hist-card">
                        <i class="bx bx-t-shirt"></i>
                        <div class="hist-val">${Number(total_historico).toLocaleString('en-US')}</div>
                        <div class="hist-label">Total prendas procesadas (históricas)</div>
                    </div>
                    <div class="hist-card">
                        <i class="bx bx-cart"></i>
                        <div class="hist-val">${data.total_ops}</div>
                        <div class="hist-label">Órdenes de Pedido procesadas</div>
                    </div>
                    <div class="hist-info">
                        <i class="bx bx-info-circle"></i>
                        El historial completo incluye todas las OC que alguna vez fueron asignadas a esta línea,
                        incluyendo las ya completadas.
                    </div>
                </div>`;
        }
    }

    tabBtns.forEach(btn => {
        btn.addEventListener('click', () => {
            tabBtns.forEach(t => t.classList.remove('active'));
            btn.classList.add('active');
            currentTab = btn.dataset.tab;
            if (currentData) {
                const color = getColor(currentData.linea.num_linea);
                renderTab(currentTab, currentData, color);
            }
        });
    });

    function cerrarModal() {
        modalOverlay.style.display   = 'none';
        document.body.style.overflow = '';
        currentData = null;
    }

    btnCerrarModal.addEventListener('click', cerrarModal);
    modalOverlay.addEventListener('click', e => { if (e.target === modalOverlay) cerrarModal(); });
    document.addEventListener('keydown', e => {
        if (e.key === 'Escape') {
            if (modalOverlay.style.display === 'flex')     cerrarModal();
            if (modalFormOverlay.style.display === 'flex') cerrarFormModal();
        }
    });

    function abrirFormNueva() {
        formTitle.textContent     = 'Nueva Línea';
        formSubtitle.textContent  = 'Se creará automáticamente como la siguiente línea';
        formIdLinea.value         = '';
        formOperarios.value       = 2;
        grupoEstado.style.display = 'none';
        formAlert.style.display   = 'none';
        modalFormOverlay.style.display = 'flex';
        document.body.style.overflow   = 'hidden';
    }

    function abrirFormEditar(idLinea) {
        const card = lineasGrid.querySelector(`.linea-card[data-id="${idLinea}"]`);
        const estadoActual = card ? card.dataset.estado : 'Activa';

        formTitle.textContent     = `Editar Línea #${card ? card.dataset.num : idLinea}`;
        formSubtitle.textContent  = 'Modifica los parámetros de esta línea';
        formIdLinea.value         = idLinea;
        grupoEstado.style.display = '';
        formAlert.style.display   = 'none';

        document.querySelectorAll('input[name="formEstado"]').forEach(r => {
            r.checked = r.value === estadoActual;
        });

        modalFormOverlay.style.display = 'flex';
        document.body.style.overflow   = 'hidden';
    }

    function cerrarFormModal() {
        modalFormOverlay.style.display = 'none';
        document.body.style.overflow   = '';
    }

    btnNuevaLinea.addEventListener('click', abrirFormNueva);
    cardAgregar.addEventListener('click', abrirFormNueva);
    btnEditarLinea.addEventListener('click', () => {
        if (editandoIdLinea) {
            cerrarModal();
            setTimeout(() => abrirFormEditar(editandoIdLinea), 180);
        }
    });
    btnCerrarForm.addEventListener('click', cerrarFormModal);
    modalFormOverlay.addEventListener('click', e => {
        if (e.target === modalFormOverlay) cerrarFormModal();
    });

    spinMinus.addEventListener('click', () => {
        const v = parseInt(formOperarios.value) || 1;
        if (v > 1) formOperarios.value = v - 1;
    });
    spinPlus.addEventListener('click', () => {
        const v = parseInt(formOperarios.value) || 1;
        if (v < 50) formOperarios.value = v + 1;
    });

    btnFormSubmit.addEventListener('click', () => {
        const idLinea      = formIdLinea.value;
        const numOperarios = parseInt(formOperarios.value) || 2;
        const estado       = document.querySelector('input[name="formEstado"]:checked')?.value || 'Activa';

        btnFormSubmit.disabled = true;
        btnFormSubmit.innerHTML = "<i class='bx bx-loader-alt bx-spin'></i> Guardando...";

        const formData = new FormData();
        formData.append('num_operarios', numOperarios);

        if (idLinea) {
            formData.append('action', 'editar_linea');
            formData.append('id_linea', idLinea);
            formData.append('estado', estado);
        } else {
            formData.append('action', 'crear_linea');
        }

        fetch('linea.php', { method: 'POST', body: formData })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    mostrarFormAlert('success', data.message);
                    setTimeout(() => window.location.reload(), 1200);
                } else {
                    mostrarFormAlert('error', data.message);
                    btnFormSubmit.disabled = false;
                    btnFormSubmit.innerHTML = "<i class='bx bx-save'></i> GUARDAR";
                }
            })
            .catch(() => {
                mostrarFormAlert('error', 'Error de conexión con el servidor.');
                btnFormSubmit.disabled = false;
                btnFormSubmit.innerHTML = "<i class='bx bx-save'></i> GUARDAR";
            });
    });

    function mostrarFormAlert(tipo, msg) {
        formAlert.style.display = 'block';
        formAlert.className     = `form-alert ${tipo}`;
        formAlert.innerHTML     = msg;
    }
});

function irAOrdenPedido(idOP) {
    sessionStorage.setItem('highlightOP', idOP);

    const parent = window.parent;
    const parentDoc = parent.document;

    parentDoc.querySelectorAll('.content-section').forEach(sec => {
        sec.classList.remove('active');
    });
    const seccionPedidos = parentDoc.getElementById('content-pedidos');
    if (seccionPedidos) seccionPedidos.classList.add('active');

    parentDoc.querySelectorAll('.nav-link').forEach(link => {
        link.classList.remove('active');
    });
    const navPedidos = parentDoc.querySelector('.nav-link[data-target="pedidos"]');
    if (navPedidos) navPedidos.classList.add('active');

    const iframePedidos = parentDoc.getElementById('iframe-pedidos');
    if (iframePedidos) {
        iframePedidos.src = 'ordenPedido.php?_=' + Date.now();
    }
}