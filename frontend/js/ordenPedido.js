document.addEventListener('DOMContentLoaded', () => {
    const searchOP      = document.getElementById('searchOP');
    const searchCliente = document.getElementById('searchCliente');
    const searchEstado  = document.getElementById('searchEstado');
    const tbodyOP       = document.getElementById('tbodyOP');
    const excelFile     = document.getElementById('excelFile');
    const statusAlert   = document.getElementById('statusAlert');
    const fileName      = document.getElementById('fileName');
    
    const previewContainer  = document.getElementById('previewContainer');
    const tbodyPreview      = document.getElementById('tbodyPreview');
    const btnCancelarCarga  = document.getElementById('btnCancelarCarga');
    const btnConfirmarCarga = document.getElementById('btnConfirmarCarga');
    const mainTableWrapper  = document.querySelector('.main-table-wrapper');

    const modalOverlay       = document.getElementById('modalOverlay');
    const modalTitle         = document.getElementById('modalTitle');
    const modalSubtitle      = document.getElementById('modalSubtitle');
    const modalDescBadge     = document.getElementById('modalDescBadge');
    const modalBody          = document.getElementById('modalBody');
    const modalResumen       = document.getElementById('modalResumen');
    const modalDistribucion  = document.getElementById('modalDistribucion');
    const distBars           = document.getElementById('distBars');
    const btnCerrarModal     = document.getElementById('btnCerrarModal');

    const filtroOC          = document.getElementById('filtroOC');
    const filtroLinea       = document.getElementById('filtroLinea');
    const filtroOrden       = document.getElementById('filtroOrden');
    const filtroResultCount = document.getElementById('filtroResultCount');

    let fileToUpload  = null;
    let allDivisiones = [];

    function filtrarTabla() {
        const valOP      = searchOP.value.toLowerCase().trim();
        const valCliente = searchCliente.value.toLowerCase().trim();
        const valEstado  = searchEstado.value;
        const filas      = tbodyOP.querySelectorAll('tr');

        filas.forEach(fila => {
            const txtOP      = fila.cells[0].textContent.toLowerCase();
            const txtCliente = fila.cells[1].textContent.toLowerCase();
            const txtEstado  = fila.getAttribute('data-estado');

            const ok = txtOP.includes(valOP) && txtCliente.includes(valCliente) &&
                       (valEstado === '' || txtEstado === valEstado);
            fila.style.display = ok ? '' : 'none';
        });
    }

    [searchOP, searchCliente].forEach(el => el.addEventListener('input', filtrarTabla));
    searchEstado.addEventListener('change', filtrarTabla);
    filtrarTabla();

    const LINE_COLORS = [
        '#2196f3','#e91e63','#ff9800','#4caf50','#9c27b0',
        '#00bcd4','#f44336','#8bc34a','#ff5722','#607d8b'
    ];
    const lineColorMap = {};
    let colorIdx = 0;
    function getLineaColor(lineaId) {
        const key = lineaId || '__sin_linea__';
        if (!lineColorMap[key]) {
            lineColorMap[key] = LINE_COLORS[colorIdx % LINE_COLORS.length];
            colorIdx++;
        }
        return lineColorMap[key];
    }

    function renderDistribucion(divs) {
        const totales = {};
        let grandTotal = 0;
        divs.forEach(d => {
            const k = d.id_linea ? `Línea ${d.id_linea}` : 'Sin línea';
            totales[k] = (totales[k] || 0) + parseFloat(d.cantidad || 0);
            grandTotal += parseFloat(d.cantidad || 0);
        });

        if (Object.keys(totales).length === 0) {
            modalDistribucion.style.display = 'none';
            return;
        }

        distBars.innerHTML = '';
        Object.entries(totales)
            .sort((a, b) => b[1] - a[1])
            .forEach(([linea, total]) => {
                const pct = grandTotal > 0 ? (total / grandTotal * 100).toFixed(1) : 0;
                const color = getLineaColor(linea.replace('Línea ', ''));
                const bar = document.createElement('div');
                bar.className = 'dist-bar-row';
                bar.innerHTML = `
                    <div class="dist-bar-label">
                        <span class="dist-dot" style="background:${color}"></span>
                        ${linea}
                    </div>
                    <div class="dist-bar-track">
                        <div class="dist-bar-fill" style="width:${pct}%; background:${color}"></div>
                    </div>
                    <div class="dist-bar-info">
                        <span class="dist-bar-pct">${pct}%</span>
                        <span class="dist-bar-val">${total.toLocaleString('en-US')} prendas</span>
                    </div>
                `;
                distBars.appendChild(bar);
            });

        modalDistribucion.style.display = 'block';

        requestAnimationFrame(() => {
            distBars.querySelectorAll('.dist-bar-fill').forEach(el => {
                el.style.transition = 'width 0.6s cubic-bezier(0.4,0,0.2,1)';
            });
        });
    }

    function renderCards(divs) {
        if (divs.length === 0) {
            modalBody.innerHTML = `
                <div class="modal-empty">
                    <i class="bx bx-filter-alt"></i>
                    <p>No se encontraron divisiones con los filtros aplicados.</p>
                </div>`;
            filtroResultCount.textContent = '0 resultados';
            filtroResultCount.className = 'filtro-result-count no-results';
            return;
        }

        filtroResultCount.textContent = `${divs.length} resultado${divs.length !== 1 ? 's' : ''}`;
        filtroResultCount.className = 'filtro-result-count';

        const grid = document.createElement('div');
        grid.className = 'divisiones-grid';

        divs.forEach((d, index) => {
            const fecha  = d.fecha_corte ? d.fecha_corte : '—';
            const obs    = d.observacion ? d.observacion.trim() : '';
            const linea  = d.id_linea ? `Línea ${d.id_linea}` : 'Sin línea asignada';
            const cant   = parseFloat(d.cantidad || 0).toLocaleString('en-US', {minimumFractionDigits: 0});
            const color  = getLineaColor(d.id_linea);

            const card = document.createElement('div');
            card.className = 'division-card';
            card.style.setProperty('--linea-color', color);
            card.innerHTML = `
                <div class="card-header-oc" style="background: linear-gradient(135deg, color-mix(in srgb, ${color} 70%, #0d1b2a) 0%, #1e3a5f 100%);">
                    <span class="card-oc-id">
                        <i class="bx bx-cut"></i>
                        OC #${d.id_oc}
                    </span>
                    <span class="card-linea-badge" style="background: rgba(255,255,255,0.15); border-color: rgba(255,255,255,0.3); color:#fff;">
                        <span class="linea-dot" style="background:${color}"></span>
                        ${linea}
                    </span>
                </div>
                <div class="card-body-oc">
                    <div class="card-cantidad-highlight" style="border-color: color-mix(in srgb, ${color} 30%, #e2e8f0);">
                        <div>
                            <div class="qty-label">Cantidad de prendas</div>
                            <div class="qty-value" style="color:${color}">${cant}</div>
                        </div>
                        <i class="bx bx-package" style="color:${color}; opacity:0.4;"></i>
                    </div>

                    <div class="card-dato">
                        <i class="bx bx-calendar"></i>
                        <div>
                            <span class="card-dato-label">Fecha de Corte</span>
                            <span class="card-dato-value">${fecha}</span>
                        </div>
                    </div>

                    <div class="${obs ? 'card-obs' : 'card-obs card-obs-empty'}">
                        ${obs
                            ? `<i class="bx bx-message-detail"></i> ${obs}`
                            : '<i class="bx bx-message-x"></i> Sin observaciones registradas'}
                    </div>
                </div>
            `;
            grid.appendChild(card);
        });

        modalBody.innerHTML = '';
        modalBody.appendChild(grid);
    }

    function aplicarFiltrosModal() {
        const valOC    = filtroOC.value.toLowerCase().trim();
        const valLinea = filtroLinea.value;
        const orden    = filtroOrden.value;

        let resultado = allDivisiones.filter(d => {
            const okOC    = valOC === '' || String(d.id_oc).includes(valOC);
            const okLinea = valLinea === '' ||
                            (valLinea === '__sin_linea__' ? !d.id_linea : String(d.id_linea) === valLinea);
            return okOC && okLinea;
        });

        resultado.sort((a, b) => {
            switch (orden) {
                case 'oc_asc':    return a.id_oc - b.id_oc;
                case 'oc_desc':   return b.id_oc - a.id_oc;
                case 'cant_desc': return parseFloat(b.cantidad) - parseFloat(a.cantidad);
                case 'cant_asc':  return parseFloat(a.cantidad) - parseFloat(b.cantidad);
                case 'fecha_asc': return (a.fecha_corte || '').localeCompare(b.fecha_corte || '');
                case 'fecha_desc':return (b.fecha_corte || '').localeCompare(a.fecha_corte || '');
                default: return 0;
            }
        });

        renderCards(resultado);
    }

    filtroOC.addEventListener('input', aplicarFiltrosModal);
    filtroLinea.addEventListener('change', aplicarFiltrosModal);
    filtroOrden.addEventListener('change', aplicarFiltrosModal);

    tbodyOP.addEventListener('click', (e) => {
        const btn = e.target.closest('.btn-ver-divisiones');
        if (!btn) return;

        const idOP    = btn.dataset.id;
        const cliente = btn.dataset.cliente;
        const estilo  = btn.dataset.estilo;
        const desc    = btn.dataset.desc  || '';
        const total   = btn.dataset.total || '0';

        filtroOC.value    = '';
        filtroOrden.value = 'oc_asc';
        filtroResultCount.textContent = '';
        modalDistribucion.style.display = 'none';
        allDivisiones = [];

        modalTitle.textContent    = `Divisiones de Corte — OP #${idOP}`;
        modalSubtitle.textContent = `${cliente}  ·  Estilo: ${estilo}`;
        modalDescBadge.textContent = desc;

        modalResumen.innerHTML = '';
        modalBody.innerHTML = `
            <div class="modal-loading">
                <i class="bx bx-loader-alt"></i>
                <span>Cargando divisiones...</span>
            </div>`;

        modalOverlay.style.display = 'flex';
        document.body.style.overflow = 'hidden';

        fetch(`ordenPedido.php?action=get_divisiones&id_op=${idOP}&_=${Date.now()}`)
            .then(res => res.json())
            .then(data => {
                if (!data.success) {
                    modalBody.innerHTML = `
                        <div class="modal-empty">
                            <i class="bx bx-error-circle"></i>
                            <p>${data.message || 'Error al obtener las divisiones.'}</p>
                        </div>`;
                    return;
                }

                const divs = data.divisiones;
                allDivisiones = divs;

                if (divs.length === 0) {
                    modalBody.innerHTML = `
                        <div class="modal-empty">
                            <i class="bx bx-layer-minus"></i>
                            <p>Esta orden no tiene divisiones de corte registradas.</p>
                        </div>`;
                    filtroLinea.innerHTML = '<option value="">Todas las Líneas</option>';
                    return;
                }

                const lineasUnicas = [...new Set(divs.map(d => d.id_linea).filter(Boolean))].sort((a,b) => a-b);
                const tieneSinLinea = divs.some(d => !d.id_linea);

                filtroLinea.innerHTML = '<option value="">Todas las Líneas</option>';
                lineasUnicas.forEach(l => {
                    const opt = document.createElement('option');
                    opt.value = l;
                    opt.textContent = `Línea ${l}`;
                    filtroLinea.appendChild(opt);
                });
                if (tieneSinLinea) {
                    const opt = document.createElement('option');
                    opt.value = '__sin_linea__';
                    opt.textContent = 'Sin línea asignada';
                    filtroLinea.appendChild(opt);
                }

                const totalCantidad  = divs.reduce((acc, d) => acc + parseFloat(d.cantidad || 0), 0);
                const cantidadOP     = parseFloat(total);
                const pctCubierto    = cantidadOP > 0 ? (totalCantidad / cantidadOP * 100).toFixed(1) : '—';
                const conFecha       = divs.filter(d => d.fecha_corte).length;
                const proximaFecha   = divs
                    .filter(d => d.fecha_corte)
                    .map(d => d.fecha_corte)
                    .sort()
                    .find(f => f >= new Date().toISOString().slice(0, 10)) || null;

                modalResumen.innerHTML = `
                    <span class="resumen-chip">
                        <i class="bx bx-layer"></i>
                        <strong>${divs.length}</strong> divisiones
                    </span>
                    <span class="resumen-chip">
                        <i class="bx bx-t-shirt"></i>
                        <strong>${totalCantidad.toLocaleString('en-US')}</strong> prendas
                    </span>
                    ${lineasUnicas.length > 0 ? `
                    <span class="resumen-chip">
                        <i class="bx bx-git-branch"></i>
                        <strong>${lineasUnicas.length}</strong> línea${lineasUnicas.length !== 1 ? 's' : ''}
                    </span>` : ''}
                    ${pctCubierto !== '—' ? `
                    <span class="resumen-chip resumen-chip-highlight">
                        <i class="bx bx-pie-chart-alt-2"></i>
                        <strong>${pctCubierto}%</strong> de OP cubierto
                    </span>` : ''}
                    ${conFecha > 0 ? `
                    <span class="resumen-chip">
                        <i class="bx bx-calendar-check"></i>
                        <strong>${conFecha}</strong> con fecha programada
                    </span>` : ''}
                    ${proximaFecha ? `
                    <span class="resumen-chip resumen-chip-date">
                        <i class="bx bx-time"></i>
                        Próx. corte: <strong>${proximaFecha}</strong>
                    </span>` : ''}
                `;

                renderDistribucion(divs);

                aplicarFiltrosModal();
            })
            .catch(err => {
                console.error(err);
                modalBody.innerHTML = `
                    <div class="modal-empty">
                        <i class="bx bx-wifi-off"></i>
                        <p>Error de comunicación con el servidor.</p>
                    </div>`;
            });
    });

    function cerrarModal() {
        modalOverlay.style.display = 'none';
        document.body.style.overflow = '';
        Object.keys(lineColorMap).forEach(k => delete lineColorMap[k]);
        colorIdx = 0;
    }

    btnCerrarModal.addEventListener('click', cerrarModal);
    modalOverlay.addEventListener('click', (e) => { if (e.target === modalOverlay) cerrarModal(); });
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && modalOverlay.style.display === 'flex') cerrarModal();
    });

    excelFile.addEventListener('change', () => {
        if (excelFile.files.length === 0) return;

        fileToUpload = excelFile.files[0];
        fileName.textContent = fileToUpload.name;

        statusAlert.style.display = 'block';
        statusAlert.className = 'alert-box info';
        statusAlert.innerHTML = "<i class='bx bx-loader-alt bx-spin'></i> Leyendo estructura del archivo Excel para previsualización...";

        const formData = new FormData();
        formData.append('excel_file', fileToUpload);
        formData.append('action', 'preview');

        fetch('ordenPedido.php', { method: 'POST', body: formData })
        .then(res => res.json())
        .then(data => {
            if (data.success && data.preview_data) {
                statusAlert.style.display = 'none';
                tbodyPreview.innerHTML = '';
                
                data.preview_data.forEach(item => {
                    const tr = document.createElement('tr');
                    tr.innerHTML = `
                        <td><strong class="text-primary">${item.op}</strong></td>
                        <td>${item.cliente}</td>
                        <td><span class="badge-estilo">${item.estilo}</span></td>
                        <td>${item.descripcion}</td>
                        <td>${Number(item.cantidad).toLocaleString('en-US', {minimumFractionDigits: 2})}</td>
                        <td>
                            <span class="badge-divisiones">
                                <i class="bx bx-layer"></i> ${item.divisiones} cortes
                            </span>
                        </td>
                        <td>${item.fecha}</td>
                    `;
                    tbodyPreview.appendChild(tr);
                });

                previewContainer.style.display = 'block';
                mainTableWrapper.style.opacity = '0.3';
            } else {
                statusAlert.className = 'alert-box error';
                statusAlert.innerHTML = `<b>Error en Análisis:</b> ${data.message}`;
                resetPreviewState();
            }
        })
        .catch(err => {
            console.error(err);
            statusAlert.className = 'alert-box error';
            statusAlert.innerHTML = '<b>Error Crítico:</b> El servidor no pudo procesar la vista previa.';
            resetPreviewState();
        });
    });

    btnConfirmarCarga.addEventListener('click', () => {
        if (!fileToUpload) return;

        statusAlert.style.display = 'block';
        statusAlert.className = 'alert-box info';
        statusAlert.innerHTML = "<i class='bx bx-loader-alt bx-spin'></i> Ejecutando transacciones en Base de Datos MySQL via Python...";

        const formData = new FormData();
        formData.append('excel_file', fileToUpload);
        formData.append('action', 'load');

        fetch('ordenPedido.php', { method: 'POST', body: formData })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                statusAlert.className = 'alert-box success';
                statusAlert.innerHTML = `<b>¡Migración Exitosa!</b> ${data.message}<br>Órdenes cargadas: ${data.ordenes} | Divisiones de corte añadidas: ${data.cortes}`;
                previewContainer.style.display = 'none';
                setTimeout(() => window.location.reload(), 3000);
            } else {
                statusAlert.className = 'alert-box error';
                statusAlert.innerHTML = `<b>Error en Inserción:</b> ${data.message}`;
            }
        })
        .catch(err => {
            console.error(err);
            statusAlert.className = 'alert-box error';
            statusAlert.innerHTML = '<b>Error Crítico:</b> Fallo de comunicación de datos en el servidor.';
        });
    });

    btnCancelarCarga.addEventListener('click', () => {
        resetPreviewState();
        statusAlert.style.display = 'block';
        statusAlert.className = 'alert-box info';
        statusAlert.innerHTML = 'Carga de archivo cancelada por el usuario.';
        setTimeout(() => statusAlert.style.display = 'none', 2500);
    });

    function resetPreviewState() {
        fileToUpload = null;
        excelFile.value = '';
        fileName.textContent = 'Formatos: .xlsx, .xlsm';
        previewContainer.style.display = 'none';
        mainTableWrapper.style.opacity = '1';
    }
});

document.addEventListener('DOMContentLoaded', function destacarOP() {
    const idOP = sessionStorage.getItem('highlightOP');
    if (!idOP) return;
    sessionStorage.removeItem('highlightOP');

    const selectEstado = document.getElementById('searchEstado');
    if (selectEstado && selectEstado.value !== '') {
        selectEstado.value = '';
        selectEstado.dispatchEvent(new Event('change'));
    }

    const tbodyOP = document.getElementById('tbodyOP');
    if (!tbodyOP) return;

    let filaTarget = null;
    for (const fila of tbodyOP.querySelectorAll('tr')) {
        if (fila.cells[0]?.textContent?.trim() === String(idOP)) {
            filaTarget = fila;
            break;
        }
    }
    if (!filaTarget) return;

    filaTarget.style.display = '';

    setTimeout(() => {
        filaTarget.scrollIntoView({ behavior: 'smooth', block: 'center' });
        filaTarget.classList.add('op-highlight');

        setTimeout(() => {
            filaTarget.classList.remove('op-highlight');
        }, 1500);
    }, 200);
});