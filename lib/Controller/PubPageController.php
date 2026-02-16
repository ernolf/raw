<?php
namespace OCA\Raw\Controller;

use OCA\Raw\Service\CspManager;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
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

	private function plainNotFound() {
		if (session_status() === PHP_SESSION_ACTIVE) {
			session_write_close();
			ini_set('session.use_cookies', 0);
		}
		header_remove('Set-Cookie');
		header('Content-Type: text/plain; charset=utf-8');
		header('Cache-Control: no-store, max-age=0');
		header('Content-Length: 9');
		http_response_code(404);
		echo 'Not found';
		exit;
	}

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
			$this->plainNotFound();
		}

		try {
			$share = $this->shareManager->getShareByToken($token);
			$node = $share->getNode();
		} catch (\Throwable $e) {
			$this->plainNotFound();
		}
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
	public function getByTokenRoot($token) {
		// Wrapper for root alias /raw/{token}, keeps legacy /apps/raw/{token} intact
		return $this->getByTokenWithoutS($token);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[PublicPage]
	public function getByTokenRootLegacyS($token) {
		// Wrapper for legacy root alias /raw/s/{token}
		return $this->getByTokenWithoutS($token);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[PublicPage]
	public function getByTokenAndPath($token, $path) {
		if (!$this->isAllowedToken($token)) {
			$this->plainNotFound();
		}

		try {
			$share = $this->shareManager->getShareByToken($token);
			$dirNode = $share->getNode();
		} catch (\Throwable $e) {
			$this->plainNotFound();
		}
		if ($dirNode->getType() !== 'dir') {
			throw new \Exception("Received a sub-path for a share that is not a directory");
		}
		try {
			$fileNode = $dirNode->get($path);
		} catch (NotFoundException $e) {
			$this->plainNotFound();
		}
		$this->returnRawResponse($fileNode);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[PublicPage]
	public function getByTokenAndPathWithoutS($token, $path) {
		return $this->getByTokenAndPath($token, $path);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[PublicPage]
	public function getByTokenAndPathRoot($token, $path) {
		// Wrapper for root alias /raw/{token}/{path}
		return $this->getByTokenAndPathWithoutS($token, $path);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[PublicPage]
	public function getByTokenAndPathRootLegacyS($token, $path) {
		// Wrapper for legacy root alias /raw/s/{token}/{path}
		return $this->getByTokenAndPathWithoutS($token, $path);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[PublicPage]
	public function getRssRoot() {
		// Root namespace alias: /rss -> behaves like /raw/rss
		return $this->getByTokenRoot('rss');
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[PublicPage]
	public function getRssRootPath($path = '') {
		// Root namespace alias: /rss/{path} -> behaves like /raw/rss/{path}
		if ($path === '' || $path === null) {
			return $this->getByTokenRoot('rss');
		}
		return $this->getByTokenAndPathRoot('rss', (string)$path);
	}
}
