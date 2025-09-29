@php($title = 'Masuk')
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $title }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>

<body class="min-h-screen flex items-center justify-center">
    <!-- Full-screen animated background -->
    <div aria-hidden="true" id="network-bg" class="pointer-events-none fixed inset-0 z-0"></div>
    <div class="w-1/3 relative z-10">

        <div class=" relative bg-[var(--carbon-black)] text-white">

            <!-- Centered card -->
            <div class=" grid place-items-center px-4 py-12">
                <div class="login-card glass-card relative w-full max-w-md rounded-2xl p-8 md:p-12 shadow-2xl border border-white/10">
                    <div class="absolute -inset-px rounded-2xl pointer-events-none login-card-glow"></div>

                    <h1 class="text-2xl font-black text-center mb-4 text-gray-900 dark:text-gray-100">
                        CTO PANEL</h1>
                    <h1 class="text-xl font-semibold text-center mb-4 text-gray-900 dark:text-gray-100">
                        Masuk ke akun Anda</h1>

                    @if ($errors->any())
                    <div class="mb-4 text-sm text-red-600">
                        <ul class="list-disc list-inside">
                            @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                    </div>
                    @endif

                    @php($loginError = $errors->has('login'))
                    @php($passwordError = $errors->has('password'))
                    <form method="POST" action="{{ route('login.attempt') }}" class="space-y-5">
                        @csrf
                        <div>
                            <label class="block text-sm font-medium text-zinc-300" for="login">Email atau Username</label>
                            <div class="input-shell mt-1 {{ $loginError ? 'has-error' : '' }}">
                                <div class="leading-icon" aria-hidden="true">
                                    <!-- user icon -->
                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0z" />
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 20.25a8.25 8.25 0 1115 0A17.933 17.933 0 0012 18.75c-2.695 0-5.24.6-7.5 1.5z" />
                                    </svg>
                                </div>
                                <input id="login" name="login" type="text" required autofocus autocomplete="username" value="{{ old('login') }}" class="field-input" placeholder="email atau nama pengguna" />
                            </div>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-zinc-300" for="password">Kata sandi</label>
                            <div class="input-shell mt-1 {{ $passwordError ? 'has-error' : '' }}">
                                <div class="leading-icon" aria-hidden="true">
                                    <!-- lock icon -->
                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M16 10V7a4 4 0 10-8 0v3" />
                                        <rect width="14" height="10" x="5" y="10" rx="2" ry="2" />
                                    </svg>
                                </div>
                                <input id="password" name="password" type="password" required autocomplete="current-password" class="field-input" placeholder="••••••••" />
                                <button type="button" id="togglePassword" class="trailing-icon" aria-label="Toggle password visibility">
                                    <!-- eye icon -->
                                    <svg id="eyeIcon" xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12z" />
                                        <circle cx="12" cy="12" r="3" />
                                    </svg>
                                </button>
                            </div>
                        </div>

                        <div class="flex items-center justify-between">
                            <label class="inline-flex items-center select-none">
                                <input type="checkbox" name="remember" class="rounded border-zinc-700 bg-zinc-900 text-red-600 focus:ring-red-600/70" />
                                <span class="ml-2 text-sm text-zinc-300">Ingat saya</span>
                            </label>

                        </div>

                        <button type="submit" class="w-full inline-flex justify-center rounded-lg bg-red-600 px-4 py-3 text-white font-semibold tracking-wide shadow-lg shadow-red-900/30 hover:bg-red-700 focus:outline-none focus:ring-2 focus:ring-red-500">Masuk</button>
                    </form>
                </div>
            </div>
        </div>
        <script>
            // Simple canvas network background
            (function() {
                const container = document.getElementById('network-bg');
                if (!container) return;
                const canvas = document.createElement('canvas');
                const ctx = canvas.getContext('2d');
                container.appendChild(canvas);

                let width, height, dpr;
                const nodes = [];
                const NODE_COUNT = 44; // a bit denser

                function resize() {
                    dpr = window.devicePixelRatio || 1;
                    width = container.clientWidth;
                    height = container.clientHeight;
                    canvas.width = width * dpr;
                    canvas.height = height * dpr;
                    canvas.style.width = width + 'px';
                    canvas.style.height = height + 'px';
                    ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
                }

                function initNodes() {
                    nodes.length = 0;
                    for (let i = 0; i < NODE_COUNT; i++) {
                        nodes.push({
                            x: Math.random() * width,
                            y: Math.random() * height,
                            vx: (Math.random() - 0.5) * 0.2,
                            vy: (Math.random() - 0.5) * 0.2,
                            r: 2.2 + Math.random() * 1.8, // larger nodes
                            phase: Math.random() * Math.PI * 2
                        });
                    }
                }

                function draw() {
                    ctx.clearRect(0, 0, width, height);
                    const LINK_DIST = 160; // longer reach for links
                    // draw connections
                    for (let i = 0; i < nodes.length; i++) {
                        for (let j = i + 1; j < nodes.length; j++) {
                            const a = nodes[i],
                                b = nodes[j];
                            const dx = a.x - b.x,
                                dy = a.y - b.y;
                            const dist = Math.hypot(dx, dy);
                            if (dist < LINK_DIST) {
                                const t = Math.max(0, 1 - dist / LINK_DIST);
                                const alpha = t * 0.18; // stronger visibility
                                ctx.lineWidth = 1.2 + t * 0.6; // thicker when closer
                                ctx.strokeStyle = `rgba(0, 212, 255, ${alpha})`;
                                ctx.beginPath();
                                ctx.moveTo(a.x, a.y);
                                ctx.lineTo(b.x, b.y);
                                ctx.stroke();
                            }
                        }
                    }
                    // draw nodes
                    for (const n of nodes) {
                        const pulse = 0.3 + Math.sin(Date.now() / 900 + n.phase) * 0.2;
                        ctx.fillStyle = `rgba(0, 212, 255, ${0.22 + pulse})`;
                        ctx.shadowColor = 'rgba(0, 212, 255, 0.35)';
                        ctx.shadowBlur = 6;
                        ctx.beginPath();
                        ctx.arc(n.x, n.y, n.r, 0, Math.PI * 2);
                        ctx.fill();
                        // crisp outline
                        ctx.shadowBlur = 0;
                        ctx.strokeStyle = 'rgba(0, 212, 255, 0.35)';
                        ctx.lineWidth = 0.6;
                        ctx.stroke();
                        // red core pulse
                        ctx.fillStyle = 'rgba(236, 28, 36, 0.12)';
                        ctx.beginPath();
                        ctx.arc(n.x, n.y, n.r * 0.6, 0, Math.PI * 2);
                        ctx.fill();
                    }
                }

                function step() {
                    for (const n of nodes) {
                        n.x += n.vx;
                        n.y += n.vy;
                        if (n.x < 0 || n.x > width) n.vx *= -1;
                        if (n.y < 0 || n.y > height) n.vy *= -1;
                    }
                    draw();
                    requestAnimationFrame(step);
                }

                resize();
                initNodes();
                step();
                window.addEventListener('resize', () => {
                    resize();
                    initNodes();
                });
            })();

            // Password visibility toggle
            (function() {
                const toggle = document.getElementById('togglePassword');
                const pwd = document.getElementById('password');
                const eye = document.getElementById('eyeIcon');
                if (!toggle || !pwd || !eye) return;
                toggle.addEventListener('click', () => {
                    const masked = pwd.getAttribute('type') === 'password';
                    pwd.setAttribute('type', masked ? 'text' : 'password');
                    eye.innerHTML = masked ?
                        '<path stroke-linecap="round" stroke-linejoin="round" d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/><path d="M3 3l18 18" stroke-linecap="round" stroke-linejoin="round" />' :
                        '<path stroke-linecap="round" stroke-linejoin="round" d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/>';
                });
            })();
        </script>


    </div>
</body>

</html>