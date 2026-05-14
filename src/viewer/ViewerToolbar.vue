<template>
	<header class="exelearning-viewer__toolbar">
		<span class="exelearning-viewer__title" :title="filename">{{ filename }}</span>
		<span v-if="status" class="exelearning-viewer__status">{{ status }}</span>
	</header>
</template>

<script lang="ts">
import { defineComponent } from 'vue'

export default defineComponent({
	name: 'ViewerToolbar',
	props: {
		filename: { type: String, required: true },
		status: { type: String, default: '' },
	},
})
</script>

<style scoped>
.exelearning-viewer__toolbar {
	display: flex;
	align-items: center;
	gap: 1rem;
	padding: 0.5rem 1rem;
	background: var(--color-main-background, #fff);
	border-bottom: 1px solid var(--color-border, #ddd);
	font-size: 0.95rem;
}
.exelearning-viewer__title {
	font-weight: 600;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}
.exelearning-viewer__status {
	color: var(--color-text-maxcontrast, #666);
}
</style>
