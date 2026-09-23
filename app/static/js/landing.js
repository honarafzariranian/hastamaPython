(() => {
  'use strict';

  /* ── Scroll reveal ─────────────────────────────────────── */
  const items = document.querySelectorAll('.reveal');
  if ('IntersectionObserver' in window) {
    const observer = new IntersectionObserver(
      (entries) => {
        entries.forEach((entry) => {
          if (entry.isIntersecting) {
            entry.target.classList.add('visible');
            observer.unobserve(entry.target);
          }
        });
      },
      { threshold: 0.12, rootMargin: '0px 0px -8% 0px' }
    );
    items.forEach((el) => observer.observe(el));
  } else {
    items.forEach((el) => el.classList.add('visible'));
  }

  /* ── Sticky nav glass on scroll ────────────────────────── */
  const nav = document.getElementById('siteNav');
  if (nav) {
    let ticking = false;
    const syncNav = () => {
      nav.classList.toggle('is-scrolled', window.scrollY > 8);
      ticking = false;
    };
    syncNav();
    window.addEventListener(
      'scroll',
      () => {
        if (!ticking) {
          ticking = true;
          window.requestAnimationFrame(syncNav);
        }
      },
      { passive: true }
    );
  }

  /* ── Mobile menu (class-driven — no inline styles) ─────── */
  const menuBtn = document.getElementById('menuBtn');
  const primaryNav = document.getElementById('primaryNav');

  if (menuBtn && primaryNav && nav) {
    const setOpen = (open) => {
      nav.classList.toggle('is-open', open);
      menuBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
      menuBtn.setAttribute('aria-label', open ? 'بستن منو' : 'باز کردن منو');
    };

    const isOpen = () => nav.classList.contains('is-open');

    menuBtn.addEventListener('click', () => setOpen(!isOpen()));

    primaryNav.querySelectorAll('a').forEach((link) => {
      link.addEventListener('click', () => setOpen(false));
    });

    document.addEventListener('keydown', (event) => {
      if (event.key === 'Escape' && isOpen()) {
        setOpen(false);
        menuBtn.focus();
      }
    });

    document.addEventListener('click', (event) => {
      if (!isOpen()) return;
      if (!nav.contains(event.target)) setOpen(false);
    });

    const mq = window.matchMedia('(min-width: 801px)');
    const onChange = (event) => {
      if (event.matches) setOpen(false);
    };
    if (mq.addEventListener) mq.addEventListener('change', onChange);
    else if (mq.addListener) mq.addListener(onChange);
  }
})();
