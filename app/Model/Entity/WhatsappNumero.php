<?php

namespace App\Model\Entity;

use App\Model\Db\Database;

/**
 * Números/instâncias WhatsApp da escola (hoje 1 default; multi-ready).
 */
class WhatsappNumero {

	public $id;
	public $id_admin;
	public $evolution_instance;
	public $numero;
	public $apelido;
	public $status = 'disconnected';
	public $ativo = 1;
	public $is_default = 1;

	public static function tabelaExiste(): bool {
		static $cache = null;
		if ($cache !== null) {
			return $cache;
		}
		try {
			$pdo = new \PDO(
				'mysql:host='.getenv('DB_HOST').';dbname='.getenv('DB_NAME').';charset=utf8mb4',
				getenv('DB_USER'),
				getenv('DB_PASS'),
				[\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]
			);
			$stmt = $pdo->query("SHOW TABLES LIKE 'whatsapp_numeros'");
			$cache = $stmt && $stmt->rowCount() > 0;
		} catch (\Throwable $e) {
			$cache = false;
		}
		return $cache;
	}

	public static function getDefault(int $idAdmin) {
		if (!self::tabelaExiste()) {
			return null;
		}
		$row = (new Database('whatsapp_numeros'))
			->select('id_admin = '.(int)$idAdmin.' AND is_default = 1', 'id ASC', 1)
			->fetchObject(self::class);
		if ($row) {
			return $row;
		}
		return (new Database('whatsapp_numeros'))
			->select('id_admin = '.(int)$idAdmin, 'id ASC', 1)
			->fetchObject(self::class) ?: null;
	}

	public static function syncFromIntegracao(int $idAdmin, string $instance, string $status, ?string $numero = null): ?self {
		if (!self::tabelaExiste()) {
			return null;
		}

		$ob = self::getDefault($idAdmin);
		$db = new Database('whatsapp_numeros');
		$dados = [
			'evolution_instance' => $instance,
			'status'             => $status,
			'ativo'              => 1,
			'is_default'         => 1,
			'apelido'            => 'Principal',
		];
		if ($numero) {
			$dados['numero'] = $numero;
		}

		if ($ob instanceof self) {
			$db->update('id = '.(int)$ob->id, $dados);
			foreach ($dados as $k => $v) {
				$ob->$k = $v;
			}
			return $ob;
		}

		$dados['id_admin'] = $idAdmin;
		$id = (int)$db->insert($dados);
		$ob = new self;
		$ob->id = $id;
		$ob->id_admin = $idAdmin;
		foreach ($dados as $k => $v) {
			$ob->$k = $v;
		}
		return $ob;
	}
}
