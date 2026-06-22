let chartTendencia = null, chartEfProyectada = null, chartCargaProy = null;
let chartRiesgoLineas = null, chartDemandaClientes = null, chartEstilosPie = null;
let lastData = null;
let countdownSecs = 300;
let countdownTimer = null;

// Paleta limpia (modo claro)
const C_BLUE   = '#2563eb';
const C_VIO    = '#9333ea';
const C_GREEN  = '#16a34a';
const C_AMBER  = '#d97706';
const C_RED    = '#dc2626';
const C_TEAL   = '#0d9488';
const C_CYAN   = '#0891b2';
const COLORS   = [C_BLUE, C_VIO, C_TEAL, C_AMBER, C_GREEN, '#f43f5e', '#06b6d4', '#818cf8'];

Chart.defaults.font.family    = "'Inter', system-ui, sans-serif";
Chart.defaults.font.size      = 11;
Chart.defaults.color          = '#6b7280';
Chart.defaults.plugins.legend.display = false;

function showLoader(v) {
    document.getElementById('loader').classList.toggle('active', v);
}

function startCountdown() {
    clearInterval(countdownTimer);
    countdownSecs = 300;
    countdownTimer = setInterval(() => {
        countdownSecs--;
        const m = Math.floor(countdownSecs / 60);
        const s = countdownSecs % 60;
        const el = document.getElementById('countdown');
        if (el) el.textContent = `${m}:${s.toString().padStart(2,'0')}`;
        if (countdownSecs <= 0) cargarPredicciones();
    }, 1000);
}

async function cargarPredicciones() {
    const fd = document.getElementById('fecha_desde').value;
    const fh = document.getElementById('fecha_hasta').value;
    const ic = document.getElementById('id_cliente').value;
    showLoader(true);
    const params = new URLSearchParams({ api: 1 });
    if (fd) params.set('fecha_desde', fd);
    if (fh) params.set('fecha_hasta', fh);
    if (ic) params.set('id_cliente', ic);
    const url = `prediccion.php?${params.toString()}`;
    try {
        const res  = await fetch(url);
        const data = await res.json();
        if (!data.success) { alert('Error: ' + (data.message || 'No se pudo cargar.')); return; }
        lastData = data;
        renderTodo(data);
        startCountdown();
    } catch (e) {
        console.error(e);
        alert('Error de conexión con el servidor.');
    } finally {
        showLoader(false);
    }
}

function renderTodo(data) {
    const p = data.predicciones;

    animNum('pred_prendas',  p.total_prendas_3meses  || 0);
    animNum('pred_prendas6', p.total_prendas_6meses  || 0);
    animNum('pred_ops',      p.total_ops_3meses      || 0);

    // KPI riesgo
    const riesgoEl    = document.getElementById('pred_riesgo');
    const riesgoSubEl = document.getElementById('pred_riesgo_sub');
    if (riesgoEl) {
        const niveles = { 'alto': 'ALTO', 'moderado': 'MODERADO', 'bajo': 'BAJO' };
        riesgoEl.textContent = niveles[p.riesgo_global] || '—';
        riesgoEl.style.color = p.riesgo_global === 'alto' ? C_RED : p.riesgo_global === 'moderado' ? C_AMBER : C_GREEN;
    }
    if (riesgoSubEl)
        riesgoSubEl.textContent = `${p.lineas_en_riesgo || 0} línea(s) en zona de riesgo`;

    const deltaEl = document.getElementById('kpi_crecimiento');
    if (deltaEl && p.crecimiento_mensual !== undefined) {
        const pos = p.crecimiento_mensual >= 0;
        deltaEl.textContent = (pos ? '+' : '') + p.crecimiento_mensual + '%/mes';
        deltaEl.className   = 'kpi-delta ' + (pos ? 'delta-up' : 'delta-down');
    }

    // Poblar clientes solo si hay datos nuevos o el select tiene solo "Todos"
    if (data.clientes && data.clientes.length > 0) {
        poblarClientes(data.clientes);
    }

    renderAlertas(data.alertas || []);
    renderTendencia(data.tendencia || []);
    renderEfProyectada(data.ef_proyectada || []);
    renderCargaProyectada(data.carga_proyectada_lineas || []);
    renderRiesgoLineas(data.carga_proyectada_lineas || []);
    renderDemandaClientes(data.demanda_clientes || []);
    renderEstilos(data.estilos_proyectados || []);
    renderEstilosPie(data.estilos_proyectados || []);
    renderResumen(data);

    const footer = document.getElementById('pred_footer');
    if (footer && data.fecha) footer.textContent = 'Última actualización: ' + data.fecha;
    const stamp = document.getElementById('resumenFecha');
    if (stamp && data.fecha) stamp.textContent = data.fecha;
}

