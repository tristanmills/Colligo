<?php

namespace Colligo;

class Colligo {

	/**
	 * @var object
	 */
	private $DOMDocument;

	/**
	 * @var object
	 */
	private $DOMXpath;

	/**
	 * @var array
	 */
	private $settings;

	/**
	 * @var string
	 */
	private $url;

	/**
	 * @var object
	 */
	private $context;

	/**
	 * __construct()
	 *
	 * @param array $settings Description of settings.
	 *
	 */
	function __construct($settings = array()) {

		$this->settings = array_merge(array(
			'location'        => './files',
			'jsDirectory'     => 'js',
			'cssDirectory'    => 'css',
			'fontsDirectory'  => 'fonts',
			'flashDirectory'  => 'flash',
			'imgDirectory'    => 'img',
			'htmlFilename'    => 'index.html',
			'userAgent'       => 'Mozilla/5.0 (Windows; U; MSIE 7.0; Windows NT 6.0;)',
			'excludedDomains' => array(
				'fonts.googleapis.com',
				'maps.googleapis.com',
				'fast.fonts.com',
				'use.typekit.net'
			)
		), $settings);

		$this->settings['location'] = rtrim($this->settings['location'], '/');

	}

	/**
	 * Download the given URL and any necessary resources.
	 *
	 * @param string $url The URL to run.
	 * @param string $username The user name needed for the URL.
	 * @param string $password The password needed for the URL.
	 */
	public function download($url, $username = '', $password = '') {

		$this->url = $url;

		$this->context = stream_context_create(array(
			'http' => array(
				'user_agent' => $this->settings['userAgent'],
				'header'  => 'Authorization: Basic ' . base64_encode($username . ':' . $password)
			),
			'ssl' => array(
				'verify_peer' => false,
				'verify_peer_name' => false
			)
		));

		$htmlFilePath = $this->settings['location'] . '/' . $this->settings['htmlFilename'];

		$htmlContent = file_get_contents($this->url, false, $this->context);

		$htmlContent = mb_convert_encoding($htmlContent, 'UTF-8', mb_detect_encoding($htmlContent));

		$htmlContent = mb_convert_encoding($htmlContent, 'html-entities', 'UTF-8');

		$this->DOMDocument = new \DOMDocument();

		@$this->DOMDocument->loadHTML($htmlContent);

		$this->DOMXpath = new \DOMXpath($this->DOMDocument);

		$this->prepareDirectories();

		$this->processBaseTag();

		$this->processComments();

		$this->processFavicon();

		$this->processImages();

		$this->processFlash();

		$this->processInlineCss();

		$this->processCss();

		$this->processJs();

		$this->convertStyleBlocks();

		$this->convertScriptBlocks();

		$this->DOMDocument->saveHTMLFile($htmlFilePath);

	}

	/**
	 * Prepare the directory structure.
	 *
	 */
	private function prepareDirectories() {

		if (file_exists($this->settings['location'])) {

			$this->rmtree($this->settings['location']);

		}

		mkdir($this->settings['location']);

		mkdir($this->settings['location'] . '/' . $this->settings['jsDirectory']);

		mkdir($this->settings['location'] . '/' . $this->settings['cssDirectory']);

		mkdir($this->settings['location'] . '/' . $this->settings['fontsDirectory']);

		mkdir($this->settings['location'] . '/' . $this->settings['flashDirectory']);

		mkdir($this->settings['location'] . '/' . $this->settings['imgDirectory']);

	}

	/**
	 * Process the <base> tag.
	 *
	 */
	private function processBaseTag() {

		$baseNode = $this->DOMXpath->query('//base')->item(0);

		if ($baseNode) {

			$baseNode->setAttribute('href', '');

		}

	}

	/**
	 * Process the comments.
	 *
	 */
	private function processComments() {

		$commentNodes = $this->DOMXpath->query('//comment()');

		foreach ($commentNodes as $commentNode) {

			$oldCommentContent = $commentNode->nodeValue;

			$newCommentContent = $this->processCommentContent($oldCommentContent, './');

			$commentNode->nodeValue = $newCommentContent;

		}

	}

