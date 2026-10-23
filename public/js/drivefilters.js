/**
 * KB Wizard - filtros da tabela "Status por artigo".
 *
 * Filtra no navegador as linhas já carregadas (até 200 registros), sem
 * recarregar a página. As linhas ocultas continuam no formulário, então
 * "Salvar seleção" preserva o estado dos checkboxes.
 */
(function () {
    'use strict';

    function onReady(fn) {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', fn);
        } else {
            fn();
        }
    }

    function normalize(value) {
        var text = String(value || '').toLowerCase();
        if (typeof text.normalize === 'function') {
            text = text.normalize('NFD').replace(/[\u0300-\u036f]/g, '');
        }
        return text;
    }

    onReady(function () {
        var scope = document.querySelector('#kbwizard-drive-config');
        if (!scope) {
            return;
        }

        var controls = scope.querySelectorAll('[data-kbw-filter]');
        var rows = scope.querySelectorAll('[data-kbw-row]');
        if (!controls.length || !rows.length) {
            return;
        }

        var ui = scope.querySelectorAll('[data-kbw-filter-ui]');
        var empty = scope.querySelector('[data-kbw-empty]');
        var count = scope.querySelector('[data-kbw-filter-count]');
        var clear = scope.querySelector('[data-kbw-filter-clear]');
        var searchInput = scope.querySelector('[data-kbw-filter="search"]');
        var total = rows.length;

        function filterValue(name) {
            var control = scope.querySelector('[data-kbw-filter="' + name + '"]');
            return control ? control.value : '';
        }

        function apply() {
            var search = normalize(filterValue('search').trim());
            var sync = filterValue('sync');
            var status = filterValue('status');
            var doc = filterValue('doc');
            var pdf = filterValue('pdf');
            var visible = 0;

            for (var i = 0; i < rows.length; i++) {
                var row = rows[i];
                var matches = true;

                if (search !== '' && normalize(row.getAttribute('data-kbw-name')).indexOf(search) === -1) {
                    matches = false;
                }
                if (matches && sync !== '' && row.getAttribute('data-kbw-sync') !== sync) {
                    matches = false;
                }
                if (matches && status !== '' && row.getAttribute('data-kbw-status') !== status) {
                    matches = false;
                }
                if (matches && doc !== '' && row.getAttribute('data-kbw-doc') !== doc) {
                    matches = false;
                }
                if (matches && pdf !== '' && row.getAttribute('data-kbw-pdf') !== pdf) {
                    matches = false;
                }

                row.classList.toggle('d-none', !matches);
                if (matches) {
                    visible += 1;
                }
            }

            if (empty) {
                empty.classList.toggle('d-none', visible > 0);
            }
            if (count) {
                count.textContent = visible + ' de ' + total + (total === 1 ? ' artigo' : ' artigos');
            }
        }

        for (var i = 0; i < controls.length; i++) {
            controls[i].addEventListener('input', apply);
            controls[i].addEventListener('change', apply);
        }

        if (searchInput) {
            searchInput.addEventListener('keydown', function (event) {
                // A busca fica dentro do formulário de "Salvar seleção";
                // Enter não deve submeter o formulário.
                if (event.key === 'Enter' || event.keyCode === 13) {
                    event.preventDefault();
                }
            });
        }

        if (clear) {
            clear.addEventListener('click', function () {
                for (var j = 0; j < controls.length; j++) {
                    controls[j].value = '';
                }
                apply();
                if (searchInput) {
                    searchInput.focus();
                }
            });
        }

        // Só revela os filtros quando o JavaScript está ativo. Sem JS, a
        // tabela continua exatamente como antes.
        for (var k = 0; k < ui.length; k++) {
            ui[k].classList.remove('d-none');
        }
        apply();
    });
})();
