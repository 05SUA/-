const createMenus = Array.from(document.querySelectorAll('[data-create-menu]'));

document.addEventListener('keydown', (event) => {
    if (event.key !== 'Escape') return;
    const menu = createMenus.find((item) => item.open && item.contains(document.activeElement));
    if (menu) {
        menu.open = false;
        menu.querySelector('summary').focus();
    }
});

document.addEventListener('click', (event) => {
    if (!(event.target instanceof Element)) return;
    for (const menu of createMenus) {
        if (menu.open && (!menu.contains(event.target) || event.target.closest('a'))) {
            menu.open = false;
        }
    }
});

if (typeof HTMLDialogElement !== 'undefined' && 'showModal' in HTMLDialogElement.prototype) {
    const openers = Array.from(document.querySelectorAll('[data-open-dialog]'));
    const breakpoints = {
        sidebar: '(max-width: 1023px)',
        detail: '(max-width: 767px)',
    };

    for (const dialog of document.querySelectorAll('dialog[data-drawer]')) {
        const controls = openers.filter((button) => button.dataset.openDialog === dialog.id);
        const media = matchMedia(breakpoints[dialog.dataset.drawer] ?? 'all');

        for (const button of controls) {
            button.addEventListener('click', () => {
                if (!media.matches || dialog.open) return;
                dialog.showModal();
                button.setAttribute('aria-expanded', 'true');
            });
        }

        dialog.addEventListener('close', () => {
            for (const button of controls) button.setAttribute('aria-expanded', 'false');
        });
        dialog.addEventListener('click', (event) => {
            if (!(event.target instanceof Element)) return;
            if (event.target.closest('a')) {
                dialog.close();
            } else if (event.target === dialog) {
                const bounds = dialog.getBoundingClientRect();
                if (event.clientX < bounds.left || event.clientX > bounds.right) dialog.close();
            }
        });
        media.addEventListener('change', () => {
            if (!media.matches && dialog.open) dialog.close();
        });
    }

    document.documentElement.dataset.dialogsReady = '';
}
