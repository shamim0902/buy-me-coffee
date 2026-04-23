<template>
    <nav class="bmc-breadcrumb" aria-label="Breadcrumb">
        <ol class="bmc-breadcrumb__list">
            <li
                v-for="(crumb, index) in crumbs"
                :key="crumb.path"
                class="bmc-breadcrumb__item"
            >
                <router-link
                    v-if="index < crumbs.length - 1"
                    :to="crumb.path"
                    class="bmc-breadcrumb__link"
                >{{ crumb.label }}</router-link>
                <span v-else class="bmc-breadcrumb__current">{{ crumb.label }}</span>
                <span v-if="index < crumbs.length - 1" class="bmc-breadcrumb__separator">/</span>
            </li>
        </ol>
    </nav>
</template>

<script setup>
import { computed } from 'vue';
import { useRoute } from 'vue-router';

const route = useRoute();

const crumbs = computed(() => {
    const matched = route.matched;
    const result = [];

    for (const record of matched) {
        const label = record.meta?.breadcrumb || record.name || '';
        if (label) {
            result.push({
                label: typeof label === 'function' ? label(route) : label,
                path: record.path || '/',
            });
        }
    }

    // If we have dynamic params (like supporter name), append them
    if (route.meta?.breadcrumbDynamic) {
        const last = result[result.length - 1];
        if (last) {
            last.label = route.meta.breadcrumbDynamic;
        }
    }

    return result.length ? result : [{ label: 'Dashboard', path: '/' }];
});
</script>

<style scoped>
.bmc-breadcrumb__list {
    display: flex;
    align-items: center;
    list-style: none;
    margin: 0;
    padding: 0;
    gap: 6px;
}
.bmc-breadcrumb__link {
    font-size: var(--text-sm);
    color: var(--text-tertiary);
    text-decoration: none;
    transition: color var(--duration-fast) var(--ease-default);
}
.bmc-breadcrumb__link:hover {
    color: var(--text-primary);
}
.bmc-breadcrumb__current {
    font-size: var(--text-sm);
    color: var(--text-primary);
    font-weight: 500;
}
.bmc-breadcrumb__separator {
    font-size: var(--text-sm);
    color: var(--text-tertiary);
}
</style>
