<?php

/**
 * This file is part of the Tracy (https://tracy.nette.org)
 * Copyright (c) 2004 David Grudl (https://davidgrudl.com)
 */

declare(strict_types=1);

namespace Tracy;


/**
 * Debug Bar.
 */
class Bar
{
	/** @var IBarPanel[] */
	private $panels = [];

	/** @var bool  initialized by dispatchAssets() */
	private $useSession = false;

	/** @var string|null  generated by renderLoader() */
	private $contentId;


	/**
	 * Add custom panel.
	 * @return static
	 */
	public function addPanel(IBarPanel $panel, ?string $id = null): self
	{
		if ($id === null) {
			$c = 0;
			do {
				$id = get_class($panel) . ($c++ ? "-$c" : '');
			} while (isset($this->panels[$id]));
		}

		$this->panels[$id] = $panel;
		return $this;
	}


	/**
	 * Returns panel with given id
	 */
	public function getPanel(string $id): ?IBarPanel
	{
		return $this->panels[$id] ?? null;
	}


	/**
	 * Renders loading <script>
	 * @internal
	 */
	public function renderLoader(): void
	{
		if (!$this->useSession) {
			throw new \LogicException('Start session before Tracy is enabled.');
		}

		$contentId = $this->contentId = $this->contentId ?: Helpers::createId();
		$nonce = Helpers::getNonce();
		$async = true;
		require __DIR__ . '/assets/loader.phtml';
	}


	/**
	 * Renders debug bar.
	 */
	public function render(): void
	{
		$useSession = $this->useSession && session_status() === PHP_SESSION_ACTIVE;
		$redirectQueue = &$_SESSION['_tracy']['redirect'];

		foreach (['bar', 'redirect', 'bluescreen'] as $key) {
			$queue = &$_SESSION['_tracy'][$key];
			$queue = array_slice((array) $queue, -10, null, true);
			$queue = array_filter($queue, function ($item) {
				return isset($item['time']) && $item['time'] > time() - 60;
			});
		}

		if (Helpers::isAjax()) {
			if ($useSession) {
				$contentId = $_SERVER['HTTP_X_TRACY_AJAX'];
				$_SESSION['_tracy']['bar'][$contentId] = ['content' => $this->renderPartial('ajax', '-ajax:' . $contentId), 'time' => time()];
			}
		} elseif (Helpers::isRedirect()) {
			if ($useSession) {
				$redirectQueue[] = ['content' => $this->renderPartial('redirect', '-r' . count($redirectQueue)), 'time' => time()];
			}
		} elseif (Helpers::isHtmlMode()) {
			$content = $this->renderPartial('main');

			foreach (array_reverse((array) $redirectQueue) as $item) {
				$content['bar'] .= $item['content']['bar'];
				$content['panels'] .= $item['content']['panels'];
			}

			$redirectQueue = null;

			$content = '<div id=tracy-debug-bar>' . $content['bar'] . '</div>' . $content['panels'];

			if ($this->contentId) {
				$_SESSION['_tracy']['bar'][$this->contentId] = ['content' => $content, 'time' => time()];
			} else {
				$contentId = Helpers::createId();
				$nonce = Helpers::getNonce();
				$async = false;
				require __DIR__ . '/assets/loader.phtml';
			}
		}
	}


	private function renderPartial(string $type, string $suffix = ''): array
	{
		$panels = $this->renderPanels($suffix);

		return [
			'bar' => Helpers::capture(function () use ($type, $panels) {
				require __DIR__ . '/assets/bar.phtml';
			}),
			'panels' => Helpers::capture(function () use ($type, $panels) {
				require __DIR__ . '/assets/panels.phtml';
			}),
		];
	}