function animNum(id, target, dur = 900) {
    const el = document.getElementById(id);
    if (!el) return;
    const val = Number(target) || 0;
    let start = null;
    const step = ts => {
        if (!start) start = ts;
        const pct  = Math.min((ts - start) / dur, 1);
        const ease = 1 - Math.pow(1 - pct, 3);
        el.textContent = Math.floor(val * ease).toLocaleString('es-PE');
        if (pct < 1) requestAnimationFrame(step);
    };
    requestAnimationFrame(step);
}

function poblarClientes(clientes) {
    const sel = document.getElementById('id_cliente');
    const cur = sel.value;
    // Limpiar opciones excepto "Todos"
    while (sel.options.length > 1) sel.remove(1);
    clientes.forEach(c => sel.add(new Option(c.nombre_cliente, c.id_cliente)));
    // Restaurar selección si aún existe
    if (cur) {
        const exists = Array.from(sel.options).some(o => o.value == cur);
        sel.value = exists ? cur : '';
    }
}

function tooltip(extra = {}) {
    return {
        backgroundColor: '#1f2937',
        titleColor:  '#f9fafb',
        bodyColor:   '#9ca3af',
        padding: 12,
        cornerRadius: 8,
        borderColor: 'rgba(37,99,235,.3)',
        borderWidth: 1,
        ...extra
    };
}

function lightScales(yLabel) {
    return {
        x: {
            grid:   { color: 'rgba(0,0,0,.05)' },
            ticks:  { color: '#6b7280', font: { size: 10 } },
            border: { color: 'rgba(0,0,0,.1)' }
        },
        y: {
            beginAtZero: true,
            grid:   { color: 'rgba(0,0,0,.05)' },
            ticks:  { color: '#6b7280', font: { size: 10 }, callback: yLabel || (v => v >= 1000 ? (v/1000).toFixed(0)+'k' : v) },
            border: { color: 'rgba(0,0,0,.1)' }
        }
    };
}

// ── ALERTAS
function renderAlertas(alertas) {
    const wrap = document.getElementById('alertasContainer');
    if (!alertas.length) {
        wrap.innerHTML = '<div class="alerta-item alerta-ok"><i class="bx bx-check-circle"></i><span>Sin alertas críticas para los próximos meses. Producción dentro de parámetros normales.</span></div>';
        return;
    }
    const colores = { critico: 'alerta-critico', alto: 'alerta-alto', info: 'alerta-info', ok: 'alerta-ok' };
    wrap.innerHTML = alertas.map(a => `
        <div class="alerta-item ${colores[a.tipo] || 'alerta-info'}">
            <i class="bx ${a.icono}"></i>
            <span>${a.msg}</span>
        </div>
    `).join('');
}

