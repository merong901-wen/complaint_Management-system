const THEME_STORAGE_KEY = "complaint-system-theme";
const SIDEBAR_STORAGE_KEY = "complaint-system-sidebar";
const LOGIN_IDENTIFIER_KEY = "complaint-system-login-identifier";
const systemThemePreference = window.matchMedia("(prefers-color-scheme: dark)");

function readPreference(key) {
    try {
        return localStorage.getItem(key);
    } catch {
        return null;
    }
}

function savePreference(key, value) {
    try {
        localStorage.setItem(key, value);
    } catch {}
}

function applyTheme(isDark) {
    document.body.classList.toggle("dark-mode", isDark);
    document.querySelectorAll("[data-theme-toggle]").forEach((button) => {
        const label = button.querySelector("[data-theme-label]");
        if (label) {
            label.textContent = isDark ? "Light mode" : "Dark mode";
        } else {
            button.textContent = isDark ? "Light mode" : "Dark mode";
        }

        const icon = button.querySelector("[data-theme-icon]");
        if (icon?.classList.contains("sidebar-theme-icon")) {
            icon.textContent = isDark ? "☀" : "☾";
        } else if (icon) {
            icon.className = `fa-solid ${isDark ? "fa-sun" : "fa-moon"}`;
        }
        button.setAttribute("aria-pressed", String(isDark));
        button.setAttribute("aria-label", `Switch to ${isDark ? "light" : "dark"} mode`);
    });
    document.dispatchEvent(new CustomEvent("complaint-theme-change", { detail: { dark: isDark } }));
}

function toggleDarkMode() {
    const isDark = !document.body.classList.contains("dark-mode");
    savePreference(THEME_STORAGE_KEY, isDark ? "dark" : "light");
    applyTheme(isDark);
}

const savedTheme = readPreference(THEME_STORAGE_KEY);
applyTheme(savedTheme ? savedTheme === "dark" : systemThemePreference.matches);
systemThemePreference.addEventListener("change", (event) => {
    if (!readPreference(THEME_STORAGE_KEY)) applyTheme(event.matches);
});
document.addEventListener("click", (event) => {
    const target = event.target instanceof Element ? event.target : event.target.parentElement;
    if (!target) return;

    if (target.closest("[data-theme-toggle]")) toggleDarkMode();
    const passwordToggle = target.closest("[data-password-toggle]");
    if (passwordToggle) {
        const input = document.getElementById(passwordToggle.dataset.passwordToggle);
        if (input) {
            const isVisible = input.type === "password";
            input.type = isVisible ? "text" : "password";
            const eye = passwordToggle.querySelector("[data-eye-icon]");
            const eyeOff = passwordToggle.querySelector("[data-eye-off-icon]");
            if (eye && eyeOff) {
                eye.hidden = isVisible;
                eyeOff.hidden = !isVisible;
            } else {
                passwordToggle.textContent = isVisible ? "Hide" : "Show";
            }
            passwordToggle.setAttribute("aria-label", `${isVisible ? "Hide" : "Show"} password`);
            passwordToggle.setAttribute("aria-pressed", String(isVisible));
            input.focus({ preventScroll: true });
        }
    }
    if (target.closest("[data-sidebar-toggle]")) {
        const isCollapsed = !document.body.classList.contains("sidebar-collapsed");
        savePreference(SIDEBAR_STORAGE_KEY, isCollapsed ? "collapsed" : "expanded");
        applySidebarState(isCollapsed);
    }
});

function initializeAuthHelpers() {
    const identifier = document.getElementById("login-identifier");
    const remember = document.getElementById("remember-identifier");
    if (!identifier || !remember) return;

    let savedIdentifier = "";
    try { savedIdentifier = localStorage.getItem(LOGIN_IDENTIFIER_KEY) || ""; } catch {}
    if (savedIdentifier) {
        identifier.value = savedIdentifier;
        remember.checked = true;
    }
    identifier.form?.addEventListener("submit", () => {
        try {
            if (remember.checked) localStorage.setItem(LOGIN_IDENTIFIER_KEY, identifier.value.trim());
            else localStorage.removeItem(LOGIN_IDENTIFIER_KEY);
        } catch {}
    });
}

function normalizeForSearch(value) {
    return value.normalize("NFKD").replace(/[\u0300-\u036f]/g, "").toLocaleLowerCase();
}

