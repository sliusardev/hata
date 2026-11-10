/**
 * Starts dragging media tiles or rows onto a folder in the library's folder tree.
 *
 * ids are the files that move: the whole selection when the dragged file is selected,
 * otherwise just that file. With more than one file, the drag image is a small
 * "N files" badge instead of the tile.
 *
 * @param {DragEvent} event
 * @param {number[]} ids
 * @param {string} label  translated "N files" text for the badge
 */
export default function mediaDragStart(event, ids, label) {
    event.dataTransfer.effectAllowed = 'move';
    event.dataTransfer.setData('application/x-media-ids', JSON.stringify(ids));

    if (ids.length < 2) {
        return;
    }

    const badge = document.createElement('div');
    badge.textContent = label;
    badge.style.cssText =
        'position:fixed;top:-100px;left:-100px;padding:4px 10px;border-radius:9999px;' +
        'background:#27272a;color:#fff;font:600 13px/1.4 system-ui,sans-serif;white-space:nowrap';
    document.body.appendChild(badge);
    event.dataTransfer.setDragImage(badge, 12, 12);
    setTimeout(() => badge.remove(), 0);
}