	/**
	 * Find URLs in the comments, download the files, then update the comments.
	 *
	 * @param string $commentContent The content of a comment.
	 * @param string $pathPrefix The relative path before the subdirectory.
	 * @return string Returns new comment content.
	 */
	private function processCommentContent($commentContent, $pathPrefix) {

		$patterns = array();

		$replacements = array();

		preg_match_all('~<script\s+.*?src\=[\'\"](.*?)[\'\"]~', $commentContent, $jsMatches);

		preg_match_all('~<link\s+.*?rel\=[\'\"](?i)stylesheet[\'\"].*?href\=[\'\"](.*?)[\'\"]~', $commentContent, $cssMatches1);

		preg_match_all('~<link\s+.*?href\=[\'\"](.*?)[\'\"].*?rel\=[\'\"](?i)stylesheet[\'\"]~', $commentContent, $cssMatches2);

		preg_match_all('~<link\s+.*?rel\=[\'\"].*?(?i)icon.*?[\'\"].*?href\=[\'\"](.*?)[\'\"]~', $commentContent, $faviconMatches1);

		preg_match_all('~<link\s+.*?href\=[\'\"](.*?)[\'\"].*?rel\=[\'\"].*?(?i)icon.*?[\'\"]~', $commentContent, $faviconMatches2);

		preg_match_all('~<img\s+.*?src\=[\'\"](.*?)[\'\"]~', $commentContent, $imgMatches);

		$cssMatches = array(array_merge($cssMatches1[0], $cssMatches2[0]), array_merge($cssMatches1[1], $cssMatches2[1]));

		$faviconMatches = array(array_merge($faviconMatches1[0], $faviconMatches2[0]), array_merge($faviconMatches1[1], $faviconMatches2[1]));

		if (isset($jsMatches[1])) {

			foreach ($jsMatches[1] as $oldJsSrc) {

				if ($this->validResource($oldJsSrc)) {

					$normalizedResourceUrl = $this->normalizeResourceUrl($this->url, $oldJsSrc);

					$oldJsContent = @file_get_contents($normalizedResourceUrl, false, $this->context);

					if ($oldJsContent !== false) {

						$newJsFilename = $this->fileEncode(basename($oldJsSrc));

						$newJsSrc = $pathPrefix . $this->settings['jsDirectory'] . '/' . $newJsFilename;

						$newJsPath = $this->settings['location'] . '/' . $this->settings['jsDirectory'] . '/' . $newJsFilename;

						$newJsContent = $this->processJsContent($oldJsContent, '../', $normalizedResourceUrl);

						file_put_contents($newJsPath, $newJsContent);

						$patterns[] = $oldJsSrc;

						$replacements[] = $newJsSrc;

					}

				}

			}

		}

		if (isset($cssMatches[1])) {

			foreach ($cssMatches[1] as $oldCssHref) {

				if ($this->validResource($oldCssHref)) {

					$normalizedResourceUrl = $this->normalizeResourceUrl($this->url, $oldCssHref);

					$oldCssContent = @file_get_contents($normalizedResourceUrl, false, $this->context);

					if ($oldCssContent !== false) {

						$newCssFilename = $this->fileEncode(basename($oldCssHref));

						$newCssHref = $pathPrefix . $this->settings['cssDirectory'] . '/' . $newCssFilename;

						$newCssPath = $this->settings['location'] . '/' . $this->settings['cssDirectory'] . '/' . $newCssFilename;

						$newCssContent = $this->processCssContent($oldCssContent, '../', $normalizedResourceUrl);

						file_put_contents($newCssPath, $newCssContent);

						$patterns[] = $oldCssHref;

						$replacements[] = $newCssHref;

					}

				}

			}

		}

		if (isset($faviconMatches[1])) {

			foreach ($faviconMatches[1] as $oldFaviconHref) {

				if ($this->validResource($oldFaviconHref)) {

					$normalizedResourceUrl = $this->normalizeResourceUrl($this->url, $oldFaviconHref);

					$oldFaviconContent = @file_get_contents($normalizedResourceUrl, false, $this->context);

					if ($oldFaviconContent !== false) {

						$newFaviconFilename = $this->fileEncode(basename($oldFaviconHref));

						$newFaviconHref = $pathPrefix . $this->settings['imgDirectory'] . '/' . $newFaviconFilename;

						$newFaviconPath = $this->settings['location'] . '/' . $this->settings['imgDirectory'] . '/' . $newFaviconFilename;

						$newFaviconContent = $oldCssContent;

						file_put_contents($newFaviconPath, $newFaviconContent);

						$patterns[] = $oldFaviconHref;

						$replacements[] = $newFaviconHref;

					}

				}

			}

		}

		if (isset($imgMatches[1])) {

			foreach ($imgMatches[1] as $oldImgSrc) {

				if ($this->validResource($oldImgSrc)) {

					$normalizedResourceUrl = $this->normalizeResourceUrl($this->url, $oldImgSrc);

					$oldImgContent = @file_get_contents($normalizedResourceUrl, false, $this->context);

					if ($oldImgContent !== false) {

						$newImgFilename = $this->fileEncode(basename($oldImgSrc));

						$newImgSrc = $pathPrefix . $this->settings['imgDirectory'] . '/' . $newImgFilename;

						$newImgPath = $this->settings['location'] . '/' . $this->settings['imgDirectory'] . '/' . $newImgFilename;

						$newImgContent = $oldImgContent;

						file_put_contents($newImgPath, $newImgContent);

						$patterns[] = $oldImgSrc;

						$replacements[] = $newImgSrc;

					}

				}

			}

		}

		$newCommentContent = str_replace($patterns, $replacements, $commentContent);

		return $newCommentContent;

	}

