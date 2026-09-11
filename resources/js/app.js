import Alpine from 'alpinejs';
import collapse from '@alpinejs/collapse';

Alpine.plugin(collapse);

const STORAGE_KEYS = {
    theme: 'ec-theme',
    direction: 'ec-direction',
};

function getStorageValue(key) {
    try {
        return window.localStorage.getItem(key);
    } catch {
        return null;
    }
}

function setStorageValue(key, value) {
    try {
        window.localStorage.setItem(key, value);
    } catch {
        // localStorage can be unavailable in restrictive browser contexts.
    }
}

function applyTheme(theme) {
    const resolvedTheme =
        theme === 'dark' || theme === 'light'
            ? theme
            : window.matchMedia('(prefers-color-scheme: dark)').matches
                ? 'dark'
                : 'light';

    document.documentElement.classList.toggle(
        'dark',
        resolvedTheme === 'dark',
    );

    document.documentElement.dataset.theme = resolvedTheme;
}

function applyDirection(direction) {
    const resolvedDirection =
        direction === 'rtl' ? 'rtl' : 'ltr';

    document.documentElement.setAttribute(
        'dir',
        resolvedDirection,
    );
}

function initializeTheme() {
    const savedTheme = getStorageValue(STORAGE_KEYS.theme);

    applyTheme(savedTheme);
}

function initializeDirection() {
    const savedDirection = getStorageValue(STORAGE_KEYS.direction);

    applyDirection(savedDirection);
}

Alpine.store('theme', {
    current: 'light',

    init() {
        const savedTheme = getStorageValue(STORAGE_KEYS.theme);

        this.current =
            savedTheme === 'dark' || savedTheme === 'light'
                ? savedTheme
                : window.matchMedia(
                      '(prefers-color-scheme: dark)',
                  ).matches
                    ? 'dark'
                    : 'light';

        applyTheme(this.current);
    },

    toggle() {
        this.current =
            this.current === 'dark'
                ? 'light'
                : 'dark';

        applyTheme(this.current);
        setStorageValue(STORAGE_KEYS.theme, this.current);
    },

    set(theme) {
        if (theme !== 'dark' && theme !== 'light') {
            return;
        }

        this.current = theme;

        applyTheme(this.current);
        setStorageValue(STORAGE_KEYS.theme, this.current);
    },

    isDark() {
        return this.current === 'dark';
    },
});

Alpine.store('sidebar', {
    isExpanded: true,
    isHovered: false,
    isMobileOpen: false,

    toggleExpanded() {
        this.isExpanded = !this.isExpanded;
    },

    toggleMobile() {
        this.isMobileOpen = !this.isMobileOpen;
    },

    closeMobile() {
        this.isMobileOpen = false;
    },

    setHovered(value) {
        this.isHovered = Boolean(value);
    },
});

window.EduCore = Object.freeze({
    theme: Alpine.store('theme'),
    sidebar: Alpine.store('sidebar'),
});

initializeTheme();
initializeDirection();

window.Alpine = Alpine;

Alpine.start();