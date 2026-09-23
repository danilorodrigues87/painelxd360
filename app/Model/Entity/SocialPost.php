<?php

namespace App\Model\Entity;

use App\Model\Db\Database;
use PDO;

class SocialPost {

	public $id;
	public $id_admin;
	public $canais = 'ambos';
	public $formato = 'feed';
	public $caption;
	public $status = 'rascunho';
	public $agendado_em;
	public $publicado_em;
	public $fb_post_id;
	public $ig_media_id;
	public $erro_msg;
	public $created_by;
	public $created_at;
	public $updated_at;

	public static function temColunaFormato(): bool {
		static $ok = null;
		if ($ok !== null) {
			return $ok;
		}
		try {
			$row = (new Database('social_posts'))->execute(
				"SHOW COLUMNS FROM social_posts LIKE 'formato'"
			)->fetch(PDO::FETCH_ASSOC);
			$ok = !empty($row);
		} catch (\Throwable $e) {
			$ok = false;
		}
		return $ok;
	}

	public static function tabelaExiste(): bool {
		static $ok = null;
		if ($ok !== null) {
			return $ok;
		}
		try {
			$db = new Database();
			$st = $db->execute("SHOW TABLES LIKE 'social_posts'");
			$ok = $st && $st->rowCount() > 0;
		} catch (\Throwable $e) {
			$ok = false;
		}
		return $ok;
	}

	public static function getById(int $id, int $idAdmin): ?self {
		$row = (new Database('social_posts'))->select(
			'id = '.(int)$id.' AND id_admin = '.(int)$idAdmin
		)->fetchObject(self::class);
		return $row instanceof self ? $row : null;
	}

	/** @return self[] */
	public static function listSemana(int $idAdmin, string $inicioYmd, string $fimYmd): array {
		$stmt = (new Database('social_posts'))->select(
			'id_admin = '.(int)$idAdmin.'
			AND status IN ("rascunho","agendado","publicando","publicado","erro")
			AND agendado_em IS NOT NULL
			AND agendado_em >= "'.addslashes($inicioYmd).' 00:00:00"
			AND agendado_em <= "'.addslashes($fimYmd).' 23:59:59"',
			'agendado_em ASC'
		);
		$out = [];
		while ($r = $stmt->fetchObject(self::class)) {
			$out[] = $r;
		}
		return $out;
	}

	/** @return self[] */
	public static function listProntosParaPublicar(int $limite = 10, int $idAdmin = 0): array {
		$where = 'status = "agendado" AND agendado_em IS NOT NULL AND agendado_em <= NOW()';
		if ($idAdmin > 0) {
			$where .= ' AND id_admin = '.(int)$idAdmin;
		}
		$stmt = (new Database('social_posts'))->select($where, 'agendado_em ASC', (int)$limite);
		$out = [];
		while ($r = $stmt->fetchObject(self::class)) {
			$out[] = $r;
		}
		return $out;
	}

	/** @return self[] */
	public static function listMes(int $idAdmin, string $anoMes): array {
		if (!preg_match('/^\d{4}-\d{2}$/', $anoMes)) {
			$anoMes = date('Y-m');
		}
		$inicio = $anoMes.'-01';
		$fim = date('Y-m-t', strtotime($inicio.' 00:00:00'));
		return self::listSemana($idAdmin, $inicio, $fim);
	}

	/** @return self[] */
	public static function listHistorico(int $idAdmin, int $limite = 80): array {
		$stmt = (new Database('social_posts'))->select(
			'id_admin = '.(int)$idAdmin.' AND status IN ("publicado","erro","cancelado","agendado","publicando")',
			'COALESCE(publicado_em, agendado_em, created_at) DESC',
			(int)$limite
		);
		$out = [];
		while ($r = $stmt->fetchObject(self::class)) {
			$out[] = $r;
		}
		return $out;
	}

	public function salvar(): int {
		$formato = in_array($this->formato, ['feed', 'story', 'reel', 'carousel'], true)
			? $this->formato : 'feed';
		$dados = [
			'id_admin' => (int)$this->id_admin,
			'canais' => in_array($this->canais, ['facebook', 'instagram', 'ambos'], true) ? $this->canais : 'ambos',
			'caption' => $this->caption,
			'status' => in_array($this->status, ['rascunho', 'agendado', 'publicando', 'publicado', 'erro', 'cancelado'], true)
				? $this->status : 'rascunho',
			'agendado_em' => $this->agendado_em ?: null,
			'publicado_em' => $this->publicado_em ?: null,
			'fb_post_id' => $this->fb_post_id ?: null,
			'ig_media_id' => $this->ig_media_id ?: null,
			'erro_msg' => $this->erro_msg ?: null,
			'created_by' => $this->created_by ? (int)$this->created_by : null,
		];
		if (self::temColunaFormato()) {
			$dados['formato'] = $formato;
		}
		$db = new Database('social_posts');
		if (!empty($this->id)) {
			$db->update('id = '.(int)$this->id.' AND id_admin = '.(int)$this->id_admin, $dados);
			return (int)$this->id;
		}
		$this->id = (int)$db->insert($dados);
		return (int)$this->id;
	}

	/** Claim para o worker (só se ainda estiver agendado). */
	public function claimPublicando(): bool {
		$fresh = self::getById((int)$this->id, (int)$this->id_admin);
		if (!$fresh || $fresh->status !== 'agendado') {
			return false;
		}
		(new Database('social_posts'))->update(
			'id = '.(int)$this->id.' AND id_admin = '.(int)$this->id_admin.' AND status = "agendado"',
			['status' => 'publicando', 'erro_msg' => null]
		);
		$again = self::getById((int)$this->id, (int)$this->id_admin);
		if ($again && $again->status === 'publicando') {
			$this->status = 'publicando';
			return true;
		}
		return false;
	}
}
