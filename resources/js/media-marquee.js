/**
 * Alpine component for the media grid: draw a box with the mouse to select the tiles it touches.
 *
 * The box starts only on empty space (not on a tile, a button or a link), so dragging a tile onto
 * a folder keeps working. While drawing, touched tiles get data-boxed="true"; on release the ids
 * go to $wire.selectBox(ids, add). Shift or Ctrl/Cmd adds to the current selection, otherwise the
 * box replaces it. A press without movement (under 5 px) does nothing.
 *
 * The start point, the pointer and the tile rects are kept in page coordinates, so scrolling while
 * drawing doesn't make the box drift; the box itself is position: fixed and drawn at page
 * coordinates minus the current scroll. Tile rects are measured once, when the box first appears.
 * Pointer moves and scrolls are folded into one update per animation frame.
 */
const THRESHOLD = 5;

export default function mediaMarquee() {
    return {
        box: null,
        start: null,
        add: false,
        pointer: null,
        rects: null,
        frame: null,
        listeners: null,
        root: null,

        begin(event) {
            if (event.button !== 0 || event.pointerType === 'touch') {
                return;
            }

            if (event.target.closest('[data-media-id], [data-media-folder], button, a, input, label, select, textarea')) {
                return;
            }

            this.finish({ cancelled: true });

            this.root = this.$el;
            this.start = { x: event.clientX + window.scrollX, y: event.clientY + window.scrollY };
            this.pointer = { x: event.clientX, y: event.clientY };
            this.add = event.shiftKey || event.metaKey || event.ctrlKey;
            this.box = null;
            this.rects = null;

            const move = (moveEvent) => {
                if (moveEvent.buttons === 0) {
                    this.finish();

                    return;
                }

                this.pointer = { x: moveEvent.clientX, y: moveEvent.clientY };

                if (this.box) {
                    moveEvent.preventDefault();
                }

                this.schedule();
            };
            const scroll = () => this.schedule();
            const end = () => this.finish();
            const cancel = () => this.finish({ cancelled: true });

            this.listeners = { pointermove: move, pointerup: end, pointercancel: cancel, scroll };

            for (const [name, listener] of Object.entries(this.listeners)) {
                window.addEventListener(name, listener, name === 'scroll' ? { passive: true } : undefined);
            }
        },

        /** Runs update() once on the next animation frame, however many events arrive before it. */
        schedule() {
            if (this.frame === null) {
                this.frame = requestAnimationFrame(() => {
                    this.frame = null;
                    this.update();
                });
            }
        },

        update() {
            if (!this.start || !this.pointer) {
                return;
            }

            const x = this.pointer.x + window.scrollX;
            const y = this.pointer.y + window.scrollY;
            const box = {
                left: Math.min(this.start.x, x),
                top: Math.min(this.start.y, y),
                width: Math.abs(x - this.start.x),
                height: Math.abs(y - this.start.y),
            };

            if (!this.box && box.width < THRESHOLD && box.height < THRESHOLD) {
                return;
            }

            if (this.rects === null) {
                this.rects = this.measure();
            }

            this.box = box;

            for (const { tile, rect } of this.rects) {
                const boxed = this.touches(rect) ? 'true' : 'false';

                if (tile.dataset.boxed !== boxed) {
                    tile.dataset.boxed = boxed;
                }
            }
        },

        /**
         * Ends the drag: removes every listener, sends the touched ids unless the pointer was
         * cancelled, and clears the highlight.
         */
        finish({ cancelled = false } = {}) {
            if (this.listeners) {
                for (const [name, listener] of Object.entries(this.listeners)) {
                    window.removeEventListener(name, listener);
                }

                this.listeners = null;
            }

            if (this.frame !== null) {
                cancelAnimationFrame(this.frame);
                this.frame = null;
            }

            if (!cancelled && this.start) {
                this.update();
            }

            if (!cancelled && this.box) {
                const ids = (this.rects ?? [])
                    .filter(({ rect }) => this.touches(rect))
                    .map(({ tile }) => Number(tile.dataset.mediaId));

                this.$wire.selectBox(ids, this.add);
            }

            for (const { tile } of this.rects ?? []) {
                delete tile.dataset.boxed;
            }

            this.start = null;
            this.pointer = null;
            this.box = null;
            this.rects = null;
        },

        /** Every tile with its rect in page coordinates. */
        measure() {
            const root = this.root ?? this.$el;

            return Array.from(root.querySelectorAll('[data-media-id]')).map((tile) => {
                const rect = tile.getBoundingClientRect();

                return {
                    tile,
                    rect: {
                        left: rect.left + window.scrollX,
                        right: rect.right + window.scrollX,
                        top: rect.top + window.scrollY,
                        bottom: rect.bottom + window.scrollY,
                    },
                };
            });
        },

        touches(rect) {
            const box = this.box;

            return (
                box !== null &&
                rect.left < box.left + box.width &&
                rect.right > box.left &&
                rect.top < box.top + box.height &&
                rect.bottom > box.top
            );
        },

        /** Inline style of the box: page coordinates minus the current scroll, since the box is fixed. */
        boxStyle() {
            const box = this.box;

            return box
                ? `left:${box.left - window.scrollX}px;top:${box.top - window.scrollY}px;width:${box.width}px;height:${box.height}px`
                : '';
        },
    };
}
