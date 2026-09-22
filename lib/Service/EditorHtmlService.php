<?php

declare(strict_types=1);

namespace OCA\ExeLearning\Service;

/**
 * Builds the HTML shell used to embed the static eXeLearning editor.
 */
class EditorHtmlService {
	public function prepare(string $html, string $editorBaseHref): string {
		$staticConfig = json_encode([
			'hideUI' => (object)[
				'fileMenu' => true,
				'saveButton' => true,
				'shareButton' => false,
				'userMenu' => true,
				'downloadButton' => false,
				'helpMenu' => false,
			],
		], JSON_UNESCAPED_SLASHES);

		$configScript = '<script>(function(){'
			. 'var base=new URL(".",document.baseURI).href.replace(/\\/+$/,"");'
			. 'var origin=window.location.origin;'
			. 'window.__EXE_EMBEDDING_CONFIG__=Object.assign(' . $staticConfig . ','
			. '{basePath:base,parentOrigin:origin,trustedOrigins:[origin]});'
			. '})();</script>';

		$resilienceScript = '<script>' . $this->resilienceScript() . '</script>';
		$headInject = '<base href="' . htmlspecialchars($editorBaseHref, ENT_QUOTES) . '">' . $resilienceScript . $configScript;
		if (preg_match('/<head[^>]*>/i', $html, $match, PREG_OFFSET_CAPTURE)) {
			$position = $match[0][1] + strlen($match[0][0]);
			$html = substr($html, 0, $position) . $headInject . substr($html, $position);
		}

		$bridge = '<script>' . $this->bridgeScript() . '</script>';
		return str_ireplace('</body>', $bridge . '</body>', $html);
	}

	private function resilienceScript(): string {
		return <<<'JS'
(function () {
    if ("serviceWorker" in navigator) {
        try {
            navigator.serviceWorker.register = function () {
                return Promise.resolve({
                    scope: "", installing: null, waiting: null, active: null,
                    addEventListener: function () {}, removeEventListener: function () {},
                    update: function () { return Promise.resolve(); },
                    unregister: function () { return Promise.resolve(true); }
                });
            };
        } catch (e) { void e; }
    }

    var originalFetch = window.fetch;
    if (originalFetch) {
        window.fetch = function (input, init) {
            var url = typeof input === "string" ? input : (input && input.url) || "";
            return originalFetch.apply(this, arguments).then(function (response) {
                if (!response.ok && (url.indexOf(".css") !== -1 || url.indexOf("idevices") !== -1)) {
                    console.warn("[Nextcloud] Fetch 404 fallback:", url);
                    return new Response("/* empty fallback */", { status: 200, headers: { "Content-Type": "text/css" } });
                }
                return response;
            }).catch(function (error) {
                if (url.indexOf(".css") !== -1 || url.indexOf("idevices") !== -1) {
                    console.warn("[Nextcloud] Fetch error fallback:", url);
                    return new Response("/* empty fallback */", { status: 200, headers: { "Content-Type": "text/css" } });
                }
                throw error;
            });
        };
    }

    var patchJQuery = function ($) {
        if (!$ || !$.ajaxTransport) return;
        $.ajaxTransport("+*", function (options) {
            var url = options.url || "";
            if (!(url.indexOf(".css") !== -1 || url.indexOf("idevices") !== -1)) return;
            return {
                send: function (headers, completeCallback) {
                    var xhr = new XMLHttpRequest();
                    xhr.open(options.type || "GET", url, true);
                    xhr.onload = function () {
                        if (xhr.status >= 200 && xhr.status < 300) {
                            completeCallback(xhr.status, xhr.statusText, { text: xhr.responseText });
                        } else {
                            console.warn("[Nextcloud] jQuery 404 fallback:", url);
                            completeCallback(200, "OK", { text: "/* empty fallback */" });
                        }
                    };
                    xhr.onerror = function () {
                        console.warn("[Nextcloud] jQuery error fallback:", url);
                        completeCallback(200, "OK", { text: "/* empty fallback */" });
                    };
                    xhr.send();
                },
                abort: function () {}
            };
        });
    };
    if (window.jQuery) {
        patchJQuery(window.jQuery);
    } else {
        try {
            Object.defineProperty(window, "jQuery", {
                configurable: true,
                set: function (val) {
                    Object.defineProperty(window, "jQuery", {
                        configurable: true, writable: true, enumerable: true, value: val
                    });
                    patchJQuery(val);
                },
                get: function () { return undefined; }
            });
        } catch (e) { void e; }
    }
})();
JS;
	}

	private function bridgeScript(): string {
		return <<<'JS'
(() => {
    const send = (msg) => { try { window.parent.postMessage(msg, '*'); } catch (e) { void e; } };
    window.addEventListener('keydown', (event) => {
        if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 's') {
            event.preventDefault();
            send({ type: 'REQUEST_SAVE', requestId: 'nextcloud-exelearning-shortcut-' + Date.now() });
        }
    }, true);

    const waitForReady = () => new Promise((resolve) => {
        const tick = () => {
            const ready = window.eXeLearning && window.eXeLearning.ready;
            if (ready && typeof ready.then === 'function') ready.then(resolve);
            else setTimeout(tick, 50);
        };
        tick();
    });
    waitForReady().then(() => {
        const bridge = window.eXeLearning && window.eXeLearning.app && window.eXeLearning.app.embeddingBridge;
        if (!bridge) return;
        bridge.handleSaveRequest = async function (requestId) {
            const project = this.app.project;
            const yjsBridge = project && project._yjsBridge;
            const documentManager = yjsBridge && yjsBridge.documentManager;
            if (!window.SharedExporters || !documentManager) {
                throw new Error('Exporter unavailable');
            }
            if (typeof documentManager._updateVersionMetadata === 'function') {
                try { await documentManager._updateVersionMetadata(); } catch (_e) { void _e; }
            }
            const exporter = window.SharedExporters.createExporter(
                'elpx', documentManager,
                yjsBridge.assetCache, yjsBridge.resourceFetcher, yjsBridge.assetManager
            );
            const result = await exporter.export({});
            if (!result || !result.success || !result.data) {
                throw new Error((result && result.error) || 'Export failed');
            }
            const data = result.data;
            const bytes = data instanceof ArrayBuffer
                ? data
                : (ArrayBuffer.isView(data)
                    ? data.buffer.slice(data.byteOffset, data.byteOffset + data.byteLength)
                    : data);
            this.postToParent({
                type: 'SAVE_FILE', requestId,
                bytes, filename: result.filename || 'project.elpx',
                size: bytes.byteLength,
            });
        };
    });
})();
JS;
	}
}
