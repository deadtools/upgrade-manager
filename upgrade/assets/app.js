(function () {
    'use strict';

    var csrf = window.UME && window.UME.csrf ? window.UME.csrf : '';
    var currentJob = null;
    var timer = null;
    var startedAt = 0;
    var terminalHandled = false;
    var previousFocus = null;
    var modalOpen = false;

    function byId(id) {
        return document.getElementById(id);
    }

    function all(selector, root) {
        return Array.prototype.slice.call((root || document).querySelectorAll(selector));
    }

    function enc(data) {
        var form = new FormData();
        Object.keys(data || {}).forEach(function (key) { form.append(key, data[key]); });
        form.append('csrf', csrf);
        return form;
    }

    function api(action, data, file, fileField) {
        var body = enc(data || {});
        if (file) body.append(fileField || 'patch', file);
        return fetch('?action=' + encodeURIComponent(action), {
            method: 'POST',
            credentials: 'same-origin',
            body: body,
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
        }).then(function (response) {
            return response.text().then(function (text) {
                var json;
                try { json = JSON.parse(text); }
                catch (error) { throw new Error('HTTP ' + response.status + ': ' + text.slice(0, 500)); }
                if (!response.ok || !json.ok) throw new Error(json.error || ('HTTP ' + response.status));
                return json;
            });
        });
    }

    function notice(message, type, persistent) {
        var node = byId('notice');
        if (!node) return;
        node.className = 'notice ' + (type || 'ok');
        node.textContent = message;
        if (!persistent) {
            window.setTimeout(function () {
                if (node.textContent === message) {
                    node.className = '';
                    node.textContent = '';
                }
            }, 7000);
        }
    }

    function copyText(text) {
        var value = String(text == null ? '' : text);
        if (navigator.clipboard && navigator.clipboard.writeText) return navigator.clipboard.writeText(value);
        return new Promise(function (resolve, reject) {
            var area = document.createElement('textarea');
            area.value = value;
            area.setAttribute('readonly', 'readonly');
            area.style.position = 'fixed';
            area.style.left = '-9999px';
            area.style.top = '0';
            document.body.appendChild(area);
            area.focus();
            area.select();
            var ok = false;
            try { ok = document.execCommand('copy'); } catch (error) { ok = false; }
            document.body.removeChild(area);
            if (ok) resolve();
            else reject(new Error('Не удалось скопировать URL в буфер обмена.'));
        });
    }

    function noticeAction(message, type, label, handler) {
        var node = byId('notice');
        if (!node) return;
        node.className = 'notice ' + (type || 'warning') + ' with-action';
        node.textContent = '';
        var text = document.createElement('span');
        text.className = 'notice-message';
        text.textContent = message;
        var button = document.createElement('button');
        button.type = 'button';
        button.className = 'button ghost small notice-action';
        button.textContent = label;
        button.addEventListener('click', function () { handler(button); });
        node.appendChild(text);
        node.appendChild(button);
    }

    function saveFlash(message, type) {
        try { window.sessionStorage.setItem('ume-result', JSON.stringify({ message: message, type: type || 'ok' })); }
        catch (error) { /* Storage can be unavailable in hardened browsers. */ }
    }

    function restoreFlash() {
        try {
            var raw = window.sessionStorage.getItem('ume-result');
            if (!raw) return;
            window.sessionStorage.removeItem('ume-result');
            var data = JSON.parse(raw);
            if (data && data.message) notice(data.message, data.type || 'ok', true);
        } catch (error) { /* Ignore malformed or unavailable storage. */ }
    }

    function copyMigrationLink(jobId, button) {
        if (button) { button.disabled = true; button.textContent = 'Получаем…'; }
        return api('migration_export_link', { job_id: jobId }).then(function (result) {
            if (!result.link || !result.link.url) throw new Error('Ссылка миграции недоступна.');
            return copyText(result.link.url).then(function () {
                if (button) button.textContent = 'Скопировано';
                return result.link;
            });
        }).catch(function (error) {
            if (button) { button.disabled = false; button.textContent = 'Копировать ссылку'; }
            throw error;
        });
    }

    function restoreMigrationResult() {
        try {
            var raw = window.sessionStorage.getItem('ume-migration-result');
            if (!raw) return;
            window.sessionStorage.removeItem('ume-migration-result');
            var data = JSON.parse(raw);
            if (!data || !data.job_id) return;
            noticeAction('Ссылка миграции готова' + (data.expires ? ' · действует до ' + data.expires : '') + '. Она хранится зашифрованно на сервере и останется доступна после reload/повторного входа, пока не истечёт или не будет использована.', 'ok', 'Копировать ссылку', function (button) {
                copyMigrationLink(data.job_id, button).catch(function (error) { notice(error.message, 'error', true); });
            });
        } catch (error) { /* Ignore unavailable storage. */ }
    }

    function fmt(bytes) {
        var units = ['Б', 'КБ', 'МБ', 'ГБ'];
        var index = 0;
        var value = Number(bytes || 0);
        while (value >= 1024 && index < units.length - 1) { value /= 1024; index++; }
        return value.toFixed(index ? 2 : 0) + ' ' + units[index];
    }

    function date(value) {
        var parsed = Number(value);
        if (!parsed) return String(value || '—');
        return new Date(parsed * 1000).toLocaleString();
    }

    function esc(value) {
        return String(value == null ? '' : value).replace(/[&<>"']/g, function (character) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[character];
        });
    }

    function typeLabel(type) {
        return type === 'backup' ? 'Резервная копия' : type === 'backup_restore' ? 'Восстановление бэкапа' : type === 'install' ? 'Установка патча' : type === 'rollback' ? 'Откат патча' : type === 'migration_export' ? 'Подготовка переноса проекта' : type === 'migration_import' ? 'Перенос проекта' : type;
    }

    function statusLabel(status) {
        var labels = {
            running: 'Выполняется', stale: 'Ожидает продолжения', cancelling: 'Прерывается', rolling_back: 'Безопасный откат',
            completed: 'Завершён', failed: 'Ошибка', cancelled: 'Прерван', installed: 'Установлен', rolled_back: 'Отменён откатом',
            planned: 'ТЗ принято', in_progress: 'В работе', deployed: 'Установлено', no_changes: 'Без изменений'
        };
        return labels[status] || status || '—';
    }

    function statusClass(status) {
        if (status === 'completed' || status === 'installed' || status === 'deployed' || status === 'no_changes') return 'success';
        if (status === 'running' || status === 'rolling_back' || status === 'cancelling' || status === 'stale' || status === 'in_progress') return 'warning';
        if (status === 'failed') return 'error';
        return 'neutral';
    }

    function terminalMessage(job) {
        if (job.status === 'failed') {
            return job.type === 'install' ? 'Патч не применён: ' + (job.message || 'произошла ошибка') : typeLabel(job.type) + ' — ошибка: ' + (job.message || 'операция не выполнена');
        }
        if (job.status === 'cancelled') return 'Процесс прерван. Безопасное состояние сайта сохранено.';
        if (job.type === 'install') return 'Патч применён. Страница и данные Upgrade Manager обновлены.';
        if (job.type === 'backup') return 'Резервная копия успешно создана.';
        if (job.type === 'backup_restore') return 'Резервная копия восстановлена. Файлы и сохранённая часть базы данных возвращены к снимку.';
        if (job.type === 'rollback') return 'Откат выполнен. Файлы и база данных восстановлены.';
        if (job.type === 'migration_export') return 'Ссылка для прямого переноса проекта подготовлена.';
        if (job.type === 'migration_import') return 'Проект полностью перенесён на новый сайт.';
        return job.message || 'Операция завершена.';
    }

    function setProcessModal(open) {
        var box = byId('process');
        var shell = document.querySelector('.admin-shell');
        if (!box) return;
        if (open) {
            if (!modalOpen) previousFocus = document.activeElement;
            modalOpen = true;
            box.classList.remove('hidden');
            box.setAttribute('aria-hidden', 'false');
            document.body.classList.add('process-modal-open');
            if (shell) {
                shell.setAttribute('inert', '');
                shell.setAttribute('aria-hidden', 'true');
            }
        } else {
            modalOpen = false;
            box.classList.add('hidden');
            box.setAttribute('aria-hidden', 'true');
            document.body.classList.remove('process-modal-open');
            if (shell) {
                shell.removeAttribute('inert');
                shell.removeAttribute('aria-hidden');
            }
            if (previousFocus && typeof previousFocus.focus === 'function') {
                try { previousFocus.focus(); } catch (error) { /* Ignore stale focus targets. */ }
            }
            previousFocus = null;
        }
    }

    function renderJob(job) {
        var box = byId('process');
        if (!box) return;
        var dialog = box.querySelector('.process-dialog');
        if (!job) {
            if (dialog) dialog.classList.remove('terminal', 'success', 'error', 'cancelled');
            currentJob = null;
            startedAt = 0;
            setProcessModal(false);
            return;
        }

        setProcessModal(true);
        currentJob = job;
        var terminal = ['completed', 'failed', 'cancelled'].indexOf(job.status) >= 0;
        var progress = Math.max(0, Math.min(100, Number(job.progress || 0)));
        byId('process-title').textContent = typeLabel(job.type) + ' · ' + job.id;
        byId('process-message').textContent = job.message || job.phase || '';
        byId('process-status').textContent = statusLabel(job.status);
        byId('progress-bar').style.width = progress + '%';
        byId('progress-percent').textContent = progress + '%';
        byId('process-log').textContent = (job.log_tail || []).join('\n');

        var cancel = byId('cancel-btn');
        var canCancel = !terminal && ['running', 'stale'].indexOf(job.status) >= 0 && job.cancel_allowed !== false;
        cancel.disabled = !canCancel;
        if (job.status === 'cancelling' || job.cancel_requested) cancel.textContent = 'Прерываем…';
        else if (job.status === 'rolling_back') cancel.textContent = 'Возвращаем изменения…';
        else if (!canCancel && !terminal && job.type === 'rollback') cancel.textContent = 'Критический этап…';
        else cancel.textContent = 'Прервать';

        var lockNote = byId('process-lock-note');
        if (lockNote) {
            if (job.status === 'rolling_back') lockNote.textContent = 'Прерывание принято. Интерфейс останется заблокированным до безопасного возврата изменений.';
            else if (!canCancel && !terminal && job.type === 'rollback') lockNote.textContent = 'Идёт критическое восстановление. Его нельзя остановить посередине, чтобы не повредить файлы или базу данных.';
            else lockNote.textContent = 'Пока процесс не завершён, остальной интерфейс заблокирован.';
        }

        var resume = byId('resume-btn');
        if (job.status === 'stale') resume.classList.remove('hidden');
        else resume.classList.add('hidden');

        if (!startedAt) startedAt = Date.now();
        byId('process-time').textContent = Math.round((Date.now() - startedAt) / 1000) + ' сек.';

        if (dialog) {
            dialog.classList.toggle('terminal', terminal);
            dialog.classList.toggle('success', job.status === 'completed');
            dialog.classList.toggle('error', job.status === 'failed');
            dialog.classList.toggle('cancelled', job.status === 'cancelled');
        }

        if (!terminal && canCancel && !box.contains(document.activeElement)) {
            window.setTimeout(function () { if (modalOpen && !cancel.disabled) cancel.focus(); }, 0);
        }

        if (terminal) {
            if (timer) window.clearTimeout(timer);
            timer = null;
            if (!terminalHandled) {
                terminalHandled = true;
                if (job.status === 'completed' && job.type === 'migration_export' && job.data && job.data.migration_link_available) {
                    try { window.sessionStorage.setItem('ume-migration-result', JSON.stringify({ job_id: job.id, expires: job.data.expires_at || '' })); } catch (error) { /* ignore */ }
                }
                saveFlash(terminalMessage(job), job.status === 'failed' ? 'error' : 'ok');
                window.setTimeout(function () { window.location.reload(); }, 700);
            }
        } else if (job.status !== 'stale') {
            if (timer) window.clearTimeout(timer);
            timer = window.setTimeout(step, 80);
        }
    }

    // 3.6.5: readable step errors, growing back-off and a clear hint when the server keeps aborting the same step.
    var stepFailures = 0;
    function stepErrorText(message) {
        var text = String(message || '');
        var http = text.match(/^HTTP (\d{3})/);
        if (http && /<html|<!doctype/i.test(text)) {
            var code = http[1];
            if (code === '500') return 'сервер прервал шаг (HTTP 500 — чаще всего превышен лимит времени или памяти PHP)';
            if (code === '502' || code === '503' || code === '504') return 'сервер не ответил вовремя (HTTP ' + code + ')';
            return 'HTTP ' + code;
        }
        return text.length > 300 ? text.slice(0, 300) + '…' : text;
    }
    function step() {
        if (!currentJob) return;
        api('step', { job_id: currentJob.id }).then(function (result) {
            stepFailures = 0;
            renderJob(result.job);
        }).catch(function (error) {
            stepFailures++;
            var delay = Math.min(15000, 1000 * Math.pow(2, Math.min(stepFailures - 1, 4)));
            var text = stepErrorText(error.message);
            var hint = stepFailures >= 5 ? ' Шаг не проходит уже ' + stepFailures + ' раз подряд: процесс можно безопасно прервать кнопкой «Прервать» и проверить журнал ошибок PHP хостинга.' : '';
            if (stepFailures === 1 || stepFailures === 5) notice('Ошибка шага: ' + text, 'error');
            if (byId('process-message')) byId('process-message').textContent = 'Ошибка: ' + text + '. Повтор через ' + Math.round(delay / 1000) + ' с (попытка ' + stepFailures + ').' + hint;
            if (byId('process-status')) byId('process-status').textContent = stepFailures >= 5 ? 'Шаг не выполняется' : 'Повторяем запрос';
            timer = window.setTimeout(step, delay);
        });
    }

    function fileModeInfo(value) {
        var map = {
            all: { label: 'Все файлы', short: 'все файлы', badge: 'warning' },
            code_cms: { label: 'Код + Ядро CMS', short: 'код + CMS', badge: 'success' },
            necessary: { label: 'Только нужные без CMS', short: 'без CMS', badge: 'success' },
            none: { label: 'Без файлов', short: 'без файлов', badge: 'neutral' }
        };
        return map[value] || map.code_cms;
    }

    function databaseModeInfo(value) {
        var map = {
            full: { label: 'Полный дамп', short: 'полный дамп', badge: 'warning' },
            minimal: { label: 'Минимум данных', short: 'минимум', badge: 'success' },
            schema: { label: 'Только структура без данных', short: 'структура', badge: 'success' },
            none: { label: 'Без БД', short: 'без БД', badge: 'neutral' }
        };
        return map[value] || map.minimal;
    }

    function legacyBackupProfile(mode) {
        var map = {
            code: { fileMode: 'code_cms', databaseMode: 'none' },
            schema: { fileMode: 'code_cms', databaseMode: 'schema' },
            compact: { fileMode: 'code_cms', databaseMode: 'minimal' },
            full: { fileMode: 'code_cms', databaseMode: 'full' },
            complete: { fileMode: 'all', databaseMode: 'full' }
        };
        var out = map[mode] || map.compact;
        return { fileMode: out.fileMode, databaseMode: out.databaseMode, legacyMode: mode || 'compact' };
    }

    function backupProfileFromRow(row) {
        if (row && row.file_mode && row.database_mode) {
            return { fileMode: row.file_mode, databaseMode: row.database_mode, legacyMode: '', databaseIncluded: row.database_included !== false };
        }
        var name = row && row.name ? row.name : '';
        var current = name.match(/-files-(all|code_cms|necessary|none)-db-(full|minimal|schema|none)\.zip$/i);
        if (current) return { fileMode: current[1].toLowerCase(), databaseMode: current[2].toLowerCase(), legacyMode: '', databaseIncluded: row && typeof row.database_included !== 'undefined' ? !!row.database_included : current[2].toLowerCase() !== 'none' };
        var old = name.match(/-(code|schema|compact|full|complete)\.zip$/i);
        var legacy = legacyBackupProfile(old ? old[1].toLowerCase() : 'compact');
        legacy.databaseIncluded = row && typeof row.database_included !== 'undefined' ? !!row.database_included : legacy.databaseMode !== 'none';
        return legacy;
    }

    function renderBackups(rows) {
        var body = byId('backups-body');
        if (!body) return;
        body.innerHTML = (rows || []).map(function (row) {
            var profile = backupProfileFromRow(row);
            var fileInfo = fileModeInfo(profile.fileMode);
            var dbInfo = databaseModeInfo(profile.databaseMode);
            var fileLabel = profile.legacyMode === 'complete' ? 'Код + изображения (старый формат)' : fileInfo.label;
            var publicButton = row.public_available ? '<button class="button secondary small backup-url" type="button" data-id="' + esc(row.id) + '" title="Скопировать публичную очищенную ссылку на бэкап для ChatGPT и скачивания" aria-label="Скопировать публичный URL бэкапа ' + esc(row.id) + '">URL</button>' : '<button class="button secondary small" type="button" disabled title="Этот бэкап создан до 3.2.4. Создайте его заново, чтобы получить очищенный публичный URL.">URL</button>';
            var versionInfo = row.site_version ? '<span class="status-badge neutral">Версия: ' + esc(row.site_version) + '</span>' + (row.next_patch_version ? '<span class="status-badge neutral">Следующий патч: ' + esc(row.next_patch_version) + '</span>' : '') : '';
            return '<tr>' +
                '<td class="check-cell"><input type="checkbox" class="row-check" data-table="backups" value="' + esc(row.id) + '" aria-label="Выбрать бэкап"></td>' +
                '<td class="file-cell"><div class="file-name"><span class="file-icon">ZIP</span><div><strong>' + esc(row.name) + '</strong><small>ID резервной копии</small></div></div></td>' +
                '<td><div class="backup-mode-pair"><span class="status-badge ' + fileInfo.badge + '">Файлы: ' + esc(fileLabel) + '</span><span class="status-badge ' + dbInfo.badge + '">БД: ' + esc(dbInfo.label) + '</span>' + versionInfo + '</div></td>' +
                '<td>' + fmt(row.size) + '</td><td>' + date(row.mtime) + '</td>' +
                '<td class="actions-cell"><div class="row-actions"><button class="button ghost danger-text small backup-restore" type="button" data-id="' + esc(row.id) + '" data-file-mode="' + esc(profile.fileMode) + '" data-db-mode="' + esc(profile.databaseMode) + '" data-legacy-mode="' + esc(profile.legacyMode || '') + '" data-db-included="' + (profile.databaseIncluded ? '1' : '0') + '">Откатить</button><a class="button secondary small" href="?action=download&type=backup&id=' + encodeURIComponent(row.id) + '">Скачать</a>' + publicButton + '</div></td></tr>';
        }).join('') || '<tr><td colspan="6" class="empty-cell">Резервных копий пока нет.</td></tr>';
    }

    function renderHistory(rows) {
        var body = byId('history-body');
        if (!body) return;
        body.innerHTML = (rows || []).map(function (row) {
            var status = row.status || 'installed';
            var actions = '';
            if (row.rollback_file && row.rollback_available) {
                actions += '<a class="button secondary small" href="?action=download&type=rollback&id=' + encodeURIComponent(row.rollback_file) + '">Rollback ZIP</a>';
            }
            if (status === 'installed' && row.rollback_available) {
                actions += '<button class="button danger small rollback" type="button" data-id="' + esc(row.patch_id) + '">Откатить</button>';
            }
            if (!actions) actions = '<span class="muted-action">Нет действий</span>';
            return '<tr>' +
                '<td class="check-cell"><input type="checkbox" class="row-check" data-table="history" value="' + esc(row.patch_id) + '" aria-label="Выбрать патч"></td>' +
                '<td class="file-cell"><strong>' + esc(row.name) + '</strong><small>' + esc(row.patch_id) + '</small></td>' +
                '<td>' + (row.site_version ? '<span class="history-site-version" title="Версия сайта после установки этого патча">' + esc(row.site_version) + '</span>' + ((row.version && row.version !== row.site_version) ? '<span class="history-patch-version">manifest ' + esc(row.version) + '</span>' : '') : esc(row.version)) + '</td><td><span class="status-badge ' + statusClass(status) + '">' + esc(statusLabel(status)) + '</span></td>' +
                '<td>' + esc(row.installed_at || '—') + '</td><td class="actions-cell"><div class="row-actions">' + actions + '</div></td></tr>';
        }).join('') || '<tr><td colspan="6" class="empty-cell">Патчи ещё не устанавливались.</td></tr>';
    }

    // 3.6.9: site version block; patch digit carries 9 -> next minor version.
    function isoDate(value) {
        if (!value) return '';
        var parsed = new Date(value);
        return isNaN(parsed.getTime()) ? String(value) : parsed.toLocaleString();
    }

    function renderSiteVersion(info) {
        var value = byId('site-version-value');
        if (!value || !info || !info.version) return;
        var meta = byId('site-version-meta');
        var when = isoDate(info.updated_at);
        var name = info.patch_name || info.patch_id || '';
        var text = 'Следующий патч: ' + (info.next_version || '—') + ' (1.0.9 → 1.1.0 → … → 2.0.0)';
        if (info.source === 'patch') text = 'Патч' + (name ? ' «' + name + '»' : '') + (when ? ' · ' + when : '');
        else if (info.source === 'rollback') text = 'Откат' + (name ? ' «' + name + '»' : ' патча') + (when ? ' · ' + when : '');
        else if (info.source === 'backup_restore') text = 'Восстановлен бэкап' + (when ? ' · ' + when : '');
        else if (info.source === 'manual') text = 'Задана вручную' + (when ? ' · ' + when : '');
        var previous = '';
        try { previous = window.sessionStorage.getItem('ume-site-version') || ''; window.sessionStorage.setItem('ume-site-version', info.version); } catch (error) { previous = ''; }
        value.textContent = info.version;
        value.classList.remove('bumped');
        if (previous && previous !== info.version) { void value.offsetWidth; value.classList.add('bumped'); }
        if (meta) { meta.textContent = text; meta.title = text; }
        var input = byId('site-version-input');
        if (input && byId('site-version-form').classList.contains('hidden')) input.value = info.version;
    }

    function initSiteVersion() {
        var view = byId('site-version-view');
        var form = byId('site-version-form');
        var edit = byId('site-version-edit');
        var cancel = byId('site-version-cancel');
        var input = byId('site-version-input');
        if (!view || !form || !edit || !input) return;
        function toggle(editing) {
            view.classList.toggle('hidden', editing);
            form.classList.toggle('hidden', !editing);
            if (editing) { input.value = byId('site-version-value').textContent.replace('—', '').trim(); input.focus(); input.select(); }
            else edit.focus();
        }
        edit.addEventListener('click', function () { toggle(true); });
        if (cancel) cancel.addEventListener('click', function () { toggle(false); });
        form.addEventListener('keydown', function (event) { if (event.key === 'Escape') toggle(false); });
        form.addEventListener('submit', function (event) {
            event.preventDefault();
            var version = input.value.trim();
            if (!/^v?\d{1,6}\.\d{1,6}\.\d{1,6}$/i.test(version)) { notice('Версия сайта должна быть в формате X.Y.Z, например 1.0.0.', 'error', true); input.focus(); return; }
            var buttons = form.querySelectorAll('button');
            Array.prototype.forEach.call(buttons, function (button) { button.disabled = true; });
            api('set_site_version', { version: version }).then(function (result) {
                toggle(false);
                renderSiteVersion(result.site_version);
                notice('Версия сайта установлена: ' + result.site_version.version + '. Следующий патч должен иметь версию ' + (result.site_version.next_version || '—') + '.', 'ok');
            }).catch(function (error) {
                notice(error.message, 'error', true);
            }).then(function () {
                Array.prototype.forEach.call(buttons, function (button) { button.disabled = false; });
            });
        });
    }

    function renderJobs(rows) {
        var body = byId('jobs-body');
        if (!body) return;
        body.innerHTML = (rows || []).map(function (row) {
            var actions = row.migration_link_available ? '<button class="button secondary small migration-link-copy" type="button" data-job-id="' + esc(row.id) + '">Копировать ссылку</button>' : '<span class="muted-action">Нет действий</span>';
            return '<tr><td class="check-cell"><input type="checkbox" class="row-check" data-table="jobs" value="' + esc(row.id) + '" aria-label="Выбрать процесс"></td>' +
                '<td class="file-cell"><strong>' + esc(typeLabel(row.type)) + '</strong><small>' + esc(row.id) + '</small></td>' +
                '<td><span class="status-badge ' + statusClass(row.status) + '">' + esc(statusLabel(row.status)) + '</span></td>' +
                '<td>' + esc(row.message || row.phase || '—') + '</td><td>' + esc(row.created_at || '—') + '</td><td class="actions-cell"><div class="row-actions">' + actions + '</div></td></tr>';
        }).join('') || '<tr><td colspan="6" class="empty-cell">Процессов пока нет.</td></tr>';
        all('.migration-link-copy', body).forEach(function (button) {
            button.addEventListener('click', function () { copyMigrationLink(button.getAttribute('data-job-id'), button).catch(function (error) { notice(error.message, 'error', true); }); });
        });
    }

    function renderMigrationShare(rows) {
        var box = byId('migration-share-status');
        if (!box) return;
        var active = null;
        (rows || []).some(function (row) { if (row.type === 'migration_export' && row.migration_link_available) { active = row; return true; } return false; });
        if (!active) { box.classList.add('hidden'); box.innerHTML = ''; return; }
        box.classList.remove('hidden');
        box.innerHTML = '<div><strong>Активная ссылка переноса готова.</strong><small>' + (active.migration_expires_at ? 'Действует до ' + esc(active.migration_expires_at) + '. ' : '') + 'Секрет хранится только в зашифрованном серверном хранилище.</small></div><button class="button secondary small" type="button">Копировать ссылку</button>';
        var button = box.querySelector('button');
        if (button) button.addEventListener('click', function () { copyMigrationLink(active.id, button).catch(function (error) { notice(error.message, 'error', true); }); });
    }

    function renderLogs(rows) {
        var body = byId('logs-body');
        if (!body) return;
        body.innerHTML = (rows || []).map(function (row) {
            return '<tr><td class="check-cell"><input type="checkbox" class="row-check" data-table="logs" value="' + esc(row.id) + '" aria-label="Выбрать журнал"></td>' +
                '<td class="file-cell"><div class="file-name"><span class="file-icon">LOG</span><div><strong>' + esc(row.name) + '</strong><small>Технический журнал</small></div></div></td>' +
                '<td>' + fmt(row.size) + '</td><td>' + date(row.mtime) + '</td>' +
                '<td class="actions-cell"><div class="row-actions"><a class="button secondary small" href="?action=download&type=log&id=' + encodeURIComponent(row.id) + '">Скачать</a></div></td></tr>';
        }).join('') || '<tr><td colspan="5" class="empty-cell">Журналов пока нет.</td></tr>';
    }

    function renderDiagnostics(site) {
        var grid = byId('diagnostic-grid');
        var details = byId('site-info');
        if (!grid && !details) return;
        var diagnostics = site.diagnostics || {};
        var items = [
            ['cms_detected', 'CMS', 'Автоматическое определение движка'],
            ['cms_supported', 'Совместимость CMS', 'Поддерживаемая версия движка/приложения'],
            ['database_available', 'База данных', 'MySQL/MariaDB backup и SQL-миграции'],
            ['zip', 'ZIP', 'Архивы бэкапов и патчей'],
            ['openssl', 'OpenSSL', 'Криптографические операции'],
            ['json', 'JSON', 'Манифесты и настройки'],
            ['session', 'Session', 'Авторизация и Recovery-доступ'],
            ['storage_writable', 'Хранилище', 'Доступно для записи'],
            ['config_found', 'Upgrade config', 'Локальный профиль создан']
        ];
        if (grid) {
            grid.innerHTML = items.map(function (item) {
                var ok = !!diagnostics[item[0]];
                return '<div class="diagnostic-item ' + (ok ? 'ok' : 'fail') + '"><span class="diagnostic-symbol">' + (ok ? '✓' : '!') + '</span><div><strong>' + item[1] + '</strong><small>' + item[2] + '</small></div></div>';
            }).join('');
        }
        if (details) {
            var cms = site.cms || {};
            details.innerHTML = '<div class="detail-row"><span>Домен</span><strong>' + esc(site.domain) + '</strong></div>' +
                '<div class="detail-row"><span>CMS</span><strong>' + esc((cms.name || 'Generic') + (cms.version ? ' ' + cms.version : '')) + '</strong></div>' +
                '<div class="detail-row"><span>Поддержка</span><strong>' + esc((cms.supported === false ? 'нет' : 'да') + (cms.min_supported_version ? ' · от ' + cms.min_supported_version : '')) + '</strong></div>' +
                '<div class="detail-row"><span>База</span><strong>' + esc(diagnostics.database_engine || 'unknown') + '</strong></div>' +
                '<div class="detail-row"><span>PHP</span><strong>' + esc(site.php) + '</strong></div>' +
                '<div class="detail-row"><span>Проект</span><strong>' + esc(site.project) + '</strong></div>' +
                '<div class="detail-row"><span>Site ID</span><strong title="' + esc(site.site_id) + '">' + esc(site.site_id) + '</strong></div>' +
                '<div class="detail-row"><span>Корень сайта</span><strong title="' + esc(site.root) + '">' + esc(site.root) + '</strong></div>';
        }
    }

    function listHtml(items, emptyText) {
        if (!items || !items.length) return '<p class="empty-copy">' + esc(emptyText || 'Нет данных.') + '</p>';
        return '<ul class="task-check-list">' + items.map(function (item) { return '<li>' + esc(item) + '</li>'; }).join('') + '</ul>';
    }

    function taskEventsHtml(events) {
        if (!events || !events.length) return '<p class="empty-copy">Журнал пока пуст.</p>';
        return '<div class="task-timeline">' + events.map(function (event) {
            var level = String(event.level || 'INFO').toLowerCase();
            return '<div class="timeline-item ' + esc(level) + '"><span class="timeline-dot"></span><div><div class="timeline-meta"><strong>' + esc(event.level || 'INFO') + '</strong><time>' + esc(event.at || '') + '</time></div><p>' + esc(event.message || '') + '</p></div></div>';
        }).join('') + '</div>';
    }

    function taskJobsHtml(jobs) {
        if (!jobs || !jobs.length) return '<p class="empty-copy">Серверные процессы ещё не запускались.</p>';
        return '<div class="task-job-list">' + jobs.map(function (job) {
            return '<div class="task-job"><div><strong>' + esc(typeLabel(job.type)) + '</strong><small>' + esc(job.id || '') + '</small></div><span class="status-badge ' + statusClass(job.status) + '">' + esc(statusLabel(job.status)) + '</span></div>';
        }).join('') + '</div>';
    }

    function renderApiTasks(rows) {
        var root = byId('api-task-list');
        if (!root) return;
        rows = rows || [];
        var count = byId('api-task-count');
        if (count) count.textContent = 'Задач: ' + rows.length;
        if (!rows.length) {
            root.innerHTML = '<section class="card empty-task-card"><div class="empty-task-icon">API</div><h3>Прямых заданий пока нет</h3><p>После подключения ChatGPT первая созданная задача появится здесь вместе с полным ТЗ и журналом.</p></section>';
            return;
        }
        root.innerHTML = rows.map(function (task, index) {
            var result = task.result || null;
            var patch = task.patch || null;
            var resultHtml = result ? '<div class="task-result ' + statusClass(result.status) + '"><div class="task-block-title"><h4>Результат</h4><span class="status-badge ' + statusClass(result.status) + '">' + esc(statusLabel(result.status)) + '</span></div><p class="task-summary">' + esc(result.summary || '') + '</p><div class="task-two-cols"><div><h5>Выполнено по ТЗ</h5>' + listHtml(result.completed_requirements, 'Отдельные пункты не указаны.') + '</div><div><h5>Проверки</h5>' + listHtml(result.validation_checks, 'Проверки не указаны.') + '</div></div></div>' : '<div class="task-result pending"><div class="task-block-title"><h4>Результат</h4><span class="status-badge warning">Ожидается</span></div><p class="empty-copy">ChatGPT ещё не зафиксировал итог задания.</p></div>';
            var awaitingApproval = !!(patch && patch.status === 'validated' && patch.upload_id && patch.approved_upload_id !== patch.upload_id);
            var approvedNote = patch && patch.upload_id && patch.approved_upload_id === patch.upload_id ? '<small class="task-approval-note">Установка разрешена владельцем: ' + esc(patch.approved_at || '') + '</small>' : '';
            var approveButton = awaitingApproval ? '<button class="button primary small api-task-approve" type="button" data-task-id="' + esc(task.id) + '">Разрешить установку</button>' : '';
            var patchHtml = patch ? '<div class="task-patch"><span>Патч</span><strong>' + esc(patch.name || patch.patch_id || '—') + '</strong><small>' + esc((patch.version || '') + (patch.patch_id ? ' · ' + patch.patch_id : '')) + '</small>' + approvedNote + approveButton + '</div>' : '';
            var rollback = task.rollback_request || null;
            var rollbackHtml = rollback ? '<div class="task-patch"><span>Откат патча</span><strong>' + esc(rollback.patch_id || '—') + '</strong><small>' + esc(rollback.status === 'pending' ? 'Ожидает разрешения владельца' : rollback.status === 'approved' ? 'Разрешён владельцем, ожидает запуска' : 'Откат запущен') + '</small>' + (rollback.status === 'pending' ? '<button class="button primary small api-task-approve-rollback" type="button" data-task-id="' + esc(task.id) + '" data-patch-id="' + esc(rollback.patch_id) + '">Разрешить откат</button>' : '') + '</div>' : '';
            return '<section class="card api-task-card"><div class="api-task-head"><div><div class="task-id">' + esc(task.id) + '</div><h3>' + esc(task.title || 'API-задача') + '</h3><p>Создано: ' + esc(task.created_at || '—') + ' · Обновлено: ' + esc(task.updated_at || '—') + '</p></div><span class="status-badge ' + statusClass(task.status) + '">' + esc(statusLabel(task.status)) + '</span></div>' + patchHtml + rollbackHtml + '<details class="task-details"' + (index === 0 ? ' open' : '') + '><summary>Открыть ТЗ, действия и проверки</summary><div class="task-section"><h4>Техническое задание</h4><p class="task-specification">' + esc(task.specification || '').replace(/\n/g, '<br>') + '</p><h5>Критерии приёмки</h5>' + listHtml(task.acceptance_criteria, 'Критерии отдельно не перечислены.') + '</div>' + resultHtml + '<div class="task-two-cols task-operational"><div><h4>Процессы</h4>' + taskJobsHtml(task.jobs) + '</div><div><h4>Журнал выполнения</h4>' + taskEventsHtml(task.events) + '</div></div></details></section>';
        }).join('');
    }

    function verifyOAuthDiscovery(apiData) {
        var discovery = apiData && apiData.discovery ? apiData.discovery : null;
        if (!discovery || !window.fetch || !apiData.enabled || !apiData.https) return;
        var tests = [
            { key: 'prm', url: discovery.protected_resource_url, validate: function (d) { return d && d.resource === apiData.mcp_url && Array.isArray(d.authorization_servers) && d.authorization_servers.indexOf(apiData.oauth_issuer) !== -1; } },
            { key: 'as', url: discovery.authorization_server_url, validate: function (d) { return d && d.issuer === apiData.oauth_issuer && Array.isArray(d.code_challenge_methods_supported) && d.code_challenge_methods_supported.indexOf('S256') !== -1 && d.authorization_response_iss_parameter_supported === true; } }
        ];
        Promise.all(tests.map(function (test) {
            return fetch(test.url, { cache: 'no-store', credentials: 'omit', headers: { 'Accept': 'application/json' } }).then(function (response) {
                if (!response.ok) throw new Error('HTTP ' + response.status);
                return response.json();
            }).then(function (data) {
                if (!test.validate(data)) throw new Error('Некорректные metadata');
                return { key: test.key, ok: true, value: test.url };
            }).catch(function (error) { return { key: test.key, ok: false, value: error.message || 'Недоступно' }; });
        })).then(function (results) {
            var allOk = true;
            results.forEach(function (result) {
                if (!result.ok) allOk = false;
                var row = document.querySelector('.diagnostic-row[data-check="' + result.key + '"]');
                if (row) {
                    row.classList.toggle('ok', result.ok);
                    row.classList.toggle('bad', !result.ok);
                    var code = row.querySelector('code');
                    if (code) code.textContent = result.value;
                }
            });
            var status = byId('api-connection-status');
            if (status && !allOk) {
                status.className = 'connection-status warning';
                status.innerHTML = '<span class="state-dot"></span><div><strong>OAuth discovery недоступен снаружи</strong><small>Проверьте отмеченные маршруты /.well-known. Пока ChatGPT не сможет завершить подключение.</small></div>';
            }
        });
    }

    function renderApiConnection(apiData) {
        if (!apiData) return;
        var status = byId('api-connection-status');
        var url = byId('mcp-url');
        var metrics = byId('api-connection-metrics');
        var diagnostics = byId('api-connection-diagnostics');
        var settings = apiData.settings || {};
        var discovery = apiData.discovery || {};
        if (url) url.value = apiData.mcp_url || '';
        if (status) {
            var usable = !!apiData.enabled && !!apiData.https && !!discovery.ready;
            var title = usable ? 'API готов к подключению' : (!apiData.enabled ? 'API отключён' : (!apiData.https ? 'Требуется HTTPS' : 'OAuth discovery требует настройки'));
            var detail = usable ? 'OAuth 2.1, PKCE S256 и MCP discovery доступны по стандартным адресам' : (!apiData.enabled ? 'Разрешите прямое API-подключение ниже' : (!apiData.https ? 'ChatGPT подключает удалённый MCP только по HTTPS' : 'Проверьте создание /.well-known OAuth-метаданных в диагностике ниже'));
            status.className = 'connection-status ' + (usable ? 'ok' : 'warning');
            status.innerHTML = '<span class="state-dot"></span><div><strong>' + title + '</strong><small>' + detail + '</small></div>';
        }
        if (metrics) metrics.innerHTML = '<div><span>Подключения</span><strong>' + Number(apiData.connected_clients || 0) + '</strong></div><div><span>Активные токены</span><strong>' + Number(apiData.active_access_tokens || 0) + '</strong></div><div><span>MCP 2026</span><strong>' + (discovery.mcp_modern_2026_07_28 ? 'Да' : 'Нет') + '</strong></div>';
        if (diagnostics) {
            var checks = [
                { ok: !!apiData.https, label: 'HTTPS', value: apiData.https ? 'готов' : 'не обнаружен' },
                { ok: !!discovery.protected_resource_shim, label: 'Protected Resource Metadata', value: discovery.protected_resource_url || '' },
                { ok: !!discovery.authorization_server_shim, label: 'Authorization Server Metadata', value: discovery.authorization_server_url || '' },
                { ok: !!discovery.pkce_s256, label: 'PKCE S256', value: discovery.pkce_s256 ? 'поддерживается' : 'нет' },
                { ok: !!discovery.cimd_supported && !!discovery.dcr_supported, label: 'Регистрация клиента', value: 'CIMD + DCR' }
            ];
            diagnostics.innerHTML = '<div class="diagnostic-title">Проверка обнаружения ChatGPT</div>' + checks.map(function (item, index) { var key = index === 1 ? 'prm' : (index === 2 ? 'as' : 'local-' + index); return '<div class="diagnostic-row ' + (item.ok ? 'ok' : 'bad') + '" data-check="' + key + '"><span class="diagnostic-dot"></span><strong>' + esc(item.label) + '</strong><code>' + esc(item.value) + '</code></div>'; }).join('');
        }
        var form = byId('api-settings-form');
        if (form) {
            form.elements.enabled.checked = !!settings.enabled;
            if (form.elements.patch_upload_mode) form.elements.patch_upload_mode.value = settings.patch_upload_mode || 'confirm';
            if (form.elements.patch_apply_mode) form.elements.patch_apply_mode.value = settings.patch_apply_mode || 'confirm';
            form.elements.require_backup_before_install.checked = !!settings.require_backup_before_install;
            form.elements.auto_rollback.checked = true;
        }
        verifyOAuthDiscovery(apiData);
    }

    function updateExclusionLines() {
        var textarea = byId('backup-exclusions');
        var gutter = byId('backup-exclusion-lines');
        if (!textarea || !gutter) return;
        var count = Math.max(1, String(textarea.value || '').split(/\r\n|\r|\n/).length);
        var lines = [];
        for (var i = 1; i <= count; i++) lines.push(i);
        gutter.textContent = lines.join('\n');
        gutter.scrollTop = textarea.scrollTop;
    }

    function applyUiTheme(theme) {
        var mode = theme === 'dark' ? 'dark' : 'light';
        var dark = mode === 'dark';
        var workspace = document.querySelector('.workspace');
        if (workspace) workspace.classList.toggle('theme-dark', dark);
        if (document.body) document.body.classList.toggle('ume-theme-dark', dark);
        var toggle = byId('ui-theme-toggle');
        if (toggle) toggle.checked = dark;
        var label = byId('ui-theme-label');
        if (label) label.textContent = dark ? 'Тема: Ночь' : 'Тема: День';
    }

    function applySettings(settings) {
        var fallback = legacyBackupProfile(settings.dump_mode || 'compact');
        var fileMode = settings.file_mode || fallback.fileMode;
        var databaseMode = settings.database_mode || fallback.databaseMode;
        var form = byId('settings-form');
        if (form) {
            if (form.elements.file_mode) form.elements.file_mode.value = fileMode;
            if (form.elements.database_mode) form.elements.database_mode.value = databaseMode;
            form.elements.backup_retention.value = settings.backup_retention || 10;
            form.elements.log_retention_days.value = settings.log_retention_days || 30;
        }
        var uiTheme = settings.ui_theme === 'dark' ? 'dark' : 'light';
        applyUiTheme(uiTheme);
        var exclusions = byId('backup-exclusions');
        if (exclusions) exclusions.value = (settings.backup_exclusions || []).join('\n');
        updateExclusionLines();
        var badge = byId('backup-mode-badge');
        if (badge) badge.textContent = 'Файлы: ' + fileModeInfo(fileMode).short + ' · БД: ' + databaseModeInfo(databaseMode).short;
    }

    function selectedDatabaseConnectionMode(form) {
        var checked = form.querySelector('input[name="database_connection_mode"]:checked');
        return checked ? checked.value : 'auto';
    }

    function toggleDatabaseConnectionFields(form) {
        var mode = selectedDatabaseConnectionMode(form);
        var fileFields = byId('file-db-fields');
        var manualFields = byId('manual-db-fields');
        if (fileFields) fileFields.classList.toggle('hidden', mode !== 'file');
        if (manualFields) manualFields.classList.toggle('hidden', mode !== 'manual');
    }

    function renderDatabaseConnection(data) {
        if (!data) return;
        var form = byId('database-connection-form');
        if (!form) return;
        var manual = data.manual || {};
        var mode = data.mode || 'auto';
        var modeInput = form.querySelector('input[name="database_connection_mode"][value="' + mode + '"]');
        if (modeInput) modeInput.checked = true;
        if (form.elements.database_config_path) form.elements.database_config_path.value = data.file_path || '';
        form.elements.manual_db_host.value = manual.host || 'localhost';
        form.elements.manual_db_port.value = manual.port || 3306;
        form.elements.manual_db_name.value = manual.database || '';
        form.elements.manual_db_user.value = manual.user || '';
        form.elements.manual_db_prefix.value = manual.prefix || '';
        form.elements.manual_db_password.value = '';
        if (form.elements.manual_db_clear_password) form.elements.manual_db_clear_password.checked = false;
        form.elements.manual_db_password.placeholder = data.password_configured ? 'Пароль уже сохранён — оставьте пустым, чтобы не менять' : 'Пароль БД';
        toggleDatabaseConnectionFields(form);
        var status = byId('database-connection-status');
        if (status) {
            var active = data.active || {};
            var labels = { auto: 'Автоопределение CMS', file: 'Файл конфигурации', manual: 'Ручное подключение' };
            var source = labels[mode] || 'Подключение к БД';
            if (active.available) status.innerHTML = '<span class="status-badge success">' + esc(source) + '</span><span class="update-inline-note">' + esc(active.host || '') + ':' + Number(active.port || 3306) + ' · ' + esc(active.database || '') + ' · ' + esc(active.user || '') + '</span>';
            else status.innerHTML = '<span class="status-badge warning">' + esc(source) + '</span><span class="update-inline-note">' + esc(active.reason || 'Реквизиты БД не определены') + '</span>';
        }
    }

    function formatCronTime(value) {
        if (!value) return '';
        var d = new Date(value);
        if (isNaN(d.getTime())) return value;
        return d.toLocaleString('ru-RU');
    }

    function renderCron(data) {
        if (!data) return;
        var form = byId('cron-settings-form');
        var box = byId('cron-status-box');
        var reinstall = byId('reinstall-cron');
        if (form) {
            form.elements.auto_backup_enabled.checked = !!data.enabled;
            form.elements.auto_backup_frequency.value = data.frequency || 'daily';
            form.elements.auto_backup_frequency.disabled = !data.enabled;
        }
        if (reinstall) reinstall.classList.toggle('hidden', !(data.enabled && data.system_cron_available));
        if (!box) return;
        var html = '';
        if (!data.enabled) {
            html = '<div class="cron-status-line"><span class="status-badge neutral">Автобэкап отключён</span><span>Системная задача не запускает резервные копии.</span></div>';
        } else if (data.active) {
            html = '<div class="cron-status-line"><span class="status-badge success">Системный cron установлен</span><span>' + esc(data.frequency_label || '') + '</span></div>';
            if (data.next_run_at) html += '<small>Следующий запуск по расписанию: <strong>' + esc(formatCronTime(data.next_run_at)) + '</strong></small>';
            if (data.cron_install_method) {
                var methodLabel = data.cron_install_method === 'stdin' ? 'STDIN (crontab -)' : (data.cron_install_method === 'system_temp_file' ? 'системный временный файл' : data.cron_install_method);
                html += '<small>Способ установки cron: <strong>' + esc(methodLabel) + '</strong></small>';
            }
            if (data.last_success_at) html += '<small>Последний успешный полный бэкап: <strong>' + esc(formatCronTime(data.last_success_at)) + '</strong>' + (data.last_file ? ' · ' + esc(data.last_file) : '') + '</small>';
            else html += '<small>Автоматический бэкап ещё не выполнялся.</small>';
        } else {
            var reason = data.cron_error || data.system_cron_reason || 'Системный cron недоступен.';
            html = '<div class="cron-status-line"><span class="status-badge error">Cron не создан</span><span>' + esc(reason) + '</span></div><small>Настройка сохранена, но без системного cron гарантированный запуск по времени невозможен.</small>';
            if (data.crontab_binary || data.php_cli_binary) html += '<small>Диагностика: crontab=' + esc(data.crontab_binary || 'не найден') + ' · PHP CLI=' + esc(data.php_cli_binary || 'не найден') + '</small>';
        }
        if (data.last_status === 'failed' && data.last_error) html += '<div class="cron-last-error">Последняя ошибка backup: ' + esc(data.last_error) + '</div>';
        box.innerHTML = html;
    }

    function renderSelfUpdate(data) {
        if (!data) return;
        var version = byId('self-update-version');
        var result = byId('self-update-result');
        var check = byId('check-self-update');
        var apply = byId('apply-self-update');
        var central = !!(data.repository && data.repository.enabled);
        if (version) version.textContent = 'Версия ' + (data.current_version || '—');
        if (check) { check.classList.toggle('hidden', central); check.disabled = central || !(data.client && data.client.configured); }
        var installerButton = byId('download-installer'); if (installerButton) installerButton.classList.toggle('hidden', !central);
        if (apply) apply.classList.add('hidden');
        var description = byId('self-update-description');
        if (description) description.textContent = central ? 'Основной сайт обновляется только вручную проверенным FTP ZIP, затем публикует релиз для подключённых сайтов.' : 'Клиентский сайт получает и устанавливает Upgrade Manager только с настроенного центрального репозитория.';
        if (result) {
            if (central) result.innerHTML = '<span class="status-badge success">Центральный репозиторий</span><span class="update-inline-note">Ручное самообновление разрешено только на этом основном сайте</span>';
            else if (data.client && data.client.configured) result.innerHTML = '<span class="status-badge success">Центральный сервер настроен</span><span class="update-inline-note">' + esc(data.client.server_url || '') + '</span>';
            else result.innerHTML = '<span class="status-badge warning">Источник обновлений не настроен</span><a class="update-inline-link" href="./?view=self-update">Настроить источник</a>';
        }
    }

    function renderUpdateAdmin(data) {
        if (!data) return;
        var sourceForm = byId('update-source-form');
        if (sourceForm) {
            sourceForm.elements.update_server_url.value = data.client && data.client.server_url ? data.client.server_url : '';
            sourceForm.elements.update_repository_key.value = '';
            sourceForm.elements.update_repository_key.placeholder = data.client && data.client.key_configured ? 'Ключ уже сохранён — оставьте пустым, чтобы не менять' : 'Вставьте ключ центрального сайта';
        }
        var central = !!(data.repository && data.repository.enabled);
        var repoForm = byId('repository-form');
        if (repoForm) repoForm.elements.repository_enabled.checked = central;
        var sourceCard = byId('update-source-card'); if (sourceCard) sourceCard.classList.toggle('hidden', central);
        var manualCard = byId('manual-self-update-card'); if (manualCard) manualCard.classList.toggle('hidden', !central);
        var selfBackupCard = byId('self-backup-card'); if (selfBackupCard) selfBackupCard.classList.toggle('hidden', !central);
        var remoteResetCard = byId('remote-password-reset-card'); if (remoteResetCard) remoteResetCard.classList.toggle('hidden', !central);
        var smtpCard = byId('smtp-settings-card'); if (smtpCard) smtpCard.classList.toggle('hidden', !central);
        var manualLocked = byId('manual-self-update-locked'); if (manualLocked) manualLocked.classList.toggle('hidden', central);
        var panel = byId('repository-panel');
        if (panel) panel.classList.toggle('hidden', !(data.repository && data.repository.enabled));
        var feed = byId('repository-feed-url');
        if (feed && data.repository) feed.value = data.repository.feed_url || '';
        var key = byId('repository-key');
        if (key && data.repository) key.value = data.repository.key || '';
        var autoPublication = byId('repository-auto-publication');
        if (autoPublication && data.repository) {
            var auto = data.repository.automatic_publication || {};
            autoPublication.classList.toggle('error', auto.state === 'error');
            autoPublication.classList.toggle('success', auto.state !== 'error');
            autoPublication.innerHTML = auto.state === 'error'
                ? '<strong>Автопубликация временно не выполнена.</strong> ' + esc(auto.message || 'Upgrade Manager повторит публикацию автоматически при следующей проверке.')
                : '<strong>Автопубликация включена.</strong> ' + esc(auto.message || 'Новая версия публикуется автоматически сразу после успешного самообновления.');
        }
        var latest = byId('repository-latest');
        if (latest && data.repository) {
            var rel = data.repository.latest || {};
            latest.innerHTML = rel.version ? '<span class="status-badge success">Доступна ' + esc(rel.version) + '</span><span class="update-inline-note">' + esc(rel.published_at || '') + ' · ' + fmt(rel.package_bytes || 0) + ' · файлов: ' + Number(rel.file_count || 0) + '</span>' : '<span class="status-badge neutral">Ожидается первая автоматическая публикация</span>';
        }
        var remoteSecurity = byId('remote-password-reset-security');
        if (remoteSecurity && data.repository) {
            var remote = data.repository.remote_password_reset || {};
            if (remote.available) remoteSecurity.innerHTML = '<strong>RSA-подпись готова.</strong> Fingerprint: <code>' + esc(remote.fingerprint || '') + '</code>. Приватный ключ хранится только на главном сайте.';
            else remoteSecurity.innerHTML = '<strong>Удалённый сброс сейчас недоступен.</strong> ' + esc(remote.reason || 'Проверьте OpenSSL и cURL на главном сайте.');
            remoteSecurity.classList.toggle('error', !remote.available);
            remoteSecurity.classList.toggle('success', !!remote.available);
        }
        var smtpForm = byId('smtp-settings-form');
        if (smtpForm && data.smtp) {
            var smtp = data.smtp || {};
            smtpForm.elements.smtp_enabled.checked = !!smtp.enabled;
            smtpForm.elements.smtp_host.value = smtp.host || '';
            smtpForm.elements.smtp_port.value = smtp.port || 587;
            smtpForm.elements.smtp_security.value = smtp.security || 'tls';
            smtpForm.elements.smtp_username.value = smtp.username || '';
            smtpForm.elements.smtp_password.value = '';
            smtpForm.elements.smtp_password.placeholder = smtp.password_configured ? 'Пароль уже сохранён — оставьте пустым, чтобы не менять' : 'SMTP-пароль';
            smtpForm.elements.smtp_from_email.value = smtp.from_email || '';
            smtpForm.elements.smtp_from_name.value = smtp.from_name || 'Upgrade Manager Security';
            var passNote = byId('smtp-password-note');
            if (passNote) passNote.textContent = smtp.password_configured ? 'Пароль хранится зашифрованно. Оставьте поле пустым, чтобы сохранить текущий.' : 'Оставьте пустым, если SMTP-сервер не требует авторизацию.';
            var statusBox = byId('smtp-status-box');
            if (statusBox) {
                if (smtp.enabled && smtp.configured) statusBox.innerHTML = '<span class="status-badge success">SMTP включён</span><span class="update-inline-note">' + esc(smtp.host || '') + ':' + Number(smtp.port || 0) + ' · ' + esc((smtp.security || 'tls').toUpperCase()) + '</span>';
                else if (smtp.enabled) statusBox.innerHTML = '<span class="status-badge warning">SMTP включён, но заполнен не полностью</span>';
                else statusBox.innerHTML = '<span class="status-badge neutral">SMTP выключен · используется PHP mail()</span>';
            }
        }
        renderSelfUpdate(data);
    }

    function loadDashboard() {
        fetch('?action=dashboard', { credentials: 'same-origin', headers: { 'Accept': 'application/json' } }).then(function (response) {
            return response.json().then(function (json) {
                if (!response.ok || !json.ok) throw new Error(json.error || ('HTTP ' + response.status));
                return json;
            });
        }).then(function (result) {
            var data = result.data;
            renderJob(data.active_job);
            renderSiteVersion(data.site_version || null);
            renderBackups(data.backups);
            renderHistory(data.history);
            renderJobs(data.jobs || []);
            renderMigrationShare(data.jobs || []);
            renderLogs(data.logs || []);
            renderApiTasks(data.api_tasks || []);
            renderApiConnection(data.api || null);
            renderSelfUpdate(data.self_update || null);
            applySettings(data.settings || {});
            renderDatabaseConnection(data.database_connection || null);
            renderCron(data.cron || null);
            renderDiagnostics(data.site || {});
        }).catch(function (error) {
            notice(error.message, 'error', true);
        });
    }

    function initSidebar() {
        var toggle = byId('menu-toggle');
        var backdrop = byId('sidebar-backdrop');
        if (!toggle || !backdrop) return;
        function close() {
            document.body.classList.remove('sidebar-open');
            toggle.setAttribute('aria-expanded', 'false');
        }
        toggle.addEventListener('click', function () {
            var open = !document.body.classList.contains('sidebar-open');
            document.body.classList.toggle('sidebar-open', open);
            toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        });
        backdrop.addEventListener('click', close);
        document.addEventListener('keydown', function (event) { if (event.key === 'Escape') close(); });
    }

    var login = byId('login-form');
    // Keep login as a normal browser form submit. Native navigation gives
    // password managers the best chance to detect and save username/password.
    if (login) return;

    initSidebar();
    initSiteVersion();
    restoreFlash();
    restoreMigrationResult();

    function setWizard(id, open) {
        var modal = byId(id);
        if (!modal) return;
        modal.classList.toggle('hidden', !open);
        modal.setAttribute('aria-hidden', open ? 'false' : 'true');
        document.body.classList.toggle('wizard-modal-open', open);
        if (open) {
            var focusable = modal.querySelector('input,button,select,textarea');
            if (focusable) window.setTimeout(function () { focusable.focus(); }, 0);
        }
    }

    var openMigrationExport = byId('open-migration-export');
    var openMigrationImport = byId('open-migration-import');
    if (openMigrationExport) openMigrationExport.addEventListener('click', function () { setWizard('migration-export-modal', true); });
    if (openMigrationImport) openMigrationImport.addEventListener('click', function () { setWizard('migration-import-modal', true); });
    all('[data-close-wizard]').forEach(function (node) { node.addEventListener('click', function () { setWizard(node.getAttribute('data-close-wizard'), false); }); });
    document.addEventListener('keydown', function (event) {
        if (event.key !== 'Escape') return;
        ['migration-export-modal','migration-import-modal'].forEach(function (id) { var node = byId(id); if (node && !node.classList.contains('hidden')) setWizard(id, false); });
    });

    var migrationExportForm = byId('migration-export-form');
    if (migrationExportForm) migrationExportForm.addEventListener('submit', function (event) {
        event.preventDefault();
        var form = this;
        var button = form.querySelector('button[type="submit"]');
        if (button) { button.disabled = true; button.textContent = 'Подготавливаем…'; }
        api('start_migration_export', { target_domain: form.elements.target_domain.value }).then(function (result) {
            setWizard('migration-export-modal', false);
            terminalHandled = false; startedAt = Date.now(); renderJob(result.job);
        }).catch(function (error) {
            if (button) { button.disabled = false; button.textContent = 'Создать ссылку для переноса'; }
            notice(error.message, 'error', true);
        });
    });

    var migrationImportForm = byId('migration-import-form');
    if (migrationImportForm) migrationImportForm.addEventListener('submit', function (event) {
        event.preventDefault();
        var form = this;
        var button = form.querySelector('button[type="submit"]');
        if (button) { button.disabled = true; button.textContent = 'Проверяем источник…'; }
        api('start_migration_import', {
            source_url: form.elements.source_url.value,
            db_host: form.elements.db_host.value || 'localhost',
            db_port: form.elements.db_port.value || '3306',
            db_name: form.elements.db_name.value,
            db_user: form.elements.db_user.value,
            db_password: form.elements.db_password.value
        }).then(function (result) {
            setWizard('migration-import-modal', false);
            terminalHandled = false; startedAt = Date.now(); renderJob(result.job);
        }).catch(function (error) {
            if (button) { button.disabled = false; button.textContent = 'Проверить и начать перенос'; }
            notice(error.message, 'error', true);
        });
    });

    var backupButton = byId('backup-btn');
    if (backupButton) {
        backupButton.addEventListener('click', function () {
            backupButton.disabled = true;
            startedAt = Date.now();
            terminalHandled = false;
            api('start_backup', {}).then(function (result) { renderJob(result.job); })
                .catch(function (error) { notice(error.message, 'error'); backupButton.disabled = false; });
        });
    }

    var resumeButton = byId('resume-btn');
    if (resumeButton) resumeButton.addEventListener('click', function () {
        if (currentJob) api('resume', { job_id: currentJob.id }).then(function (result) { renderJob(result.job); }).catch(function (error) { notice(error.message, 'error'); });
    });

    var cancelButton = byId('cancel-btn');
    if (cancelButton) cancelButton.addEventListener('click', function () {
        if (!currentJob || cancelButton.disabled) return;
        if (timer) window.clearTimeout(timer);
        timer = null;
        cancelButton.disabled = true;
        cancelButton.textContent = 'Прерываем…';
        byId('process-message').textContent = 'Запрашиваем безопасное прерывание процесса…';
        api('cancel', { job_id: currentJob.id }).then(function (result) {
            renderJob(result.job);
        }).catch(function (error) {
            byId('process-message').textContent = 'Не удалось прервать процесс: ' + error.message;
            notice(error.message, 'error', true);
            cancelButton.disabled = currentJob && currentJob.cancel_allowed === false;
            if (!cancelButton.disabled) cancelButton.textContent = 'Прервать';
            timer = window.setTimeout(step, 1000);
        });
    });

    var dropzone = byId('dropzone');
    var patchFile = byId('patch-file');
    var validateButton = byId('validate-btn');
    var chosenFile = null;
    if (dropzone && patchFile && validateButton) {
        function choose(file) {
            chosenFile = file || null;
            byId('patch-name').textContent = file ? (file.name + ' · ' + fmt(file.size)) : 'Поддерживается безопасный формат ZIP';
            validateButton.disabled = !file;
            byId('patch-report').innerHTML = '';
            var noticeNode = byId('notice');
            if (noticeNode && noticeNode.classList.contains('with-action')) { noticeNode.className = ''; noticeNode.textContent = ''; }
        }

        function startUploadedPatch(report, force, trigger) {
            if (trigger) { trigger.disabled = true; trigger.textContent = 'Запускаем установку…'; }
            terminalHandled = false;
            api('start_install', { upload_id: report.upload_id, force: force ? '1' : '' }).then(function (response) {
                startedAt = Date.now();
                renderJob(response.job);
            }).catch(function (error) {
                if (trigger) { trigger.disabled = false; trigger.textContent = force ? 'Продолжить всё равно' : 'Установить патч'; }
                notice('Патч не применён: ' + error.message, 'error', true);
            });
        }
        patchFile.addEventListener('change', function () { choose(this.files[0]); });
        ['dragenter', 'dragover'].forEach(function (name) {
            dropzone.addEventListener(name, function (event) { event.preventDefault(); dropzone.classList.add('drag'); });
        });
        ['dragleave', 'drop'].forEach(function (name) {
            dropzone.addEventListener(name, function (event) {
                event.preventDefault();
                dropzone.classList.remove('drag');
                if (name === 'drop' && event.dataTransfer.files[0]) choose(event.dataTransfer.files[0]);
            });
        });
        validateButton.addEventListener('click', function () {
            if (!chosenFile) return;
            validateButton.disabled = true;
            validateButton.textContent = 'Проверяем пакет…';
            api('upload_patch', {}, chosenFile).then(function (result) {
                var report = result.report;
                var manifest = report.manifest;
                if (report.valid) {
                    byId('patch-report').innerHTML = '<div class="report"><div class="report-head"><div><h4>' + esc(manifest.name) + ' · ' + esc(manifest.version) + '</h4><p>' + esc(manifest.description || 'Патч проверен и готов к установке.') + '</p></div><span class="status-badge success">Проверка пройдена</span></div>' +
                        '<div class="report-stats"><span class="report-stat">Файлов: ' + report.files_count + '</span><span class="report-stat">Удалений: ' + report.delete_count + '</span><span class="report-stat">Миграций: ' + report.migration_count + '</span><span class="report-stat">Объём: ' + fmt(report.uncompressed_bytes) + '</span>' + (report.sealed_locally ? '<span class="report-stat">Подпись: локально</span>' : '<span class="report-stat">Подпись: проверена</span>') + '</div>' +
                        '<button class="button primary" id="install-now" type="button">Установить патч</button></div>';
                    validateButton.textContent = 'Патч проверен';
                    byId('install-now').onclick = function () { startUploadedPatch(report, false, this); };
                    return;
                }
                var warnings = Array.isArray(report.validation_warnings) ? report.validation_warnings : [];
                var warningHtml = warnings.map(function (warning) { return '<li>' + esc(warning && warning.message ? warning.message : warning) + '</li>'; }).join('');
                byId('patch-report').innerHTML = '<div class="report warning-report"><div class="report-head"><div><h4>' + esc(manifest.name) + ' · ' + esc(manifest.version) + '</h4><p>Целостность и подпись пакета проверены, но найдены несовпадения совместимости.</p></div><span class="status-badge warning">Есть предупреждение</span></div>' +
                    '<ul class="validation-warning-list">' + warningHtml + '</ul>' +
                    '<div class="report-stats"><span class="report-stat">Файлов: ' + report.files_count + '</span><span class="report-stat">Удалений: ' + report.delete_count + '</span><span class="report-stat">Миграций: ' + report.migration_count + '</span><span class="report-stat">Объём: ' + fmt(report.uncompressed_bytes) + '</span>' + (report.sealed_locally ? '<span class="report-stat">Подпись: локально</span>' : '<span class="report-stat">Подпись: проверена</span>') + '</div></div>';
                validateButton.disabled = false;
                validateButton.textContent = 'Проверить выбранный патч';
                var message = 'Патч не прошёл проверку: ' + (report.validation_error || (warnings[0] && warnings[0].message) || 'обнаружено несовпадение совместимости.');
                noticeAction(message, 'error', 'Продолжить всё равно', function (button) { startUploadedPatch(report, true, button); });
            }).catch(function (error) {
                notice('Патч не прошёл проверку: ' + error.message, 'error', true);
                validateButton.disabled = false;
                validateButton.textContent = 'Проверить выбранный патч';
            });
        });
    }

    var downloadInstaller = byId('download-installer');
    if (downloadInstaller) downloadInstaller.addEventListener('click', function () {
        notice('Скачивается upgrade.php. Загрузите его в корень нужного сайта, откройте https://сайт/upgrade.php и нажмите «Установить». Настройку email и пароля завершите сразу в том же браузере — файл удалится сам.', 'ok', true);
    });
    var checkSelfUpdate = byId('check-self-update');
    var applySelfUpdate = byId('apply-self-update');
    if (checkSelfUpdate) checkSelfUpdate.addEventListener('click', function () {
        checkSelfUpdate.disabled = true;
        var result = byId('self-update-result');
        if (result) result.innerHTML = '<span class="status-badge warning">Проверяем центральный сервер…</span>';
        api('check_self_update', {}).then(function (response) {
            var u = response.update || {};
            if (u.update_available) {
                if (result) result.innerHTML = '<span class="status-badge success">Доступна версия ' + esc(u.latest_version) + '</span><span class="update-inline-note">' + esc(u.notes || 'Новый релиз готов к установке.') + ' · ' + fmt(u.package_bytes || 0) + '</span>';
                if (applySelfUpdate) { applySelfUpdate.classList.remove('hidden'); applySelfUpdate.textContent = 'Обновить до ' + u.latest_version; applySelfUpdate.setAttribute('data-version', u.latest_version || ''); }
            } else {
                if (result) result.innerHTML = '<span class="status-badge success">Установлена актуальная версия ' + esc(u.current_version || '') + '</span>';
                if (applySelfUpdate) applySelfUpdate.classList.add('hidden');
            }
            checkSelfUpdate.disabled = false;
        }).catch(function (error) {
            if (result) result.innerHTML = '<span class="status-badge error">Проверка не выполнена</span><span class="update-inline-note">' + esc(error.message) + '</span>';
            checkSelfUpdate.disabled = false;
        });
    });
    if (applySelfUpdate) applySelfUpdate.addEventListener('click', function () {
        var v = applySelfUpdate.getAttribute('data-version') || 'новую версию';
        if (!window.confirm('Обновить Upgrade Manager до ' + v + '? Локальный config.local.php и секреты сайта будут сохранены.')) return;
        applySelfUpdate.disabled = true;
        checkSelfUpdate.disabled = true;
        var result = byId('self-update-result');
        if (result) result.innerHTML = '<span class="status-badge warning">Скачиваем, проверяем и устанавливаем обновление…</span>';
        api('apply_self_update', {}).then(function (response) {
            var u = response.update || {};
            saveFlash(u.message || 'Upgrade Manager обновлён.', 'ok');
            window.setTimeout(function () { window.location.reload(true); }, 500);
        }).catch(function (error) {
            if (result) result.innerHTML = '<span class="status-badge error">Обновление отменено</span><span class="update-inline-note">' + esc(error.message) + '</span>';
            notice(error.message, 'error', true); applySelfUpdate.disabled = false; checkSelfUpdate.disabled = false;
        });
    });

    function showSelfUpdateProcess(message, progress, logText) {
        if (timer) window.clearTimeout(timer);
        timer = null;
        currentJob = null;
        terminalHandled = false;
        startedAt = Date.now();
        setProcessModal(true);
        var dialog = byId('process') ? byId('process').querySelector('.process-dialog') : null;
        if (dialog) dialog.classList.remove('terminal', 'success', 'error', 'cancelled');
        byId('process-title').textContent = 'Обновление Upgrade Manager';
        byId('process-message').textContent = message || 'Подготавливаем обновление…';
        byId('process-status').textContent = 'Критический процесс';
        byId('progress-bar').style.width = Math.max(0, Math.min(100, Number(progress || 0))) + '%';
        byId('progress-percent').textContent = Math.max(0, Math.min(100, Number(progress || 0))) + '%';
        byId('process-time').textContent = '0 сек.';
        byId('process-log').textContent = logText || 'FTP ZIP проверен. Создаём локальный rollback перед заменой файлов.';
        var cancel = byId('cancel-btn');
        if (cancel) { cancel.disabled = true; cancel.textContent = 'Критический этап…'; }
        var resume = byId('resume-btn'); if (resume) resume.classList.add('hidden');
        var lockNote = byId('process-lock-note');
        if (lockNote) lockNote.textContent = 'Во время замены файлов интерфейс заблокирован. При любой серверной ошибке Upgrade Manager автоматически восстанавливает предыдущую версию.';
    }

    function finishSelfUpdateProcess(success, message) {
        var dialog = byId('process') ? byId('process').querySelector('.process-dialog') : null;
        if (dialog) { dialog.classList.add('terminal'); dialog.classList.toggle('success', !!success); dialog.classList.toggle('error', !success); }
        byId('process-message').textContent = message || (success ? 'Обновление завершено.' : 'Обновление не выполнено.');
        byId('process-status').textContent = success ? 'Завершён' : 'Ошибка';
        byId('progress-bar').style.width = success ? '100%' : '0%';
        byId('progress-percent').textContent = success ? '100%' : 'Ошибка';
        byId('process-time').textContent = Math.round((Date.now() - startedAt) / 1000) + ' сек.';
    }

    var selfUpdateDropzone = byId('self-update-dropzone');
    var selfUpdateFile = byId('self-update-file');
    var selfUpdateReport = byId('manual-self-update-report');
    var selfUpdatePackageName = byId('self-update-package-name');
    if (selfUpdateDropzone && selfUpdateFile && selfUpdateReport) {
        function stageManualSelfUpdate(file) {
            if (!file) return;
            if (!/\.zip$/i.test(file.name || '')) { notice('Для самообновления нужен FTP ZIP Upgrade Manager.', 'error', true); return; }
            selfUpdatePackageName.textContent = (file.name || 'upgrade-manager.zip') + ' · ' + fmt(file.size || 0) + ' · загружаем и проверяем…';
            selfUpdateReport.innerHTML = '<div class="report"><span class="status-badge warning">Проверяем пакет…</span><p class="manual-update-caption">Контроль структуры, версии и SHA-256 всех файлов.</p></div>';
            api('stage_manual_self_update', {}, file, 'package').then(function (response) {
                var pkg = response.package || {};
                selfUpdatePackageName.textContent = (file.name || 'upgrade-manager.zip') + ' · ' + fmt(file.size || 0);
                selfUpdateReport.innerHTML = '<div class="report manual-update-ready"><div class="report-head"><div><h4>Upgrade Manager ' + esc(pkg.version || '') + '</h4><p>' + esc(pkg.notes || 'Полный FTP-пакет проверен и готов к установке.') + '</p></div><span class="status-badge success">Пакет проверен</span></div>' +
                    '<div class="report-stats"><span class="report-stat">Сейчас: ' + esc(pkg.current_version || '') + '</span><span class="report-stat">Новая версия: ' + esc(pkg.version || '') + '</span><span class="report-stat">Файлов: ' + Number(pkg.file_count || 0) + '</span><span class="report-stat">ZIP: ' + fmt(pkg.package_bytes || 0) + '</span></div>' +
                    '<p class="manual-update-safety">Перед заменой файлов автоматически создаётся rollback-копия самого /upgrade/. <strong>config.local.php</strong>, <strong>.data/</strong>, OAuth/MCP-токены и локальные настройки не заменяются.</p>' +
                    '<button class="button primary" id="install-manual-self-update" type="button">Установить ' + esc(pkg.version || 'обновление') + '</button></div>';
                var install = byId('install-manual-self-update');
                if (install) install.addEventListener('click', function () {
                    if (!window.confirm('Обновить Upgrade Manager с ' + (pkg.current_version || 'текущей версии') + ' до ' + (pkg.version || 'новой версии') + '? Перед заменой файлов будет создан автоматический rollback.')) return;
                    install.disabled = true;
                    showSelfUpdateProcess('Создаём страховочную копию и заменяем файлы Upgrade Manager…', 35, 'Пакет прошёл SHA-256 проверку.\nСохраняем текущие управляемые файлы.\nЛокальная конфигурация и .data не затрагиваются.');
                    api('apply_manual_self_update', { stage_id: pkg.stage_id || '' }).then(function (result) {
                        var update = result.update || {};
                        finishSelfUpdateProcess(true, update.message || 'Upgrade Manager успешно обновлён.');
                        saveFlash(update.message || 'Upgrade Manager успешно обновлён.', 'ok');
                        window.setTimeout(function () { window.location.reload(true); }, 650);
                    }).catch(function (error) {
                        finishSelfUpdateProcess(false, error.message);
                        byId('process-log').textContent += '\nОшибка: ' + error.message + '\nСервер выполнил автоматический rollback, если замена файлов уже началась.';
                        notice(error.message, 'error', true);
                        window.setTimeout(function () { setProcessModal(false); }, 1200);
                        install.disabled = false;
                    });
                });
            }).catch(function (error) {
                selfUpdateReport.innerHTML = '<div class="report warning-report"><div class="report-head"><div><h4>FTP ZIP не принят</h4><p>' + esc(error.message) + '</p></div><span class="status-badge error">Ошибка проверки</span></div></div>';
                selfUpdatePackageName.textContent = 'Выберите корректный upgrade-manager-*-ftp.zip';
                notice(error.message, 'error', true);
            });
        }
        selfUpdateFile.addEventListener('change', function () { stageManualSelfUpdate(this.files && this.files[0] ? this.files[0] : null); });
        ['dragenter', 'dragover'].forEach(function (name) { selfUpdateDropzone.addEventListener(name, function (event) { event.preventDefault(); event.stopPropagation(); selfUpdateDropzone.classList.add('drag'); }); });
        ['dragleave', 'drop'].forEach(function (name) { selfUpdateDropzone.addEventListener(name, function (event) { event.preventDefault(); event.stopPropagation(); selfUpdateDropzone.classList.remove('drag'); }); });
        selfUpdateDropzone.addEventListener('drop', function (event) { var files = event.dataTransfer && event.dataTransfer.files; if (files && files[0]) stageManualSelfUpdate(files[0]); });
        selfUpdateDropzone.addEventListener('keydown', function (event) { if ((event.key === 'Enter' || event.key === ' ') && event.target === selfUpdateDropzone) { event.preventDefault(); selfUpdateFile.click(); } });
    }

    var updateSourceForm = byId('update-source-form');
    if (updateSourceForm) updateSourceForm.addEventListener('submit', function (event) {
        event.preventDefault(); var form = this;
        api('save_update_settings', { update_server_url: form.elements.update_server_url.value, update_repository_key: form.elements.update_repository_key.value }).then(function (r) {
            renderUpdateAdmin(r.data || {}); notice('Источник обновлений сохранён.', 'ok', true);
        }).catch(function (error) { notice(error.message, 'error', true); });
    });

    var repositoryForm = byId('repository-form');
    if (repositoryForm) repositoryForm.addEventListener('submit', function (event) {
        event.preventDefault(); var form = this;
        api('save_repository_settings', { repository_enabled: form.elements.repository_enabled.checked ? '1' : '' }).then(function (r) {
            renderUpdateAdmin(r.data || {}); notice(form.elements.repository_enabled.checked ? 'Центральный сервер обновлений включён.' : 'Центральный сервер обновлений выключен.', 'ok', true);
        }).catch(function (error) { notice(error.message, 'error', true); });
    });

    var remotePasswordResetForm = byId('remote-password-reset-form');
    function remoteAccessMode(form) {
        var checked = form.querySelector('input[name="remote_mode"]:checked');
        return checked ? checked.value : 'reset';
    }
    function syncRemoteAccessMode(form) {
        var mode = remoteAccessMode(form), fields = byId('remote-set-fields'), button = byId('remote-access-submit');
        if (fields) fields.classList.toggle('hidden', mode !== 'set');
        if (button) button.textContent = mode === 'set' ? 'Установить email и пароль' : 'Сбросить пароль';
        ['email', 'password', 'password_confirm'].forEach(function (name) { if (form.elements[name]) form.elements[name].required = mode === 'set'; });
    }
    if (remotePasswordResetForm) {
        Array.prototype.forEach.call(remotePasswordResetForm.querySelectorAll('input[name="remote_mode"]'), function (radio) {
            radio.addEventListener('change', function () { syncRemoteAccessMode(remotePasswordResetForm); });
        });
        syncRemoteAccessMode(remotePasswordResetForm);
    }
    function remoteCopyField(id, label, value, note) {
        return '<div class="field"><label>' + esc(label) + '</label><div class="copy-row"><input id="' + id + '" readonly value="' + esc(value || '') + '"><button class="button secondary small" type="button" id="copy-' + id + '">Копировать</button></div>' + (note ? '<small>' + esc(note) + '</small>' : '') + '</div>';
    }
    function bindRemoteCopy(ids) {
        ids.forEach(function (id) { var b = byId('copy-' + id); if (b) b.addEventListener('click', function () { copyInput(id); }); });
    }
    if (remotePasswordResetForm) remotePasswordResetForm.addEventListener('submit', function (event) {
        event.preventDefault(); var form = this, button = byId('remote-access-submit') || form.querySelector('button[type="submit"]'), result = byId('remote-password-reset-result');
        var domain = (form.elements.domain.value || '').trim(); if (!domain) return;
        var mode = remoteAccessMode(form), idleText = mode === 'set' ? 'Установить email и пароль' : 'Сбросить пароль';
        var request;
        if (mode === 'set') {
            var email = (form.elements.email.value || '').trim(), password = form.elements.password.value || '', confirmPassword = form.elements.password_confirm.value || '';
            if (!email) { notice('Укажите email администратора для клиентского сайта.', 'error', true); return; }
            if (password.length < 8 || !/[a-z]/.test(password) || !/[A-Z]/.test(password) || !/[0-9]/.test(password)) { notice('Пароль: минимум 8 символов, латинская строчная и заглавная буква и цифра.', 'error', true); return; }
            if (password !== confirmPassword) { notice('Пароли не совпадают.', 'error', true); return; }
            if (!window.confirm('Установить на ' + domain + ' логин ' + email + ' и заданный пароль?\n\nПрежние логин и пароль этого сайта перестанут работать, все текущие сессии будут отключены.')) return;
            request = api('remote_set_credentials', { domain: domain, email: email, password: password, password_confirm: confirmPassword }).then(function (response) {
                var r = response.credentials || {};
                form.elements.password.value = ''; form.elements.password_confirm.value = '';
                if (result) result.innerHTML = '<div class="report"><div class="report-head"><div><h4>Логин и пароль установлены · ' + esc(r.domain || domain) + '</h4><p>Все старые сессии отозваны. Вход — по email и паролю, которые вы задали; менять их при входе не потребуется.</p></div><span class="status-badge success">Готово</span></div>' +
                    remoteCopyField('remote-set-username', 'Логин (email)', r.username || email, 'Этот email также используется для Recovery и восстановления пароля на клиентском сайте.') + '</div>';
                bindRemoteCopy(['remote-set-username']);
                notice('На ' + (r.domain || domain) + ' установлены логин ' + (r.username || email) + ' и новый пароль.', 'ok', true);
            });
        } else {
            if (!window.confirm('Сбросить пароль Upgrade Manager на ' + domain + '? Все текущие сессии этого сайта будут немедленно отключены.')) return;
            request = api('remote_reset_password', { domain: domain }).then(function (response) {
                var r = response.reset || {};
                if (result) result.innerHTML = '<div class="report"><div class="report-head"><div><h4>Пароль сброшен · ' + esc(r.domain || domain) + '</h4><p>Все старые сессии отозваны. Временный пароль показывается только в этом ответе.</p></div><span class="status-badge success">Готово</span></div>' +
                    remoteCopyField('remote-reset-username', 'Логин', r.username, '') +
                    remoteCopyField('remote-reset-password', 'Временный пароль', r.temporary_password, 'После первого входа клиент принудительно откроет раздел «Безопасность» и потребует постоянный пароль.') + '</div>';
                bindRemoteCopy(['remote-reset-username', 'remote-reset-password']);
                notice('Пароль на ' + (r.domain || domain) + ' сброшен. Скопируйте временный пароль сейчас.', 'ok', true);
            });
        }
        if (button) { button.disabled = true; button.textContent = mode === 'set' ? 'Устанавливаем…' : 'Сбрасываем…'; }
        if (result) result.innerHTML = '<div class="report"><span class="status-badge warning">Подписываем одноразовую команду…</span></div>';
        request.catch(function (error) {
            if (result) result.innerHTML = '<div class="report warning-report"><div class="report-head"><div><h4>Операция не выполнена</h4><p>' + esc(error.message) + '</p></div><span class="status-badge error">Ошибка</span></div></div>';
            notice(error.message, 'error', true);
        }).finally(function () { if (button) { button.disabled = false; button.textContent = idleText; } });
    });

    var smtpSettingsForm = byId('smtp-settings-form');
    function smtpPayload(form) {
        return {
            smtp_enabled: form.elements.smtp_enabled.checked ? '1' : '',
            smtp_host: form.elements.smtp_host.value,
            smtp_port: form.elements.smtp_port.value,
            smtp_security: form.elements.smtp_security.value,
            smtp_username: form.elements.smtp_username.value,
            smtp_password: form.elements.smtp_password.value,
            smtp_from_email: form.elements.smtp_from_email.value,
            smtp_from_name: form.elements.smtp_from_name.value
        };
    }
    function saveSmtpForm(form) {
        return api('save_smtp_settings', smtpPayload(form)).then(function (response) {
            var smtp = response.smtp || {};
            form.elements.smtp_password.value = '';
            form.elements.smtp_password.placeholder = smtp.password_configured ? 'Пароль уже сохранён — оставьте пустым, чтобы не менять' : 'SMTP-пароль';
            var note = byId('smtp-password-note'); if (note) note.textContent = smtp.password_configured ? 'Пароль хранится зашифрованно. Оставьте поле пустым, чтобы сохранить текущий.' : 'Оставьте пустым, если SMTP-сервер не требует авторизацию.';
            return smtp;
        });
    }
    if (smtpSettingsForm) smtpSettingsForm.addEventListener('submit', function (event) {
        event.preventDefault(); var form = this;
        saveSmtpForm(form).then(function () {
            notice('SMTP-настройки сохранены.', 'ok', true);
            return api('self_update_status', {});
        }).then(function (r) { renderUpdateAdmin(r.data || {}); }).catch(function (error) { notice(error.message, 'error', true); });
    });
    var smtpTestConnection = byId('smtp-test-connection');
    if (smtpTestConnection && smtpSettingsForm) smtpTestConnection.addEventListener('click', function () {
        var button = this, statusBox = byId('smtp-status-box'); button.disabled = true; button.textContent = 'Проверяем…';
        saveSmtpForm(smtpSettingsForm).then(function () {
            if (statusBox) statusBox.innerHTML = '<span class="status-badge warning">Подключаемся к SMTP…</span>';
            return api('test_smtp_connection', {});
        }).then(function (response) {
            var t = response.smtp_test || {};
            if (statusBox) statusBox.innerHTML = '<span class="status-badge success">Подключение успешно</span><span class="update-inline-note">' + esc(t.host || '') + ':' + Number(t.port || 0) + ' · ' + esc((t.security || '').toUpperCase()) + (t.authenticated ? ' · AUTH OK' : ' · без AUTH') + '</span>';
            notice(t.message || 'SMTP-подключение успешно проверено.', 'ok', true);
        }).catch(function (error) {
            if (statusBox) statusBox.innerHTML = '<span class="status-badge error">Ошибка SMTP</span><span class="update-inline-note">' + esc(error.message) + '</span>';
            notice(error.message, 'error', true);
        }).finally(function () { button.disabled = false; button.textContent = 'Проверить подключение'; });
    });
    var smtpSendTest = byId('smtp-send-test');
    if (smtpSendTest && smtpSettingsForm) smtpSendTest.addEventListener('click', function () {
        var button = this, statusBox = byId('smtp-status-box'), target = (smtpSettingsForm.elements.smtp_test_email.value || '').trim();
        if (!target) { notice('Укажите email для тестового письма.', 'error', true); return; }
        button.disabled = true; button.textContent = 'Отправляем…';
        saveSmtpForm(smtpSettingsForm).then(function () {
            if (statusBox) statusBox.innerHTML = '<span class="status-badge warning">Отправляем тестовое письмо…</span>';
            return api('send_smtp_test', { email: target });
        }).then(function (response) {
            var t = response.smtp_test || {};
            if (statusBox) statusBox.innerHTML = '<span class="status-badge success">Тестовое письмо принято SMTP</span><span class="update-inline-note">' + esc(target) + '</span>';
            notice(t.message || ('Тестовое письмо отправлено на ' + target + '.'), 'ok', true);
        }).catch(function (error) {
            if (statusBox) statusBox.innerHTML = '<span class="status-badge error">Письмо не отправлено</span><span class="update-inline-note">' + esc(error.message) + '</span>';
            notice(error.message, 'error', true);
        }).finally(function () { button.disabled = false; button.textContent = 'Отправить тестовое письмо'; });
    });

    function copyInput(id) {
        var input = byId(id); if (!input || !input.value) return;
        function done() { notice('Скопировано.', 'ok'); }
        if (navigator.clipboard && navigator.clipboard.writeText) navigator.clipboard.writeText(input.value).then(done).catch(function () { input.select(); document.execCommand('copy'); done(); });
        else { input.select(); document.execCommand('copy'); done(); }
    }
    var copyFeed = byId('copy-feed-url'); if (copyFeed) copyFeed.addEventListener('click', function () { copyInput('repository-feed-url'); });
    var copyRepoKey = byId('copy-repository-key'); if (copyRepoKey) copyRepoKey.addEventListener('click', function () { copyInput('repository-key'); });

    if (updateSourceForm || repositoryForm || remotePasswordResetForm || smtpSettingsForm) api('self_update_status', {}).then(function (r) { renderUpdateAdmin(r.data || {}); }).catch(function (error) { notice(error.message, 'error', true); });

    var adminEmailForm = byId('admin-email-form');
    if (adminEmailForm) adminEmailForm.addEventListener('submit', function (event) {
        event.preventDefault();
        var form = this;
        api('change_admin_email', { current_password: form.elements.current_password.value, admin_email: form.elements.admin_email.value })
            .then(function (response) {
                if (response && response.csrf) csrf = response.csrf;
                notice('Email администратора сохранён. Он теперь используется как логин и для Recovery.', 'ok', true);
                form.elements.current_password.value = '';
                window.setTimeout(function () { window.location.reload(); }, 700);
            })
            .catch(function (error) { notice(error.message, 'error', true); });
    });

    var passwordForm = byId('password-form');
    if (passwordForm) passwordForm.addEventListener('submit', function (event) {
        event.preventDefault();
        var form = this;
        api('change_password', { current_password: form.elements.current_password.value, new_password: form.elements.new_password.value })
            .then(function (response) {
                if (response && response.csrf) csrf = response.csrf;
                notice('Пароль администратора изменён. Старые сессии отключены.', 'ok', true);
                form.reset();
            })
            .catch(function (error) { notice(error.message, 'error', true); });
    });

    var databaseConnectionForm = byId('database-connection-form');
    function databaseConnectionPayload(form) {
        return {
            database_connection_mode: selectedDatabaseConnectionMode(form),
            database_config_path: form.elements.database_config_path ? form.elements.database_config_path.value : '',
            manual_db_host: form.elements.manual_db_host.value,
            manual_db_port: form.elements.manual_db_port.value,
            manual_db_name: form.elements.manual_db_name.value,
            manual_db_user: form.elements.manual_db_user.value,
            manual_db_password: form.elements.manual_db_password.value,
            manual_db_clear_password: form.elements.manual_db_clear_password.checked ? '1' : '',
            manual_db_prefix: form.elements.manual_db_prefix.value
        };
    }
    if (databaseConnectionForm) {
        var dbModeInputs = databaseConnectionForm.querySelectorAll('input[name="database_connection_mode"]');
        Array.prototype.forEach.call(dbModeInputs, function (input) { input.addEventListener('change', function () { toggleDatabaseConnectionFields(databaseConnectionForm); }); });
        databaseConnectionForm.addEventListener('submit', function (event) {
            event.preventDefault();var form=this, button=form.querySelector('button[type="submit"]');if(button){button.disabled=true;button.textContent='Сохраняем…';}
            api('save_database_connection', databaseConnectionPayload(form)).then(function (result) { renderDatabaseConnection(result.database_connection || {});notice('Настройки подключения к БД сохранены.', 'ok', true); }).catch(function (error) { notice(error.message, 'error', true); }).finally(function () { if(button){button.disabled=false;button.textContent='Сохранить подключение';} });
        });
        var dbTestButton = byId('test-database-connection');
        if (dbTestButton) dbTestButton.addEventListener('click', function () {
            var button=this, status=byId('database-connection-status');button.disabled=true;button.textContent='Проверяем…';
            api('test_database_connection', databaseConnectionPayload(databaseConnectionForm)).then(function (result) { var t=result.database_test||{};if(status)status.innerHTML='<span class="status-badge success">Подключение успешно</span><span class="update-inline-note">' + esc(t.host||'') + ':' + Number(t.port||3306) + ' · ' + esc(t.database||'') + (t.server_version ? ' · MySQL/MariaDB ' + esc(t.server_version) : '') + '</span>';notice('Подключение к БД успешно.', 'ok', false); }).catch(function (error) { if(status)status.innerHTML='<span class="status-badge error">Ошибка подключения</span><span class="update-inline-note">' + esc(error.message) + '</span>';notice(error.message, 'error', true); }).finally(function () { button.disabled=false;button.textContent='Проверить подключение'; });
        });
    }

    var settingsForm = byId('settings-form');
    if (settingsForm) settingsForm.addEventListener('submit', function (event) {
        event.preventDefault();
        var form = this;
        if (form.elements.file_mode.value === 'none' && form.elements.database_mode.value === 'none') {
            notice('Выберите хотя бы файлы или БД — пустой бэкап создать нельзя.', 'error', true);
            return;
        }
        api('save_settings', {
            file_mode: form.elements.file_mode.value,
            database_mode: form.elements.database_mode.value,
            backup_retention: form.elements.backup_retention.value,
            log_retention_days: form.elements.log_retention_days.value
        }).then(function (result) {
            applySettings(result.settings || {});
            notice('Настройки резервного копирования сохранены.', 'ok', true);
        }).catch(function (error) { notice(error.message, 'error', true); });
    });

    var themeToggle = byId('ui-theme-toggle');
    if (themeToggle) themeToggle.addEventListener('change', function () {
        var toggle = this;
        var previous = toggle.checked ? 'light' : 'dark';
        var selected = toggle.checked ? 'dark' : 'light';
        applyUiTheme(selected);
        toggle.disabled = true;
        api('save_settings', { ui_theme: selected }).then(function (result) {
            applySettings(result.settings || {});
            toggle.disabled = false;
        }).catch(function (error) {
            applyUiTheme(previous);
            toggle.disabled = false;
            notice(error.message, 'error', true);
        });
    });

    var cronSettingsForm = byId('cron-settings-form');
    if (cronSettingsForm) {
        var cronEnabled = cronSettingsForm.elements.auto_backup_enabled;
        var cronFrequency = cronSettingsForm.elements.auto_backup_frequency;
        if (cronEnabled) cronEnabled.addEventListener('change', function () { if (cronFrequency) cronFrequency.disabled = !this.checked; });
        cronSettingsForm.addEventListener('submit', function (event) {
            event.preventDefault();
            var form = this;
            api('save_cron_settings', {
                auto_backup_enabled: form.elements.auto_backup_enabled.checked ? '1' : '',
                auto_backup_frequency: form.elements.auto_backup_frequency.value
            }).then(function (result) {
                renderCron(result.cron || {});
                if (result.cron && result.cron.enabled && !result.cron.active) notice('Настройка сохранена, но сервер не разрешил автоматически создать системный cron: ' + (result.cron.cron_error || result.cron.system_cron_reason || 'неизвестная причина'), 'error', true);
                else notice(result.cron && result.cron.enabled ? 'Автоматический cron резервного копирования настроен.' : 'Автоматическое резервное копирование отключено.', 'ok', true);
            }).catch(function (error) { notice(error.message, 'error', true); });
        });
    }

    var reinstallCron = byId('reinstall-cron');
    if (reinstallCron) reinstallCron.addEventListener('click', function () {
        var button = this;button.disabled = true;
        api('reinstall_cron', {}).then(function (result) {
            renderCron(result.cron || {});
            if (result.cron && result.cron.active) notice('Системная cron-задача переустановлена.', 'ok', true);
            else notice((result.cron && (result.cron.cron_error || result.cron.system_cron_reason)) || 'Не удалось установить cron.', 'error', true);
        }).catch(function (error) { notice(error.message, 'error', true); }).then(function () { button.disabled = false; }, function () { button.disabled = false; });
    });

    var exclusionsForm = byId('backup-exclusions-form');
    var exclusionsTextarea = byId('backup-exclusions');
    if (exclusionsTextarea) {
        exclusionsTextarea.addEventListener('input', updateExclusionLines);
        exclusionsTextarea.addEventListener('scroll', updateExclusionLines);
        updateExclusionLines();
    }
    if (exclusionsForm) exclusionsForm.addEventListener('submit', function (event) {
        event.preventDefault();
        var form = this;
        api('save_backup_exclusions', { backup_exclusions: form.elements.backup_exclusions.value }).then(function (result) {
            applySettings(result.settings || {});
            notice('Исключения для всех бэкапов сохранены.', 'ok', true);
        }).catch(function (error) { notice(error.message, 'error', true); });
    });

    var apiSettingsForm = byId('api-settings-form');
    if (apiSettingsForm) apiSettingsForm.addEventListener('submit', function (event) {
        event.preventDefault();
        var form = this;
        api('save_api_settings', {
            enabled: form.elements.enabled.checked ? '1' : '',
            patch_upload_mode: form.elements.patch_upload_mode ? form.elements.patch_upload_mode.value : 'confirm',
            patch_apply_mode: form.elements.patch_apply_mode ? form.elements.patch_apply_mode.value : 'confirm',
            require_backup_before_install: form.elements.require_backup_before_install.checked ? '1' : '',
            auto_rollback: '1'
        }).then(function () {
            notice('Настройки прямого API-доступа сохранены.', 'ok', true);
            loadDashboard();
        }).catch(function (error) { notice(error.message, 'error', true); });
    });

    var copyMcp = byId('copy-mcp-url');
    if (copyMcp) copyMcp.addEventListener('click', function () {
        var input = byId('mcp-url');
        if (!input || !input.value) return;
        function done() { notice('MCP-адрес скопирован.', 'ok'); }
        if (navigator.clipboard && navigator.clipboard.writeText) navigator.clipboard.writeText(input.value).then(done).catch(function () { input.select(); document.execCommand('copy'); done(); });
        else { input.select(); document.execCommand('copy'); done(); }
    });

    var revokeApi = byId('revoke-api-tokens');
    if (revokeApi) revokeApi.addEventListener('click', function () {
        if (!window.confirm('Отключить все текущие подключения ChatGPT к этому сайту? Для повторной работы потребуется новая OAuth-авторизация.')) return;
        revokeApi.disabled = true;
        api('revoke_api_tokens', {}).then(function () { notice('Все подключения ChatGPT отключены.', 'ok', true); loadDashboard(); revokeApi.disabled = false; })
            .catch(function (error) { notice(error.message, 'error', true); revokeApi.disabled = false; });
    });

    document.addEventListener('click', function (event) {
        var target = event.target;
        if (target.classList.contains('backup-url')) {
            var backupUrlId = target.getAttribute('data-id') || '';
            if (!backupUrlId) {
                notice('Не удалось создать публичную ссылку на этот бэкап.', 'error', true);
                return;
            }
            var directLink = document.createElement('a');
            directLink.href = './public-backup.php?id=' + encodeURIComponent(backupUrlId);
            var directUrl = directLink.href;
            copyText(directUrl).then(function () {
                notice('Публичная очищенная ссылка скопирована. Вход и токен не нужны: ChatGPT увидит безопасную копию без секретов/runtime и сможет скачать её ZIP.', 'ok');
            }).catch(function (error) {
                notice(error.message || 'Не удалось скопировать URL бэкапа.', 'error', true);
            });
            return;
        }
        if (target.classList.contains('api-task-approve-rollback')) {
            var rollbackTaskId = target.getAttribute('data-task-id') || '';
            var rollbackPatchId = target.getAttribute('data-patch-id') || '';
            if (!rollbackTaskId || !rollbackPatchId) return;
            if (!window.confirm('Разрешить откат патча ' + rollbackPatchId + ' для API-задачи ' + rollbackTaskId + '?\n\nФайлы и таблицы будут восстановлены из rollback-пакета. Действие изменяет данные сайта.')) return;
            target.disabled = true;
            api('approve_api_rollback', { task_id: rollbackTaskId, patch_id: rollbackPatchId }).then(function () {
                notice('Откат разрешён. Сообщите ChatGPT, что можно повторить start_patch_rollback.', 'ok');loadDashboard();
            }).catch(function (error) { target.disabled = false; notice(error.message, 'error', true); });
            return;
        }
        if (target.classList.contains('api-task-approve')) {
            var approveTaskId = target.getAttribute('data-task-id') || '';
            if (!approveTaskId) return;
            if (!window.confirm('Разрешить установку проверенного патча из API-задачи ' + approveTaskId + '?\n\nПеред установкой Upgrade Manager сделает бэкап (если он включён в правилах API) и сохранит rollback-пакет. После разрешения ChatGPT сможет запустить deployment.')) return;
            target.disabled = true;
            api('approve_api_task', { task_id: approveTaskId }).then(function () {
                notice('Установка разрешена. Сообщите ChatGPT, что можно продолжать deployment.', 'ok');
                loadDashboard();
            }).catch(function (error) { target.disabled = false; notice(error.message, 'error', true); });
            return;
        }
        if (target.classList.contains('backup-restore')) {
            var backupId = target.getAttribute('data-id') || '';
            var fileMode = target.getAttribute('data-file-mode') || 'code_cms';
            var databaseMode = target.getAttribute('data-db-mode') || 'minimal';
            var legacyMode = target.getAttribute('data-legacy-mode') || '';
            var databaseIncluded = target.getAttribute('data-db-included') !== '0';
            var fileWarning = fileMode === 'none' ? 'Файлы изменены не будут.' : fileMode === 'all' ? (legacyMode === 'complete' ? 'Старый полный формат восстановит код и изображения из снимка; другие несохранённые типы файлов не затрагиваются.' : 'Будут восстановлены все файлы в области снимка; лишние файлы этой области будут удалены.') : fileMode === 'necessary' ? 'Будут восстановлены только нужные проектные файлы без распознанного ядра CMS; ядро CMS не затрагивается.' : 'Будут восстановлены кодовые файлы вместе с ядром CMS; лишние кодовые файлы этой области будут удалены.';
            var dbWarning = !databaseIncluded ? 'В этом архиве database.sql отсутствует — база данных изменена не будет.' : databaseMode === 'none' ? 'База данных изменена не будет.' : databaseMode === 'schema' ? 'ВНИМАНИЕ: таблицы БД из этой копии будут восстановлены пустыми — текущие данные этих таблиц будут удалены.' : databaseMode === 'minimal' ? 'Будет восстановлена сохранённая минимальная часть БД; таблицы, данные которых намеренно не выгружались, не очищаются.' : 'База данных будет полностью восстановлена до состояния снимка.';
            var warning = 'Откатить сайт к резервной копии ' + backupId + '?\n\n' + fileWarning + '\n' + dbWarning + '\n\nФайлы: ' + fileModeInfo(fileMode).label + '. БД: ' + databaseModeInfo(databaseMode).label + '.';
            if (window.confirm(warning)) {
                terminalHandled = false;
                api('start_backup_restore', { backup_id: backupId }).then(function (result) {
                    startedAt = Date.now();
                    renderJob(result.job);
                }).catch(function (error) { notice(error.message, 'error', true); });
            }
        }

        if (target.classList.contains('rollback')) {
            if (window.confirm('Откатить патч ' + target.getAttribute('data-id') + '?')) {
                terminalHandled = false;
                api('start_rollback', { patch_id: target.getAttribute('data-id') }).then(function (result) {
                    startedAt = Date.now();
                    renderJob(result.job);
                }).catch(function (error) { notice(error.message, 'error', true); });
            }
        }

        if (target.classList.contains('bulk-delete')) {
            var type = target.getAttribute('data-type');
            var table = type === 'backup' ? 'backups' : type === 'log' ? 'logs' : type === 'history' ? 'history' : 'jobs';
            var ids = all('.row-check[data-table="' + table + '"]:checked').map(function (node) { return node.value; });
            if (!ids.length) { notice('Сначала выберите хотя бы одну строку.', 'error'); return; }
            if (window.confirm('Удалить выбранные записи? Это действие нельзя отменить.')) {
                api('bulk_delete', { type: type, ids: JSON.stringify(ids) }).then(function (result) {
                    saveFlash('Удалено записей: ' + result.deleted + '.', 'ok');
                    window.location.reload();
                }).catch(function (error) { notice(error.message, 'error', true); });
            }
        }
    });

    document.addEventListener('change', function (event) {
        if (event.target.classList.contains('select-all')) {
            all('.row-check[data-table="' + event.target.getAttribute('data-table') + '"]').forEach(function (node) { node.checked = event.target.checked; });
        }
    });

    loadDashboard();
}());
