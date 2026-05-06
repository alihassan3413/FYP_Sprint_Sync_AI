<script setup lang="ts" generic="T extends Record<string, any>">
import { computed } from 'vue';
import { ChevronDown, ChevronUp } from 'lucide-vue-next';

export interface Column<Row = any> {
  key: string;
  label?: string;
  sortable?: boolean;
  align?: 'left' | 'center' | 'right';
  width?: string;
  hideOnMobile?: boolean;
  accessor?: (row: Row) => any;
  cellClass?: string;
  headerClass?: string;
}

export type SortDirection = 'asc' | 'desc' | null;

export interface SortState {
  key: string | null;
  direction: SortDirection;
}

interface Props<Row> {
  columns: Column<Row>[];
  rows: Row[];
  rowKey?: string | ((row: Row) => string | number);
  loading?: boolean;
  skeletonRows?: number;
  clickableRows?: boolean;
  highlightedKey?: string | number | null;
  density?: 'comfortable' | 'compact';
  bordered?: boolean;
}

const props = withDefaults(defineProps<Props<T>>(), {
  rowKey: 'id',
  loading: false,
  skeletonRows: 5,
  clickableRows: false,
  highlightedKey: null,
  density: 'comfortable',
  bordered: false,
});

const emit = defineEmits<{
  (e: 'row-click', row: T, event: MouseEvent): void;
}>();

const sort = defineModel<SortState>('sort', {
  default: () => ({ key: null, direction: null }),
});

function toggleSort(col: Column<T>) {
  if (!col.sortable) return;

  if (sort.value.key !== col.key) {
    sort.value = { key: col.key, direction: 'asc' };
    return;
  }

  if (sort.value.direction === 'asc') {
    sort.value = { key: col.key, direction: 'desc' };
    return;
  }

  sort.value = { key: null, direction: null };
}

const sortedRows = computed(() => {
  if (!sort.value.key || !sort.value.direction) return props.rows;

  const col = props.columns.find((column) => column.key === sort.value.key);
  if (!col) return props.rows;

  const direction = sort.value.direction === 'asc' ? 1 : -1;
  const getValue = col.accessor ?? ((row: T) => row[col.key]);

  return [...props.rows].sort((a, b) => {
    const aValue = getValue(a);
    const bValue = getValue(b);

    if (aValue == null && bValue == null) return 0;
    if (aValue == null) return 1;
    if (bValue == null) return -1;

    if (typeof aValue === 'number' && typeof bValue === 'number') {
      return (aValue - bValue) * direction;
    }

    return String(aValue).localeCompare(String(bValue)) * direction;
  });
});

function getRowKey(row: T): string | number {
  if (typeof props.rowKey === 'function') {
    return props.rowKey(row);
  }

  return row[props.rowKey] ?? JSON.stringify(row);
}

function getCellValue(row: T, col: Column<T>) {
  return col.accessor ? col.accessor(row) : row[col.key];
}

function alignClass(align?: 'left' | 'center' | 'right') {
  if (align === 'right') return 'text-right';
  if (align === 'center') return 'text-center';

  return 'text-left';
}

const cellPadding = computed(() =>
  props.density === 'compact' ? 'px-4 py-2.5' : 'px-6 py-3.5',
);

const headerPadding = computed(() =>
  props.density === 'compact' ? 'px-4 py-2' : 'px-6 py-2.5',
);

const colCount = computed(() => props.columns.length);
</script>

