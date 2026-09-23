<?php

namespace App\Model\Entity;

use App\Model\Db\Database;

class PlatformHelpArtigo {

	public $id;
	public $id_categoria;
	public $titulo;
	public $slug;
	public $resumo;
	public $corpo;
	public $video_url;
	public $video_titulo;
	public $ordem = 0;
	public $publicado = 0;
	public $updated_at;
	public $created_at;

	public static function getById(int $id): ?self {
		$row = (new Database('platform_help_artigos'))->select('id = '.(int)$id)->fetchObject(self::class);
		return $row instanceof self ? $row : null;
	}

	public static function getBySlug(string $slug, bool $somentePublicado = false): ?self {
		$slug = addslashes($slug);
		$where = 'slug = "'.$slug.'"';
		if ($somentePublicado) {
			$where .= ' AND publicado = 1';
		}
		$row = (new Database('platform_help_artigos'))->select($where)->fetchObject(self::class);
		return $row instanceof self ? $row : null;
	}

	/** @return self[] */
	public static function listByCategoria(int $idCat, bool $somentePublicado = false): array {
		$where = 'id_categoria = '.(int)$idCat;
		if ($somentePublicado) {
			$where .= ' AND publicado = 1';
		}
		$stmt = (new Database('platform_help_artigos'))->select($where, 'ordem ASC, id ASC');
		$out = [];
		while ($r = $stmt->fetchObject(self::class)) {
			$out[] = $r;
		}
		return $out;
	}

	/** @return self[] */
	public static function listAll(bool $somentePublicado = false): array {
		$where = $somentePublicado ? 'publicado = 1' : '1=1';
		$stmt = (new Database('platform_help_artigos'))->select($where, 'ordem ASC, id ASC');
		$out = [];
		while ($r = $stmt->fetchObject(self::class)) {
			$out[] = $r;
		}
		return $out;
	}

	public function salvar(): int {
		$dados = [
			'id_categoria' => (int)$this->id_categoria,
			'titulo' => $this->titulo,
			'slug' => $this->slug,
			'resumo' => $this->resumo ?: null,
			'corpo' => $this->corpo ?: null,
			'video_url' => $this->video_url ?: null,
			'video_titulo' => $this->video_titulo ?: null,
			'ordem' => (int)$this->ordem,
			'publicado' => (int)$this->publicado ? 1 : 0,
		];
		$db = new Database('platform_help_artigos');
		if (!empty($this->id)) {
			$db->update('id = '.(int)$this->id, $dados);
			return (int)$this->id;
		}
		$this->id = (int)$db->insert($dados);
		return (int)$this->id;
	}

	public function excluir(): bool {
		return (bool)(new Database('platform_help_artigos'))->delete('id = '.(int)$this->id);
	}

	/** Converte URL YouTube/Vimeo em embed, se possível. */
	public function videoEmbedSrc(): string {
		$url = trim((string)$this->video_url);
		if ($url === '') {
			return '';
		}
		if (preg_match('~(?:youtube\.com/watch\?v=|youtu\.be/|youtube\.com/embed/)([a-zA-Z0-9_-]{6,})~', $url, $m)) {
			return 'https://www.youtube.com/embed/'.$m[1];
		}
		if (preg_match('~vimeo\.com/(?:video/)?(\d+)~', $url, $m)) {
			return 'https://player.vimeo.com/video/'.$m[1];
		}
		if (stripos($url, 'youtube.com/embed/') !== false || stripos($url, 'player.vimeo.com') !== false) {
			return $url;
		}
		return $url;
	}
}
