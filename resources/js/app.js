import mediaUploader from './media-uploader.js';

document.addEventListener('alpine:init', () => {
    window.Alpine.data('mediaUploader', mediaUploader);
});
