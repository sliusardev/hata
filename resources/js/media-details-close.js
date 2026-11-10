/**
 * Closing the media details panel without its × button: a click on empty space in the grid or list
 * area, and Escape. Both call $wire.closeDetails(), which asks first when the panel has unsaved changes.
 */
const INTERACTIVE = '[data-media-id], [data-media-folder], table, button, a, input, label, select, textarea, summary, [role], [popover], dialog, [contenteditable]';

function detailsOpen() {
    return document.querySelector('[data-media-details]') !== null;
}

/** A dialog or dropdown is open on top of the page. */
function overlayOpen() {
    try {
        return document.querySelector('dialog[open], :popover-open') !== null;
    } catch (error) {
        return document.querySelector('dialog[open]') !== null;
    }
}

/** A click that landed on the area's own background (not on a tile, control, panel, dialog or dropdown). */
export function emptyClick(event, wire) {
    if (event.button !== 0 || !detailsOpen() || overlayOpen()) {
        return;
    }

    // A marquee drag ends with a click on the grid itself; it selected tiles and is not a click on empty space.
    if (Date.now() - (window.mediaMarqueeEndedAt ?? 0) < 150) {
        return;
    }

    const target = event.target;

    if (!(target instanceof Element) || !target.closest('[data-media-area]')) {
        return;
    }

    const hit = target.closest(INTERACTIVE);

    if (hit !== null && hit.closest('[data-media-area]') !== null && !hit.matches('[data-media-area]')) {
        return;
    }

    wire.closeDetails();
}

/** Escape closes the panel unless focus is in a form field, a dialog or a dropdown. */
export function escapeKey(event, wire) {
    if (event.key !== 'Escape' || event.defaultPrevented || event.isComposing || !detailsOpen() || overlayOpen()) {
        return;
    }

    const target = event.target;

    // The trigger of a dropdown (Flux closes its menu on this same Escape) or anything inside one.
    if (target instanceof Element && target.closest('[aria-expanded], [aria-haspopup], ui-dropdown, [data-flux-dropdown]')) {
        return;
    }

    if (target instanceof Element && target.closest('input, textarea, select, [contenteditable], dialog, [popover], [role="menu"], [role="listbox"], [role="dialog"]')) {
        return;
    }

    wire.closeDetails();
}
