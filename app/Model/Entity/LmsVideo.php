<?php

namespace App\Model\Entity;

class LmsVideo extends LmsBase {

	public $id;
	public $id_aula;
	public $id_admin;
	public $titulo;
	public $url;
	public $provider = 'youtube';
	public $bunny_video_id;
	public $bunny_status;
	public $bunny_error;
	public $duracao_min = 0;
	public $ordem = 0;

	protected static function table(): string {
		return 'lms_videos';
	}

	public static function temColunasBunny(): bool {
		static $ok = null;
		if ($ok !== null) {
			return $ok;
		}
		try {
			$stmt = (new \App\Model\Db\Database())->execute("SHOW COLUMNS FROM lms_videos LIKE 'bunny_video_id'");
			$ok = $stmt && $stmt->rowCount() > 0;
		} catch (\Throwable $e) {
			$ok = false;
		}
		return $ok;
	}

	public static function listByAula(int $idAula, int $idAdmin): array {
		$stmt = self::get(
			'id_aula = '.(int)$idAula.' AND id_admin = '.(int)$idAdmin,
			'ordem ASC, id ASC'
		);
		$rows = [];
		while ($r = $stmt->fetchObject(self::class)) {
			$rows[] = $r;
		}
		return $rows;
	}

	public function salvar(): int {
		$provider = in_array($this->provider, ['youtube', 'private', 'bunny'], true)
			? $this->provider
			: 'youtube';
		$dados = [
			'id_aula' => (int)$this->id_aula,
			'id_admin' => (int)$this->id_admin,
			'titulo' => $this->titulo,
			'url' => $this->url,
			'provider' => $provider,
			'duracao_min' => (int)$this->duracao_min,
			'ordem' => (int)$this->ordem,
		];
		if (self::temColunasBunny()) {
			$dados['bunny_video_id'] = $this->bunny_video_id ?: null;
			$status = $this->bunny_status;
			$dados['bunny_status'] = in_array($status, ['uploading', 'processing', 'ready', 'error'], true)
				? $status
				: null;
			$dados['bunny_error'] = $this->bunny_error ?: null;
		}
		if (!empty($this->id)) {
			$this->updateRow((int)$this->id, (int)$this->id_admin, $dados);
			return (int)$this->id;
		}
		$this->id = $this->insertRow($dados);
		return (int)$this->id;
	}

	public function excluir(): bool {
		return $this->deleteRow((int)$this->id, (int)$this->id_admin);
	}
}
