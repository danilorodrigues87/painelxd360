<?php

namespace App\Model\Entity;

use App\Model\Db\Database;

class WhatsappMensagem {

	public $id;
	public $id_admin;
	public $conversa_id;
	public $direction;
	public $tipo = 'text';
	public $corpo;
	public $media_url;
	public $wa_message_id;
	public $status;
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
			$stmt = $pdo->query("SHOW TABLES LIKE 'whatsapp_mensagens'");
			$cache = $stmt && $stmt->rowCount() > 0;
		} catch (\Throwable $e) {
			$cache = false;
		}
		return $cache;
	}

	public static function registrar(array $dados): ?int {
		if (!self::tabelaExiste()) {
			return null;
		}

		if (!empty($dados['wa_message_id'])) {
			$wa = addslashes((string)$dados['wa_message_id']);
			$existe = (new Database('whatsapp_mensagens'))
				->select('wa_message_id = "'.$wa.'"', null, 1, 'id')
				->fetch(\PDO::FETCH_ASSOC);
			if (!empty($existe['id'])) {
				return (int)$existe['id'];
			}
		}

		return (int)(new Database('whatsapp_mensagens'))->insert([
			'id_admin'      => (int)$dados['id_admin'],
			'conversa_id'   => (int)$dados['conversa_id'],
			'direction'     => $dados['direction'] ?? 'in',
			'tipo'          => $dados['tipo'] ?? 'text',
			'corpo'         => $dados['corpo'] ?? null,
			'media_url'     => $dados['media_url'] ?? null,
			'wa_message_id' => $dados['wa_message_id'] ?? null,
			'status'        => $dados['status'] ?? null,
			'id_usuario'    => $dados['id_usuario'] ?? null,
		]);
	}

	public static function existeWaMessageId(?string $waMessageId): bool {
		if ($waMessageId === null || trim($waMessageId) === '') {
			return false;
		}
		if (!self::tabelaExiste()) {
			return false;
		}
		$wa = addslashes(trim($waMessageId));
		$existe = (new Database('whatsapp_mensagens'))
			->select('wa_message_id = "'.$wa.'"', null, 1, 'id')
			->fetch(\PDO::FETCH_ASSOC);
		return !empty($existe['id']);
	}
}
