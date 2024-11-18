<?php
namespace OCA\Raw\Controller;

use \Exception;
use OCP\IConfig;
use OCP\IRequest;
use OCP\Share\IManager;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\NotFoundResponse;
use OCP\Files\NotFoundException;

class PubPageController extends Controller {
	use RawResponse;

	private $shareManager;
	private $config;

	public function __construct(
		$AppName,
		IRequest $request,
		IManager $shareManager,
		IConfig $config
	) {
		parent::__construct($AppName, $request);
		$this->shareManager = $shareManager;
		$this->config = $config;
	}

	private function isAllowedToken($token) {
		// Load allowed tokens and wildcards from config
		$allowedTokens = $this->config->getSystemValue('allowed_raw_tokens', []);
		$allowedWildcards = $this->config->getSystemValue('allowed_raw_token_wildcards', []);

		// Direct match check
		if (in_array($token, $allowedTokens, true)) {
			return true;
		}

		// Wildcard match check
		foreach ($allowedWildcards as $wildcard) {
			// Replace '*' with a regex pattern to match any number of any characters
			$pattern = '/^' . str_replace('\*', '.*', preg_quote($wildcard, '/')) . '$/';

			if (preg_match($pattern, $token)) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @PublicPage
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	public function getByToken($token) {
		if (!$this->isAllowedToken($token)) {
			return new NotFoundResponse();
		}

		$share = $this->shareManager->getShareByToken($token);
		$node = $share->getNode();
		$this->returnRawResponse($node);
	}

	/**
	 * @PublicPage
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	public function getByTokenWithoutS($token, $path) {
		return $this->getByToken($token, $path);
	}

	/**
	 * @PublicPage
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	public function getByTokenAndPath($token, $path) {
		if (!$this->isAllowedToken($token)) {
			return new NotFoundResponse();
		}

		$share = $this->shareManager->getShareByToken($token);
		$dirNode = $share->getNode();
		if ($dirNode->getType() !== 'dir') {
			throw new Exception("Received a sub-path for a share that is not a directory");
		}
		try {
			$fileNode = $dirNode->get($path);
		} catch (NotFoundException $e) {
			return new NotFoundResponse();
		}
		$this->returnRawResponse($fileNode);
	}

	/**
	 * @PublicPage
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	public function getByTokenAndPathWithoutS($token, $path) {
		return $this->getByTokenAndPath($token, $path);
	}
}
