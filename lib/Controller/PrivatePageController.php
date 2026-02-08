<?php
namespace OCA\Raw\Controller;

use OCA\Raw\Service\CspManager;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\IRequest;
use OCP\IServerContainer;
use OCP\IConfig;
use OCP\IUserSession;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\NotFoundResponse;
use OCP\Files\NotFoundException;

class PrivatePageController extends Controller {
	use RawResponse;

	/** @var string|null */
	private $loggedInUserId;

	/** @var IServerContainer */
	private $serverContainer;

	/** @var CspManager */
	protected $cspManager;

	/** @var IConfig */
	private $config;

	/**
	 * @param string $appName
	 * @param IRequest $request
	 * @param IServerContainer $serverContainer
	 * @param IConfig $config
	 * @param CspManager $cspManager
	 * @param IUserSession $userSession
	 */
	public function __construct(
		$appName,
		IRequest $request,
		IServerContainer $serverContainer,
		IConfig $config,
		CspManager $cspManager,
		IUserSession $userSession
	) {
		parent::__construct($appName, $request);

		$this->serverContainer = $serverContainer;
		$this->config = $config;
		$this->cspManager = $cspManager;

		// Set loggedInUserId from the user session if available (null if anonymous)
		// This is safer and more idiomatic than passing the UID into the constructor.
		$this->loggedInUserId = null;
		if ($userSession->isLoggedIn() && $userSession->getUser() !== null) {
			$this->loggedInUserId = $userSession->getUser()->getUID();
		}
		// Note: for public/anonymous requests loggedInUserId remains null
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function getByPath($userId, $path) {
		if ($userId !== $this->loggedInUserId) {
			// TODO Currently, we only allow access to one's own files. I suppose we could implement
			// authorisation checks and give the user access to files that have been shared with them.
			return new NotFoundResponse(); // would 403 Forbidden be better?
		}

		$userFolder = $this->serverContainer->getUserFolder($userId);
		if (!$userFolder) {
			return new NotFoundResponse();
		}

		try {
			$node = $userFolder->get($path);
		} catch (NotFoundException $e) {
			return new NotFoundResponse();
		}
		$this->returnRawResponse($node);
	}
}
