(() => {
    const chat = document.querySelector('[data-chat]');
    if (!chat) return;

    if (matchMedia('(max-width: 820px)').matches) {
        chat.querySelectorAll('[data-chat-profile], [data-chat-sessions]').forEach((panel) => {
            panel.open = false;
        });
    }

    const composer = chat.querySelector('[data-chat-send]');
    const transcript = chat.querySelector('[data-chat-transcript]');
    const messages = chat.querySelector('[data-chat-messages]');
    const template = chat.querySelector('[data-chat-message-template]');
    if (!composer || !transcript || !messages || !template || typeof globalThis.crypto?.randomUUID !== 'function') return;

    const message = composer.elements.message;
    const request = composer.elements.request_id;
    const cursor = composer.elements.expected_last_id;
    const button = composer.querySelector('button[type="submit"]');
    const status = composer.querySelector('[data-chat-status]');
    const reopen = composer.querySelector('[data-chat-reload]');
    const empty = chat.querySelector('[data-chat-empty]');
    const character = chat.dataset.characterName || '캐릭터';
    let attemptedMessage = null;
    let historyChanged = false;

    const atBottom = () => transcript.scrollHeight - transcript.scrollTop - transcript.clientHeight < 64;
    const scrollToBottom = () => { transcript.scrollTop = transcript.scrollHeight; };
    const report = (text, failed = false) => {
        status.textContent = text;
        status.hidden = !text;
        status.classList.toggle('state--error', failed);
        status.classList.toggle('state--loading', !failed && text !== '');
    };

    const makeRow = (row) => {
        const item = template.content.firstElementChild.cloneNode(true);
        item.classList.add('chat-message--' + row.role);
        if (row.id) item.dataset.messageId = String(row.id);

        item.querySelector('[data-chat-author]').textContent = row.role === 'assistant' ? character : '나';
        const content = item.querySelector('[data-chat-content]');
        content.textContent = row.content;
        content.hidden = row.content === '';
        const action = item.querySelector('[data-chat-action]');
        action.textContent = row.action;
        action.hidden = row.action === '';
        return item;
    };

    const isMessage = (row) => row && Number.isSafeInteger(row.id) && row.id > 0
        && typeof row.content === 'string' && typeof row.action === 'string';

    function appendSavedPair(data) {
        const pair = data?.messages;
        if (!Array.isArray(pair) || pair.length !== 2 || !pair.every(isMessage)
            || pair[0].role !== 'user' || pair[1].role !== 'assistant'
            || pair[0].id >= pair[1].id || data.latest_message_id !== pair[1].id) {
            throw new Error('Unexpected chat response');
        }

        const newestDisplayed = Number(cursor.value);
        const existing = new Set(Array.from(messages.querySelectorAll('[data-message-id]'), (item) => Number(item.dataset.messageId)));
        const rows = document.createDocumentFragment();
        for (const row of pair) {
            if (row.id > newestDisplayed && !existing.has(row.id)) rows.append(makeRow(row));
        }
        messages.append(rows);
        cursor.value = String(Math.max(newestDisplayed, data.latest_message_id));
    }

    function freshRequest() {
        request.value = crypto.randomUUID();
        attemptedMessage = null;
    }

    scrollToBottom();

    message.addEventListener('keydown', (event) => {
        if (event.key !== 'Enter' || event.shiftKey || event.ctrlKey || event.metaKey || event.altKey
            || event.isComposing || event.keyCode === 229 || !matchMedia('(pointer: fine)').matches) return;
        event.preventDefault();
        if (!button.disabled) composer.requestSubmit(button);
    });

    composer.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (button.disabled || historyChanged) return;
        const text = message.value.trim();
        if (!text) {
            report('보낼 메시지를 입력해 주세요.', true);
            message.focus();
            return;
        }
        if (attemptedMessage !== null && attemptedMessage !== text) freshRequest();
        attemptedMessage = text;
        const body = {...Object.fromEntries(new FormData(composer)), message: text};
        const pending = makeRow({role: 'user', content: text, action: ''});
        pending.classList.add('chat-message--pending');
        pending.setAttribute('aria-hidden', 'true');
        messages.append(pending);
        if (empty) empty.hidden = true;
        button.disabled = true;
        message.readOnly = true;
        report(character + '의 답장을 기다리는 중이에요.');
        scrollToBottom();

        try {
            const response = await fetch(composer.dataset.endpoint, {
                method: 'POST', credentials: 'same-origin',
                headers: {'Accept': 'application/json', 'Content-Type': 'application/json'},
                body: JSON.stringify(body),
            });
            const result = await response.json();
            if (!response.ok || result.ok !== true) {
                const code = result.error?.code;
                if (code === 'CHAT_CHANGED') {
                    historyChanged = true;
                    if (reopen) reopen.hidden = false;
                } else if (typeof code === 'string' && (code.startsWith('GEMINI_')
                    || ['AI_DAILY_LIMIT', 'DUPLICATE_REQUEST', 'VALIDATION_ERROR'].includes(code))) {
                    freshRequest();
                }
                report(result.error?.message ?? '응답을 확인하지 못했어요. 다시 시도해 주세요.', true);
                return;
            }

            const followConversation = atBottom();
            pending.remove();
            appendSavedPair(result.data);
            freshRequest();
            message.value = '';
            report('');
            if (followConversation) scrollToBottom();
        } catch {
            report('응답을 확인할 수 없어요. 같은 메시지를 보내면 저장 여부를 다시 확인해요.', true);
        } finally {
            pending.remove();
            if (empty) empty.hidden = messages.querySelector('[data-message-id]') !== null;
            button.disabled = historyChanged;
            message.readOnly = false;
            if (!historyChanged && [message, button, document.body].includes(document.activeElement)) {
                message.focus({preventScroll: true});
            }
        }
    });
})();
