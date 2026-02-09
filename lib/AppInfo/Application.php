<?php
namespace OCA\Raw\AppInfo;

use OCA\Raw\Controller\PrivatePageController;
use OCA\Raw\Controller\PubPageController;
use OCA\Raw\Service\CspManager;
use OCP\AppFramework\App;
use OCP\IConfig;
use OCP\IContainer;
use OCP\IRequest;
use OCP\IUserSession;
use OCP\Files\IRootFolder;
use OCP\Share\IManager;

class Application extends App {
	/**
	 * Application constructor
	 *
	 * @param array $urlParams
	 */
	public function __construct(array $urlParams = []) {
		parent::__construct('raw', $urlParams);
		$container = $this->getContainer();

		$this->registerServices($container);
		$this->registerControllers($container);
	}

	/**
	 * Register shared services used by the app.
	 */
	protected function registerServices(IContainer $c) {
		// Register CspManager as a shared service that uses OCP\IConfig
		$c->registerService('CspManager', function($container) {
			/** @var IConfig $config */
			$config = $container->query('OCP\IConfig');
			return new CspManager($config);
		});
	}

	/**
	 * Register controller factories that inject dependencies.
	 */
	protected function registerControllers(IContainer $c) {
		$c->registerService('PubPageController', function($container) {
			$appName = $container->getAppName();
			/** @var IRequest $request */
			$request = $container->query('Request');
			/** @var IManager $shareManager */
			$shareManager = $container->query('OCP\Share\IManager');
			/** @var IConfig $config */
			$config = $container->query('OCP\IConfig');
			/** @var CspManager $cspManager */
			$cspManager = $container->query('CspManager');

			return new PubPageController($appName, $request, $shareManager, $config, $cspManager);
		});

		$c->registerService('PrivatePageController', function($container) {
			$appName = $container->getAppName();
			/** @var IRequest $request */
			$request = $container->query('Request');
			/** @var IRootFolder $rootFolder */
			$rootFolder = $container->query('OCP\Files\IRootFolder');
			/** @var CspManager $cspManager */
			$cspManager = $container->query('CspManager');
			/** @var IUserSession $userSession */
			$userSession = $container->query('OCP\IUserSession');

			return new PrivatePageController($appName, $request, $rootFolder, $cspManager, $userSession);
		});
	}
}

