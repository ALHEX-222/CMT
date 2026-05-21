document.addEventListener('DOMContentLoaded', () => {
    const userMenuBtn = document.getElementById('userMenuBtn');
    const dropdownMenu = document.getElementById('dropdownMenu');
    const openModalBtn = document.getElementById('openModalBtn');
    const logoutModal = document.getElementById('logoutModal');
    const cancelBtn = document.getElementById('cancelBtn');
    const confirmBtn = document.getElementById('confirmBtn');
    const navLinks = document.querySelectorAll('.nav-link');
    const contentSections = document.querySelectorAll('.content-section');
    const alertBtn = document.getElementById('alertBtn');

    navLinks.forEach(link => {
        link.addEventListener('click', (e) => {
            e.preventDefault();
            
            navLinks.forEach(item => item.classList.remove('active'));
            contentSections.forEach(section => section.classList.remove('active'));

            link.classList.add('active');
            const target = link.getAttribute('data-target');
            
            // Mostrar la sección correspondiente
            const targetSection = document.getElementById(`content-${target}`);
            if (targetSection) {
                targetSection.classList.add('active');
            }

            // ==========================================
            // LOGICA PARA DETECTAR LA PESTAÑA PEDIDOS
            // ==========================================
            if (target === 'pedidos') {
                const iframePedidos = document.getElementById('iframe-pedidos');
                if (iframePedidos) {
                    // Fuerza la actualización de la tabla interna llamando a la BD
                    iframePedidos.contentWindow.location.reload();
                }
            }
        });
    });

    userMenuBtn.addEventListener('click', (e) => {
        e.stopPropagation();
        userMenuBtn.classList.toggle('active');
        dropdownMenu.classList.toggle('show');
    });

    document.addEventListener('click', () => {
        if (dropdownMenu.classList.contains('show')) {
            userMenuBtn.classList.remove('active');
            dropdownMenu.classList.remove('show');
        }
    });

    openModalBtn.addEventListener('click', (e) => {
        e.stopPropagation();
        logoutModal.classList.add('active');
        userMenuBtn.classList.remove('active');
        dropdownMenu.classList.remove('show');
    });

    cancelBtn.addEventListener('click', () => {
        logoutModal.classList.remove('active');
    });

    confirmBtn.addEventListener('click', () => {
        window.location.href = 'login/logout.php';
    });

    alertBtn.addEventListener('click', () => {
        alert('Módulo de Alertas en desarrollo.');
    });

    window.addEventListener('pageshow', function (event) {
        if (event.persisted || (window.performance && window.performance.navigation.type === 2)) {
            window.location.reload(true);
        }
    });
});