<template>
  <div
    :class="[
      'overflow-hidden',
      bordered && 'rounded-xl border bg-card',
    ]"
  >
    <div class="overflow-x-auto">
      <table class="w-full text-sm">
        <thead>
          <tr class="border-b bg-muted/30 text-left">
            <th
              v-for="col in columns"
              :key="col.key"
              scope="col"
              :style="col.width ? { width: col.width } : undefined"
              :class="[
                headerPadding,
                'text-[11px] font-medium uppercase tracking-[0.06em] text-muted-foreground',
                alignClass(col.align),
                col.hideOnMobile && 'hidden sm:table-cell',
                col.sortable && 'cursor-pointer select-none transition-colors hover:text-foreground',
                col.headerClass,
              ]"
              :aria-sort="
                sort.key === col.key
                  ? sort.direction === 'asc'
                    ? 'ascending'
                    : sort.direction === 'desc'
                      ? 'descending'
                      : 'none'
                  : undefined
              "
              @click="toggleSort(col)"
            >
              <span class="inline-flex items-center gap-1.5">
                <slot :name="`header-${col.key}`" :column="col">
                  {{ col.label }}
                </slot>

                <span
                  v-if="col.sortable"
                  class="inline-flex flex-col leading-none opacity-50 transition-opacity"
                  :class="sort.key === col.key && 'opacity-100'"
                  aria-hidden="true"
                >
                  <ChevronUp
                    class="size-2"
                    :class="
                      sort.key === col.key && sort.direction === 'asc'
                        ? 'text-foreground'
                        : 'text-muted-foreground/60'
                    "
                  />

                  <ChevronDown
                    class="size-2 -mt-0.5"
                    :class="
                      sort.key === col.key && sort.direction === 'desc'
                        ? 'text-foreground'
                        : 'text-muted-foreground/60'
                    "
                  />
                </span>
              </span>
            </th>
          </tr>
        </thead>

        <tbody>
          <template v-if="loading">
            <tr
              v-for="n in skeletonRows"
              :key="`skeleton-${n}`"
              class="border-b last:border-b-0"
            >
              <td
                v-for="col in columns"
                :key="col.key"
                :class="[
                  cellPadding,
                  col.hideOnMobile && 'hidden sm:table-cell',
                ]"
              >
                <div
                  class="h-4 animate-pulse rounded bg-muted"
                  :style="{
                    width: `${40 + ((n * 7 + col.key.length * 13) % 50)}%`,
                  }"
                />
              </td>
            </tr>
          </template>

          <template v-else-if="sortedRows.length > 0">
            <tr
              v-for="row in sortedRows"
              :key="getRowKey(row)"
              :class="[
                'group border-b last:border-b-0 transition-colors',
                clickableRows && 'cursor-pointer',
                highlightedKey != null && getRowKey(row) === highlightedKey
                  ? 'bg-violet-50/40 hover:bg-violet-50/60 dark:bg-violet-500/5 dark:hover:bg-violet-500/8'
                  : 'hover:bg-muted/40',
              ]"
              @click="(event) => clickableRows && emit('row-click', row, event)"
            >
              <td
                v-for="col in columns"
                :key="col.key"
                :class="[
                  cellPadding,
                  alignClass(col.align),
                  col.hideOnMobile && 'hidden sm:table-cell',
                  col.cellClass,
                ]"
              >
                <slot
                  :name="`cell-${col.key}`"
                  :row="row"
                  :value="getCellValue(row, col)"
                  :column="col"
                >
                  <span class="text-foreground">
                    {{ getCellValue(row, col) }}
                  </span>
                </slot>
              </td>
            </tr>
          </template>

          <tr v-else>
            <td :colspan="colCount" class="p-0">
              <slot name="empty">
                <div class="px-6 py-16 text-center text-sm text-muted-foreground">
                  No results found.
                </div>
              </slot>
            </td>
          </tr>
        </tbody>

        <tfoot v-if="$slots.footer">
          <tr class="border-t bg-muted/20">
            <slot name="footer" :rows="sortedRows" />
          </tr>
        </tfoot>
      </table>
    </div>

    <div
      v-if="$slots['table-footer'] && !loading && sortedRows.length > 0"
      class="border-t"
    >
      <slot name="table-footer" :rows="sortedRows" />
    </div>
  </div>
</template>
