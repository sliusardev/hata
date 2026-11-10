/**
 * Alpine component of the media library page: the drop overlay and the upload queue.
 *
 * Files go into a queue and are sent one at a time: optionally shrunk in the browser,
 * sent with $wire.upload('upload', ...), then stored with $wire.storeUpload(). A file
 * that fails shows its error on its tile and the queue goes on with the next one.
 *
 * Config (from the page): canUpload, clientResize, clientSide (px), accept (extensions), types ({ext: kind}),
 * limits ({kind: bytes}), locale and texts (translated strings with :placeholders).
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

    for (let index = 3; index < pixels.length; index += 4) {
        if (pixels[index] < 255) {
            return true;
        }
    }

    return false;
}

export default function mediaUploader(config) {
    /** The picked File objects, kept out of Alpine's reactive proxies (createImageBitmap needs the real Blob). */
    const files = new Map();
    let nextId = 1;

    return {
        queue: [],
        running: false,
        dragging: false,
        dragDepth: 0,
        collapsed: false,

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

        hasFiles(event) {
            return config.canUpload && Array.from(event.dataTransfer?.types ?? []).includes('Files');
        },

        dragEnter(event) {
            if (!this.hasFiles(event)) {
                return;
            }

            this.dragDepth++;
            this.dragging = true;
        },

        dragOver(event) {
            if (!this.hasFiles(event)) {
                return;
            }

            event.preventDefault();
            event.dataTransfer.dropEffect = 'copy';
        },

        dragLeave(event) {
            if (!this.hasFiles(event)) {
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
            this.dragDepth = 0;
            this.dragging = false;
            this.enqueue(event.dataTransfer.files);
        },

        pick(input) {
            this.enqueue(input.files);
            input.value = '';
        },

        enqueue(fileList) {
            if (!config.canUpload) {
                return;
            }

            for (const file of Array.from(fileList ?? [])) {
                const id = nextId++;
                const extension = extensionOf(file.name);
                const error = this.precheck(file, extension);
                files.set(id, file);

                this.queue.push({
                    id,
                    name: file.name,
                    extension,
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

            if (file.size > config.limits[kind]) {
                return replace(config.texts.too_large, {
                    size: this.megabytes(file.size),
                    limit: this.megabytes(config.limits[kind]),
                });
            }

            return null;
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
                const file = await this.shrink(picked, item.extension);

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
         * Shrink a photo to clientSide px on its long side (and convert HEIC) before upload.
         * Any failure returns the picked file, so the server gets the original.
         */
        async shrink(file, extension) {
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
