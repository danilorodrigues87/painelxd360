<?php

namespace App\Http\Middleware;

use App\Common\Environment;

/**
 * CORS para portal do aluno e L-Editor (API /api/v1/student e /api/v1/editor).
 * Headers e preflight ficam aqui — não no index.php.
 * Só Origins listados em STUDENT_CORS_ORIGINS (ou localhost em dev).
 */
class CorsStudent {

	/** Aplica headers CORS (rotas OK e respostas de erro do Router). */
	public static function applyHeaders(): void {
		$origin = trim((string)($_SERVER['HTTP_ORIGIN'] ?? ''));
		$allowedRaw = (string)(Environment::get('STUDENT_CORS_ORIGINS')
			?: 'http://localhost:8080,http://127.0.0.1:8080,http://localhost:8081,http://127.0.0.1:8081');
		$origins = array_values(array_filter(array_map('trim', explode(',', $allowedRaw))));

		$allow = false;
		if ($origin === '') {
			// Mesma origem / curl / sem browser — não ecoa Origin arbitrário
			$allow = false;
		} elseif (in_array('*', $origins, true)) {
			$allow = true;
		} elseif (in_array($origin, $origins, true)) {
			$allow = true;
		} elseif (self::isLocalDevOrigin($origin)) {
			$allow = true;
		}

		if ($allow && $origin !== '') {
			header('Access-Control-Allow-Origin: '.$origin);
			header('Access-Control-Allow-Credentials: true');
			header('Vary: Origin');
			header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
			header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, Accept, Origin');
			header('Access-Control-Expose-Headers: Content-Type, Authorization');
			header('Access-Control-Max-Age: 86400');
			if (self::isLocalDevOrigin($origin)) {
				header('Access-Control-Allow-Private-Network: true');
			}
		}
	}

	public static function isStudentApiUri(string $uri): bool {
		$path = '/'.ltrim($uri, '/');
		return str_starts_with($path, '/api/v1/student')
			|| str_starts_with($path, '/api/v1/editor');
	}

	public function handle($request, $next) {
		self::applyHeaders();

		if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
			$origin = trim((string)($_SERVER['HTTP_ORIGIN'] ?? ''));
			$allowed = $origin !== '' && (
				self::isLocalDevOrigin($origin)
				|| in_array($origin, self::allowedOrigins(), true)
				|| in_array('*', self::allowedOrigins(), true)
			);
			http_response_code($allowed ? 204 : 403);
			exit;
		}

		return $next($request);
	}

	/** @return list<string> */
	private static function allowedOrigins(): array {
		$allowedRaw = (string)(Environment::get('STUDENT_CORS_ORIGINS')
			?: 'http://localhost:8080,http://127.0.0.1:8080,http://localhost:8081,http://127.0.0.1:8081');
		return array_values(array_filter(array_map('trim', explode(',', $allowedRaw))));
	}

	private static function isLocalDevOrigin(string $origin): bool {
		$host = parse_url($origin, PHP_URL_HOST);
		if (!is_string($host) || $host === '') {
			return false;
		}
		$host = strtolower($host);
		return $host === 'localhost'
			|| $host === '127.0.0.1'
			|| $host === '::1'
			|| str_ends_with($host, '.local');
	}
}
