/**
 * theme.js — Gestion du thème de couleur du dashboard.
 * 5 thèmes disponibles : blanc | noir | vert | bleu | rouge
 *
 * Préférence persistée :
 *   1. cookie "dash_theme" (1 an)
 *   2. localStorage en backup
 *
 * Application :
 *   <body data-theme="..."></body>
 */

(function () {
  'use strict';

  const THEMES = ['blanc', 'noir', 'vert', 'bleu', 'rouge'];
  const COOKIE_NAME = 'dash_theme';
  const STORAGE_KEY = 'dash_theme';
  const DEFAULT_THEME = 'blanc';

  // ----- Helpers cookie -----
  function setCookie(name, value, days) {
    const d = new Date();
    d.setTime(d.getTime() + days * 24 * 60 * 60 * 1000);
    document.cookie = `${name}=${encodeURIComponent(value)};expires=${d.toUTCString()};path=/;SameSite=Lax`;
  }

  function getCookie(name) {
    const cookies = document.cookie.split(';');
    for (const c of cookies) {
      const [k, v] = c.trim().split('=');
      if (k === name) return decodeURIComponent(v || '');
    }
    return null;
  }

  // ----- Get / Set du thème -----
  function getStoredTheme() {
    let t = getCookie(COOKIE_NAME);
    if (!t) {
      try { t = localStorage.getItem(STORAGE_KEY); } catch (e) { /* ignore */ }
    }
    return THEMES.includes(t) ? t : DEFAULT_THEME;
  }

  function applyTheme(theme) {
    if (!THEMES.includes(theme)) theme = DEFAULT_THEME;
    document.body.setAttribute('data-theme', theme);

    setCookie(COOKIE_NAME, theme, 365);
    try { localStorage.setItem(STORAGE_KEY, theme); } catch (e) { /* ignore */ }

    // Marquer l'option active dans le menu
    document.querySelectorAll('.theme-option').forEach(el => {
      el.classList.toggle('active', el.dataset.themeColor === theme);
    });
  }

  // ----- Construction du widget -----
  function buildSwitcher() {
    const slot = document.getElementById('theme-switcher-slot');
    if (!slot) return;

    const labels = {
      blanc: 'Clair',
      noir:  'Sombre',
      vert:  'Forêt',
      bleu:  'Océan',
      rouge: 'Rouge',
    };

    slot.innerHTML = `
      <div class="theme-switcher" id="theme-switcher">
        <button type="button" class="theme-switcher-btn" id="theme-toggle" aria-label="Changer le thème">
          <i class="fas fa-palette"></i>
        </button>
        <div class="theme-switcher-menu">
          <div class="theme-switcher-title">Couleur du tableau de bord</div>
          <div class="theme-grid-with-labels">
            ${THEMES.map(t => `
              <div class="theme-cell">
                <button class="theme-option" data-theme-color="${t}" aria-label="Thème ${labels[t]}"></button>
                <span class="theme-option-label">${labels[t]}</span>
              </div>
            `).join('')}
          </div>
        </div>
      </div>
    `;

    const wrap = document.getElementById('theme-switcher');
    const btn  = document.getElementById('theme-toggle');

    btn.addEventListener('click', e => {
      e.stopPropagation();
      wrap.classList.toggle('open');
    });

    document.addEventListener('click', e => {
      if (!wrap.contains(e.target)) wrap.classList.remove('open');
    });

    slot.querySelectorAll('.theme-option').forEach(opt => {
      opt.addEventListener('click', () => {
        applyTheme(opt.dataset.themeColor);
        wrap.classList.remove('open');
      });
    });
  }

  // ----- Init -----
  // 1) Appliquer immédiatement le thème stocké, avant que la page n'apparaisse,
  //    pour éviter un flash.
  function preApply() {
    if (document.body) {
      const t = getStoredTheme();
      document.body.setAttribute('data-theme', t);
    }
  }
  preApply();

  document.addEventListener('DOMContentLoaded', () => {
    applyTheme(getStoredTheme());
    buildSwitcher();
  });
})();
