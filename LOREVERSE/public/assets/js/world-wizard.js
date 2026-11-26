const wizard = document.querySelector('[data-world-wizard]');
if (wizard) {
    const initial = JSON.parse(wizard.querySelector('[data-world-initial]').textContent);
    const panels = Array.from(wizard.querySelectorAll('[data-world-step]'));
    const labels = Array.from(wizard.querySelectorAll('[data-step-label]'));
    const seedControls = Array.from(wizard.querySelectorAll('[data-seed]'));
    const draftControls = Array.from(wizard.querySelectorAll('[data-draft]'));
    const editors = Array.from(wizard.querySelectorAll('[data-world-list]'));
    const locks = Array.from(wizard.querySelectorAll('[data-lock]'));
    const regenerate = Array.from(wizard.querySelectorAll('[data-regenerate]'));
    const back = wizard.querySelector('[data-prev-step]');
    const next = wizard.querySelector('[data-next-step]');
    const cancel = wizard.querySelector('[data-cancel-ai]');
    const cover = wizard.querySelector('[data-world-cover]');
    const preview = wizard.querySelector('[data-cover-preview]');
    const savedLink = wizard.querySelector('[data-saved-link]');
    const warningList = wizard.querySelector('[data-draft-warnings]');
    let step = 0;
    let dirty = false;
    let draftStarted = false;
    let pending = false;
    let controller = null;
    let previewUrl = null;
    let id = initial?.id ?? null;
    let version = initial?.updated_at ?? null;

    function editorState(editor) {
        const count = editor.querySelector('[data-entries]').children.length;
        editor.querySelector('[data-empty-entry]').hidden = count > 0;
        editor.querySelector('[data-add-entry]').disabled = pending || count >= 10;
    }

    function appendEntry(editor, value = '') {
        const entry = editor.querySelector('template').content.firstElementChild.cloneNode(true);
        const name = entry.querySelector('[data-entry-name]');
        const text = entry.querySelector('[data-entry-text]');
        if (name) {
            name.value = value.name ?? '';
            text.value = value.description ?? '';
        } else {
            text.value = value;
        }
        entry.querySelector('[data-remove-entry]').addEventListener('click', () => {
            entry.remove();
            dirty = true;
            editorState(editor);
            editor.querySelector('[data-add-entry]').focus();
        });
        editor.querySelector('[data-entries]').append(entry);
        editorState(editor);
        return name ?? text;
    }

    function readEntries(editor) {
        return Array.from(editor.querySelector('[data-entries]').children).flatMap((entry) => {
            const title = entry.querySelector('[data-entry-name]');
            const text = entry.querySelector('[data-entry-text]').value.trim();
            if (title) {
                const name = title.value.trim();
                return name || text ? [{name, description: text}] : [];
            }
            return text ? [text] : [];
        });
    }

    function writeEntries(editor, entries) {
        editor.querySelector('[data-entries]').replaceChildren();
        for (const entry of entries ?? []) appendEntry(editor, entry);
        editorState(editor);
    }

    function seed() {
        const values = Object.fromEntries(seedControls.map((input) => [input.dataset.seed, input.value.trim()]));
        values.genre = wizard.querySelector('[name="genre"]:checked')?.value ?? '';
        values.keywords = readEntries(editors.find((editor) => editor.dataset.worldList === 'keywords'));
        return values;
    }

    function draft() {
        const values = {...seed()};
        for (const control of draftControls) values[control.dataset.draft] = control.value.trim();
        for (const editor of editors) values[editor.dataset.worldList] = readEntries(editor);
        return values;
    }

    function applyField(field, value) {
        const control = draftControls.find((input) => input.dataset.draft === field);
        if (control) control.value = value ?? '';
        const editor = editors.find((item) => item.dataset.worldList === field && field !== 'keywords');
        if (editor) writeEntries(editor, value);
    }

    function applyDraft(data) {
        for (const control of draftControls) applyField(control.dataset.draft, data[control.dataset.draft]);
        for (const editor of editors) applyField(editor.dataset.worldList, data[editor.dataset.worldList]);
        draftStarted = true;
    }

    function lockState() {
        const seeded = seed();
        const protectedFields = ['name', 'era', 'mood'].filter((field) => seeded[field]);
        for (const lock of locks) lock.disabled = pending || protectedFields.includes(lock.dataset.lock);
        for (const button of regenerate) {
            button.disabled = pending || protectedFields.includes(button.dataset.regenerate)
                || locks.some((lock) => lock.checked && lock.dataset.lock === button.dataset.regenerate);
        }
    }

    function showStep(index, focus = true) {
        step = index;
        panels.forEach((panel, position) => { panel.hidden = position !== step; });
        labels.forEach((label, position) => {
            if (position === step) label.setAttribute('aria-current', 'step');
            else label.removeAttribute('aria-current');
        });
        back.hidden = step === 0;
        next.hidden = step >= 3;
        lockState();
        if (focus) panels[step].querySelector('h2').focus();
    }

    function setBusy(state, ai = false) {
        pending = state;
        wizard.setAttribute('aria-busy', String(state));
        for (const control of wizard.querySelectorAll('input, textarea, button')) control.disabled = state;
        cancel.hidden = !ai;
        cancel.disabled = !ai;
        for (const editor of editors) editorState(editor);
        lockState();
    }

    function validateGenre() {
        const values = seed();
        if (!values.genre) {
            LoreverseWorld.notice(wizard, '장르를 선택해 주세요.', true);
            return false;
        }
        if (values.genre === 'custom' && !values.custom_genre) {
            LoreverseWorld.notice(wizard, '직접 입력할 장르의 이름을 적어 주세요.', true);
            return false;
        }
        return true;
    }

    function requestId() {
        if (typeof crypto.randomUUID === 'function') return crypto.randomUUID();
        const bytes = crypto.getRandomValues(new Uint8Array(16));
        bytes[6] = (bytes[6] & 0x0f) | 0x40;
        bytes[8] = (bytes[8] & 0x3f) | 0x80;
        const hex = Array.from(bytes, (byte) => byte.toString(16).padStart(2, '0')).join('');
        return [hex.slice(0, 8), hex.slice(8, 12), hex.slice(12, 16), hex.slice(16, 20), hex.slice(20)].join('-');
    }

    async function generate(target = null) {
        if (pending || !validateGenre()) return;
        const payload = {
            ...seed(), request_id: requestId(), target_field: target,
            locked_fields: locks.filter((lock) => lock.checked).map((lock) => lock.dataset.lock),
            current_data: draftStarted ? draft() : null,
        };
        controller = new AbortController();
        setBusy(true, true);
        LoreverseWorld.notice(wizard, 'Gemini가 초안을 만들고 있어요.');
        try {
            const result = await LoreverseWorld.post(wizard, 'worlds/generate', payload, controller.signal);
            if (target) applyField(target, result.world[target]);
            else applyDraft(result.world);
            const items = result.warnings.map((warning) => {
                const item = document.createElement('li');
                item.textContent = warning;
                return item;
            });
            warningList.replaceChildren(...items);
            warningList.hidden = items.length === 0;
            dirty = true;
            if (!target) showStep(4);
            LoreverseWorld.notice(wizard, '초안을 받았어요. 내용을 검토해 주세요.');
        } catch (error) {
            LoreverseWorld.notice(wizard, controller.signal.aborted
                ? '응답 대기를 취소했어요. 서버 요청은 잠시 처리 중일 수 있어요.'
                : error.message, true);
        } finally {
            controller = null;
            setBusy(false);
        }
    }

    async function save() {
        if (pending || !validateGenre()) return;
        const settings = draft();
        if (!settings.name) {
            LoreverseWorld.notice(wizard, '저장할 세계관 이름을 입력해 주세요.', true);
            wizard.querySelector('[data-draft="name"]').focus();
            return;
        }
        const values = id === null ? settings : {...settings, id, expected_updated_at: version};
        const file = cover.files[0];
        setBusy(true);
        LoreverseWorld.notice(wizard, '세계관을 저장하는 중이에요.');
        try {
            const result = await LoreverseWorld.post(wizard, 'worlds/save', values);
            id = result.world.id;
            version = result.world.updated_at;
            dirty = false;
            if (file) {
                try {
                    await LoreverseWorld.upload(wizard, id, file);
                } catch (error) {
                    savedLink.href = LoreverseWorld.href(wizard, id);
                    savedLink.hidden = false;
                    LoreverseWorld.notice(wizard, '세계관을 저장했지만 커버를 올리지 못했어요. ' + error.message
                        + ' 파일을 바꾸고 저장을 다시 누르면 재시도할 수 있어요.', true);
                    return;
                }
            }
            location.assign(LoreverseWorld.href(wizard, id));
        } catch (error) {
            LoreverseWorld.notice(wizard, error.message, true);
        } finally {
            setBusy(false);
        }
    }

    function showCustomGenre() {
        wizard.querySelector('[data-custom-genre]').hidden = seed().genre !== 'custom';
    }

    const genreIdea = wizard.querySelector('[data-genre-idea]');
    if (genreIdea) {
        const suggestion = wizard.querySelector('[data-genre-suggestion]');
        const suggestionLabel = wizard.querySelector('[data-genre-suggestion-label]');
        const acceptSuggestion = wizard.querySelector('[data-use-genre-suggestion]');
        let suggestedRadio = null;

        genreIdea.addEventListener('input', () => {
            suggestedRadio = null;
            suggestion.hidden = true;
            LoreverseWorld.notice(wizard, '');
        });
        wizard.querySelector('[data-recommend-genre]').addEventListener('click', async () => {
            if (pending) return;
            const idea = genreIdea.value.trim();
            if (!idea) {
                LoreverseWorld.notice(wizard, '세계관 아이디어를 입력해 주세요.');
                genreIdea.focus();
                return;
            }
            suggestedRadio = null;
            suggestion.hidden = true;
            controller = new AbortController();
            setBusy(true, true);
            LoreverseWorld.notice(wizard, 'Gemini가 어울리는 장르를 찾고 있어요.');
            try {
                const result = await LoreverseWorld.post(wizard, 'worlds/recommend-genre',
                    {idea, request_id: requestId()}, controller.signal);
                suggestedRadio = [...wizard.querySelectorAll('[name="genre"]')].find((input) => input.value === result.genre);
                if (!suggestedRadio) throw new Error('추천 장르를 확인할 수 없어요.');
                suggestionLabel.textContent = result.label;
                suggestion.hidden = false;
                LoreverseWorld.notice(wizard, '');
            } catch (error) {
                LoreverseWorld.notice(wizard, controller.signal.aborted
                    ? '추천 응답 대기를 취소했어요.'
                    : error.message, true);
            } finally {
                controller = null;
                setBusy(false);
            }
            if (suggestedRadio) acceptSuggestion.focus();
        });
        acceptSuggestion.addEventListener('click', () => {
            if (!suggestedRadio || pending) return;
            const features = seedControls.find((control) => control.dataset.seed === 'features');
            if (!features.value.trim()) features.value = genreIdea.value.trim();
            suggestedRadio.checked = true;
            suggestedRadio.dispatchEvent(new Event('input', {bubbles: true}));
            suggestion.hidden = true;
            LoreverseWorld.notice(wizard, '추천 장르를 선택했어요.');
            suggestedRadio.focus();
        });
    }

    for (const editor of editors) {
        editor.querySelector('[data-add-entry]').addEventListener('click', () => {
            dirty = true;
            appendEntry(editor).focus();
        });
    }
    wizard.addEventListener('submit', (event) => event.preventDefault());
    wizard.addEventListener('input', (event) => {
        dirty = true;
        const field = event.target.dataset.seed;
        if (draftStarted && ['name', 'era', 'mood'].includes(field)) applyField(field, event.target.value);
        showCustomGenre();
        lockState();
    });
    back.addEventListener('click', () => {
        LoreverseWorld.notice(wizard, '');
        showStep(step - 1);
    });
    next.addEventListener('click', () => {
        if (step === 0 && !validateGenre()) return;
        LoreverseWorld.notice(wizard, '');
        showStep(step + 1);
    });
    wizard.querySelector('[data-start-manual]').addEventListener('click', () => {
        if (!draftStarted) applyDraft(seed());
        LoreverseWorld.notice(wizard, '');
        showStep(4);
    });
    wizard.querySelector('[data-generate-world]').addEventListener('click', () => generate());
    wizard.querySelector('[data-regenerate-all]').addEventListener('click', () => generate());
    for (const button of regenerate) button.addEventListener('click', () => generate(button.dataset.regenerate));
    cancel.addEventListener('click', () => controller?.abort());
    wizard.querySelector('[data-save-world]').addEventListener('click', save);
    cover.addEventListener('change', () => {
        if (previewUrl) URL.revokeObjectURL(previewUrl);
        const file = cover.files[0];
        previewUrl = file ? URL.createObjectURL(file) : null;
        preview.hidden = !previewUrl;
        if (previewUrl) preview.src = previewUrl;
        else preview.removeAttribute('src');
    });
    addEventListener('beforeunload', (event) => {
        if (dirty) {
            event.preventDefault();
            event.returnValue = '';
        }
    });

    if (initial) {
        for (const field of seedControls) field.value = initial[field.dataset.seed] ?? '';
        writeEntries(editors.find((editor) => editor.dataset.worldList === 'keywords'), initial.keywords);
        applyDraft(initial);
    }
    showCustomGenre();
    showStep(initial ? 4 : 0, false);
}
