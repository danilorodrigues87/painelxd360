<?php

namespace App\Http\Middleware;

use App\Common\Environment;

/**
 * CORS para API Conecta Jovem (/api/v1/conect e /api/v1/conect-empresa).
 */
class CorsConect {

	public static function applyHeaders(): void {
		$origin = trim((string)($_SERVER['HTTP_ORIGIN'] ?? ''));
		$allowedRaw = (string)(Environment::get('CONECT_CORS_ORIGINS')
			?: 'http://localhost:5173,http://127.0.0.1:5173,http://localhost:4173');
		$origins = array_values(array_filter(array_map('trim', explode(',', $allowedRaw))));

		$allow = false;
		if ($origin !== '' && (in_array($origin, $origins, true) || in_array('*', $origins, true) || self::isLocalDevOrigin($origin))) {
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
		}
	}

	public static function isConectApiUri(string $uri): bool {
		$path = '/'.ltrim($uri, '/');
		return str_starts_with($path, '/api/v1/conect');
	}

	public function handle($request, $next) {
		self::applyHeaders();
		if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
			http_response_code(204);
			exit;
		}
		return $next($request);
	}

	private static function isLocalDevOrigin(string $origin): bool {
		$host = parse_url($origin, PHP_URL_HOST);
		if (!is_string($host) || $host === '') {
			return false;
		}
		$host = strtolower($host);
		if (in_array($host, ['localhost', '127.0.0.1', '::1'], true) || str_ends_with($host, '.local')) {
			return true;
		}
		// Rede local (celular/tablet testando via 192.168.x.x)
		if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
			return preg_match('/^(10\.|172\.(1[6-9]|2\d|3[01])\.|192\.168\.)/', $host) === 1;
		}
		return false;
	}
}