function enhanceLongTables() {
    document.querySelectorAll("main.content .table-responsive > table, main.content table.table").forEach((table) => {
        if (table.dataset.localSearch === "ready" || table.id === "complaintsTable") return;
        const tbody = table.tBodies[0];
        if (!tbody) return;
        const rows = Array.from(tbody.rows).filter((row) => !row.querySelector("td[colspan]") && row.cells.length > 1);
        if (rows.length < 6) return;

        table.dataset.localSearch = "ready";
        const toolbar = document.createElement("div");
        toolbar.className = "table-quick-filter";
        const field = document.createElement("input");
        field.className = "form-control";
        field.type = "search";
        field.placeholder = "Filter this page…";
        field.setAttribute("aria-label", `Filter ${table.caption?.textContent.trim() || "table"} rows on this page`);
        const status = document.createElement("span");
        status.className = "table-quick-filter-status";
        status.setAttribute("role", "status");
        toolbar.append(field, status);
        const host = table.closest(".table-responsive") || table;
        host.parentNode.insertBefore(toolbar, host);

        const update = () => {
            const query = normalizeForSearch(field.value.trim());
            let visible = 0;
            rows.forEach((row) => {
                const matches = !query || normalizeForSearch(row.textContent).includes(query);
                row.hidden = !matches;
                if (matches) visible++;
            });
            status.textContent = query ? `${visible} of ${rows.length} rows shown` : `${rows.length} rows on this page`;
        };
        field.addEventListener("input", update);
        update();
    });
}

function enhanceCharacterLimits() {
    document.querySelectorAll("input[maxlength], textarea[maxlength]").forEach((field) => {
        if (field.dataset.counterReady) return;
        field.dataset.counterReady = "true";
        const counter = document.createElement("small");
        counter.className = "field-character-count";
        counter.setAttribute("aria-live", "polite");
        field.insertAdjacentElement("afterend", counter);
        const update = () => {
            const remaining = Number(field.maxLength) - field.value.length;
            counter.textContent = `${field.value.length} / ${field.maxLength}`;
            counter.dataset.nearLimit = String(remaining <= Math.min(20, Math.ceil(field.maxLength * .1)));
        };
        field.addEventListener("input", update);
        update();
    });
}

function enhanceStrongPasswordInputs(root = document) {
    if (document.body.classList.contains("auth-page")) return;
    const inputs = root.matches?.('input[type="password"][minlength="12"]')
        ? [root]
        : Array.from(root.querySelectorAll?.('input[type="password"][minlength="12"]') || []);
    inputs.forEach((input) => {
        if (input.dataset.passwordHelperReady || input.parentElement?.querySelector("[data-password-toggle]")) return;
        input.dataset.passwordHelperReady = "true";
        const wrapper = document.createElement("div");
        wrapper.className = "auth-password-field password-helper-field mb-3";
        input.parentNode.insertBefore(wrapper, input);
        input.classList.remove("mb-3");
        wrapper.appendChild(input);

        const toggle = document.createElement("button");
        toggle.type = "button";
        toggle.className = "password-toggle";
        toggle.dataset.passwordToggle = input.id || (input.id = `password-${Math.random().toString(36).slice(2)}`);
        toggle.textContent = "Show";
        toggle.setAttribute("aria-label", "Show password");
        toggle.setAttribute("aria-pressed", "false");
        wrapper.appendChild(toggle);

        const meter = document.createElement("div");
        meter.className = "password-meter";
        meter.setAttribute("aria-hidden", "true");
        const fill = document.createElement("span");
        meter.appendChild(fill);
        const hint = document.createElement("small");
        hint.className = "password-guidance password-helper-hint";
        hint.textContent = "Use at least 12 characters with uppercase, lowercase, a number, and a symbol.";
        const status = document.createElement("small");
        status.className = "password-guidance password-helper-status";
        status.setAttribute("aria-live", "polite");
        wrapper.insertAdjacentElement("afterend", meter);
        meter.insertAdjacentElement("afterend", hint);
        hint.insertAdjacentElement("afterend", status);

        input.addEventListener("input", () => {
            const value = input.value;
            if (!value) {
                fill.style.width = "0";
                status.textContent = "";
                input.setCustomValidity("");
                return;
            }
            const checks = [value.length >= 12, /[A-Z]/.test(value), /[a-z]/.test(value), /[0-9]/.test(value), /[^a-zA-Z0-9]/.test(value)];
            const passed = checks.filter(Boolean).length;
            fill.style.width = `${passed * 20}%`;
            fill.dataset.strength = passed === 5 ? "strong" : passed >= 3 ? "medium" : "weak";
            status.textContent = passed === 5 ? "Strong password" : `${passed} of 5 requirements met`;
            input.setCustomValidity(passed === 5 ? "" : "Use at least 12 characters with uppercase, lowercase, a number, and a symbol.");
        });
    });
}

