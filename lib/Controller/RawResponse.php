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

	protected function getMimeType($fileNode) {
		$filename = $fileNode->getName();
		$mimetype = $fileNode->getMimeType();

		// Check if the MIME type needs to be determined due to missing extension
		if (empty(pathinfo($filename, PATHINFO_EXTENSION))) {
			// Initialize `finfo` to detect MIME type
			$finfo = new \finfo(FILEINFO_MIME_TYPE);
			$content = $fileNode->getContent();

			// Detect MIME type based on file content
			$mimetype = $finfo->buffer($content) ?: 'application/octet-stream';
		}

		return $mimetype;
	}

	protected function returnRawResponse($fileNode) {
		if ($fileNode->getType() === 'dir') {
			// If the requested path is a folder, try return its index.html.
			try {
				$fileNode = $fileNode->get('index.html');
			} catch (NotFoundException $e) {
				return new NotFoundResponse();
			}
		}

		$content = $fileNode->getContent();
		$mimetype = $this->getMimeType($fileNode);

		// Ugly hack to have exact control over the response, to e.g. prevent security middleware
		// messing up the CSP. TODO find a neater solution than bluntly doing header() + echo + exit.
		header( // Add a super strict CSP: no connectivity allowed.
			"Content-Security-Policy: sandbox; default-src 'none'; img-src data:; media-src data:; "
			. "style-src data: 'unsafe-inline'; font-src data:; frame-src data:"
		);
		header("Content-Type: {$mimetype}");
		echo $content;
		exit;
	}
}
