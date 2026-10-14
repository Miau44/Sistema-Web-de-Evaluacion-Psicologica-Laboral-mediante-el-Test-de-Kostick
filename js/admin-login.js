(() => {
    const password = document.getElementById('password');
    const toggle = document.querySelector('.password-toggle');
    const form = document.querySelector('.login-form');
    const submit = document.querySelector('.login-submit');

    if (password && toggle) {
        toggle.addEventListener('click', () => {
            const isVisible = password.type === 'text';
            password.type = isVisible ? 'password' : 'text';
            toggle.setAttribute('aria-pressed', String(!isVisible));
            toggle.setAttribute('aria-label', isVisible ? 'Mostrar contraseña' : 'Ocultar contraseña');
        });
    }

    if (form && submit) {
        form.addEventListener('submit', () => {
            if (!form.checkValidity()) {
                submit.disabled = false;
                submit.removeAttribute('aria-busy');
                submit.querySelector('span').textContent = 'Iniciar sesión';
                return;
            }
            submit.disabled = true;
            submit.setAttribute('aria-busy', 'true');
            submit.querySelector('span').textContent = 'Iniciando sesión…';
        });
    }
})();
