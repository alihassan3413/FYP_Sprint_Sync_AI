<script setup lang="ts">
/**
 * AppListToolbar — the standard toolbar for every list/table page.
 *
 * Provides:
 *   - Search input (v-model:search)
 *   - Segmented control for filter tabs (v-model:filter)
 *   - Slots for additional left/right actions
 *
 * Use this above any AppDataTable for a consistent look across pages.
 *
 * Usage:
 *   <AppListToolbar
 *     v-model:search="search"
 *     v-model:filter="filter"
 *     :filter-options="filterOptions"
 *     search-placeholder="Search members…"
 *   >
 *     <template #right>
 *       <Button variant="outline" size="sm">
 *         <ListFilter class="size-3.5" />
 *         Filter
 *       </Button>
 *     </template>
 *   </AppListToolbar>
 */

interface FilterOption {
  value: string;
  label: string;
  count?: number;
}

interface Props {
  filterOptions?: FilterOption[];
  searchPlaceholder?: string;
  /** Hide the search input entirely */
  hideSearch?: boolean;
  /** Maximum width class for the search input wrapper */
  searchWidth?: string;
}

withDefaults(defineProps<Props>(), {
  searchPlaceholder: 'Search…',
  hideSearch: false,
  searchWidth: 'sm:max-w-xs',
});

const search = defineModel<string>('search', { default: '' });
const filter = defineModel<string>('filter');
</script>

<template>
  <div class="flex flex-col gap-3 border-b bg-card px-4 py-3 sm:flex-row sm:items-center">
    <div v-if="!hideSearch" :class="['w-full', searchWidth]">
      <AppSearchInput v-model="search" :placeholder="searchPlaceholder" />
    </div>

    <div class="flex flex-1 items-center gap-2">
      <slot name="left" />

      <AppSegmentedControl
        v-if="filterOptions && filterOptions.length > 0 && filter !== undefined"
        v-model="filter"
        :options="filterOptions"
      />

      <div class="ml-auto flex items-center gap-2">
        <slot name="right" />
      </div>
    </div>
  </div>
</template>
