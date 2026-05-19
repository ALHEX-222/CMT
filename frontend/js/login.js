document.addEventListener('DOMContentLoaded', () => {
    const form = document.getElementById('loginForm');
    const button = form.querySelector('.btn-login');

    form.addEventListener('submit', () => {
        // Cambia el texto del botón para feedback visual
        button.textContent = 'Cargando...';
        button.style.opacity = '0.7';
        button.style.pointerEvents = 'none';
    });
});