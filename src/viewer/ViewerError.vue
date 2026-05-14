<template>
	<div class="exelearning-viewer__error" role="alert">
		<h2 class="exelearning-viewer__error-title">
			{{ title }}
		</h2>
		<p v-if="message" class="exelearning-viewer__error-message">
			{{ message }}
		</p>
		<details v-if="detail" class="exelearning-viewer__error-detail">
			<summary>{{ t('exelearning', 'Show details') }}</summary>
			<pre>{{ detail }}</pre>
		</details>
	</div>
</template>

<script lang="ts">
import { defineComponent } from 'vue'
import { translate as t } from '@nextcloud/l10n'

export default defineComponent({
	name: 'ViewerError',
	props: {
		title: { type: String, required: true },
		message: { type: String, default: '' },
		detail: { type: String, default: '' },
	},
	methods: {
		t,
	},
})
</script>

<style scoped>
.exelearning-viewer__error {
	padding: 2rem;
	max-width: 32rem;
	margin: 4rem auto;
	color: var(--color-text-light, #222);
	text-align: center;
}
.exelearning-viewer__error-title {
	font-size: 1.25rem;
	margin-bottom: 0.5rem;
}
.exelearning-viewer__error-message {
	margin-bottom: 1rem;
}
.exelearning-viewer__error-detail pre {
	text-align: left;
	white-space: pre-wrap;
	background: var(--color-background-dark, #f5f5f5);
	padding: 0.5rem;
	border-radius: 4px;
	font-size: 0.85rem;
}
</style>
