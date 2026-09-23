<?php

namespace App\Common\Helpers;

use App\Common\CtiCatalog;
use App\Model\Entity\User;

/** Validação de tenant do editor Ascend (painel escola vs Master + catálogo CTI). */
class EditorAuthHelper {

	/**
	 * Quem pode editar cursos no tenant catálogo CTI (Master hoje; RBAC via permissão).
	 */
	public static function canEditCatalogCti(User $user, int $idAdminToken): bool {
		if (!CtiCatalog::isEscolaCatalogo($idAdminToken)) {
			return false;
		}
		if (MasterGateHelper::isMasterEmail($user->email ?? '')) {
			return true;
		}
		if ((int)($user->id_admin ?? -1) === 0) {
			return MasterGateHelper::podeAcessarModulo('ead_cursos');
		}
		return false;
	}

	/** Alias semântico — preferir canEditCatalogCti em código novo. */
	public static function isMasterCatalogEditor(User $user, int $idAdminToken): bool {
		return self::canEditCatalogCti($user, $idAdminToken);
	}

	/** Resolve id_admin efetivo para APIs do editor após exchange JWT. */
	public static function resolveIdAdmin(User $user, array $jwtClaims): int {
		$idAdminJwt = (int)($jwtClaims['id_admin'] ?? 0);
		if ($idAdminJwt > 0 && self::canEditCatalogCti($user, $idAdminJwt)) {
			return $idAdminJwt;
		}
		if ($idAdminJwt > 0 && !empty($jwtClaims['master_catalog']) && CtiCatalog::isEscolaCatalogo($idAdminJwt)) {
			return $idAdminJwt;
		}
		if ($idAdminJwt > 0 && (int)$user->id_admin === $idAdminJwt) {
			return $idAdminJwt;
		}
		return (int)$user->id_admin;
	}

	/** Valida se usuário pode usar token one-time com id_admin informado. */
	public static function usuarioCompativelComToken(User $user, int $idAdminToken): bool {
		if ((int)$user->id_admin === $idAdminToken) {
			return true;
		}
		return self::canEditCatalogCti($user, $idAdminToken);
	}
}
