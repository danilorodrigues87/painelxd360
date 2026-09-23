<?php

namespace App\Common\Helpers;

use App\Model\Db\Database;
use App\Model\Entity\LmsAula;
use App\Model\Entity\LmsCurso;
use App\Model\Entity\LmsModulo;

/**
 * Helpers do LMS (painel + API).
 */
class LmsHelper {

	public static function tabelasExistem(): bool {
		static $ok = null;
		if ($ok !== null) {
			return $ok;
		}
		try {
			$db = new Database();
			$stmt = $db->execute("SHOW TABLES LIKE 'lms_cursos'");
			$ok = $stmt && $stmt->rowCount() > 0;
		} catch (\Throwable $e) {
			$ok = false;
		}
		return $ok;
	}

	public static function slugify(string $text): string {
		$text = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
		$text = strtolower(preg_replace('/[^a-z0-9]+/i', '-', (string)$text) ?? '');
		return trim($text, '-') ?: 'curso';
	}

	/** Garante curso + módulo "Conteúdo" para a trilha (legado). */
	public static function garantirCursoTrilha(int $idTrilha, int $idAdmin, string $nomeTrilha = ''): ?LmsCurso {
		if (!self::tabelasExistem() || $idTrilha <= 0 || $idAdmin <= 0) {
			return null;
		}
		$curso = LmsCurso::getByTrilha($idTrilha, $idAdmin);
		if ($curso instanceof LmsCurso) {
			self::garantirModuloPadrao((int)$curso->id, $idAdmin);
			return $curso;
		}

		$curso = new LmsCurso();
		$curso->id_admin = $idAdmin;
		$curso->id_trilha = $idTrilha;
		$curso->titulo = $nomeTrilha !== '' ? $nomeTrilha : 'curso-'.$idTrilha;
		$base = self::slugify($nomeTrilha !== '' ? $nomeTrilha : 'curso-'.$idTrilha);
		$curso->slug = self::slugUnico($base, $idAdmin);
		$curso->short_description = '';
		$curso->level = 'Iniciante';
		$curso->objectives = '[]';
		$curso->publicado = 0;
		$curso->salvar();
		self::garantirModuloPadrao((int)$curso->id, $idAdmin);
		return LmsCurso::getByIdAdmin((int)$curso->id, $idAdmin);
	}

	/** Cria curso EAD independente (sem trilha). */
	public static function criarCursoIndependente(int $idAdmin, string $titulo = 'Novo curso'): ?LmsCurso {
		if (!self::tabelasExistem() || $idAdmin <= 0) {
			return null;
		}
		$titulo = trim($titulo) !== '' ? trim($titulo) : 'Novo curso';
		$curso = new LmsCurso();
		$curso->id_admin = $idAdmin;
		$curso->id_trilha = null;
		$curso->titulo = $titulo;
		$curso->slug = self::slugUnico(self::slugify($titulo), $idAdmin);
		$curso->short_description = '';
		$curso->level = 'Iniciante';
		$curso->objectives = '[]';
		$curso->publicado = 0;
		$curso->salvar();
		self::garantirModuloPadrao((int)$curso->id, $idAdmin);
		return LmsCurso::getByIdAdmin((int)$curso->id, $idAdmin);
	}

	/** Cria curso no catálogo CTI (Master). */
	public static function criarCursoCti(string $titulo = 'Novo curso CTI'): ?LmsCurso {
		$idAdmin = \App\Common\CtiCatalog::idAdmin();
		if ($idAdmin <= 0 || !self::tabelasExistem()) {
			return null;
		}
		$curso = self::criarCursoIndependente($idAdmin, $titulo);
		if (!$curso instanceof LmsCurso || !LmsCurso::temColunaOrigem()) {
			return $curso;
		}
		$curso->origem = \App\Common\CtiCatalog::ORIGEM_CTI;
		$curso->vitrine_ativo = 0;
		$curso->salvar();
		return LmsCurso::getByIdAdmin((int)$curso->id, $idAdmin);
	}

	public static function garantirModuloPadrao(int $idCurso, int $idAdmin): LmsModulo {
		$mods = LmsModulo::listByCurso($idCurso, $idAdmin);
		if (!empty($mods)) {
			return $mods[0];
		}
		$m = new LmsModulo();
		$m->id_curso = $idCurso;
		$m->id_admin = $idAdmin;
		$m->titulo = 'Conteúdo';
		$m->ordem = 0;
		$m->salvar();
		return LmsModulo::getByIdAdmin((int)$m->id, $idAdmin);
	}

	public static function slugUnico(string $base, int $idAdmin, ?int $ignoreId = null): string {
		$slug = $base;
		$i = 2;
		while (true) {
			$exist = LmsCurso::getBySlug($slug, $idAdmin);
			if (!$exist || ($ignoreId && (int)$exist->id === (int)$ignoreId)) {
				return $slug;
			}
			$slug = $base.'-'.$i;
			$i++;
		}
	}

	public static function contagemAulasCurso(int $idCurso, int $idAdmin): int {
		$n = 0;
		foreach (LmsModulo::listByCurso($idCurso, $idAdmin) as $mod) {
			$n += count(LmsAula::listByModulo((int)$mod->id, $idAdmin));
		}
		return $n;
	}

	public static function statusEad(?LmsCurso $curso, int $idAdmin): string {
		if (!$curso instanceof LmsCurso) {
			return 'sem_conteudo';
		}
		if ((int)$curso->publicado === 1) {
			return 'publicado';
		}
		if (self::contagemAulasCurso((int)$curso->id, $idAdmin) > 0) {
			return 'rascunho';
		}
		return 'sem_conteudo';
	}

	/** Extrai ID de watch / youtu.be / shorts / embed / ID puro. */
	public static function youtubeVideoId(string $url): ?string {
		$url = trim($url);
		if ($url === '') {
			return null;
		}
		if (preg_match('/^[a-zA-Z0-9_-]{11}$/', $url)) {
			return $url;
		}
		if (preg_match('/(?:youtube\.com\/(?:watch\?(?:.*&)?v=|embed\/|shorts\/|live\/)|youtu\.be\/)([a-zA-Z0-9_-]{11})/', $url, $m)) {
			return $m[1];
		}
		return null;
	}

	/** Normaliza URL YouTube para embed; private/outros passam limpos. */
	public static function normalizeVideoUrl(string $url, string $provider = 'youtube'): string {
		$url = trim($url);
		if ($url === '') {
			return '';
		}
		if ($provider !== 'youtube') {
			return $url;
		}
		$id = self::youtubeVideoId($url);
		return $id ? 'https://www.youtube.com/embed/'.$id : $url;
	}
}
