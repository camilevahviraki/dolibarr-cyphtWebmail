document.addEventListener("DOMContentLoaded", function () {

    initBuildForm();
    initLogToggle();
    initLastBuildLog();
    // Cancel button removed for now; setup.php no longer renders it.
    // Backend support (requestCancel(), CyphtManager::runProcess()'s
    // cancel-flag poll) is still in place.

});

const buildUrl = "../admin/build/build.php";

// Shared between the live-streaming build form and the last-build-log
// loader. One {t,c} object becomes one colored <span>. obj.t drives the
// CSS class (log-out/log-err/log-info, see setup.php); a missing type
// falls back to "info".
function appendLogLine(log, obj) {
    const span = document.createElement("span");
    span.className = "log-" + (obj && obj.t ? obj.t : "info");
    span.textContent = obj ? obj.c : "";
    log.appendChild(span);
    log.scrollTop = log.scrollHeight;
}

// Renders a complete NDJSON string (already fully received, no partial
// lines to buffer) into the log box. Used for the persisted last-build
// log, which arrives as one whole string embedded in the page.
function renderNdjsonText(log, text) {
    const lines = text.split("\n");
    for (let i = 0; i < lines.length; i++) {
        const line = lines[i];
        if (line === "") continue;
        try {
            appendLogLine(log, JSON.parse(line));
        } catch (e) {
            appendLogLine(log, { t: "out", c: line });
        }
    }
}

// Loads whatever CyphtManager::getLastBuildLog() had on disk at page
// render time. Rendered into the log box immediately; the box itself
// stays hidden until the toggle button is clicked.
function initLastBuildLog() {
    const source = document.getElementById("cyphtwebmail-last-log");
    const log = document.getElementById("cyphtwebmail-log");
    if (!source || !log) return;

    let text;
    try {
        text = JSON.parse(source.textContent);
    } catch (e) {
        console.error("Could not parse embedded last-build-log JSON:", e);
        return;
    }

    if (!text) return;

    renderNdjsonText(log, text);
}

// Shared by the toggle button and the build form (which auto-shows the
// log itself once a build actually starts, so nobody has to remember to
// click "Show" first to watch it happen).
function setLogVisible(visible) {
    const wrap = document.getElementById("cyphtwebmail-log-wrap");
    const toggle = document.getElementById("cyphtwebmail-log-toggle");
    if (!wrap) return;

    wrap.style.display = visible ? "" : "none";

    if (toggle) {
        // The button starts hidden when there was no previous build.
        // The first time something is actually shown, make the button
        // visible too, not just its label.
        if (visible) {
            toggle.style.display = "";
        }
        toggle.textContent = visible
            ? (toggle.dataset.hideText || "Hide build log")
            : (toggle.dataset.showText || "Show build log");
    }
}

function initLogToggle() {
    const toggle = document.getElementById("cyphtwebmail-log-toggle");
    const wrap = document.getElementById("cyphtwebmail-log-wrap");

    if (!toggle || !wrap) return;

    toggle.addEventListener("click", function () {
        const currentlyVisible = wrap.style.display !== "none";
        setLogVisible(!currentlyVisible);
    });
}

function initBuildForm() {
    const form = document.getElementById("cypht-build-form");

    if (!form) return;

    form.addEventListener("submit", async function (e) {
        e.preventDefault();

        const button = form.querySelector('button[type="submit"]');
        if (!button) return;

        button.disabled = true;
        button.dataset.originalText = button.textContent;
        button.textContent = button.dataset.loadingText || 'Building...';
        setLogVisible(true);


        const formData = new FormData(form);

        try {
            const response = await fetch(buildUrl, {
                method: "POST",
                body: formData
            });

            // Do not bail out on a non-2xx status; the server still sends
            // a normal NDJSON body even on failure, e.g. the "build
            // already running" guard, and that body explains why.
            if (!response.ok) {
                console.log("Build response was not ok (status " + response.status + "), reading body anyway.");
            }

            const reader = response.body.getReader();
            const decoder = new TextDecoder();

            const log = document.getElementById("cyphtwebmail-log");
            log.textContent = "";

            // Server sends one JSON object per line (NDJSON), {t, c},
            // rendered via appendLogLine(). A reader.read() chunk can
            // split a line in half, so lines are buffered across reads
            // and parsed once a full "\n"-terminated line has arrived.
            let buffer = "";

            const processBuffer = function (flushRemainder) {
                let newlineIndex;
                while ((newlineIndex = buffer.indexOf("\n")) !== -1) {
                    const line = buffer.slice(0, newlineIndex);
                    buffer = buffer.slice(newlineIndex + 1);
                    if (line === "") continue;
                    try {
                        appendLogLine(log, JSON.parse(line));
                    } catch (e) {
                        appendLogLine(log, { t: "out", c: line });
                    }
                }
                if (flushRemainder && buffer !== "") {
                    try {
                        appendLogLine(log, JSON.parse(buffer));
                    } catch (e) {
                        appendLogLine(log, { t: "out", c: buffer });
                    }
                    buffer = "";
                }
            };

            while (true) {
                const { done, value } = await reader.read();

                if (done) {
                    processBuffer(true);
                    break;
                }

                buffer += decoder.decode(value, { stream: true });
                processBuffer(false);
            }
        } catch (err) {
            console.error(err);
        } finally {
            button.disabled = false;
            button.textContent = button.dataset.originalText || "Generate";
        }

    });
}

