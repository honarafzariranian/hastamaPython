<script setup>
/**
 * A single training lesson — the Vue equivalent of
 * `app/templates/training-lesson.html`.
 *
 * The template is the legacy markup element for element: the same divs, the same
 * class names, the same order, the same SVG icons and the same Persian labels.
 * The stylesheet that styles it (`resources/css/legacy/training.css`, ported
 * verbatim from `app/static/css/training.css`) is already loaded globally, so
 * this component carries no styles of its own.
 *
 * The lesson is reached from a category page (or a search result), and the only
 * machine contract is `GET /api/training/search`.  The endpoint is a substring
 * match whose searchable text includes the category slug, so the page asks for
 * every category slug and picks the lesson whose `id` matches the route — the
 * same role filter the hub applies is applied here by the backend, so a lesson
 * the caller may not see simply is not among the answers.
 *
 * The search answer carries the summary fields only (id, title, description,
 * category, role, icon); the long-form body the legacy template rendered
 * (steps, «for what», «before start», «important», «if wrong» and the estimated
 * time) is not part of that contract, so those blocks render only when the
 * data is there.  The previous/next navigation the legacy built from
 * `previous_lesson` / `next_lesson` is computed here from the category's lesson
 * list, which the search answers in the catalogue's own order.
 */
import { computed, onMounted, ref, watch } from 'vue';
import { RouterLink, useRoute } from 'vue-router';
import api from '@/services/api';
import { useAuthStore } from '@/stores/auth';

const route = useRoute();
const auth = useAuthStore();
const lessonId = computed(() => route.params.lessonId);

const CATEGORY_SLUGS = ['general', 'user', 'admin'];

const loading = ref(true);
const error = ref('');
const lesson = ref(null);
const categorySlug = ref('');
const prevLesson = ref(null);
const nextLesson = ref(null);

const PROGRESS_KEY = 'hastama_training_progress';
const completed = ref({});

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

function isCompleted(id) {
    return Boolean(completed.value[id]?.done);
}

function toggleComplete(id) {
    if (isCompleted(id)) {
        delete completed.value[id];
    } else {
        completed.value[id] = { done: true, ts: Date.now() };
    }

    saveProgress();
}

function roleLabel(role) {
    return role === 'admin' ? '🛡️ ویژه مدیران' : role === 'user' ? '👤 ویژه کاربران' : '📘 عمومی';
}

async function fetchBySlug(slug) {
    const response = await api.get('/training/search', { params: { q: slug } });
    return (response.results ?? []).map((item) => ({ ...item, _slug: slug }));
}

async function loadLesson() {
    loading.value = true;
    error.value = '';
    lesson.value = null;
    prevLesson.value = null;
    nextLesson.value = null;

    try {
        const results = await Promise.all(CATEGORY_SLUGS.map(fetchBySlug));
        const all = results.flat();
        const found = all.find((item) => item.id === lessonId.value);

        if (!found) {
            error.value = 'آموزش مورد نظر یافت نشد.';
            return;
        }

        lesson.value = found;
        categorySlug.value = found._slug;

        const categoryLessons = all.filter((item) => item._slug === found._slug);
        const index = categoryLessons.findIndex((item) => item.id === found.id);

        prevLesson.value = index > 0 ? categoryLessons[index - 1] : null;
        nextLesson.value = index >= 0 && index < categoryLessons.length - 1 ? categoryLessons[index + 1] : null;
    } catch (failure) {
        error.value = failure.apiFailure?.message || failure.message || 'خطا در دریافت آموزش.';
    } finally {
        loading.value = false;
    }
}

const isCurrentCompleted = computed(() => (lesson.value ? isCompleted(lesson.value.id) : false));

watch(lessonId, loadLesson, { immediate: true });

onMounted(() => {
    document.body.classList.add('tr-page');
    completed.value = getProgress();
});
</script>

