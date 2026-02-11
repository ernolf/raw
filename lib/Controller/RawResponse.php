<?php
namespace OCA\Raw\Controller;

use OCA\Raw\Service\CspManager;
use OCP\AppFramework\Http\NotFoundResponse;
use OCP\Files\NotFoundException;

trait RawResponse {

	/**
	 * Determine the MIME type for a file node.
	 *
	 * Uses the node-provided MIME type by default. If the filename has no extension,
	 * detects the MIME type from the file content via finfo(FILEINFO_MIME_TYPE).
	 */
	protected function getMimeType($fileNode, ?string $content = null) {
		$filename = $fileNode->getName();
		$mimetype = $fileNode->getMimeType();

		$ext = pathinfo($filename, PATHINFO_EXTENSION);
		// If there is no extension OR Nextcloud reports a generic binary MIME type,
		// detect MIME type from the file content.
		if (empty($ext) || strtolower((string)$mimetype) === 'application/octet-stream') {
			$finfo = new \finfo(FILEINFO_MIME_TYPE);
			$buf = $content ?? $fileNode->getContent();
			$mimetype = $finfo->buffer($buf) ?: 'application/octet-stream';
		}

		return $mimetype;
	}

	/**
	 * Return a raw HTTP response for the given file node.
	 *
	 * Behavior implemented by this method:
	 *
	 * - If the node is a directory, attempts to serve "index.html".
	 * - Reads the node content into memory.
	 * - Computes an ETag:
	 *   - prefers mtime+size when available
	 *   - otherwise falls back to md5(content)
	 * - Sends caching headers (ETag, Cache-Control, Content-Length, and Last-Modified when mtime is available).
	 * - Handles conditional requests:
	 *   - evaluates If-None-Match first (supports "*", lists, and weak validators "W/")
	 *   - if no ETag match, evaluates If-Modified-Since (HTTP-date or a numeric unix timestamp)
	 *   - responds with 304 (no body) when validators match
	 * - Sets Content-Security-Policy using the controller-provided CspManager.
	 * - Best-effort attempt to prevent Set-Cookie from being emitted by PHP
	 *   (closes an active session, disables session cookies for the remainder of the request,
	 *   and removes already queued Set-Cookie headers).
	 * - For HEAD requests, sends headers only and exits.
	 */
	protected function returnRawResponse($fileNode) {
		if ($fileNode->getType() === 'dir') {
			// If the requested path is a folder, try to return its index.html.
			try {
				$fileNode = $fileNode->get('index.html');
			} catch (NotFoundException $e) {
				return new NotFoundResponse();
			}
		}

		// Load content and determine MIME type.
		$content = $fileNode->getContent();
		$mimetype = $this->getMimeType($fileNode, $content);

		// --- Build ETag: prefer mtime+size to avoid expensive hashing ---
		$etag = null;
		$mtime = null;
		$size = null;
		try {
			// Try common metadata accessors on the node; not all node types expose these.
			if (is_callable([$fileNode, 'getMTime'])) {
				$mtime = $fileNode->getMTime();
			} elseif (is_callable([$fileNode, 'getMtime'])) {
				$mtime = $fileNode->getMtime();
			}

			if (is_callable([$fileNode, 'getSize'])) {
				$size = $fileNode->getSize();
			}

			// If both mtime and size are available, use them to form a fast ETag.
			if ($mtime !== null && $size !== null) {
				$etag = '"' . dechex((int)$mtime) . '-' . dechex((int)$size) . '"';
			}
		} catch (\Throwable $e) {
			// Ignore metadata failures and fall back to content hash.
		}

		// Fallback: use MD5 of content for a byte-exact ETag.
		if ($etag === null) {
			$etag = '"' . md5($content) . '"';
		}

		// --- Prepare Last-Modified header (if mtime available) ---
		$lastModifiedHeader = null;
		if ($mtime !== null) {
			// Format as HTTP-date in GMT, e.g. "Fri, 29 Aug 2025 20:53:02 GMT"
			$lastModifiedHeader = gmdate('D, d M Y H:i:s', (int)$mtime) . ' GMT';
		}

		// --- Check If-None-Match header from the client first (ETag has priority) ---
		$ifNoneMatch = $_SERVER['HTTP_IF_NONE_MATCH'] ?? '';
		$matchedEtag = false;

		if ($ifNoneMatch !== '') {
			$clientEtags = preg_split('/\s*,\s*/', $ifNoneMatch);
			foreach ($clientEtags as $c) {
				$c = trim($c);
				if ($c === '*') {
					$matchedEtag = true;
					break;
				}
				$clean = preg_replace('/^\s*(W\/)?\s*"(.*)"\s*$/i', '$2', $c);
				if ($clean === $c) {
					$clean = trim($c, " \t\n\r\0\x0B\"");
				}
				$serverEtagValue = trim($etag, '"');
				if ($clean === $serverEtagValue) {
					$matchedEtag = true;
					break;
				}
			}
		}

		// --- If no ETag match, evaluate If-Modified-Since (only if Last-Modified is available) ---
		$matchedIMS = false;
		if (!$matchedEtag && $lastModifiedHeader !== null) {
			$ifModifiedSince = $_SERVER['HTTP_IF_MODIFIED_SINCE'] ?? '';
			if ($ifModifiedSince !== '') {
				// Remove optional surrounding quotes and whitespace
				$clean = trim($ifModifiedSince, " \t\n\r\0\x0B\"");

				// If the header is purely numeric, treat it as a unix timestamp (seconds).
				if (preg_match('/^\d+$/', $clean)) {
					$clientTime = (int)$clean;
				} else {
					// Fallback to strtotime for HTTP-date parsing.
					$clientTime = strtotime($clean);
				}

				// Compare only if we successfully parsed a time value.
				if ($clientTime !== false && $clientTime >= (int)$mtime) {
					$matchedIMS = true;
				}
			}
		}

		// Expect the controller to provide a CspManager instance.
		// If not present, throw a RuntimeException so the problem is visible and fixed in tests.
		if (!isset($this->cspManager) || !($this->cspManager instanceof CspManager)) {
			// throw explicit exception — do not silently fall back
			throw new \RuntimeException('CspManager missing: controllers must create and assign $this->cspManager using IConfig.');
		}

		// Use the provided manager
		$csp = $this->cspManager->determineCspForRequest($fileNode);

		// Ensure not empty (defensive but not silent fallback)
		// If empty, throw so admin/developer notices misconfiguration
		if (trim($csp) === '') {
			throw new \RuntimeException('CspManager returned empty CSP for request; check raw_csp configuration.');
		}
		header('Content-Security-Policy: ' . $csp);


		/*
		 * Before finalizing response — remove Set-Cookie headers set earlier by PHP/Nextcloud.
		 * This will prevent PHP from sending any Set-Cookie headers that were already queued.
		 * Note: it cannot stop a reverse-proxy or webserver module from adding Set-Cookie afterwards.
		 */
		if (session_status() === PHP_SESSION_ACTIVE) {
			// close session so PHP won't try to re-send the session cookie later
			session_write_close();
			// disable session cookies for the remainder of the request
			ini_set('session.use_cookies', 0);
		}
		header_remove('Set-Cookie');

		// --- If either ETag matched or If-Modified-Since matched -> 304 Not Modified ---
		if ($matchedEtag || $matchedIMS) {
			// Send ETag and Last-Modified if available
			header('ETag: ' . $etag);
			if ($lastModifiedHeader !== null) {
				header('Last-Modified: ' . $lastModifiedHeader);
			}
			header('Cache-Control: public, max-age=0');
			http_response_code(304);
			exit;
		}

		// --- Otherwise send normal headers and body. Include Content-Length, ETag and Last-Modified ---
		header("Content-Type: {$mimetype}");
		header('Content-Length: ' . ($size !== null ? (int)$size : strlen($content)));
		header('ETag: ' . $etag);
		if ($lastModifiedHeader !== null) {
			header('Last-Modified: ' . $lastModifiedHeader);
		}
		header('Cache-Control: public, max-age=0');

		// For HEAD requests, send headers only and exit.
		$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
		if (strtoupper($method) === 'HEAD') {
			exit;
		}

		// Output the body for GET requests.
		echo $content;
		exit;
	}
}
