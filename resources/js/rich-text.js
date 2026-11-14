/**
 * The Tiptap editor behind <x-admin.rich-text>. Loaded with import() by the richText Alpine component, so Vite
 * puts Tiptap in its own chunk that only pages with an editor download.
 *
 * The schema allows exactly what the server keeps (App\Support\Content\BodySanitizer): paragraphs, h2-h4, bold,
 * italic, underline, strike, inline code, links (href, and target only as _blank), bullet and numbered lists,
 * blockquote, line breaks, horizontal rules, code blocks and images of the media library. Anything else that is
 * pasted is reduced to that by the schema (an h1 becomes a paragraph, an image without data-media-id is dropped).
 */

import { Editor, Extension, Node } from '@tiptap/core';
import StarterKit from '@tiptap/starter-kit';

const ALLOWED_SCHEME = /^(https?|mailto|tel):/i;
const ANY_SCHEME = /^[a-z][a-z0-9+.-]*:/i;
const MEDIA_ID = /^[1-9][0-9]*$/;

/**
 * The link addresses the server keeps: http, https, mailto, tel and relative addresses.
 */
export function isAllowedHref(href) {
    const value = String(href ?? '').replace(/[\s\u0000-\u001f]/g, '');

    return value !== '' && (ALLOWED_SCHEME.test(value) || !ANY_SCHEME.test(value));
}

/**
 * An image of the media library: stored as <img data-media-id alt>. The src only shows the file in the editor
 * (from the picker, or from the id => url map for a body that is opened again); the server drops it.
 */
const MediaImage = Node.create({
    name: 'mediaImage',
    group: 'block',
    atom: true,
    draggable: true,
    selectable: true,

    addOptions() {
        return { images: {} };
    },

    addAttributes() {
        return {
            mediaId: {
                default: null,
                parseHTML: (element) => element.getAttribute('data-media-id'),
                renderHTML: (attributes) => ({ 'data-media-id': attributes.mediaId }),
            },
            alt: {
                default: '',
                parseHTML: (element) => element.getAttribute('alt') ?? '',
                renderHTML: (attributes) => ({ alt: attributes.alt ?? '' }),
            },
            src: {
                default: null,
                parseHTML: (element) =>
                    this.options.images[element.getAttribute('data-media-id')] ?? element.getAttribute('src') ?? null,
                renderHTML: (attributes) => (attributes.src ? { src: attributes.src } : {}),
            },
        };
    },

    parseHTML() {
        return [
            {
                tag: 'img[data-media-id]',
                getAttrs: (element) => (MEDIA_ID.test(element.getAttribute('data-media-id') ?? '') ? null : false),
            },
        ];
    },

    renderHTML({ HTMLAttributes }) {
        return ['img', HTMLAttributes];
    },
});

/**
 * Ctrl/Cmd+K opens the link form of the component.
 */
const LinkShortcut = Extension.create({
    name: 'richTextLinkShortcut',

    addOptions() {
        return { onLinkShortcut: () => {} };
    },

    addKeyboardShortcuts() {
        return {
            'Mod-k': () => {
                this.options.onLinkShortcut();

                return true;
            },
        };
    },
});

/**
 * Pasted HTML keeps only the attributes the server keeps, so a pasted link or list looks the way it will be stored:
 * a link keeps href and target="_blank", a numbered list loses start and type.
 */
function cleanPastedHtml(html) {
    const document = new DOMParser().parseFromString(html, 'text/html');

    document.body.querySelectorAll('a').forEach((link) => {
        const target = link.getAttribute('target');

        [...link.attributes].forEach(({ name }) => name !== 'href' && link.removeAttribute(name));

        if (target === '_blank') {
            link.setAttribute('target', '_blank');
        }
    });

    document.body.querySelectorAll('ol').forEach((list) => {
        list.removeAttribute('start');
        list.removeAttribute('type');
    });

    return document.body.innerHTML;
}

/**
 * The extensions that make up the schema (exported on their own so the schema can be inspected without a page).
 */
export function editorExtensions({ images = {}, onLinkShortcut = () => {} } = {}) {
    return [
        StarterKit.configure({
            heading: { levels: [2, 3, 4] },
            link: {
                openOnClick: false,
                autolink: true,
                defaultProtocol: 'https',
                HTMLAttributes: { target: null, rel: null, class: null },
                isAllowedUri: (url) => isAllowedHref(url),
            },
        }),
        MediaImage.configure({ images }),
        LinkShortcut.configure({ onLinkShortcut }),
    ];
}

/**
 * @param {{
 *   element: HTMLElement,
 *   content: string,
 *   images: Record<string, string>,
 *   editable: boolean,
 *   labelledBy: string|null,
 *   onUpdate: () => void,
 *   onStateChange: () => void,
 *   onLinkShortcut: () => void,
 * }} options
 */
export function createEditor({ element, content, images, editable, labelledBy, onUpdate, onStateChange, onLinkShortcut }) {
    const attributes = { class: 'rich-text-content', 'aria-multiline': 'true', role: 'textbox' };

    if (labelledBy) {
        attributes['aria-labelledby'] = labelledBy;
    }

    if (!editable) {
        attributes['aria-readonly'] = 'true';
    }

    return new Editor({
        element,
        content,
        editable,
        extensions: editorExtensions({ images, onLinkShortcut }),
        editorProps: {
            attributes,
            transformPastedHTML: cleanPastedHtml,
        },
        onUpdate,
        onTransaction: onStateChange,
        onFocus: onStateChange,
        onBlur: onStateChange,
    });
}
