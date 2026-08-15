const tokenKey = 'sanctum_token';

const state = {
    token: localStorage.getItem(tokenKey),
    user: null,
    courses: [],
    categories: [],
};

const elements = {
    addUserForm: document.getElementById('addUserForm'),
    categoryForm: document.getElementById('categoryForm'),
    categoryFormTitle: document.getElementById('categoryFormTitle'),
    categorySubmitButton: document.getElementById('categorySubmitButton'),
    cancelCategoryEditButton: document.getElementById('cancelCategoryEditButton'),
    courseForm: document.getElementById('courseForm'),
    courseFormTitle: document.getElementById('courseFormTitle'),
    courseSubmitButton: document.getElementById('courseSubmitButton'),
    cancelEditButton: document.getElementById('cancelEditButton'),
    categorySelect: document.getElementById('categorySelect'),
    logoutButton: document.getElementById('logoutButton'),
    refreshButton: document.getElementById('refreshButton'),
    authMessage: document.getElementById('authMessage'),
    sessionStatus: document.getElementById('sessionStatus'),
    profileStrip: document.getElementById('profileStrip'),
    courseList: document.getElementById('courseList'),
    categoryList: document.getElementById('categoryList'),
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
        return;
    }

    localStorage.removeItem(tokenKey);
}

function setMessage(message, type = 'info') {
    elements.authMessage.textContent = message;
    elements.authMessage.dataset.type = type;
}

function formData(form) {
    return Object.fromEntries(new FormData(form).entries());
}

