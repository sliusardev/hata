/**
 * Alpine component of the media library page: the drop overlay and the upload queue.
 *
 * Files go into a queue and are sent one at a time: optionally shrunk in the browser,
 * sent with $wire.upload('upload', ...), then stored with $wire.storeUpload(). A file
 * that fails shows its error on its tile and the queue goes on with the next one.
 *
 * The details panel's "Replace file" reuses prepareFile() and sends the file as the "replacement" property.
 *
 * Config (from the page): canUpload, clientResize, clientSide (px), accept (extensions), types ({ext: kind}),
 * limits ({kind: bytes}), uploadCap (PHP's upload cap in bytes, null = none), locale and texts
 * (translated strings with :placeholders).
 */

/** Formats the browser can decode and the canvas can re-encode. */
const RESIZABLE = ['jpg', 'jpeg', 'png', 'webp', 'heic', 'heif'];

/** Formats the server may not decode, so they are converted even when no scaling is needed. */
const ALWAYS_CONVERT = ['heic', 'heif'];

/** Formats that may carry transparency, which JPEG would lose. */
const MAY_HAVE_ALPHA = ['png', 'webp'];

/** Formats that get a preview in the queue (object URL). */
const PREVIEWABLE = ['jpg', 'jpeg', 'png', 'webp', 'gif'];

const JPEG_QUALITY = 0.92;

/** The overlay hides when no dragover arrived for this long (the cursor left the window, the drag was cancelled). */
const DRAG_TIMEOUT = 300;

function extensionOf(name) {
    const dot = name.lastIndexOf('.');

    return dot === -1 ? '' : name.slice(dot + 1).toLowerCase();
}

function baseName(name) {
    const dot = name.lastIndexOf('.');

    return dot <= 0 ? name : name.slice(0, dot);
}

function replace(text, values) {
    return Object.entries(values).reduce((result, [key, value]) => result.replaceAll(`:${key}`, value), text);
}

/**
 * Whether any pixel of the drawn image is not fully opaque, checked on a small copy to save memory.
 */
function hasAlpha(source, width, height) {
    const scale = Math.min(1, 256 / Math.max(width, height));
    const canvas = document.createElement('canvas');
    canvas.width = Math.max(1, Math.round(width * scale));
    canvas.height = Math.max(1, Math.round(height * scale));
    const context = canvas.getContext('2d');
    context.drawImage(source, 0, 0, canvas.width, canvas.height);
    const pixels = context.getImageData(0, 0, canvas.width, canvas.height).data;
    canvas.width = canvas.height = 0;

    for (let index = 3; index < pixels.length; index += 4) {
        if (pixels[index] < 255) {
            return true;
        }
    }

    return false;
}

/**
 * Shrink a photo to config.clientSide px on its long side (and convert HEIC) before upload.
 * Any failure returns the picked file, so the server gets the original.
 */
export async function prepareFile(file, config) {
    const extension = extensionOf(file.name);

    if (!config.clientResize || !RESIZABLE.includes(extension) || typeof createImageBitmap !== 'function') {
        return file;
    }

    let bitmap;

    try {
        bitmap = await createImageBitmap(file, { imageOrientation: 'from-image' });
        const longSide = Math.max(bitmap.width, bitmap.height);
        const scale = longSide > config.clientSide ? config.clientSide / longSide : 1;

        if (scale === 1 && !ALWAYS_CONVERT.includes(extension)) {
            return file;
        }

        const canvas = document.createElement('canvas');
        canvas.width = Math.max(1, Math.round(bitmap.width * scale));
        canvas.height = Math.max(1, Math.round(bitmap.height * scale));
        canvas.getContext('2d').drawImage(bitmap, 0, 0, canvas.width, canvas.height);

        const keepAlpha = MAY_HAVE_ALPHA.includes(extension) && hasAlpha(canvas, canvas.width, canvas.height);
        const type = keepAlpha ? 'image/png' : 'image/jpeg';
        const blob = await new Promise((resolve) => canvas.toBlob(resolve, type, JPEG_QUALITY));
        canvas.width = canvas.height = 0;

        if (!blob) {
            return file;
        }

        return new File([blob], `${baseName(file.name)}.${keepAlpha ? 'png' : 'jpg'}`, {
            type,
            lastModified: file.lastModified,
        });
    } catch (error) {
        return file;
    } finally {
        bitmap?.close?.();
    }
}