	private function renderPanels(string $suffix = ''): array
	{
		set_error_handler(function (int $severity, string $message, string $file, int $line) {
			if (error_reporting() & $severity) {
				throw new \ErrorException($message, 0, $severity, $file, $line);
			}
		});

		$obLevel = ob_get_level();
		$panels = [];

		foreach ($this->panels as $id => $panel) {
			$idHtml = preg_replace('#[^a-z0-9]+#i', '-', $id) . $suffix;
			try {
				$tab = (string) $panel->getTab();
				$panelHtml = $tab ? $panel->getPanel() : null;

			} catch (\Throwable $e) {
				while (ob_get_level() > $obLevel) { // restore ob-level if broken
					ob_end_clean();
				}

				$idHtml = "error-$idHtml";
				$tab = "Error in $id";
				$panelHtml = "<h1>Error: $id</h1><div class='tracy-inner'>" . nl2br(Helpers::escapeHtml($e)) . '</div>';
				unset($e);
			}

			$panels[] = (object) ['id' => $idHtml, 'tab' => $tab, 'panel' => $panelHtml];
		}

		restore_error_handler();
		return $panels;
	}


	/**
	 * Renders debug bar assets.
	 * @internal
	 */
	public function dispatchAssets(): bool
	{
		$asset = $_GET['_tracy_bar'] ?? null;
		if ($asset === 'js') {
			header('Content-Type: application/javascript; charset=UTF-8');
			header('Cache-Control: max-age=864000');
			header_remove('Pragma');
			header_remove('Set-Cookie');
			$this->renderAssets();
			return true;
		}

		$this->useSession = session_status() === PHP_SESSION_ACTIVE;
		if (!$this->useSession) {
			return false;
		}

		if (is_string($asset) && preg_match('#^content(-ajax)?\.(\w+)$#', $asset, $m)) {
			[, $ajax, $requestId] = $m;
			header('Content-Type: application/javascript; charset=UTF-8');
			header('Cache-Control: max-age=60');
			header_remove('Set-Cookie');
			if (!$ajax) {
				$this->renderAssets();
			}

			$session = &$_SESSION['_tracy']['bar'][$requestId];
			if ($session) {
				$method = $ajax ? 'loadAjax' : 'init';
				echo "Tracy.Debug.$method(", json_encode($session['content'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE), ');';
				$session = null;
			}

			$session = &$_SESSION['_tracy']['bluescreen'][$requestId];
			if ($session) {
				echo 'Tracy.BlueScreen.loadAjax(', json_encode($session['content'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE), ');';
				$session = null;
			}

			return true;
		}

		if (Helpers::isAjax()) {
			header('X-Tracy-Ajax: 1'); // session must be already locked
		}

		return false;
	}


	private function renderAssets(): void
	{
		$css = array_map('file_get_contents', array_merge([
			__DIR__ . '/assets/bar.css',
			__DIR__ . '/../assets/toggle.css',
			__DIR__ . '/../assets/table-sort.css',
			__DIR__ . '/../Dumper/assets/dumper-light.css',
			__DIR__ . '/../Dumper/assets/dumper-dark.css',
			__DIR__ . '/../BlueScreen/assets/bluescreen.css',
		], Debugger::$customCssFiles));

		echo "'use strict';
(function(){
	var el = document.createElement('style');
	el.setAttribute('nonce', document.currentScript.getAttribute('nonce') || document.currentScript.nonce);
	el.className='tracy-debug';
	el.textContent=" . json_encode(Helpers::minifyCss(implode('', $css))) . ";
	document.head.appendChild(el);})
();\n";

		array_map(function ($file) { echo '(function() {', file_get_contents($file), '})();'; }, [
			__DIR__ . '/assets/bar.js',
			__DIR__ . '/../assets/toggle.js',
			__DIR__ . '/../assets/table-sort.js',
			__DIR__ . '/../Dumper/assets/dumper.js',
			__DIR__ . '/../BlueScreen/assets/bluescreen.js',
		]);
		array_map('readfile', Debugger::$customJsFiles);
	}
}
