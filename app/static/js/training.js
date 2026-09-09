/* ═══════════════════════════════════════════════════════════════
   HASTAMA TRAINING SYSTEM — JavaScript
   ═══════════════════════════════════════════════════════════════ */
(function () {
  'use strict';

  /* ── Local Progress Storage ────────────────────────────────── */
  const PROGRESS_KEY = 'hastama_training_progress';

  function getProgress() {
    try { return JSON.parse(localStorage.getItem(PROGRESS_KEY) || '{}'); }
    catch { return {}; }
  }

  function setProgress(lessonId, completed) {
    const p = getProgress();
    if (completed) p[lessonId] = { done: true, ts: Date.now() };
    else delete p[lessonId];
    localStorage.setItem(PROGRESS_KEY, JSON.stringify(p));
  }

  function isCompleted(lessonId) {
    return !!getProgress()[lessonId]?.done;
  }

  function getTotalLessons() {
    return document.querySelectorAll('.tr-lesson-item[data-lesson-id]').length;
  }

  function getCompletedCount() {
    let n = 0;
    document.querySelectorAll('.tr-lesson-item[data-lesson-id]').forEach(el => {
      if (isCompleted(el.dataset.lessonId)) n++;
    });
    return n;
  }

  /* ── Search ────────────────────────────────────────────────── */
  const searchInput = document.getElementById('trSearchInput');
  const searchResults = document.getElementById('trSearchResults');
  let searchTimer = null;

  if (searchInput && searchResults) {
    searchInput.addEventListener('input', function () {
      clearTimeout(searchTimer);
      const q = this.value.trim();
      if (q.length < 2) { searchResults.classList.remove('is-open'); return; }
      searchTimer = setTimeout(() => {
        fetch('/api/training/search?q=' + encodeURIComponent(q))
          .then(r => r.json())
          .then(data => {
            if (!data.success || !data.results.length) {
              searchResults.innerHTML = '<div class="tr-search__empty">آموزشی با این عنوان پیدا نشد</div>';
              searchResults.classList.add('is-open');
              return;
            }
            searchResults.innerHTML = data.results.map(r => `
              <a href="/training/lesson/${r.id}" class="tr-search__item">
                <span class="tr-search__item-icon">${r.icon}</span>
                <div class="tr-search__item-info">
                  <div class="tr-search__item-title">${r.title}</div>
                  <div class="tr-search__item-cat">${r.category} — ${r.role === 'admin' ? 'مدیر' : r.role === 'user' ? 'کاربر' : 'عمومی'}</div>
                </div>
              </a>
            `).join('');
            searchResults.classList.add('is-open');
          })
          .catch(() => {
            searchResults.innerHTML = '<div class="tr-search__empty">خطا در جستجو</div>';
            searchResults.classList.add('is-open');
          });
      }, 300);
    });

    document.addEventListener('click', e => {
      if (!searchInput.contains(e.target) && !searchResults.contains(e.target)) {
        searchResults.classList.remove('is-open');
      }
    });
  }

  /* ── Lesson Completion Toggle ──────────────────────────────── */
  document.addEventListener('click', e => {
    const btn = e.target.closest('[data-toggle-complete]');
    if (!btn) return;
    const id = btn.dataset.lessonId || btn.closest('[data-lesson-id]')?.dataset?.lessonId;
    if (!id) return;
    const done = !isCompleted(id);
    setProgress(id, done);
    btn.classList.toggle('is-done', done);
    btn.textContent = done ? '✓ تکمیل شده' : 'علامت‌گذاری به عنوان تکمیل‌شده';
  });

  /* ── Update progress bars on category pages ────────────────── */
  function updateCategoryProgress() {
    const total = getTotalLessons();
    if (!total) return;
    const done = getCompletedCount();
    const pct = Math.round((done / total) * 100);
    document.querySelectorAll('.tr-progress-bar__fill').forEach(el => {
      el.style.width = pct + '%';
    });
    document.querySelectorAll('[data-progress-text]').forEach(el => {
      el.textContent = pct + '%';
    });
  }
  updateCategoryProgress();

  /* ── Mark completed lessons in list ────────────────────────── */
  document.querySelectorAll('.tr-lesson-item[data-lesson-id]').forEach(el => {
    if (isCompleted(el.dataset.lessonId)) {
      el.style.opacity = '.7';
      el.insertAdjacentHTML('beforeend',
        '<span style="color:#10b981;font-size:.8rem;font-weight:600;">✓ تکمیل شده</span>'
      );
    }
  });

  /* ── Scroll Reveal (lightweight) ───────────────────────────── */
  if ('IntersectionObserver' in window) {
    const obs = new IntersectionObserver(entries => {
      entries.forEach(e => {
        if (e.isIntersecting) {
          e.target.style.opacity = '1';
          e.target.style.transform = 'translateY(0)';
          obs.unobserve(e.target);
        }
      });
    }, { threshold: 0.1 });
    document.querySelectorAll('.tr-block, .tr-step, .tr-lesson-item').forEach(el => {
      el.style.opacity = '0';
      el.style.transform = 'translateY(12px)';
      el.style.transition = 'opacity .4s ease, transform .4s ease';
      obs.observe(el);
    });
  }
})();
