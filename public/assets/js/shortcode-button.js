(function () {
    'use strict';

    window.EsseShortcodeButton = function (context) {
        const ui = $.summernote.ui;
        const button = ui.button({
            contents: '<i class="bi bi-puzzle"></i>',
            tooltip: 'Widget einfügen',
            click: function () {
                if (!window.EsseShortcode) return;
                window.EsseShortcode.open(function (code) {
                    context.invoke('editor.insertText', code);
                });
            },
        });
        return button.render();
    };
})();
