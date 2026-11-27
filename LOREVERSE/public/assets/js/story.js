(() => {
    const root = document.querySelector('[data-story-studio]');
    if (!root) return;

    const message = root.querySelector('[data-story-message]');
    let busy = false;

    function notice(text, error = false) {
        message.textContent = text;
        message.hidden = text === '';
        message.classList.toggle('state--loading', !error);
        message.classList.toggle('state--error', error);
        if (error) message.focus();
    }

    function requestId() {
        if (typeof crypto.randomUUID === 'function') return crypto.randomUUID();
        const bytes = crypto.getRandomValues(new Uint8Array(16));
        bytes[6] = (bytes[6] & 0x0f) | 0x40;
        bytes[8] = (bytes[8] & 0x3f) | 0x80;
        const hex = Array.from(bytes, (byte) => byte.toString(16).padStart(2, '0')).join('');
        return [hex.slice(0, 8), hex.slice(8, 12), hex.slice(12, 16), hex.slice(16, 20), hex.slice(20)].join('-');
    }

    function requestBody(form, submitter) {
        const fields = new FormData(form);
        const storyId = Number(root.dataset.storyId);
        switch (form.dataset.storyForm) {
            case 'create': {
                const cast = fields.getAll('character_ids[]').map(Number);
                if (cast.length < 1 || cast.length > 5) {
                    throw new Error('등장인물을 한 명 이상, 최대 다섯 명까지 선택해 주세요.');
                }
                fields.delete('character_ids[]');
                fields.delete('_csrf');
                fields.delete('request_id');
                return {...Object.fromEntries(fields), world_id: Number(root.dataset.worldId), character_ids: cast};
            }
            case 'advance': {
                const action = form.dataset.actionType;
                const values = {
                    ...Object.fromEntries(fields), story_id: storyId, expected_turn: Number(root.dataset.latestTurn),
                    action_type: action, request_id: requestId(),
                };
                if (action === 'choice') values.choice_index = Number(submitter?.value ?? '0');
                return values;
            }
            case 'apply-canon': {
                const indices = fields.getAll('selected_relationship_indices[]').map(Number);
                const includeEvent = fields.has('include_event');
                if (indices.length === 0 && !includeEvent) {
                    throw new Error('공식 반영할 관계 또는 사건을 선택해 주세요.');
                }
                return {
                    story_id: storyId, turn_id: Number(fields.get('turn_id')),
                    selected_relationship_indices: indices, include_event: includeEvent,
                };
            }
            case 'complete':
                return {id: storyId, status: fields.get('status')};
            case 'delete':
                return {id: storyId, confirmed: fields.has('confirmed')};
            default:
                throw new Error('스토리 요청 내용을 확인해 주세요.');
        }
    }

    function refreshAt(anchor) {
        if (anchor) history.replaceState(null, '', '#' + anchor);
        window.location.reload();
    }

    const heading = document.getElementById(window.location.hash.slice(1));
    if (heading && root.contains(heading)) {
        const historyPanel = heading.closest('details');
        if (historyPanel) historyPanel.open = true;
        heading.setAttribute('tabindex', '-1');
        heading.focus();
    }

    root.addEventListener('submit', async (event) => {
        const form = event.target;
        if (!form.matches('[data-story-form]')) return;
        if (!busy && form.dataset.storyForm === 'create' && event.submitter?.value === 'draft_setup') return;
        event.preventDefault();
        if (busy) return;

        const route = form.dataset.storyForm;
        const usesAi = route === 'advance' && form.dataset.actionType !== 'manual';
        let values;
        try {
            values = requestBody(form, event.submitter);
        } catch (error) {
            notice(error.message, true);
            return;
        }

        busy = true;
        form.setAttribute('aria-busy', 'true');
        const buttons = root.querySelectorAll('[data-story-form] button[type="submit"]:not(:disabled)');
        buttons.forEach((button) => { button.disabled = true; });
        notice(usesAi ? 'Gemini가 장면을 만들고 있어요.' : '변경 내용을 저장하고 있어요.');
        let succeeded = false;
        try {
            const result = await LoreverseWorld.post(root, 'stories/' + route, values);
            succeeded = true;
            if (route === 'create') {
                const url = new URL(root.dataset.storyUrl, window.location.href);
                url.searchParams.set('id', String(result.story.id));
                url.searchParams.delete('world_id');
                window.location.assign(url);
            } else if (route === 'delete') {
                window.location.assign(root.dataset.listUrl);
            } else if (route === 'advance') {
                refreshAt('story-latest');
            } else {
                refreshAt(route === 'apply-canon' ? 'story-turn-' + values.turn_id : null);
            }
        } catch (error) {
            succeeded = false;
            notice(error.message + (usesAi ? ' 장면을 직접 작성해 이어갈 수 있어요.' : ''), true);
        } finally {
            if (!succeeded) {
                busy = false;
                form.removeAttribute('aria-busy');
                buttons.forEach((button) => { button.disabled = false; });
            }
        }
    });
})();
