document.addEventListener('DOMContentLoaded', () => {

    const D = window.DASH_DATA || {};

    const PALETTE = {
        blue:   '#2196f3',
        amber:  '#f59e0b',
        green:  '#22c55e',
        indigo: '#6366f1',
        teal:   '#14b8a6',
        rose:   '#f43f5e',
        slate:  '#64748b',
        purple: '#a855f7',
        orange: '#fb923c',
        cyan:   '#06b6d4',
    };
    const COLORS = Object.values(PALETTE);

    Chart.defaults.font.family = "'DM Sans', 'Segoe UI', system-ui, sans-serif";
    Chart.defaults.font.size   = 12;
    Chart.defaults.color       = '#64748b';
    Chart.defaults.plugins.legend.display = false;

    function makeGradient(ctx, colorTop, colorBottom, height = 300) {
        const g = ctx.createLinearGradient(0, 0, 0, height);
        g.addColorStop(0, colorTop);
        g.addColorStop(1, colorBottom);
        return g;
    }

    function animateCount(el, target, duration = 1400) {
        let start = null;
        const step = ts => {
            if (!start) start = ts;
            const p = Math.min((ts - start) / duration, 1);
            const ease = 1 - Math.pow(1 - p, 3);
            el.textContent = Math.floor(target * ease).toLocaleString('en-US');
            if (p < 1) requestAnimationFrame(step);
        };
        requestAnimationFrame(step);
    }

    document.querySelectorAll('.kpi-tile-val[data-count]').forEach((el, i) => {
        const target = parseInt(el.dataset.count) || 0;
        setTimeout(() => animateCount(el, target), i * 120);
    });

    const updateEl = document.getElementById('lastUpdate');
    function setUpdateTime() {
        if (updateEl) updateEl.textContent = new Date().toLocaleTimeString('es-PE',
            { hour: '2-digit', minute: '2-digit', second: '2-digit' });
    }
    setUpdateTime();
    let countdown = 300;
    setInterval(() => {
        countdown--;
        setUpdateTime();
        if (countdown <= 0) window.location.reload();
    }, 1000);

    document.getElementById('btnRefresh')?.addEventListener('click', () => {
        document.getElementById('btnRefresh').classList.add('spinning');
        setTimeout(() => window.location.reload(), 300);
    });

    const filterPanel = document.getElementById('filterPanel');
    document.getElementById('btnFilter')?.addEventListener('click', () => {
        if (!filterPanel) return;
        const visible = filterPanel.style.display === 'block';
        filterPanel.style.display = visible ? 'none' : 'block';
    });

    function exportTableToExcel(tableId, filename) {
        if (typeof XLSX === 'undefined') {
            alert('La librería Excel (SheetJS) no está cargada. Verifica tu conexión.');
            return;
        }

        const table = document.getElementById(tableId);
        if (!table) return;

        const rows   = [...table.querySelectorAll('tr')];
        const data   = rows.map(r =>
            [...r.querySelectorAll('th, td')].map(c => c.innerText.trim())
        );

        const wb = XLSX.utils.book_new();
        const ws = XLSX.utils.aoa_to_sheet(data);

        const colWidths = data[0].map((_, colIdx) => ({
            wch: Math.min(
                40,
                Math.max(10, ...data.map(row => (row[colIdx] || '').length))
            )
        }));
        ws['!cols'] = colWidths;

        const headerStyle = {
            font:      { bold: true, color: { rgb: 'FFFFFF' }, sz: 11 },
            fill:      { fgColor: { rgb: '2196F3' } },
            alignment: { horizontal: 'center', vertical: 'center' },
            border: {
                bottom: { style: 'medium', color: { rgb: '1565C0' } }
            }
        };

        const rowStyle = {
            font:      { sz: 10 },
            alignment: { vertical: 'center' }
        };
        const rowAltStyle = {
            font:      { sz: 10 },
            fill:      { fgColor: { rgb: 'F0F4F8' } },
            alignment: { vertical: 'center' }
        };

        data.forEach((row, rIdx) => {
            row.forEach((_, cIdx) => {
                const cellRef = XLSX.utils.encode_cell({ r: rIdx, c: cIdx });
                if (!ws[cellRef]) return;
                if (rIdx === 0) {
                    ws[cellRef].s = headerStyle;
                } else {
                    ws[cellRef].s = rIdx % 2 === 0 ? rowAltStyle : rowStyle;
                }
            });
        });

        ws['!rows'] = data.map((_, i) => ({ hpt: i === 0 ? 22 : 18 }));

        XLSX.utils.book_append_sheet(wb, ws, 'OPs Recientes');
        XLSX.writeFile(wb, filename);
    }

    function exportDashboardExcel() {
        if (typeof XLSX === 'undefined') {
            alert('La librería Excel (SheetJS) no está cargada. Verifica tu conexión.');
            return;
        }

        const wb   = XLSX.utils.book_new();
        const date = new Date().toLocaleDateString('es-PE');

        const kpis = D.kpis || {};
        const ef   = D.eficiencia || {};
        const kpiData = [
            ['CMT — Resumen del Dashboard', ''],
            ['Generado el:', date],
            ['', ''],
            ['INDICADOR', 'VALOR'],
            ['Total Órdenes de Pedido',    kpis.total_ops          || 0],
            ['OPs Pendientes',             kpis.ops_pendientes     || 0],
            ['OPs Completadas',            kpis.ops_completadas    || 0],
            ['Total Prendas',              kpis.total_prendas      || 0],
            ['Prendas en Proceso',         kpis.prendas_en_proceso || 0],
            ['Total Clientes',             kpis.total_clientes     || 0],
            ['Total Líneas',               kpis.total_lineas       || 0],
            ['Líneas Activas',             kpis.lineas_activas     || 0],
            ['Total Órdenes de Corte',     kpis.total_oc           || 0],
            ['', ''],
            ['EFICIENCIA GLOBAL', ''],
            ['Promedio Cumplimiento (%)',   ef.promedio  || 0],
            ['Mínimo (%)',                  ef.minimo    || 0],
            ['Máximo (%)',                  ef.maximo    || 0],
            ['OPs Sobre Meta (≥85%)',       ef.sobre_meta || 0],
        ];
        const wsKpi = XLSX.utils.aoa_to_sheet(kpiData);
        wsKpi['!cols'] = [{ wch: 32 }, { wch: 16 }];
        if (wsKpi['A1']) wsKpi['A1'].s = { font: { bold: true, sz: 14, color: { rgb: '2196F3' } } };
        ['A4', 'B4'].forEach(ref => {
            if (wsKpi[ref]) wsKpi[ref].s = {
                font: { bold: true, color: { rgb: 'FFFFFF' } },
                fill: { fgColor: { rgb: '2196F3' } }
            };
        });
        XLSX.utils.book_append_sheet(wb, wsKpi, 'Resumen KPIs');

        const tableEl = document.getElementById('opsTable');
        if (tableEl) {
            const rows   = [...tableEl.querySelectorAll('tr')];
            const opsRaw = rows.map(r =>
                [...r.querySelectorAll('th, td')].map(c => c.innerText.trim())
            );
            const wsOps = XLSX.utils.aoa_to_sheet(opsRaw);
            wsOps['!cols'] = opsRaw[0]?.map((_, ci) => ({
                wch: Math.min(35, Math.max(10, ...opsRaw.map(r => (r[ci] || '').length)))
            }));
            if (opsRaw[0]) {
                opsRaw[0].forEach((_, ci) => {
                    const ref = XLSX.utils.encode_cell({ r: 0, c: ci });
                    if (wsOps[ref]) wsOps[ref].s = {
                        font: { bold: true, color: { rgb: 'FFFFFF' } },
                        fill: { fgColor: { rgb: '2196F3' } },
                        alignment: { horizontal: 'center' }
                    };
                });
            }
            XLSX.utils.book_append_sheet(wb, wsOps, 'OPs Recientes');
        }

        const clientes = D.topClientes || [];
        if (clientes.length) {
            const cliRows = [
                ['Cliente', 'Total Prendas', 'Total OPs', 'OPs Pendientes'],
                ...clientes.map(c => [
                    c.nombre_cliente,
                    c.total_prendas,
                    c.total_ops,
                    c.pendientes
                ])
            ];
            const wsCli = XLSX.utils.aoa_to_sheet(cliRows);
            wsCli['!cols'] = [{ wch: 30 }, { wch: 16 }, { wch: 12 }, { wch: 16 }];
            cliRows[0].forEach((_, ci) => {
                const ref = XLSX.utils.encode_cell({ r: 0, c: ci });
                if (wsCli[ref]) wsCli[ref].s = {
                    font: { bold: true, color: { rgb: 'FFFFFF' } },
                    fill: { fgColor: { rgb: '14B8A6' } },
                    alignment: { horizontal: 'center' }
                };
            });
            XLSX.utils.book_append_sheet(wb, wsCli, 'Top Clientes');
        }

        const estilos = D.topEstilos || [];
        if (estilos.length) {
            const estRows = [
                ['Estilo', 'Total Prendas', 'Total OPs'],
                ...estilos.map(e => [e.estilo, e.total_prendas, e.total_ops])
            ];
            const wsEst = XLSX.utils.aoa_to_sheet(estRows);
            wsEst['!cols'] = [{ wch: 20 }, { wch: 16 }, { wch: 12 }];
            estRows[0].forEach((_, ci) => {
                const ref = XLSX.utils.encode_cell({ r: 0, c: ci });
                if (wsEst[ref]) wsEst[ref].s = {
                    font: { bold: true, color: { rgb: 'FFFFFF' } },
                    fill: { fgColor: { rgb: 'F59E0B' } },
                    alignment: { horizontal: 'center' }
                };
            });
            XLSX.utils.book_append_sheet(wb, wsEst, 'Top Estilos');
        }

        const lineas = D.cargaLineas || [];
        if (lineas.length) {
            const linRows = [
                ['Línea', 'Estado', 'Carga Actual (Prendas)', 'OCs Activas', 'OPs Activas', 'Operarios'],
                ...lineas.map(l => [
                    `Línea ${l.num_linea}`,
                    l.estado_linea,
                    l.carga_actual,
                    l.ocs_activas,
                    l.ops_activas,
                    l.num_operarios || 0
                ])
            ];
            const wsLin = XLSX.utils.aoa_to_sheet(linRows);
            wsLin['!cols'] = [
                { wch: 10 }, { wch: 12 }, { wch: 22 },
                { wch: 13 }, { wch: 13 }, { wch: 12 }
            ];
            linRows[0].forEach((_, ci) => {
                const ref = XLSX.utils.encode_cell({ r: 0, c: ci });
                if (wsLin[ref]) wsLin[ref].s = {
                    font: { bold: true, color: { rgb: 'FFFFFF' } },
                    fill: { fgColor: { rgb: 'F43F5E' } },
                    alignment: { horizontal: 'center' }
                };
            });
            XLSX.utils.book_append_sheet(wb, wsLin, 'Carga por Línea');
        }

        const ts = new Date().toISOString().slice(0, 10);
        XLSX.writeFile(wb, `cmt_dashboard_${ts}.xlsx`);
    }

    document.getElementById('btnExportTable')?.addEventListener('click', () => {
        const ts = new Date().toISOString().slice(0, 10);
        exportTableToExcel('opsTable', `ops_recientes_${ts}.xlsx`);
    });

    document.getElementById('btnExport')?.addEventListener('click', () => {
        exportDashboardExcel();
    });

    const cargaData   = D.cargaLineas  || [];
    const lineasData  = D.lineas       || [];
    let modeLineas    = 'actual';
    const ctxL = document.getElementById('chartLineas')?.getContext('2d');
    let chartLineas   = null;

    const alertPlugin = {
        id: 'lineaAlert',
        afterDatasetsDraw(chart) {
            const { ctx } = chart;
            const ds   = chart.data.datasets[0];
            const meta = chart.getDatasetMeta(0);
            const vals = ds.data.filter(v => v > 0);
            if (!vals.length) return;
            const maxV      = Math.max(...vals);
            const threshold = maxV * 0.30;
            let alertCount  = 0;

            meta.data.forEach((bar, i) => {
                const val = ds.data[i];
                if (val > 0 && val < threshold) {
                    alertCount++;
                    ctx.save();
                    ctx.fillStyle = 'rgba(244,63,94,0.12)';
                    ctx.beginPath();
                    ctx.roundRect(bar.x - 14, bar.y - 28, 28, 22, 4);
                    ctx.fill();
                    ctx.font      = 'bold 14px sans-serif';
                    ctx.textAlign = 'center';
                    ctx.fillStyle = '#f43f5e';
                    ctx.fillText('⚠', bar.x, bar.y - 12);
                    ctx.restore();
                }
            });

            const badge   = document.getElementById('alertBadge');
            const countEl = document.getElementById('alertCount');
            if (badge && countEl) {
                countEl.textContent = alertCount;
                badge.style.display = alertCount > 0 ? 'inline-flex' : 'none';
            }
        }
    };

    function buildChartLineas() {
        if (chartLineas) chartLineas.destroy();
        if (!ctxL) return;

        let labels = [], vals = [], colorsBar = [], borderBar = [];

        if (modeLineas === 'actual') {
            labels = cargaData.map(r => `L${r.num_linea}`);
            vals   = cargaData.map(r => r.carga_actual);
        } else if (modeLineas === 'historico') {
            labels = lineasData.map(r => `L${r.num_linea}`);
            vals   = lineasData.map(r => r.total_prendas);
        } else {
            labels = lineasData.map(r => `L${r.num_linea}`);
            vals   = lineasData.map(r => r.total_oc);
        }

        const maxV = Math.max(...vals.filter(v => v > 0), 1);

        colorsBar = vals.map(v => {
            const pct = v / maxV;
            return pct > 0.70 ? PALETTE.rose  + 'cc'
                 : pct > 0.40 ? PALETTE.amber + 'cc'
                 :               PALETTE.teal + 'cc';
        });
        borderBar = vals.map(v => {
            const pct = v / maxV;
            return pct > 0.70 ? PALETTE.rose
                 : pct > 0.40 ? PALETTE.amber
                 :               PALETTE.teal;
        });

        chartLineas = new Chart(ctxL, {
            type: 'bar',
            data: {
                labels,
                datasets: [{
                    label: modeLineas === 'oc' ? 'Órdenes de Corte' : 'Prendas',
                    data: vals,
                    backgroundColor: colorsBar,
                    borderColor:     borderBar,
                    borderWidth: 2,
                    borderRadius: 7,
                    borderSkipped: false,
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                animation: { duration: 750, easing: 'easeOutQuart' },
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        backgroundColor: '#1e293b',
                        titleColor: '#f1f5f9',
                        bodyColor: '#cbd5e1',
                        padding: 12,
                        cornerRadius: 8,
                        callbacks: {
                            title: ctx => `Línea ${ctx[0].label.replace('L','')}`,
                            label: ctx => {
                                const v = ctx.parsed.y;
                                const label = modeLineas === 'oc' ? 'Órdenes de corte' : 'Prendas';
                                return ` ${label}: ${v.toLocaleString('en-US')}`;
                            },
                            afterLabel: ctx => {
                                const maxVal = Math.max(...vals.filter(v=>v>0),1);
                                const pct = ((ctx.parsed.y / maxVal) * 100).toFixed(0);
                                const isLow = ctx.parsed.y > 0 && ctx.parsed.y < maxVal * 0.3;
                                return isLow ? ` ⚠️ Producción baja (${pct}% del máximo)` : ` ${pct}% del máximo`;
                            }
                        }
                    }
                },
                scales: {
                    x: {
                        grid: { display: false },
                        ticks: { font: { size: 11, weight: '600' } }
                    },
                    y: {
                        grid: { color: 'rgba(0,0,0,0.04)' },
                        beginAtZero: true,
                        ticks: {
                            callback: v => v >= 1000 ? (v/1000).toFixed(1)+'k' : v
                        }
                    }
                }
            },
            plugins: [alertPlugin]
        });
    }

    buildChartLineas();

    const tendenciaData = D.tendencia || [];
    const labels_t  = tendenciaData.map(r => r.mes_label || r.mes);
    const pend_t    = tendenciaData.map(r => r.pendientes);
    const comp_t    = tendenciaData.map(r => r.completadas);
    const prend_t   = tendenciaData.map(r => r.prendas);

    let modeTendencia = 'ordenes';
    const ctxT = document.getElementById('chartTendencia')?.getContext('2d');
    let chartTendencia = null;

    function buildChartTendencia() {
        if (chartTendencia) chartTendencia.destroy();
        if (!ctxT) return;

        const gAmber = makeGradient(ctxT, 'rgba(245,158,11,0.75)', 'rgba(245,158,11,0.05)', 260);
        const gGreen = makeGradient(ctxT, 'rgba(34,197,94,0.75)',  'rgba(34,197,94,0.05)',  260);
        const gBlue  = makeGradient(ctxT, 'rgba(33,150,243,0.7)',  'rgba(33,150,243,0.05)', 260);

        const datasets = modeTendencia === 'ordenes' ? [
            {
                label: 'Pendientes',
                data: pend_t,
                backgroundColor: gAmber,
                borderColor: PALETTE.amber,
                borderWidth: 2, borderRadius: 5, borderSkipped: false,
            },
            {
                label: 'Completadas',
                data: comp_t,
                backgroundColor: gGreen,
                borderColor: PALETTE.green,
                borderWidth: 2, borderRadius: 5, borderSkipped: false,
            }
        ] : [{
            label: 'Prendas',
            data: prend_t,
            backgroundColor: gBlue,
            borderColor: PALETTE.blue,
            borderWidth: 2, fill: true, tension: 0.4, type: 'line',
            pointBackgroundColor: PALETTE.blue,
            pointRadius: 3, pointHoverRadius: 6,
        }];

        chartTendencia = new Chart(ctxT, {
            type: modeTendencia === 'ordenes' ? 'bar' : 'line',
            data: { labels: labels_t, datasets },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                animation: { duration: 600, easing: 'easeOutQuart' },
                plugins: {
                    legend: {
                        display: modeTendencia === 'ordenes',
                        position: 'top',
                        labels: { usePointStyle: true, pointStyle: 'circle', boxWidth: 7, padding: 12, font: { size: 11 } }
                    },
                    tooltip: {
                        backgroundColor: '#1e293b',
                        titleColor: '#f1f5f9',
                        bodyColor: '#cbd5e1',
                        padding: 10,
                        cornerRadius: 8,
                        callbacks: {
                            label: ctx => ` ${ctx.dataset.label}: ${ctx.parsed.y.toLocaleString('en-US')}`
                        }
                    }
                },
                scales: {
                    x: {
                        grid: { display: false },
                        ticks: { maxRotation: 45, font: { size: 10 } }
                    },
                    y: {
                        grid: { color: 'rgba(0,0,0,0.04)' },
                        beginAtZero: true,
                        ticks: {
                            font: { size: 10 },
                            callback: v => modeTendencia === 'prendas'
                                ? (v >= 1000 ? (v/1000).toFixed(0)+'k' : v) : v
                        }
                    }
                }
            }
        });
    }

    buildChartTendencia();

    const estadoData  = D.estadoOps || [];
    const donutColors = { 'Pendiente': PALETTE.amber, 'Completado': PALETTE.green };
    const ctxD = document.getElementById('chartDonut')?.getContext('2d');

    if (ctxD && estadoData.length) {
        new Chart(ctxD, {
            type: 'doughnut',
            data: {
                labels: estadoData.map(r => r.estado),
                datasets: [{
                    data: estadoData.map(r => r.cantidad),
                    backgroundColor: estadoData.map(r => donutColors[r.estado] || PALETTE.slate),
                    borderWidth: 3,
                    borderColor: '#fff',
                    hoverBorderColor: '#fff',
                    hoverOffset: 8,
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '68%',
                animation: { animateRotate: true, duration: 900 },
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        backgroundColor: '#1e293b',
                        callbacks: {
                            label: ctx => ` ${ctx.label}: ${ctx.parsed.toLocaleString()} órdenes`
                        }
                    }
                }
            }
        });

        const legendEl = document.getElementById('donutLegend');
        if (legendEl) {
            estadoData.forEach(r => {
                const totalOps = D.kpis?.total_ops || 1;
                const pct   = ((r.cantidad / totalOps) * 100).toFixed(1);
                const color = donutColors[r.estado] || PALETTE.slate;
                legendEl.innerHTML += `
                    <div class="donut-legend-item">
                        <span class="dl-dot" style="background:${color}"></span>
                        <span class="dl-label">${r.estado}</span>
                        <span class="dl-val">${r.cantidad.toLocaleString()}</span>
                        <span class="dl-pct">${pct}%</span>
                    </div>`;
            });
        }
    }

    const ef      = D.eficiencia || {};
    const efValue = Math.min(Math.max(ef.promedio || 0, 0), 100);
    const ctxG    = document.getElementById('chartGauge')?.getContext('2d');

    const gaugeNeedle = {
        id: 'gaugeNeedle',
        afterDatasetsDraw(chart) {
            const { ctx, chartArea: { left, top, width, height } } = chart;
            const cx    = left + width / 2;
            const cy    = top + height;
            const r     = Math.min(width, height * 2) * 0.36;
            const angle = Math.PI + (Math.PI * (efValue / 100));
            const nx = cx + r * Math.cos(angle);
            const ny = cy + r * Math.sin(angle);

            ctx.save();
            ctx.beginPath();
            ctx.moveTo(cx, cy);
            ctx.lineTo(nx, ny);
            ctx.strokeStyle = '#374151';
            ctx.lineWidth   = 3;
            ctx.lineCap     = 'round';
            ctx.stroke();
            ctx.beginPath();
            ctx.arc(cx, cy, 7, 0, Math.PI * 2);
            ctx.fillStyle = '#374151';
            ctx.fill();
            ctx.beginPath();
            ctx.arc(cx, cy, 3.5, 0, Math.PI * 2);
            ctx.fillStyle = '#fff';
            ctx.fill();
            ctx.restore();
        }
    };

    if (ctxG) {
        const efColor = efValue >= 80 ? PALETTE.green
                      : efValue >= 60 ? PALETTE.amber
                      :                 PALETTE.rose;

        new Chart(ctxG, {
            type: 'doughnut',
            data: {
                datasets: [
                    {
                        data: [efValue, 100 - efValue, 100],
                        backgroundColor: [efColor, '#e2e8f0', 'transparent'],
                        borderWidth: 0,
                        weight: 1,
                    },
                    {
                        data: [30, 30, 40, 100],
                        backgroundColor: [
                            'rgba(244,63,94,0.15)',
                            'rgba(245,158,11,0.15)',
                            'rgba(34,197,94,0.15)',
                            'transparent'
                        ],
                        borderWidth: 0,
                        weight: 0.12,
                    }
                ]
            },
            options: {
                circumference: 180,
                rotation: 270,
                responsive: true,
                maintainAspectRatio: false,
                cutout: '65%',
                plugins: {
                    legend: { display: false },
                    tooltip: { enabled: false }
                },
                animation: { duration: 1200, easing: 'easeOutQuart' }
            },
            plugins: [gaugeNeedle]
        });

        const gaugePctEl = document.getElementById('gaugePct');
        if (gaugePctEl) {
            let start = null;
            const dur = 1200;
            const anim = ts => {
                if (!start) start = ts;
                const p    = Math.min((ts - start) / dur, 1);
                const ease = 1 - Math.pow(1 - p, 3);
                gaugePctEl.textContent = (efValue * ease).toFixed(1) + '%';
                gaugePctEl.style.color = efValue >= 80 ? PALETTE.green
                                       : efValue >= 60 ? PALETTE.amber
                                       :                 PALETTE.rose;
                if (p < 1) requestAnimationFrame(anim);
            };
            requestAnimationFrame(anim);
        }
    }

    const ctxC    = document.getElementById('chartClientes')?.getContext('2d');
    const cliData = D.topClientes || [];

    if (ctxC && cliData.length) {
        const cliLabels = cliData.map(r =>
            r.nombre_cliente.length > 22 ? r.nombre_cliente.substring(0, 20) + '…' : r.nombre_cliente
        );
        new Chart(ctxC, {
            type: 'bar',
            data: {
                labels: cliLabels,
                datasets: [{
                    label: 'Prendas totales',
                    data: cliData.map(r => r.total_prendas),
                    backgroundColor: COLORS.slice(0, cliData.length).map(c => c + 'cc'),
                    borderColor:     COLORS.slice(0, cliData.length),
                    borderWidth: 1.5,
                    borderRadius: 6,
                    borderSkipped: false,
                }]
            },
            options: {
                indexAxis: 'y',
                responsive: true,
                maintainAspectRatio: false,
                animation: { duration: 800, delay: ctx => ctx.dataIndex * 60 },
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        backgroundColor: '#1e293b',
                        callbacks: {
                            label: ctx => ` ${ctx.parsed.x.toLocaleString('en-US')} prendas`,
                            afterLabel: ctx => ` ${cliData[ctx.dataIndex]?.total_ops || 0} OPs`
                        }
                    }
                },
                scales: {
                    x: {
                        grid: { color: 'rgba(0,0,0,0.04)' },
                        ticks: { callback: v => v >= 1000 ? (v/1000).toFixed(0)+'k' : v, font: { size: 10 } }
                    },
                    y: { grid: { display: false }, ticks: { font: { size: 10 } } }
                }
            }
        });
    }

    const estilosData = D.topEstilos || [];
    const ctxE = document.getElementById('chartEstilos')?.getContext('2d');

    if (ctxE && estilosData.length) {
        new Chart(ctxE, {
            type: 'bar',
            data: {
                labels: estilosData.map(r => r.estilo),
                datasets: [{
                    label: 'Prendas',
                    data: estilosData.map(r => r.total_prendas),
                    backgroundColor: COLORS.slice(0, estilosData.length).map(c => c + 'bb'),
                    borderColor:     COLORS.slice(0, estilosData.length),
                    borderWidth: 1.5,
                    borderRadius: 5,
                }]
            },
            options: {
                indexAxis: 'y',
                responsive: true,
                maintainAspectRatio: false,
                animation: { duration: 800, delay: ctx => ctx.dataIndex * 50 },
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        backgroundColor: '#1e293b',
                        callbacks: {
                            label: ctx => ` ${ctx.parsed.x.toLocaleString('en-US')} prendas · ${estilosData[ctx.dataIndex].total_ops} OPs`
                        }
                    }
                },
                scales: {
                    x: {
                        grid: { color: 'rgba(0,0,0,0.04)' },
                        ticks: { callback: v => v >= 1000 ? (v/1000).toFixed(0)+'k' : v, font: { size: 10 } }
                    },
                    y: { grid: { display: false }, ticks: { font: { size: 10 } } }
                }
            }
        });
    }

    document.querySelectorAll('.ctrl-btn').forEach(btn => {
        btn.addEventListener('click', () => {
            btn.closest('.panel-controls')
               .querySelectorAll('.ctrl-btn')
               .forEach(b => b.classList.remove('active'));
            btn.classList.add('active');

            const chartKey = btn.dataset.chart;
            const mode     = btn.dataset.mode;

            if (chartKey === 'tendencia') {
                modeTendencia = mode;
                buildChartTendencia();
            } else if (chartKey === 'lineas') {
                modeLineas = mode;
                buildChartLineas();
            }
        });
    });

});