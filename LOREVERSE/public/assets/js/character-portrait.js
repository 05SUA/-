(() => {
    const portrait = document.querySelector('[data-character-portrait]');
    if (!portrait || typeof globalThis.crypto?.randomUUID !== 'function') return;

    const tools = portrait.querySelector('[data-portrait-tools]');
    const current = portrait.querySelector('[data-portrait-current]');
    const generate = portrait.querySelector('[data-portrait-generate]');
    const apply = portrait.querySelector('[data-portrait-apply]');
    const discard = portrait.querySelector('[data-portrait-discard]');
    const status = portrait.querySelector('[data-portrait-status]');
    const result = portrait.querySelector('[data-portrait-result]');
    const heading = portrait.querySelector('[data-portrait-heading]');
    const preview = portrait.querySelector('[data-portrait-preview]');
    const {id, csrf, generateUrl, uploadUrl, imageUrl, autoRequestId} = portrait.dataset;
    const extensions = new Map([['image/png', 'png'], ['image/jpeg', 'jpg'], ['image/webp', 'webp']]);
    const maxSize = 2 * 1024 * 1024;
    const unreadable = '생성된 이미지를 확인할 수 없어요. 다시 시도해 주세요.';
    let busy = false;
    let requestId = autoRequestId ?? null;
    let draft = null;

    function report(message, mode = '') {
        status.textContent = message;
        status.hidden = message === '';
        status.classList.toggle('state--loading', mode === 'loading');
        status.classList.toggle('state--error', mode === 'error');
        status.classList.toggle('state--success', mode === 'success');
    }

    function setBusy(active) {
        busy = active;
        generate.disabled = active || draft !== null;
        apply.disabled = active;
        discard.disabled = active;
    }

    function clearPreview() {
        if (draft) URL.revokeObjectURL(draft.url);
        draft = null;
        result.hidden = true;
        preview.replaceChildren();
    }

    async function post(url, options, fallback) {
        let response;
        let payload;
        try {
            response = await fetch(url, {
                ...options, method: 'POST', credentials: 'same-origin', redirect: 'error',
            });
            payload = await response.json();
        } catch {
            throw new Error(fallback);
        }
        if (!response.ok || payload?.ok !== true) {
            const error = new Error(typeof payload?.error?.message === 'string' ? payload.error.message : fallback);
            error.code = payload?.error?.code;
            throw error;
        }
        return payload.data;
    }

    async function showPreview(image) {
        const extension = extensions.get(image?.mime_type);
        if (!extension || typeof image.base64 !== 'string' || image.base64.length > Math.ceil(maxSize / 3) * 4) {
            throw new Error(unreadable);
        }

        let bytes;
        try {
            bytes = Uint8Array.from(atob(image.base64), (byte) => byte.charCodeAt(0));
        } catch {
            throw new Error(unreadable);
        }
        if (bytes.length === 0 || bytes.length > maxSize) throw new Error(unreadable);

        const blob = new Blob([bytes], {type: image.mime_type});
        draft = {blob, url: URL.createObjectURL(blob), extension};
        const picture = new Image(300, 400);
        picture.alt = '생성된 캐릭터 이미지';
        picture.className = current.className;
        picture.src = draft.url;
        try {
            await picture.decode();
        } catch {
            throw new Error(unreadable);
        }
        preview.replaceChildren(picture);
        result.hidden = false;
    }

    async function generatePortrait(automatic = false) {
        if (busy || draft) return;
        requestId ??= crypto.randomUUID();
        setBusy(true);
        report('캐릭터 이미지를 만드는 중이에요.', 'loading');
        let focus = generate;
        try {
            const data = await post(generateUrl, {
                headers: {'Content-Type': 'application/json', Accept: 'application/json'},
                body: JSON.stringify({_csrf: csrf, id: Number(id), request_id: requestId}),
            }, '이미지 생성 응답을 확인하지 못했어요. 잠시 후 다시 시도해 주세요.');
            requestId = null;
            await showPreview(data?.image);
            report('AI 이미지가 생성됐어요.', 'success');
            focus = heading;
        } catch (error) {
            if (error.code && error.code !== 'AI_IN_PROGRESS') requestId = null;
            clearPreview();
            report(automatic ? `캐릭터 설정은 저장됐어요. ${error.message}` : error.message, 'error');
        } finally {
            setBusy(false);
            if (!automatic) focus.focus();
        }
    }

    generate.addEventListener('click', () => generatePortrait());

    apply.addEventListener('click', async () => {
        if (busy || !draft) return;
        setBusy(true);
        report('프로필 이미지를 저장하는 중이에요.', 'loading');
        let focus = apply;
        try {
            const body = new FormData();
            body.append('_csrf', csrf);
            body.append('target_id', id);
            body.append('file', draft.blob, `ai-portrait.${draft.extension}`);
            const data = await post(uploadUrl, {body, headers: {Accept: 'application/json'}},
                '이미지 저장 결과를 확인하지 못했어요. 미리보기를 유지했어요.');
            const saved = data?.character;
            if (saved?.has_image !== true || String(saved.id) !== id || typeof saved.updated_at !== 'string') {
                throw new Error('저장된 이미지 정보를 확인하지 못했어요.');
            }
            const storedUrl = new URL(imageUrl, window.location.href);
            storedUrl.searchParams.set('v', saved.updated_at);
            current.src = storedUrl.href;
            clearPreview();
            report('프로필 이미지를 저장했어요.', 'success');
            focus = generate;
        } catch (error) {
            report(error.message, 'error');
        } finally {
            setBusy(false);
            focus.focus();
        }
    });

    discard.addEventListener('click', () => {
        if (busy || !draft) return;
        clearPreview();
        setBusy(false);
        report('미리보기를 버렸어요.');
        generate.focus();
    });

    window.addEventListener('pagehide', (event) => {
        if (!event.persisted) clearPreview();
    });
    tools.hidden = false;
    if (autoRequestId) generatePortrait(true);
})();
