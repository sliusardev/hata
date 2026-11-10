import mediaDragStart from './media-drag.js';
import mediaMarquee from './media-marquee.js';
import mediaUploader from './media-uploader.js';

window.mediaDragStart = mediaDragStart;

document.addEventListener('alpine:init', () => {
    window.Alpine.data('mediaMarquee', mediaMarquee);
    window.Alpine.data('mediaUploader', mediaUploader);
});
