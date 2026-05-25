document.addEventListener('DOMContentLoaded', () => {
    const form   = document.getElementById('loginForm');
    const button = document.getElementById('btnLogin');

    form.addEventListener('submit', () => {
        button.textContent    = 'Autenticando...';
        button.disabled       = true;
    });
});