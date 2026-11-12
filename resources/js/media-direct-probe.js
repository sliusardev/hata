/**
 * Probes whether the browser can fetch locally stored media as static files, and reports the result to the server.
 *
 * The page sets window.mediaDirectProbe = { url, token, report, auto }: the probe file's URL, its expected content,
 * the endpoint to report to, and whether to probe on page load (the layout sets it only while a check is due; the
 * settings page sets it always, with auto off, for the "Check again" button). Without the config nothing happens.
 */
let probed = false;

/** Laravel's XSRF-TOKEN cookie, which it accepts back in the X-XSRF-TOKEN header. */
function xsrfToken() {
    const value = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/)?.[1];

    return value === undefined ? undefined : decodeURIComponent(value);
}

/** Whether fetching the probe file returns exactly the expected token. */
async function fetchProbe(config) {
    try {
        const response = await fetch(config.url, { cache: 'no-store', credentials: 'omit' });

        return response.ok && (await response.text()).trim() === String(config.token).trim();
    } catch (error) {
        return false;
    }
}

/**
 * Probes now and reports the result.
 *
 * @returns {Promise<string>} the new state ('direct', 'unreachable', ...), or 'unchecked' when it could not be reported
 */
export async function checkMediaDirect() {
    const config = window.mediaDirectProbe;

    if (!config?.url || !config.report) {
        return 'unchecked';
    }

    probed = true;

    const ok = await fetchProbe(config);

    try {
        const headers = { Accept: 'application/json', 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' };
        const xsrf = xsrfToken();

        if (xsrf !== undefined) {
            headers['X-XSRF-TOKEN'] = xsrf;
        }

        const response = await fetch(config.report, {
            method: 'POST',
            headers,
            credentials: 'same-origin',
            body: JSON.stringify({ ok, url: config.url }),
        });

        return response.ok ? (await response.json()).state ?? 'unchecked' : 'unchecked';
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
