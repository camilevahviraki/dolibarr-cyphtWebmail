document.addEventListener("DOMContentLoaded", function () {

    initBuildForm();
    // Cancel button removed for now - setup.php no longer renders it.
    // Re-add initCancelButton()/removeCancelAfterBuild() once the build
    // pipeline itself works end to end; the backend support (requestCancel(),
    // the cancel-flag poll in CyphtManager::runProcess()) is still in place.

});

const buildUrl = "../admin/build/build.php";

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


        const formData = new FormData(form);

        try {
            const response = await fetch(buildUrl, {
                method: "POST",
                body: formData
            });

            if (!response.ok) {
                throw new Error("Build failed");
            }

            console.log("build response ==>", response);
            const reader = response.body.getReader();
            const decoder = new TextDecoder();

            const log = document.getElementById("cyphtwebmail-log");
            log.textContent = "";

            while (true) {
                const { done, value } = await reader.read();

                if (done) {
                    break;
                }

                const chunk = decoder.decode(value, { stream: true });

                log.textContent += chunk;
                log.scrollTop = log.scrollHeight;
            }
        } catch (err) {
            console.log(err, "form.action ==>", formData); 
        } finally {
            button.disabled = false;
            button.textContent = button.dataset.originalText || "Generate";
        }

    });
}

