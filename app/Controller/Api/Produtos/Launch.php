<?php

namespace App\Controller\Api\Produtos;

use App\Common\Environment;
use App\Common\Helpers\ModuleGateHelper;
use App\Common\ProductModules;
use App\Model\Entity\ClientesAssinantes;
use App\Model\Entity\ProdutoLaunchToken;
use App\Model\Entity\User;

class Launch {

	private static function ok(array $data, int $code = 200): array {
		return [
			'code' => $code,
			'json' => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
		];
	}

	private static function err(string $msg, int $code = 400): array {
		return self::ok(['ok' => false, 'message' => $msg, 'erro' => $msg], $code);
	}

	private static function secretOk($request): bool {
		$expected = trim((string)Environment::get('PRODUCT_LAUNCH_SECRET', ''));
		if ($expected === '') {
			return true;
		}
		$hdr = (string)($request->getHeaders()['X-Product-Launch-Secret'] ?? '');
		if ($hdr === '' && function_exists('apache_request_headers')) {
			$headers = apache_request_headers();
			$hdr = (string)($headers['X-Product-Launch-Secret'] ?? $headers['x-product-launch-secret'] ?? '');
		}
		return hash_equals($expected, $hdr);
	}

	/**
	 * Consumo do token pelo backend do produto (DoceFlow).
	 * Body: { "token": "..." }
	 */
	public static function exchange($request) {
		if (!self::secretOk($request)) {
			return self::err('Não autorizado.', 401);
		}

		$post = $request->getPostVars();
		if (!is_array($post)) {
			$post = [];
		}
		if (empty($post['token'])) {
			$raw = file_get_contents('php://input');
			$decoded = is_string($raw) ? json_decode($raw, true) : null;
			if (is_array($decoded)) {
				$post = $decoded;
			}
		}

		$plain = trim((string)($post['token'] ?? ''));
		if ($plain === '') {
			return self::err('Informe o token.', 400);
		}

		try {
			$row = ProdutoLaunchToken::consumir($plain);
		} catch (\Throwable $e) {
			error_log('[produtos.launch.exchange] '.$e->getMessage());
			return self::err('Falha ao validar token.', 500);
		}

		if (!$row) {
			return self::err('Token inválido ou expirado.', 401);
		}

		$tenantId = (int)$row->tenant_id;
		$user = User::getUserById((int)$row->id_usuario);
		if (!$user instanceof User) {
			return self::err('Usuário inválido.', 401);
		}

		$escola = ClientesAssinantes::getEscolaById($tenantId);
		if (!$escola instanceof ClientesAssinantes || !$escola->isAtiva()) {
			return self::err('Cliente inativo ou não encontrado.', 403);
		}

		$slug = (string)$row->produto_slug;
		if (!in_array($slug, ModuleGateHelper::getSlugsProdutos($tenantId), true)) {
			return self::err('Produto não licenciado neste plano.', 403);
		}

		$status = (string)($escola->assinatura_status ?? '');
		if ($status === 'suspensa') {
			return self::err('Assinatura suspensa.', 403);
		}

		return self::ok([
			'ok' => true,
			'tenant_id' => $tenantId,
			'user_id' => (int)$user->id,
			'email' => (string)($user->email ?? ''),
			'nome' => (string)($user->nome ?? ''),
			'produto_slug' => $slug,
			'produto_label' => ProductModules::slugParaLabel($slug) ?? $slug,
		]);
	}
}
