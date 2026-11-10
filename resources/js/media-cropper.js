/**
 * Alpine component for the crop page: Cropper.js on the source preview, synced to the Livewire edits.
 *
 * The preview (admin.media.source) is the oriented source scaled to at most 2048 px, so the crop is sent
 * as fractions of the frame the user sees (rotated and flipped), which the server maps to source pixels.
 *
 * Cropper draws the image as rotate(R) · scaleX · scaleY, i.e. it flips in the image's own axes and then
 * rotates. The server rotates first and flips the rotated frame (what the user sees). So for 90° and 270°
 * Cropper's scaleX is a visual vertical flip and scaleY a horizontal one; edits keep the visual meaning.
 *
 * Edits go to $wire without a request on every change; one request follows shortly after the user stops
 * (cropend, rotate, flip), so the server re-renders the "Result" line.
 *
 * Cropper.js and its CSS are imported on demand, so Vite puts them in their own chunk that only the crop
 * page loads.
 */

const COMMIT_DELAY = 300;

const normalize = (degrees) => ((Math.round(degrees) % 360) + 360) % 360;

const fraction = (value) => Math.min(1, Math.max(0, Math.round(value * 1e6) / 1e6));

export default function mediaCropper({ ratios }) {
    return {
        cropper: null,
        ready: false,
        failed: false,
        timer: null,

        init() {
            const image = this.$refs.image;

            if (!image) {
                return;
            }

            image.addEventListener('error', () => (this.failed = true), { once: true });

            if (image.complete && image.naturalWidth > 0) {
                this.start();
            } else {
                image.addEventListener('load', () => this.start(), { once: true });
            }
        },

        async start() {
            if (this.cropper) {
                return;
            }

            let Cropper;

            try {
                [{ default: Cropper }] = await Promise.all([import('cropperjs'), import('cropperjs/dist/cropper.css')]);
            } catch {
                this.failed = true;

                return;
            }

            if (this.cropper || !this.$refs.image?.isConnected) {
                return;
            }

            this.cropper = new Cropper(this.$refs.image, {
                viewMode: 1,
                autoCropArea: 1,
                dragMode: 'crop',
                background: false,
                movable: false,
                zoomable: false,
                toggleDragModeOnDblclick: false,
                aspectRatio: this.aspectRatioFor(this.$wire.ratio),
                ready: () => this.seed(),
                crop: () => this.pushCrop(),
                cropend: () => this.schedule(),
            });
        },

        destroy() {
            clearTimeout(this.timer);
            this.cropper?.destroy();
            this.cropper = null;
        },

        /** Apply the edits the page was opened with (stored ones, when an original is kept). */
        seed() {
            const edits = this.$wire.edits ?? {};
            const rotate = normalize(Number(edits.rotate) || 0);

            if (rotate !== 0) {
                this.turn(() => this.cropper.rotateTo(rotate));
            }

            const swap = rotate % 180 === 90;
            const scaleX = (swap ? edits.flip_v : edits.flip_h) ? -1 : 1;
            const scaleY = (swap ? edits.flip_h : edits.flip_v) ? -1 : 1;
            this.cropper.scale(scaleX, scaleY);

            const crop = edits.crop;

            if (crop && typeof crop === 'object') {
                const [width, height] = this.frameSize();
                this.cropper.setData({
                    x: crop.x * width,
                    y: crop.y * height,
                    width: crop.w * width,
                    height: crop.h * height,
                });
            }

            this.ready = true;
            this.pushCrop();
        },

        /**
         * Rotate, then fit the image back into the container and select all of it.
         *
         * Cropper grows the canvas to the rotated bounding box, and in viewMode 1 the canvas may not get smaller than
         * the crop box, so the crop box is cleared while rotating and drawn again afterwards.
         */
        turn(rotation) {
            this.cropper.clear();
            rotation();
            this.fit();
            this.cropper.crop();
            this.selectAll();
        },

        /** Scale the canvas to fit the container and center it. */
        fit() {
            const container = this.cropper.getContainerData();
            const canvas = this.cropper.getCanvasData();
            const scale = Math.min(container.width / canvas.width, container.height / canvas.height);
            const width = canvas.width * scale;
            const height = canvas.height * scale;

            this.cropper.setCanvasData({
                left: (container.width - width) / 2,
                top: (container.height - height) / 2,
                width,
            });
        },

        /** Select the whole image (within the aspect ratio), as after opening it. */
        selectAll() {
            const canvas = this.cropper.getCanvasData();
            const ratio = this.aspectRatioFor(this.$wire.ratio);
            let width = canvas.width;
            let height = canvas.height;

            if (!Number.isNaN(ratio)) {
                if (width / height > ratio) {
                    width = height * ratio;
                } else {
                    height = width / ratio;
                }
            }

            this.cropper.setCropBoxData({
                left: canvas.left + (canvas.width - width) / 2,
                top: canvas.top + (canvas.height - height) / 2,
                width,
                height,
            });
        },

        /** Natural size of the preview after the current rotation. */
        frameSize() {
            const { naturalWidth, naturalHeight } = this.cropper.getImageData();
            const rotate = normalize(this.cropper.getData().rotate ?? 0);

            return rotate % 180 === 90 ? [naturalHeight, naturalWidth] : [naturalWidth, naturalHeight];
        },

        pushCrop() {
            if (!this.ready) {
                return;
            }

            const data = this.cropper.getData();
            const [width, height] = this.frameSize();
            const x = fraction(data.x / width);
            const y = fraction(data.y / height);

            this.$wire.$set(
                'edits.crop',
                {
                    x,
                    y,
                    w: Math.min(fraction(data.width / width), fraction(1 - x)),
                    h: Math.min(fraction(data.height / height), fraction(1 - y)),
                },
                false,
            );
        },

        pushTransform() {
            const data = this.cropper.getData();
            const rotate = normalize(data.rotate ?? 0);
            const swap = rotate % 180 === 90;
            const flippedX = data.scaleX < 0;
            const flippedY = data.scaleY < 0;

            this.$wire.$set('edits.rotate', rotate, false);
            this.$wire.$set('edits.flip_h', swap ? flippedY : flippedX, false);
            this.$wire.$set('edits.flip_v', swap ? flippedX : flippedY, false);
        },

        rotate(degrees) {
            if (!this.ready) {
                return;
            }

            this.turn(() => this.cropper.rotate(degrees));
            this.pushTransform();
            this.pushCrop();
            this.schedule();
        },

        /** Flip along the visual axis: 'h' mirrors left and right, 'v' top and bottom. */
        flip(axis) {
            if (!this.ready) {
                return;
            }

            const data = this.cropper.getData();
            const swap = normalize(data.rotate ?? 0) % 180 === 90;
            const imageX = axis === 'h' ? !swap : swap;

            if (imageX) {
                this.cropper.scaleX(-(data.scaleX || 1));
            } else {
                this.cropper.scaleY(-(data.scaleY || 1));
            }

            this.pushTransform();
            this.pushCrop();
            this.schedule();
        },

        setRatio(key) {
            this.cropper?.setAspectRatio(this.aspectRatioFor(key));
            this.pushCrop();
            clearTimeout(this.timer);
            this.$wire.$set('ratio', key);
        },

        aspectRatioFor(key) {
            const ratio = ratios[key];

            return typeof ratio === 'number' ? ratio : NaN;
        },

        /** One request shortly after the last change, so the server re-renders the result size. */
        schedule() {
            clearTimeout(this.timer);
            this.timer = setTimeout(() => this.$wire.$commit(), COMMIT_DELAY);
        },
    };
}
