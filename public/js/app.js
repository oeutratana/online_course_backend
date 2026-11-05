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
    analyticsPanel: document.getElementById('analyticsPanel'),
    analyticsTitle: document.getElementById('analyticsTitle'),
    analyticsStats: document.getElementById('analyticsStats'),
    analyticsTrend: document.getElementById('analyticsTrend'),
    analyticsRating: document.getElementById('analyticsRating'),
    analyticsDropOff: document.getElementById('analyticsDropOff'),
    closeAnalyticsButton: document.getElementById('closeAnalyticsButton'),
    statCards: {
        users: { value: document.getElementById('statUsers'), hint: document.getElementById('statUsersHint') },
        courses: { value: document.getElementById('statCourses'), hint: document.getElementById('statCoursesHint') },
        categories: { value: document.getElementById('statCategories'), hint: document.getElementById('statCategoriesHint') },
        enrollments: { value: document.getElementById('statEnrollments'), hint: document.getElementById('statEnrollmentsHint') },
        payments: { value: document.getElementById('statPayments'), hint: document.getElementById('statPaymentsHint') },
        lessons: { value: document.getElementById('statLessons'), hint: document.getElementById('statLessonsHint') },
        quizzes: { value: document.getElementById('statQuizzes'), hint: document.getElementById('statQuizzesHint') },
        questions: { value: document.getElementById('statQuestions'), hint: document.getElementById('statQuestionsHint') },
    },
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

/**
 * Reads every row of a paginated Laravel endpoint.
 *
 * `/api/courses`, `/api/my/courses` and `/api/admin/courses` are paginated, so
 * a single page would hide rows from the console. Laravel 10 returns the pager
 * fields at the root of the payload; newer versions nest them under `meta`.
 */
async function fetchAllRows(path) {
    const first = await request(path);

    if (!first || Array.isArray(first)) {
        return Array.isArray(first) ? first : [];
    }

    const rows = [...(first.data ?? [])];
    const lastPage = first.last_page ?? first.meta?.last_page ?? 1;

    for (let page = 2; page <= lastPage; page += 1) {
        const separator = path.includes('?') ? '&' : '?';
        const next = await request(`${path}${separator}page=${page}`);

        if (!Array.isArray(next?.data)) {
            break;
        }

        rows.push(...next.data);
    }

    return rows;
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
    elements.logoutButton.style.display = 'flex';
    elements.sessionStatus.textContent = `${user.name} (${user.role})`;
    const adminName = document.getElementById('adminName');
    if (adminName) adminName.textContent = user.name;
    elements.profileStrip.textContent = `Website protected by auth middleware. API checked by Sanctum as ${user.email}.`;
}

function renderCourses(courses) {
    // Course endpoints are paginated: accept both a bare array and a page payload.
    const rows = Array.isArray(courses) ? courses : (courses?.data ?? []);

    state.courses = rows;

    if (!rows.length) {
        elements.courseList.innerHTML = '<p class="empty-state">No courses found yet.</p>';
        return;
    }

    elements.courseList.innerHTML = `<div class="course-table-wrap"><table class="course-table"><thead><tr><th>#</th><th>Course Title</th><th>Category</th><th>Instructor</th><th>Price</th><th>Status</th><th>Action</th></tr></thead><tbody>${rows.slice(0, 6).map((course, index) => {
        const priceLabel = Number(course.price || 0) === 0
            ? 'Free'
            : `$${Number(course.price || 0).toFixed(2)}`;

        return `
        <tr><td>${index + 1}</td><td><span class="course-thumb">${escapeHtml((course.title || 'C').slice(0, 1).toUpperCase())}</span><strong>${escapeHtml(course.title)}</strong></td><td>${escapeHtml(course.category?.name || 'Uncategorized')}</td><td>${escapeHtml(course.instructor?.name || '—')}</td><td>${priceLabel}</td><td><span class="status-pill ${course.is_published ? 'published' : 'draft'}">${course.is_published ? 'Published' : 'Draft'}</span></td><td><div class="course-actions"><button class="table-action" type="button" aria-label="Edit course" data-course-edit="${course.id}">•••</button><button class="table-action" type="button" aria-label="Course analytics" data-course-analytics="${course.id}">↗</button><button class="table-action" type="button" aria-label="Delete course" data-course-delete="${course.id}">×</button></div></td></tr>
    `;
    }).join('')}</tbody></table></div>`;
}

// ─── COURSE ANALYTICS ─────────────────────────────────────────────

