<?php

namespace App\Model\Entity;

use App\Model\Db\Database;

class EmailAniversarioLog {

	public static function tabelaExiste(): bool {
		try {
			$pdo = new \PDO(
				'mysql:host='.getenv('DB_HOST').';dbname='.getenv('DB_NAME').';charset=utf8mb4',
				getenv('DB_USER'),
				getenv('DB_PASS'),
				[\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]
			);
			$stmt = $pdo->query("SHOW TABLES LIKE 'email_aniversario_log'");
			return $stmt && $stmt->rowCount() > 0;
		} catch (\Throwable $e) {
			return false;
		}
	}

	public static function jaEnviou(int $usuarioId, int $ano): bool {
		if (!self::tabelaExiste()) {
			return false;
		}
		$row = (new Database('email_aniversario_log'))->select(
			'usuario_id = '.(int)$usuarioId.' AND ano = '.(int)$ano,
			null,
			1,
			'id'
		)->fetch(\PDO::FETCH_ASSOC);
		return !empty($row);
	}

	public static function registrar(int $idAdmin, int $usuarioId, int $ano, string $email): void {
		if (!self::tabelaExiste()) {
			return;
		}
		(new Database('email_aniversario_log'))->insert([
			'id_admin'      => $idAdmin,
			'usuario_id'    => $usuarioId,
			'ano'           => $ano,
			'email_destino' => $email,
		]);
	}

	public static function contarHoje(int $idAdmin): int {
		if (!self::tabelaExiste()) {
			return 0;
		}
		$row = (new Database('email_aniversario_log'))->select(
			'id_admin = '.(int)$idAdmin.' AND DATE(enviado_em) = CURDATE()',
			null,
			null,
			'COUNT(*) AS qtd'
		)->fetch(\PDO::FETCH_ASSOC);
		return (int)($row['qtd'] ?? 0);
	}
}
