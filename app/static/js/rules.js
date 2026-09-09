/* ═══════════════════════════════════════════════════════════════════
   HASTAMA — قوانین و مقررات — Interactions & Scroll Tracking
   ═══════════════════════════════════════════════════════════════════ */
(function () {
  'use strict';

  /* ── Reading Progress Bar ──────────────────────────────────── */
  const progressBar = document.querySelector('.rl-progress__bar');
  function updateProgress() {
    const h = document.documentElement;
    const pct = (h.scrollTop / (h.scrollHeight - h.clientHeight)) * 100;
    if (progressBar) progressBar.style.width = Math.min(pct, 100) + '%';
  }

  /* ── Top Nav Scroll State ──────────────────────────────────── */
  const topnav = document.querySelector('.rl-topnav');
  function updateNav() {
    if (!topnav) return;
    topnav.classList.toggle('scrolled', window.scrollY > 30);
  }

  /* ── Section Reveal on Scroll ──────────────────────────────── */
  const sections = document.querySelectorAll('.rl-section');
  const revealObserver = new IntersectionObserver(
    (entries) => {
      entries.forEach((e) => {
        if (e.isIntersecting) {
          e.target.classList.add('visible');
          revealObserver.unobserve(e.target);
        }
      });
    },
    { threshold: 0.08, rootMargin: '0px 0px -40px 0px' }
  );
  sections.forEach((s) => revealObserver.observe(s));

  /* ── Active TOC Tracking ───────────────────────────────────── */
  const tocLinks = document.querySelectorAll('.rl-toc__item a');
  const sectionIds = Array.from(tocLinks).map((a) => a.getAttribute('href').slice(1));

  function updateActive() {
    let active = sectionIds[0];
    for (const id of sectionIds) {
      const el = document.getElementById(id);
      if (el && el.getBoundingClientRect().top <= 140) active = id;
    }
    tocLinks.forEach((a) => {
      a.classList.toggle('active', a.getAttribute('href') === '#' + active);
    });
  }

  /* ── Scroll Handler (throttled) ────────────────────────────── */
  let ticking = false;
  function onScroll() {
    if (!ticking) {
      requestAnimationFrame(() => {
        updateProgress();
        updateNav();
        updateActive();
        ticking = false;
      });
      ticking = true;
    }
  }
  window.addEventListener('scroll', onScroll, { passive: true });
  window.addEventListener('resize', onScroll, { passive: true });

  /* ── Mobile TOC Toggle ─────────────────────────────────────── */
  const tocToggle = document.querySelector('.rl-toc-toggle');
  const tocPanel = document.querySelector('.rl-toc');
  if (tocToggle && tocPanel) {
    tocToggle.addEventListener('click', () => {
      const open = tocPanel.classList.toggle('open');
      tocToggle.setAttribute('aria-expanded', String(open));
    });
    // Close TOC when a link is clicked on mobile
    tocLinks.forEach((a) => {
      a.addEventListener('click', () => tocPanel.classList.remove('open'));
    });
  }

  /* ── CTA Button Enable ─────────────────────────────────────── */
  const checkbox = document.getElementById('rlAccept');
  const ctaBtn = document.getElementById('rlCtaBtn');
  if (checkbox && ctaBtn) {
    ctaBtn.style.opacity = '0.5';
    ctaBtn.style.pointerEvents = 'none';
    checkbox.addEventListener('change', () => {
      const on = checkbox.checked;
      ctaBtn.style.opacity = on ? '1' : '0.5';
      ctaBtn.style.pointerEvents = on ? 'auto' : 'none';
    });
  }

  /* ── Init ──────────────────────────────────────────────────── */
  onScroll();
})();
