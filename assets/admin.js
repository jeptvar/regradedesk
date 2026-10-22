(function ($) {
    'use strict';
    const field = document.getElementById('ss-photos');
    if (!field) return;
    const preview = document.getElementById('ss-photo-preview');
    const status = document.getElementById('ss-photo-status');
    const approval = document.querySelector('[name="photos_confirmed"]');
    const create = document.getElementById('ss-create-draft');
    const markDirty = function () {
        if (create && !field.closest('fieldset').disabled) {
            create.disabled = true;
            document.getElementById('ss-unsaved').textContent = secondShelf.unsaved;
        }
    };
    field.closest('form').addEventListener('input', markDirty);
    field.closest('form').addEventListener('change', markDirty);
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
