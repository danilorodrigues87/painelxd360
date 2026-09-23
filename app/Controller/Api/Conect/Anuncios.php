<?php

namespace App\Controller\Api\Conect;

use App\Common\Helpers\ConectAnuncioHelper;
use App\Model\Entity\CjAnuncio;

class Anuncios {

	private static function respond(array $body, int $code = 200): array {
		return [
			'code' => $code,
			'json' => json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
		];
	}

	public static function listar($request): array {
		if (!CjAnuncio::tabelaExiste()) {
			return self::respond(['items' => [], 'sqlOk' => false]);
		}
		$q = $request->getQueryParams() ?? [];
		$slot = trim((string)($q['slot'] ?? 'footer_carousel'));
		$uf = trim((string)($q['uf'] ?? ''));
		$cidadeId = (int)($q['cidadeId'] ?? $q['cidade_id'] ?? 0);
		$limit = min(12, max(1, (int)($q['limit'] ?? 8)));

		$items = ConectAnuncioHelper::listarPublico(
			$slot,
			$uf !== '' ? $uf : null,
			$cidadeId > 0 ? $cidadeId : null,
			$limit
		);

		return self::respond(['items' => $items, 'sqlOk' => true]);
	}

	public static function evento($request, int $id): array {
		if (!CjAnuncio::tabelaExiste() || $id <= 0) {
			return self::respond(['ok' => false, 'message' => 'Anúncios não disponíveis.'], 503);
		}
		$row = CjAnuncio::getById($id);
		if (!$row || ($row['status'] ?? '') !== 'ativo') {
			return self::respond(['ok' => false, 'message' => 'Anúncio não encontrado.'], 404);
		}

		$post = $request->getPostVars() ?: [];
		$tipo = (string)($post['tipo'] ?? '');
		if (!in_array($tipo, ['impressao', 'clique'], true)) {
			return self::respond(['ok' => false, 'message' => 'Tipo inválido.'], 400);
		}

		$ok = ConectAnuncioHelper::registrarEvento(
			$id,
			$tipo,
			(string)($post['visitorId'] ?? $post['visitor_id'] ?? ''),
			(string)($post['slot'] ?? $row['slot'] ?? ''),
			isset($post['uf']) ? (string)$post['uf'] : null,
			isset($post['cidadeId']) ? (int)$post['cidadeId'] : (isset($post['cidade_id']) ? (int)$post['cidade_id'] : null)
		);

		return self::respond(['ok' => $ok]);
	}

	public static function configPublico($request): array {
		$config = \App\Model\Entity\CjAnuncioConfig::get();
		return self::respond([
			'precoMinimoMensal'    => (float)($config['preco_minimo_mensal'] ?? 99),
			'slotsHabilitados'     => $config['slots_habilitados'] ?? [],
			'slots'                => ConectAnuncioHelper::SLOTS,
			'slotDimensoes'        => ConectAnuncioHelper::SLOT_DIMENSOES,
		]);
	}
}