	/**
	 * Process the favicon.
	 *
	 */
	private function processFavicon() {

		// For XPath 2.0
		// $faviconNodes = $this->DOMXpath->query('//link[contains(lower-case(@rel), "icon")]');
		$faviconNodes = $this->DOMXpath->query('//link[contains(@rel, "icon")] | //link[contains(@rel, "Icon")] | //link[contains(@rel, "ICON")]');

		foreach ($faviconNodes as $faviconNode) {

			$oldFaviconHref = $faviconNode->getAttribute('href');

			if ($this->validResource($oldFaviconHref)) {

				$normalizedResourceUrl = $this->normalizeResourceUrl($this->url, $oldFaviconHref);

				$oldFaviconContent = @file_get_contents($normalizedResourceUrl, false, $this->context);

				if ($oldFaviconContent !== false) {

					$newFaviconFilename = $this->fileEncode(basename($oldFaviconHref));

					$newFaviconHref = './' . $this->settings['imgDirectory'] . '/' . $newFaviconFilename;

					$newFaviconPath = $this->settings['location'] . '/' . $this->settings['imgDirectory'] . '/' . $newFaviconFilename;

					$newFaviconContent = $oldFaviconContent;

					file_put_contents($newFaviconPath, $newFaviconContent);

					$faviconNode->setAttribute('href', $newFaviconHref);

				}

			}

		}

		$normalizedFaviconUrl = $this->normalizeResourceUrl($this->url, '/favicon.ico');

		$realFaviconContent = @file_get_contents($normalizedFaviconUrl, false, $this->context);

		if ($realFaviconContent !== false) {

			$realFaviconPath = $this->settings['location'] . '/favicon.ico';

			file_put_contents($realFaviconPath, $realFaviconContent);

		}

	}

	/**
	 * Process the images.
	 *
	 */
	private function processImages() {

		// For XPath 2.0
		// $imageNodes = $this->DOMXpath->query('//img | //input[lower-case(@type)="image"]');
		$imageNodes = $this->DOMXpath->query('//img | //input[@type="image"] | //input[@type="Image"] | //input[@type="IMAGE"]');

		foreach ($imageNodes as $imageNode) {

			$oldImgSrc = $imageNode->getAttribute('src');

			if ($this->validResource($oldImgSrc)) {

				$normalizedResourceUrl = $this->normalizeResourceUrl($this->url, $oldImgSrc);

				$oldImgContent = @file_get_contents($normalizedResourceUrl, false, $this->context);

				if ($oldImgContent !== false) {

					$newImageFilename = $this->fileEncode(basename($oldImgSrc));

					$newImgSrc = './' . $this->settings['imgDirectory'] . '/' . $newImageFilename;

					$newImgPath = $this->settings['location'] . '/' . $this->settings['imgDirectory'] . '/' . $newImageFilename;

					$newImgContent = $oldImgContent;

					file_put_contents($newImgPath, $newImgContent);

					$imageNode->setAttribute('src', $newImgSrc);

				}

			}

		}

	}

	/**
	 * Process the Flash objects.
	 *
	 */
	private function processFlash() {

		$flashNodes = $this->DOMXpath->query('//object[@data]');

		foreach ($flashNodes as $flashNode) {

			$oldFlashData = $flashNode->getAttribute('data');

			if ($this->validResource($oldFlashData)) {

				$normalizedResourceUrl = $this->normalizeResourceUrl($this->url, $oldFlashData);

				$oldFlashContent = @file_get_contents($normalizedResourceUrl, false, $this->context);

				if ($oldFlashContent !== false) {

					$newFlashFilename = $this->fileEncode(basename($oldFlashData));

					$newFlashData = './' . $this->settings['flashDirectory'] . '/' . $newFlashFilename;

					$newFlashPath = $this->settings['location'] . '/' . $this->settings['flashDirectory'] . '/' . $newFlashFilename;

					$newFlashContent = $oldFlashContent;

					file_put_contents($newFlashPath, $newFlashContent);

					$flashNode->setAttribute('data', $newFlashData);

				}

			}

		}

	}

