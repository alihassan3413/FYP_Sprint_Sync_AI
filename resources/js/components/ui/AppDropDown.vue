<script setup lang="ts">
/**
 * AppDropdownMenu — declarative wrapper around shadcn's dropdown-menu.
 *
 * Reduces verbose <DropdownMenuItem> ladders to a clean `items` config.
 * Use this for any contextual menu in the app — row actions, kebab menus,
 * "more options" buttons, etc.
 *
 * For complex cases (custom item rendering, nested menus), drop down to the
 * raw shadcn primitives — this component intentionally covers the 90% case.
 *
 * Usage:
 *   <AppDropdownMenu :items="actions" align="end" />
 *
 *   <AppDropdownMenu :items="actions">
 *     <template #trigger>
 *       <Button>Custom trigger</Button>
 *     </template>
 *   </AppDropdownMenu>
 */

import { type Component } from 'vue';
import { MoreHorizontal } from 'lucide-vue-next';

import { Button } from '@/components/ui/button';
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuLabel,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';

/* ----------------------------------------------------------------------------
 * Types
 * ------------------------------------------------------------------------- */
export interface DropdownItem {
  /** Visible label */
  label: string;
  /** Click handler — fired when the item is selected */
  onSelect?: (event: Event) => void;
  /** Optional Lucide icon component */
  icon?: Component;
  /** Disable the item */
  disabled?: boolean;
  /** Style as a destructive action (red) */
  destructive?: boolean;
  /** Optional trailing keyboard shortcut hint, e.g. "⌘E" */
  shortcut?: string;
  /** Render a section heading instead of an item — `label` is the heading */
  heading?: boolean;
}

/**
 * The `items` array supports two grouping styles:
 *  1. Flat array of `DropdownItem`
 *  2. `null` value as a separator between groups
 *
 * Example:
 *   [
 *     { label: 'Edit',   onSelect: () => ... },
 *     { label: 'Copy',   onSelect: () => ... },
 *     null,                                           // separator
 *     { label: 'Delete', onSelect: () => ..., destructive: true },
 *   ]
 */
export type DropdownEntry = DropdownItem | null;

interface Props {
  items: DropdownEntry[];
  /** Optional menu heading shown at the top */
  heading?: string;
  /** Menu alignment */
  align?: 'start' | 'center' | 'end';
  /** Menu side */
  side?: 'top' | 'right' | 'bottom' | 'left';
  /** Tailwind width class for the content */
  width?: string;
  /** ARIA label for the default trigger button */
  triggerLabel?: string;
}

withDefaults(defineProps<Props>(), {
  align: 'end',
  side: 'bottom',
  width: 'w-52',
  triggerLabel: 'Open menu',
});
</script>

<template>
  <DropdownMenu>
    <DropdownMenuTrigger as-child>
      <slot name="trigger">
        <!-- Default: shadcn ghost icon button with kebab -->
        <Button
          variant="ghost"
          size="icon"
          class="size-8"
          :aria-label="triggerLabel"
          @click.stop
        >
          <MoreHorizontal class="size-4" />
        </Button>
      </slot>
    </DropdownMenuTrigger>

    <DropdownMenuContent :align="align" :side="side" :class="width">
      <template v-if="heading">
        <DropdownMenuLabel class="text-xs">{{ heading }}</DropdownMenuLabel>
        <DropdownMenuSeparator />
      </template>

      <template v-for="(entry, i) in items" :key="i">
        <!-- Separator -->
        <DropdownMenuSeparator v-if="entry === null" />

        <!-- Heading -->
        <DropdownMenuLabel
          v-else-if="entry.heading"
          class="text-xs"
        >
          {{ entry.label }}
        </DropdownMenuLabel>

        <!-- Item -->
        <DropdownMenuItem
          v-else
          :disabled="entry.disabled"
          :class="
            entry.destructive
              ? 'text-rose-600 focus:text-rose-600 dark:text-rose-400 dark:focus:text-rose-400'
              : ''
          "
          @select="entry.onSelect?.($event)"
        >
          <component
            v-if="entry.icon"
            :is="entry.icon"
            class="size-3.5"
          />
          <span class="flex-1">{{ entry.label }}</span>
          <span
            v-if="entry.shortcut"
            class="ml-auto text-[10px] tracking-widest text-muted-foreground"
          >
            {{ entry.shortcut }}
          </span>
        </DropdownMenuItem>
      </template>
    </DropdownMenuContent>
  </DropdownMenu>
</template>
