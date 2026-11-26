const worldDetail = document.querySelector('[data-world-detail]');
if (worldDetail) {
    const id = Number(worldDetail.dataset.id);
    const duplicate = worldDetail.querySelector('[data-world-duplicate]');
    const remove = worldDetail.querySelector('[data-world-delete]');
    const cover = worldDetail.querySelector('[data-detail-cover]');
    const coverSubmit = cover.querySelector('button');
    const controls = [duplicate, remove, coverSubmit];

    async function execute(action, next) {
        if (worldDetail.hasAttribute('aria-busy')) return;
        worldDetail.setAttribute('aria-busy', 'true');
        for (const button of controls) button.disabled = true;
        try {
            const result = await action();
            location.assign(next(result));
        } catch (error) {
            LoreverseWorld.notice(worldDetail, error.message, true);
        } finally {
            worldDetail.removeAttribute('aria-busy');
            for (const button of controls) button.disabled = false;
        }
    }

    duplicate.addEventListener('click', () => {
        if (!confirm('세계관 설정만 복제할까요? 사용자 커버, 인물, 스토리 등 연결된 데이터는 복사되지 않아요.')) return;
        execute(() => LoreverseWorld.post(worldDetail, 'worlds/duplicate', {id}),
            (data) => LoreverseWorld.href(worldDetail, data.world.id));
    });
    remove.addEventListener('click', () => {
        if (!confirm('세계관과 소속 캐릭터, 관계, 스토리, 타임라인 등 연결된 데이터가 함께 삭제될 수 있어요. 삭제할까요?')) return;
        execute(() => LoreverseWorld.post(worldDetail, 'worlds/delete', {id, confirmed: true}),
            () => worldDetail.dataset.listUrl);
    });
    cover.addEventListener('submit', (event) => {
        event.preventDefault();
        const file = cover.querySelector('[name="file"]').files[0];
        if (file) execute(() => LoreverseWorld.upload(worldDetail, id, file), () => LoreverseWorld.href(worldDetail, id));
    });
}
