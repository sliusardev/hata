/**
 * Starts dragging media tiles or rows onto a folder in the library's folder tree.
 *
 * The files that move are decided here, at drag time: the whole selection ($wire.selected) when
 * the dragged file is in it, otherwise just that file. Checkbox values may be strings, so ids are
 * compared as numbers. With more than one file, the drag image is a small "N files" badge
 * instead of the tile; its text comes from the page's data-media-drag-labels attribute.
 *
 * @param {DragEvent} event
 * @param {number} id  the dragged file
 * @param {object} $wire  the library component
 */
export default function mediaDragStart(event, id, $wire) {
    const selected = Array.isArray($wire.selected) ? $wire.selected.map(Number) : [];
    const ids = selected.includes(Number(id)) ? selected : [Number(id)];

    event.dataTransfer.effectAllowed = 'move';
    event.dataTransfer.setData('application/x-media-ids', JSON.stringify(ids));

    if (ids.length < 2) {
        return;
    }

    const badge = document.createElement('div');
    badge.textContent = dragLabel(event.target, ids.length);
    badge.style.cssText =
        'position:fixed;top:-100px;left:-100px;padding:4px 10px;border-radius:9999px;' +
        'background:#27272a;color:#fff;font:600 13px/1.4 system-ui,sans-serif;white-space:nowrap';
    document.body.appendChild(badge);
    event.dataTransfer.setDragImage(badge, 12, 12);
    setTimeout(() => badge.remove(), 0);
}

/**
 * The translated "N files" text. The page renders the plural forms for 1, 2 and 5 (with ":count"
 * left in); Intl.PluralRules tells which of them the count's plural category belongs to.
 *
 * @param {Element} target
 * @param {number} count
 * @returns {string}
 */
function dragLabel(target, count) {
    let config = null;

    try {
        config = JSON.parse(target.closest('[data-media-drag-labels]')?.dataset.mediaDragLabels ?? 'null');
    } catch {
        config = null;
    }

    if (!config || !config.forms) {
        return String(count);
    }

    const rules = new Intl.PluralRules(config.locale);
    const samples = Object.keys(config.forms);
    const sample = samples.find((number) => rules.select(Number(number)) === rules.select(count)) ?? samples[samples.length - 1];

    return config.forms[sample].replace(':count', String(count));
}