function formatMoney(amount, currency = 'USD') {
    const value = Number(amount || 0);

    try {
        return new Intl.NumberFormat(undefined, {
            style: 'currency',
            currency: currency || 'USD',
        }).format(value);
    } catch (error) {
        // An unknown currency code must not blank out the whole panel.
        return `${currency} ${value.toFixed(2)}`;
    }
}

function formatPercent(value) {
    return `${Number(value || 0).toFixed(Number(value || 0) % 1 === 0 ? 0 : 2)}%`;
}

function formatNumber(value) {
    return new Intl.NumberFormat().format(Number(value || 0));
}

/**
 * Inline sparkline for the 30 day trend. Two series, no chart library.
 */
function trendChart(points, currency) {
    const rows = Array.isArray(points) ? points : [];

    if (!rows.length) {
        return '<p class="empty-state">No activity recorded yet.</p>';
    }

    const width = 560;
    const height = 160;
    const padding = 24;
    const maxEnrollments = Math.max(1, ...rows.map((point) => Number(point.enrollments || 0)));
    const maxRevenue = Math.max(1, ...rows.map((point) => Number(point.revenue || 0)));

    const x = (index) => padding + (index * (width - padding * 2)) / Math.max(1, rows.length - 1);
    const y = (value, max) => height - padding - (Number(value || 0) / max) * (height - padding * 2);

    const enrollmentPath = rows
        .map((point, index) => `${index === 0 ? 'M' : 'L'} ${x(index).toFixed(1)} ${y(point.enrollments, maxEnrollments).toFixed(1)}`)
        .join(' ');
    const revenuePath = rows
        .map((point, index) => `${index === 0 ? 'M' : 'L'} ${x(index).toFixed(1)} ${y(point.revenue, maxRevenue).toFixed(1)}`)
        .join(' ');

    const totalEnrollments = rows.reduce((sum, point) => sum + Number(point.enrollments || 0), 0);
    const totalRevenue = rows.reduce((sum, point) => sum + Number(point.revenue || 0), 0);

    return `
        <svg class="trend-chart" viewBox="0 0 ${width} ${height}" role="img"
             aria-label="Enrollments and revenue over the last ${rows.length} days">
            <path class="trend-area" d="${revenuePath} L ${x(rows.length - 1).toFixed(1)} ${height - padding} L ${x(0).toFixed(1)} ${height - padding} Z"></path>
            <path class="trend-line trend-revenue" d="${revenuePath}"></path>
            <path class="trend-line trend-enrollments" d="${enrollmentPath}"></path>
        </svg>
        <p class="trend-legend">
            <span class="trend-key trend-key-enrollments">${formatNumber(totalEnrollments)} enrollments</span>
            <span class="trend-key trend-key-revenue">${formatMoney(totalRevenue, currency)} revenue</span>
        </p>
        <p class="trend-axis"><span>${escapeHtml(rows[0].date)}</span><span>${escapeHtml(rows[rows.length - 1].date)}</span></p>
    `;
}

function ratingBars(reviews) {
    const distribution = reviews?.distribution ?? {};
    const highest = Math.max(1, ...Object.values(distribution).map(Number));

    const rows = [5, 4, 3, 2, 1].map((star) => {
        const count = Number(distribution[star] || 0);
        const width = Math.round((count / highest) * 100);

        return `
            <div class="rating-row">
                <span class="rating-star">${star}★</span>
                <span class="rating-bar"><span class="rating-fill" style="width:${width}%"></span></span>
                <span class="rating-count">${formatNumber(count)}</span>
            </div>
        `;
    });

    return `
        <p class="rating-average">${Number(reviews?.average_rating || 0).toFixed(2)} / 5 from ${formatNumber(reviews?.total_reviews || 0)} reviews</p>
        ${rows.join('')}
    `;
}

function dropOffNotice(progress) {
    const lesson = progress?.biggest_drop_off;

    if (!lesson) {
        return progress?.total_lessons
            ? '<p class="drop-off drop-off-clear">Every enrolled student has finished all lessons.</p>'
            : '<p class="drop-off">This course has no lessons yet, so there is no progress to track.</p>';
    }

    return `
        <p class="drop-off">
            <strong>Most students stall on lesson ${escapeHtml(String(lesson.order))}: ${escapeHtml(lesson.title)}</strong>
            <span>${formatNumber(lesson.students_incomplete)} enrolled student(s) have not reached it yet.</span>
        </p>
    `;
}