	/**
	 * Process the in-line CSS.
	 *
	 */
	private function processInlineCss() {

		$inlineCssNodes = $this->DOMXpath->query('//*[@style]');

		foreach ($inlineCssNodes as $inlineCssNode) {

			$oldInlineCss = $inlineCssNode->getAttribute('style');

			$newInlineCss = $this->processCssContent($oldInlineCss, './', $this->url);

			$inlineCssNode->setAttribute('style', $newInlineCss);

		}

	}

	/**
	 * Process the CSS.
	 *
	 */
	private function processCss() {

		// For XPath 2.0
		// $cssNodes = $this->DOMXpath->query('//link[lower-case(@rel)="stylesheet"]');
		$cssNodes = $this->DOMXpath->query('//link[@rel="stylesheet"] | //link[@rel="Stylesheet"] | //link[@rel="STYLESHEET"] | //link[@rel="StyleSheet"]');
		$headNode = $this->DOMXpath->query('//head')->item(0);

		foreach ($cssNodes as $cssNode) {

			$oldCssHref = $cssNode->getAttribute('href');

			if ($this->validResource($oldCssHref)) {

				$normalizedResourceUrl = $this->normalizeResourceUrl($this->url, $oldCssHref);

				$oldCssContent = @file_get_contents($normalizedResourceUrl, false, $this->context);

				if ($oldCssContent !== false) {

					$newCssFilename = $this->fileEncode(basename($oldCssHref));

					$newCssHref = './' . $this->settings['cssDirectory'] . '/' . $newCssFilename;

					$newCssPath = $this->settings['location'] . '/' . $this->settings['cssDirectory'] . '/' . $newCssFilename;

					$newCssContent = $this->processCssContent($oldCssContent, '../', $normalizedResourceUrl);

					file_put_contents($newCssPath, $newCssContent);

					$cssNode->setAttribute('href', $newCssHref);

					$headNode->appendChild($cssNode);

				}

			}

		}

	}