function escapeHtml(value) {
    return String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
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

    if (state.token) {
        headers.Authorization = `Bearer ${state.token}`;
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
        error.status = response.status;
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

function renderSession(user) {
    if (user.role !== 'admin') {
        window.location.href = '/student/dashboard';
        return;
    }

    elements.logoutButton.hidden = false;
    elements.sessionStatus.textContent = `${user.name} (${user.role})`;
    elements.profileStrip.textContent = `Website protected by auth middleware. API checked by Sanctum as ${user.email}.`;
}

function renderCourses(courses) {
    elements.courseCount.textContent = courses.length;
    state.courses = courses;

    if (!courses.length) {
        elements.courseList.innerHTML = '<p class="empty-state">No courses found yet.</p>';
        return;
    }

    elements.courseList.innerHTML = courses.map((course) => {
        const priceLabel = Number(course.price || 0) === 0
            ? 'Free'
            : `$${Number(course.price || 0).toFixed(2)}`;

        return `
        <article class="course-item">
            <div class="course-main">
                <h3>${escapeHtml(course.title)}</h3>
                <p>${escapeHtml(course.category?.name || 'Uncategorized')} - ${escapeHtml(course.instructor?.name || 'No instructor')} - ${course.is_published ? 'Published' : 'Draft'}</p>
                ${course.video_url ? `<p><a href="${escapeHtml(course.video_url)}" target="_blank" rel="noopener">Watch video</a></p>` : ''}
                ${course.description ? `<p>${escapeHtml(course.description)}</p>` : ''}
            </div>
            <div class="course-meta">
                <span>${priceLabel}</span>
                <div class="course-actions">
                    <button class="secondary-action" type="button" data-course-edit="${course.id}">Edit</button>
                    <button class="danger-action" type="button" data-course-delete="${course.id}">Delete</button>
                </div>
            </div>
        </article>
    `;
    }).join('');
}

function renderCategories(categories) {
    state.categories = categories;
    elements.categorySelect.innerHTML = [
        '<option value="">Uncategorized</option>',
        ...categories.map((category) => (
            `<option value="${category.id}">${escapeHtml(category.name)}</option>`
        )),
    ].join('');

    if (!categories.length) {
        elements.categoryList.innerHTML = '<p class="empty-state">No categories found yet.</p>';
        return;
    }

    elements.categoryList.innerHTML = categories.map((category) => `
        <article class="category-item">
            <div>
                <strong>${escapeHtml(category.name)}</strong>
                <span>${escapeHtml(category.slug)}</span>
                ${category.description ? `<p>${escapeHtml(category.description)}</p>` : ''}
            </div>
            <div class="course-actions">
                <button class="secondary-action" type="button" data-category-edit="${category.id}">Edit</button>
                <button class="danger-action" type="button" data-category-delete="${category.id}">Delete</button>
            </div>
        </article>
    `).join('');
}

function categoryPayload() {
    const data = new FormData(elements.categoryForm);

    return {
        name: data.get('name'),
        slug: data.get('slug') || null,
        description: data.get('description') || null,
    };
}

function resetCategoryForm() {
    elements.categoryForm.reset();
    elements.categoryForm.elements.id.value = '';
    elements.categoryFormTitle.textContent = 'Add category';
    elements.categorySubmitButton.textContent = 'Add category';
    elements.cancelCategoryEditButton.hidden = true;
}

function editCategory(categoryId) {
    const category = state.categories.find((item) => String(item.id) === String(categoryId));

    if (!category) {
        return;
    }

    elements.categoryForm.elements.id.value = category.id;
    elements.categoryForm.elements.name.value = category.name || '';
    elements.categoryForm.elements.slug.value = category.slug || '';
    elements.categoryForm.elements.description.value = category.description || '';
    elements.categoryFormTitle.textContent = 'Edit category';
    elements.categorySubmitButton.textContent = 'Save category';
    elements.cancelCategoryEditButton.hidden = false;
    elements.categoryForm.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

function coursePayload() {
    const data = new FormData(elements.courseForm);

    return {
        title: data.get('title'),
        category_id: data.get('category_id') || null,
        price: data.get('price') || 0,
        thumbnail: data.get('thumbnail') || null,
        video_url: data.get('video_url') || null,
        description: data.get('description') || null,
        is_published: elements.courseForm.elements.is_published.checked,
    };
}

function resetCourseForm() {
    elements.courseForm.reset();
    elements.courseForm.elements.id.value = '';
    elements.courseForm.elements.price.value = '0';
    elements.courseFormTitle.textContent = 'Add course';
    elements.courseSubmitButton.textContent = 'Add course';
    elements.cancelEditButton.hidden = true;
}

function editCourse(courseId) {
    const course = state.courses.find((item) => String(item.id) === String(courseId));

    if (!course) {
        return;
    }

    elements.courseForm.elements.id.value = course.id;
    elements.courseForm.elements.title.value = course.title || '';
    elements.courseForm.elements.category_id.value = course.category_id || '';
    elements.courseForm.elements.price.value = course.price || 0;
    elements.courseForm.elements.thumbnail.value = course.thumbnail || '';
    elements.courseForm.elements.video_url.value = course.video_url || '';
    elements.courseForm.elements.description.value = course.description || '';
    elements.courseForm.elements.is_published.checked = Boolean(course.is_published);
    elements.courseFormTitle.textContent = 'Edit course';
    elements.courseSubmitButton.textContent = 'Save course';
    elements.cancelEditButton.hidden = false;
    elements.courseForm.scrollIntoView({ behavior: 'smooth', block: 'start' });
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

async function refreshDashboard() {
    const [courses, categories, user, users, enrollments, payments] = await Promise.all([
        request('/api/my/courses'),
        request('/api/categories'),
        request('/api/user'),
        request('/api/users'),
        request('/api/enrollments'),
        request('/api/payments'),
    ]);

    state.user = user;
    renderSession(user);

    if (user.role !== 'admin') {
        return;
    }

    renderCategories(categories);
    renderCourses(courses);
    renderUsers(users);
    elements.enrollmentCount.textContent = enrollments.length;
    elements.paymentCount.textContent = payments.length;
}

elements.addUserForm.addEventListener('submit', async (event) => {
    event.preventDefault();
    setMessage('Adding user...');

    try {
        await refreshCsrfCookie();
        const data = await request('/api/users', {
            method: 'POST',
            body: JSON.stringify(formData(elements.addUserForm)),
        });
        elements.addUserForm.reset();
        setMessage(`User added: ${data.name}.`, 'success');
        await refreshDashboard();
    } catch (error) {
        setMessage(validationMessage(error), 'error');
    }
});

elements.categoryForm.addEventListener('submit', async (event) => {
    event.preventDefault();

    const categoryId = elements.categoryForm.elements.id.value;
    const isEditing = Boolean(categoryId);

    try {
        await refreshCsrfCookie();
        const data = await request(isEditing ? `/api/categories/${categoryId}` : '/api/categories', {
            method: isEditing ? 'PUT' : 'POST',
            body: JSON.stringify(categoryPayload()),
        });

        setMessage(isEditing ? `Category updated: ${data.name}.` : `Category added: ${data.name}.`, 'success');
        resetCategoryForm();
        await refreshDashboard();
    } catch (error) {
        setMessage(validationMessage(error), 'error');
    }
});

elements.courseForm.addEventListener('submit', async (event) => {
    event.preventDefault();

    const courseId = elements.courseForm.elements.id.value;
    const isEditing = Boolean(courseId);

    try {
        await refreshCsrfCookie();
        await request(isEditing ? `/api/courses/${courseId}` : '/api/courses', {
            method: isEditing ? 'PUT' : 'POST',
            body: JSON.stringify(coursePayload()),
        });

        setMessage(isEditing ? 'Course updated.' : 'Course added.', 'success');
        resetCourseForm();
        await refreshDashboard();
    } catch (error) {
        setMessage(validationMessage(error), 'error');
    }
});

elements.cancelEditButton.addEventListener('click', () => {
    resetCourseForm();
    setMessage('');
});

elements.cancelCategoryEditButton.addEventListener('click', () => {
    resetCategoryForm();
    setMessage('');
});

elements.categoryList.addEventListener('click', async (event) => {
    const editButton = event.target.closest('[data-category-edit]');
    const deleteButton = event.target.closest('[data-category-delete]');

    if (editButton) {
        editCategory(editButton.dataset.categoryEdit);
        return;
    }

    if (!deleteButton) {
        return;
    }

    const category = state.categories.find((item) => String(item.id) === String(deleteButton.dataset.categoryDelete));

    if (!category || !window.confirm(`Delete "${category.name}"? Courses using it will become uncategorized.`)) {
        return;
    }

    try {
        await refreshCsrfCookie();
        await request(`/api/categories/${category.id}`, { method: 'DELETE' });
        setMessage('Category deleted.', 'success');
        resetCategoryForm();
        await refreshDashboard();
    } catch (error) {
        setMessage(validationMessage(error), 'error');
    }
});

elements.courseList.addEventListener('click', async (event) => {
    const editButton = event.target.closest('[data-course-edit]');
    const deleteButton = event.target.closest('[data-course-delete]');

    if (editButton) {
        editCourse(editButton.dataset.courseEdit);
        return;
    }

    if (!deleteButton) {
        return;
    }

    const course = state.courses.find((item) => String(item.id) === String(deleteButton.dataset.courseDelete));

    if (!course || !window.confirm(`Delete "${course.title}"?`)) {
        return;
    }

    try {
        await refreshCsrfCookie();
        await request(`/api/courses/${course.id}`, { method: 'DELETE' });
        setMessage('Course deleted.', 'success');
        resetCourseForm();
        await refreshDashboard();
    } catch (error) {
        setMessage(validationMessage(error), 'error');
    }
});

elements.logoutButton.addEventListener('click', async () => {
    try {
        await refreshCsrfCookie();
        await request('/api/logout', { method: 'POST' });
    } catch (error) {
        // Continue local cleanup even if the server already removed the session.
    }

    setToken(null);
    window.location.href = '/login';
});

elements.refreshButton.addEventListener('click', () => {
    refreshDashboard().catch((error) => {
        if (error.status === 401) {
            setToken(null);
            window.location.href = '/login';
            return;
        }

        setMessage(validationMessage(error), 'error');
    });
});

refreshDashboard().catch((error) => {
    if (error.status === 401) {
        elements.logoutButton.hidden = true;
        setToken(null);
        window.location.href = '/login';
        return;
    }

    setMessage('Could not load dashboard data.', 'error');
});
