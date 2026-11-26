const heroVideo = document.querySelector('[data-hero-video]');
const heroToggle = document.querySelector('[data-hero-toggle]');

if (heroVideo && heroToggle) {
    const reducedMotion = matchMedia('(prefers-reduced-motion: reduce)');
    async function playHero() {
        if (!heroVideo.getAttribute('src')) heroVideo.src = heroVideo.dataset.heroVideo;
        try {
            await heroVideo.play();
        } catch {
            heroToggle.textContent = '영상 재생';
        }
    }
    heroToggle.hidden = false;
    heroVideo.addEventListener('playing', () => {
        heroVideo.classList.add('is-playing');
        heroToggle.textContent = '영상 멈춤';
    });
    heroVideo.addEventListener('pause', () => {
        heroVideo.classList.remove('is-playing');
        heroToggle.textContent = '영상 재생';
    });
    heroVideo.addEventListener('error', () => {
        heroVideo.classList.remove('is-playing');
        heroToggle.textContent = '영상 재생';
    });
    heroToggle.addEventListener('click', () => {
        if (heroVideo.paused) playHero();
        else heroVideo.pause();
    });
    reducedMotion.addEventListener('change', (event) => {
        if (event.matches) heroVideo.pause();
    });
    if (!reducedMotion.matches) playHero();
}
