import copyText from './copy-text.js';
import mediaCropper from './media-cropper.js';
import { emptyClick, escapeKey } from './media-details-close.js';
import { checkMediaDirect, probeOnLoad } from './media-direct-probe.js';
import mediaDragStart from './media-drag.js';
import * as mediaFolderDrag from './media-folder-drag.js';
import { mediaFolderTiles } from './media-folder-drag.js';
import mediaMarquee from './media-marquee.js';
import mediaUploader from './media-uploader.js';

window.copyText = copyText;
window.mediaDragStart = mediaDragStart;
window.mediaFolderDrag = mediaFolderDrag;
window.mediaDetailsClose = { emptyClick, escapeKey };
window.checkMediaDirect = checkMediaDirect;

probeOnLoad();

/**
 * On a page marked data-serial-islands the Livewire islands are render targets only: the server decides which
 * of them an action re-renders. Livewire would otherwise mark every action started inside an island, render only
 * that island and send it in parallel with the page's other requests, each answering with the whole state; a
 * tile click during an upload could then undo the upload's state (or the other way round). Without the mark all
 * requests of the page stay in one queue.
 */
document.addEventListener('livewire:init', () => {
    window.Livewire.interceptAction(({ action }) => {
        if (action.metadata?.island && action.component?.el?.hasAttribute('data-serial-islands')) {
            const { island, ...metadata } = action.metadata;
            action.metadata = metadata;
        }
    });
});

document.addEventListener('alpine:init', () => {
    window.Alpine.data('mediaCropper', mediaCropper);
    window.Alpine.data('mediaFolderTiles', mediaFolderTiles);
    window.Alpine.data('mediaMarquee', mediaMarquee);
    window.Alpine.data('mediaUploader', mediaUploader);
});
