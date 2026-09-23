<?php

namespace App\Common\Helpers;

use App\Common\Environment;
use App\Model\Entity\ClientesAssinantes;
use App\Model\Entity\User;

/**
 * Roteamento candidato/lead → escola parceira (1 escola por cidade; fallback id=1).
 */
class ConectJovemLeadRouter {

	public static function fallbackIdAdmin(): int {
		$id = (int)Environment::get('CONECT_ESCOLA_FALLBACK_ID', 1);
		return $id > 0 ? $id : 1;
	}

	public static function escolaIdPorCidadeId(int $cidadeId): ?int {
		if ($cidadeId <= 0) {
			return null;
		}
		$rows = ClientesAssinantes::getEscolas('cidade = '.(int)$cidadeId, 'id ASC', '1');
		if (!$rows) {
			return null;
		}
		$escola = $rows->fetchObject(ClientesAssinantes::class);
		if (!$escola instanceof ClientesAssinantes || !$escola->isAtiva()) {
			return null;
		}
		return (int)($escola->id ?: $escola->id_admin);
	}

	/**
	 * @param array{id_admin_fixo?:int,cidade_id?:int,id_usuario?:int} $ctx
	 */
	public static function resolverIdAdmin(array $ctx): int {
		if (!empty($ctx['id_admin_fixo']) && (int)$ctx['id_admin_fixo'] > 0) {
			return (int)$ctx['id_admin_fixo'];
		}

		$idUsuario = (int)($ctx['id_usuario'] ?? 0);
		if ($idUsuario > 0) {
			$user = User::getUserById($idUsuario);
			if ($user instanceof User && ($user->nivel ?? '') === 'Cliente' && (int)$user->id_admin > 0) {
				return (int)$user->id_admin;
			}
		}

		$cidadeId = (int)($ctx['cidade_id'] ?? 0);
		$escolaId = self::escolaIdPorCidadeId($cidadeId);
		if ($escolaId !== null && $escolaId > 0) {
			return $escolaId;
		}

		return self::fallbackIdAdmin();
	}
}