	/**
	 * Convert CSS content and download any necessary items.
	 *
	 * @param string $cssContent The CSS that needs processed.
	 * @param string $pathPrefix The relative path before the subdirectory.
	 * @param string $cssUrl The URL the content came from.
	 * @return string processed CSS.
	 */
	private function processCssContent($cssContent, $pathPrefix, $cssUrl) {

		$patterns = array('~<!--~', '~-->~', '~//-->~');

		$replacements = array('', '', '');

		preg_match_all('~@import url\(\s*[\'\"]?(.*?)[\'\"]?\s*\)~', $cssContent, $importMatches1);

		preg_match_all('~@import [\'\"](.*?)[\'\"];~', $cssContent, $importMatches2);

		preg_match_all('~(?<!@import )url\(\s*[\'\"]?(.*?)[\'\"]?\s*\)~', $cssContent, $resourceMatches);

		if (isset($importMatches1[1])) {

			foreach ($importMatches1[1] as $importUrl) {

				if ($this->validResource($importUrl)) {

					$normalizedResourceUrl = $this->normalizeResourceUrl($cssUrl, $importUrl);

					$oldCssContent = @file_get_contents($normalizedResourceUrl, false, $this->context);

					if ($oldCssContent !== false) {

						$newCssFilename = $this->fileEncode(basename($importUrl));

						$newImportUrl = './' . $newCssFilename;

						$newCssPath = $this->settings['location'] . '/' . $this->settings['cssDirectory'] . '/' . $newCssFilename;

						$newCssContent = $this->processCssContent($oldCssContent, '../', $normalizedResourceUrl);

						file_put_contents($newCssPath, $newCssContent);

						$patterns[] = '~@import url\(\s*[\'\"]?' . preg_quote($importUrl) . '[\'\"]?\s*\)~';

						$replacements[] = '@import url("' .  $newImportUrl . '")';

					}

				}

			}

		}

		if (isset($importMatches2[1])) {

			foreach ($importMatches2[1] as $importUrl) {

				if ($this->validResource($importUrl)) {

					$normalizedResourceUrl = $this->normalizeResourceUrl($cssUrl, $importUrl);

					$oldCssContent = @file_get_contents($normalizedResourceUrl, false, $this->context);

					if ($oldCssContent !== false) {

						$newCssFilename = $this->fileEncode(basename($importUrl));

						$newImportUrl = './' . $newCssFilename;

						$newCssPath = $this->settings['location'] . '/' . $this->settings['cssDirectory'] . '/' . $newCssFilename;

						$newCssContent = $this->processCssContent($oldCssContent, '../', $normalizedResourceUrl);

						file_put_contents($newCssPath, $newCssContent);

						$patterns[] = '~@import [\'\"]' . preg_quote($importUrl) . '[\'\"]~';

						$replacements[] = '@import url("' . $newImportUrl . '")';

					}

				}

			}

		}

		if (isset($resourceMatches[1])) {

			foreach ($resourceMatches[1] as $resourceUrl) {

				$resourceUrl = trim($resourceUrl);

				if ($this->validResource($resourceUrl)) {

					$filePath = parse_url(urldecode($resourceUrl), PHP_URL_PATH);

					$fileExtension = pathinfo($filePath, PATHINFO_EXTENSION);

					if ($fileExtension === 'eot' || $fileExtension === 'otf' || $fileExtension === 'svg'|| $fileExtension === 'ttf' || $fileExtension === 'woff') {

						// TODO: Change font detection to new regex.
						$subDirectory = $this->settings['fontsDirectory'];

					} elseif ($fileExtension === 'htc' || $fileExtension === 'js' || $fileExtension === 'xml' || $fileExtension === 'php') {

						// TODO: Process files?
						$subDirectory = $this->settings['jsDirectory'];

					} else {

						$subDirectory = $this->settings['imgDirectory'];

					}

					$normalizedResourceUrl = $this->normalizeResourceUrl($cssUrl, $resourceUrl);

					$oldRsourceContent = @file_get_contents($normalizedResourceUrl, false, $this->context);

					if ($oldRsourceContent !== false) {

						$newResourceFilename = $this->fileEncode(basename($resourceUrl));

						$newResourceUrl = $pathPrefix . $subDirectory . '/' . $newResourceFilename;

						$newResourcePath = $this->settings['location'] . '/' . $subDirectory . '/' . $newResourceFilename;

						$newRsourceContent = $oldRsourceContent;

						file_put_contents($newResourcePath, $newRsourceContent);

						$patterns[] = '~url\(\s*[\'\"]?' . preg_quote($resourceUrl) . '[\'\"]?\s*\)~';

						$replacements[] = 'url("' . $newResourceUrl . '")';

					}

				}

			}

		}

		$newCssContent = trim(preg_replace($patterns, $replacements, $cssContent));

		$newCssContent = mb_convert_encoding($newCssContent, 'UTF-8', mb_detect_encoding($newCssContent));

		return $newCssContent;

	}

	/**
	 * Process the JavaScript.
	 *
	 */
	private function processJs() {

		$jsNodes = $this->DOMXpath->query('//script[@src]');

		foreach ($jsNodes as $jsNode) {

			$oldJsSrc = $jsNode->getAttribute('src');

			if ($this->validResource($oldJsSrc)) {

				$normalizedResourceUrl = $this->normalizeResourceUrl($this->url, $oldJsSrc);

				$oldJsContent = @file_get_contents($normalizedResourceUrl, false, $this->context);

				if ($oldJsContent !== false) {

					$newJsFilename = $this->fileEncode(basename($oldJsSrc));

					$newJsSrc = './' . $this->settings['jsDirectory'] . '/' . $newJsFilename;

					$newJsPath = $this->settings['location'] . '/' . $this->settings['jsDirectory'] . '/' . $newJsFilename;

					$newJsContent = $this->processJsContent($oldJsContent, '../', $normalizedResourceUrl);

					file_put_contents($newJsPath, $newJsContent);

					$jsNode->setAttribute('src', $newJsSrc);

				}

			}

		}

	}

