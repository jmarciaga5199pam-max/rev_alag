/* ============================================
   AlagApp Clinic - Shared Toast & Confirm
   In-app notifications replacing native
   alert() / confirm() across all dashboards
   ============================================ */

(function () {
    'use strict';

    // ---- Toast Container ----
    function ensureToastContainer() {
        var container = document.getElementById('appToastContainer');
        if (!container) {
            container = document.createElement('div');
            container.id = 'appToastContainer';
            document.body.appendChild(container);
        }
        return container;
    }

    // SVG icons per toast type
    var TOAST_ICONS = {
        success: '<svg class="toast-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg>',
        error: '<svg class="toast-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><line x1="15" y1="9" x2="9" y2="15"></line><line x1="9" y1="9" x2="15" y2="15"></line></svg>',
        info: '<svg class="toast-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="16" x2="12" y2="12"></line><line x1="12" y1="8" x2="12.01" y2="8"></line></svg>',
        warning: '<svg class="toast-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path><line x1="12" y1="9" x2="12" y2="13"></line><line x1="12" y1="17" x2="12.01" y2="17"></line></svg>'
    };

    /**
     * Show an in-app toast notification.
     * @param {string} message - Text to display
     * @param {string} [type='success'] - 'success' | 'error' | 'info' | 'warning'
     * @param {number} [duration=3500] - Milliseconds before auto-dismiss (0 = sticky)
     */
    function showToast(message, type, duration) {
        type = type || 'success';
        if (!TOAST_ICONS[type]) type = 'info';
        duration = typeof duration === 'number' ? duration : 3500;

        var container = ensureToastContainer();
        var toast = document.createElement('div');
        toast.className = 'app-toast ' + type;
        toast.setAttribute('role', type === 'error' ? 'alert' : 'status');
        toast.setAttribute('aria-live', type === 'error' ? 'assertive' : 'polite');

        var safeMsg = String(message == null ? '' : message)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');

        toast.innerHTML = TOAST_ICONS[type]
            + '<span class="toast-msg" style="flex:1">' + safeMsg + '</span>'
            + '<button class="toast-close" aria-label="Close">&times;</button>';

        container.appendChild(toast);

        // Trigger show transition
        requestAnimationFrame(function () {
            toast.classList.add('show');
        });

        var dismissed = false;
        function dismiss() {
            if (dismissed) return;
            dismissed = true;
            toast.classList.remove('show');
            setTimeout(function () {
                if (toast.parentNode) toast.parentNode.removeChild(toast);
            }, 400);
        }

        toast.querySelector('.toast-close').addEventListener('click', dismiss);

        if (duration > 0) {
            setTimeout(dismiss, duration);
        }

        return { dismiss: dismiss, element: toast };
    }

    /**
     * Show a styled confirmation modal. Returns a Promise<boolean>.
     * Also supports callback style: appConfirm(title, msg, function(ok){ ... })
     * @param {string} title - Modal title
     * @param {string} message - Modal message
     * @param {Function|object} [cbOrOptions] - callback(ok) OR options object
     * @param {object} [options] - { confirmText, cancelText, primary }
     */
    function appConfirm(title, message, cbOrOptions, options) {
        var cb = typeof cbOrOptions === 'function' ? cbOrOptions : null;
        var opts = (options || (typeof cbOrOptions === 'object' ? cbOrOptions : {})) || {};
        var confirmText = opts.confirmText || 'Confirm';
        var cancelText = opts.cancelText || 'Cancel';
        var primary = opts.primary === true;

        return new Promise(function (resolve) {
            var backdrop = document.createElement('div');
            backdrop.className = 'app-confirm-backdrop';

            var safeTitle = String(title || '').replace(/</g, '&lt;');
            var safeMsg = String(message || '').replace(/</g, '&lt;');

            backdrop.innerHTML =
                '<div class="app-confirm-box" role="dialog" aria-modal="true" aria-labelledby="appConfirmTitle">' +
                    '<div class="app-confirm-title" id="appConfirmTitle">' + safeTitle + '</div>' +
                    '<div class="app-confirm-message">' + safeMsg + '</div>' +
                    '<div class="app-confirm-actions">' +
                        '<button type="button" class="app-confirm-btn cancel" data-action="cancel">' + cancelText + '</button>' +
                        '<button type="button" class="app-confirm-btn confirm' + (primary ? ' primary' : '') + '" data-action="confirm">' + confirmText + '</button>' +
                    '</div>' +
                '</div>';

            document.body.appendChild(backdrop);
            requestAnimationFrame(function () { backdrop.classList.add('show'); });

            function close(result) {
                backdrop.classList.remove('show');
                setTimeout(function () {
                    if (backdrop.parentNode) backdrop.parentNode.removeChild(backdrop);
                    document.removeEventListener('keydown', onKey);
                }, 250);
                if (cb) { try { cb(result); } catch (e) {} }
                resolve(result);
            }

            function onKey(e) {
                if (e.key === 'Escape') close(false);
                else if (e.key === 'Enter') close(true);
            }
            document.addEventListener('keydown', onKey);

            backdrop.addEventListener('click', function (e) {
                if (e.target === backdrop) close(false);
                var btn = e.target.closest('[data-action]');
                if (!btn) return;
                close(btn.getAttribute('data-action') === 'confirm');
            });

            // Focus confirm button for keyboard users
            setTimeout(function () {
                var confirmBtn = backdrop.querySelector('[data-action="confirm"]');
                if (confirmBtn) confirmBtn.focus();
            }, 50);
        });
    }

    /**
     * Show a styled prompt modal with a text input. Returns a Promise<string|null>.
     * Also supports callback style: appPrompt(title, msg, function(value){ ... })
     */
    function appPrompt(title, message, cbOrOptions, options) {
        var cb = typeof cbOrOptions === 'function' ? cbOrOptions : null;
        var opts = (options || (typeof cbOrOptions === 'object' ? cbOrOptions : {})) || {};
        var confirmText = opts.confirmText || 'Submit';
        var cancelText = opts.cancelText || 'Cancel';
        var placeholder = opts.placeholder || '';
        var defaultValue = opts.defaultValue || '';
        var multiline = opts.multiline === true;
        var required = opts.required !== false;

        return new Promise(function (resolve) {
            var backdrop = document.createElement('div');
            backdrop.className = 'app-confirm-backdrop';

            function esc(s) {
                return String(s == null ? '' : s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
            }

            var inputHtml = multiline
                ? '<textarea class="app-prompt-input" rows="3" placeholder="' + esc(placeholder) + '">' + esc(defaultValue) + '</textarea>'
                : '<input type="text" class="app-prompt-input" placeholder="' + esc(placeholder) + '" value="' + esc(defaultValue) + '">';

            backdrop.innerHTML =
                '<div class="app-confirm-box" role="dialog" aria-modal="true" aria-labelledby="appPromptTitle">' +
                    '<div class="app-confirm-title" id="appPromptTitle">' + esc(title) + '</div>' +
                    '<div class="app-confirm-message">' + esc(message) + '</div>' +
                    '<div class="app-prompt-input-wrap" style="margin:12px 0;">' + inputHtml + '</div>' +
                    '<div class="app-confirm-actions">' +
                        '<button type="button" class="app-confirm-btn cancel" data-action="cancel">' + esc(cancelText) + '</button>' +
                        '<button type="button" class="app-confirm-btn confirm primary" data-action="confirm">' + esc(confirmText) + '</button>' +
                    '</div>' +
                '</div>';

            document.body.appendChild(backdrop);
            requestAnimationFrame(function () { backdrop.classList.add('show'); });

            var input = backdrop.querySelector('.app-prompt-input');

            function close(result) {
                backdrop.classList.remove('show');
                setTimeout(function () {
                    if (backdrop.parentNode) backdrop.parentNode.removeChild(backdrop);
                    document.removeEventListener('keydown', onKey);
                }, 250);
                if (cb) { try { cb(result); } catch (e) {} }
                resolve(result);
            }

            function submitValue() {
                var val = (input.value || '').trim();
                if (required && !val) {
                    input.focus();
                    return;
                }
                close(val);
            }

            function onKey(e) {
                if (e.key === 'Escape') close(null);
                else if (e.key === 'Enter' && !multiline) { e.preventDefault(); submitValue(); }
            }
            document.addEventListener('keydown', onKey);

            backdrop.addEventListener('click', function (e) {
                if (e.target === backdrop) { close(null); return; }
                var btn = e.target.closest('[data-action]');
                if (!btn) return;
                if (btn.getAttribute('data-action') === 'confirm') submitValue();
                else close(null);
            });

            setTimeout(function () { if (input) input.focus(); }, 60);
        });
    }

    // Expose globally
    window.showToast = showToast;
    window.appConfirm = appConfirm;
    window.appPrompt = appPrompt;

    // Backward-compatible wrapper: existing showNotification(msg, type) calls
    // should route to the toast system when the legacy #notification element
    // is missing. If a page defines its own showNotification, we don't override.
    if (typeof window.showNotification !== 'function') {
        window.showNotification = function (message, type) {
            // If a legacy element exists, use it; otherwise use toast.
            var legacy = document.getElementById('notification');
            if (legacy) {
                legacy.textContent = message;
                legacy.className = 'notification ' + (type || 'success') + ' show';
                setTimeout(function () { legacy.classList.remove('show'); }, 3000);
                return;
            }
            showToast(message, type || 'success');
        };
    }
})();
