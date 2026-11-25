const previewButton = document.querySelector('[data-preview-open]');
const previewDialog = document.querySelector('#preview-dialog');

previewButton?.addEventListener('click', () => previewDialog?.showModal());
