/**
 * Mafasa - Relatórios de Ativos (tela v2).
 * - Tipos: checkbox mestre (#mf-types-all) com estado indeterminado.
 * - Colunas: sincroniza input[name="cols[]"] e esconde th/td via data-col.
 * - Copiar link compartilha filtros + types[] + cols[] (URL do form).
 * - Link de impressão sincronizado com os mesmos parâmetros.
 * - Select2 nos selects de filtro (quando a lib está disponível).
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
    params.set('page', '1');
    return window.location.pathname + '?' + params.toString();
  }

  function syncPrintLink() {
    var print = document.getElementById('mf-print-link');
    if (!print) return;
    try {
      var shared = new URL(currentShareUrl(), window.location.href);
      var target = print.getAttribute('data-print') || 'print.php';
      print.setAttribute('href', target + '?' + shared.searchParams.toString());
    } catch (e) {
      /* mantém link original */
    }
  }

  function typesBoxes() {
    return Array.prototype.slice.call(document.querySelectorAll('input[name="types[]"]'));
  }

  function syncTypesMaster() {
    var master = document.getElementById('mf-types-all');
    if (!master) return;
    var boxes = typesBoxes();
    if (!boxes.length) {
      master.checked = false;
      master.indeterminate = false;
      return;
    }
    var checked = boxes.filter(function (b) { return b.checked; }).length;
    master.checked = (checked === boxes.length);
    master.indeterminate = (checked > 0 && checked < boxes.length);
  }

  function initSelect2() {
    if (!window.jQuery || !jQuery.fn || typeof jQuery.fn.select2 !== 'function') return;
    jQuery('#mf-form .mf-field select').each(function () {
      if (!jQuery(this).hasClass('select2-hidden-accessible')) {
        jQuery(this).select2({ width: '100%', allowClear: true });
      }
    });
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

    var master = document.getElementById('mf-types-all');
    if (master) {
      master.addEventListener('change', function () {
        typesBoxes().forEach(function (el) { el.checked = master.checked; });
        master.indeterminate = false;
      });
    }
    typesBoxes().forEach(function (el) {
      el.addEventListener('change', syncTypesMaster);
    });
    syncTypesMaster();

    var copy = document.getElementById('mf-copy-link');
    if (copy) copy.addEventListener('click', function () {
      var url = new URL(currentShareUrl(), window.location.href).toString();
      function ok() {
        var t = copy.textContent;
        copy.textContent = 'Copiado!';
        setTimeout(function () { copy.textContent = t; }, 1600);
      }
      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(url).then(ok, function () {
          window.prompt('Copie o link:', url);
        });
      } else {
        window.prompt('Copie o link:', url);
      }
    });

    initSelect2();
    applyVisibility();
  });
})();
