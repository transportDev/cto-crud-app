import "./bootstrap";
import "./orderModal";
import "../css/dashboard.css"; // ensure dashboard styles are bundled

// Lazy-load page-specific scripts based on DOM markers to avoid per-page @vite entries
window.addEventListener("DOMContentLoaded", () => {
    // Dashboard page marker
    if (document.getElementById("dash-data")) {
        import("./pages/dashboard.js");
    }
    // Usulan Order page marker
    if (document.getElementById("exportUsulanOrder")) {
        import("./pages/usulan-order.js");
    }
});