function renderCourseAnalytics(analytics) {
    const { course, enrollments, progress, reviews, revenue, demand, trend } = analytics;

    elements.analyticsTitle.textContent = course.is_published ? course.title : `${course.title} (draft)`;
    elements.analyticsPanel.hidden = false;

    elements.analyticsStats.innerHTML = [
        ['Enrollments', formatNumber(enrollments.total), `${formatNumber(enrollments.last_30_days)} in the last 30 days`],
        ['Completed', formatNumber(enrollments.completed), formatPercent(enrollments.completion_rate) + ' completion rate'],
        ['Average progress', formatPercent(progress.average_completion_percent), `${formatNumber(progress.total_lessons)} lessons`],
        ['Gross revenue', formatMoney(revenue.gross_revenue, revenue.currency), `${formatNumber(revenue.successful_payments)} paid order(s)`],
        ['Average order', formatMoney(revenue.average_order_value, revenue.currency), `${formatPercent(revenue.failed_rate)} failed attempts`],
        ['Rating', Number(reviews.average_rating || 0).toFixed(2), `${formatNumber(reviews.total_reviews)} review(s)`],
        ['Wishlisted', formatNumber(demand.wishlists), 'students saving it for later'],
        ['Certificates', formatNumber(demand.certificates), 'completion awards issued'],
    ].map(([label, value, hint]) => `
        <article>
            <span>${escapeHtml(label)}</span>
            <strong>${escapeHtml(String(value))}</strong>
            <small>${escapeHtml(hint)}</small>
        </article>
    `).join('');

    elements.analyticsTrend.innerHTML = trendChart(trend.points, revenue.currency);
    elements.analyticsRating.innerHTML = ratingBars(reviews);
    elements.analyticsDropOff.innerHTML = dropOffNotice(progress);

    elements.analyticsPanel.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
}

async function showCourseAnalytics(courseId) {
    elements.analyticsPanel.hidden = false;
    elements.analyticsTitle.textContent = 'Loading analytics...';
    elements.analyticsStats.innerHTML = '';
    elements.analyticsTrend.innerHTML = '';
    elements.analyticsRating.innerHTML = '';
    elements.analyticsDropOff.innerHTML = '';

    try {
        const analytics = await request(`/api/admin/courses/${courseId}/analytics`);
        renderCourseAnalytics(analytics);
    } catch (error) {
        elements.analyticsPanel.hidden = true;
        setMessage(validationMessage(error), 'error');
    }
}

elements.closeAnalyticsButton.addEventListener('click', () => {
    elements.analyticsPanel.hidden = true;
});

