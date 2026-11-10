/**
 * Drag helpers shared by everything in the media library that shows folders: the folder tree and
 * the subfolder tiles above the files.
 *
 * Two drag payloads exist. File drags (media-drag.js) carry FILES_TYPE, a JSON list of ids; folder
 * drags carry FOLDER_TYPE, the id of the folder. A drop target decides from the types alone
 * (the data itself can't be read before the drop).
 */
export const FILES_TYPE = 'application/x-media-ids';
export const FOLDER_TYPE = 'application/x-media-folder';

/**
 * The payload kinds a drag event carries.
 *
 * @param {DragEvent} event
 * @returns {{folder: boolean, files: boolean}}
 */
export function dragKinds(event) {
    const types = Array.from(event.dataTransfer?.types ?? []);

    return { folder: types.includes(FOLDER_TYPE), files: types.includes(FILES_TYPE) };
}

/**
 * Marks a drag as moving the folder with this id.
 *
 * @param {DragEvent} event
 * @param {number} id
 */
export function startFolderDrag(event, id) {
    event.dataTransfer.effectAllowed = 'move';
    event.dataTransfer.setData(FOLDER_TYPE, String(id));
}

/**
 * What a drop carries: a folder id or a list of file ids (the folder wins when both are there).
 *
 * @param {DragEvent} event
 * @returns {{folderId: number|null, ids: number[]|null}}
 */
export function readDrop(event) {
    const folderId = event.dataTransfer.getData(FOLDER_TYPE);
    const ids = event.dataTransfer.getData(FILES_TYPE);

    if (folderId) {
        return { folderId: Number(folderId), ids: null };
    }

    return { folderId: null, ids: ids ? JSON.parse(ids) : null };
}

/**
 * True when a dragleave really left the element and didn't just move onto a child of it.
 *
 * Safari fires dragleave with no relatedTarget, so the pointer position is checked against the
 * element's box then.
 *
 * @param {DragEvent} event
 * @returns {boolean}
 */
export function leftElement(event) {
    const box = event.currentTarget.getBoundingClientRect();
    const inside = event.relatedTarget
        ? event.currentTarget.contains(event.relatedTarget)
        : event.clientX > box.left && event.clientX < box.right && event.clientY > box.top && event.clientY < box.bottom;

    return !inside;
}

/**
 * Alpine component for the subfolder tiles: each tile is a drop target for files (moveTo) and
 * folders (moveFolder) and, with edit rights, can be dragged itself as a folder.
 */
export function mediaFolderTiles() {
    return {
        over: null,
        dragging: null,

        accepts(event, target) {
            const kinds = dragKinds(event);

            return (kinds.folder && this.dragging !== target) || kinds.files;
        },

        enter(event, target) {
            if (!this.accepts(event, target)) {
                return;
            }

            event.preventDefault();
            event.dataTransfer.dropEffect = 'move';
            this.over = target;
        },

        leave(event) {
            if (leftElement(event)) {
                this.over = null;
            }
        },

        reset() {
            this.over = null;
            this.dragging = null;
        },

        start(event, id) {
            startFolderDrag(event, id);
            this.dragging = id;
        },

        drop(event, target) {
            const { folderId, ids } = readDrop(event);

            this.reset();

            if (folderId !== null) {
                if (folderId !== target) {
                    this.$wire.moveFolder(folderId, target);
                }
            } else if (ids) {
                this.$wire.moveTo(ids, target);
            }
        },
    };
}
