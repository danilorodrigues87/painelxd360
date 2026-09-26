/**
 * Tema claro/escuro dos painéis escola e Master.
 * Preferência em localStorage (chave xd360-theme).
 */
(function () {
  'use strict';

  var KEY = 'xd360-theme';

  function current() {
    try {
      var t = localStorage.getItem(KEY);
      return t === 'dark' ? 'dark' : 'light';
    } catch (e) {
      return 'light';
    }
  }

  function apply(theme) {
    var t = theme === 'dark' ? 'dark' : 'light';
    document.documentElement.setAttribute('data-bs-theme', t);
    document.querySelectorAll('.xd360-navbar').forEach(function (nav) {
      var dark = t === 'dark';
      nav.classList.toggle('navbar-dark', dark);
      nav.classList.toggle('navbar-light', !dark);
    });
    try {
      localStorage.setItem(KEY, t);
    } catch (e) {}
    syncToggleLabel(t);
    try {
      document.dispatchEvent(new CustomEvent('painel-theme-change', { detail: { theme: t } }));
    } catch (e) {}
  }

  function syncToggleLabel(theme) {
    var el = document.getElementById('btn-toggle-theme');
    if (!el) return;
    var dark = theme === 'dark';
    el.innerHTML = dark
      ? '<i class="fa-regular fa-sun me-1"></i> Tema claro'
      : '<i class="fa-regular fa-moon me-1"></i> Tema escuro';
  }

  function toggle() {
    apply(current() === 'dark' ? 'light' : 'dark');
  }

  document.addEventListener('DOMContentLoaded', function () {
    apply(current());
    var el = document.getElementById('btn-toggle-theme');
    if (el) {
      el.addEventListener('click', function (e) {
        e.preventDefault();
        toggle();
      });
    }
  });

  window.PainelTheme = { apply: apply, toggle: toggle, current: current };
})();
