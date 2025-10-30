<x-filament-panels::page.simple>
    <div class=" relative bg-[var(--carbon-black)] text-white overflow-hidden">

        <div aria-hidden="true" class="pointer-events-none absolute inset-0" id="network-bg"></div>


        <div class=" grid place-items-center px-4 py-12">
            <div class="login-card relative w-full max-w-md rounded-2xl bg-white/5 dark:bg-black/20 backdrop-blur-xl supports-[backdrop-filter]:bg-white/10 p-8 md:p-12 shadow-2xl">
                <div class="absolute -inset-px rounded-2xl pointer-events-none login-card-glow"></div>

                <x-filament-panels::form wire:submit="authenticate" class="space-y-6">
                    {{ $this->form }}
                    <x-filament::button type="submit" class="w-full login-button" wire:loading.attr="disabled" wire:target="authenticate" aria-label="Sign in">
                        <span wire:loading.remove wire:target="authenticate">Sign in</span>
                        <span wire:loading.flex wire:target="authenticate" class="items-center justify-center gap-2">
                            Connecting
                            <span class="w-2.5 h-2.5 rounded-full bg-[var(--electric-blue)] animate-ping inline-block"></span>
                        </span>
                    </x-filament::button>
                </x-filament-panels::form>

                @if (filled($this->getCachedForms()['form']->getComponents()))
                <div class="sr-only" aria-live="polite">Login form loaded</div>
                @endif
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
            const NODE_COUNT = 36;

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
                        r: 1.2 + Math.random() * 1.4,
                        phase: Math.random() * Math.PI * 2
                    });
                }
            }

            function draw() {
                ctx.clearRect(0, 0, width, height);
                // draw connections
                for (let i = 0; i < nodes.length; i++) {
                    for (let j = i + 1; j < nodes.length; j++) {
                        const a = nodes[i],
                            b = nodes[j];
                        const dx = a.x - b.x,
                            dy = a.y - b.y;
                        const dist = Math.hypot(dx, dy);
                        if (dist < 140) {
                            const alpha = Math.max(0, 1 - dist / 140) * 0.08; // subtle
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
                    const pulse = 0.3 + Math.sin(Date.now() / 900 + n.phase) * 0.15;
                    ctx.fillStyle = `rgba(0, 212, 255, ${0.15 + pulse})`;
                    ctx.beginPath();
                    ctx.arc(n.x, n.y, n.r, 0, Math.PI * 2);
                    ctx.fill();
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
    </script>
</x-filament-panels::page.simple>