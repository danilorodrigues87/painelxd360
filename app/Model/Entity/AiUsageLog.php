<?php

namespace App\Model\Entity;

use App\Model\Db\Database;

class AiUsageLog {

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
			$stmt = $pdo->query("SHOW TABLES LIKE 'ai_usage_log'");
			$cache = $stmt && $stmt->rowCount() > 0;
		} catch (\Throwable $e) {
			$cache = false;
		}
		return $cache;
	}

	public static function registrar(array $data): void {
		if (!self::tabelaExiste()) {
			return;
		}
		try {
			(new Database('ai_usage_log'))->insert([
				'id_admin'           => (int)($data['id_admin'] ?? 0),
				'feature'            => mb_substr((string)($data['feature'] ?? 'chat'), 0, 64),
				'provider'           => mb_substr((string)($data['provider'] ?? ''), 0, 32),
				'model'              => mb_substr((string)($data['model'] ?? ''), 0, 128),
				'prompt_tokens'      => (int)($data['prompt_tokens'] ?? 0),
				'completion_tokens'  => (int)($data['completion_tokens'] ?? 0),
				'total_tokens'       => (int)($data['total_tokens'] ?? 0),
				'chars_in'           => (int)($data['chars_in'] ?? 0),
				'success'            => !empty($data['success']) ? 1 : 0,
				'http_status'        => (int)($data['http_status'] ?? 0),
				'latency_ms'         => (int)($data['latency_ms'] ?? 0),
				'error_snippet'      => isset($data['error_snippet'])
					? mb_substr((string)$data['error_snippet'], 0, 255)
					: null,
			]);
		} catch (\Throwable $e) {
			// Não interrompe fluxo principal
		}
	}

	/**
	 * @return array{total_calls:int,total_tokens:int,success_calls:int}
	 */
	public static function resumo(int $idAdmin, string $whereExtra = ''): array {
		if (!self::tabelaExiste()) {
			return ['total_calls' => 0, 'total_tokens' => 0, 'success_calls' => 0];
		}
		$where = 'id_admin = '.(int)$idAdmin;
		if ($whereExtra !== '') {
			$where .= ' AND '.$whereExtra;
		}
		$row = (new Database('ai_usage_log'))->select(
			$where,
			null,
			null,
			'COUNT(*) as total_calls, SUM(total_tokens) as total_tokens, SUM(success=1) as success_calls'
		)->fetchObject();
		return [
			'total_calls'   => (int)($row->total_calls ?? 0),
			'total_tokens'  => (int)($row->total_tokens ?? 0),
			'success_calls' => (int)($row->success_calls ?? 0),
		];
	}

	public static function listar(int $idAdmin, string $whereExtra, ?string $limit = null): \PDOStatement {
		$where = 'id_admin = '.(int)$idAdmin;
		if ($whereExtra !== '') {
			$where .= ' AND '.$whereExtra;
		}
		return (new Database('ai_usage_log'))->select($where, 'created_at DESC', $limit);
	}
}