	/**
	 * Convert JavaScript content and download any necessary items.
	 *
	 * @param string $jsContent The Javascript that needs processed.
	 * @param string $pathPrefix The relative path before the subdirectory.
	 * @param string $jsUrl The URL the content came from.
	 * @return string processed JavaScript.
	 */
	private function processJsContent($jsContent, $pathPrefix, $jsUrl) {

		$patterns = array('<!--', '-->', '//-->', '//<![CDATA[', '//]]>');

		$replacements = array('', '', '', '', '');

		preg_match_all('~<script\s+.*?src\=[\'\"](.*?)[\'\"]~', $jsContent, $jsMatches);

		preg_match_all('~<link\s+.*?rel\=[\'\"](?i)stylesheet[\'\"].*?href\=[\'\"](.*?)[\'\"]~', $jsContent, $cssMatches1);

		preg_match_all('~<link\s+.*?href\=[\'\"](.*?)[\'\"].*?rel\=[\'\"](?i)stylesheet[\'\"]~', $jsContent, $cssMatches2);

		preg_match_all('~<link\s+.*?rel\=[\'\"].*?(?i)icon.*?[\'\"].*?href\=[\'\"](.*?)[\'\"]~', $jsContent, $faviconMatches1);

		preg_match_all('~<link\s+.*?href\=[\'\"](.*?)[\'\"].*?rel\=[\'\"].*?(?i)icon.*?[\'\"]~', $jsContent, $faviconMatches2);

		preg_match_all('~<img\s+.*?src\=[\'\"](.*?)[\'\"]~', $jsContent, $imgMatches);

		preg_match_all('~src[\'\"]?:\s+?[\'\"](.*?)[\'\"]~', $jsContent, $specialMatches1);

		$cssMatches = array(array_merge($cssMatches1[0], $cssMatches2[0]), array_merge($cssMatches1[1], $cssMatches2[1]));

		$faviconMatches = array(array_merge($faviconMatches1[0], $faviconMatches2[0]), array_merge($faviconMatches1[1], $faviconMatches2[1]));

		if (isset($jsMatches[1])) {

			foreach ($jsMatches[1] as $oldJsSrc) {

				if ($this->validResource($oldJsSrc)) {

					$normalizedResourceUrl = $this->normalizeResourceUrl($jsUrl, $oldJsSrc);

					$oldJsContent = @file_get_contents($normalizedResourceUrl, false, $this->context);

					if ($oldJsContent !== false) {

						$newJsFilename = $this->fileEncode(basename($oldJsSrc));

						$newJsSrc = $pathPrefix . $this->settings['jsDirectory'] . '/' . $newJsFilename;

						$newJsPath = $this->settings['location'] . '/' . $this->settings['jsDirectory'] . '/' . $newJsFilename;

						$newJsContent = $this->processJsContent($oldJsContent, '../', $normalizedResourceUrl);

						file_put_contents($newJsPath, $newJsContent);

						$patterns[] = $oldJsSrc;

						$replacements[] = $newJsSrc;

					}

				}

			}

		}

		if (isset($cssMatches[1])) {

			foreach ($cssMatches[1] as $oldCssHref) {

				if ($this->validResource($oldCssHref)) {

					$normalizedResourceUrl = $this->normalizeResourceUrl($jsUrl, $oldCssHref);

					$oldCssContent = @file_get_contents($normalizedResourceUrl, false, $this->context);

					if ($oldCssContent !== false) {

						$newCssFilename = $this->fileEncode(basename($oldCssHref));

						$newCssHref = $pathPrefix . $this->settings['cssDirectory'] . '/' . $newCssFilename;

						$newCssPath = $this->settings['location'] . '/' . $this->settings['cssDirectory'] . '/' . $newCssFilename;

						$newCssContent = $this->processCssContent($oldCssContent, '../', $normalizedResourceUrl);

						file_put_contents($newCssPath, $newCssContent);

						$patterns[] = $oldCssHref;

						$replacements[] = $newCssHref;

					}

				}

			}

		}

		if (isset($faviconMatches[1])) {

			foreach ($faviconMatches[1] as $oldFaviconHref) {

				if ($this->validResource($oldFaviconHref)) {

					$normalizedResourceUrl = $this->normalizeResourceUrl($this->url, $oldFaviconHref);

					$oldFaviconContent = @file_get_contents($normalizedResourceUrl, false, $this->context);

					if ($oldFaviconContent !== false) {

						$newFaviconFilename = $this->fileEncode(basename($oldFaviconHref));

						$newFaviconHref = $pathPrefix . $this->settings['imgDirectory'] . '/' . $newFaviconFilename;

						$newFaviconPath = $this->settings['location'] . '/' . $this->settings['imgDirectory'] . '/' . $newFaviconFilename;

						$newFaviconContent = $oldCssContent;

						file_put_contents($newFaviconPath, $newFaviconContent);

						$patterns[] = $oldFaviconHref;

						$replacements[] = $newFaviconHref;

					}

				}

			}

		}

		if (isset($imgMatches[1])) {

			foreach ($imgMatches[1] as $oldImgSrc) {

				if ($this->validResource($oldImgSrc)) {

					$normalizedResourceUrl = $this->normalizeResourceUrl($jsUrl, $oldImgSrc);

					$oldImgContent = @file_get_contents($normalizedResourceUrl, false, $this->context);

					if ($oldImgContent !== false) {

						$newImgFilename = $this->fileEncode(basename($oldImgSrc));

						$newImgSrc = $pathPrefix . $this->settings['imgDirectory'] . '/' . $newImgFilename;

						$newImgPath = $this->settings['location'] . '/' . $this->settings['imgDirectory'] . '/' . $newImgFilename;

						$newImgContent = $oldImgContent;

						file_put_contents($newImgPath, $newImgContent);

						$patterns[] = $oldImgSrc;

						$replacements[] = $newImgSrc;

					}

				}

			}
		}

		if (isset($specialMatches1[1])) {

			foreach ($specialMatches1[1] as $oldImgSrc) {

				if ($this->validResource($oldImgSrc)) {

					$normalizedResourceUrl = $this->normalizeResourceUrl($jsUrl, $oldImgSrc);

					$oldImgContent = @file_get_contents($normalizedResourceUrl, false, $this->context);

					if ($oldImgContent !== false) {

						$newImgFilename = $this->fileEncode(basename($oldImgSrc));

						$newImgSrc = './' . $this->settings['imgDirectory'] . '/' . $newImgFilename;

						$newImgPath = $this->settings['location'] . '/' . $this->settings['imgDirectory'] . '/' . $newImgFilename;

						$newImgContent = $oldImgContent;

						file_put_contents($newImgPath, $newImgContent);

						$patterns[] = $oldImgSrc;

						$replacements[] = $newImgSrc;

					}

				}

			}
		}

		$newJsContent = trim(str_replace($patterns, $replacements, $jsContent));

		return $newJsContent;

	}

