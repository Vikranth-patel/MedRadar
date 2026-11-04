/* MedRadar — hero background video: lazy, accessible, data-friendly.
 * - Shows the poster/photo first, fades the video in once it is actually playing.
 * - Skips the video for "reduce motion" and Data Saver / 2G connections.
 * - One video file: assets/hero-video.mp4 (H.264 plays in every modern browser, including Safari/iOS).
 * - Pause/Play button (WCAG: moving content must be pausable); pauses automatically off-screen / in background tabs.
 */
(function () {
    'use strict';
    const hero = document.getElementById('top-search'), v = document.getElementById('hero-video'), btn = document.getElementById('hero-video-toggle');
    if (!hero || !v) return;

    const conn = navigator.connection || {};
    const reduce = matchMedia('(prefers-reduced-motion: reduce)').matches;
    const saveData = conn.saveData === true || /(^|-)2g$/.test(conn.effectiveType || '');
    let userPaused = false;
    try { userPaused = localStorage.getItem('mr-hero-paused') === '1'; } catch (e) {}
    if (reduce || saveData) return;                         // keep the still photo only

    const src = v.dataset.src;
    if (!src) return;
    v.muted = true;                                          // required for autoplay

    const label = (playing) => {
        btn.setAttribute('aria-pressed', String(!playing));
        btn.setAttribute('aria-label', playing ? 'Pause background video' : 'Play background video');
        btn.querySelector('.hv-icon').textContent = playing ? '❚❚' : '▶';
        btn.querySelector('.hv-text').textContent = playing ? 'Pause video' : 'Play video';
    };
    v.addEventListener('playing', () => { hero.classList.add('video-on'); btn.classList.remove('hidden'); label(true); });
    v.addEventListener('error', () => { hero.classList.remove('video-on'); btn.classList.add('hidden'); });   // photo stays as fallback

    v.src = src; v.load();
    const play = () => { const p = v.play(); if (p && p.catch) p.catch(() => { btn.classList.remove('hidden'); label(false); }); };   // autoplay blocked → offer a Play button
    if (userPaused) { btn.classList.remove('hidden'); label(false); v.addEventListener('loadeddata', () => hero.classList.add('video-on'), { once: true }); }
    else play();

    btn.addEventListener('click', () => {
        if (v.paused) { userPaused = false; play(); } else { userPaused = true; v.pause(); label(false); }
        try { localStorage.setItem('mr-hero-paused', userPaused ? '1' : '0'); } catch (e) {}
    });

    // save battery / CPU: pause when the hero is off-screen or the tab is hidden
    const resumeIfAllowed = () => { if (!userPaused && !document.hidden && inView) play(); };
    let inView = true;
    if ('IntersectionObserver' in window) new IntersectionObserver(([e]) => { inView = e.isIntersecting; inView ? resumeIfAllowed() : v.pause(); }, { threshold: .05 }).observe(hero);
    document.addEventListener('visibilitychange', () => document.hidden ? v.pause() : resumeIfAllowed());
})();