function guardDuplicateSubmissions(event) {
    const form = event.target;
    if (!(form instanceof HTMLFormElement) || event.defaultPrevented || form.dataset.allowRepeat === "true") return;
    if (form.dataset.submitting === "true") {
        event.preventDefault();
        return;
    }
    form.dataset.submitting = "true";
    form.setAttribute("aria-busy", "true");
}

function initializePageHelpers() {
    enhanceLongTables();
    enhanceCharacterLimits();
    enhanceStrongPasswordInputs();
    const observer = new MutationObserver((mutations) => {
        mutations.forEach((mutation) => mutation.addedNodes.forEach((node) => {
            if (node instanceof HTMLElement) enhanceStrongPasswordInputs(node);
        }));
    });
    observer.observe(document.body, { childList: true, subtree: true });
}

if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", initializeAuthHelpers, { once: true });
    document.addEventListener("DOMContentLoaded", initializePageHelpers, { once: true });
} else {
    initializeAuthHelpers();
    initializePageHelpers();
}
document.addEventListener("submit", guardDuplicateSubmissions);

function applySidebarState(isCollapsed) {
    document.body.classList.toggle("sidebar-collapsed", isCollapsed);
    document.querySelectorAll("[data-sidebar-toggle]").forEach((button) => {
        const label = button.querySelector("[data-sidebar-label]");
        const action = isCollapsed ? "Expand" : "Collapse";
        if (label) label.textContent = action;
        button.setAttribute("aria-expanded", String(!isCollapsed));
        button.setAttribute("aria-label", `${action} sidebar`);
        button.title = `${action} sidebar`;
    });
}

const savedSidebarState = readPreference(SIDEBAR_STORAGE_KEY);
applySidebarState(savedSidebarState ? savedSidebarState === "collapsed" : window.matchMedia("(max-width: 700px)").matches);

function makeDashboardCardsClickable() {
    const path = window.location.pathname;
    const cards = document.querySelectorAll(".stat-grid > .stat, .stat-grid > .stat-card");

    cards.forEach((card) => {
        if (card.closest("a")) return;

        let destination = "";
        const label = card.querySelector("span")?.textContent.trim().toLowerCase() || "";
        if (path.includes("/teacher/")) {
            destination = "complaints.php";
        } else if (path.includes("/department_head/")) {
            if (label.includes("teacher")) {
                destination = "teachers.php";
            } else if (label.includes("approval")) {
                destination = "approvals.php";
            } else {
                const recentPanel = Array.from(document.querySelectorAll(".panel")).find((panel) => panel.querySelector("h2")?.textContent.includes("Recent department complaints"));
                if (recentPanel) recentPanel.id = "recent-department-complaints";
                destination = "#recent-department-complaints";
            }
        } else if (path.includes("/faculty_director/")) {
            destination = "registrations.php";
        }

        if (!destination) return;
        const link = document.createElement("a");
        link.href = destination;
        link.className = "ui-card-link";
        link.setAttribute("aria-label", `Open ${label || "dashboard details"}`);
        card.parentNode.insertBefore(link, card);
        link.appendChild(card);
    });
}

if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", makeDashboardCardsClickable, { once: true });
} else {
    makeDashboardCardsClickable();
}

window.addEventListener("storage", (event) => {
    if (event.key === THEME_STORAGE_KEY) applyTheme(event.newValue ? event.newValue === "dark" : systemThemePreference.matches);
    if (event.key === SIDEBAR_STORAGE_KEY) applySidebarState(event.newValue === "collapsed");
});