	/**
	 * Convert style blocks into normal CSS files.
	 *
	 */
	private function convertStyleBlocks() {

		$styleBlockNodes = $this->DOMXpath->query('//style');

		foreach ($styleBlockNodes as $index => $styleBlockNode) {

			$cssFilename = 'style-block-' . sprintf('%02d', $index + 1) . '.css';

			$cssHref = './' . $this->settings['cssDirectory'] . '/' . $cssFilename;

			$cssFilePath = $this->settings['location'] . '/' . $this->settings['cssDirectory'] . '/' . $cssFilename;

			$cssContent = $this->processCssContent($styleBlockNode->nodeValue, '../', $this->url);

			file_put_contents($cssFilePath, $cssContent);

			$link = $this->DOMDocument->createElement('link');

			$link->setAttribute('rel', 'stylesheet');

			$link->setAttribute('media', $styleBlockNode->getAttribute('media'));

			$link->setAttribute('href', $cssHref);

			$styleBlockNode->parentNode->insertBefore($link, $styleBlockNode);

			$styleBlockNode->parentNode->removeChild($styleBlockNode);

		}

	}

	/**
	 * Convert script blocks into normal JavaScript files.
	 *
	 */
	private function convertScriptBlocks() {

		$scriptBlockNodes = $this->DOMXpath->query('//script[not(@src)]');

		foreach ($scriptBlockNodes as $index => $scriptBlockNode) {

			$jsFilename = 'script-block-' . sprintf('%02d', $index + 1) . '.js';

			$jsSrc = './' . $this->settings['jsDirectory'] . '/' . $jsFilename;

			$jsFilePath = $this->settings['location'] . '/' . $this->settings['jsDirectory'] . '/' . $jsFilename;

			$jsContent = $this->processJsContent($scriptBlockNode->nodeValue, '../', $this->url);

			file_put_contents($jsFilePath, $jsContent);

			$script = $this->DOMDocument->createElement('script');

			$script->setAttribute('type', 'text/javascript');

			$script->setAttribute('src', $jsSrc);

			$scriptBlockNode->parentNode->insertBefore($script, $scriptBlockNode);

			$scriptBlockNode->parentNode->removeChild($scriptBlockNode);

		}

	}

