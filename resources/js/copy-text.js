/**
 * Copies text to the clipboard from a click handler. Call it synchronously inside the click.
 *
 * Without the async clipboard API (plain HTTP), the text is copied right away with
 * execCommand('copy') from a hidden textarea. That is still inside the click, which browsers
 * require for it. The textarea uses a 16px font so iOS doesn't zoom, and setSelectionRange so iOS
 * selects its text. Focus goes back to the element that had it, so keyboard users keep their
 * place. With the API (HTTPS), its promise decides. A rejection is not retried with execCommand,
 * because that would run outside the click, where Firefox and Safari block it.
 *
 * @param {string} text
 * @returns {Promise<boolean>} whether the text was copied
 */
export default function copyText(text) {
    if (window.isSecureContext && navigator.clipboard?.writeText) {
        return navigator.clipboard.writeText(text).then(
            () => true,
            () => false,
        );
    }

    return Promise.resolve(copyWithCommand(text));
}

/**
 * @param {string} text
 * @returns {boolean}
 */
function copyWithCommand(text) {
    const previous = document.activeElement;
    const area = document.createElement('textarea');

    area.value = text;
    area.setAttribute('readonly', '');
    area.style.cssText = 'position:fixed;top:0;left:0;opacity:0;pointer-events:none;font-size:16px';
    document.body.appendChild(area);
    area.focus();
    area.select();
    area.setSelectionRange(0, text.length);

    let copied = false;

    try {
        copied = document.execCommand('copy');
    } catch {
        copied = false;
    }

    area.remove();

    if (previous instanceof HTMLElement) {
        previous.focus({ preventScroll: true });
    }

    return copied;
}
