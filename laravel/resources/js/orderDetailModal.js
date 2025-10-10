// Order detail modal logic: provides window.openOrderDetailModal(siteId)
// and window.closeOrderDetailModal().

(function () {
    const state = { requestToken: 0 };
    const FIELD_LAYOUT = [
        { key: "site_id", label: "Site ID" },
        { key: "site_name", label: "Nama Site" },
        { key: "tier", label: "Tier" },
        { key: "region", label: "Regional" },
        { key: "witel", label: "Witel" },
        { key: "simpul", label: "Simpul" },
        { key: "site_class", label: "Site Class" },
        { key: "program", label: "Program" },
        { key: "nama_program", label: "Nama Program" },
        { key: "bw_order", label: "BW Order" },
        { key: "sow", label: "SOW" },
        { key: "bill_type", label: "Bill Type" },
        { key: "product_type", label: "Product Type" },
        { key: "nop", label: "NOP" },
        { key: "priority", label: "Priority" },
        { key: "no_order", label: "No Order" },
        { key: "status_order", label: "Status Order" },
        { key: "progress", label: "Progress" },
        { key: "update_progress", label: "Update Progress" },
        { key: "target_close", label: "Target Close" },
        { key: "aging_order", label: "Aging Order" },
        { key: "tgl_order", label: "Tanggal Order" },
        { key: "tgl_on_air", label: "Tanggal On Air" },
        { key: "date_co", label: "Tanggal CO" },
        { key: "last_update", label: "Last Update" },
        { key: "pl_status", label: "PL Status" },
        { key: "pl_distribution", label: "PL Distribution" },
        { key: "pl_aging", label: "PL Aging" },
        { key: "dependency", label: "Dependency" },
        { key: "flatten_status", label: "Flatten Status" },
        { key: "feedback", label: "Feedback" },
        { key: "pic", label: "PIC" },
        { key: "lat", label: "Latitude" },
        { key: "long", label: "Longitude" },
        { key: "no", label: "Nomor Record" },
        { key: "date_id", label: "Date ID" },
    ];

    function qs(id) {
        return document.getElementById(id);
    }

    function getEls() {
        return {
            modal: qs("orderDetailModal"),
            loader: qs("orderDetailLoader"),
            errorBox: qs("orderDetailError"),
            body: qs("orderDetailBody"),
            empty: qs("orderDetailEmpty"),
            titleSite: qs("orderDetailTitleSite"),
        };
    }

    function formatValue(val) {
        if (val == null || val === "") return "–";
        if (typeof val === "number") {
            if (Number.isFinite(val)) return String(val);
            return "–";
        }
        if (typeof val === "object") {
            return JSON.stringify(val);
        }
        return String(val);
    }

    function prettifyKey(key = "") {
        return (
            key
                .toString()
                .split(/[_\s]+/)
                .filter(Boolean)
                .map((part) => part.charAt(0).toUpperCase() + part.slice(1))
                .join(" ")
                .trim() || key
        );
    }

    function clearBody(bodyEl) {
        if (!bodyEl) return;
        bodyEl.innerHTML = "";
        bodyEl.style.display = "none";
    }

    function renderDetail(data) {
        const { body, empty } = getEls();
        if (!body) return;
        clearBody(body);
        if (
            !data ||
            typeof data !== "object" ||
            Object.keys(data).length === 0
        ) {
            if (empty) empty.style.display = "block";
            return;
        }
        if (empty) empty.style.display = "none";
        const fragment = document.createDocumentFragment();
        const shownKeys = new Set();
        FIELD_LAYOUT.forEach((field) => {
            if (!field?.key || !field?.label) return;
            if (!(field.key in data)) return;
            const item = document.createElement("div");
            item.className = "detail-item";
            const label = document.createElement("div");
            label.className = "detail-label";
            label.textContent = field.label;
            const value = document.createElement("div");
            value.className = "detail-value";
            value.textContent = formatValue(data[field.key]);
            item.appendChild(label);
            item.appendChild(value);
            fragment.appendChild(item);
            shownKeys.add(field.key);
        });
        Object.keys(data || {})
            .filter((key) => !shownKeys.has(key))
            .forEach((key) => {
                const item = document.createElement("div");
                item.className = "detail-item";
                const label = document.createElement("div");
                label.className = "detail-label";
                label.textContent = prettifyKey(key);
                const value = document.createElement("div");
                value.className = "detail-value";
                value.textContent = formatValue(data[key]);
                item.appendChild(label);
                item.appendChild(value);
                fragment.appendChild(item);
            });
        body.appendChild(fragment);
        body.style.display = "grid";
    }

    function showLoader(flag) {
        const { loader } = getEls();
        if (!loader) return;
        loader.style.display = flag ? "flex" : "none";
    }

    function showError(message) {
        const { errorBox } = getEls();
        if (!errorBox) return;
        if (!message) {
            errorBox.classList.remove("active");
            errorBox.textContent = "";
            return;
        }
        errorBox.textContent = message;
        errorBox.classList.add("active");
    }

    function resetView(siteId = null) {
        const { empty, titleSite } = getEls();
        showLoader(false);
        showError("");
        clearBody(getEls().body);
        if (empty) empty.style.display = "none";
        if (titleSite) titleSite.textContent = siteId ?? "–";
    }

    function openModalShell() {
        const { modal } = getEls();
        if (!modal) return;
        modal.classList.add("active");
        modal?.setAttribute("aria-hidden", "false");
    }

    function closeModalShell() {
        const { modal } = getEls();
        if (!modal) return;
        modal.classList.remove("active");
        modal?.setAttribute("aria-hidden", "true");
    }

    async function fetchDetail(siteId) {
        const token = ++state.requestToken;
        resetView(siteId);
        showLoader(true);
        try {
            const res = await fetch(
                `/api/order/detail/${encodeURIComponent(siteId)}`,
                {
                    headers: { Accept: "application/json" },
                    credentials: "same-origin",
                }
            );
            const payload = await res
                .json()
                .catch(() => ({ ok: false, error: "Respon tidak valid" }));
            if (token !== state.requestToken) return;
            showLoader(false);
            if (!res.ok) {
                if (res.status === 404) {
                    renderDetail(null);
                    showError(payload.error || "Detail tidak ditemukan");
                    return;
                }
                showError(
                    payload.error || `Gagal memuat detail (HTTP ${res.status})`
                );
                return;
            }
            if (!payload.ok) {
                showError(payload.error || "Gagal memuat detail");
                return;
            }
            renderDetail(payload.data || {});
        } catch (error) {
            if (token !== state.requestToken) return;
            showLoader(false);
            showError(error?.message || "Terjadi kesalahan saat memuat detail");
        }
    }

    window.openOrderDetailModal = function (siteId) {
        if (!siteId) return;
        const { titleSite } = getEls();
        if (titleSite) titleSite.textContent = siteId;
        openModalShell();
        fetchDetail(siteId);
    };

    window.closeOrderDetailModal = function () {
        state.requestToken += 1; // cancel pending
        closeModalShell();
        resetView();
    };

    document.addEventListener("keydown", (event) => {
        if (event.key === "Escape") {
            const { modal } = getEls();
            if (modal?.classList.contains("active")) {
                window.closeOrderDetailModal();
            }
        }
    });

    const { modal } = getEls();
    modal?.addEventListener("click", (event) => {
        if (event.target === modal) {
            window.closeOrderDetailModal();
        }
    });
})();
