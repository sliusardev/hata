/**
 * Alpine component for the media grid: draw a box with the mouse to select the tiles it touches.
 *
 * The box starts only on empty space (not on a tile, a button or a link), so dragging a tile onto
 * a folder keeps working. While drawing, touched tiles get data-boxed="true"; on release the ids
 * go to $wire.selectBox(ids, add). Shift or Ctrl/Cmd adds to the current selection, otherwise the
 * box replaces it. A press without movement (under 5 px) does nothing.
 */
const THRESHOLD = 5;

export default function mediaMarquee() {
    return {
        box: null,
        start: null,
        add: false,

        begin(event) {
            if (event.button !== 0 || event.pointerType === 'touch') {
                return;
            }

            if (event.target.closest('[data-media-id], button, a, input, label, select, textarea')) {
                return;
            }

            this.start = { x: event.clientX, y: event.clientY };
            this.add = event.shiftKey || event.metaKey || event.ctrlKey;
            this.box = null;

            const move = (moveEvent) => this.update(moveEvent);
            const end = (endEvent) => {
                window.removeEventListener('pointermove', move);
                window.removeEventListener('pointerup', end);
                this.finish(endEvent);
            };

            window.addEventListener('pointermove', move);
            window.addEventListener('pointerup', end);
        },

        update(event) {
            if (!this.start) {
                return;
            }

            const left = Math.min(this.start.x, event.clientX);
            const top = Math.min(this.start.y, event.clientY);
            const width = Math.abs(event.clientX - this.start.x);
            const height = Math.abs(event.clientY - this.start.y);

            if (!this.box && width < THRESHOLD && height < THRESHOLD) {
                return;
            }

            event.preventDefault();
            this.box = { left, top, width, height };

            for (const tile of this.tiles()) {
                tile.dataset.boxed = this.touches(tile) ? 'true' : 'false';
            }
        },

        finish() {
            if (this.box) {
                const ids = this.tiles()
                    .filter((tile) => this.touches(tile))
                    .map((tile) => Number(tile.dataset.mediaId));

                this.$wire.selectBox(ids, this.add);
            }

            for (const tile of this.tiles()) {
                delete tile.dataset.boxed;
            }

            this.start = null;
            this.box = null;
        },

        tiles() {
            return Array.from(this.$el.querySelectorAll('[data-media-id]'));
        },

        touches(tile) {
            const rect = tile.getBoundingClientRect();
            const box = this.box;

            return (
                box !== null &&
                rect.left < box.left + box.width &&
                rect.right > box.left &&
                rect.top < box.top + box.height &&
                rect.bottom > box.top
            );
        },

        /** Inline style of the box, in viewport coordinates. */
        boxStyle() {
            const box = this.box;

            return box ? `left:${box.left}px;top:${box.top}px;width:${box.width}px;height:${box.height}px` : '';
        },
    };
}