// ── PROYECCIÓN DE PRODUCCIÓN
function renderTendencia(data) {
    const ctx = document.getElementById('chartTendencia');
    if (chartTendencia) chartTendencia.destroy();
    if (!data.length) { ctx.parentElement.querySelector('.chart-desc').textContent = 'Sin datos históricos en el período seleccionado.'; return; }

    const labels = data.map(d => d.mes_label || d.mes);
    const histV  = data.map(d => d.tipo === 'historico' ? Number(d.prendas) || 0 : null);
    const proyV  = data.map((d, i) => {
        if (d.tipo === 'proyeccion') return Number(d.prendas) || 0;
        if (data[i+1]?.tipo === 'proyeccion') return Number(d.prendas) || 0;
        return null;
    });
    const optV   = data.map(d => d.tipo === 'proyeccion' ? Number(d.optimista) || 0 : null);
    const pesV   = data.map(d => d.tipo === 'proyeccion' ? Number(d.pesimista) || 0 : null);

    chartTendencia = new Chart(ctx, {
        type: 'line',
        data: {
            labels,
            datasets: [
                { label:'Histórico',  data: histV, borderColor: C_BLUE, backgroundColor:'rgba(37,99,235,.07)',  borderWidth:2.5, tension:0.4, pointRadius:4, pointBackgroundColor:C_BLUE, pointBorderColor:'#fff', pointBorderWidth:2, fill:true, spanGaps:false },
                { label:'Proyección', data: proyV, borderColor: C_VIO,  backgroundColor:'rgba(147,51,234,.04)', borderWidth:2.5, borderDash:[6,4], tension:0.4, pointRadius:4, pointBackgroundColor:C_VIO, pointBorderColor:'#fff', pointBorderWidth:2, fill:false, spanGaps:false },
                { label:'Optimista',  data: optV,  borderColor: C_GREEN, borderWidth:1.5, borderDash:[3,3], tension:0.4, pointRadius:2, fill:false, spanGaps:false },
                { label:'Pesimista',  data: pesV,  borderColor: C_RED,   borderWidth:1.5, borderDash:[3,3], tension:0.4, pointRadius:2, fill:false, spanGaps:false },
            ]
        },
        options: {
            responsive:true, maintainAspectRatio:true,
            interaction:{ mode:'index', intersect:false },
            plugins: {
                legend:{ display:false },
                tooltip:{ ...tooltip(), callbacks:{ label: c => ` ${c.dataset.label}: ${Number(c.parsed.y).toLocaleString('es-PE')} prendas` }}
            },
            scales: lightScales()
        }
    });
}

// ── EFICIENCIA PROYECTADA
function renderEfProyectada(data) {
    const ctx = document.getElementById('chartEfProyectada');
    if (chartEfProyectada) chartEfProyectada.destroy();

    const labels   = data.map(d => d.mes_label || d.mes);
    const histEf   = data.map(d => d.tipo === 'historico' ? Number(d.ef) : null);
    const proyEf   = data.map((d, i) => {
        if (d.tipo === 'proyeccion') return Number(d.ef);
        if (data[i+1]?.tipo === 'proyeccion') return Number(d.ef);
        return null;
    });
    const metaEf   = data.map(() => 85);

    chartEfProyectada = new Chart(ctx, {
        type: 'line',
        data: {
            labels,
            datasets: [
                { label:'Eficiencia real',        data: histEf, borderColor: C_TEAL,  backgroundColor:'rgba(13,148,136,.08)', borderWidth:2.5, tension:0.4, pointRadius:4, pointBackgroundColor:C_TEAL, pointBorderColor:'#fff', pointBorderWidth:2, fill:true, spanGaps:false },
                { label:'Eficiencia proyectada',  data: proyEf, borderColor: C_VIO,   borderWidth:2, borderDash:[5,4], tension:0.4, pointRadius:3, pointBackgroundColor:C_VIO, fill:false, spanGaps:false },
                { label:'Meta (85%)',             data: metaEf, borderColor:'rgba(217,119,6,.5)', borderWidth:1.5, borderDash:[2,4], pointRadius:0, fill:false },
            ]
        },
        options: {
            responsive:true,
            interaction:{ mode:'index', intersect:false },
            plugins: {
                legend:{ display:false },
                tooltip:{ ...tooltip(), callbacks:{ label: c => ` ${c.dataset.label}: ${Number(c.parsed.y).toFixed(1)}%` }}
            },
            scales: {
                x: { grid:{color:'rgba(0,0,0,.05)'}, ticks:{color:'#6b7280',font:{size:10}}, border:{color:'rgba(0,0,0,.1)'} },
                y: { beginAtZero:false, min:0, max:100, grid:{color:'rgba(0,0,0,.05)'}, ticks:{color:'#6b7280',callback:v=>v+'%'}, border:{color:'rgba(0,0,0,.1)'} }
            }
        }
    });
}

