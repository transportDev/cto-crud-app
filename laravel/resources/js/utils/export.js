async function ensureXlsx() {
    if (typeof window !== "undefined" && typeof window.XLSX !== "undefined") {
        return true;
    }
    try {
        await new Promise((resolve, reject) => {
            const s = document.createElement("script");
            s.src =
                "https://cdn.jsdelivr.net/npm/xlsx@0.19.3/dist/xlsx.full.min.js";
            s.async = true;
            s.onload = () => resolve(true);
            s.onerror = reject;
            document.head.appendChild(s);
        });
        return (
            typeof window !== "undefined" && typeof window.XLSX !== "undefined"
        );
    } catch {
        return false;
    }
}

function aoaToCsv(aoa) {
    return aoa
        .map((row) =>
            row
                .map((cell) => {
                    const s = String(cell ?? "");
                    if (/[",\n]/.test(s))
                        return '"' + s.replace(/"/g, '""') + '"';
                    return s;
                })
                .join(",")
        )
        .join("\n");
}

export async function exportAoa(fileBaseName, aoa) {
    const hasXlsx = await ensureXlsx();
    const date = new Date().toISOString().slice(0, 10);
    if (hasXlsx) {
        try {
            const wb = window.XLSX.utils.book_new();
            const ws = window.XLSX.utils.aoa_to_sheet(aoa);
            window.XLSX.utils.book_append_sheet(wb, ws, "Data");
            window.XLSX.writeFile(wb, `${fileBaseName}-${date}.xlsx`);
            return;
        } catch {}
    }
    const csv = aoaToCsv(aoa);
    const blob = new Blob([csv], { type: "text/csv;charset=utf-8;" });
    const url = URL.createObjectURL(blob);
    const a = document.createElement("a");
    a.href = url;
    a.download = `${fileBaseName}-${date}.csv`;
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    URL.revokeObjectURL(url);
}
