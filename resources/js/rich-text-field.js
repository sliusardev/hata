/**
 * Alpine component of <x-admin.rich-text>: the toolbar state, the link form and the image alt field around a
 * Tiptap editor. Tiptap itself (./rich-text.js) is imported on init, so it is a separate chunk.
 *
 * The editor writes its HTML into the bound Livewire property without a request ($wire.set(name, html, false)),
 * 300 ms after the last change, and again synchronously whenever the component starts any Livewire action (an
 * action interceptor runs before Livewire collects the property updates), so a save right after typing carries
 * the latest text. An empty document is handed over as ''. The editor never reads the property back after init:
 * a server round trip does not reset it.
 *
 * The editor instance is kept in the closure, not in Alpine's reactive data: ProseMirror breaks behind a Proxy.
 */

const SYNC_DELAY = 300;

export default function richText({ model, target, images = {}, readonly = false, locale = '', labelledBy = null }) {
    let editor = null;
    let timer = null;
    let stopIntercepting = null;
    let destroyed = false;
    let isAllowedHref = () => false;
    let selectedImagePos = null;

    return {
        ready: false,
        failed: false,
        active: {},
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
            let module;

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
            isAllowedHref = module.isAllowedHref;

            editor = module.createEditor({
                element: this.$refs.content,
                content: typeof value === 'string' ? value : '',
                images,
                editable: !readonly,
                labelledBy,
                onUpdate: () => this.queueSync(),
                onStateChange: () => this.refresh(),
                onLinkShortcut: () => this.openLink(),
            });

            if (!readonly) {
                stopIntercepting = this.$wire.$intercept(() => this.sync());
            }

            this.ready = true;
            this.refresh();
        },

        destroy() {
            destroyed = true;
            clearTimeout(timer);
            stopIntercepting?.();
            editor?.destroy();
            editor = null;
        },

        queueSync() {
            clearTimeout(timer);
            timer = setTimeout(() => this.sync(), SYNC_DELAY);
        },

        /**
         * Write the editor's HTML into the bound property now, without a request.
         */
        sync() {
            clearTimeout(timer);
            timer = null;

            if (!editor || readonly) {
                return;
            }

            const html = editor.isEmpty ? '' : editor.getHTML();

            if (this.$wire.get(model) !== html) {
                this.$wire.set(model, html, false);
            }
        },

        /**
         * Toolbar state from the editor: active marks and blocks, undo/redo, the selected image.
         */
        refresh() {
            if (!editor) {
                return;
            }

            this.active = {
                bold: editor.isActive('bold'),
                italic: editor.isActive('italic'),
                underline: editor.isActive('underline'),
                strike: editor.isActive('strike'),
                bulletList: editor.isActive('bulletList'),
                orderedList: editor.isActive('orderedList'),
                blockquote: editor.isActive('blockquote'),
                link: editor.isActive('link'),
            };

            const level = [2, 3, 4].find((candidate) => editor.isActive('heading', { level: candidate }));
            this.block = level ? `h${level}` : 'paragraph';
            this.canUndo = editor.can().undo();
            this.canRedo = editor.can().redo();

            const { selection } = editor.state;
            const image = selection.node?.type.name === 'mediaImage' ? selection.node : null;
            this.imageSelected = image !== null;

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

            const href = normalizeHref(this.linkHref);

            if (href === '') {
                this.removeLink();

                return;
            }

            if (!isAllowedHref(href)) {
                this.linkInvalid = true;

                return;
            }

            const attributes = { href, target: this.linkNewTab ? '_blank' : null };

            if (editor.state.selection.empty && !editor.isActive('link')) {
                // Nothing selected: the address becomes the link's text, and typing after it is plain text again.
                editor
                    .chain()
                    .focus()
                    .insertContent({ type: 'text', text: href, marks: [{ type: 'link', attrs: attributes }] })
                    .unsetLink()
                    .run();
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
         * Ask the page's detached media picker for an image; only this editor reacts to the answer.
         */
        openImagePicker() {
            window.dispatchEvent(new CustomEvent('media-picker:open', { detail: { target, locale } }));
        },

        onPicked(detail) {
            if (!editor || readonly || detail?.target !== target || !detail.id || !detail.url) {
                return;
            }

            editor
                .chain()
                .focus()
                .insertContent({
                    type: 'mediaImage',
                    attrs: { mediaId: String(detail.id), alt: detail.alt ?? '', src: detail.url },
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

/**
 * A typed address without a scheme: "example.com/page" gets https://, "name@example.com" gets mailto:.
 */
function normalizeHref(value) {
    const href = String(value ?? '').trim();

    if (/^[^\s@/:]+@[^\s@/:]+\.[^\s@/:]+$/.test(href)) {
        return `mailto:${href}`;
    }

    if (/^[^\s/:?#.]+(\.[^\s/:?#.]+)*\.[a-z]{2,}([/:?#]|$)/i.test(href)) {
        return `https://${href}`;
    }

    return href;
}