	/**
	 * Check if the given resource URL is desirable to download.
	 *
	 * @param string $resource The resource to check
	 * @return bool TRUE or FALSE.
	 */
	private function validResource($resourceUrl) {

		if (empty($resourceUrl) === false && stripos($resourceUrl, 'data:') === false) {

			$resourceUrlParts = parse_url($resourceUrl);

			if (isset($resourceUrlParts['host']) && in_array($resourceUrlParts['host'], $this->settings['excludedDomains'])) {

				return false;

			} else {

				return true;

			}

		} else {

			return false;

		}

	}

	/**
	 * Create a single absolute URL from the base URL and the resource URL.
	 *
	 * @param string $baseUrl An absolute URL to a file.
	 * @param string $resourceUrl A URL (absolute, site-relative, or document-relative) to a resource.
	 * @return string An absolute URL to a resource.
	 */
	private function normalizeResourceUrl($baseUrl, $resourceUrl) {

		if (empty($resourceUrl) === true) {

			return '';

		}

		$baseUrlParts = parse_url($baseUrl);

		$resourceUrlParts = parse_url($resourceUrl);

		if (isset($resourceUrlParts['scheme']) && isset($resourceUrlParts['host'])) {

			return $resourceUrl;

		} elseif (isset($baseUrlParts['scheme']) && isset($resourceUrlParts['host'])) {

			return $baseUrlParts['scheme'] . ':' . $resourceUrl;

		}

		$scheme          = isset($baseUrlParts['scheme']) ? $baseUrlParts['scheme'] . '://' : '';
		$host            = isset($baseUrlParts['host']) ? $baseUrlParts['host'] : '';
		$port            = isset($baseUrlParts['port']) ? ':' . $baseUrlParts['port'] : '';
		$user            = isset($baseUrlParts['user']) ? $baseUrlParts['user'] : '';
		$pass            = isset($baseUrlParts['pass']) ? ':' . $baseUrlParts['pass']  : '';
		$pass            = ($user || $pass) ? $pass . '@' : '';
		$baseUrlPath     = isset($baseUrlParts['path']) ? $baseUrlParts['path'] : '/';
		$resourceUrlPath = isset($resourceUrlParts['path']) ? $resourceUrlParts['path'] : '/';
		$query           = isset($resourceUrlParts['query']) ? '?' . $resourceUrlParts['query'] : '';
		$fragment        = isset($resourceUrlParts['fragment']) ? '#' . $resourceUrlParts['fragment'] : '';


		if (strpos($resourceUrlPath, '/') === 0) {

			return $scheme . $user . $pass . $host . $port . $resourceUrlPath . $query . $fragment;

		} elseif ($baseUrlPath[strlen($baseUrlPath) - 1] === '/') {

			return $scheme . $user . $pass . $host . $port . $baseUrlPath . $resourceUrlPath . $query . $fragment;

		} else {

			$position = strrpos($baseUrlPath, basename($baseUrlPath));

			if ($position !== false) {

				$baseUrlPath = substr_replace($baseUrlPath, '', $position, strlen(basename($baseUrlPath)));

			}

			return $scheme . $user . $pass . $host . $port . $baseUrlPath . $resourceUrlPath . $query . $fragment;

		}

	}

	/**
	 * Encodes a filename to be safe on all file systems.
	 *
	 * @param string $filename The string to be encoded.
	 * @return string An encoded string.
	 */
	private function fileEncode($filename) {

		$restricted_list = array('\\', '|', '/', ':', '?', '"', '*', '<', '>');

		$replacement_list = array('_', '_', '_', '_', '_', '_', '_', '_', '_');

		return str_replace($restricted_list, $replacement_list, $filename);

	}

	/**
	 * Deletes filename and any contents. A E_USER_WARNING level error will be generated on failure.
	 *
	 * @param string $filename Path to the file or directory.
	 * @return bool Returns TRUE on success or FALSE on failure.
	 */
	private function rmtree($filename) {

		if (file_exists($filename) === false) {

			trigger_error('rmtree(' . $filename . '): No such file or directory', E_USER_WARNING);

			return false;

		}

		if (is_dir($filename)) {

			$objects = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($filename, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);

			foreach ($objects as $object) {

				if ($object->isDir()) {

					rmdir($object->getPathname());

				} else {

					unlink($object->getPathname());

				}
			}

			return rmdir($filename);

		} elseif (is_file($filename)) {

			return unlink($filename);

		} else {

			trigger_error('rmtree(' . $filename . '): Invalid argument', E_USER_WARNING);

			return false;

		}

	}

}
