(function ($) {
    'use strict';
    const field = document.getElementById('ss-photos');
    if (!field) return;
    const preview = document.getElementById('ss-photo-preview');
    const status = document.getElementById('ss-photo-status');
    const approval = document.querySelector('[name="photos_confirmed"]');
    const create = document.getElementById('ss-create-draft');
    const inspectionForm = field.form;
    const snapshot = function () { return JSON.stringify(Array.from(new FormData(inspectionForm).entries())); };
    const saved = snapshot();
    const warnBeforeLeaving = function (event) {
        event.preventDefault();
        event.returnValue = '';
    };
    const markDirty = function () {
        const dirty = !field.closest('fieldset').disabled && snapshot() !== saved;
        window.removeEventListener('beforeunload', warnBeforeLeaving);
        if (dirty) window.addEventListener('beforeunload', warnBeforeLeaving);
        if (create) {
            create.disabled = dirty;
            document.getElementById('ss-unsaved').textContent = dirty ? secondShelf.unsaved : '';
        }
    };
    // The decision control lives in the sidebar and belongs to the form via its form attribute.
    const onEdit = function (event) {
        if (event.target.form === inspectionForm) markDirty();
    };
    document.addEventListener('input', onEdit);
    document.addEventListener('change', onEdit);
    inspectionForm.addEventListener('submit', function () {
        window.removeEventListener('beforeunload', warnBeforeLeaving);
    });
    // Re-arm the warning when Back restores a form after a rejected submission.
    window.addEventListener('pageshow', markDirty);
    let frame;
    $('#ss-select-photos').on('click', function () {
        if (!frame) {
            frame = wp.media({ title: secondShelf.title, button: { text: secondShelf.button }, library: { type: 'image' }, multiple: 'add' });
            frame.on('open', function () {
                const selection = frame.state().get('selection');
                selection.reset();
                field.value.split(',').filter(Boolean).forEach(function (id) {
                    const attachment = wp.media.attachment(Number(id));
                    attachment.fetch();
                    selection.add(attachment);
                });
            });
            frame.on('select', function () {
                const items = frame.state().get('selection').toJSON();
                field.value = items.map(item => item.id).join(',');
                preview.replaceChildren();
                items.forEach(function (item) {
                    const img = document.createElement('img');
                    img.src = item.sizes?.thumbnail?.url || item.url;
                    img.alt = item.alt || '';
                    preview.appendChild(img);
                });
                approval.checked = false;
                markDirty();
                status.textContent = items.length + ' ' + secondShelf.selected;
            });
        }
        frame.open();
    });
    $('#ss-clear-photos').on('click', function () {
        field.value = '';
        preview.replaceChildren();
        approval.checked = false;
        markDirty();
        status.textContent = '0 ' + secondShelf.selected;
    });
})(jQuery);
