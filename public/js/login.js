const tokenKey = 'sanctum_token';

const elements = {
    tabs: document.querySelectorAll('[data-auth-tab]'),
    loginForm: document.getElementById('loginForm'),
    registerForm: document.getElementById('registerForm'),
    authMessage: document.getElementById('authMessage'),
};

function setMessage(message, type = 'info') {
    elements.authMessage.textContent = message;
    elements.authMessage.dataset.type = type;
}

function formData(form) {
    return Object.fromEntries(new FormData(form).entries());
}

function csrfToken() {
    const token = document.cookie
        .split('; ')
        .find((row) => row.startsWith('XSRF-TOKEN='))
        ?.split('=')[1];

    return token ? decodeURIComponent(token) : '';
}

async function refreshCsrfCookie() {
    await fetch('/sanctum/csrf-cookie', {
        credentials: 'same-origin',
    });
}

async function request(path, options = {}) {
    const headers = {
        Accept: 'application/json',
        ...options.headers,
    };

    if (options.body) {
        headers['Content-Type'] = 'application/json';
        headers['X-XSRF-TOKEN'] = csrfToken();
    }

    const response = await fetch(path, {
        ...options,
        credentials: 'same-origin',
        headers,
    });

    const data = await response.json().catch(() => null);

    if (!response.ok) {
        const error = new Error(data?.message || 'Something went wrong.');
        error.data = data;
        throw error;
    }

    return data;
}

function validationMessage(error) {
    if (error.data?.errors) {
        return Object.values(error.data.errors).flat().join(' ');
    }

    return error.message || 'Something went wrong.';
}

async function authenticate(path, form, loadingMessage) {
    setMessage(loadingMessage);
    await refreshCsrfCookie();

    const data = await request(path, {
        method: 'POST',
        body: JSON.stringify(formData(form)),
    });

    localStorage.setItem(tokenKey, data.token);
    window.location.href = data.user.role === 'admin' ? '/admin/dashboard' : '/student/dashboard';
}

elements.tabs.forEach((tab) => {
    tab.addEventListener('click', () => {
        const activeTab = tab.dataset.authTab;

        elements.tabs.forEach((item) => item.classList.toggle('active', item === tab));
        elements.loginForm.classList.toggle('hidden', activeTab !== 'login');
        elements.registerForm.classList.toggle('hidden', activeTab !== 'register');
        setMessage('');
    });
});

elements.loginForm.addEventListener('submit', async (event) => {
    event.preventDefault();

    try {
        await authenticate('/api/login', elements.loginForm, 'Checking login...');
    } catch (error) {
        localStorage.removeItem(tokenKey);
        setMessage(`Login failed: ${validationMessage(error)}`, 'error');
    }
});

elements.registerForm.addEventListener('submit', async (event) => {
    event.preventDefault();

    try {
        await authenticate('/api/register', elements.registerForm, 'Creating account...');
    } catch (error) {
        localStorage.removeItem(tokenKey);
        setMessage(validationMessage(error), 'error');
    }
});
