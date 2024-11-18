<?php
namespace OCA\Raw\Controller;

use \Exception;
use OCP\IRequest;
use OCP\Share\IManager;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\NotFoundResponse;
use OCP\Files\NotFoundException;

class PubPageController extends Controller {
	use RawResponse;

	private $shareManager;

	public function __construct(
		$AppName,
		IRequest $request,
		IManager $shareManager
	) {
		parent::__construct($AppName, $request);
		$this->shareManager = $shareManager;
	}

	/**
	 * @PublicPage
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	public function getByToken($token) {
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
