/* Notification bell widget — used by parent, doctor, and admin dashboards.
   Renders into #notificationBellMount placed in each dashboard's top bar. */

(function () {
    function mount() {
        var mountEl = document.getElementById('notificationBellMount');
        if (!mountEl || mountEl.dataset.mounted === '1') return;
        mountEl.dataset.mounted = '1';

        mountEl.innerHTML =
            '<div class="relative inline-block">' +
                '<button type="button" id="notifBellBtn" aria-label="Notifications"' +
                ' class="relative p-2 rounded-full hover:bg-gray-100 text-gray-600 focus:outline-none focus:ring-2 focus:ring-primary">' +
                    '<svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">' +
                        '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"' +
                        ' d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/>' +
                    '</svg>' +
                    '<span id="notifBellBadge" class="hidden absolute -top-1 -right-1 bg-red-500 text-white text-xs font-bold rounded-full px-1.5 py-0.5 min-w-[18px] text-center">0</span>' +
                '</button>' +
                '<div id="notifBellPanel" class="hidden absolute right-0 mt-2 w-80 max-h-96 overflow-y-auto bg-white border border-gray-200 rounded-lg shadow-xl z-50">' +
                    '<div class="flex items-center justify-between px-4 py-3 border-b border-gray-200">' +
                        '<span class="font-semibold text-gray-800">Notifications</span>' +
                        '<button type="button" id="notifMarkAllBtn" class="text-xs text-primary hover:underline">Mark all read</button>' +
                    '</div>' +
                    '<div id="notifBellList" class="divide-y divide-gray-100 text-sm">' +
                        '<div class="p-4 text-center text-gray-500 text-xs">Loading…</div>' +
                    '</div>' +
                '</div>' +
            '</div>';

        var btn = document.getElementById('notifBellBtn');
        var panel = document.getElementById('notifBellPanel');

        btn.addEventListener('click', function (e) {
            e.stopPropagation();
            if (panel.classList.contains('hidden')) {
                load();
                panel.classList.remove('hidden');
            } else {
                panel.classList.add('hidden');
            }
        });
        document.addEventListener('click', function (e) {
            if (!panel.contains(e.target) && e.target !== btn) {
                panel.classList.add('hidden');
            }
        });
        document.getElementById('notifMarkAllBtn').addEventListener('click', markAllRead);

        refreshCount();
        setInterval(refreshCount, 60000);
    }

    function refreshCount() {
        fetch('notifications.php?action=count', { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data || !data.success) return;
                updateBadge(data.unread);
            })
            .catch(function () { /* ignore */ });
    }

    function load() {
        var list = document.getElementById('notifBellList');
        list.innerHTML = '<div class="p-4 text-center text-gray-500 text-xs">Loading…</div>';
        fetch('notifications.php?action=list', { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data || !data.success) {
                    list.innerHTML = '<div class="p-4 text-center text-red-500 text-xs">Could not load.</div>';
                    return;
                }
                renderList(data.items || []);
                updateBadge(data.unread || 0);
            })
            .catch(function () {
                list.innerHTML = '<div class="p-4 text-center text-red-500 text-xs">Could not load.</div>';
            });
    }

    function renderList(items) {
        var list = document.getElementById('notifBellList');
        if (!items.length) {
            list.innerHTML = '<div class="p-6 text-center text-gray-500 text-sm">No notifications yet.</div>';
            return;
        }
        list.innerHTML = items.map(function (n) {
            var unreadCls = n.is_read ? '' : ' bg-pink-50';
            return '<div class="p-3 hover:bg-gray-50' + unreadCls + '" data-id="' + n.id + '">' +
                       '<div class="flex items-start justify-between gap-2">' +
                           '<span class="font-semibold text-gray-800 text-sm">' + escapeHtml(n.title) + '</span>' +
                           '<span class="text-[10px] text-gray-400 whitespace-nowrap">' + formatDate(n.created_at) + '</span>' +
                       '</div>' +
                       '<div class="text-xs text-gray-600 mt-1">' + escapeHtml(n.message) + '</div>' +
                       (n.is_read ? '' :
                           '<button type="button" class="text-[11px] text-primary hover:underline mt-1 js-mark-read">Mark as read</button>') +
                   '</div>';
        }).join('');

        list.querySelectorAll('.js-mark-read').forEach(function (b) {
            b.addEventListener('click', function () {
                var wrapper = b.closest('[data-id]');
                if (!wrapper) return;
                markRead(parseInt(wrapper.getAttribute('data-id'), 10));
            });
        });
    }

    function markRead(id) {
        var fd = new FormData();
        fd.append('id', id);
        if (window.CSRF_TOKEN) fd.append('csrf_token', window.CSRF_TOKEN);
        fetch('notifications.php?action=mark_read', { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function () { load(); });
    }

    function markAllRead() {
        var fd = new FormData();
        if (window.CSRF_TOKEN) fd.append('csrf_token', window.CSRF_TOKEN);
        fetch('notifications.php?action=mark_all_read', { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function () { load(); });
    }

    function updateBadge(unread) {
        var badge = document.getElementById('notifBellBadge');
        if (!badge) return;
        if (unread > 0) {
            badge.textContent = unread > 99 ? '99+' : String(unread);
            badge.classList.remove('hidden');
        } else {
            badge.classList.add('hidden');
        }
    }

    function escapeHtml(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;')
            .replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    function formatDate(s) {
        try {
            var d = new Date(s.replace(' ', 'T'));
            if (isNaN(d.getTime())) return s;
            return d.toLocaleString();
        } catch (e) { return s; }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', mount);
    } else {
        mount();
    }
})();
