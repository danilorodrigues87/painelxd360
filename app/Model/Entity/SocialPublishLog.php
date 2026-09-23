<?php

namespace App\Model\Entity;

use App\Model\Db\Database;

class SocialPublishLog {

	public $id;
	public $id_admin;
	public $id_post;
	public $origem;
	public $status;
	public $mensagem;
	public $fb_post_id;
	public $ig_media_id;
	public $formato;
	public $canais;
	public $created_at;

	public static function tabelaExiste(): bool {
		static $ok = null;
		if ($ok !== null) {
			return $ok;
		}
		try {
			$st = (new Database())->execute("SHOW TABLES LIKE 'social_publish_log'");
			$ok = $st && $st->rowCount() > 0;
		} catch (\Throwable $e) {
			$ok = false;
		}
		return $ok;
	}

	public static function registrar(array $dados): void {
		if (!self::tabelaExiste()) {
			return;
		}
		try {
			(new Database('social_publish_log'))->insert([
				'id_admin' => (int)($dados['id_admin'] ?? 0),
				'id_post' => (int)($dados['id_post'] ?? 0),
				'origem' => mb_substr((string)($dados['origem'] ?? 'worker'), 0, 20),
				'status' => mb_substr((string)($dados['status'] ?? 'ok'), 0, 20),
				'mensagem' => isset($dados['mensagem']) ? mb_substr((string)$dados['mensagem'], 0, 500) : null,
				'fb_post_id' => $dados['fb_post_id'] ?? null,
				'ig_media_id' => $dados['ig_media_id'] ?? null,
				'formato' => $dados['formato'] ?? null,
				'canais' => $dados['canais'] ?? null,
			]);
		} catch (\Throwable $e) {
			// ignore
		}
	}

	/** @return array<int,array> */
	public static function listByAdmin(int $idAdmin, int $limite = 50): array {
		if (!self::tabelaExiste()) {
			return [];
		}
		$stmt = (new Database('social_publish_log'))->select(
			'id_admin = '.(int)$idAdmin,
			'id DESC',
			(int)$limite
		);
		$out = [];
		while ($r = $stmt->fetch(\PDO::FETCH_ASSOC)) {
			$out[] = $r;
		}
		return $out;
	}
}
