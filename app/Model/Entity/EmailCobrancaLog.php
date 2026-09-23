<?php

namespace App\Model\Entity;

use App\Model\Db\Database;

class EmailCobrancaLog {

	public $id;
	public $id_admin;
	public $caixa_id;
	public $tipo;
	public $dias;
	public $email_destino;
	public $enviado_em;

	public static function tabelaExiste(): bool {
		try {
			$pdo = new \PDO(
				'mysql:host='.getenv('DB_HOST').';dbname='.getenv('DB_NAME').';charset=utf8mb4',
				getenv('DB_USER'),
				getenv('DB_PASS'),
				[\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]
			);
			$stmt = $pdo->query("SHOW TABLES LIKE 'email_cobranca_log'");
			return $stmt && $stmt->rowCount() > 0;
		} catch (\Throwable $e) {
			return false;
		}
	}

	public static function jaEnviou(int $caixaId, string $tipo, int $dias): bool {
		if (!self::tabelaExiste()) {
			return false;
		}
		$row = (new Database('email_cobranca_log'))->select(
			'caixa_id = '.(int)$caixaId.' AND tipo = "'.addslashes($tipo).'" AND dias = '.(int)$dias,
			null,
			1,
			'id'
		)->fetch(\PDO::FETCH_ASSOC);
		return !empty($row);
	}

	public static function registrar(int $idAdmin, int $caixaId, string $tipo, int $dias, string $email): void {
		if (!self::tabelaExiste()) {
			return;
		}
		$db = new Database('email_cobranca_log');
		$db->insert([
			'id_admin'      => $idAdmin,
			'caixa_id'      => $caixaId,
			'tipo'          => $tipo,
			'dias'          => $dias,
			'email_destino' => $email,
		]);
	}

	public static function contarHoje(int $idAdmin): int {
		if (!self::tabelaExiste()) {
			return 0;
		}
		$row = (new Database('email_cobranca_log'))->select(
			'id_admin = '.(int)$idAdmin.' AND DATE(enviado_em) = CURDATE()',
			null,
			null,
			'COUNT(*) AS qtd'
		)->fetch(\PDO::FETCH_ASSOC);
		return (int)($row['qtd'] ?? 0);
	}
}
