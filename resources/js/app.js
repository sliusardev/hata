import copyText from './copy-text.js';
import mediaCropper from './media-cropper.js';
import mediaDragStart from './media-drag.js';
import mediaMarquee from './media-marquee.js';
import mediaUploader from './media-uploader.js';

window.copyText = copyText;
window.mediaDragStart = mediaDragStart;

document.addEventListener('alpine:init', () => {
    window.Alpine.data('mediaCropper', mediaCropper);
    window.Alpine.data('mediaMarquee', mediaMarquee);
    window.Alpine.data('mediaUploader', mediaUploader);
});
