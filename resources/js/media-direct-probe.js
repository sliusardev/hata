/**
 * Probes whether the browser can load locally stored media as static files, and reports the result to the server.
 *
 * The page sets window.mediaDirectProbe = { url, width, height, report, csrf, auto }: the probe image's URL, the pixel
 * size it must have, the endpoint to report to with its CSRF token, and whether to probe on page load (the layout sets
 * it only while a check is due; the settings page sets it always, with auto off, for the "Check again" button).
 * Without the config nothing happens.
 *
 * The probe loads the image the way an <img> does (no CORS, so another host or scheme for the static URL does not
 * matter) and compares its natural size. Once the server has recorded a result, a `media-direct-checked` window event
 * carries the new state, so a status line on the page can refresh itself.
 */
let probed = false;

/** Milliseconds after which a probe image that neither loaded nor failed counts as failed. */
const PROBE_TIMEOUT = 15000;

/** Whether the probe image loads with exactly the expected size. */
function loadProbe(config) {
    return new Promise((resolve) => {
        const image = new Image();
        let timer;

        const settle = (ok) => {
            clearTimeout(timer);
            image.onload = null;
            image.onerror = null;
            resolve(ok);
        };

        timer = setTimeout(() => settle(false), PROBE_TIMEOUT);
        image.onload = () => settle(image.naturalWidth === Number(config.width) && image.naturalHeight === Number(config.height));
        image.onerror = () => settle(false);
        image.src = `${config.url}${config.url.includes('?') ? '&' : '?'}check=${Date.now()}`;
    });
}

/**
 * Probes now and reports the result.
 *
 * @param {boolean} force record the result even when the same one was stored minutes ago ("Check again")
 * @returns {Promise<string>} the new state ('direct', 'unreachable', ...), or 'unchecked' when it could not be reported
 */
export async function checkMediaDirect(force = false) {
    const config = window.mediaDirectProbe;

    if (!config?.url || !config.report) {
        return 'unchecked';
    }

    probed = true;

    const ok = await loadProbe(config);

    try {
        const response = await fetch(config.report, {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': config.csrf ?? '',
            },
            credentials: 'same-origin',
            body: JSON.stringify({ ok, url: config.url, force: force === true }),
        });

        if (!response.ok) {
            return 'unchecked';
        }

        const state = (await response.json()).state ?? 'unchecked';

        window.dispatchEvent(new CustomEvent('media-direct-checked', { detail: { state } }));

        return state;
    } catch (error) {
        return 'unchecked';
    }
}

function probeIfDue() {
    if (!probed && window.mediaDirectProbe?.auto === true) {
        checkMediaDirect();
    }
}

/** Probes once per full page load (and after a wire:navigate to a page that carries the config). */
export function probeOnLoad() {
    document.addEventListener('livewire:navigated', probeIfDue);

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', probeIfDue, { once: true });
    } else {
        probeIfDue();
    }
}
