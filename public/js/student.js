const tokenKey = 'sanctum_token';

const state = {
    token: localStorage.getItem(tokenKey),
    user: null,
    courses: [],
    categories: [],
    activePayments: {},
};

const elements = {
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
    courseCount: document.getElementById('courseCount'),
};

function setToken(token) {
    state.token = token;

    if (token) {
        localStorage.setItem(tokenKey, token);
        return;
    }

    localStorage.removeItem(tokenKey);
}

function escapeHtml(value) {
    return String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

function setMessage(message, type = 'info') {
    elements.authMessage.textContent = message;
    elements.authMessage.dataset.type = type;
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
    const method = options.method || 'GET';
    const headers = {
        Accept: 'application/json',
        ...options.headers,
    };

    if (options.body) {
        headers['Content-Type'] = 'application/json';
    }

    if (method !== 'GET') {
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

function renderCourses(courses) {
    elements.courseCount.textContent = courses.length;
    state.courses = courses;

    if (!courses.length) {
        elements.courseList.innerHTML = '<p class="empty-state">No courses found yet.</p>';
        return;
    }

    elements.courseList.innerHTML = courses.map((course) => {
        const canManage = state.user?.role === 'admin' || course.user_id === state.user?.id;
        const hasAccess = Boolean(course.has_access);
        const isEnrolled = Boolean(course.is_enrolled);
        const isFree = Number(course.price || 0) === 0;
        const priceLabel = isFree ? 'Free' : `$${Number(course.price || 0).toFixed(2)}`;
        const purchaseButton = isEnrolled || (hasAccess && !isFree)
            ? `<span class="access-pill">${isFree ? 'Enrolled' : 'Purchased'}</span>`
            : `<button class="secondary-action" type="button" data-course-pay="${course.id}">${isFree ? 'Enroll for free' : 'Pay with Bakong'}</button>`;

        return `
            <article class="course-item">
                <div class="course-main">
                    <h3>${escapeHtml(course.title)}</h3>
                    <p>${escapeHtml(course.category?.name || 'Uncategorized')} - ${escapeHtml(course.instructor?.name || 'No instructor')} - ${course.is_published ? 'Published' : 'Draft'}</p>
                    ${course.video_url ? `<p><a href="${escapeHtml(course.video_url)}" target="_blank" rel="noopener">Watch video</a></p>` : ''}
                    ${!hasAccess && !isFree ? '<p>Pay for this course to unlock the video.</p>' : ''}
                    ${course.description ? `<p>${escapeHtml(course.description)}</p>` : ''}
                </div>
                <div class="course-meta">
                    <span>${priceLabel}</span>
                    <div class="course-actions">
                        ${purchaseButton}
                        ${canManage ? `
                            <button class="secondary-action" type="button" data-course-edit="${course.id}">Edit</button>
                            <button class="danger-action" type="button" data-course-delete="${course.id}">Delete</button>
                        ` : ''}
                    </div>
                </div>
                <div class="course-payment-slot" id="paymentSlot-${course.id}" hidden></div>
            </article>
        `;
    }).join('');
}

function showCoursePayment(courseId, payment) {
    state.activePayments[courseId] = payment;

    const slot = document.getElementById(`paymentSlot-${courseId}`);

    if (!slot) {
        return;
    }

    slot.hidden = false;
    slot.innerHTML = `
        <div>
            <p class="eyebrow">Bakong payment</p>
            <h4>Scan KHQR for ${escapeHtml(payment.course?.title || 'this course')}</h4>
            <p>Amount: $${Number(payment.amount || 0).toFixed(2)}</p>
        </div>
        ${payment.qr_url ? `<img src="${escapeHtml(payment.qr_url)}" alt="Bakong KHQR for ${escapeHtml(payment.course?.title || 'course')}">` : ''}
        <div class="form-actions">
            <button type="button" data-payment-check="${courseId}">I have paid</button>
            <button class="secondary-action" type="button" data-payment-close="${courseId}">Close</button>
        </div>
    `;
    slot.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
}

function closeCoursePayment(courseId) {
    delete state.activePayments[courseId];

    const slot = document.getElementById(`paymentSlot-${courseId}`);

    if (slot) {
        slot.hidden = true;
        slot.innerHTML = '';
    }
}

function renderCategories(categories) {
    state.categories = categories;
    elements.categorySelect.innerHTML = [
        '<option value="">Uncategorized</option>',
        ...categories.map((category) => (
            `<option value="${category.id}">${escapeHtml(category.name)}</option>`
        )),
    ].join('');
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

async function refreshDashboard() {
    const [user, courses, categories] = await Promise.all([
        request('/api/user'),
        request('/api/my/courses'),
        request('/api/categories'),
    ]);

    if (user.role === 'admin') {
        window.location.href = '/admin/dashboard';
        return;
    }

    state.user = user;
    elements.logoutButton.hidden = false;
    elements.sessionStatus.textContent = `${user.name} (${user.role})`;
    elements.profileStrip.textContent = `Welcome ${user.name}. You are logged in as a ${user.role}.`;
    renderCategories(categories);
    renderCourses(courses);
}

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

elements.courseList.addEventListener('click', async (event) => {
    const editButton = event.target.closest('[data-course-edit]');
    const deleteButton = event.target.closest('[data-course-delete]');
    const payButton = event.target.closest('[data-course-pay]');
    const checkButton = event.target.closest('[data-payment-check]');
    const closeButton = event.target.closest('[data-payment-close]');

    if (editButton) {
        editCourse(editButton.dataset.courseEdit);
        return;
    }

    if (checkButton) {
        const courseId = checkButton.dataset.paymentCheck;
        const payment = state.activePayments[courseId];

        if (!payment) {
            return;
        }

        try {
            await refreshCsrfCookie();
            const response = await request(`/api/payments/${payment.id}/check`, {
                method: 'POST',
            });
            const checkedPayment = response.payment || response;

            if (checkedPayment.status !== 'paid') {
                setMessage(response.message || 'Bakong has not confirmed this payment yet. Try again in a few seconds.', 'warning');
                return;
            }

            closeCoursePayment(courseId);
            setMessage(`Payment confirmed. You are enrolled in ${checkedPayment.course?.title || 'the course'}.`, 'success');
            await refreshDashboard();
        } catch (error) {
            if (error.status === 202) {
                setMessage('Bakong has not confirmed this payment yet. Try again in a few seconds.', 'warning');
                return;
            }

            setMessage(validationMessage(error), 'error');
        }

        return;
    }

    if (closeButton) {
        closeCoursePayment(closeButton.dataset.paymentClose);
        return;
    }

    if (payButton) {
        try {
            await refreshCsrfCookie();
            payButton.disabled = true;
            const payment = await request('/api/payments', {
                method: 'POST',
                body: JSON.stringify({
                    course_id: payButton.dataset.coursePay,
                    method: 'bakong',
                }),
            });

            if (payment.status === 'paid') {
                setMessage(`You are enrolled in ${payment.course?.title || 'the course'}.`, 'success');
                await refreshDashboard();
                return;
            }

            showCoursePayment(payButton.dataset.coursePay, payment);
            setMessage('Scan the Bakong KHQR to pay.', 'info');
        } catch (error) {
            setMessage(validationMessage(error), 'error');
        } finally {
            payButton.disabled = false;
        }

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

refreshDashboard().catch((error) => {
    if (error.status === 401) {
        elements.logoutButton.hidden = true;
        setToken(null);
        window.location.href = '/login';
        return;
    }

    setMessage('Could not load student dashboard data.', 'error');
});
