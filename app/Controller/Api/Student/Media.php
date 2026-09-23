<?php

namespace App\Controller\Api\Student;

use App\Http\Response;
use App\Common\Helpers\BunnyStorageHelper;

/**
 * Proxy Bunny Storage para <img>/<audio> (token assinado, sem JWT).
 * Baixa via Storage API — não depende de Token Auth da Pull Zone CDN.
 */
class Media {

	public static function bunnyFile($request) {
		$get = $request->getQueryParams() ?: [];
		$token = (string)($get['t'] ?? $get['token'] ?? '');
		$path = BunnyStorageHelper::verifyFileToken($token);
		if ($path === null) {
			$path = BunnyStorageHelper::pathFromFileToken($token, true);
		}
		if ($path === null) {
			return new Response(403, 'Token inválido ou expirado.', 'text/plain; charset=utf-8');
		}

		$res = BunnyStorageHelper::fetch($path);
		if (empty($res['ok']) || !isset($res['body'])) {
			return new Response(502, $res['message'] ?? 'Falha ao buscar mídia.', 'text/plain; charset=utf-8');
		}
		$mime = (string)($res['contentType'] ?? 'application/octet-stream');
		if (!headers_sent()) {
			header_remove('Content-Type');
			header('Content-Type: '.$mime, true);
		}
		$resp = new Response(200, $res['body'], $mime);
		$resp->addHeader('Cache-Control', 'private, max-age=300');
		$resp->addHeader('Accept-Ranges', 'bytes');
		$resp->addHeader('X-Content-Type-Options', 'nosniff');
		return $resp;
	}
}