<template>
    <div class="tr">
        <div class="tr-bg-layer" aria-hidden="true"></div>

        <div class="tr-shell">
            <!-- Error State -->
            <div v-if="error" class="tr-error" style="margin-top:80px;">
                <div class="tr-error__icon">📚</div>
                <div class="tr-error__title">{{ error }}</div>
                <div class="tr-error__text">آموزش مورد نظر در سیستم وجود ندارد.</div>
                <RouterLink to="/login" style="margin-top:16px;display:inline-flex;padding:10px 24px;background:var(--tr-primary);color:#fff;border-radius:100px;">بازگشت به صفحه ورود</RouterLink>
            </div>

            <template v-else>
                <!-- Loading State -->
                <div v-if="loading" class="tr-loading">
                    <p>در حال بارگذاری آموزش…</p>
                </div>

                <template v-else-if="lesson">
                    <!-- Breadcrumb -->
                    <nav class="tr-breadcrumb" aria-label="مسیر صفحه">
                        <RouterLink to="/login">ورود</RouterLink>
                        <span class="tr-breadcrumb__sep">/</span>
                        <RouterLink to="/training">آموزش سامانه</RouterLink>
                        <span class="tr-breadcrumb__sep">/</span>
                        <RouterLink :to="'/training/' + categorySlug">{{ lesson.category }}</RouterLink>
                        <span class="tr-breadcrumb__sep">/</span>
                        <span>{{ lesson.title }}</span>
                    </nav>

                    <RouterLink v-if="auth.isAuthenticated" :to="backUrl" class="tr-back-btn">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 12H5"/><polyline points="12 19 5 12 12 5"/></svg>
                        بازگشت به پنل
                    </RouterLink>

                    <article class="tr-lesson">
                        <!-- Back Button -->
                        <RouterLink :to="'/training/' + categorySlug" class="tr-lesson__back">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M9 18l6-6-6-6"/></svg>
                            بازگشت به فهرست آموزش‌ها
                        </RouterLink>

                        <!-- Header -->
                        <div class="tr-lesson__header">
                            <div class="tr-lesson__icon">{{ lesson.icon }}</div>
                            <h1 class="tr-lesson__title">{{ lesson.title }}</h1>
                            <p class="tr-lesson__desc">{{ lesson.description }}</p>
                            <div class="tr-lesson__meta">
                                <span v-if="lesson.estimated_time">⏱ {{ lesson.estimated_time }}</span>
                                <span>📖 {{ lesson.category }}</span>
                                <span>{{ roleLabel(lesson.role) }}</span>
                            </div>
                        </div>

                        <!-- For What -->
                        <div v-if="lesson.for_what" class="tr-block tr-block--info">
                            <div class="tr-block__label">🎯 این آموزش برای چیست؟</div>
                            <div class="tr-block__text">{{ lesson.for_what }}</div>
                        </div>

                        <!-- Before Start -->
                        <div v-if="lesson.before_start" class="tr-block tr-block--warning">
                            <div class="tr-block__label">📋 قبل از شروع</div>
                            <div class="tr-block__text">{{ lesson.before_start }}</div>
                        </div>

                        <!-- Steps -->
                        <div v-if="lesson.steps && lesson.steps.length" class="tr-steps">
                            <div v-for="(step, index) in lesson.steps" :key="index" class="tr-step">
                                <div class="tr-step__num">{{ index + 1 }}</div>
                                <div class="tr-step__content">
                                    <div class="tr-step__title">{{ step.title }}</div>
                                    <div class="tr-step__text">{{ step.content }}</div>
                                </div>
                            </div>
                        </div>

                        <!-- Important Note -->
                        <div v-if="lesson.important" class="tr-block tr-block--success">
                            <div class="tr-block__label">💡 نکته مهم</div>
                            <div class="tr-block__text">{{ lesson.important }}</div>
                        </div>

                        <!-- If Wrong -->
                        <div v-if="lesson.if_wrong" class="tr-block tr-block--danger">
                            <div class="tr-block__label">⚠️ اگر اشتباه شد چه کار کنیم؟</div>
                            <div class="tr-block__text">{{ lesson.if_wrong }}</div>
                        </div>

                        <!-- Complete Button -->
                        <div style="text-align:center;margin:32px 0;">
                            <button
                                type="button"
                                class="tr-complete-btn"
                                :class="{ 'is-done': isCurrentCompleted }"
                                :aria-pressed="String(isCurrentCompleted)"
                                data-toggle-complete
                                :data-lesson-id="lesson.id"
                                @click="toggleComplete(lesson.id)"
                            >
                                <svg v-if="isCurrentCompleted" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:middle;margin-inline-end:6px;"><path d="M4.5 12.5 10 18 19.5 7"/></svg>
                                <svg v-else width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:middle;margin-inline-end:6px;"><rect x="3" y="3" width="18" height="18" rx="4"/></svg>
                                {{ isCurrentCompleted ? 'تکمیل شده ✓' : 'علامت‌گذاری به عنوان تکمیل‌شده' }}
                            </button>
                        </div>

                        <!-- Prev / Next Navigation -->
                        <div class="tr-nav">
                            <RouterLink v-if="prevLesson" :to="'/training/lesson/' + encodeURIComponent(String(prevLesson.id))" class="tr-nav__link">
                                <div class="tr-nav__link-label">← آموزش قبلی</div>
                                <div class="tr-nav__link-title">{{ prevLesson.icon }} {{ prevLesson.title }}</div>
                            </RouterLink>
                            <RouterLink v-if="nextLesson" :to="'/training/lesson/' + encodeURIComponent(String(nextLesson.id))" class="tr-nav__link tr-nav__link--next">
                                <div class="tr-nav__link-label">آموزش بعدی →</div>
                                <div class="tr-nav__link-title">{{ nextLesson.icon }} {{ nextLesson.title }}</div>
                            </RouterLink>
                        </div>

                        <!-- Back to Category -->
                        <div style="text-align:center;margin-top:24px;">
                            <RouterLink :to="'/training/' + categorySlug" class="tr-lesson__back" style="display:inline-flex;">
                                بازگشت به فهرست آموزش‌های {{ lesson.category }}
                            </RouterLink>
                        </div>
                    </article>
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
