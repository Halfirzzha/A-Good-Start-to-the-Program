(() => {
    const root = document.body;
    if (!root) {
        return;
    }

    const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    const themeToggle = document.querySelector('[data-theme-toggle]');

    const setTheme = (theme, persist = true) => {
        root.dataset.theme = theme;
        if (persist) {
            try {
                localStorage.setItem('errorTheme', theme);
            } catch (error) {
                // Ignore storage errors.
            }
        }

        if (themeToggle) {
            const isDark = theme === 'dark';
            themeToggle.setAttribute('aria-pressed', isDark ? 'true' : 'false');
            themeToggle.textContent = isDark ? 'Light mode' : 'Dark mode';
        }
    };

    try {
        const stored = localStorage.getItem('errorTheme');
        if (stored === 'light' || stored === 'dark') {
            setTheme(stored, false);
        } else {
            const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
            setTheme(prefersDark ? 'dark' : 'light', false);
        }
    } catch (error) {
        const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
        setTheme(prefersDark ? 'dark' : 'light', false);
    }

    if (themeToggle) {
        themeToggle.addEventListener('click', () => {
            const nextTheme = root.dataset.theme === 'dark' ? 'light' : 'dark';
            setTheme(nextTheme);
        });
    }

    const copyButton = document.querySelector('[data-copy-request-id]');
    if (copyButton) {
        const value = copyButton.getAttribute('data-copy-request-id');
        if (!value || value === 'n/a') {
            copyButton.disabled = true;
        } else {
            copyButton.addEventListener('click', async () => {
                try {
                    if (navigator.clipboard && navigator.clipboard.writeText) {
                        await navigator.clipboard.writeText(value);
                        copyButton.textContent = 'Copied';
                        setTimeout(() => {
                            copyButton.textContent = 'Copy Request ID';
                        }, 1400);
                        return;
                    }

                    window.prompt('Copy Request ID:', value);
                } catch (error) {
                    window.prompt('Copy Request ID:', value);
                }
            });
        }
    }

    document.querySelectorAll('[data-action]').forEach((button) => {
        button.addEventListener('click', () => {
            const action = button.getAttribute('data-action');
            if (action === 'back') {
                history.back();
                return;
            }

            if (action === 'reload') {
                window.location.reload();
            }
        });
    });

    if (reduceMotion) {
        return;
    }

    const tiltCards = Array.from(document.querySelectorAll('[data-tilt]'));
    if (tiltCards.length === 0) {
        return;
    }

    let lastX = 0;
    let lastY = 0;
    let rafId = null;

    const updateTilt = () => {
        const x = lastX;
        const y = lastY;
        root.style.setProperty('--bg-tilt-x', x.toFixed(3));
        root.style.setProperty('--bg-tilt-y', y.toFixed(3));
        tiltCards.forEach((card) => {
            const strength = Number(card.getAttribute('data-tilt')) || 0.6;
            const tiltX = (y * 8 * strength).toFixed(2);
            const tiltY = (x * 8 * strength).toFixed(2);
            card.style.setProperty('--tilt-x', `${tiltX}deg`);
            card.style.setProperty('--tilt-y', `${tiltY}deg`);
            card.style.setProperty('--shine-x', `${50 + x * 40}%`);
            card.style.setProperty('--shine-y', `${50 + y * 40}%`);
        });
        rafId = null;
    };

    const onMove = (event) => {
        const width = window.innerWidth || 1;
        const height = window.innerHeight || 1;
        lastX = (event.clientX / width - 0.5) * 2;
        lastY = (event.clientY / height - 0.5) * 2;

        if (!rafId) {
            rafId = window.requestAnimationFrame(updateTilt);
        }
    };

    const resetTilt = () => {
        lastX = 0;
        lastY = 0;
        root.style.setProperty('--bg-tilt-x', '0');
        root.style.setProperty('--bg-tilt-y', '0');
        updateTilt();
    };

    window.addEventListener('pointermove', onMove, { passive: true });
    window.addEventListener('blur', resetTilt);
})();
