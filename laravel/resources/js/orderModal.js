// Order modal logic extracted from inline script
// Provides: window.openOrderModal(prefill), window.closeOrderModal()
// Depends on global loadCapacity() if present.

(function () {
    const state = { submitting: false, prefillToken: null };

    function ensureShell() {
        if (document.getElementById("orderModal")) return;
        // If for some reason modal not rendered, we skip.
    }

    function qs(id) {
        return document.getElementById(id);
    }

    function toast(msg, type = "success") {
        let box = qs("toastStack");
        if (!box) {
            box = document.createElement("div");
            box.id = "toastStack";
            box.style.position = "fixed";
            box.style.top = "16px";
            box.style.right = "16px";
            box.style.zIndex = "120";
            box.style.display = "flex";
            box.style.flexDirection = "column";
            box.style.gap = "10px";
            document.body.appendChild(box);
        }
        const item = document.createElement("div");
        item.className = "toast-item";
        item.style.padding = "10px 14px";
        item.style.border = "1px solid";
        item.style.borderRadius = "12px";
        item.style.fontSize = "13px";
        item.style.fontWeight = "500";
        item.style.backdropFilter = "blur(6px)";
        item.style.background = "rgba(17,18,22,0.85)";
        item.style.color = "#fff";
        item.style.boxShadow = "0 6px 18px -2px rgba(0,0,0,.45)";
        item.style.borderColor =
            type === "success" ? "rgba(16,185,129,.6)" : "rgba(239,68,68,.6)";
        item.textContent = msg;
        box.appendChild(item);
        setTimeout(() => {
            item.style.opacity = "0";
            item.style.transition = "opacity .4s";
        }, 2600);
        setTimeout(() => {
            item.remove();
        }, 3200);
    }

    window.openOrderModal = async function (prefill = {}) {
        ensureShell();
        const modal = qs("orderModal");
        const form = qs("orderForm");
        if (!modal || !form) return;

        const err = qs("orderErrors");
        if (err) {
            err.classList.remove("active");
            err.innerHTML = "";
        }

        const loader = qs("orderPrefillStatus");
        const screen = qs("screenLoading");
        if (loader) loader.style.display = "none";

        // Reset form to defaults on every open to avoid leaking previous values
        if (typeof form.reset === "function") {
            form.reset();
        } else {
            for (const el of form.elements) {
                if (
                    el instanceof HTMLInputElement ||
                    el instanceof HTMLTextAreaElement
                ) {
                    if (el.type === "checkbox" || el.type === "radio")
                        el.checked = false;
                    else el.value = "";
                }
            }
        }
        // Ensure all controls are enabled (in case a previous load left them disabled)
        for (const el of form.elements) {
            if (
                el instanceof HTMLInputElement ||
                el instanceof HTMLTextAreaElement
            )
                el.disabled = false;
        }

        // Always set siteid_ne first if provided
        if (prefill.siteid_ne && form.elements["siteid_ne"]) {
            form.elements["siteid_ne"].value = prefill.siteid_ne;
        }
        // Insert quick local metrics (link_util fraction -> percent, jarak_odp)
        if (prefill.link_util != null && form.elements["link_util"]) {
            const v = Number(prefill.link_util);
            if (!Number.isNaN(v)) {
                const val = v <= 1 ? v * 100 : v;
                if (!form.elements["link_util"].value)
                    form.elements["link_util"].value = val.toFixed(2);
            }
        }
        if (prefill.jarak_odp != null && form.elements["jarak_odp"]) {
            if (!form.elements["jarak_odp"].value)
                form.elements["jarak_odp"].value = prefill.jarak_odp;
        }

        modal.classList.add("active");

        // Fetch backend data if site id present
        if (prefill.site_id) {
            if (screen) screen.classList.add("active");

            // Load existing comments list (read-only)
            const commentsPromise = (async () => {
                try {
                    const c = await fetch(
                        `/api/order-comments?site_id=${encodeURIComponent(
                            prefill.site_id
                        )}`,
                        {
                            headers: { Accept: "application/json" },
                            credentials: "same-origin",
                        }
                    );
                    const cj = await c
                        .json()
                        .catch(() => ({ ok: false, data: [] }));
                    const listWrap = qs("orderCommentsList");
                    const ul = qs("orderCommentsUl");
                    const empty = qs("orderCommentsEmpty");
                    if (listWrap && ul && empty) {
                        listWrap.style.display = "block";
                        ul.innerHTML = "";
                        if (cj.ok && Array.isArray(cj.data) && cj.data.length) {
                            empty.style.display = "none";
                            ul.style.display = "block";
                            cj.data.forEach((item) => {
                                const li = document.createElement("li");
                                li.style.padding = "6px 0";
                                li.style.borderBottom =
                                    "1px solid var(--panel-border)";
                                li.style.fontSize = "12px";
                                li.style.color = "var(--muted)";
                                const name = (item.requestor || "").toString();
                                const text = (item.comment || "").toString();
                                li.textContent = `${name} – ${text}`;
                                ul.appendChild(li);
                            });
                        } else {
                            empty.style.display = "block";
                            ul.style.display = "none";
                        }
                    }
                } catch (e) {
                    /* ignore */
                }
            })();

            // Prefill backend data
            const token = Date.now() + Math.random();
            state.prefillToken = token;
            if (loader) loader.style.display = "flex";

            const enabledEls = [];
            for (const el of form.elements) {
                if (
                    el instanceof HTMLInputElement ||
                    el instanceof HTMLTextAreaElement
                ) {
                    if (!el.disabled) {
                        enabledEls.push(el);
                        el.disabled = true;
                    }
                }
            }

            const prefillPromise = (async () => {
                try {
                    const q = new URLSearchParams({ site_id: prefill.site_id });
                    const r = await fetch(
                        `/api/order-prefill?${q.toString()}`,
                        {
                            headers: { Accept: "application/json" },
                            credentials: "same-origin",
                        }
                    );
                    if (!r.ok) throw new Error("Prefill HTTP " + r.status);
                    const j = await r.json();
                    if (state.prefillToken === token && j.ok && j.data) {
                        Object.entries(j.data).forEach(([k, v]) => {
                            if (v == null) return;
                            const el = form.elements[k];
                            if (!el) return;
                            if (String(el.value).trim() === "") el.value = v;
                        });
                    }
                } catch (e) {
                    toast("Gagal prefill data", "error");
                }
            })();

            try {
                await Promise.all([commentsPromise, prefillPromise]);
            } catch (ex) {
                toast("Gagal memuat data", "error");
            } finally {
                if (state.prefillToken === token) {
                    if (loader) loader.style.display = "none";
                    enabledEls.forEach((el) => {
                        el.disabled = false;
                    });
                }
                if (screen) screen.classList.remove("active");
            }
        }
    };

    window.closeOrderModal = function () {
        const modal = qs("orderModal");
        const form = qs("orderForm");
        const loader = qs("orderPrefillStatus");
        const screen = qs("screenLoading");
        if (modal) modal.classList.remove("active");
        // Clear and unlock form on close to avoid leaked state
        if (form) {
            if (typeof form.reset === "function") form.reset();
            for (const el of form.elements) {
                if (
                    el instanceof HTMLInputElement ||
                    el instanceof HTMLTextAreaElement
                )
                    el.disabled = false;
            }
        }
        if (loader) loader.style.display = "none";
        if (screen) screen.classList.remove("active");
    };

    async function handleSubmit(e) {
        e.preventDefault();
        if (state.submitting) return;
        const form = e.target;
        const btn = qs("orderSubmitBtn");
        const errorsBox = qs("orderErrors");
        if (errorsBox) {
            errorsBox.classList.remove("active");
            errorsBox.innerHTML = "";
        }
        state.submitting = true;
        if (btn) {
            btn.disabled = true;
            btn.dataset.orig = btn.innerHTML;
            btn.innerHTML = '<div class="saving-spinner"></div>';
        }
        try {
            const fd = new FormData(form);
            const meta = document.querySelector('meta[name="csrf-token"]');
            const csrf =
                (meta && meta.getAttribute("content")) || fd.get("_token");
            if (!csrf) {
                if (errorsBox) {
                    errorsBox.innerHTML =
                        "Token sesi tidak ditemukan. Muat ulang halaman.";
                    errorsBox.classList.add("active");
                }
                toast("CSRF token hilang", "error");
                return;
            }
            const r = await fetch(form.dataset.action, {
                method: "POST",
                headers: {
                    Accept: "application/json",
                    "X-CSRF-TOKEN": csrf,
                    "X-Requested-With": "XMLHttpRequest",
                },
                body: fd,
                credentials: "same-origin",
            });
            const json = await r.json().catch(() => ({ ok: false }));
            if (!r.ok || !json.ok) {
                const errs = json.errors || { general: ["Gagal menyimpan."] };
                const list = Object.values(errs).flat();
                if (errorsBox) {
                    errorsBox.innerHTML = list
                        .map((m) => `<div>${m}</div>`)
                        .join("");
                    errorsBox.classList.add("active");
                }
                toast("Gagal menyimpan order", "error");
                return;
            }
            window.closeOrderModal();
            toast("Order berhasil dibuat");
            if (typeof window.loadCapacity === "function")
                window.loadCapacity();
        } catch (ex) {
            if (errorsBox) {
                errorsBox.innerHTML = "Terjadi kesalahan jaringan.";
                errorsBox.classList.add("active");
            }
            toast("Kesalahan jaringan", "error");
        } finally {
            state.submitting = false;
            if (btn) {
                btn.disabled = false;
                btn.innerHTML = btn.dataset.orig || "Simpan";
            }
        }
    }

    document.addEventListener("DOMContentLoaded", () => {
        const form = qs("orderForm");
        if (form) {
            form.addEventListener("submit", handleSubmit);
            if (!form.dataset.action) {
                form.dataset.action =
                    form.getAttribute("action") ||
                    form.dataset.action ||
                    form.dataset.route ||
                    window.orderStoreRoute ||
                    "";
            }
        }
    });
})();
