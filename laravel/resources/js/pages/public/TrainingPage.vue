<script setup>
/**
 * The training hub — the Vue equivalent of `app/templates/training.html`.
 *
 * The template is the legacy markup element for element: the same divs, the same
 * class names, the same order, the same SVG icons and the same Persian labels.
 * The stylesheet that styles it (`resources/css/legacy/training.css`, ported
 * verbatim from `app/static/css/training.css`) is already loaded globally, so
 * this component carries no styles of its own.
 *
 * The catalogue lives in `app/data/training_content.json` and the only machine
 * contract the backend exposes for it is `GET /api/training/search`.  That
 * endpoint is a substring match over `title + description + category`, and the
 * category slug is deliberately part of the searchable text — so querying a
 * slug returns every lesson in that category, with the role filter
 * (`_category_accessible`) applied by the backend.  The hub therefore asks for
 * each category slug and groups the answers; a category page asks for its own
 * slug.  An empty query answers an empty list, which is why the hub cannot ask
 * for "everything" in one call.
 *
 * The legacy client's two interactive behaviours are ported: the live search
 * box (debounced 300 ms) and the lesson-completion progress kept in
 * `localStorage` (`hastama_training_progress`).
 */
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { RouterLink, useRoute } from 'vue-router';
import api from '@/services/api';
import { useAuthStore } from '@/stores/auth';

const route = useRoute();
const auth = useAuthStore();
const category = computed(() => route.params.category);
const fromLogin = computed(() => route.query.from_login === 'true');

const CATEGORY_SLUGS = ['general', 'user', 'admin'];

/*
 * The category cards show the category's own icon and description.  The search
 * contract answers lessons, not categories, so the display metadata the legacy
 * rendered from `training_data.categories` is reproduced here — the same
 * Persian copy the running application prints.
 */
const CATEGORY_META = {
    general: {
        icon: '📘',
        description: 'آموزش‌های پایه‌ای مفید برای همه کاربران سامانه',
    },
    user: {
        icon: '👤',
        description: 'آموزش کامل استفاده از پنل کاربری، از ورود تا استفاده از امکانات مختلف',
    },
    admin: {
        icon: '🛡️',
        description: 'آموزش کامل امکانات پنل مدیریت و نحوه مدیریت اطلاعات سامانه',
    },
};

const loading = ref(true);
const error = ref('');
const categories = ref([]);
const activeCategory = ref(null);

const searchQuery = ref('');
const searchResults = ref([]);
const searchOpen = ref(false);
const searchLoading = ref(false);
let searchTimer = null;

const PROGRESS_KEY = 'hastama_training_progress';
const completed = ref({});

const overviewCards = [
    { icon: '🔒', title: 'امنیت', text: 'حفاظت از اطلاعات حساب کاربری و رعایت اصول امنیتی.' },
    { icon: '👤', title: 'مسئولیت کاربر', text: 'هر کاربر مسئول حفظ اطلاعات ورود و فعالیت‌های حساب خود است.' },
    { icon: '⏰', title: 'حضور و ثبت اطلاعات', text: 'اطلاعات ثبت‌شده باید مطابق واقعیت و قوانین مجموعه باشد.' },
    { icon: '📋', title: 'رعایت مقررات', text: 'استفاده از امکانات هستما باید مطابق قوانین مجموعه باشد.' },
];

const backUrl = computed(() => {
    if (auth.isMasterAdmin) {
        return '/master-admin';
    }

    if (auth.isAdmin) {
        return '/admin';
    }

    return '/user_panel';
});

function getProgress() {
    try {
        return JSON.parse(localStorage.getItem(PROGRESS_KEY) || '{}');
    } catch {
        return {};
    }
}

function saveProgress() {
    try {
        localStorage.setItem(PROGRESS_KEY, JSON.stringify(completed.value));
    } catch {
        /* private browsing: the progress simply does not persist */
    }
}

function isCompleted(lessonId) {
    return Boolean(completed.value[lessonId]?.done);
}

function toggleComplete(lessonId) {
    if (isCompleted(lessonId)) {
        delete completed.value[lessonId];
    } else {
        completed.value[lessonId] = { done: true, ts: Date.now() };
    }

    saveProgress();
}

function roleLabel(role) {
    return role === 'admin' ? 'مدیر' : role === 'user' ? 'کاربر' : 'عمومی';
}

async function fetchBySlug(slug) {
    const response = await api.get('/training/search', { params: { q: slug } });
    return response.results ?? [];
}

function categoryMeta(slug) {
    return CATEGORY_META[slug] || { icon: '📖', description: '' };
}

async function loadHub() {
    loading.value = true;
    error.value = '';
    activeCategory.value = null;

    try {
        const results = await Promise.all(CATEGORY_SLUGS.map(fetchBySlug));
        const visible = fromLogin.value ? ['general'] : CATEGORY_SLUGS;
        const cats = [];

        visible.forEach((slug, index) => {
            const lessons = results[index];

            if (lessons.length) {
                const meta = categoryMeta(slug);
                cats.push({
                    slug,
                    title: lessons[0].category || slug,
                    icon: meta.icon,
                    description: meta.description,
                    lessons,
                });
            }
        });

        categories.value = cats;
    } catch (failure) {
        error.value = failure.apiFailure?.message || failure.message || 'خطا در دریافت آموزش‌ها.';
    } finally {
        loading.value = false;
    }
}

