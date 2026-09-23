<?php

namespace App\Model\Entity;

use App\Model\Db\Database;

class ProdutoLaunchToken {

	public $id;
	public $token_hash;
	public $tenant_id;
	public $id_usuario;
	public $produto_slug;
	public $expira_em;
	public $usado_em;
	public $criado_em;

	public static function tabelaExiste(): bool {
		static $ok = null;
		if ($ok !== null) {
			return $ok;
		}
		try {
			$db = new Database();
			$stmt = $db->execute("SHOW TABLES LIKE 'produto_launch_tokens'");
			$ok = $stmt && $stmt->rowCount() > 0;
		} catch (\Throwable $e) {
			$ok = false;
		}
		return $ok;
	}

	public static function criar(int $tenantId, int $idUsuario, string $produtoSlug): string {
		if (!self::tabelaExiste()) {
			throw new \RuntimeException('Tabela produto_launch_tokens não existe. Execute database/xd360_produto_launch.sql.');
		}
		$produtoSlug = trim($produtoSlug);
		if ($produtoSlug === '') {
			throw new \InvalidArgumentException('produto_slug vazio');
		}

		$plain = bin2hex(random_bytes(32));
		$hash = hash('sha256', $plain);
		$expira = date('Y-m-d H:i:s', time() + 15 * 60);

		(new Database('produto_launch_tokens'))->insert([
			'token_hash' => $hash,
			'tenant_id' => (int)$tenantId,
			'id_usuario' => (int)$idUsuario,
			'produto_slug' => $produtoSlug,
			'expira_em' => $expira,
		]);

		return $plain;
	}

	/** @return self|null */
	public static function consumir(string $plainToken) {
		if (!self::tabelaExiste()) {
			throw new \RuntimeException('Tabela produto_launch_tokens não existe.');
		}
		$plainToken = trim($plainToken);
		if ($plainToken === '') {
			return null;
		}

		$hash = hash('sha256', $plainToken);
		$hashEsc = addslashes($hash);
		$db = new Database('produto_launch_tokens');

		$row = $db->select(
			"token_hash = '{$hashEsc}'",
			null,
			'1'
		)->fetchObject(self::class);

		if (!$row instanceof self) {
			return null;
		}

		$expiraTs = strtotime((string)$row->expira_em);
		if ($expiraTs === false || $expiraTs < time()) {
			return null;
		}

		if (!empty($row->usado_em)) {
			return $row;
		}

		$agora = date('Y-m-d H:i:s');
		$db->update('id = '.(int)$row->id, ['usado_em' => $agora]);
		$row->usado_em = $agora;

		return $row;
	}
}
