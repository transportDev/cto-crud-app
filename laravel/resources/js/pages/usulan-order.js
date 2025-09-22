import { exportAoa } from "../utils/export.js";

function buildHeader() {
    return [
        "No",
        "Tanggal",
        "Requestor",
        "Regional",
        "NOP",
        "Site NE",
        "Site FE",
        "Transport Type",
        "PL Status",
        "Transport Category",
        "PL Value",
        "Link Cap",
        "Link Util",
        "Link Owner",
        "Propose Solution",
        "Remark",
        "Jarak ODP",
        "Cek NIM",
        "Status Order",
        "Komentar",
    ];
}

function rowsToAoa(rows) {
    const header = buildHeader();
    const body = rows.map((o) => {
        const comments = Array.isArray(o.comments)
            ? o.comments.map((c) => `${c.requestor} – ${c.comment}`).join(" | ")
            : "";
        return [
            o.no,
            o.tanggal_input ?? "",
            o.requestor ?? "",
            o.regional ?? "",
            o.nop ?? "",
            o.siteid_ne ?? "",
            o.siteid_fe ?? "",
            o.transport_type ?? "",
            o.pl_status ?? "",
            o.transport_category ?? "",
            o.pl_value ?? "",
            o.link_capacity ?? "",
            o.link_util ?? "",
            o.link_owner ?? "",
            o.propose_solution ?? "",
            o.remark ?? "",
            o.jarak_odp ?? "",
            o.cek_nim_order ?? "",
            o.status_order ?? "",
            comments,
        ];
    });
    return [header, ...body];
}

window.addEventListener("DOMContentLoaded", () => {
    const btn = document.getElementById("exportUsulanOrder");
    if (!btn) return;
    btn.addEventListener("click", async () => {
        btn.disabled = true;
        const original = btn.textContent;
        btn.textContent = "Menyiapkan…";
        try {
            const url = new URL("/api/usulan-order", window.location.origin);
            const q = new URL(window.location.href).searchParams.get("q");
            if (q) url.searchParams.set("q", q);
            const res = await fetch(url.toString(), {
                headers: { Accept: "application/json" },
            });
            const data = await res.json();
            if (!data.ok) throw new Error(data.error || "Gagal memuat data");
            const aoa = rowsToAoa(Array.isArray(data.rows) ? data.rows : []);
            await exportAoa("Usulan-Order", aoa);
        } catch (e) {
            console.error(e);
            alert("Gagal mengekspor data.");
        } finally {
            btn.disabled = false;
            btn.textContent = original;
        }
    });
});