// ── CARGA PROYECTADA POR LÍNEA
function renderCargaProyectada(lineas) {
    const ctx = document.getElementById('chartCargaProy');
    if (chartCargaProy) chartCargaProy.destroy();
    if (!lineas.length) return;

    const labels  = lineas.map(l => 'L' + l.num_linea);
    const actual  = lineas.map(l => Number(l.carga_actual) || 0);
    const proyect = lineas.map(l => Number(l.carga_proy)   || 0);

    chartCargaProy = new Chart(ctx, {
        type: 'bar',
        data: {
            labels,
            datasets: [
                { label:'Carga actual',     data: actual,  backgroundColor:'rgba(37,99,235,.2)', borderColor:C_BLUE, borderWidth:1.5, borderRadius:4, borderSkipped:false },
                { label:'Proyección 1 mes', data: proyect, backgroundColor: proyect.map(v => v > 8000 ? 'rgba(220,38,38,.75)' : v > 4000 ? 'rgba(217,119,6,.75)' : 'rgba(147,51,234,.6)'), borderRadius:4, borderSkipped:false },
            ]
        },
        options: {
            responsive:true,
            interaction:{ mode:'index', intersect:false },
            plugins: {
                legend:{ display:true, labels:{ color:'#6b7280', usePointStyle:true, boxWidth:8, font:{size:10} }},
                tooltip:{ ...tooltip(), callbacks:{ label: c => ` ${c.dataset.label}: ${Number(c.parsed.y).toLocaleString('es-PE')} prendas` }}
            },
            scales: lightScales()
        }
    });
}

// ── MAPA DE RIESGO (scatter)
function renderRiesgoLineas(lineas) {
    const ctx = document.getElementById('chartRiesgoLineas');
    if (chartRiesgoLineas) chartRiesgoLineas.destroy();
    if (!lineas.length) return;

    const coloresRiesgo = { 'crítico':'rgba(220,38,38,.85)', 'alto':'rgba(217,119,6,.85)', 'medio':'rgba(8,145,178,.75)', 'bajo':'rgba(22,163,74,.75)' };

    chartRiesgoLineas = new Chart(ctx, {
        type: 'scatter',
        data: {
            datasets: [{
                label: 'Líneas',
                data: lineas.map(l => ({ x: Number(l.carga_proy)||0, y: Number(l.ef_linea)||0, label: 'L'+l.num_linea, riesgo: l.nivel_riesgo })),
                backgroundColor: lineas.map(l => coloresRiesgo[l.nivel_riesgo] || 'rgba(100,116,139,.7)'),
                pointRadius: 9,
                pointHoverRadius: 12,
            }]
        },
        options: {
            responsive:true,
            plugins: {
                legend:{ display:false },
                tooltip:{ ...tooltip(), callbacks:{
                    label: c => {
                        const d = c.raw;
                        return [` Línea: ${d.label}`, ` Carga proy.: ${Number(d.x).toLocaleString('es-PE')} prendas`, ` Eficiencia: ${d.y}%`, ` Riesgo: ${d.riesgo}`];
                    }
                }}
            },
            scales: {
                x: { title:{ display:true, text:'Carga proyectada (prendas)', color:'#6b7280', font:{size:10} }, grid:{color:'rgba(0,0,0,.05)'}, ticks:{color:'#6b7280',callback:v=>v>=1000?(v/1000).toFixed(0)+'k':v}, border:{color:'rgba(0,0,0,.1)'} },
                y: { title:{ display:true, text:'Eficiencia (%)', color:'#6b7280', font:{size:10} }, min:0, max:100, grid:{color:'rgba(0,0,0,.05)'}, ticks:{color:'#6b7280',callback:v=>v+'%'}, border:{color:'rgba(0,0,0,.1)'} }
            }
        },
        plugins: [{
            id: 'linea-labels',
            afterDatasetsDraw(chart) {
                const ctx2 = chart.ctx;
                chart.data.datasets[0].data.forEach((d, i) => {
                    const meta = chart.getDatasetMeta(0);
                    const pt   = meta.data[i];
                    ctx2.fillStyle = '#1a1d23';
                    ctx2.font = 'bold 10px Inter';
                    ctx2.textAlign = 'center';
                    ctx2.fillText(d.label, pt.x, pt.y - 13);
                });
            }
        }]
    });
}

