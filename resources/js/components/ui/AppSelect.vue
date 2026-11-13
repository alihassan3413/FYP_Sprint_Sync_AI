<script setup lang="ts" generic="T extends AppSelectValue">
import { ChevronsUpDown, Loader2, Search, X } from 'lucide-vue-next';
import {
    ComboboxAnchor,
    ComboboxContent,
    ComboboxEmpty,
    ComboboxGroup,
    ComboboxInput,
    ComboboxItem,
    ComboboxLabel,
    ComboboxPortal,
    ComboboxRoot,
    ComboboxSeparator,
    ComboboxViewport,
    ComboboxVirtualizer,
    SelectContent,
    SelectGroup,
    SelectItem,
    SelectItemText,
    SelectLabel,
    SelectPortal,
    SelectRoot,
    SelectSeparator,
    SelectTrigger,
    SelectValue,
    SelectViewport,
} from 'reka-ui';
import type { Component, CSSProperties, HTMLAttributes } from 'vue';

import { cn } from '@/lib/utils';

export type AppSelectValue = string | number | null;

export interface AppSelectOption<V extends AppSelectValue = AppSelectValue> {
    value: V;
    label: string;
    description?: string;
    meta?: string;
    icon?: Component;
    avatar?: { name: string; src?: string | null };
    group?: string;
    keywords?: string[];
    disabled?: boolean;
}

interface Entry {
    key: string;
    index: number;
    option: AppSelectOption<T>;
}

defineOptions({ inheritAttrs: false });

const props = withDefaults(
    defineProps<{
        options: AppSelectOption<T>[];
        placeholder?: string;
        searchPlaceholder?: string;
        emptyText?: string;
        searchable?: boolean | 'auto';
        loading?: boolean;
        disabled?: boolean;
        invalid?: boolean;
        size?: 'sm' | 'md';
        variant?: 'default' | 'inline';
        id?: string;
        class?: HTMLAttributes['class'];
        contentClass?: HTMLAttributes['class'];
    }>(),
    {
        placeholder: 'Choose…',
        searchPlaceholder: 'Search…',
        emptyText: 'Nothing to choose from yet.',
        searchable: 'auto',
        size: 'md',
        variant: 'default',
    },
);

const model = defineModel<T | undefined>();

const SEARCH_THRESHOLD = 8;
const VIRTUAL_THRESHOLD = 80;

const open = ref(false);
const inputMode = ref<'pointer' | 'keyboard'>('pointer');
const query = ref('');
const triggerRef = ref<{ $el?: HTMLElement } | HTMLElement | null>(null);
const searchRef = ref<{ $el?: HTMLElement } | null>(null);
const comboRef = ref<{ highlightSelected?: () => void } | null>(null);

const entries = computed<Entry[]>(() => props.options.map((option, index) => ({ key: `option-${index}`, index, option })));
const entryByKey = computed(() => new Map(entries.value.map((entry) => [entry.key, entry])));
const selectedEntry = computed(() => (model.value === undefined ? null : (entries.value.find((entry) => entry.option.value === model.value) ?? null)));
const selected = computed(() => selectedEntry.value?.option ?? null);

const isSearchable = computed(() => (props.searchable === 'auto' ? props.options.length > SEARCH_THRESHOLD : props.searchable));

const selectedKey = computed<string | undefined>({
    get: () => selectedEntry.value?.key,
    set: (key) => {
        const entry = key === undefined ? undefined : entryByKey.value.get(key);

        if (entry) {
            model.value = entry.option.value;
        }
    },
});

const normalize = (text: string) =>
    text
        .normalize('NFD')
        .replace(/[̀-ͯ]/g, '')
        .toLowerCase()
        .trim();

function score(option: AppSelectOption<T>, needle: string): number | null {
    const label = normalize(option.label);

    if (label.startsWith(needle)) {
        return 0;
    }

    if (label.split(/[\s\-_/·(),.]+/).some((word) => word.startsWith(needle))) {
        return 1;
    }

    if (label.includes(needle)) {
        return 2;
    }

    const extra = normalize([option.description, option.meta, ...(option.keywords ?? [])].filter(Boolean).join(' '));

    return extra.includes(needle) ? 3 : null;
}

