const chat = document.querySelector('[data-chat]');
const chatProfile = chat?.querySelector('[data-chat-profile]');
if (chatProfile && matchMedia('(max-width: 820px)').matches) chatProfile.open = false;

const composer = chat?.querySelector('[data-chat-send]');
if (composer && typeof crypto.randomUUID === 'function') {
    const message = composer.elements.message;
    const request = composer.elements.request_id;
    const button = composer.querySelector('button[type="submit"]');
    const status = composer.querySelector('[data-chat-status]');
    let attemptedMessage = null;

    const report = (text, failed = false) => {
        status.textContent = text;
        status.hidden = !text;
        status.classList.toggle('state--error', failed);
        status.classList.toggle('state--loading', !failed && text !== '');
    };

    composer.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (button.disabled) return;
        const text = message.value.trim();
        if (!text) {
            report('보낼 메시지를 입력해 주세요.', true);
            message.focus();
            return;
        }
        if (attemptedMessage !== null && attemptedMessage !== text) request.value = crypto.randomUUID();
        attemptedMessage = text;
        const body = Object.fromEntries(new FormData(composer));
        button.disabled = true;
        message.readOnly = true;
        report('캐릭터의 응답을 기다리는 중이에요.');
        try {
            const response = await fetch(composer.dataset.endpoint, {
                method: 'POST', credentials: 'same-origin',
                headers: {'Accept': 'application/json', 'Content-Type': 'application/json'},
                body: JSON.stringify(body),
            });
            const result = await response.json();
            if (!response.ok || result.ok !== true) {
                const error = result.error?.code;
                if (error !== 'AI_IN_PROGRESS') {
                    request.value = crypto.randomUUID();
                    attemptedMessage = null;
                }
                report(result.error?.message ?? '응답을 확인하지 못했어요. 다시 시도해 주세요.', true);
                return;
            }
            history.replaceState(null, '', composer.getAttribute('action') + '#chat-thread-end');
            location.reload();
        } catch {
            report('응답을 확인할 수 없어요. 같은 메시지를 보내면 저장 여부를 다시 확인해요.', true);
        } finally {
            button.disabled = false;
            message.readOnly = false;
        }
    });
}
