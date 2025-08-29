<?php
namespace OCA\Raw\Controller;

use \Exception;

trait RawResponse {
/*
	protected function getMimeType($fileNode) {
		$filename = $fileNode->getName();
		$mimetype = $fileNode->getMimeType();

		// If the MIME type is not determined by the extension, we can try to determine it ourselves
		if (empty(pathinfo($filename, PATHINFO_EXTENSION))) {
			// Example: Determine MIME type based on file content or predefined types
			$content = $fileNode->getContent();
			if (strpos($content, '<?php') === 0) {
				$mimetype = 'text/x-php';
			} elseif (strpos($content, '<!DOCTYPE html>') === 0) {
				$mimetype = 'text/html';
			} elseif (preg_match('/^\s*{/', $content)) { // check for JSON
				$mimetype = 'application/json';
			} else {
				$mimetype = 'text/plain'; // default to plain text
			}
		}

		return $mimetype;
	}
 */

	/**
	 * Determine MIME type for a file node.
	 * If the filename has no extension, use finfo to detect MIME type from content.
	 */
	protected function getMimeType($fileNode) {
		$filename = $fileNode->getName();
		$mimetype = $fileNode->getMimeType();

		// If there is no extension, detect MIME type from the file content.
		if (empty(pathinfo($filename, PATHINFO_EXTENSION))) {
			// Initialize finfo to detect MIME type.
			$finfo = new \finfo(FILEINFO_MIME_TYPE);
			$content = $fileNode->getContent();

			// Detect MIME type based on buffer content; fallback to octet-stream.
			$mimetype = $finfo->buffer($content) ?: 'application/octet-stream';
		}

		return $mimetype;
	}

	/**
	 * Return a raw HTTP response for the given file node.
	 *
	 * - If the node is a directory, attempt to return its index.html.
	 * - Compute an ETag (prefer mtime+size; fallback to md5 of content).
	 * - Honor If-None-Match header (supports '*', multiple values, weak ETags).
	 * - Return 304 Not Modified when appropriate (no body).
	 * - Support HEAD requests: send headers only.
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
		$mimetype = $this->getMimeType($fileNode);

		// --- Build ETag: prefer mtime+size to avoid expensive hashing ---
		$etag = null;
		try {
			$mtime = null;
			$size = null;

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

		// --- Check If-None-Match header from the client ---
		$ifNoneMatch = $_SERVER['HTTP_IF_NONE_MATCH'] ?? '';
		$matched = false;

		if ($ifNoneMatch !== '') {
			// Clients may send multiple ETags separated by commas.
			$clientEtags = preg_split('/\s*,\s*/', $ifNoneMatch);
			foreach ($clientEtags as $c) {
				$c = trim($c);

				// Wildcard '*' matches any current representation.
				if ($c === '*') {
					$matched = true;
					break;
				}

				// Remove optional weak indicator (W/) and quotes, extracting the raw tag.
				// Example: W/"abc" or "abc" -> abc
				$clean = preg_replace('/^\s*(W\/)?\s*"(.*)"\s*$/i', '$2', $c);

				// If regex did not change the value, strip quotes conservatively.
				if ($clean === $c) {
					$clean = trim($c, " \t\n\r\0\x0B\"");
				}

				// Compare against server ETag without surrounding quotes.
				$serverEtagValue = trim($etag, '"');
				if ($clean === $serverEtagValue) {
					$matched = true;
					break;
				}
			}
		}

		// Set strict Content-Security-Policy as before.
		header(
			"Content-Security-Policy: sandbox; default-src 'none'; img-src data:; media-src data:; "
			. "style-src data: 'unsafe-inline'; font-src data:; frame-src data:"
		);

		// If matched, respond with 304 Not Modified (no body).
		if ($matched) {
			header('ETag: ' . $etag);
			header('Cache-Control: public, max-age=0');
			http_response_code(304);
			exit;
		}

		// Otherwise send normal headers and body. Include Content-Length and ETag.
		header("Content-Type: {$mimetype}");
		header('Content-Length: ' . strlen($content));
		header('ETag: ' . $etag);
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
