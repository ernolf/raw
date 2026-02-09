<?php
namespace OCA\Raw\Controller;

use OCA\Raw\Service\CspManager;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\NotFoundResponse;
use OCP\Files\NotFoundException;
use OCP\IConfig;
use OCP\IRequest;
use OCP\Share\IManager;

class PubPageController extends Controller {
	use RawResponse;

	/** @var IManager */
	private $shareManager;
	/** @var IConfig */
	private $config;
	/** @var CspManager */
	protected $cspManager;

	public function __construct(
		$appName,
		IRequest $request,
		IManager $shareManager,
		IConfig $config,
		CspManager $cspManager
	) {
		parent::__construct($appName, $request);
		$this->shareManager = $shareManager;
		$this->config = $config;
		$this->cspManager = $cspManager;
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

	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[PublicPage]
	public function getByToken($token) {
		if (!$this->isAllowedToken($token)) {
			return new NotFoundResponse();
		}

		$share = $this->shareManager->getShareByToken($token);
		$node = $share->getNode();
		$this->returnRawResponse($node);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[PublicPage]
	public function getByTokenWithoutS($token) {
		return $this->getByToken($token);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[PublicPage]
	public function getByTokenAndPath($token, $path) {
		if (!$this->isAllowedToken($token)) {
			return new NotFoundResponse();
		}

		$share = $this->shareManager->getShareByToken($token);
		$dirNode = $share->getNode();
		if ($dirNode->getType() !== 'dir') {
			throw new \Exception("Received a sub-path for a share that is not a directory");
		}
		try {
			$fileNode = $dirNode->get($path);
		} catch (NotFoundException $e) {
			return new NotFoundResponse();
		}
		$this->returnRawResponse($fileNode);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[PublicPage]
	public function getByTokenAndPathWithoutS($token, $path) {
		return $this->getByTokenAndPath($token, $path);
	}
}
