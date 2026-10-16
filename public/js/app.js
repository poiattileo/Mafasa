/**
 * Mafasa - Relatórios de Ativos (tela).
 * - Desmarcar coluna esconde th/td via data-col na hora.
 * - Botões marcar tudo / limpar / padrão.
 * - Copiar link compartilha filtros + cols[] (URL atual do form).
 */
(function () {
  'use strict';

  function checkedCols() {
    return Array.prototype.slice
      .call(document.querySelectorAll('input[name="cols[]"]:checked'))
      .map(function (el) { return el.getAttribute('data-col'); })
      .filter(Boolean);
  }

  function applyVisibility() {
    var checked = checkedCols();
    document.querySelectorAll('#mf-table [data-col]').forEach(function (cell) {
      var col = cell.getAttribute('data-col');
      cell.style.display = checked.indexOf(col) === -1 ? 'none' : '';
    });
    syncPrintLink();
  }

  function setAll(on, defaults) {
    document.querySelectorAll('input[name="cols[]"]').forEach(function (el) {
      var col = el.getAttribute('data-col');
      if (defaults) {
        el.checked = defaults.indexOf(col) !== -1;
      } else {
        el.checked = !!on;
      }
    });
    applyVisibility();
  }

  function currentShareUrl() {
    var form = document.getElementById('mf-form');
    if (!form) return window.location.href;
    var params = new URLSearchParams(new FormData(form));
    // Troca page por 1 no link compartilhado (começa do início).
    params.set('page', '1');
    if (!params.get('gerar')) params.set('gerar', '1');
    return window.location.pathname + '?' + params.toString();
  }

  function syncPrintLink() {
    var print = document.getElementById('mf-print-link');
    if (!print) return;
    try {
      var url = new URL(print.getAttribute('href'), window.location.href);
      var shared = new URL(currentShareUrl(), window.location.href);
      url.search = shared.searchParams.toString().replace(/^/, '?');
      // print.php recebe os mesmos params da tela
      print.setAttribute('href', 'print.php' + url.search);
    } catch (e) {
      /* mantém link original */
    }
  }

  document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('input[name="cols[]"]').forEach(function (el) {
      el.addEventListener('change', applyVisibility);
    });

    var all = document.getElementById('mf-cols-all');
    if (all) all.addEventListener('click', function () { setAll(true); });
    var none = document.getElementById('mf-cols-none');
    if (none) none.addEventListener('click', function () { setAll(false); });
    var def = document.getElementById('mf-cols-default');
    if (def) def.addEventListener('click', function () {
      var raw = def.getAttribute('data-default') || '';
      var list = raw.split(',').map(function (s) { return s.trim(); }).filter(Boolean);
      setAll(false, list);
    });

    var copy = document.getElementById('mf-copy-link');
    if (copy) copy.addEventListener('click', function () {
      var url = currentShareUrl();
      function ok() {
        var t = copy.textContent;
        copy.textContent = 'Copiado!';
        setTimeout(function () { copy.textContent = t; }, 1600);
      }
      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(new URL(url, window.location.href).toString()).then(ok, function () {
          window.prompt('Copie o link:', new URL(url, window.location.href).toString());
        });
      } else {
        window.prompt('Copie o link:', new URL(url, window.location.href).toString());
      }
    });

    applyVisibility();
  });
})();