async function loadCategory(slug) {
    loading.value = true;
    error.value = '';
    categories.value = [];

    try {
        const lessons = await fetchBySlug(slug);
        const meta = categoryMeta(slug);
        activeCategory.value = {
            slug,
            title: lessons[0]?.category || slug,
            icon: meta.icon,
            description: meta.description,
            lessons,
        };
    } catch (failure) {
        error.value = failure.apiFailure?.message || failure.message || 'خطا در دریافت آموزش‌ها.';
        activeCategory.value = null;
    } finally {
        loading.value = false;
    }
}

function onSearchInput() {
    clearTimeout(searchTimer);

    const query = searchQuery.value.trim();

    if (query.length < 2) {
        searchOpen.value = false;
        searchResults.value = [];
        return;
    }

    searchTimer = setTimeout(runSearch, 300);
}

async function runSearch() {
    const query = searchQuery.value.trim();

    if (query.length < 2) {
        return;
    }

    searchLoading.value = true;

    try {
        const response = await api.get('/training/search', { params: { q: query } });
        searchResults.value = response.results ?? [];
        searchOpen.value = true;
    } catch {
        searchResults.value = [];
        searchOpen.value = true;
    } finally {
        searchLoading.value = false;
    }
}

function closeSearch() {
    searchOpen.value = false;
}

const completedCount = computed(() => {
    const lessons = activeCategory.value?.lessons ?? [];
    return lessons.filter((lesson) => isCompleted(lesson.id)).length;
});

const progressPercent = computed(() => {
    const total = activeCategory.value?.lessons.length ?? 0;

    if (!total) {
        return 0;
    }

    return Math.round((completedCount.value / total) * 100);
});

watch(category, (slug) => {
    if (slug) {
        loadCategory(slug);
    } else {
        loadHub();
    }
}, { immediate: true });

onMounted(() => {
    document.body.classList.add('tr-page');
    completed.value = getProgress();
    document.addEventListener('click', closeSearch);
});

onBeforeUnmount(() => {
    document.body.classList.remove('tr-page');
    clearTimeout(searchTimer);
    document.removeEventListener('click', closeSearch);
});
</script>

