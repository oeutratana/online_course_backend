const state = {
    courses: [],
    filteredCourses: [],
    currentPage: 1,
    perPage: 6,
};

const elements = {
    search: document.getElementById('courseSearch'),
    sort: document.getElementById('courseSort'),
    count: document.getElementById('catalogCount'),
    categories: document.getElementById('categoryFilters'),
    list: document.getElementById('courseList'),
    alert: document.getElementById('catalogAlert'),
    previous: document.getElementById('previousPage'),
    next: document.getElementById('nextPage'),
    currentPage: document.getElementById('currentPage'),
};

function escapeHtml(value) {
    return String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

async function getJson(path) {
    const controller = new AbortController();
    const timeout = setTimeout(() => controller.abort(), 8000);

    let response;

    try {
        response = await fetch(path, {
            headers: { Accept: 'application/json' },
            signal: controller.signal,
        });
    } catch (error) {
        throw new Error(error.name === 'AbortError' ? 'Request timed out' : error.message);
    } finally {
        clearTimeout(timeout);
    }

    const data = await response.json().catch(() => null);

    if (!response.ok) {
        throw new Error(data?.message || 'Failed to fetch');
    }

    return data;
}

/**
 * Follows a Laravel paginated JSON endpoint and returns every row.
 *
 * Laravel 10 sends the pager fields at the root of the payload
 * (`data`, `last_page`, ...); newer versions nest them under `meta`.
 * A plain array response is treated as a single page.
 */
async function fetchAllPages(url) {
    const first = await getJson(url);

    if (!first || Array.isArray(first)) {
        return Array.isArray(first) ? first : [];
    }

    const rows = [...(first.data ?? [])];
    const lastPage = first.last_page ?? first.meta?.last_page ?? 1;

    for (let page = 2; page <= lastPage; page += 1) {
        const separator = url.includes('?') ? '&' : '?';
        const next = await getJson(`${url}${separator}page=${page}`);

        if (!Array.isArray(next?.data)) {
            break;
        }

        rows.push(...next.data);
    }

    return rows;
}

function checkedValues(name) {
    return [...document.querySelectorAll(`input[name="${name}"]:checked`)].map((input) => input.value);
}

function activeCategoryId() {
    return document.querySelector('input[name="category"]:checked')?.value || '';
}

function courseDifficulty(course) {
    return String(course.difficulty || course.level || '').toLowerCase();
}

function matchesPrice(course, priceFilters) {
    if (!priceFilters.length || priceFilters.length === 2) {
        return true;
    }

    const price = Number(course.price || 0);

    return priceFilters.includes(price === 0 ? 'free' : 'paid');
}

function sortCourses(courses) {
    const sorted = [...courses];

    if (elements.sort.value === 'price_low') {
        return sorted.sort((a, b) => Number(a.price || 0) - Number(b.price || 0));
    }

    if (elements.sort.value === 'newest') {
        return sorted.sort((a, b) => new Date(b.created_at || 0) - new Date(a.created_at || 0));
    }

    return sorted.sort((a, b) => Number(b.enrollments_count || b.payments_count || 0) - Number(a.enrollments_count || a.payments_count || 0));
}

function applyFilters() {
    const search = elements.search.value.trim().toLowerCase();
    const categoryId = activeCategoryId();
    const difficulty = checkedValues('difficulty')[0] || '';
    const prices = checkedValues('price');

    state.filteredCourses = sortCourses(state.courses.filter((course) => {
        const text = `${course.title || ''} ${course.description || ''} ${course.category?.name || ''}`.toLowerCase();

        return (!search || text.includes(search))
            && (!categoryId || String(course.category_id || course.category?.id || '') === categoryId)
            && (!difficulty || !courseDifficulty(course) || courseDifficulty(course) === difficulty)
            && matchesPrice(course, prices);
    }));

    state.currentPage = 1;
    renderCourses();
}

function renderEmptyState() {
    elements.list.innerHTML = `
        <section class="catalog-empty">
            <h2>No courses found</h2>
            <p>Try another search word or choose fewer options.</p>
        </section>
    `;
}

function renderCourses() {
    const totalPages = Math.max(1, Math.ceil(state.filteredCourses.length / state.perPage));
    state.currentPage = Math.min(state.currentPage, totalPages);
    const start = (state.currentPage - 1) * state.perPage;
    const courses = state.filteredCourses.slice(start, start + state.perPage);

    elements.count.textContent = state.filteredCourses.length;
    elements.currentPage.textContent = state.currentPage;
    elements.previous.disabled = state.currentPage <= 1;
    elements.next.disabled = state.currentPage >= totalPages;

    if (!courses.length) {
        renderEmptyState();
        return;
    }

    elements.list.innerHTML = courses.map((course) => `
        <article class="catalog-course">
            ${course.thumbnail ? `<img src="${escapeHtml(course.thumbnail)}" alt="">` : '<div class="course-thumb-fallback"></div>'}
            <div>
                <p>${escapeHtml(course.category?.name || 'Uncategorized')}</p>
                <h2>${escapeHtml(course.title)}</h2>
                <span>${escapeHtml(course.instructor?.name || 'No instructor')}</span>
            </div>
            <strong>${Number(course.price || 0) === 0 ? 'Free' : `$${Number(course.price || 0).toFixed(2)}`}</strong>
        </article>
    `).join('');
}

function renderCategories(categories) {
    elements.categories.innerHTML = categories.map((category) => `
        <label class="filter-choice">
            <input type="radio" name="category" value="${category.id}">
            <span>${escapeHtml(category.name)}</span>
        </label>
    `).join('');
}

function showAlert(message) {
    elements.alert.hidden = false;
    elements.alert.textContent = message;
}

async function loadCatalog() {
    try {
        // The courses endpoint is paginated: walk every page so the catalog
        // keeps showing the full list. `data` holds the rows, the root level
        // fields hold the paging info.
        const [courses, categories] = await Promise.all([
            fetchAllPages('/api/courses?per_page=60'),
            getJson('/api/categories'),
        ]);

        state.courses = courses;
        renderCategories(categories);
        applyFilters();
    } catch (error) {
        state.courses = [];
        state.filteredCourses = [];
        renderCategories([]);
        renderCourses();
        showAlert(`Backend courses could not load. ${error.message}`);
    }
}

document.addEventListener('input', (event) => {
    if (event.target.matches('#courseSearch, #courseSort, input[name="category"], input[name="difficulty"], input[name="price"]')) {
        applyFilters();
    }
});

elements.previous.addEventListener('click', () => {
    state.currentPage -= 1;
    renderCourses();
});

elements.next.addEventListener('click', () => {
    state.currentPage += 1;
    renderCourses();
});

loadCatalog();
