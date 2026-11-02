<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref, shallowRef, watch } from 'vue';
import { useRr } from '../context';
import { uploadErrorMessage } from '../composables/errors';
import { DEFAULT_EMOJIS, createEditor, formatMoney } from '../shared';
import type { ProductCard } from '../types';

type RrEditorHandle = ReturnType<typeof createEditor>;

const props = withDefaults(defineProps<{
    modelValue: string;
    tools: string[];
    lockedTools?: Record<string, number>;
    label: string;
    describedBy?: string;
    placeholder?: string;
    disabled?: boolean;
    invalid?: boolean;
}>(), { lockedTools: () => ({}), describedBy: undefined, placeholder: '', disabled: false, invalid: false });

const emit = defineEmits<{ 'update:modelValue': [html: string]; text: [text: string]; submit: []; focus: [focused: boolean] }>();
const rr = useRr();

const mount = ref<HTMLElement | null>(null);
const handle = shallowRef<RrEditorHandle | null>(null);
const tick = ref(0);
const panel = ref<'link' | 'emoji' | 'sticker' | null>(null);
const linkUrl = ref('');
const linkError = ref('');
const uploadError = ref('');
const uploading = ref(false);
const fileInput = ref<HTMLInputElement | null>(null);
const cardQuery = ref<{ query: string; from: number; to: number } | null>(null);
const cardResults = ref<ProductCard[]>([]);
const cardLoading = ref(false);
const cardIndex = ref(0);
const uid = `rr-editor-${Math.random().toString(36).slice(2)}`;
let lastEmitted = props.modelValue;
let cardTimer: ReturnType<typeof setTimeout> | null = null;
let cardRequest = 0;

const toolbar = computed(() => handle.value?.toolbar ?? []);
const stickers = computed(() => (rr.stickers.length ? rr.stickers : rr.loadedStickers.value));
const stickersLoading = ref(false);
const stickersError = ref('');
const emojis = computed(() => rr.emojis ?? DEFAULT_EMOJIS);
const cardOpen = computed(() => cardQuery.value !== null);

function isActive(item: RrEditorHandle['toolbar'][number]): boolean {
    void tick.value;

    return handle.value ? Boolean(item.active(handle.value.editor)) : false;
}

function syncCombobox() {
    const dom = handle.value?.editor.view.dom;

    if (!dom) {
        return;
    }

    if (cardOpen.value) {
        dom.setAttribute('aria-expanded', 'true');
        dom.setAttribute('aria-controls', `${uid}-cards`);
        dom.setAttribute('aria-autocomplete', 'list');

        if (cardResults.value[cardIndex.value]) {
            dom.setAttribute('aria-activedescendant', `${uid}-card-${cardIndex.value}`);
        } else {
            dom.removeAttribute('aria-activedescendant');
        }
    } else {
        ['aria-expanded', 'aria-controls', 'aria-autocomplete', 'aria-activedescendant'].forEach((name) => dom.removeAttribute(name));
    }
}

async function searchCards(query: string) {
    const request = ++cardRequest;
    cardLoading.value = true;

    try {
        let results: ProductCard[] = [];

        if (rr.searchCards) {
            results = await rr.searchCards(query);
        } else if (/^[a-z][a-z0-9_]{0,63}:[A-Za-z0-9-]{1,64}$/.test(query)) {
            const [type, id] = query.split(':');
            const card = await rr.api.card(type, id);
            results = card ? [card] : [];
        }

        if (request === cardRequest) {
            cardResults.value = results.slice(0, 8);
            cardIndex.value = 0;
        }
    } catch {
        if (request === cardRequest) {
            cardResults.value = [];
        }
    } finally {
        if (request === cardRequest) {
            cardLoading.value = false;
            syncCombobox();
        }
    }
}

function chooseCard(card: ProductCard) {
    handle.value?.insertCard(card, cardQuery.value);
    cardQuery.value = null;
    cardResults.value = [];
    syncCombobox();
}

