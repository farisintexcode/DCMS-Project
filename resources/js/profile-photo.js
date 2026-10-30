window.nexoraPhotoEditor = wire => ({
    preview: null,
    zoom: wire.entangle('photoZoom'),
    positionX: wire.entangle('photoX'),
    positionY: wire.entangle('photoY'),
    width: 0,
    height: 0,
    imageLoaded: false,
    uploading: false,
    localError: '',
    get previewStyle() {
        if (!this.width || !this.height) return '';
        const side = Math.max(1, Math.floor(Math.min(this.width, this.height) / this.zoom));
        const left = Math.round((this.width - side) * this.positionX / 100);
        const top = Math.round((this.height - side) * this.positionY / 100);
        return `width:${this.width / side * 100}%;height:${this.height / side * 100}%;left:${-left / side * 100}%;top:${-top / side * 100}%;`;
    },
    selectPhoto(event) {
        const file = event.target.files?.[0];
        if (!file) return;
        this.localError = '';
        if (!['image/jpeg', 'image/png', 'image/webp'].includes(file.type) || file.size > 5 * 1024 * 1024) {
            this.localError = 'Choose a JPG, PNG or WebP image up to 5 MB.';
            event.target.value = '';
            return;
        }
        this.releasePreview();
        this.zoom = 1;
        this.positionX = 50;
        this.positionY = 50;
        this.preview = URL.createObjectURL(file);
        this.$refs.photoDialog.showModal();
        this.uploading = true;
        wire.upload('photo', file, () => this.uploading = false, () => {
            this.uploading = false;
            this.localError = 'Your photo could not be uploaded. Please try again.';
        }, () => {}, () => this.uploading = false);
    },
    imageReady(event) {
        this.width = event.target.naturalWidth;
        this.height = event.target.naturalHeight;
        if (this.width > 4096 || this.height > 4096) {
            this.localError = 'Resize your image to at most 4096 × 4096 pixels.';
            this.imageLoaded = false;
            return;
        }
        this.imageLoaded = true;
    },
    imageFailed() {
        if (this.preview) {
            this.localError = 'This image could not be opened. Choose another photo.';
            this.imageLoaded = false;
        }
    },
    cancelPhoto() {
        wire.cancelUpload('photo');
        this.uploading = false;
        wire.cancelPhoto();
        this.$refs.photoDialog.close();
        this.$refs.photoPicker.value = '';
        this.releasePreview();
        this.localError = '';
    },
    restoreFocus() { this.$refs.editPhoto?.focus(); },
    releasePreview() {
        if (this.preview) URL.revokeObjectURL(this.preview);
        this.preview = null;
        this.width = this.height = 0;
        this.imageLoaded = false;
    },
    destroy() { this.releasePreview(); },
});
