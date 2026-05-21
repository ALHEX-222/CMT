document.addEventListener('DOMContentLoaded', () => {
    const searchOP = document.getElementById('searchOP');
    const searchCliente = document.getElementById('searchCliente');
    const searchEstado = document.getElementById('searchEstado');
    const tbodyOP = document.getElementById('tbodyOP');
    const excelFile = document.getElementById('excelFile');
    const statusAlert = document.getElementById('statusAlert');
    const fileName = document.getElementById('fileName');
    
    const previewContainer = document.getElementById('previewContainer');
    const tbodyPreview = document.getElementById('tbodyPreview');
    const btnCancelarCarga = document.getElementById('btnCancelarCarga');
    const btnConfirmarCarga = document.getElementById('btnConfirmarCarga');
    const mainTableWrapper = document.querySelector('.main-table-wrapper');

    let fileToUpload = null; 

    function filtrarTabla() {
        const valOP = searchOP.value.toLowerCase().trim();
        const valCliente = searchCliente.value.toLowerCase().trim();
        const valEstado = searchEstado.value;
        const filas = tbodyOP.querySelectorAll('tr');

        filas.forEach(fila => {
            const txtOP = fila.cells[0].textContent.toLowerCase();
            const txtCliente = fila.cells[1].textContent.toLowerCase();
            const txtEstado = fila.getAttribute('data-estado');

            const coincideOP = txtOP.includes(valOP);
            const coincideCliente = txtCliente.includes(valCliente);
            const coincideEstado = valEstado === "" || txtEstado === valEstado;

            fila.style.display = (coincideOP && coincideCliente && coincideEstado) ? "" : "none";
        });
    }

    [searchOP, searchCliente].forEach(el => el.addEventListener('input', filtrarTabla));
    searchEstado.addEventListener('change', filtrarTabla);
    
    filtrarTabla();

    // FASE 1: PREVISUALIZACIÓN CON CONTEO DE OC
    excelFile.addEventListener('change', () => {
        if (excelFile.files.length === 0) return;

        fileToUpload = excelFile.files[0];
        fileName.textContent = fileToUpload.name;

        statusAlert.style.display = "block";
        statusAlert.className = "alert-box info";
        statusAlert.innerHTML = "<i class='bx bx-loader-alt bx-spin'></i> Leyendo estructura del archivo Excel para previsualización...";

        const formData = new FormData();
        formData.append('excel_file', fileToUpload);
        formData.append('action', 'preview'); 

        fetch('ordenPedido.php', {
            method: 'POST',
            body: formData
        })
        .then(res => res.json())
        .then(data => {
            if (data.success && data.preview_data) {
                statusAlert.style.display = "none";
                tbodyPreview.innerHTML = "";
                
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

                previewContainer.style.display = "block";
                mainTableWrapper.style.opacity = "0.3"; 
            } else {
                statusAlert.className = "alert-box error";
                statusAlert.innerHTML = `<b>Error en Análisis:</b> ${data.message}`;
                resetPreviewState();
            }
        })
        .catch(err => {
            console.error(err);
            statusAlert.className = "alert-box error";
            statusAlert.innerHTML = "<b>Error Crítico:</b> El servidor no pudo procesar la vista previa.";
            resetPreviewState();
        });
    });

    // FASE 2: CARGA REAL
    btnConfirmarCarga.addEventListener('click', () => {
        if (!fileToUpload) return;

        statusAlert.style.display = "block";
        statusAlert.className = "alert-box info";
        statusAlert.innerHTML = "<i class='bx bx-loader-alt bx-spin'></i> Ejecutando transacciones en Base de Datos MySQL via Python...";

        const formData = new FormData();
        formData.append('excel_file', fileToUpload);
        formData.append('action', 'load'); 

        fetch('ordenPedido.php', {
            method: 'POST',
            body: formData
        })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                statusAlert.className = "alert-box success";
                statusAlert.innerHTML = `<b>¡Migración Exitosa!</b> ${data.message}<br>Órdenes cargadas: ${data.ordenes} | Divisiones de corte añadidas: ${data.cortes}`;
                previewContainer.style.display = "none";
                setTimeout(() => window.location.reload(), 3000);
            } else {
                statusAlert.className = "alert-box error";
                statusAlert.innerHTML = `<b>Error en Inserción:</b> ${data.message}`;
            }
        })
        .catch(err => {
            console.error(err);
            statusAlert.className = "alert-box error";
            statusAlert.innerHTML = "<b>Error Crítico:</b> Fallo de comunicación de datos en el servidor.";
        });
    });

    btnCancelarCarga.addEventListener('click', () => {
        resetPreviewState();
        statusAlert.style.display = "block";
        statusAlert.className = "alert-box info";
        statusAlert.innerHTML = "Carga de archivo cancelada por el usuario.";
        setTimeout(() => statusAlert.style.display = "none", 2500);
    });

    function resetPreviewState() {
        fileToUpload = null;
        excelFile.value = "";
        fileName.textContent = "Formatos: .xlsx, .xlsm";
        previewContainer.style.display = "none";
        mainTableWrapper.style.opacity = "1";
    }
});