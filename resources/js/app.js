import copyText from './copy-text.js';
import entryForm from './entry-form.js';
import mediaCropper from './media-cropper.js';
import { emptyClick, escapeKey } from './media-details-close.js';
import { checkMediaDirect, probeOnLoad } from './media-direct-probe.js';
import mediaDragStart from './media-drag.js';
import * as mediaFolderDrag from './media-folder-drag.js';
import { mediaFolderTiles } from './media-folder-drag.js';
import mediaMarquee from './media-marquee.js';
import mediaUploader from './media-uploader.js';
import richText from './rich-text-field.js';

window.copyText = copyText;
window.mediaDragStart = mediaDragStart;
window.mediaFolderDrag = mediaFolderDrag;
window.mediaDetailsClose = { emptyClick, escapeKey };
window.checkMediaDirect = checkMediaDirect;

probeOnLoad();

document.addEventListener('alpine:init', () => {
    window.Alpine.data('entryForm', entryForm);
    window.Alpine.data('mediaCropper', mediaCropper);
    window.Alpine.data('mediaFolderTiles', mediaFolderTiles);
    window.Alpine.data('mediaMarquee', mediaMarquee);
    window.Alpine.data('mediaUploader', mediaUploader);
    window.Alpine.data('richText', richText);
});
