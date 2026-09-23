<?php

namespace App\Model\Entity;

use App\Model\Db\Database;

class MetaMensagem {

	public $id;
	public $id_admin;
	public $conversa_id;
	public $direction;
	public $tipo = 'text';
	public $corpo;
	public $anexo_json;
	public $meta_message_id;
	public $status_envio;
	public $id_usuario;
	public $created_at;

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
			$stmt = $pdo->query("SHOW TABLES LIKE 'meta_mensagens'");
			$cache = $stmt && $stmt->rowCount() > 0;
		} catch (\Throwable $e) {
			$cache = false;
		}
		return $cache;
	}

	public static function existeMessageId(?string $metaMessageId): bool {
		if ($metaMessageId === null || trim($metaMessageId) === '') {
			return false;
		}
		if (!self::tabelaExiste()) {
			return false;
		}
		$mid = addslashes(trim($metaMessageId));
		$row = (new Database('meta_mensagens'))
			->select('meta_message_id = "'.$mid.'"', null, 1, 'id')
			->fetch(\PDO::FETCH_ASSOC);
		return !empty($row['id']);
	}

	public static function registrar(array $dados): ?int {
		if (!self::tabelaExiste()) {
			return null;
		}

		$mid = trim((string)($dados['meta_message_id'] ?? ''));
		if ($mid !== '' && self::existeMessageId($mid)) {
			$row = (new Database('meta_mensagens'))
				->select('meta_message_id = "'.addslashes($mid).'"', null, 1, 'id')
				->fetch(\PDO::FETCH_ASSOC);
			return !empty($row['id']) ? (int)$row['id'] : null;
		}

		return (int)(new Database('meta_mensagens'))->insert([
			'id_admin'         => (int)$dados['id_admin'],
			'conversa_id'      => (int)$dados['conversa_id'],
			'direction'        => ($dados['direction'] ?? 'in') === 'out' ? 'out' : 'in',
			'tipo'             => (string)($dados['tipo'] ?? 'text'),
			'corpo'            => $dados['corpo'] ?? null,
			'anexo_json'       => $dados['anexo_json'] ?? null,
			'meta_message_id'  => $mid !== '' ? $mid : null,
			'status_envio'     => $dados['status_envio'] ?? null,
			'id_usuario'       => !empty($dados['id_usuario']) ? (int)$dados['id_usuario'] : null,
		]);
	}
}
