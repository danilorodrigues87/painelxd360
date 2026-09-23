<?php

namespace App\Common\Helpers;

use App\Session\User\Login as SessionUser;

class MasterGateHelper {

	/** @return string[] */
	public static function emailsPermitidos(): array {
		$raw = (string)(getenv('MASTER_EMAILS') ?: '');
		if (trim($raw) === '') {
			return [];
		}
		$parts = preg_split('/[\s,;]+/', $raw) ?: [];
		$emails = [];
		foreach ($parts as $p) {
			$p = strtolower(trim($p));
			if ($p !== '' && filter_var($p, FILTER_VALIDATE_EMAIL)) {
				$emails[$p] = true;
			}
		}
		return array_keys($emails);
	}

	public static function isMasterEmail(?string $email): bool {
		$email = strtolower(trim((string)$email));
		if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
			return false;
		}
		return in_array($email, self::emailsPermitidos(), true);
	}

	public static function isMasterSession(): bool {
		$email = $_SESSION['usuario-mvc-1']['email'] ?? '';
		if (self::isMasterEmail((string)$email)) {
			$_SESSION['usuario-mvc-1']['is_master'] = true;
			return true;
		}
		$userId = (int)($_SESSION['usuario-mvc-1']['id'] ?? 0);
		if ($userId > 0) {
			$user = \App\Model\Entity\User::getUserById($userId);
			if ($user instanceof \App\Model\Entity\User && (int)($user->id_admin ?? -1) === 0) {
				$_SESSION['usuario-mvc-1']['is_master'] = true;
				return true;
			}
		}
		$_SESSION['usuario-mvc-1']['is_master'] = false;
		return false;
	}

	public static function podeAcessarModulo(string $slug): bool {
		$email = (string)($_SESSION['usuario-mvc-1']['email'] ?? '');
		if (self::isMasterEmail($email)) {
			return true;
		}
		$userId = (int)($_SESSION['usuario-mvc-1']['id'] ?? 0);
		if ($userId <= 0) {
			return false;
		}
		$user = \App\Model\Entity\User::getUserById($userId);
		if (!$user instanceof \App\Model\Entity\User || (int)($user->id_admin ?? -1) !== 0) {
			return false;
		}
		$acesso = json_decode((string)($user->acesso ?? '[]'), true);
		if (!is_array($acesso) || empty($acesso)) {
			return false;
		}
		if (in_array(0, $acesso, true) || in_array('0', $acesso, true)) {
			return true;
		}
		return in_array($slug, $acesso, true);
	}

	/** @return string[] slugs de acesso do usuário logado (vazio = super-admin). */
	public static function slugsUsuarioLogado(): array {
		$email = (string)($_SESSION['usuario-mvc-1']['email'] ?? '');
		if (self::isMasterEmail($email)) {
			return \App\Common\MasterModules::getSlugs();
		}
		$userId = (int)($_SESSION['usuario-mvc-1']['id'] ?? 0);
		$user = $userId > 0 ? \App\Model\Entity\User::getUserById($userId) : null;
		if (!$user instanceof \App\Model\Entity\User) {
			return [];
		}
		$acesso = json_decode((string)($user->acesso ?? '[]'), true);
		if (!is_array($acesso)) {
			return [];
		}
		if (in_array(0, $acesso, true) || in_array('0', $acesso, true)) {
			return \App\Common\MasterModules::getSlugs();
		}
		return array_values(array_filter(array_map('strval', $acesso)));
	}
}