// ── DEMANDA POR CLIENTE
function renderDemandaClientes(clientes) {
    const ctx = document.getElementById('chartDemandaClientes');
    if (chartDemandaClientes) chartDemandaClientes.destroy();
    if (!clientes.length) return;

    const labels = clientes.map(c => c.nombre_cliente.length > 18 ? c.nombre_cliente.slice(0,16)+'…' : c.nombre_cliente);
    const vals   = clientes.map(c => Number(c.prendas_proy_3m) || 0);
    const colRiesgo = { alto:'rgba(220,38,38,.75)', medio:'rgba(217,119,6,.75)', bajo:'rgba(37,99,235,.65)' };

    chartDemandaClientes = new Chart(ctx, {
        type: 'bar',
        data: {
            labels,
            datasets: [{
                label: 'Demanda proyectada 3m',
                data: vals,
                backgroundColor: clientes.map(c => colRiesgo[c.riesgo] || 'rgba(37,99,235,.6)'),
                borderRadius: 5,
                borderSkipped: false
            }]
        },
        options: {
            indexAxis: 'y',
            responsive: true,
            plugins: {
                legend:{ display:false },
                tooltip:{ ...tooltip(), callbacks:{ label: c => {
                    const cl = clientes[c.dataIndex];
                    return [` Prendas proyectadas (3m): ${Number(c.parsed.x).toLocaleString('es-PE')}`, ` OPs estimadas: ${cl.ops_proy}`, ` Riesgo entrega: ${cl.riesgo}`];
                }}}
            },
            scales: {
                x: { grid:{color:'rgba(0,0,0,.05)'}, ticks:{color:'#6b7280',callback:v=>v>=1000?(v/1000).toFixed(0)+'k':v,font:{size:10}}, border:{color:'rgba(0,0,0,.1)'} },
                y: { grid:{display:false}, ticks:{color:'#374151',font:{size:10}}, border:{color:'rgba(0,0,0,.1)'} }
            }
        }
    });
}

// ── ESTILOS PROYECTADOS
function renderEstilos(estilos) {
    const container = document.getElementById('topEstilosPred');
    if (!estilos.length) { container.innerHTML = '<p class="empty-msg">Sin datos disponibles en el período seleccionado.</p>'; return; }
    const max = Math.max(...estilos.map(e => Number(e.proy_3m) || 0), 1);
    const iconTend = { sube:'bx-trending-up', baja:'bx-trending-down', estable:'bx-minus' };
    const colTend  = { sube:C_GREEN, baja:C_RED, estable:C_AMBER };
    container.innerHTML = estilos.map((e, i) => `
        <div class="estilo-row">
            <span class="estilo-rank">${i+1}</span>
            <div class="estilo-bar-wrap">
                <div class="estilo-header">
                    <span class="estilo-name">${e.estilo || '—'}</span>
                    <span class="estilo-tend" style="color:${colTend[e.tendencia] || C_AMBER}">
                        <i class="bx ${iconTend[e.tendencia] || 'bx-minus'}" style="font-size:13px;vertical-align:middle"></i>
                        ${Number(e.variacion) > 0 ? '+' : ''}${e.variacion}%
                    </span>
                </div>
                <div class="estilo-bar-bg">
                    <div class="estilo-bar-fill" style="width:${Math.round((Number(e.proy_3m)/max)*100)}%;background:${COLORS[i%COLORS.length]}"></div>
                </div>
            </div>
            <span class="estilo-total">${Number(e.proy_3m).toLocaleString('es-PE')}</span>
        </div>
    `).join('');
}