function onKeyDown(event: KeyboardEvent): boolean {
    if (!cardOpen.value) {
        return false;
    }

    if (event.key === 'Escape') {
        cardQuery.value = null;
        syncCombobox();

        return true;
    }

    if (!cardResults.value.length) {
        return false;
    }

    if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
        const step = event.key === 'ArrowDown' ? 1 : -1;
        cardIndex.value = (cardIndex.value + step + cardResults.value.length) % cardResults.value.length;
        syncCombobox();

        return true;
    }

    if (event.key === 'Enter' || event.key === 'Tab') {
        chooseCard(cardResults.value[cardIndex.value]);

        return true;
    }

    return false;
}

function build() {
    handle.value?.destroy();

    if (!mount.value) {
        return;
    }

    handle.value = createEditor({
        element: mount.value,
        tools: props.tools,
        lockedTools: props.lockedTools,
        content: props.modelValue,
        label: props.label,
        describedBy: props.describedBy,
        uploadPrefixes: rr.mediaPrefixes,
        stickers: rr.stickers,
        getStickers: () => rr.loadedStickers.value,
        editable: !props.disabled,
        onUpdate: ({ html, text }) => {
            lastEmitted = html;
            emit('update:modelValue', html);
            emit('text', text);
            tick.value++;
        },
        onCardQuery: (query) => {
            cardQuery.value = query;

            if (cardTimer) {
                clearTimeout(cardTimer);
            }

            if (query) {
                cardTimer = setTimeout(() => searchCards(query.query), 200);
            } else {
                cardResults.value = [];
            }

            syncCombobox();
        },
        onKeyDown,
        onSubmitShortcut: () => emit('submit'),
        onFocusChange: ({ focused }) => emit('focus', focused),
    });

    handle.value.editor.on('selectionUpdate', () => tick.value++);
    handle.value.editor.on('transaction', () => tick.value++);

    if (props.placeholder) {
        handle.value.editor.view.dom.setAttribute('data-placeholder', props.placeholder);
    }

    if (props.invalid) {
        handle.value.editor.view.dom.setAttribute('aria-invalid', 'true');
    }

    emit('text', handle.value.getText());
}

onMounted(build);
onBeforeUnmount(() => {
    if (cardTimer) {
        clearTimeout(cardTimer);
    }

    handle.value?.destroy();
});

watch(() => [props.tools.join(','), JSON.stringify(props.lockedTools)], build);
watch(() => props.modelValue, (value) => {
    if (handle.value && value !== lastEmitted) {
        lastEmitted = value;
        handle.value.setContent(value);
    }
});
watch(() => props.disabled, (disabled) => handle.value?.editor.setEditable(!disabled, false));
watch(() => props.invalid, (invalid) => {
    const dom = handle.value?.editor.view.dom;

    if (dom) {
        invalid ? dom.setAttribute('aria-invalid', 'true') : dom.removeAttribute('aria-invalid');
    }
});

function onToolbarKeydown(event: KeyboardEvent) {
    if (!['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) {
        return;
    }

    const buttons = [...(event.currentTarget as HTMLElement).querySelectorAll<HTMLButtonElement>('.rr-toolbar-button')];
    const index = buttons.indexOf(document.activeElement as HTMLButtonElement);
    const next = event.key === 'Home' ? 0 : event.key === 'End' ? buttons.length - 1 : (index + (event.key === 'ArrowRight' ? 1 : -1) + buttons.length) % buttons.length;

    event.preventDefault();
    buttons.forEach((button, position) => button.setAttribute('tabindex', position === next ? '0' : '-1'));
    buttons[next]?.focus();
}

function press(item: RrEditorHandle['toolbar'][number]) {
    if (!handle.value || item.locked !== null || props.disabled) {
        return;
    }

    uploadError.value = '';

    switch (item.kind) {
        case 'toggle':
            item.run?.(handle.value.editor);
            break;
        case 'link':
            linkUrl.value = handle.value.currentLink();
            linkError.value = '';
            panel.value = panel.value === 'link' ? null : 'link';
            nextTick(() => document.getElementById(`${uid}-link`)?.focus());
            break;
        case 'emoji':
            panel.value = panel.value === item.kind ? null : item.kind;
            break;
        case 'sticker':
            panel.value = panel.value === item.kind ? null : item.kind;

            if (panel.value === 'sticker' && stickers.value.length === 0) {
                openStickers();
            }

            break;
        case 'image':
            if (!rr.uploadImage) {
                uploadError.value = rr.t('editor.image_unavailable');
            } else {
                fileInput.value?.click();
            }

            break;
        case 'card':
            handle.value.insertText('#');
            break;
    }

    tick.value++;
}

function applyLink() {
    const url = linkUrl.value.trim();

    if (url !== '' && !/^(https?:\/\/|mailto:|\/(?!\/))/i.test(url)) {
        linkError.value = rr.t('editor.link_invalid');

        return;
    }

    handle.value?.setLink(url);
    panel.value = null;
}

async function onFile(event: Event) {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0];
    input.value = '';

    if (!file || !rr.uploadImage) {
        return;
    }

    uploading.value = true;
    uploadError.value = '';

    try {
        const uploaded = await rr.uploadImage(file);

        if (!handle.value?.insertImage({ url: uploaded.url, alt: uploaded.alt ?? '' })) {
            uploadError.value = rr.t('editor.image_failed');
        }
    } catch (failure) {
        uploadError.value = uploadErrorMessage(failure, rr.t);
    } finally {
        uploading.value = false;
    }
}

