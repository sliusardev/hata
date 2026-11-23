/**
 * Alpine component of <x-hata::admin.rich-text>: the toolbar state, the link form and the image alt field around a
 * Tiptap editor. Tiptap itself (./rich-text.js) is imported on init, so it is a separate chunk.
 *
 * The editor writes its HTML into the bound Livewire property without a request ($wire.set(name, html, false)),
 * 300 ms after the last change, and again synchronously whenever the component starts any Livewire action while an
 * edit is still unwritten (an action interceptor runs before Livewire collects the property updates), so a save
 * right after typing carries the latest text and an untouched body is never sent back. An empty document is handed over as ''. The editor never reads the property back after init:
 * a server round trip does not reset it.
 *
 * The editor instance is kept in the closure, not in Alpine's reactive data: ProseMirror breaks behind a Proxy.
 */

const SYNC_DELAY = 300;

const MARKS = ['bold', 'italic', 'underline', 'strike', 'bulletList', 'orderedList', 'blockquote', 'link'];

export default function richText({ model, target, id, images = {}, readonly = false, locale = '', labelledBy = null }) {
    let editor = null;
    let module = null;
    let timer = null;
    let pending = false;
    let stopIntercepting = null;
    let destroyed = false;
    let selectedImagePos = null;

    return {
        ready: false,
        failed: false,
        active: Object.fromEntries(MARKS.map((name) => [name, false])),
        block: 'paragraph',
        canUndo: false,
        canRedo: false,
        linkOpen: false,
        linkHref: '',
        linkNewTab: false,
        linkInvalid: false,
        imageSelected: false,
        imageAlt: '',

        async init() {
            try {
                module = await import('./rich-text.js');
            } catch {
                this.failed = true;

                return;
            }

            if (destroyed) {
                return;
            }

            const value = this.$wire.get(model);

            editor = module.createEditor({
                element: this.$refs.content,
                content: typeof value === 'string' ? value : '',
                images,
                editable: !readonly,
                id,
                labelledBy,
                onUpdate: () => this.queueSync(),
                onStateChange: (transaction) => this.refresh(transaction),
                onLinkShortcut: () => this.openLink(),
            });

            if (!readonly) {
                stopIntercepting = this.$wire.$intercept(() => this.sync());
            }

            this.ready = true;
            this.refresh(null);
        },

        destroy() {
            destroyed = true;
            clearTimeout(timer);
            stopIntercepting?.();
            editor?.destroy();
            editor = null;
        },

        /**
         * Called for edits only (a focus or click that makes the editor append an empty paragraph is not one, see
         * isContentEdit()). The first edit after a write tells the page through the bubbling `rich-text:changed`
         * event, so a form can count the editor as changed without comparing HTML.
         */
        queueSync() {
            if (!pending) {
                this.$el.dispatchEvent(new CustomEvent('rich-text:changed', { bubbles: true, detail: { model } }));
            }

            pending = true;
            clearTimeout(timer);
            timer = setTimeout(() => this.sync(), SYNC_DELAY);
        },

        /**
         * Write the editor's HTML into the bound property now, without a request, if an edit is unwritten.
         */
        sync() {
            clearTimeout(timer);
            timer = null;

            if (!editor || readonly || !pending) {
                return;
            }

            pending = false;
            this.$wire.set(model, editor.isEmpty ? '' : editor.getHTML(), false);
        },

        /**
         * Toolbar state from the editor: active marks and blocks, undo/redo, the selected image. A transaction that
         * changed neither the document, the selection nor the stored marks changes nothing here; undo/redo only
         * change with the document. Only values that differ are assigned, so Alpine re-renders only those.
         */
        refresh(transaction) {
            if (!editor || (transaction && !transaction.docChanged && !transaction.selectionSet && !transaction.storedMarksSet)) {
                return;
            }

            MARKS.forEach((name) => {
                const isActive = editor.isActive(name);

                if (this.active[name] !== isActive) {
                    this.active[name] = isActive;
                }
            });

            const level = [2, 3, 4].find((candidate) => editor.isActive('heading', { level: candidate }));
            const block = level ? `h${level}` : 'paragraph';

            if (this.block !== block) {
                this.block = block;
            }

            if (!transaction || transaction.docChanged) {
                const canUndo = editor.can().undo();
                const canRedo = editor.can().redo();

                if (this.canUndo !== canUndo) {
                    this.canUndo = canUndo;
                }

                if (this.canRedo !== canRedo) {
                    this.canRedo = canRedo;
                }
            }

            const { selection } = editor.state;
            const image = selection.node?.type.name === 'mediaImage' ? selection.node : null;

            if (this.imageSelected !== (image !== null)) {
                this.imageSelected = image !== null;
            }

            // The alt field is filled when another image is selected, not on its own edits (keeps the caret).
            if (image && selectedImagePos !== selection.from) {
                this.imageAlt = image.attrs.alt ?? '';
            }

            selectedImagePos = image ? selection.from : null;
        },

        command(name) {
            if (!editor) {
                return;
            }

            const chain = editor.chain().focus();
            const commands = {
                bold: () => chain.toggleBold(),
                italic: () => chain.toggleItalic(),
                underline: () => chain.toggleUnderline(),
                strike: () => chain.toggleStrike(),
                bulletList: () => chain.toggleBulletList(),
                orderedList: () => chain.toggleOrderedList(),
                blockquote: () => chain.toggleBlockquote(),
                undo: () => chain.undo(),
                redo: () => chain.redo(),
            };

            commands[name]?.().run();
        },

        setBlock(value) {
            if (!editor) {
                return;
            }

            const level = { h2: 2, h3: 3, h4: 4 }[value];
            const chain = editor.chain().focus();

            (level ? chain.setHeading({ level }) : chain.setParagraph()).run();
        },

        openLink() {
            if (!editor || readonly) {
                return;
            }

            const attributes = editor.getAttributes('link');
            this.linkHref = attributes.href ?? '';
            this.linkNewTab = attributes.target === '_blank';
            this.linkInvalid = false;
            this.linkOpen = true;
            this.$nextTick(() => this.$refs.linkInput?.focus());
        },

        closeLink() {
            this.linkOpen = false;
            editor?.commands.focus();
        },

        applyLink() {
            if (!editor) {
                return;
            }

            const href = module.normalizeHref(this.linkHref);

            if (href === '') {
                this.removeLink();

                return;
            }

            if (!module.isAllowedHref(href)) {
                this.linkInvalid = true;

                return;
            }

            const attributes = { href, target: this.linkNewTab ? '_blank' : null };

            if (editor.state.selection.empty && !editor.isActive('link')) {
                // Nothing selected: the address becomes the link's text, and typing after it is plain text again.
                module.insertLinkText(editor.chain().focus(), href, attributes).run();
            } else {
                editor.chain().focus().extendMarkRange('link').setLink(attributes).run();
            }

            this.linkOpen = false;
        },

        removeLink() {
            editor?.chain().focus().extendMarkRange('link').unsetLink().run();
            this.linkOpen = false;
        },

        /**
         * The record's language changed while the form is open (a new record's can).
         */
        setLocale(code) {
            locale = code;
        },

        /**
         * Ask the page's detached media picker for an image; only this editor reacts to the answer.
         */
        openImagePicker() {
            window.dispatchEvent(new CustomEvent('media-picker:open', { detail: { target, locale } }));
        },

        onPicked(detail) {
            if (!editor || readonly || detail?.target !== target || !detail.id || !detail.url) {
                return;
            }

            module
                .insertImage(editor.chain().focus(), editor.state, {
                    mediaId: String(detail.id),
                    alt: detail.alt ?? '',
                    src: detail.url,
                })
                .run();
        },

        updateAlt() {
            if (!editor || !this.imageSelected) {
                return;
            }

            editor.chain().updateAttributes('mediaImage', { alt: this.imageAlt }).run();
        },
    };
}

