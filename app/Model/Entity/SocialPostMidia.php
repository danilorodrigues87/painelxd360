<?php

namespace App\Model\Entity;

use App\Model\Db\Database;

class SocialPostMidia {

	public $id;
	public $id_post;
	public $id_admin;
	public $tipo = 'image';
	public $path_local;
	public $url_externa;
	public $mime;
	public $bytes;
	public $ordem = 0;
	public $created_at;

	/** @return self[] */
	public static function listByPost(int $idPost, int $idAdmin): array {
		$stmt = (new Database('social_post_midias'))->select(
			'id_post = '.(int)$idPost.' AND id_admin = '.(int)$idAdmin,
			'ordem ASC, id ASC'
		);
		$out = [];
		while ($r = $stmt->fetchObject(self::class)) {
			$out[] = $r;
		}
		return $out;
	}

	public function salvar(): int {
		$dados = [
			'id_post' => (int)$this->id_post,
			'id_admin' => (int)$this->id_admin,
			'tipo' => in_array($this->tipo, ['image', 'video'], true) ? $this->tipo : 'image',
			'path_local' => $this->path_local ?: null,
			'url_externa' => $this->url_externa ?: null,
			'mime' => $this->mime ?: null,
			'bytes' => $this->bytes !== null ? (int)$this->bytes : null,
			'ordem' => (int)$this->ordem,
		];
		$db = new Database('social_post_midias');
		if (!empty($this->id)) {
			$db->update('id = '.(int)$this->id.' AND id_admin = '.(int)$this->id_admin, $dados);
			return (int)$this->id;
		}
		$this->id = (int)$db->insert($dados);
		return (int)$this->id;
	}

	public function excluir(): bool {
		return (bool)(new Database('social_post_midias'))->delete(
			'id = '.(int)$this->id.' AND id_admin = '.(int)$this->id_admin
		);
	}

	public function urlPublica(): string {
		if (!empty($this->url_externa)) {
			return (string)$this->url_externa;
		}
		if (!empty($this->path_local)) {
			return \App\Common\Helpers\SocialMediaStorage::urlPublica((string)$this->path_local);
		}
		return '';
	}
}