async function openStickers() {
    stickersLoading.value = true;
    stickersError.value = '';

    try {
        await rr.loadStickers();
    } catch {
        stickersError.value = rr.t('editor.stickers_failed');
    } finally {
        stickersLoading.value = false;
    }
}

function insertEmoji(emoji: string) {
    handle.value?.insertText(emoji);
    panel.value = null;
}

function insertSticker(sticker: { id: string | number; name: string; url: string }) {
    handle.value?.insertSticker(sticker);
    panel.value = null;
}

function closePanel(event: KeyboardEvent) {
    if (event.key === 'Escape' && panel.value) {
        event.stopPropagation();
        panel.value = null;
        handle.value?.focus();
    }
}

defineExpose({
    focus: () => handle.value?.focus(),
    clear: () => handle.value?.clear(),
    setContent: (html: string) => handle.value?.setContent(html),
    insertQuote: (quote: { commentId: number | string; text: string }) => handle.value?.insertQuote(quote) ?? false,
    getText: () => handle.value?.getText() ?? '',
    editor: () => handle.value?.editor ?? null,
});
</script>

<template>
    <div class="rr-editor" :class="{ 'is-disabled': disabled, 'is-invalid': invalid }" @keydown="closePanel">
        <div v-if="toolbar.length" class="rr-editor__toolbar" role="toolbar" :aria-label="rr.t('editor.toolbar')" :aria-controls="`${uid}-content`" @keydown="onToolbarKeydown">
            <button
                v-for="(item, index) in toolbar"
                :key="item.tool"
                type="button"
                class="rr-toolbar-button"
                :class="[`rr-toolbar-button--${item.tool}`, { 'is-active': isActive(item), 'is-locked': item.locked !== null }]"
                :tabindex="index === 0 ? 0 : -1"
                :aria-pressed="item.kind === 'toggle' ? (isActive(item) ? 'true' : 'false') : undefined"
                :aria-expanded="['link', 'emoji', 'sticker'].includes(item.kind) ? (panel === item.kind ? 'true' : 'false') : undefined"
                :aria-disabled="item.locked !== null || disabled ? 'true' : undefined"
                :aria-keyshortcuts="item.shortcut ? item.shortcut.replace('Mod', 'Control') : undefined"
                :title="item.locked !== null ? rr.tc('editor.locked', item.locked, { tool: rr.t(item.label) }) : rr.t(item.label)"
                :aria-label="item.locked !== null ? rr.tc('editor.locked', item.locked, { tool: rr.t(item.label) }) : rr.t(item.label)"
                :data-tool="item.tool"
                @mousedown.prevent
                @click="press(item)"
            ><span class="rr-toolbar-button__icon" aria-hidden="true" :data-icon="item.tool" /></button>
        </div>

        <div v-if="panel === 'link'" class="rr-editor__panel" role="group" :aria-label="rr.t('editor.tool.link')">
            <label :for="`${uid}-link`" class="rr-label">{{ rr.t('editor.link_url') }}</label>
            <div class="rr-input-row">
                <input :id="`${uid}-link`" v-model="linkUrl" type="url" inputmode="url" class="rr-input" placeholder="https://" :aria-invalid="linkError ? 'true' : undefined" :aria-describedby="linkError ? `${uid}-link-error` : undefined" @keydown.enter.prevent="applyLink">
                <button type="button" class="rr-button rr-button--small rr-button--primary" @click="applyLink">{{ rr.t('editor.link_apply') }}</button>
                <button v-if="handle?.currentLink()" type="button" class="rr-button rr-button--small rr-button--ghost" @click="linkUrl = ''; applyLink()">{{ rr.t('editor.link_remove') }}</button>
            </div>
            <p v-if="linkError" :id="`${uid}-link-error`" class="rr-field-error">{{ linkError }}</p>
        </div>

        <div v-if="panel === 'emoji'" class="rr-editor__panel rr-picker" role="group" :aria-label="rr.t('editor.emojis')">
            <button v-for="emoji in emojis" :key="emoji" type="button" class="rr-picker__item" :aria-label="emoji" @click="insertEmoji(emoji)">{{ emoji }}</button>
        </div>

        <div v-if="panel === 'sticker'" class="rr-editor__panel rr-picker rr-picker--stickers" role="group" :aria-label="rr.t('editor.stickers')" :aria-busy="stickersLoading ? 'true' : undefined">
            <button v-for="sticker in stickers" :key="sticker.id" type="button" class="rr-picker__item" :aria-label="sticker.name" @click="insertSticker(sticker)">
                <img :src="sticker.url" alt="" loading="lazy">
            </button>
            <p v-if="stickersLoading" class="rr-help" role="status">{{ rr.t('editor.stickers_loading') }}</p>
            <p v-else-if="stickersError" class="rr-field-error" role="alert">{{ stickersError }}</p>
            <p v-else-if="!stickers.length" class="rr-help">{{ rr.t('editor.stickers_empty') }}</p>
        </div>

        <input v-if="tools.includes('image')" ref="fileInput" type="file" accept="image/*" class="rr-visually-hidden" tabindex="-1" aria-hidden="true" @change="onFile">

        <div :id="`${uid}-content`" ref="mount" class="rr-editor__surface" :data-placeholder="placeholder" />

        <div v-if="cardOpen" :id="`${uid}-cards`" class="rr-editor__cards" role="listbox" :aria-label="rr.t('editor.card_results')" :aria-busy="cardLoading ? 'true' : undefined">
            <p v-if="cardLoading && !cardResults.length" class="rr-help">{{ rr.t('editor.card_loading') }}</p>
            <p v-else-if="!cardResults.length" class="rr-help">{{ rr.searchCards ? rr.t('editor.card_empty') : rr.t('editor.card_hint') }}</p>
            <div
                v-for="(card, index) in cardResults"
                :id="`${uid}-card-${index}`"
                :key="`${card.type}:${card.id}`"
                role="option"
                class="rr-editor__card-option"
                :class="{ 'is-active': index === cardIndex }"
                :aria-selected="index === cardIndex ? 'true' : 'false'"
                @mousedown.prevent="chooseCard(card)"
            >
                <img v-if="card.imageUrl" :src="card.imageUrl" alt="" class="rr-card__image" loading="lazy">
                <span class="rr-card__title">{{ card.title }}</span>
                <span v-if="card.price" class="rr-card__meta">{{ formatMoney(card.price, rr.locale) }}</span>
            </div>
        </div>

        <p v-if="uploading" class="rr-help" role="status">{{ rr.t('editor.image_uploading') }}</p>
        <p v-if="uploadError" class="rr-field-error" role="alert">{{ uploadError }}</p>
        <p v-if="tools.includes('product_card') && !lockedTools.product_card" class="rr-help rr-editor__hint">{{ rr.t('editor.card_hint') }}</p>
    </div>
</template>
