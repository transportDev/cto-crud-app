import "./bootstrap";
import "./orderModal";
import "./orderDetailModal";
import "./capacityCommentsModal";
import "../css/dashboard.css";

window.addEventListener("DOMContentLoaded", () => {
    if (document.getElementById("dash-data")) {
        import("./pages/dashboard.js");
    }
    if (document.getElementById("exportUsulanOrder")) {
        import("./pages/usulan-order.js");
    }
});