function initializeComplaintProgressChart(canvasId, endpoint, updatedLabelId, refreshButtonId, chartType = "bar") {
    const canvas = document.getElementById(canvasId);
    const updatedLabel = document.getElementById(updatedLabelId);
    const refreshButton = document.getElementById(refreshButtonId);
    const statuses = ["pending", "inprogress", "resolved", "closed"];

    if (!canvas || !updatedLabel || !window.Chart) {
        if (updatedLabel) updatedLabel.textContent = "Progress graph could not be loaded";
        return;
    }

    const isDoughnut = chartType === "doughnut";
    const chart = new Chart(canvas, {
        type: isDoughnut ? "doughnut" : "bar",
        data: {
            labels: ["Pending", "In progress", "Resolved", "Closed"],
            datasets: [{
                label: "Complaints",
                data: statuses.map(() => 0),
                backgroundColor: isDoughnut ? ["#F0B44B", "#06B6D4", "#8B7CF6", "#64748B"] : "#8B7CF6",
                borderColor: isDoughnut ? ["#fff", "#fff", "#fff", "#fff"] : "#8B7CF6",
                borderWidth: 1,
                borderRadius: 3,
                maxBarThickness: 42
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            ...(!isDoughnut ? {
                indexAxis: "y",
                scales: {
                    x: { beginAtZero: true, ticks: { precision: 0 }, grid: { drawBorder: false } },
                    y: { grid: { display: false } }
                }
            } : {}),
            plugins: { legend: { display: isDoughnut, position: "bottom", labels: { color: "#000" } } },
            animation: { duration: 250 }
        }
    });

    function updateChartTheme() {
        const foreground = document.body.classList.contains("dark-mode") ? "#fff" : "#000";
        const grid = document.body.classList.contains("dark-mode") ? "rgba(255,255,255,0.2)" : "rgba(0,0,0,0.2)";
        if (!isDoughnut) {
            chart.options.scales.x.ticks.color = foreground;
            chart.options.scales.y.ticks.color = foreground;
            chart.options.scales.x.grid.color = grid;
        } else {
            chart.options.plugins.legend.labels.color = foreground;
        }
        chart.update("none");
    }

    async function refreshProgress() {
        if (refreshButton) refreshButton.disabled = true;

        try {
            const separator = endpoint.includes("?") ? "&" : "?";
            const response = await fetch(`${endpoint}${separator}_=${Date.now()}`, { cache: "no-store" });
            if (!response.ok) throw new Error("Progress request failed");
            const counts = await response.json();
            chart.data.datasets[0].data = statuses.map((status) => Number(counts[status] || 0));
            updateChartTheme();
            updatedLabel.textContent = `Updated ${new Date().toLocaleTimeString([], { hour: "2-digit", minute: "2-digit" })} · refreshes automatically`;
        } catch (error) {
            updatedLabel.textContent = "Live progress is temporarily unavailable";
        } finally {
            if (refreshButton) refreshButton.disabled = false;
        }
    }

    if (refreshButton) refreshButton.addEventListener("click", refreshProgress);
    document.addEventListener("complaint-theme-change", updateChartTheme);
    updateChartTheme();
    refreshProgress();
    window.setInterval(refreshProgress, 60000);
}

/**
 * showToast - Show a temporary notification message (toast)
 * @param {string} message - Text to display
 * @param {number} duration - How long to display the toast in ms (default 3000)
 * @param {string} type - 'info', 'success', 'error', 'warning' (default 'info')
 *
 * Usage:
 * showToast("Complaint added!", 3000, "success");
 */
function showToast(message, duration = 3000, type = "info") {
    // Create container if it doesn't exist
    let container = document.getElementById("toastContainer");
    if (!container) {
        container = document.createElement("div");
        container.id = "toastContainer";
        container.style.position = "fixed";
        container.style.bottom = "20px";
        container.style.right = "20px";
        container.style.zIndex = 9999;
        document.body.appendChild(container);
    }

    // Create toast
    let toast = document.createElement("div");
    toast.innerText = message;
    toast.style.padding = "12px 20px";
    toast.style.borderRadius = "8px";
    toast.style.marginTop = "10px";
    toast.style.color = "#fff";
    toast.style.opacity = 0;
    toast.style.transition = "opacity 0.5s, transform 0.3s";
    toast.style.boxShadow = "0 4px 12px rgba(0,0,0,0.3)";
    toast.style.minWidth = "200px";
    toast.style.textAlign = "center";
    toast.style.fontFamily = "'Poppins', sans-serif";

    // Background color based on type
    switch (type) {
        case "success":
        case "error":
        case "warning":
        default: toast.style.backgroundColor = "#7565e8";
    }

    container.appendChild(toast);

    // Show toast
    setTimeout(() => { toast.style.opacity = 1; toast.style.transform = "translateY(-5px)"; }, 100);

    // Hide and remove toast
    setTimeout(() => {
        toast.style.opacity = 0;
        toast.style.transform = "translateY(0)";
        setTimeout(() => { toast.remove(); }, 500);
    }, duration);
}
