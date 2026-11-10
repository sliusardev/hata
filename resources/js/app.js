import mediaDragStart from './media-drag.js';
import mediaUploader from './media-uploader.js';

window.mediaDragStart = mediaDragStart;

document.addEventListener('alpine:init', () => {
    window.Alpine.data('mediaUploader', mediaUploader);
});