function renderCategories(categories) {
    state.categories = categories;    elements.categorySelect.innerHTML = [
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
    const rows = Array.isArray(users) ? users.slice(0, 10) : [];
    if (!rows.length) {
        elements.userList.innerHTML = '<p class="empty-state">No users found yet.</p>';
        return;
    }

    elements.userList.innerHTML = rows.map((user) => `
        <article class="user-item">
            <div>
                <strong>${escapeHtml(user.name)}</strong>
                <span>${escapeHtml(user.email)}</span>
            </div>
            <span class="role-pill role-${escapeHtml(user.role)}">${escapeHtml(user.role)}</span>
        </article>
    `).join('');
}

function renderRecentCourses(courses) {
    const listEl = document.getElementById('recentCoursesList');
    if (!listEl) return;
    const rows = Array.isArray(courses) ? courses.slice(0, 5) : [];
    if (!rows.length) {
        listEl.innerHTML = '<p class="empty-state">No courses yet.</p>';
        return;
    }
    listEl.innerHTML = rows.map((course) => `
        <article class="recent-item">
            <span class="activity-icon activity-course">▣</span><div>
                <strong>${escapeHtml(course.title)}</strong>
                <span>${escapeHtml(course.category?.name || 'Uncategorized')} · ${course.is_published ? 'Published' : 'Draft'}</span>
            </div>
            <span class="recent-meta">${course.instructor?.name ? escapeHtml(course.instructor.name) : '-'}</span>
        </article>
    `).join('');
}

function renderRecentActivity(dashboard) {
    const listEl = document.getElementById('recentActivityList');
    if (!listEl) return;
    const rows = [
        ...(dashboard?.recent_enrollments || []).slice(0, 3).map((item) => ({
            type: 'enrollment', icon: '♧', title: 'New enrollment',
            detail: `${item.student?.name || 'A student'} enrolled in ${item.course?.title || 'a course'}`,
            at: item.enrolled_at,
        })),
        ...(dashboard?.recent_payments || []).slice(0, 3).map((item) => ({
            type: 'payment', icon: '▱', title: 'Payment received',
            detail: `${formatMoney(item.amount, item.currency)} from ${item.student?.name || 'a student'}`,
            at: item.created_at,
        })),
        ...(dashboard?.recent_users || []).slice(0, 2).map((item) => ({
            type: 'user', icon: '♙', title: 'New user registered',
            detail: `${item.name} joined the platform`,
            at: item.created_at,
        })),
        ...(dashboard?.recent_courses || []).slice(0, 2).map((item) => ({
            type: 'course', icon: '▣', title: item.is_published ? 'New course created' : 'Course updated',
            detail: item.title, at: item.created_at,
        })),
    ].sort((a, b) => new Date(b.at || 0) - new Date(a.at || 0)).slice(0, 5);
    if (!rows.length) {
        listEl.innerHTML = '<p class="empty-state">No recent activity yet.</p>';
        return;
    }
    listEl.innerHTML = rows.map((item) => `
        <article class="activity-entry">
            <span class="activity-icon activity-${item.type}">${item.icon}</span>
            <div><strong>${escapeHtml(item.title)}</strong><span>${escapeHtml(item.detail)}</span><small>${item.at ? escapeHtml(new Date(item.at).toLocaleString()) : 'Recently'}</small></div>
        </article>
    `).join('');
}

function renderEnrollmentTable(enrollments) {
    const listEl = document.getElementById('recentEnrollmentsList');
    if (!listEl) return;
    const rows = Array.isArray(enrollments) ? enrollments.slice(0, 5) : [];
    if (!rows.length) {
        listEl.innerHTML = '<p class="empty-state">No enrollments yet.</p>';
        return;
    }
    listEl.innerHTML = `<div class="course-table-wrap"><table class="course-table enrollment-table"><thead><tr><th>#</th><th>Student</th><th>Course</th><th>Enrolled At</th><th>Status</th></tr></thead><tbody>${rows.map((item, i) => `<tr><td>${i + 1}</td><td><span class="student-avatar">${escapeHtml((item.student?.name || '?').slice(0, 1).toUpperCase())}</span>${escapeHtml(item.student?.name || 'Unknown')}</td><td>${escapeHtml(item.course?.title || 'Course')}</td><td>${item.enrolled_at ? escapeHtml(new Date(item.enrolled_at).toLocaleString()) : '—'}</td><td><span class="status-pill published">${escapeHtml(item.status || 'Active')}</span></td></tr>`).join('')}</tbody></table></div>`;
}

function renderRecentEnrollments(enrollments) {
    const listEl = document.getElementById('recentEnrollmentsList');
    if (!listEl) return;
    const rows = Array.isArray(enrollments) ? enrollments.slice(0, 5) : [];
    if (!rows.length) {
        listEl.innerHTML = '<p class="empty-state">No enrollments yet.</p>';
        return;
    }
    listEl.innerHTML = rows.map((enrollment) => `
        <article class="recent-item">
            <div>
                <strong>${escapeHtml(enrollment.student?.name || 'Unknown')}</strong>
                <span>Enrolled in ${escapeHtml(enrollment.course?.title || 'Course')}</span>
                <small>${enrollment.enrolled_at ? new Date(enrollment.enrolled_at).toLocaleString() : 'Recently'}</small>
            </div>
            <span class="recent-meta">${enrollment.status || '-'}</span>
        </article>
    `).join('');
}

function renderRecentPayments(payments) {
    const listEl = document.getElementById('recentPaymentsList');
    if (!listEl) return;
    const rows = Array.isArray(payments) ? payments.slice(0, 5) : [];
    if (!rows.length) {
        listEl.innerHTML = '<p class="empty-state">No payments yet.</p>';
        return;
    }
    const currency = payments?.[0]?.currency || 'USD';
    listEl.innerHTML = rows.map((payment) => `
        <article class="recent-item">
            <div>
                <strong>Payment received · ${formatMoney(payment.amount, payment.currency || currency)}</strong>
                <span>${escapeHtml(payment.student?.name || 'Unknown')} · ${escapeHtml(payment.course?.title || 'Course')}</span>
            </div>
            <span class="recent-meta status-${escapeHtml(payment.status)}">${escapeHtml(payment.status)}</span>
        </article>
    `).join('');
}

/**
 * The eight headline counters.
 *
 * Everything comes from /api/admin/dashboard. The previous version counted the
 * cards from the length of the downloaded course/user/enrollment/payment lists,
 * which meant fetching whole tables just to take a number, and broke outright
 * whenever one of those endpoints started paginating.
 */
function renderSummary(dashboard) {
    const summary = dashboard?.summary ?? {};
    const users = dashboard?.users ?? {};
    const courses = dashboard?.courses ?? {};
    const enrollments = dashboard?.enrollments ?? {};
    const payments = dashboard?.payments ?? {};
    const lessons = dashboard?.lessons ?? {};
    const currency = payments.currency || summary.currency || 'USD';

    const cards = {
        users: [summary.total_users, `${formatNumber(users.total_students)} students · ${formatNumber(users.total_instructors)} instructors`],
        courses: [summary.total_courses, `${formatNumber(courses.published_courses)} published, ${formatNumber(courses.draft_courses)} draft`],
        categories: [summary.total_categories, 'taxonomy in use'],
        enrollments: [summary.total_enrollments, `${formatNumber(enrollments.active_enrollments)} active, ${formatNumber(enrollments.completed_enrollments)} completed`],
        payments: [formatMoney(summary.total_revenue, currency), `${formatNumber(payments.successful_payments)} successful payments`],
        lessons: [summary.total_lessons, `${formatNumber(lessons.draft_lessons)} in draft courses`],
        quizzes: [summary.total_quizzes, 'attached to lessons'],
        questions: [summary.total_questions, 'across all quizzes'],
    };

    Object.entries(cards).forEach(([key, [value, hint]]) => {
        const card = elements.statCards[key];

        if (!card) {
            return;
        }

        card.value.textContent = formatNumber(value);
        card.hint.textContent = hint;
    });
    const categoryTotal = document.getElementById('categoryCourseTotal');
    if (categoryTotal) categoryTotal.textContent = formatNumber(summary.total_courses);
    const revenueStat = elements.statCards.payments?.value;
    if (revenueStat) revenueStat.textContent = formatMoney(summary.total_revenue, currency);
}

async function refreshDashboard() {
    const [dashboard, courses, categories, user, users] = await Promise.all([
        // Counters come from the dashboard endpoint, not from counting lists.
        request('/api/admin/dashboard'),
        // The admin listing (drafts included) is paginated, so every page is
        // walked. `/api/my/courses` would only return the admin's own
        // enrollments, which is never what this console needs.
        fetchAllRows('/api/admin/courses?per_page=50'),
        request('/api/categories'),
        request('/api/user'),
        request('/api/users').catch(() => []),
    ]);

    state.user = user;
    renderSession(user);

    if (user.role !== 'admin') {
        return;
    }

    renderSummary(dashboard);
    renderCategories(categories);
    renderCourses(courses);
    renderUsers(users);
    document.querySelectorAll('.dashboard-management-tools').forEach((section) => { section.hidden = true; });
    const toolsBySection = {
        users: document.getElementById('users'),
        categories: document.getElementById('categories'),
        courses: document.querySelector('.course-management'),
    };
    document.querySelectorAll('.admin-nav a[href^="#"]').forEach((link) => {
        const key = link.getAttribute('href').slice(1);
        if (!toolsBySection[key]) return;
        link.addEventListener('click', (event) => {
            event.preventDefault();
            const section = toolsBySection[key];
            section.hidden = false;
            section.scrollIntoView({ behavior: 'smooth', block: 'start' });
        });
    });
    document.querySelectorAll('.action-grid a').forEach((link) => {
        link.addEventListener('click', (event) => {
            const targetId = link.getAttribute('href')?.slice(1);
            const section = toolsBySection[targetId] || document.getElementById(targetId);
            if (!section) return;
            event.preventDefault();
            section.hidden = false;
            section.scrollIntoView({ behavior: 'smooth', block: 'start' });
        });
    });
    renderRecentCourses(dashboard?.recent_courses || []);
    renderRecentEnrollments(dashboard?.recent_enrollments || []);
    renderRecentPayments(dashboard?.recent_payments || []);
    renderRecentActivity(dashboard);
    renderEnrollmentTable(dashboard?.recent_enrollments || []);
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
    const analyticsButton = event.target.closest('[data-course-analytics]');
    const editButton = event.target.closest('[data-course-edit]');
    const deleteButton = event.target.closest('[data-course-delete]');

    if (analyticsButton) {
        await showCourseAnalytics(analyticsButton.dataset.courseAnalytics);
        return;
    }

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