export default function mediaUploader(config) {
    /** The picked File objects, kept out of Alpine's reactive proxies (createImageBitmap needs the real Blob). */
    const files = new Map();
    let nextId = 1;
    let dragTimer = null;

    return {
        queue: [],
        running: false,
        dragging: false,
        dragDepth: 0,
        collapsed: false,
        replacing: false,

        get doneCount() {
            return this.queue.filter((item) => item.status === 'done').length;
        },

        get busy() {
            return this.queue.some((item) => !['done', 'failed'].includes(item.status));
        },

        /** Files still on their way (or failed), shown as tiles before the grid. */
        get pendingTiles() {
            return this.queue.filter((item) => item.status !== 'done');
        },

        get queueTitle() {
            return replace(config.texts.title, { done: this.doneCount, total: this.queue.length });
        },

        /**
         * Whether the drag carries files from the computer. A tile dragged onto a folder is not one,
         * even though Chrome lists "Files" for a dragged image.
         */
        hasFiles(event) {
            const types = Array.from(event.dataTransfer?.types ?? []);

            return types.includes('Files') && !types.includes('application/x-media-ids');
        },

        dragEnter(event) {
            if (!this.hasFiles(event) || !config.canUpload) {
                return;
            }

            this.dragDepth++;
            this.dragging = true;
        },

        /** Files are never dropped onto the page itself (the browser would open them); only uploaders see the overlay. */
        dragOver(event) {
            if (!this.hasFiles(event)) {
                return;
            }

            event.preventDefault();
            event.dataTransfer.dropEffect = config.canUpload ? 'copy' : 'none';

            if (config.canUpload) {
                this.dragging = true;
                clearTimeout(dragTimer);
                dragTimer = setTimeout(() => this.resetDrag(), DRAG_TIMEOUT);
            }
        },

        dragLeave(event) {
            if (!this.hasFiles(event) || !config.canUpload) {
                return;
            }

            this.dragDepth = Math.max(0, this.dragDepth - 1);
            this.dragging = this.dragDepth > 0;
        },

        drop(event) {
            if (!this.hasFiles(event)) {
                return;
            }

            event.preventDefault();
            this.resetDrag();

            if (config.canUpload) {
                this.enqueue(event.dataTransfer.files);
            }
        },

        resetDrag() {
            clearTimeout(dragTimer);
            this.dragDepth = 0;
            this.dragging = false;
        },

        pick(input) {
            this.enqueue(input.files);
            input.value = '';
        },

        enqueue(fileList) {
            if (!config.canUpload) {
                return;
            }

            // The folder open now, so switching folders while the queue runs doesn't change where files go.
            const folderId = /^\d+$/.test(String(this.$wire.folder ?? '')) ? Number(this.$wire.folder) : null;

            for (const file of Array.from(fileList ?? [])) {
                const id = nextId++;
                const extension = extensionOf(file.name);
                const error = this.precheck(file, extension);
                files.set(id, file);

                this.queue.push({
                    id,
                    name: file.name,
                    extension,
                    folderId,
                    preview: PREVIEWABLE.includes(extension) ? URL.createObjectURL(file) : null,
                    status: error === null ? 'waiting' : 'failed',
                    percent: 0,
                    meta: '',
                    error,
                });
            }

            this.collapsed = false;
            this.run();
        },

        /**
         * The error text for a file the server would refuse anyway, or null.
         */
        precheck(file, extension) {
            const kind = config.types[extension];

            if (!config.accept.includes(extension) || kind === undefined) {
                return replace(config.texts.unsupported, { name: file.name });
            }

            // A file the browser may shrink is checked against the server cap only after shrinking (see process()).
            const shrinkable = config.clientResize && RESIZABLE.includes(extension);

            return this.sizeError(file.size, shrinkable ? config.limits[kind] : this.capped(config.limits[kind]));
        },

        /** The limit, lowered to the server upload cap when there is one. */
        capped(limit) {
            return config.uploadCap === null || config.uploadCap === undefined ? limit : Math.min(limit, config.uploadCap);
        },

        /** The too_large text when the size is over the limit, else null. */
        sizeError(size, limit) {
            if (size <= limit) {
                return null;
            }

            return replace(config.texts.too_large, {
                size: this.megabytes(size),
                limit: this.megabytes(limit),
            });
        },

        megabytes(bytes) {
            return (bytes / 1024 / 1024).toLocaleString(config.locale, {
                minimumFractionDigits: 1,
                maximumFractionDigits: 1,
            });
        },

        async run() {
            if (this.running) {
                return;
            }

            this.running = true;

            try {
                let item;

                while ((item = this.queue.find((candidate) => candidate.status === 'waiting'))) {
                    await this.process(item);
                }
            } finally {
                this.running = false;
            }
        },

        async process(item) {
            const picked = files.get(item.id);

            try {
                item.status = 'preparing';
                const file = await prepareFile(picked, config);
                const capError = this.sizeError(file.size, this.capped(Infinity));

                if (capError !== null) {
                    this.fail(item, capError);

                    return;
                }

                item.status = 'uploading';
                const sent = await new Promise((resolve) => {
                    this.$wire.upload(
                        'upload',
                        file,
                        () => resolve(true),
                        () => resolve(false),
                        (event) => {
                            item.percent = event.detail.progress;
                        },
                        () => resolve(false),
                    );
                });

                if (!sent) {
                    this.fail(item, config.texts.failed);

                    return;
                }

                item.status = 'compressing';
                this.$wire.uploadPickedSize = picked.size;
                this.$wire.uploadPickedExtension = item.extension;
                this.$wire.uploadFolderId = item.folderId;
                const result = await this.$wire.storeUpload();

                if (result?.ok) {
                    item.status = 'done';
                    item.meta = result.meta;
                    files.delete(item.id);
                } else {
                    this.fail(item, result?.error ?? config.texts.failed);
                }
            } catch (error) {
                this.fail(item, config.texts.failed);
            }
        },

        fail(item, message) {
            item.status = 'failed';
            item.error = message;
        },

        /**
         * "Replace file" in the details panel: shrink the picked file like an upload, send it as the
         * "replacement" property, then ask the server to open the confirmation dialog.
         */
        async replaceFile(input) {
            const picked = input.files?.[0];
            input.value = '';

            if (!picked || this.replacing) {
                return;
            }

            this.replacing = true;

            try {
                const error = this.precheck(picked, extensionOf(picked.name));

                if (error !== null) {
                    this.toastError(error);

                    return;
                }

                const file = await prepareFile(picked, config);
                const capError = this.sizeError(file.size, this.capped(Infinity));

                if (capError !== null) {
                    this.toastError(capError);

                    return;
                }

                const sent = await new Promise((resolve) => {
                    this.$wire.upload('replacement', file, () => resolve(true), () => resolve(false), () => {}, () => resolve(false));
                });

                if (!sent) {
                    this.toastError(config.texts.failed);

                    return;
                }

                this.$wire.replacementPickedSize = picked.size;
                this.$wire.replacementPickedExtension = extensionOf(picked.name);
                await this.$wire.confirmReplace();
            } catch (error) {
                this.toastError(config.texts.failed);
            } finally {
                this.replacing = false;
            }
        },

        toastError(text) {
            this.$flux.toast({ text, variant: 'danger' });
        },

        statusText(item) {
            switch (item.status) {
                case 'waiting':
                    return config.texts.waiting;
                case 'preparing':
                    return config.texts.preparing;
                case 'uploading':
                    return replace(config.texts.progress, { percent: item.percent });
                case 'compressing':
                    return config.texts.compressing;
                case 'done':
                    return item.meta;
                default:
                    return item.error;
            }
        },

        remove(item) {
            if (item.preview) {
                URL.revokeObjectURL(item.preview);
            }

            files.delete(item.id);
            this.queue = this.queue.filter((candidate) => candidate.id !== item.id);
        },

        /** Close the panel once nothing is left to send. */
        clear() {
            if (this.busy) {
                return;
            }

            [...this.queue].forEach((item) => this.remove(item));
        },
    };
}
