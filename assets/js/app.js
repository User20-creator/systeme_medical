/* ============================================================
   SYSTÈME MÉDICAL — JS commun (design system)
   Animations : reveal-on-scroll, tilt 3D, counters, spotlight,
                nav scroll, sidebar toggle, toast notifications
   ============================================================ */

(function(){
  'use strict';

  /* ===== REVEAL ON SCROLL ===== */
  const observer = new IntersectionObserver((entries) => {
    entries.forEach(entry => {
      if (entry.isIntersecting) {
        const el = entry.target;
        const delay = parseInt(el.dataset.delay || 0);
        setTimeout(() => el.classList.add('in'), delay);
        observer.unobserve(el);
      }
    });
  }, { threshold: 0.12, rootMargin: '0px 0px -60px 0px' });

  function bindReveal() {
    document.querySelectorAll('.reveal-up, .reveal-fade, .reveal-scale').forEach(el => {
      if (!el.classList.contains('in')) observer.observe(el);
    });
  }

  /* ===== 3D TILT ===== */
  function bindTilt() {
    document.querySelectorAll('.tilt').forEach(card => {
      if (card.dataset.tiltBound) return;
      card.dataset.tiltBound = '1';
      const max = parseFloat(card.dataset.tiltMax || 8);

      card.addEventListener('mousemove', e => {
        const r = card.getBoundingClientRect();
        const x = (e.clientX - r.left) / r.width - 0.5;
        const y = (e.clientY - r.top) / r.height - 0.5;
        card.style.transform = `perspective(1000px) rotateX(${-y * max}deg) rotateY(${x * max}deg) translateZ(0)`;
      });
      card.addEventListener('mouseleave', () => card.style.transform = '');
    });
  }

  /* ===== COUNTER / NUMBER TICKER ===== */
  function animateCounter(el) {
    const target = parseFloat(el.dataset.count);
    const dur = parseInt(el.dataset.dur || 1800);
    const decimals = parseInt(el.dataset.decimals || 0);
    const prefix = el.dataset.prefix || '';
    const suffix = el.dataset.suffix || '';
    const start = performance.now();

    function step(t) {
      const p = Math.min((t - start) / dur, 1);
      const eased = 1 - Math.pow(1 - p, 3);
      const val = target * eased;
      el.textContent = prefix + val.toLocaleString('fr-FR', {
        minimumFractionDigits: decimals, maximumFractionDigits: decimals
      }) + suffix;
      if (p < 1) requestAnimationFrame(step);
    }
    requestAnimationFrame(step);
  }

  const counterObserver = new IntersectionObserver((entries) => {
    entries.forEach(entry => {
      if (entry.isIntersecting) {
        animateCounter(entry.target);
        counterObserver.unobserve(entry.target);
      }
    });
  }, { threshold: 0.4 });

  function bindCounters() {
    document.querySelectorAll('[data-count]').forEach(el => counterObserver.observe(el));
  }

  /* ===== SPOTLIGHT (follows mouse) ===== */
  function bindSpotlight() {
    document.querySelectorAll('.spotlight-host').forEach(host => {
      if (host.dataset.spotBound) return;
      host.dataset.spotBound = '1';
      host.addEventListener('mousemove', e => {
        const r = host.getBoundingClientRect();
        host.style.setProperty('--spot-x', (e.clientX - r.left) + 'px');
        host.style.setProperty('--spot-y', (e.clientY - r.top) + 'px');
      });
    });
  }

  /* ===== PUBLIC NAV SCROLL EFFECT ===== */
  function bindNavScroll() {
    const nav = document.querySelector('.site-nav');
    if (!nav) return;
    const onScroll = () => nav.classList.toggle('scrolled', window.scrollY > 20);
    onScroll();
    window.addEventListener('scroll', onScroll, { passive: true });
  }

  /* ===== SIDEBAR TOGGLE (mobile) ===== */
  function bindSidebar() {
    const sidebar = document.querySelector('.sidebar');
    const toggle = document.querySelector('.topbar-toggle');
    if (!sidebar || !toggle) return;

    let backdrop = document.querySelector('.sidebar-backdrop');
    if (!backdrop) {
      backdrop = document.createElement('div');
      backdrop.className = 'sidebar-backdrop';
      document.body.appendChild(backdrop);
    }

    const close = () => {
      sidebar.classList.remove('open');
      backdrop.classList.remove('show');
      document.body.style.overflow = '';
    };
    const open = () => {
      sidebar.classList.add('open');
      backdrop.classList.add('show');
      document.body.style.overflow = 'hidden';
    };

    toggle.addEventListener('click', () => {
      sidebar.classList.contains('open') ? close() : open();
    });
    backdrop.addEventListener('click', close);
    window.addEventListener('resize', () => { if (window.innerWidth > 900) close(); });
  }

  /* ===== TOAST NOTIFICATIONS ===== */
  window.toast = function(message, type = 'info', duration = 4000) {
    let stack = document.getElementById('toast-stack');
    if (!stack) {
      stack = document.createElement('div');
      stack.id = 'toast-stack';
      stack.style.cssText = 'position:fixed;top:24px;right:24px;z-index:9999;display:flex;flex-direction:column;gap:10px;pointer-events:none;';
      document.body.appendChild(stack);
    }
    const icons = {
      success: '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>',
      danger: '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>',
      warning: '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>',
      info: '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>',
    };
    const colors = {
      success: { bg: '#f0fdf4', bd: '#86efac', tx: '#166534' },
      danger:  { bg: '#fef2f2', bd: '#fca5a5', tx: '#991b1b' },
      warning: { bg: '#fffbeb', bd: '#fde68a', tx: '#92400e' },
      info:    { bg: '#eff6ff', bd: '#bfdbfe', tx: '#1e40af' },
    };
    const c = colors[type] || colors.info;
    const t = document.createElement('div');
    t.style.cssText = `display:flex;align-items:flex-start;gap:10px;padding:14px 18px;border-radius:12px;background:${c.bg};border:1px solid ${c.bd};color:${c.tx};font-size:.9rem;font-weight:500;box-shadow:0 10px 30px rgba(15,23,42,.12);min-width:260px;max-width:380px;pointer-events:auto;transform:translateX(400px);opacity:0;transition:transform .35s cubic-bezier(.16,1,.3,1),opacity .35s;`;
    t.innerHTML = `${icons[type] || icons.info}<div style="flex:1;line-height:1.45;">${message}</div>`;
    stack.appendChild(t);
    requestAnimationFrame(() => { t.style.transform = 'translateX(0)'; t.style.opacity = '1'; });
    setTimeout(() => {
      t.style.transform = 'translateX(400px)'; t.style.opacity = '0';
      setTimeout(() => t.remove(), 400);
    }, duration);
  };

  /* ===== COPY-TO-CLIPBOARD (for hashes) ===== */
  function bindCopyHash() {
    document.querySelectorAll('[data-copy]').forEach(el => {
      if (el.dataset.copyBound) return;
      el.dataset.copyBound = '1';
      el.style.cursor = 'pointer';
      el.addEventListener('click', () => {
        const txt = el.dataset.copy || el.textContent.trim();
        navigator.clipboard.writeText(txt).then(() => {
          if (window.toast) window.toast('Hash copié dans le presse-papier', 'success', 2500);
        });
      });
    });
  }

  /* ===== PASSWORD TOGGLE ===== */
  function bindPasswordToggle() {
    document.querySelectorAll('[data-pw-toggle]').forEach(btn => {
      if (btn.dataset.pwBound) return;
      btn.dataset.pwBound = '1';
      // Le data-pw-toggle peut être soit un ID nu (ex "pwInput"), soit un sélecteur ("#pwInput", ".cls")
      const ref = btn.dataset.pwToggle || '';
      let target = null;
      if (ref) {
        if (ref.startsWith('#') || ref.startsWith('.') || ref.startsWith('[')) {
          target = document.querySelector(ref);
        } else {
          target = document.getElementById(ref) || document.querySelector(ref);
        }
      }
      if (!target) return;
      btn.addEventListener('click', () => {
        const isPw = target.type === 'password';
        target.type = isPw ? 'text' : 'password';
        // L'icône peut être l'élément <i> à l'intérieur du bouton OU un SVG ; on remplace tout le contenu.
        btn.innerHTML = isPw
          ? '<i class="fas fa-eye-slash"></i>'
          : '<i class="fas fa-eye"></i>';
      });
    });
  }

  /* ===== INIT ===== */
  function init() {
    bindReveal();
    bindTilt();
    bindCounters();
    bindSpotlight();
    bindNavScroll();
    bindSidebar();
    bindCopyHash();
    bindPasswordToggle();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }

  // Expose for dynamic content
  window.appUI = { bindReveal, bindTilt, bindCounters, bindSpotlight, bindCopyHash, bindPasswordToggle };
})();
