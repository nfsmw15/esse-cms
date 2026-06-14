(function () {
    'use strict';

    window.EsseMediaButton = function (context) {
        const ui = $.summernote.ui;
        const button = ui.button({
            contents: '<i class="bi bi-images"></i>',
            tooltip: 'Aus Mediathek einfügen',
            click: function () {
                if (!window.EsseMedia) return;
                window.EsseMedia.open(function (file) {
                    context.invoke('editor.insertImage', file.url, function ($image) {
                        if (file.alt) $image.attr('alt', file.alt);
                    });
                }, { type: 'image', warnPrivate: true });
            },
        });
        return button.render();
    };
})();
