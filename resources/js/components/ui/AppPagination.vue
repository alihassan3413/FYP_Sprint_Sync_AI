<script setup lang="ts">
/**
 * AppPagination — a generic page-number control for server-paginated lists.
 *
 * Usage:
 *   <AppPagination
 *     :current-page="results.current_page"
 *     :last-page="results.last_page"
 *     :total="results.total"
 *     :from="results.from"
 *     :to="results.to"
 *     @change="(page) => goToPage(page)"
 *   />
 */

import { ChevronLeft, ChevronRight } from 'lucide-vue-next';
import { computed } from 'vue';

interface Props {
    currentPage: number;
    lastPage: number;
    total: number;
    from: number | null;
    to: number | null;
}

const props = defineProps<Props>();

const emit = defineEmits<{
    (e: 'change', page: number): void;
}>();

const pageNumbers = computed(() => {
    const pages = new Set<number>();
    const add = (page: number) => {
        if (page >= 1 && page <= props.lastPage) pages.add(page);
    };

    add(1);
    add(props.lastPage);
    add(props.currentPage - 1);
    add(props.currentPage);
    add(props.currentPage + 1);

    return [...pages].sort((a, b) => a - b);
});

function go(page: number) {
    if (page < 1 || page > props.lastPage || page === props.currentPage) return;
    emit('change', page);
}
</script>

<template>
    <div v-if="lastPage > 1" class="flex flex-col gap-3 border-t px-4 py-3 sm:flex-row sm:items-center sm:justify-between">
        <p class="text-muted-foreground text-xs">
            Showing <span class="text-foreground font-medium">{{ from }}–{{ to }}</span> of
            <span class="text-foreground font-medium">{{ total }}</span>
        </p>

        <div class="flex items-center gap-1">
            <button
                type="button"
                class="text-muted-foreground hover:bg-accent hover:text-accent-foreground inline-flex size-8 items-center justify-center rounded-md transition-colors disabled:pointer-events-none disabled:opacity-40"
                :disabled="currentPage === 1"
                aria-label="Previous page"
                @click="go(currentPage - 1)"
            >
                <ChevronLeft class="size-4" />
            </button>

            <template v-for="(page, index) in pageNumbers" :key="page">
                <span v-if="index > 0 && page - pageNumbers[index - 1] > 1" class="text-muted-foreground px-1 text-xs">…</span>

                <button
                    type="button"
                    class="inline-flex size-8 items-center justify-center rounded-md text-xs font-medium transition-colors"
                    :class="page === currentPage ? 'bg-primary text-primary-foreground' : 'text-foreground hover:bg-accent'"
                    @click="go(page)"
                >
                    {{ page }}
                </button>
            </template>

            <button
                type="button"
                class="text-muted-foreground hover:bg-accent hover:text-accent-foreground inline-flex size-8 items-center justify-center rounded-md transition-colors disabled:pointer-events-none disabled:opacity-40"
                :disabled="currentPage === lastPage"
                aria-label="Next page"
                @click="go(currentPage + 1)"
            >
                <ChevronRight class="size-4" />
            </button>
        </div>
    </div>
</template>
