/**
 * Alpine component of the record form (resources/views/partials/admin/content/entry-form.blade.php).
 *
 * Saving a stored record does not leave the page: the server answers with `entry-saved`, after which the form's
 * values are the saved ones (`onSaved()`).
 *
 * Runs in the browser without a request: the address prefix (it follows the parent select), the search preview,
 * the leave-page warning, Ctrl/Cmd+S and scrolling to the first error. The one request is the slug suggestion,
 * 400 ms after the title stops changing, while the slug still follows the title (a new record whose slug was not
 * typed by hand).
 *
 * Dirty tracking compares the form's values with those it opened with, except the rich text ones: the editor's
 * HTML differs from the stored one (image src), so those count as changed only once an editor reports an edit
 * (the `rich-text:changed` event).
 *
 * The form's language is changed in place (`changeLocale()`; a saved record takes it on Save): one request, after which the prefix, the parents'
 * paths and the editors follow the new language, and the address bar drops its `locale` (a translation keeps it,
 * next to `from`).
 */
export default function entryForm({ readonly, slugFollows, prefix, parentPaths, siteName, nowLocal, editorModels, leaveModal, savedLocale, savedState, saveLabels }) {
    let initial = null;
    let ticket = 0;

    return {
        slugFollows: slugFollows && !readonly,
        prefix,
        parentPaths,
        editorChanged: false,
        savedLocale,
        savedState,
        pendingUrl: null,
        leaving: false,

        init() {
            initial = this.snapshot();
        },

        /**
         * The form's values without the rich text ones, as a string to compare.
         */
        snapshot() {
            const form = JSON.parse(JSON.stringify(this.$wire.form ?? {}));

            delete form.body;
            editorModels.forEach((name) => delete form.fields?.[name]);

            return JSON.stringify(form);
        },

        isDirty() {
            if (readonly || this.$wire.finished) {
                return false;
            }

            // A saved record whose language was changed in the form has that to save.
            return this.editorChanged || this.snapshot() !== initial || (!!this.savedLocale && this.$wire.locale !== this.savedLocale);
        },

        addressPrefix() {
            const path = this.parentPaths[String(this.$wire.form.parent_id ?? '')];

            return this.prefix + (path ? `${path}/` : '');
        },

        async changeLocale(select) {
            const result = await this.$wire.changeLocale(select.value);

            // Not offered (or the same): show the language the form really has.
            if (!result) {
                select.value = this.$wire.locale;

                return;
            }

            this.prefix = result.prefix;
            this.parentPaths = result.parentPaths;
            window.dispatchEvent(new CustomEvent('entry-locale:changed', { detail: { locale: result.locale } }));

            const url = new URL(window.location.href);

            if (url.searchParams.has('from')) {
                url.searchParams.set('locale', result.locale);
            } else {
                url.searchParams.delete('locale');
            }

            window.history.replaceState(window.history.state, '', url);
        },

        async followTitle() {
            if (!this.slugFollows) {
                return;
            }

            const mine = ++ticket;
            const slug = await this.$wire.suggestSlug();

            // A later suggestion, or a slug typed meanwhile, wins.
            if (mine === ticket && this.slugFollows && typeof slug === 'string') {
                this.$wire.set('form.slug', slug, false);
            }
        },

        onSlugInput(event) {
            // Typing takes the slug over; emptying it lets it follow the title again.
            this.slugFollows = !readonly && event.target.value.trim() === '';
        },

        onParentChange() {
            this.followTitle();
        },

        seoAddress() {
            const address = (this.addressPrefix() + (this.$wire.form.slug ?? '')).replace(/\/+$/, '');

            return address.split('/').join(' › ');
        },

        seoTitle() {
            const own = String(this.$wire.form.seo_title ?? '').trim();

            if (own !== '') {
                return own;
            }

            // The same rule as Entry::documentTitle(): the SEO title alone, else the title, " - " and the site name.
            return [String(this.$wire.form.title ?? '').trim(), siteName].filter((part) => part !== '').join(' - ');
        },

        seoDescription() {
            const own = String(this.$wire.form.seo_description ?? '').trim();

            return own !== '' ? own : String(this.$wire.form.excerpt ?? '').trim();
        },

        isScheduled() {
            const date = String(this.$wire.form.published_at ?? '');

            return this.$wire.form.status === 'published' && date !== '' && date > nowLocal;
        },

        publishesNow() {
            return this.$wire.form.status === 'published' && String(this.$wire.form.published_at ?? '') === '';
        },

        /**
         * What Save will do, as the button's label: publish or schedule a record that is not that yet, keep a new
         * one as a draft, else plain Save.
         */
        saveLabel() {
            if (this.$wire.form.status === 'published') {
                if (this.isScheduled()) {
                    return this.savedState === 'scheduled' ? saveLabels.save : saveLabels.schedule;
                }

                return this.savedState === 'published' ? saveLabels.save : saveLabels.publish;
            }

            return this.savedState === null ? saveLabels.draft : saveLabels.save;
        },

        /**
         * A saved record was stored without leaving the page: what is in the form now is the saved state.
         */
        onSaved({ locale, state, title }) {
            this.savedLocale = locale;
            this.savedState = state;
            this.editorChanged = false;
            document.title = title;
            this.$nextTick(() => {
                initial = this.snapshot();
            });
        },

        shortcutHint(text) {
            const mac = /Mac|iPhone|iPad/.test(navigator.platform || navigator.userAgent);

            return text.replace(':key', mac ? '⌘' : 'Ctrl');
        },

        onKeydown(event) {
            if (readonly || !(event.ctrlKey || event.metaKey) || event.altKey || event.key?.toLowerCase() !== 's') {
                return;
            }

            event.preventDefault();

            // Not while a dialog (a media picker, a confirmation) is open over the form.
            if (document.querySelector('dialog[open]')) {
                return;
            }

            this.$refs.form.requestSubmit();
        },

        /**
         * After a failed save: bring the first field with an error into view (after Livewire has morphed the page).
         */
        scrollToError() {
            requestAnimationFrame(() => {
                const error = this.$root.querySelector('[data-flux-error]:not(.hidden)');

                if (!error) {
                    return;
                }

                const field = error.closest('[data-flux-field]') ?? error.parentElement ?? error;
                field.scrollIntoView({ block: 'center', behavior: 'smooth' });
                field.querySelector('input:not([type=hidden]), textarea, select')?.focus({ preventScroll: true });
            });
        },

        onNavigate(event) {
            if (!this.leaving && !event.detail.history && this.isDirty()) {
                event.preventDefault();
                this.pendingUrl = event.detail.url.toString();
                this.$flux.modal(leaveModal).show();
            }
        },

        onUnload(event) {
            if (!this.leaving && this.isDirty()) {
                event.preventDefault();
                event.returnValue = '';
            }
        },

        leave() {
            this.leaving = true;
            this.$flux.modal(leaveModal).close();
            window.Livewire.navigate(this.pendingUrl);
        },
    };
}