const visible = computed<Entry[]>(() => {
    const needle = normalize(query.value);

    if (!needle) {
        return entries.value;
    }

    return entries.value
        .map((entry) => ({ entry, rank: score(entry.option, needle) }))
        .filter((candidate): candidate is { entry: Entry; rank: number } => candidate.rank !== null)
        .sort((a, b) => a.rank - b.rank || a.entry.index - b.entry.index)
        .map((candidate) => candidate.entry);
});

const sections = computed(() => {
    const groups = new Map<string, Entry[]>();

    for (const entry of visible.value) {
        const label = entry.option.group ?? '';
        const bucket = groups.get(label) ?? [];
        bucket.push(entry);
        groups.set(label, bucket);
    }

    return [...groups].map(([label, items]) => ({ label, items }));
});

const isGrouped = computed(() => props.options.some((option) => option.group));
const isVirtual = computed(() => isSearchable.value && !isGrouped.value && visible.value.length > VIRTUAL_THRESHOLD);
const visibleKeys = computed(() => visible.value.map((entry) => entry.key));
const labelOf = (key: string) => entryByKey.value.get(key)?.option.label ?? '';

function triggerElement(): HTMLElement | null {
    const target = triggerRef.value;

    if (target instanceof HTMLElement) {
        return target;
    }

    return target?.$el ?? null;
}

const coarsePointer = typeof window !== 'undefined' && window.matchMedia('(pointer: coarse)').matches;
const focusSearchOnOpen = computed(() => inputMode.value === 'keyboard' || !coarsePointer);
const closedFromOutside = ref(false);

watch(open, (isOpen) => {
    if (isOpen) {
        closedFromOutside.value = false;

        if (!focusSearchOnOpen.value) {
            nextTick(() => requestAnimationFrame(() => searchRef.value?.$el?.blur()));
        }

        if (isSearchable.value && !query.value) {
            nextTick(() => requestAnimationFrame(() => comboRef.value?.highlightSelected?.()));

            if (isVirtual.value) {
                setTimeout(() => open.value && !query.value && comboRef.value?.highlightSelected?.(), 120);
            }
        }

        return;
    }

    query.value = '';

    if (closedFromOutside.value) {
        return;
    }

    requestAnimationFrame(() => triggerElement()?.focus());
});

function onTriggerKeydown(event: KeyboardEvent) {
    inputMode.value = 'keyboard';

    if (open.value || props.disabled) {
        return;
    }

    if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
        event.preventDefault();
        open.value = true;

        return;
    }

    if (event.key.length === 1 && event.key !== ' ' && !event.metaKey && !event.ctrlKey && !event.altKey) {
        event.preventDefault();
        query.value = event.key;
        open.value = true;
    }
}

let typeahead = '';
let typeaheadTimer: ReturnType<typeof setTimeout> | undefined;

function onSelectTriggerKeydown(event: KeyboardEvent) {
    inputMode.value = 'keyboard';

    if (open.value || props.disabled || event.key.length !== 1 || event.key === ' ' || event.metaKey || event.ctrlKey || event.altKey) {
        return;
    }

    typeahead += normalize(event.key);
    clearTimeout(typeaheadTimer);
    typeaheadTimer = setTimeout(() => (typeahead = ''), 1000);

    const repeated = [...typeahead].every((character) => character === typeahead[0]);
    const needle = repeated ? typeahead[0] : typeahead;
    const start = (selectedEntry.value?.index ?? -1) + (repeated ? 1 : 0);
    const ordered = [...entries.value.slice(Math.max(start, 0)), ...entries.value.slice(0, Math.max(start, 0))];
    const match = ordered.find((entry) => !entry.option.disabled && normalize(entry.option.label).startsWith(needle));

    if (match) {
        model.value = match.option.value;
    }
}

function onSearchTab(event: KeyboardEvent) {
    const trigger = triggerElement();

    event.preventDefault();
    closedFromOutside.value = true;
    open.value = false;

    if (!trigger) {
        return;
    }

    const focusable = [
        ...document.querySelectorAll<HTMLElement>(
            'a[href], button:not([disabled]), input:not([disabled]):not([type=hidden]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])',
        ),
    ].filter((element) => element.offsetParent !== null && !element.closest('[data-reka-popper-content-wrapper]'));
    const index = focusable.indexOf(trigger);

    (focusable[index + (event.shiftKey ? -1 : 1)] ?? trigger).focus();
}

function onEscape(event: KeyboardEvent) {
    if (query.value) {
        event.preventDefault();
        query.value = '';
    }
}

