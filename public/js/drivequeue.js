/**
 * KB Wizard - processamento incremental da fila do Google Drive.
 *
 * O botão "Processar fila agora" é interceptado e vira um loop AJAX: cada
 * requisição processa até 10 artigos / 20s e devolve quantos restaram. A tela
 * mostra o progresso e avisa para não fechar a aba enquanto o loop roda.
 *
 * Sem JavaScript o botão continua funcionando como submit normal (fallback).
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

    onReady(function () {
        var button = document.querySelector('[data-kbw-process]');
        var panel = document.querySelector('[data-kbw-progress]');
        if (!button || !panel) {
            return;
        }
        // Navegador antigo sem fetch: deixa o botão fazer o POST normal.
        if (typeof window.fetch !== 'function' || typeof window.URLSearchParams !== 'function') {
            return;
        }

        var endpoint = panel.getAttribute('data-endpoint') || '';
        var form = button.closest('form');
        var tokenInput = form ? form.querySelector('input[name="_glpi_csrf_token"]') : null;
        var tokenInputs = document.querySelectorAll('input[name="_glpi_csrf_token"]');
        var bar = panel.querySelector('#kbw-drive-progress-bar');
        var barWrap = bar ? bar.parentNode : null;
        var title = panel.querySelector('#kbw-drive-progress-title');
        var count = panel.querySelector('#kbw-drive-progress-count');
        var syncedEl = panel.querySelector('#kbw-drive-synced');
        var failedEl = panel.querySelector('#kbw-drive-failed');
        var skippedEl = panel.querySelector('#kbw-drive-skipped');
        var remainingEl = panel.querySelector('#kbw-drive-remaining');
        var summary = panel.querySelector('#kbw-drive-summary');
        var cancelBtn = panel.querySelector('#kbw-drive-cancel');
        var reloadBtn = panel.querySelector('#kbw-drive-reload');
        var originalLabel = button.innerHTML;

        var running = false;
        var cancelled = false;
        var csrf = tokenInput ? tokenInput.value : '';
        var total = parseInt(panel.getAttribute('data-pending') || '0', 10) || 0;
        var done = 0;
        var synced = 0;
        var failed = 0;
        var skipped = 0;
        var remaining = total;
        var lastRemaining = total;
        var stalls = 0;
        var busyRetries = 0;
        var MAX_BUSY_RETRIES = 60; // 60 x 1,5s = 90s esperando o lock
        var MAX_CHUNKS = 2000;     // trava de segurança contra loop infinito
        var chunks = 0;

        function setText(el, value) {
            if (el) {
                el.textContent = String(value);
            }
        }

        function setBar(percent) {
            if (!bar) {
                return;
            }
            percent = Math.max(0, Math.min(100, Math.round(percent)));
            bar.style.width = percent + '%';
            bar.textContent = percent + '%';
            if (barWrap) {
                barWrap.setAttribute('aria-valuenow', String(percent));
            }
        }

        function render() {
            setText(count, done + ' de ' + total);
            setText(syncedEl, synced);
            setText(failedEl, failed);
            setText(skippedEl, skipped);
            setText(remainingEl, remaining);
            setBar(total > 0 ? (done * 100) / total : 100);
        }

        function setStatus(text) {
            setText(title, text);
        }

        function setRunning(value) {
            running = value;
            button.disabled = value;
            if (value) {
                button.innerHTML = '<i class="ti ti-loader me-1"></i>Processando...';
            } else {
                button.innerHTML = originalLabel;
            }
        }

        function onBeforeUnload(event) {
            if (!running) {
                return undefined;
            }
            var message = 'O processamento da fila do Google Drive ainda está em andamento. Não feche esta aba.';
            event.preventDefault();
            event.returnValue = message;
            return message;
        }

        function finish(kind, message) {
            running = false;
            window.removeEventListener('beforeunload', onBeforeUnload);
            setRunning(false);

            if (cancelBtn) {
                cancelBtn.classList.add('d-none');
                cancelBtn.disabled = false;
            }
            if (reloadBtn) {
                reloadBtn.classList.remove('d-none');
            }
            if (bar) {
                bar.classList.remove('bg-success', 'progress-bar-animated', 'progress-bar-striped');
                bar.classList.add('bg-danger');
            }

            if (kind === 'done') {
                setStatus('Fila processada');
                if (remaining === 0) {
                    done = total;
                    render();
                }
                summary.className = 'mt-2 small ' + (failed > 0 ? 'text-warning' : 'text-success');
                if (total === 0) {
                    summary.textContent = 'Nenhum artigo pendente na fila.';
                } else {
                    summary.textContent = 'Concluído: ' + synced + ' sincronizado(s), '
                        + failed + ' com erro, ' + skipped + ' ignorado(s).';
                }
                total = 0;
                panel.setAttribute('data-pending', '0');
            } else if (kind === 'cancelled') {
                setStatus('Processamento cancelado');
                summary.className = 'mt-2 small text-warning';
                summary.textContent = 'O lote atual foi concluído. Restam ' + remaining
                    + ' artigo(s) na fila.';
            } else {
                setStatus('Processamento interrompido');
                if (bar) {
                    bar.classList.add('bg-danger');
                }
                summary.className = 'mt-2 small text-danger';
                summary.textContent = message || 'Não foi possível concluir o processamento.';
            }
        }

        function reset() {
            cancelled = false;
            done = 0;
            synced = 0;
            failed = 0;
            skipped = 0;
            busyRetries = 0;
            stalls = 0;
            chunks = 0;
            lastRemaining = total;
            remaining = total;
            if (summary) {
                summary.textContent = '';
                summary.className = 'mt-2 small';
            }
            if (bar) {
                bar.classList.remove('bg-danger');
                bar.classList.add('bg-success', 'progress-bar-striped', 'progress-bar-animated');
            }
            if (cancelBtn) {
                cancelBtn.classList.remove('d-none');
                cancelBtn.disabled = false;
            }
            if (reloadBtn) {
                reloadBtn.classList.add('d-none');
            }
        }

        function show() {
            panel.classList.remove('d-none');
            if (panel.scrollIntoView) {
                panel.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            }
        }

        function update(payload) {
            var processed = parseInt(payload.processed || '0', 10) || 0;
            var chunkSynced = parseInt(payload.synced || '0', 10) || 0;
            var chunkFailed = parseInt(payload.failed || '0', 10) || 0;
            var chunkSkipped = parseInt(payload.skipped || '0', 10) || 0;

            done += processed;
            synced += chunkSynced;
            failed += chunkFailed;
            skipped += chunkSkipped;
            remaining = Math.max(0, parseInt(payload.remaining || '0', 10) || 0);

            // Novos artigos podem entrar na fila durante o loop (hooks de
            // artigo/passo). Nesse caso o total cresce para a barra não
            // regredir nem passar de 100%.
            if (done + remaining > total) {
                total = done + remaining;
            }
            // O cron pode ter processado itens entre os lotes; usa o maior
            // avanço entre o contador local e o restante informado.
            if (total - remaining > done) {
                done = total - remaining;
            }

            render();
            return processed;
        }

        function chunk() {
            if (cancelled) {
                finish('cancelled');
                return;
            }

            chunks += 1;
            if (chunks > MAX_CHUNKS) {
                finish('error', 'O processamento atingiu o limite de lotes. Atualize a página e verifique a fila.');
                return;
            }

            var body = new URLSearchParams();
            body.set('process_chunk', '1');
            body.set('_glpi_csrf_token', csrf);

            var headers = { 'X-Requested-With': 'XMLHttpRequest' };
            if (csrf) {
                headers['X-Glpi-Csrf-Token'] = csrf;
            }

            fetch(endpoint, {
                method: 'POST',
                credentials: 'same-origin',
                headers: headers,
                body: body
            }).then(function (response) {
                var type = response.headers ? (response.headers.get('content-type') || '') : '';
                if (type.indexOf('application/json') === -1) {
                    throw new Error('A sessão expirou ou o servidor retornou uma página. Atualize a página e tente novamente.');
                }
                return response.json().catch(function () {
                    throw new Error('Resposta inválida do servidor. Atualize a página e tente novamente.');
                }).then(function (payload) {
                    return { response: response, payload: payload };
                });
            }).then(function (result) {
                var payload = result.payload || {};

                if (payload.csrf) {
                    csrf = payload.csrf;
                    for (var i = 0; i < tokenInputs.length; i++) {
                        tokenInputs[i].value = payload.csrf;
                    }
                }

                if (!result.response.ok || !payload.ok) {
                    throw new Error(payload.message || ('O servidor respondeu HTTP ' + result.response.status + '.'));
                }

                if (payload.busy) {
                    busyRetries += 1;
                    if (busyRetries > MAX_BUSY_RETRIES) {
                        finish('error', 'A fila continua bloqueada por outro processo. Tente novamente em alguns instantes.');
                        return;
                    }
                    setStatus('Aguardando outro processamento terminar...');
                    window.setTimeout(chunk, 1500);
                    return;
                }
                busyRetries = 0;

                var processed = update(payload);

                if (cancelled) {
                    finish('cancelled');
                    return;
                }
                if (remaining <= 0) {
                    finish('done');
                    return;
                }
                if (processed === 0 && remaining === lastRemaining) {
                    stalls += 1;
                    if (stalls >= 2) {
                        finish('error', 'A fila não avançou. Verifique o último erro e tente novamente.');
                        return;
                    }
                } else {
                    stalls = 0;
                }
                lastRemaining = remaining;
                setStatus('Processando fila...');
                window.setTimeout(chunk, 300);
            }).catch(function (error) {
                if (cancelled) {
                    finish('cancelled');
                    return;
                }
                finish('error', error && error.message ? error.message : 'Falha de comunicação com o servidor.');
            });
        }

        function start() {
            if (running) {
                return;
            }

            var configured = panel.getAttribute('data-configured') === '1';
            reset();
            show();

            if (!configured) {
                finish('error', 'Ative a sincronização e conecte a conta Google antes de processar a fila.');
                return;
            }
            if (!endpoint) {
                finish('error', 'Endereço de processamento não configurado. Atualize a página.');
                return;
            }

            setRunning(true);
            setStatus('Processando fila...');
            render();
            window.addEventListener('beforeunload', onBeforeUnload);
            chunk();
        }

        button.addEventListener('click', function (event) {
            event.preventDefault();
            start();
        });

        if (cancelBtn) {
            cancelBtn.addEventListener('click', function () {
                if (!running) {
                    return;
                }
                cancelled = true;
                cancelBtn.disabled = true;
                setStatus('Cancelando após o lote atual...');
            });
        }

        if (reloadBtn) {
            reloadBtn.addEventListener('click', function () {
                window.location.reload();
            });
        }
    });
})();
