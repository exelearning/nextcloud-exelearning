<?php
/**
 * @var \OCP\IL10N $l
 * @var array $_
 *
 * The eXeLearning editor needs the full viewport, but we render through the
 * normal Nextcloud `user` layout so that Util::addScript and IInitialState
 * keep working. The editor root therefore breaks out of the Files-app-style
 * content container with `position: fixed; inset: 0`. The Nextcloud chrome
 * is still loaded behind it — useful if we ever want to surface a "back"
 * link or notifications — but visually invisible.
 */
?>
<style>
#exelearning-editor-root {
	position: fixed;
	top: var(--header-height, 50px);
	left: 0;
	right: 0;
	bottom: 0;
	z-index: 1000;
	background: #fff;
}
#exelearning-editor-root .exelearning-editor__frame {
	width: 100%;
	height: 100%;
	border: 0;
	display: block;
}
#exelearning-editor-root .exelearning-editor__message {
	max-width: 32rem;
	margin: 4rem auto;
	padding: 2rem;
	text-align: center;
	color: var(--color-text-light, #333);
}
</style>
<div id="exelearning-editor-root" class="exelearning-editor-root">
	<noscript>
		<p class="exelearning-editor__message"><?php p($l->t('eXeLearning requires JavaScript to load the editor.')); ?></p>
	</noscript>
</div>