const triggerClass = computed(() =>
    cn(
        'group inline-flex min-w-0 items-center text-left outline-none transition-[border-color,box-shadow,background-color] duration-150 disabled:cursor-not-allowed disabled:opacity-50',
        props.variant === 'inline'
            ? 'text-foreground hover:bg-muted focus-visible:ring-ring/25 aria-expanded:bg-muted -mx-1 gap-1 rounded-md px-1.5 py-0.5 font-medium focus-visible:ring-[3px]'
            : [
                  'border-input bg-background text-foreground w-full gap-2 rounded-md border shadow-[0_1px_2px_rgb(0_0_0/0.04)]',
                  'hover:border-foreground/20 disabled:hover:border-input',
                  'focus-visible:border-ring focus-visible:ring-ring/25 focus-visible:ring-[3px]',
                  'aria-expanded:border-ring aria-expanded:ring-ring/15 aria-expanded:ring-[3px]',
                  props.size === 'sm' ? 'h-8 pr-2 pl-2.5 text-[13px]' : 'h-10 pr-2.5 pl-3 text-sm',
                  props.invalid && 'border-destructive focus-visible:border-destructive focus-visible:ring-destructive/20',
              ],
        props.class,
    ),
);

const menuClass = computed(() =>
    cn(
        'group/menu text-popover-foreground bg-popover z-50 flex flex-col overflow-hidden rounded-lg border border-black/[0.08] dark:border-white/10',
        'shadow-[0_1px_2px_rgb(0_0_0/0.04),0_12px_32px_-8px_rgb(0_0_0/0.18)] dark:shadow-[0_1px_2px_rgb(0_0_0/0.3),0_16px_40px_-8px_rgb(0_0_0/0.6)]',
        'data-[state=open]:animate-in data-[state=open]:fade-in-0 data-[state=open]:zoom-in-[0.98] data-[state=open]:duration-[120ms] data-[state=open]:ease-out',
        'data-[side=bottom]:slide-in-from-top-0.5 data-[side=top]:slide-in-from-bottom-0.5',
        'data-[state=closed]:animate-out data-[state=closed]:fade-out-0 data-[state=closed]:duration-[80ms]',
        'motion-reduce:data-[state=open]:zoom-in-100 motion-reduce:data-[side=bottom]:slide-in-from-top-0 motion-reduce:data-[side=top]:slide-in-from-bottom-0',
        props.contentClass,
    ),
);

const sizing = (prefix: 'select' | 'combobox'): CSSProperties => ({
    minWidth: `var(--reka-${prefix}-trigger-width)`,
    width: 'max-content',
    maxWidth: `min(max(var(--reka-${prefix}-trigger-width), 22.5rem), var(--reka-${prefix}-content-available-width))`,
    maxHeight: `min(20rem, var(--reka-${prefix}-content-available-height))`,
    transformOrigin: `var(--reka-${prefix}-content-transform-origin)`,
});

const itemClass = [
    'relative flex min-h-8 w-full cursor-default items-center gap-2 rounded-[8px] px-2 py-1.5 text-sm outline-none select-none pointer-coarse:min-h-10',
    'data-[highlighted]:bg-foreground/[0.05] dark:data-[highlighted]:bg-white/[0.07]',
    'group-data-[input=keyboard]/menu:data-[highlighted]:bg-foreground/[0.08] dark:group-data-[input=keyboard]/menu:data-[highlighted]:bg-white/[0.1]',
    'group-data-[input=keyboard]/menu:data-[highlighted]:ring-foreground/15 dark:group-data-[input=keyboard]/menu:data-[highlighted]:ring-white/20 group-data-[input=keyboard]/menu:data-[highlighted]:ring-1 group-data-[input=keyboard]/menu:data-[highlighted]:ring-inset',
    'data-[disabled]:pointer-events-none data-[disabled]:opacity-45',
].join(' ');
const estimateRowSize = (index: number) => (entryByKey.value.get(visibleKeys.value[index])?.option.description ? 52 : 36);
const viewportClass = '[scrollbar-color:var(--color-border)_transparent]! [scrollbar-width:thin]!';
const groupLabelClass = 'text-muted-foreground px-2 pt-2 pb-1 text-xs font-medium';
const separatorClass = 'bg-border -mx-1 my-1 h-px';
</script>

