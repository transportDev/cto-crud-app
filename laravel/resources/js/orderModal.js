(function () {
    const state = { submitting: false, prefillToken: null };
    const DEFAULT_NOP_OPTIONS = ["DENPASAR", "KUPANG", "MATARAM", "FLORES"];
    const DEFAULT_PROPOSE_SOLUTION_OPTIONS = [
        "",
        "FO TLKM",
        "Radio IP",
        "No need (Done Upgrade Channel 56 MHz)",
    ];
    const CEK_NIM_PLACEHOLDER = "Belum ada order";
    const STATUS_ORDER_DEFAULT = "1. ~0%";

    function ensureShell() {
        if (document.getElementById("orderModal")) return;
    }

    function qs(id) {
        return document.getElementById(id);
    }

    function normalizeTextValue(value) {
        return String(value ?? "").trim();
    }

    function normalizeNopValue(value) {
        return normalizeTextValue(value);
    }

    function resolveNopOptions(extra = []) {
        const globalList = Array.isArray(window.NOP_OPTIONS)
            ? window.NOP_OPTIONS
            : [];
        const merged = [...DEFAULT_NOP_OPTIONS, ...globalList, ...extra].map(
            (item) => normalizeNopValue(item)
        );
        const unique = [];
        merged.forEach((item) => {
            if (item && !unique.includes(item)) unique.push(item);
        });
        return unique;
    }

    function populateNopSelect(selectedValue = "") {
        const select = qs("nopSelect");
        if (!select) return;
        const normalizedSelected = normalizeNopValue(selectedValue);
        const options = resolveNopOptions(
            normalizedSelected ? [normalizedSelected] : []
        );
        const currentValue = normalizeNopValue(select.value);
        const desiredValue = normalizedSelected || currentValue;
        select.innerHTML = "";
        const placeholder = document.createElement("option");
        placeholder.value = "";
        placeholder.textContent = "Pilih NOP";
        select.appendChild(placeholder);
        options.forEach((item) => {
            const opt = document.createElement("option");
            opt.value = item;
            opt.textContent = item;
            select.appendChild(opt);
        });
        if (desiredValue) select.value = desiredValue;
    }

    function setNopValue(value) {
        const normalized = normalizeNopValue(value);
        populateNopSelect(normalized);
        const select = qs("nopSelect");
        if (!select) return;
        if (normalized) select.value = normalized;
        else select.value = "";
    }

    function normalizeProposeSolutionValue(value) {
        return normalizeTextValue(value);
    }

    function resolveProposeSolutionOptions(extra = []) {
        const globalList = Array.isArray(window.PROPOSE_SOLUTION_OPTIONS)
            ? window.PROPOSE_SOLUTION_OPTIONS
            : [];
        const merged = [
            ...DEFAULT_PROPOSE_SOLUTION_OPTIONS,
            ...globalList,
            ...extra,
        ].map((item) => normalizeProposeSolutionValue(item));
        const unique = [];
        merged.forEach((item) => {
            if (!unique.includes(item)) unique.push(item);
        });
        if (!unique.includes("")) unique.unshift("");
        return unique;
    }

    function populateProposeSolutionSelect(selectedValue = "") {
        const select = qs("proposeSolutionSelect");
        if (!select) return;
        const normalizedSelected = normalizeProposeSolutionValue(selectedValue);
        const options = resolveProposeSolutionOptions(
            normalizedSelected ? [normalizedSelected] : []
        );
        const currentValue = normalizeProposeSolutionValue(select.value);
        const desiredValue = normalizedSelected || currentValue;
        select.innerHTML = "";
        const placeholder = document.createElement("option");
        placeholder.value = "";
        placeholder.textContent = "Pilih Propose Solution";
        select.appendChild(placeholder);
        options
            .filter((item) => item !== "")
            .forEach((item) => {
                const opt = document.createElement("option");
                opt.value = item;
                opt.textContent = item;
                select.appendChild(opt);
            });
        select.value = desiredValue || "";
    }

    function setProposeSolutionValue(value) {
        const normalized = normalizeProposeSolutionValue(value);
        populateProposeSolutionSelect(normalized);
        const select = qs("proposeSolutionSelect");
        if (!select) return;
        select.value = normalized || "";
    }

    function getCekNimOrderInput() {
        return (
            qs("cekNimOrderInput") ||
            (qs("orderForm")?.elements["cek_nim_order"] ?? null)
        );
    }

    function getStatusOrderInput() {
        return (
            qs("statusOrderInput") ||
            (qs("orderForm")?.elements["status_order"] ?? null)
        );
    }

    function setCekNimOrderValue(value) {
        const input = getCekNimOrderInput();
        if (!input) return;
        const placeholder = input.dataset.placeholder || CEK_NIM_PLACEHOLDER;
        const normalized = normalizeTextValue(value);
        if (normalized) {
            input.value = normalized;
            input.dataset.actualValue = normalized;
            input.dataset.placeholderShown = "0";
        } else {
            input.value = placeholder;
            input.dataset.actualValue = "";
            input.dataset.placeholderShown = "1";
        }
        input.placeholder = placeholder;
        input.disabled = true;
        input.classList.toggle(
            "is-placeholder",
            input.dataset.actualValue === ""
        );
    }

    function setStatusOrderValue(value) {
        const input = getStatusOrderInput();
        if (!input) return;
        const defaultValue = input.dataset.defaultValue || STATUS_ORDER_DEFAULT;
        let normalized = "";
        if (value === true) normalized = "true";
        else if (value === false || value == null) normalized = "";
        else normalized = normalizeTextValue(value);
        const finalValue = normalized || defaultValue;
        input.value = finalValue;
        input.dataset.actualValue = finalValue;
        input.dataset.defaultValue = defaultValue;
        input.disabled = true;
        input.classList.remove("is-placeholder");
    }

    function isFormControl(el) {
        return (
            el instanceof HTMLInputElement ||
            el instanceof HTMLTextAreaElement ||
            el instanceof HTMLSelectElement
        );
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

        if (typeof form.reset === "function") {
            form.reset();
        } else {
            for (const el of form.elements) {
                if (isFormControl(el)) {
                    if (el.type === "checkbox" || el.type === "radio")
                        el.checked = false;
                    else el.value = "";
                }
            }
        }

        const allowedFields = [
            "nop",
            "propose_solution",
            "remark",
            "siteid_fe",
            "comment",
        ];
        for (const el of form.elements) {
            if (isFormControl(el) && el.name) {
                if (!allowedFields.includes(el.name)) {
                    el.disabled = true;
                } else {
                    el.disabled = false;
                }
            }
        }

        form.querySelectorAll(".hidden-clone-input").forEach((el) =>
            el.remove()
        );

        populateNopSelect();
        if (prefill.nop != null) setNopValue(prefill.nop);
        else setNopValue("");
        populateProposeSolutionSelect();
        if (prefill.propose_solution != null)
            setProposeSolutionValue(prefill.propose_solution);
        else setProposeSolutionValue("");
        setCekNimOrderValue(prefill.cek_nim_order);
        setStatusOrderValue(prefill.status_order);

        if (prefill.siteid_ne && form.elements["siteid_ne"]) {
            form.elements["siteid_ne"].value = prefill.siteid_ne;
        }
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

        if (prefill.site_id) {
            if (screen) screen.classList.add("active");

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

            const token = Date.now() + Math.random();
            state.prefillToken = token;
            if (loader) loader.style.display = "flex";

            const enabledEls = [];
            const allowedFields = [
                "nop",
                "propose_solution",
                "remark",
                "siteid_fe",
                "comment",
            ];
            const readonlyFields = [
                "requestor",
                "regional",
                "siteid_ne",
                "transport_type",
                "pl_status",
                "transport_category",
                "pl_value",
                "link_capacity",
                "link_util",
                "link_owner",
                "jarak_odp",
            ];
            for (const el of form.elements) {
                if (isFormControl(el)) {
                    if (!el.disabled && allowedFields.includes(el.name)) {
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
                            if (k === "nop") {
                                return;
                            }
                            if (k === "propose_solution") {
                                return;
                            }
                            if (k === "cek_nim_order") {
                                setCekNimOrderValue(v);
                                return;
                            }
                            if (k === "status_order") {
                                setStatusOrderValue(v);
                                return;
                            }
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
        if (form) {
            if (typeof form.reset === "function") form.reset();
            const allowedFields = [
                "nop",
                "propose_solution",
                "remark",
                "siteid_fe",
                "comment",
            ];
            for (const el of form.elements) {
                if (isFormControl(el) && el.name) {
                    el.disabled = !allowedFields.includes(el.name);
                }
            }

            form.querySelectorAll(".hidden-clone-input").forEach((el) =>
                el.remove()
            );
        }
        populateNopSelect();
        setNopValue("");
        populateProposeSolutionSelect();
        setProposeSolutionValue("");
        setCekNimOrderValue();
        setStatusOrderValue();
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

            const requiredDisabledFields = [
                "requestor",
                "regional",
                "siteid_ne",
                "transport_type",
                "pl_status",
                "transport_category",
                "pl_value",
                "link_capacity",
                "link_util",
                "link_owner",
                "jarak_odp",
            ];
            requiredDisabledFields.forEach((fieldName) => {
                const field = form.elements[fieldName];
                if (field && field.disabled && field.value) {
                    fd.set(fieldName, field.value);
                }
            });

            const cekNimInput = getCekNimOrderInput();
            if (cekNimInput) {
                const actual = cekNimInput.dataset.actualValue ?? "";
                fd.set("cek_nim_order", actual);
            }
            const statusOrderInput = getStatusOrderInput();
            if (statusOrderInput) {
                const actual = statusOrderInput.dataset.actualValue ?? "";
                fd.set("status_order", actual);
            }
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
        populateNopSelect();
        populateProposeSolutionSelect();
        setCekNimOrderValue();
        setStatusOrderValue();
    });
})();
