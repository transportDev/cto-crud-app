(function () {
    const modal = document.getElementById("capacityCommentsModal");
    if (!modal) {
        window.openCapacityCommentsModal = () => {};
        window.closeCapacityCommentsModal = () => {};
        return;
    }

    const siteEl = document.getElementById("capacityCommentsSite");
    const metaEl = document.getElementById("capacityCommentsMeta");
    const emptyEl = document.getElementById("capacityCommentsEmpty");
    const listEl = document.getElementById("capacityCommentsList");

    function sanitize(text) {
        if (text == null) return "";
        return String(text);
    }

    function resetModal() {
        if (siteEl) siteEl.textContent = "–";
        if (metaEl) metaEl.textContent = "Tidak ada komentar untuk site ini.";
        if (emptyEl) emptyEl.style.display = "block";
        if (listEl) {
            listEl.innerHTML = "";
            listEl.style.display = "none";
        }
    }

    function renderComments(comments) {
        if (!listEl || !emptyEl) return;
        listEl.innerHTML = "";
        if (!Array.isArray(comments) || comments.length === 0) {
            emptyEl.style.display = "block";
            listEl.style.display = "none";
            return;
        }

        emptyEl.style.display = "none";
        listEl.style.display = "flex";
        const frag = document.createDocumentFragment();

        comments
            .slice()
            .sort((a, b) => (Number(b?.id) || 0) - (Number(a?.id) || 0))
            .forEach((comment) => {
                const item = document.createElement("li");
                item.className = "comment-modal-item";

                const header = document.createElement("div");
                header.className = "comment-modal-header";

                const author = document.createElement("span");
                author.className = "comment-modal-author";
                author.textContent = sanitize(comment?.requestor || "Anonim");
                header.appendChild(author);

                if (comment?.order_id != null) {
                    const order = document.createElement("span");
                    order.className = "comment-modal-order";
                    order.textContent = `Order ID: ${sanitize(
                        comment.order_id
                    )}`;
                    header.appendChild(order);
                }

                const body = document.createElement("div");
                body.className = "comment-modal-body";
                body.textContent = sanitize(
                    comment?.comment || "(Tidak ada isi komentar)"
                );

                item.appendChild(header);
                item.appendChild(body);
                frag.appendChild(item);
            });

        listEl.appendChild(frag);
    }

    function openModalShell() {
        modal.classList.add("active");
        modal.setAttribute("aria-hidden", "false");
    }

    function closeModalShell() {
        modal.classList.remove("active");
        modal.setAttribute("aria-hidden", "true");
    }

    window.openCapacityCommentsModal = function ({
        siteId,
        comments,
        count,
    } = {}) {
        resetModal();
        if (!siteId) return;

        if (siteEl) siteEl.textContent = sanitize(siteId);
        const total =
            Number(count ?? (Array.isArray(comments) ? comments.length : 0)) ||
            0;
        if (metaEl) {
            metaEl.textContent =
                total > 0
                    ? `${total} komentar ditemukan.`
                    : "Tidak ada komentar untuk site ini.";
        }
        renderComments(Array.isArray(comments) ? comments : []);
        openModalShell();
    };

    window.closeCapacityCommentsModal = function () {
        closeModalShell();
        resetModal();
    };

    modal.addEventListener("click", (event) => {
        if (event.target === modal) {
            window.closeCapacityCommentsModal();
        }
    });

    const closeBtn = modal.querySelector(".modal-close");
    closeBtn?.addEventListener("click", () =>
        window.closeCapacityCommentsModal()
    );

    document.addEventListener("keydown", (event) => {
        if (event.key === "Escape" && modal.classList.contains("active")) {
            window.closeCapacityCommentsModal();
        }
    });

    resetModal();
})();
