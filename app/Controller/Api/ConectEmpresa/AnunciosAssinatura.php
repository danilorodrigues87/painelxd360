<?php

namespace App\Controller\Api\ConectEmpresa;

use App\Common\Helpers\ConectAnuncioAssinaturaService;
use App\Model\Entity\CjAnuncioPlano;
use App\Model\Entity\CjEmpresa;

class AnunciosAssinatura {

	private static function respond(array $body, int $code = 200): array {
		return [
			'code' => $code,
			'json' => json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
		];
	}

	private static function empresa($request): ?CjEmpresa {
		$e = $request->empresa ?? null;
		return $e instanceof CjEmpresa ? $e : null;
	}

	public static function planos($request): array {
		if (!ConectAnuncioAssinaturaService::moduloAtivo()) {
			return self::respond(['items' => [], 'moduloAtivo' => false]);
		}
		return self::respond([
			'items'       => ConectAnuncioAssinaturaService::listarPlanosPublicos(),
			'moduloAtivo' => true,
		]);
	}

	public static function resumo($request): array {
		$empresa = self::empresa($request);
		if (!$empresa) {
			return self::respond(['message' => 'Não autenticado.'], 401);
		}
		return self::respond(ConectAnuncioAssinaturaService::resumoEmpresa((int)$empresa->id));
	}

	public static function assinar($request): array {
		$empresa = self::empresa($request);
		if (!$empresa) {
			return self::respond(['message' => 'Não autenticado.'], 401);
		}
		if (($empresa->status ?? '') !== 'aprovada') {
			return self::respond(['message' => 'Empresa aguardando aprovação.'], 403);
		}
		$post = $request->getPostVars() ?: [];
		$json = json_decode((string)file_get_contents('php://input'), true);
		if (is_array($json)) {
			$post = array_merge($post, $json);
		}
		$planId = (int)($post['planId'] ?? $post['plan_id'] ?? 0);
		if ($planId <= 0) {
			$slug = trim((string)($post['planSlug'] ?? $post['plan_slug'] ?? ''));
			if ($slug !== '') {
				$plano = CjAnuncioPlano::getBySlug($slug);
				$planId = $plano instanceof CjAnuncioPlano ? (int)$plano->id : 0;
			}
		}
		if ($planId <= 0) {
			return self::respond(['message' => 'Selecione um plano.'], 400);
		}

		$res = ConectAnuncioAssinaturaService::assinar((int)$empresa->id, $planId);
		return self::respond($res, !empty($res['ok']) ? 200 : 400);
	}

	public static function cancelar($request): array {
		$empresa = self::empresa($request);
		if (!$empresa) {
			return self::respond(['message' => 'Não autenticado.'], 401);
		}
		$res = ConectAnuncioAssinaturaService::cancelar((int)$empresa->id);
		return self::respond($res, !empty($res['ok']) ? 200 : 400);
	}

	public static function verificar($request): array {
		$empresa = self::empresa($request);
		if (!$empresa) {
			return self::respond(['message' => 'Não autenticado.'], 401);
		}
		$post = $request->getPostVars() ?: [];
		$json = json_decode((string)file_get_contents('php://input'), true);
		if (is_array($json)) {
			$post = array_merge($post, $json);
		}
		$faturaId = (int)($post['faturaId'] ?? $post['fatura_id'] ?? 0);
		if ($faturaId <= 0) {
			return self::respond(['message' => 'Informe a fatura.'], 400);
		}
		$res = ConectAnuncioAssinaturaService::verificarPagamento((int)$empresa->id, $faturaId);
		return self::respond($res, !empty($res['ok']) ? 200 : 400);
	}
}
