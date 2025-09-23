<aside class="dash-sidebar" aria-label="Dashboard navigation">
    <div class="dash-brand">
        <div class="logo" aria-hidden="true">C</div>
        <div class="title">CTO Panel</div>
    </div>
    <nav class="dash-nav">
        <a href="{{ route('dashboard') }}" class="dash-link {{ request()->routeIs('dashboard') ? 'active' : '' }}">Dashboard</a>
        <a href="{{ route('dashboard.usulan-order') }}" class="dash-link {{ request()->routeIs('dashboard.usulan-order') ? 'active' : '' }}">Usulan Order</a>
    </nav>
    @auth
    <div class="mt-6" id="userMenuRoot">
        <button type="button" id="userMenuButton" class="user-btn" aria-haspopup="true" aria-expanded="false">
            <span style="display:flex;align-items:center;gap:10px;">
                <span class="user-avatar" aria-hidden="true">{{ strtoupper(substr(auth()->user()->name,0,1)) }}</span>
                <span class="user-name" style="display:flex;flex-direction:column;text-align:left;">
                    <span style="font-weight:600;font-size:13px;line-height:1;">{{ auth()->user()->name }}</span>
                    <span style="font-size:11px;color:#9ca3af;line-height:1.2;">{{ auth()->user()->email }}</span>
                </span>
            </span>
            <svg class="caret" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M6 9l6 6 6-6" />
            </svg>
        </button>
        <div id="userDropdown" class="user-dropdown" role="menu" aria-hidden="true">
            <div class="menu">
                <div class="user-meta">Masuk sebagai<br><span class="text-sm text-gray-200 font-medium">{{ auth()->user()->email }}</span></div>
                <form method="POST" action="{{ route('logout') }}" class="m-0 p-0" id="logoutForm">
                    @csrf
                    <button type="submit" class="dropdown-item" role="menuitem">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4" />
                            <polyline points="16 17 21 12 16 7" />
                            <line x1="21" y1="12" x2="9" y2="12" />
                        </svg>
                        <span>Keluar</span>
                    </button>
                </form>
            </div>
        </div>
    </div>
    <script>
        (function() {
            const root = document.getElementById('userMenuRoot');
            const btn = document.getElementById('userMenuButton');
            const dd = document.getElementById('userDropdown');
            if (!root || !btn || !dd) return;

            function close() {
                dd.classList.remove('open');
                btn.setAttribute('aria-expanded', 'false');
            }

            function open() {
                dd.classList.add('open');
                btn.setAttribute('aria-expanded', 'true');
            }
            btn.addEventListener('click', (e) => {
                e.stopPropagation();
                dd.classList.contains('open') ? close() : open();
            });
            document.addEventListener('click', (e) => {
                if (!root.contains(e.target)) close();
            });
            document.addEventListener('keydown', (e) => {
                if (e.key === 'Escape') close();
            });
        })();
    </script>
    @endauth
</aside>