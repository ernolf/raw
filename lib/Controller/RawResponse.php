<?php
namespace OCA\Raw\Controller;

use \Exception;

trait RawResponse {
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
		$mimetype = $fileNode->getMimeType();

		// Ugly hack to have exact control over the response, to e.g. prevent security middleware
		// messing up the CSP. TODO find a neater solution than bluntly doing header() + echo + exit.
		header( // Add a super strict CSP: no connectivity allowed.
			"Content-Security-Policy: sandbox; default-src 'none'; img-src data:; media-src data:; "
			. "style-src data: 'unsafe-inline'; font-src data:; frame-src data:"
		);
		header("Content-Type: ${mimetype}");
		echo $content;
		exit;
	}
}
