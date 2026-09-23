<?php

namespace App\Model\Entity;

use App\Model\Db\Database;
use PDO;

class StaffPushSubscription {

	public $id;
	public $id_usuario;
	public $id_admin;
	public $subscription_id;
	public $updated_at;

	public static function tabelaExiste(): bool {
		static $ok = null;
		if ($ok !== null) {
			return $ok;
		}
		try {
			$db = new Database();
			$stmt = $db->execute("SHOW TABLES LIKE 'staff_push_subscriptions'");
			$ok = $stmt && $stmt->rowCount() > 0;
		} catch (\Throwable $e) {
			$ok = false;
		}
		return $ok;
	}

	public static function salvar(int $idUsuario, int $idAdmin, string $subscriptionId): bool {
		if (!self::tabelaExiste() || $idUsuario <= 0 || $idAdmin <= 0) {
			return false;
		}
		$sub = trim($subscriptionId);
		if ($sub === '' || strlen($sub) > 64) {
			return false;
		}

		$db = new Database('staff_push_subscriptions');
		$existe = $db->select(
			'id_usuario = '.(int)$idUsuario.' AND id_admin = '.(int)$idAdmin,
			null,
			'1',
			'id'
		)->fetch(PDO::FETCH_ASSOC);

		if (!empty($existe['id'])) {
			$db->update('id = '.(int)$existe['id'], [
				'subscription_id' => $sub,
			]);
			return true;
		}

		$id = $db->insert([
			'id_usuario'      => $idUsuario,
			'id_admin'        => $idAdmin,
			'subscription_id' => $sub,
		]);
		return (bool)$id;
	}
}
