import './bootstrap';

const tokenKey = 'sanctum_token';

const state = {
    user: null,
    token: localStorage.getItem(tokenKey),
};

const elements = {
    tabs: document.querySelectorAll('[data-auth-tab]'),
    loginForm: document.getElementById('loginForm'),
    registerForm: document.getElementById('registerForm'),
    addUserForm: document.getElementById('addUserForm'),
    logoutButton: document.getElementById('logoutButton'),
    refreshButton: document.getElementById('refreshButton'),
    authMessage: document.getElementById('authMessage'),
    sessionStatus: document.getElementById('sessionStatus'),
    profileStrip: document.getElementById('profileStrip'),
    courseList: document.getElementById('courseList'),
    userList: document.getElementById('userList'),
    courseCount: document.getElementById('courseCount'),
    userCount: document.getElementById('userCount'),
    enrollmentCount: document.getElementById('enrollmentCount'),
    paymentCount: document.getElementById('paymentCount'),
};

function setToken(token) {
    state.token = token;

    if (token) {
        localStorage.setItem(tokenKey, token);
        window.axios.defaults.headers.common.Authorization = `Bearer ${token}`;
        return;
    }

    localStorage.removeItem(tokenKey);
    delete window.axios.defaults.headers.common.Authorization;
}

function setMessage(message, type = 'info') {
    elements.authMessage.textContent = message;
    elements.authMessage.dataset.type = type;
}

function formData(form) {
    return Object.fromEntries(new FormData(form).entries());
}

function validationMessage(error) {
    const errors = error.response?.data?.errors;

    if (errors) {
        return Object.values(errors).flat().join(' ');
    }

    return error.response?.data?.message || 'Something went wrong.';
}

function renderSession() {
    const isAuthenticated = Boolean(state.user);

    elements.logoutButton.hidden = !isAuthenticated;
    elements.logoutButton.disabled = !state.user;
    elements.sessionStatus.textContent = state.user ? `${state.user.name} (${state.user.role})` : 'Guest';
    elements.profileStrip.textContent = state.user
        ? `Login checked: ${state.user.email} is authenticated with Sanctum.`
        : 'Login to unlock users, enrollments, lesson progress, and payments.';
    elements.addUserForm.classList.toggle('locked', !isAuthenticated);
    elements.addUserForm.querySelectorAll('input, select, button').forEach((field) => {
        field.disabled = !isAuthenticated;
    });
}

function renderCourses(courses) {
    elements.courseCount.textContent = courses.length;

    if (!courses.length) {
        elements.courseList.innerHTML = '<p class="empty-state">No courses found yet.</p>';
        return;
    }

    elements.courseList.innerHTML = courses.map((course) => `
        <article class="course-item">
            <div>
                <h3>${course.title}</h3>
                <p>${course.category?.name || 'Uncategorized'} - ${course.instructor?.name || 'No instructor'}</p>
            </div>
            <span>$${Number(course.price || 0).toFixed(2)}</span>
        </article>
    `).join('');
}

function renderUsers(users) {
    elements.userCount.textContent = users.length;

    if (!users.length) {
        elements.userList.innerHTML = '<p class="empty-state">No users found yet.</p>';
        return;
    }

    elements.userList.innerHTML = users.map((user) => `
        <article class="user-item">
            <div>
                <strong>${user.name}</strong>
                <span>${user.email}</span>
            </div>
            <span>${user.role}</span>
        </article>
    `).join('');
}

async function loadPublicCourses() {
    const { data } = await window.axios.get('/api/courses');
    renderCourses(data);
}

async function loadProtectedData() {
    if (!state.token) {
        state.user = null;
        elements.userCount.textContent = 'Locked';
        elements.enrollmentCount.textContent = 'Locked';
        elements.paymentCount.textContent = 'Locked';
        elements.userList.innerHTML = '';
        renderSession();
        return;
    }

    try {
        const [userResponse, usersResponse, enrollmentResponse, paymentResponse] = await Promise.all([
            window.axios.get('/api/user'),
            window.axios.get('/api/users'),
            window.axios.get('/api/enrollments'),
            window.axios.get('/api/payments'),
        ]);

        state.user = userResponse.data;
        renderUsers(usersResponse.data);
        elements.enrollmentCount.textContent = enrollmentResponse.data.length;
        elements.paymentCount.textContent = paymentResponse.data.length;
        renderSession();
    } catch (error) {
        setToken(null);
        state.user = null;
        elements.userCount.textContent = 'Locked';
        elements.enrollmentCount.textContent = 'Locked';
        elements.paymentCount.textContent = 'Locked';
        elements.userList.innerHTML = '';
        renderSession();
        setMessage('Login check failed. Please login again.', 'warning');
    }
}

async function refreshDashboard() {
    await loadPublicCourses();
    await loadProtectedData();
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
    setMessage('Checking login...');

    try {
        const { data } = await window.axios.post('/api/login', formData(elements.loginForm));
        setToken(data.token);
        state.user = data.user;
        elements.loginForm.reset();
        setMessage(`Login checked: welcome ${data.user.name}.`, 'success');
        await refreshDashboard();
    } catch (error) {
        setToken(null);
        state.user = null;
        renderSession();
        setMessage(`Login check failed: ${validationMessage(error)}`, 'error');
    }
});

elements.registerForm.addEventListener('submit', async (event) => {
    event.preventDefault();
    setMessage('Creating account...');

    try {
        const { data } = await window.axios.post('/api/register', formData(elements.registerForm));
        setToken(data.token);
        state.user = data.user;
        elements.registerForm.reset();
        setMessage('Account created and logged in.', 'success');
        await refreshDashboard();
    } catch (error) {
        setMessage(validationMessage(error), 'error');
    }
});

elements.logoutButton.addEventListener('click', async () => {
    try {
        await window.axios.post('/api/logout');
    } catch (error) {
        // Token may already be expired or removed. The local session still needs clearing.
    }

    setToken(null);
    state.user = null;
    setMessage('Logged out.', 'success');
    await refreshDashboard();
});

elements.addUserForm.addEventListener('submit', async (event) => {
    event.preventDefault();
    setMessage('Adding user...');

    try {
        const { data } = await window.axios.post('/api/users', formData(elements.addUserForm));
        elements.addUserForm.reset();
        setMessage(`User added: ${data.name}.`, 'success');
        await refreshDashboard();
    } catch (error) {
        setMessage(validationMessage(error), 'error');
    }
});

elements.refreshButton.addEventListener('click', refreshDashboard);

refreshDashboard().catch(() => {
    setMessage('Could not load dashboard data.', 'error');
});
