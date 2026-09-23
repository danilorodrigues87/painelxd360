<?php

namespace App\Model\Entity;

use App\Model\Db\Database;

class LmsEditorToken {

	public $id;
	public $token_hash;
	public $id_admin;
	public $id_usuario;
	public $id_curso;
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
			$stmt = $db->execute("SHOW TABLES LIKE 'lms_editor_tokens'");
			$ok = $stmt && $stmt->rowCount() > 0;
		} catch (\Throwable $e) {
			$ok = false;
		}
		return $ok;
	}

	/** Cria token de 15 min; retorna o plain token uma única vez. */
	public static function criar(int $idAdmin, int $idUsuario, ?int $idCurso = null): string {
		if (!self::tabelaExiste()) {
			throw new \RuntimeException('Tabela lms_editor_tokens não existe. Execute database/lms_aulas_interativas.sql.');
		}

		$plain = bin2hex(random_bytes(32));
		$hash = hash('sha256', $plain);
		$expira = date('Y-m-d H:i:s', time() + 15 * 60);

		$dados = [
			'token_hash' => $hash,
			'id_admin' => (int)$idAdmin,
			'id_usuario' => (int)$idUsuario,
			'expira_em' => $expira,
		];
		// Evita bind NULL problemático em alguns drivers: só inclui se houver curso
		if ($idCurso !== null && $idCurso > 0) {
			$dados['id_curso'] = (int)$idCurso;
		}

		(new Database('lms_editor_tokens'))->insert($dados);

		return $plain;
	}

	/**
	 * Consome o token (marca usado_em).
	 * Se já foi usado há pouco (ex.: React Strict Mode / retry), ainda libera.
	 * @return object|null
	 */
	public static function consumir(string $plainToken) {
		if (!self::tabelaExiste()) {
			throw new \RuntimeException('Tabela lms_editor_tokens não existe.');
		}
		$plainToken = trim($plainToken);
		if ($plainToken === '') {
			return null;
		}

		$hash = hash('sha256', $plainToken);
		$hashEsc = addslashes($hash);
		$db = new Database('lms_editor_tokens');

		// Busca sem filtrar usado_em (permite retry curto)
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

		// Token válido e não expirado: permite reutilizar até expirar (Strict Mode / reabrir aba)
		if (!empty($row->usado_em)) {
			return $row;
		}

		$agora = date('Y-m-d H:i:s');
		$db->update('id = '.(int)$row->id, ['usado_em' => $agora]);
		$row->usado_em = $agora;

		return $row;
	}
}
