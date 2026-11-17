import Sortable from 'sortablejs';

document.addEventListener('alpine:init', () => {
    window.Alpine.magic('sortable', (el) => (options = {}) => {
        return new Sortable(el, options);
    });

    window.Alpine.store('theme', {
        dark: localStorage.getItem('theme') === 'dark' ||
              (!localStorage.getItem('theme') && window.matchMedia('(prefers-color-scheme: dark)').matches),

        init() {
            this.apply();
        },

        toggle() {
            this.dark = !this.dark;
            const theme = this.dark ? 'dark' : 'light';
            localStorage.setItem('theme', theme);
            this.apply();
            fetch('/theme', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content },
                body: JSON.stringify({ theme }),
            });
        },

        apply() {
            document.documentElement.classList.toggle('dark', this.dark);
        },
    });
});