// ── PIE ESTILOS
function renderEstilosPie(estilos) {
    const ctx = document.getElementById('chartEstilosPie');
    if (chartEstilosPie) chartEstilosPie.destroy();
    if (!estilos.length) return;
    chartEstilosPie = new Chart(ctx, {
        type: 'doughnut',
        data: {
            labels: estilos.map(e => e.estilo),
            datasets: [{
                data: estilos.map(e => Number(e.proy_3m) || 0),
                backgroundColor: COLORS.slice(0, estilos.length).map(c => c + 'cc'),
                borderColor: '#ffffff',
                borderWidth: 2,
                hoverOffset: 8
            }]
        },
        options: {
            responsive:true, cutout:'62%',
            plugins: {
                legend:{ display:true, position:'bottom', labels:{ color:'#374151', usePointStyle:true, pointStyle:'circle', boxWidth:7, padding:12, font:{size:10} }},
                tooltip:{ ...tooltip(), callbacks:{ label: c => ` ${c.label}: ${Number(c.parsed).toLocaleString('es-PE')} prendas` }}
            }
        }
    });
}

// ── RESUMEN EJECUTIVO
function renderResumen(data) {
    const p           = data.predicciones;
    const crec        = p.crecimiento_mensual ?? 0;
    const ef          = p.tasa_cumplimiento   ?? 85;
    const riesgo      = p.riesgo_global       ?? 'bajo';
    const linRiesgo   = p.lineas_en_riesgo    ?? 0;
    const dir         = crec >= 0 ? 'crecimiento' : 'contracción';
    const adj         = Math.abs(crec) > 10 ? 'acelerado' : 'moderado';
    const efTend      = (data.ef_proyectada || []).filter(r => r.tipo==='proyeccion');
    const efFin       = efTend.length ? efTend[efTend.length-1].ef : ef;
    const efDir       = efFin > ef ? 'mejorar' : efFin < ef ? 'disminuir' : 'mantenerse estable';

    const topEstilo   = (data.estilos_proyectados || [])[0];
    const topCliente  = (data.demanda_clientes    || [])[0];

    let texto = `Durante los próximos 6 meses se espera una demanda de <strong>${Number(p.total_prendas_6meses||0).toLocaleString('es-PE')} prendas</strong> `;
    texto += `con un ritmo de ${dir} ${adj} del <strong>${crec >= 0 ? '+' : ''}${crec}% mensual</strong>. `;
    texto += `La eficiencia de producción se proyecta a <strong>${efDir}</strong>, alcanzando aproximadamente <strong>${efFin}%</strong> en 6 meses. `;

    if (linRiesgo > 0)
        texto += `⚠ <strong>${linRiesgo} línea(s)</strong> presentan riesgo ${riesgo} de sobrecarga en el próximo período — se recomienda redistribuir carga. `;
    else
        texto += `Las líneas de producción se encuentran dentro de capacidad normal para el volumen proyectado. `;

    if (topEstilo)
        texto += `El estilo <strong>${topEstilo.estilo}</strong> concentrará la mayor demanda futura con ~<strong>${Number(topEstilo.proy_3m||0).toLocaleString('es-PE')} prendas</strong> estimadas. `;

    if (topCliente)
        texto += `El cliente <strong>${topCliente.nombre_cliente}</strong> generará la mayor carga proyectada: <strong>${Number(topCliente.prendas_proy_3m||0).toLocaleString('es-PE')} prendas</strong> en 3 meses.`;

    const el = document.getElementById('resumenTexto');
    if (el) el.innerHTML = texto;
}