<template>
    <div class="tr">
        <div class="tr-bg-layer" aria-hidden="true"></div>

        <div class="tr-shell">
            <!-- Breadcrumb -->
            <nav class="tr-breadcrumb" aria-label="مسیر صفحه">
                <RouterLink to="/login">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/><polyline points="10 17 15 12 10 7"/><line x1="15" y1="12" x2="3" y2="12"/></svg>
                    ورود
                </RouterLink>
                <span class="tr-breadcrumb__sep">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg>
                </span>
                <template v-if="category">
                    <RouterLink :to="'/training' + (fromLogin ? '?from_login=true' : '')">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"/><path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"/></svg>
                        آموزش سامانه
                    </RouterLink>
                    <span class="tr-breadcrumb__sep">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg>
                    </span>
                    <span class="tr-breadcrumb__current">{{ activeCategory?.title || category }}</span>
                </template>
                <span v-else class="tr-breadcrumb__current">آموزش سامانه</span>
            </nav>

            <RouterLink v-if="auth.isAuthenticated" :to="backUrl" class="tr-back-btn">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 12H5"/><polyline points="12 19 5 12 12 5"/></svg>
                بازگشت به پنل
            </RouterLink>

            <!-- Error State -->
            <div v-if="error" class="tr-error">
                <div class="tr-error__icon">⚠️</div>
                <div class="tr-error__title">{{ error }}</div>
                <div class="tr-error__text">لطفاً از صفحه اصلی آموزش‌ها دوباره تلاش کنید.</div>
                <RouterLink to="/login" style="margin-top:16px;display:inline-flex;padding:10px 24px;background:var(--tr-primary);color:#fff;border-radius:100px;">بازگشت به صفحه ورود</RouterLink>
            </div>

            <template v-else>
                <!-- Loading State -->
                <div v-if="loading" class="tr-loading">
                    <p>در حال بارگذاری آموزش‌ها…</p>
                </div>

                <template v-else>
                    <!-- Hero -->
                    <section class="tr-hero">
                        <div class="tr-hero__badge">🎓 راهنمای استفاده از هستما</div>
                        <h1 class="tr-hero__title">
                            <template v-if="category">
                                {{ activeCategory?.title || category }}
                            </template>
                            <template v-else>
                                آموزش سامانه <span>هستما</span>
                            </template>
                        </h1>
                        <p class="tr-hero__desc">
                            <template v-if="category">
                                {{ activeCategory?.description || '' }}
                            </template>
                            <template v-else>
                                راهنمای ساده و مرحله‌به‌مرحله برای استفاده از تمام امکانات سامانه
                            </template>
                        </p>
                    </section>

                    <!-- Search -->
                    <div class="tr-search">
                        <svg class="tr-search__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.2-3.2"/></svg>
                        <input
                            v-model="searchQuery"
                            type="text"
                            id="trSearchInput"
                            class="tr-search__input"
                            placeholder="جستجو در آموزش‌ها..."
                            autocomplete="off"
                            @input="onSearchInput"
                        >
                        <div id="trSearchResults" class="tr-search__results" :class="{ 'is-open': searchOpen }">
                            <p v-if="searchLoading" class="tr-search__empty">در حال جستجو…</p>
                            <p v-else-if="!searchResults.length" class="tr-search__empty">آموزشی با این عنوان پیدا نشد</p>
                            <RouterLink
                                v-for="result in searchResults"
                                :key="result.id"
                                :to="'/training/lesson/' + encodeURIComponent(String(result.id))"
                                class="tr-search__item"
                            >
                                <span class="tr-search__item-icon">{{ result.icon }}</span>
                                <div class="tr-search__item-info">
                                    <div class="tr-search__item-title">{{ result.title }}</div>
                                    <div class="tr-search__item-cat">{{ result.category }} — {{ roleLabel(result.role) }}</div>
                                </div>
                            </RouterLink>
                        </div>
                    </div>

                    <template v-if="!category">
                        <!-- Overview Cards -->
                        <section class="tr-overview">
                            <div v-for="card in overviewCards" :key="card.title" class="tr-overview__card">
                                <div class="tr-overview__icon" :class="`tr-overview__icon--${card.icon === '🔒' ? 'blue' : card.icon === '👤' ? 'green' : card.icon === '⏰' ? 'amber' : 'purple'}`">{{ card.icon }}</div>
                                <div class="tr-overview__text">
                                    <h3>{{ card.title }}</h3>
                                    <p>{{ card.text }}</p>
                                </div>
                            </div>
                        </section>

                        <!-- Category Cards -->
                        <section class="tr-categories">
                            <RouterLink
                                v-for="(cat, index) in categories"
                                :key="cat.slug"
                                :to="'/training/' + cat.slug"
                                class="tr-cat-card"
                                :style="{ animationDelay: (index * 0.1) + 's' }"
                            >
                                <div class="tr-cat-card__head">
                                    <div class="tr-cat-card__icon" :class="`tr-cat-card__icon--${cat.slug}`">{{ cat.icon }}</div>
                                    <div>
                                        <div class="tr-cat-card__title">{{ cat.title }}</div>
                                    </div>
                                </div>
                                <div class="tr-cat-card__desc">{{ cat.description }}</div>
                                <div class="tr-cat-card__meta">
                                    <span>📖 {{ cat.lessons.length }} آموزش</span>
                                    <span>⏱ حدود {{ cat.lessons.length * 2 }} دقیقه</span>
                                </div>
                            </RouterLink>
                        </section>
                    </template>

                    <template v-else>
                        <!-- Category Detail: Lesson List -->
                        <div class="tr-lessons">
                            <RouterLink
                                v-for="(lesson, index) in activeCategory?.lessons ?? []"
                                :key="lesson.id"
                                :to="'/training/lesson/' + encodeURIComponent(String(lesson.id))"
                                class="tr-lesson-item"
                                :data-lesson-id="lesson.id"
                                :style="{ animationDelay: (index * 0.05) + 's', opacity: isCompleted(lesson.id) ? '.7' : '' }"
                            >
                                <div class="tr-lesson-item__num">{{ index + 1 }}</div>
                                <div class="tr-lesson-item__info">
                                    <div class="tr-lesson-item__title">{{ lesson.icon }} {{ lesson.title }}</div>
                                    <div class="tr-lesson-item__desc">{{ lesson.description }}</div>
                                </div>
                                <div v-if="lesson.estimated_time" class="tr-lesson-item__time">⏱ {{ lesson.estimated_time }}</div>
                                <svg class="tr-lesson-item__arrow" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M15 18l-6-6 6-6"/></svg>
                                <span v-if="isCompleted(lesson.id)" style="color:#10b981;font-size:.8rem;font-weight:600;">✓ تکمیل شده</span>
                            </RouterLink>
                        </div>

                        <!-- Progress Bar -->
                        <div v-if="activeCategory && activeCategory.lessons.length" style="max-width:400px;margin:0 auto 40px;text-align:center;">
                            <div style="display:flex;justify-content:space-between;font-size:.8rem;color:var(--tr-text-muted);margin-bottom:4px;">
                                <span>پیشرفت شما</span>
                                <span data-progress-text>{{ progressPercent }}%</span>
                            </div>
                            <div class="tr-progress-bar">
                                <div class="tr-progress-bar__fill" :style="{ width: progressPercent + '%' }"></div>
                            </div>
                        </div>
                    </template>
                </template>
            </template>

            <!-- Footer -->
            <footer class="tr-footer">
                هستما — سامانه مدیریت هوشمند
            </footer>
        </div>
    </div>
</template>

<style scoped>
.tr-loading {
    text-align: center;
    padding: 60px 20px;
    color: var(--tr-text-muted);
}
</style>
