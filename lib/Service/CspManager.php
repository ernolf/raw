<?php
namespace OCA\Raw\Service;

use OCP\IConfig;

class CspManager {

	/**
	 * Central hard-coded fallback CSP.
	 * Edit this constant to change the global default policy (single source of truth).
	 */
	public const HARD_FALLBACK = "sandbox; default-src 'none'; img-src data:; media-src data:; style-src data: 'unsafe-inline'; font-src data:; frame-src data:";

	/** @var IConfig */
	protected $configService;

	/** @var string runtime fallback (initialized from constant) */
	protected $hardFallback;

	/**
	 * Constructor expects Nextcloud's IConfig service.
	 *
	 * @param IConfig $configService Nextcloud config service (use $this->config in controllers)
	 */
	public function __construct(IConfig $configService) {
		$this->configService = $configService;
		// initialize the running hardFallback from the class constant
		$this->hardFallback = self::HARD_FALLBACK;
	}

	/**
	 * Optional setter for tests or programmatic override.
	 * Use sparingly — prefer changing the class constant for permanent changes.
	 *
	 * @param string $csp
	 * @return void
	 */
	public function setHardFallback(string $csp) {
		$this->hardFallback = $csp;
	}

	/**
	 * Determine CSP for the current request / file node.
	 * Matching priority: token -> path_prefix (absolute/relative) -> path_contains -> extension -> mimetype -> hard fallback.
	 *
	 * Note: configuration is read from IConfig on every call using getSystemValue('raw_csp', []).
	 *
	 * @param object $fileNode object that provides getName() and getMimeType()
	 * @return string single-line CSP header value
	 */
	public function determineCspForRequest($fileNode) {
		$hardFallback = $this->hardFallback;

		// Read admin-provided raw_csp from Nextcloud system config (always read fresh)
		$rawCsp = (array)$this->configService->getSystemValue('raw_csp', []);
		if (!is_array($rawCsp) || count($rawCsp) === 0) {
			return $hardFallback;
		}

		// extract selectors from the configured array (safe defaults to empty arrays)
		$tokens     = $rawCsp['token'] ?? [];
		$prefixes   = $rawCsp['path_prefix'] ?? [];
		$extensions = $rawCsp['extension'] ?? [];
		$mimetypes  = $rawCsp['mimetype'] ?? [];
		// DON'T overwrite $mimetypes — path_contains is separate
		// $contains will be read below when needed

		// get the raw request path (no query)
		$uri = $_SERVER['REQUEST_URI'] ?? '';
		$uriPath = parse_url($uri, PHP_URL_PATH) ?: $uri;
		$uriPath = urldecode($uriPath);

		// 1) Token extraction: support both /apps/raw/s/{token} and /apps/raw/{token}
		$token = null;
		if (preg_match('#^/apps/raw(?:/(?:s|u))?/([^/]+)#', $uriPath, $m)) {
			$token = $m[1];
			// exact token match has highest priority
			if (isset($tokens[$token])) {
				return $this->buildCspFromPolicy($tokens[$token]);
			}
		}

		// Compute path after optional token for relative matching
		if ($token !== null) {
			$afterTokenPath = preg_replace('#^/apps/raw(?:/(?:s|u))?/' . preg_quote($token, '#') . '#', '', $uriPath);
		} else {
			$afterTokenPath = preg_replace('#^/apps/raw#', '', $uriPath);
		}
		if ($afterTokenPath === '') { $afterTokenPath = '/'; }

		// 2) path-prefix matching: supports absolute prefixes (start with /apps/raw) and relative prefixes (match against $afterTokenPath)
		$bestPrefix = null;
		$bestIsRelative = false;
		foreach ($prefixes as $prefix => $policy) {
			$prefix = (string)$prefix;
			if ($prefix === '') { continue; }

			// absolute prefix: compare against full URI path
			if (strpos($prefix, '/apps/raw') === 0) {
				if (strpos($uriPath, $prefix) === 0) {
					if ($bestPrefix === null || strlen($prefix) > strlen($bestPrefix)) {
						$bestPrefix = $prefix;
						$bestIsRelative = false;
					}
				}
				continue;
			}

			// relative prefix: normalize to have leading slash and compare with $afterTokenPath
			$rel = ($prefix[0] === '/') ? $prefix : '/' . $prefix;
			if (strpos($afterTokenPath, $rel) === 0) {
				if ($bestPrefix === null || strlen($rel) > strlen($bestPrefix)) {
					$bestPrefix = $rel;
					$bestIsRelative = true;
				}
			}
		}

		if ($bestPrefix !== null) {
			if ($bestIsRelative) {
				// try both '/html/' and 'html/' keys in original config
				$tryKeys = [$bestPrefix, ltrim($bestPrefix, '/')];
				foreach ($tryKeys as $k) {
					if (isset($prefixes[$k])) {
						return $this->buildCspFromPolicy($prefixes[$k]);
					}
				}
			} else {
				return $this->buildCspFromPolicy($prefixes[$bestPrefix]);
			}
		}

		// 3) path_contains: match either exact-slash-patterns or plain-substring patterns
		$contains = $rawCsp['path_contains'] ?? [];
		foreach ($contains as $pattern => $policy) {
			$pat = (string)$pattern;
			if ($pat === '') { continue; }

			// If the admin supplied a pattern that starts with '/', treat it as a verbatim substring.
			// This is useful to match exact segments like "/html/".
			if ($pat[0] === '/') {
				// check both full request path and path-after-app-prefix
				if (strpos($uriPath, $pat) !== false || strpos($afterTokenPath, $pat) !== false) {
					return $this->buildCspFromPolicy($policy);
				}
				continue;
			}

			// If pattern does NOT start with '/', perform a plain substring match (no leading slash added).
			// This makes 'html' match '/some_html_text/', '/htmlfile', 'some-html-data', etc.
			if (strpos($uriPath, $pat) !== false || strpos($afterTokenPath, $pat) !== false) {
				return $this->buildCspFromPolicy($policy);
			}
		}

		// 4) extension match
		$name = $fileNode->getName() ?? '';
		$ext = strtolower(pathinfo($name, PATHINFO_EXTENSION) ?: '');
		if ($ext !== '' && isset($extensions[$ext])) {
			return $this->buildCspFromPolicy($extensions[$ext]);
		}

		// 5) mimetype match
		$mime = strtolower($fileNode->getMimeType() ?? '');
		if ($mime !== '' && isset($mimetypes[$mime])) {
			return $this->buildCspFromPolicy($mimetypes[$mime]);
		}

		// No match found -> return hard fallback
		return $hardFallback;
	}