// ── EXPORTAR EXCEL
function exportarExcel() {
    if (!lastData) { alert('Primero carga los datos.'); return; }
    if (typeof XLSX === 'undefined') { alert('Librería Excel no disponible.'); return; }
    const wb = XLSX.utils.book_new();
    const p  = lastData.predicciones;

    const resumen = [
        ['CMT DEL SUR — REPORTE DE PREDICCIONES', ''],
        ['Generado el:', lastData.fecha || ''],
        ['', ''],
        ['INDICADOR', 'VALOR'],
        ['Producción proyectada 3 meses', p.total_prendas_3meses],
        ['Producción proyectada 6 meses', p.total_prendas_6meses],
        ['Nuevas órdenes esperadas (3m)',  p.total_ops_3meses],
        ['Riesgo global de incumplimiento', p.riesgo_global],
        ['Líneas en zona de riesgo',       p.lineas_en_riesgo],
        ['Crecimiento mensual (%)',        p.crecimiento_mensual],
    ];
    const ws1 = XLSX.utils.aoa_to_sheet(resumen);
    ws1['!cols'] = [{wch:36},{wch:20}];
    XLSX.utils.book_append_sheet(wb, ws1, 'Resumen');

    if (lastData.tendencia?.length) {
        const tend = [
            ['Mes','Tipo','Prendas','OPs','Optimista','Pesimista'],
            ...lastData.tendencia.map(r => [r.mes_label||r.mes,r.tipo,r.prendas,r.ops||'',r.optimista||'',r.pesimista||''])
        ];
        const ws2 = XLSX.utils.aoa_to_sheet(tend); ws2['!cols'] = [{wch:14},{wch:12},{wch:12},{wch:8},{wch:12},{wch:12}];
        XLSX.utils.book_append_sheet(wb, ws2, 'Proyección producción');
    }
    if (lastData.ef_proyectada?.length) {
        const ef = [['Mes','Tipo','Eficiencia (%)'], ...lastData.ef_proyectada.map(r => [r.mes_label||r.mes, r.tipo, r.ef])];
        const ws3 = XLSX.utils.aoa_to_sheet(ef); ws3['!cols'] = [{wch:14},{wch:12},{wch:16}];
        XLSX.utils.book_append_sheet(wb, ws3, 'Eficiencia proyectada');
    }
    if (lastData.carga_proyectada_lineas?.length) {
        const lin = [['Línea','Carga actual','Carga proyectada','Eficiencia (%)','Riesgo'],
            ...lastData.carga_proyectada_lineas.map(l => [`Línea ${l.num_linea}`, l.carga_actual, l.carga_proy, l.ef_linea, l.nivel_riesgo])];
        const ws4 = XLSX.utils.aoa_to_sheet(lin); ws4['!cols'] = [{wch:10},{wch:14},{wch:18},{wch:14},{wch:10}];
        XLSX.utils.book_append_sheet(wb, ws4, 'Riesgo por línea');
    }
    if (lastData.demanda_clientes?.length) {
        const cli = [['Cliente','Histórico 6m','Proy. 3m','OPs estimadas','Riesgo entrega'],
            ...lastData.demanda_clientes.map(c => [c.nombre_cliente, c.prendas_historico, c.prendas_proy_3m, c.ops_proy, c.riesgo])];
        const ws5 = XLSX.utils.aoa_to_sheet(cli); ws5['!cols'] = [{wch:30},{wch:14},{wch:12},{wch:14},{wch:14}];
        XLSX.utils.book_append_sheet(wb, ws5, 'Demanda clientes');
    }
    if (lastData.estilos_proyectados?.length) {
        const est = [['Estilo','Proy. 3m','Variación (%)','Tendencia'],
            ...lastData.estilos_proyectados.map(e => [e.estilo, e.proy_3m, e.variacion, e.tendencia])];
        const ws6 = XLSX.utils.aoa_to_sheet(est); ws6['!cols'] = [{wch:20},{wch:12},{wch:14},{wch:10}];
        XLSX.utils.book_append_sheet(wb, ws6, 'Estilos proyectados');
    }

    const ts = new Date().toISOString().slice(0,10);
    XLSX.writeFile(wb, `cmt_predicciones_${ts}.xlsx`);
}

window.onload = cargarPredicciones;