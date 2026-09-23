<?php

namespace App\Controller\Api\Conect;

use App\Common\Helpers\ConectApiMapper;
use App\Model\Entity\CjNotificacao;
use App\Model\Entity\User;

class Notificacoes {

	private static function respond(array $body, int $code = 200): array {
		return [
			'code' => $code,
			'json' => json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
		];
	}

	public static function listar($request): array {
		$user = $request->user ?? null;
		if (!$user instanceof User) {
			return self::respond(['message' => 'Não autenticado.'], 401);
		}
		if (!CjNotificacao::tabelaExiste()) {
			return self::respond(['items' => [], 'sqlOk' => false]);
		}
		$rows = CjNotificacao::listarPorUsuario((int)$user->id, 50);
		$items = array_map([ConectApiMapper::class, 'notificacao'], $rows);
		return self::respond(['items' => $items, 'sqlOk' => true]);
	}

	public static function marcarLida($request, int $id): array {
		$user = $request->user ?? null;
		if (!$user instanceof User) {
			return self::respond(['message' => 'Não autenticado.'], 401);
		}
		if (!CjNotificacao::tabelaExiste() || $id <= 0) {
			return self::respond(['message' => 'Notificação não encontrada.'], 404);
		}
		if (!CjNotificacao::marcarLida($id, (int)$user->id)) {
			return self::respond(['message' => 'Notificação não encontrada.'], 404);
		}
		return self::respond(['message' => 'Notificação marcada como lida.']);
	}
}
