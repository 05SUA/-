for (const form of document.querySelectorAll('[data-auth-form]')) {
    const submit = form.querySelector('[type="submit"]');
    const message = form.querySelector('[data-auth-message]');
    const fields = Array.from(form.querySelectorAll('[data-auth-field]'));

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (submit.disabled) return;
        submit.disabled = true;
        form.setAttribute('aria-busy', 'true');
        message.hidden = true;
        for (const field of fields) {
            const input = field.querySelector('input');
            const error = field.querySelector('.field-error');
            error.hidden = true;
            error.textContent = '';
            input.removeAttribute('aria-invalid');
            const help = field.querySelector('.field-help');
            if (help) input.setAttribute('aria-describedby', help.id);
            else input.removeAttribute('aria-describedby');
        }

        try {
            const response = await fetch(form.dataset.authEndpoint, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {'Content-Type': 'application/json', 'Accept': 'application/json'},
                body: JSON.stringify(Object.fromEntries(new FormData(form))),
            });
            const result = await response.json();
            if (response.ok && result.ok) {
                location.assign(result.data.redirect);
                return;
            }
            message.textContent = result.error.message;
            message.hidden = false;
            let first = null;
            for (const field of fields) {
                const errorText = result.error.fields?.[field.dataset.authField];
                if (!errorText) continue;
                const input = field.querySelector('input');
                const error = field.querySelector('.field-error');
                error.textContent = errorText;
                error.hidden = false;
                input.setAttribute('aria-invalid', 'true');
                const descriptions = [input.getAttribute('aria-describedby'), error.id].filter(Boolean);
                input.setAttribute('aria-describedby', descriptions.join(' '));
                first ??= input;
            }
            (first ?? message).focus();
        } catch {
            message.textContent = '서버와 연결할 수 없어요. 잠시 후 다시 시도해 주세요.';
            message.hidden = false;
            message.focus();
        } finally {
            form.removeAttribute('aria-busy');
            submit.disabled = false;
        }
    });
}
