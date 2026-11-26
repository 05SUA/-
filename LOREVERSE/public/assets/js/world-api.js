const LoreverseWorld = {
    async post(root, route, values, signal) {
        const multipart = values instanceof FormData;
        if (multipart) values.set('_csrf', root.dataset.csrf);
        let response;
        let result;
        try {
            response = await fetch(root.dataset.apiBase + route + '.php', {
                method: 'POST', credentials: 'same-origin', signal,
                headers: multipart ? {'Accept': 'application/json'} : {
                    'Accept': 'application/json', 'Content-Type': 'application/json',
                },
                body: multipart ? values : JSON.stringify({...values, _csrf: root.dataset.csrf}),
            });
            result = await response.json();
        } catch (error) {
            if (signal?.aborted) throw error;
            throw new Error('서버 응답을 확인할 수 없어요. 잠시 후 다시 시도해 주세요.');
        }
        if (!response.ok || !result.ok) {
            throw new Error(result.error?.message ?? '요청을 처리하지 못했어요.');
        }
        return result.data;
    },
    upload(root, id, file) {
        const data = new FormData();
        data.set('type', 'world');
        data.set('target_id', String(id));
        data.set('file', file);
        return this.post(root, 'uploads/image', data);
    },
    notice(root, message, focus = false) {
        const status = root.querySelector('[data-world-message]');
        status.textContent = message;
        status.hidden = message === '';
        if (message && focus) status.focus();
    },
    href(root, id) {
        return root.dataset.worldUrl + '?' + new URLSearchParams({id: String(id)});
    },
};
