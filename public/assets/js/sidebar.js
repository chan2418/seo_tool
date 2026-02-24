(function () {
    function initSidebar() {
        var sidebar = document.getElementById('app-sidebar');
        var overlay = document.getElementById('sidebar-overlay');
        if (!sidebar) {
            return;
        }

        function openSidebar() {
            sidebar.classList.remove('-translate-x-full');
            if (overlay) {
                overlay.classList.remove('hidden');
            }
        }

        function closeSidebar() {
            sidebar.classList.add('-translate-x-full');
            if (overlay) {
                overlay.classList.add('hidden');
            }
        }

        document.querySelectorAll('[data-sidebar-toggle="open"], #sidebar-open, #open-sidebar').forEach(function (button) {
            button.addEventListener('click', openSidebar);
        });

        if (overlay) {
            overlay.addEventListener('click', closeSidebar);
        }

        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') {
                closeSidebar();
            }
        });

        var links = document.querySelectorAll('#app-sidebar .sidebar-link');
        links.forEach(function (link) {
            link.addEventListener('click', function () {
                links.forEach(function (item) {
                    item.classList.remove('active');
                });
                link.classList.add('active');

                if (window.innerWidth < 1024) {
                    closeSidebar();
                }
            });
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initSidebar);
    } else {
        initSidebar();
    }
})();