<template>
    <SelectRoot v-if="!isSearchable" v-model="selectedKey" v-model:open="open" :disabled="disabled">
        <SelectTrigger
            :id="id"
            ref="triggerRef"
            v-bind="$attrs"
            :aria-invalid="invalid || undefined"
            :aria-busy="loading || undefined"
            :class="triggerClass"
            @pointerdown="inputMode = 'pointer'"
            @keydown="onSelectTriggerKeydown"
        >
            <SelectValue as-child>
                <span class="flex min-w-0 flex-1 items-center gap-2">
                    <AppAvatar
                        v-if="selected?.avatar"
                        :name="selected.avatar.name"
                        :src="selected.avatar.src"
                        size="2xs"
                        class="shrink-0"
                    />
                    <component :is="selected.icon" v-else-if="selected?.icon" class="text-muted-foreground size-4 shrink-0" />
                    <span class="truncate" :class="!selected && 'text-muted-foreground'">{{ selected?.label ?? placeholder }}</span>
                </span>
            </SelectValue>
            <Loader2 v-if="loading" class="text-muted-foreground size-4 shrink-0 animate-spin motion-reduce:animate-none" />
            <ChevronsUpDown
                v-else
                :class="cn('text-muted-foreground group-hover:text-foreground shrink-0 transition-colors', variant === 'inline' ? 'size-3.5' : 'size-4')"
                aria-hidden="true"
            />
        </SelectTrigger>

        <SelectPortal>
            <SelectContent
                position="popper"
                side="bottom"
                align="start"
                :side-offset="6"
                :collision-padding="8"
                :body-lock="false"
                :class="menuClass"
                :style="sizing('select')"
                :data-input="inputMode"
                @pointer-down-outside="closedFromOutside = true"
                @keydown.capture="inputMode = 'keyboard'"
                @pointermove="inputMode = 'pointer'"
            >
                <SelectViewport :class="cn('scroll-py-1 p-1', viewportClass)">
                    <div v-if="loading" class="flex flex-col gap-1 p-1" aria-hidden="true">
                        <div v-for="row in 3" :key="row" class="bg-muted h-7 animate-pulse rounded-[8px] motion-reduce:animate-none" />
                    </div>
                    <p v-else-if="!options.length" class="text-muted-foreground px-3 py-5 text-center text-[13px]">{{ emptyText }}</p>
                    <template v-else>
                        <template v-for="(section, sectionIndex) in sections" :key="section.label">
                            <SelectSeparator v-if="sectionIndex > 0" :class="separatorClass" />
                            <SelectGroup>
                                <SelectLabel v-if="section.label" :class="groupLabelClass">{{ section.label }}</SelectLabel>
                                <SelectItem
                                    v-for="entry in section.items"
                                    :key="entry.key"
                                    :value="entry.key"
                                    :disabled="entry.option.disabled"
                                    :class="itemClass"
                                >
                                    <AppSelectRow :option="entry.option" :selected="entry.key === selectedKey">
                                        <SelectItemText>{{ entry.option.label }}</SelectItemText>
                                    </AppSelectRow>
                                </SelectItem>
                            </SelectGroup>
                        </template>
                    </template>
                </SelectViewport>
            </SelectContent>
        </SelectPortal>
    </SelectRoot>

    <ComboboxRoot
        v-else
        ref="comboRef"
        v-model="selectedKey"
        v-model:open="open"
        :disabled="disabled"
        ignore-filter
        :reset-search-term-on-blur="false"
        :reset-search-term-on-select="false"
    >
        <ComboboxAnchor as-child>
            <button
                :id="id"
                ref="triggerRef"
                type="button"
                v-bind="$attrs"
                :disabled="disabled"
                :aria-invalid="invalid || undefined"
                :aria-busy="loading || undefined"
                aria-haspopup="listbox"
                :aria-expanded="open"
                :data-state="open ? 'open' : 'closed'"
                :class="triggerClass"
                @pointerdown="inputMode = 'pointer'"
                @click="open = !open"
                @keydown="onTriggerKeydown"
            >
                <span class="flex min-w-0 flex-1 items-center gap-2">
                    <AppAvatar
                        v-if="selected?.avatar"
                        :name="selected.avatar.name"
                        :src="selected.avatar.src"
                        size="2xs"
                        class="shrink-0"
                    />
                    <component :is="selected.icon" v-else-if="selected?.icon" class="text-muted-foreground size-4 shrink-0" />
                    <span class="truncate" :class="!selected && 'text-muted-foreground'">{{ selected?.label ?? placeholder }}</span>
                </span>
                <Loader2 v-if="loading" class="text-muted-foreground size-4 shrink-0 animate-spin motion-reduce:animate-none" />
                <ChevronsUpDown
                    v-else
                    :class="cn('text-muted-foreground group-hover:text-foreground shrink-0 transition-colors', variant === 'inline' ? 'size-3.5' : 'size-4')"
                    aria-hidden="true"
                />
            </button>
        </ComboboxAnchor>

        <ComboboxPortal>
            <ComboboxContent
                position="popper"
                side="bottom"
                align="start"
                :side-offset="6"
                :collision-padding="8"
                :class="menuClass"
                :style="sizing('combobox')"
                :data-input="inputMode"
                @escape-key-down="onEscape"
                @pointer-down-outside="closedFromOutside = true"
                @keydown.capture="inputMode = 'keyboard'"
                @pointermove="inputMode = 'pointer'"
            >
                <div class="border-border/70 flex shrink-0 items-center gap-2 border-b px-3">
                    <Search class="text-muted-foreground size-4 shrink-0" aria-hidden="true" />
                    <ComboboxInput
                        ref="searchRef"
                        v-model="query"
                        @keydown.tab="onSearchTab"
                        :auto-focus="focusSearchOnOpen"
                        :placeholder="searchPlaceholder"
                        :aria-label="searchPlaceholder"
                        class="placeholder:text-muted-foreground h-10 w-full min-w-0 bg-transparent text-base outline-none sm:h-9 sm:text-sm"
                    />
                    <button
                        v-if="query"
                        type="button"
                        class="text-muted-foreground hover:text-foreground hover:bg-muted -mr-1 inline-flex size-6 shrink-0 items-center justify-center rounded-md"
                        aria-label="Clear search"
                        @click="query = ''"
                    >
                        <X class="size-3.5" />
                    </button>
                </div>

                <ComboboxViewport :class="cn('min-h-0 flex-1 scroll-py-1 overflow-y-auto p-1', viewportClass)">
                    <div v-if="loading" class="flex flex-col gap-1 p-1" aria-hidden="true">
                        <div v-for="row in 3" :key="row" class="bg-muted h-7 animate-pulse rounded-[8px] motion-reduce:animate-none" />
                    </div>
                    <template v-else>
                        <ComboboxEmpty class="text-muted-foreground px-3 py-5 text-center text-[13px]">
                            <template v-if="!options.length">{{ emptyText }}</template>
                            <template v-else>
                                <span class="text-foreground block font-medium">No matches for “{{ query.trim() }}”</span>
                                <span class="mt-0.5 block text-xs">Check the spelling or try fewer letters.</span>
                            </template>
                        </ComboboxEmpty>

                        <ComboboxVirtualizer v-if="isVirtual" v-slot="{ option: key }" :options="visibleKeys" :estimate-size="estimateRowSize" :text-content="labelOf">
                            <ComboboxItem
                                :value="key"
                                :text-value="labelOf(key)"
                                :disabled="entryByKey.get(key)?.option.disabled"
                                :class="itemClass"
                            >
                                <AppSelectRow :option="entryByKey.get(key)!.option" :selected="key === selectedKey" :query="query" />
                            </ComboboxItem>
                        </ComboboxVirtualizer>

                        <template v-for="(section, sectionIndex) in sections" v-else :key="section.label">
                            <ComboboxSeparator v-if="sectionIndex > 0" :class="separatorClass" />
                            <ComboboxGroup>
                                <ComboboxLabel v-if="section.label" :class="groupLabelClass">{{ section.label }}</ComboboxLabel>
                                <ComboboxItem
                                    v-for="entry in section.items"
                                    :key="entry.key"
                                    :value="entry.key"
                                    :text-value="entry.option.label"
                                    :disabled="entry.option.disabled"
                                    :class="itemClass"
                                >
                                    <AppSelectRow :option="entry.option" :selected="entry.key === selectedKey" :query="query" />
                                </ComboboxItem>
                            </ComboboxGroup>
                        </template>
                    </template>
                </ComboboxViewport>
                <span class="sr-only" aria-live="polite">{{ query ? `${visible.length} ${visible.length === 1 ? 'result' : 'results'}` : '' }}</span>
            </ComboboxContent>
        </ComboboxPortal>
    </ComboboxRoot>
</template>
