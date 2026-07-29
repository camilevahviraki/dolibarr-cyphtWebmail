document.addEventListener("DOMContentLoaded", function () {

    initBuildForm();
    initLogToggle();
    initLastBuildLog();
    // Cancel button removed for now - setup.php no longer renders it.
    // Re-add initCancelButton()/removeCancelAfterBuild() once the build
    // pipeline itself works end to end; the backend support (requestCancel(),
    // the cancel-flag poll in CyphtManager::runProcess()) is still in place.

});

const buildUrl = "../admin/build/build.php";

// Shared between the live-streaming build form and the "last build log"
// loaded on page load - one {t,c} object becomes one colored <span>.
// obj.t drives the CSS class (log-out/log-err/log-info, see the <style>
// block in setup.php); a missing/unrecognized type falls back to "info"
// rather than silently rendering unstyled.
function appendLogLine(log, obj) {
    const span = document.createElement("span");
    span.className = "log-" + (obj && obj.t ? obj.t : "info");
    span.textContent = obj ? obj.c : "";
    log.appendChild(span);
    log.scrollTop = log.scrollHeight;
}

// Renders a complete NDJSON string (already fully received - no partial
// lines to buffer, unlike the streaming path below) into the log box.
// Used for the persisted last-build log, which arrives as one whole
// string embedded in the page rather than in chunks over time.
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
// render time (persisted incrementally by CyphtBuildPipeline as it
// builds, so this survives a page reload, unlike the in-memory-only log
// that used to disappear the moment you left the streaming request).
// Rendered into the log box immediately but the box itself stays hidden
// per its default style - "on click" is what setLogVisible()/the toggle
// button are for, this just makes sure there's something to show.
function initLastBuildLog() {
    const source = document.getElementById("cyphtwebmail-last-log");
    const log = document.getElementById("cyphtwebmail-log");
    if (!source || !log) return;

    let text;
    try {
        text = JSON.parse(source.textContent);
    } catch (e) {
        console.log("Could not parse embedded last-build-log JSON:", e);
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
        // The button itself starts hidden (via inline style from
        // setup.php) when there was no previous build to show. The first
        // time this function is asked to actually show something - a
        // build just started, or the last-build-log loader found content
        // - is also the first time there's anything to toggle, so make
        // the button itself visible too, not just its label.
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
        console.log("Build form submitted, disabling button and changing text.");

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

            // Do NOT bail out here on a non-2xx status. The server still
            // sends a normal NDJSON body even on failure - including the
            // one case that's actually a clean, expected outcome rather
            // than a crash: the "a build is already running" guard,
            // which returns before any output has been streamed, so
            // http_response_code(500) actually takes effect instead of
            // being a no-op like it is once headers are already sent.
            // Throwing here on !response.ok, like this used to, discarded
            // that whole explanatory body and left the user with nothing
            // but a generic "Build failed" - the log below is what
            // should tell them why, same as any other outcome.
            if (!response.ok) {
                console.log("Build response was not ok (status " + response.status + ") - reading body anyway, it should explain why.");
            }

            console.log("build response ==>", response);
            const reader = response.body.getReader();
            const decoder = new TextDecoder();

            const log = document.getElementById("cyphtwebmail-log");
            log.textContent = "";

            // Server sends one JSON object per line (NDJSON) - {t, c} -
            // instead of raw text, so real stdout, real stderr, and our
            // own status lines can be colored differently (appendLogLine,
            // module-scope, shared with the last-build-log loader above).
            // A single reader.read() chunk can split a line in half (or
            // contain several), so lines are buffered across reads and
            // only parsed once a full "\n"-terminated line has arrived -
            // that part is streaming-specific, unlike renderNdjsonText()
            // above which always has the complete text upfront.
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
            console.log(err, "form.action ==>", formData); 
        } finally {
            button.disabled = false;
            button.textContent = button.dataset.originalText || "Generate";
        }

    });
}