	/**
	 * Build a CSP string from policy which may be:
	 * - string (pass-through)
	 * - indexed array of directive strings
	 * - associative array directive => sources (recommended)
	 *
	 * Minimal validation / sanitization included.
	 *
	 * @param mixed $policy
	 * @return string
	 */
	public function buildCspFromPolicy($policy) {
		$allowed = [
			'default-src','script-src','style-src','img-src','media-src','font-src',
			'connect-src','object-src','frame-src','frame-ancestors','base-uri',
			'form-action','worker-src','manifest-src','prefetch-src','child-src',
			'upgrade-insecure-requests','block-all-mixed-content','sandbox'
		];

		// string passthrough
		if (is_string($policy)) {
			return $this->sanitizeCspString($policy);
		}

		// numeric-indexed array like ["default-src 'self'", "script-src 'self'"]
		if (is_array($policy) && array_values($policy) === $policy) {
			$joined = implode('; ', array_map('trim', $policy));
			return $this->sanitizeCspString($joined);
		}

		// associative array
		if (is_array($policy)) {
			$normalized = [];
			foreach ($policy as $directive => $value) {
				$directive = trim((string)$directive);
				if ($directive === '') { continue; }
				if (!in_array($directive, $allowed, true)) {
					// skip unknown directive
					continue;
				}
				$sources = [];
				if (is_array($value)) {
					foreach ($value as $v) {
						$v = trim((string)$v);
						if ($v === '') { continue; }
						$sources[] = $v;
					}
				} else {
					$parts = preg_split('/\s+/', trim((string)$value));
					foreach ($parts as $p) {
						if ($p !== '') { $sources[] = $p; }
					}
				}
				$sources = array_values(array_unique($sources));
				$normalized[$directive] = implode(' ', $sources);
			}

			// canonical ordering: default-src first, then common, then rest alphabetical
			$priority = ['default-src','script-src','style-src','img-src','media-src','font-src','connect-src','frame-src','frame-ancestors'];
			$ordered = [];
			foreach ($priority as $d) {
				if (isset($normalized[$d])) {
					$ordered[$d] = $normalized[$d];
					unset($normalized[$d]);
				}
			}
			ksort($normalized);
			foreach ($normalized as $d => $v) {
				$ordered[$d] = $v;
			}

			$parts = [];
			foreach ($ordered as $d => $v) {
				if ($v === '') {
					$parts[] = $d;
				} else {
					$parts[] = $d . ' ' . $v;
				}
			}
			return $this->sanitizeCspString(implode('; ', $parts));
		}

		return '';
	}

	/**
	 * Minimal sanitization for CSP string.
	 *
	 * @param string $csp
	 * @return string
	 */
	public function sanitizeCspString($csp) {
		$csp = preg_replace('/[\x00-\x1F\x7F]+/', ' ', (string)$csp);
		$csp = preg_replace('/\s{2,}/', ' ', trim($csp));
		return $csp;
	}
}
