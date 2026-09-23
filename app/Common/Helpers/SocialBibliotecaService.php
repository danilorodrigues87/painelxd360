<?php

namespace App\Common\Helpers;

use App\Model\Entity\SocialBiblioteca;
use App\Model\Db\Database;

class SocialBibliotecaService {

	public static function tabelaOk(): bool {
		return SocialBiblioteca::tabelaExiste();
	}

	/**
	 * @return array{success:bool,sql_ok:bool,message?:string,itens?:array,formato_col_ok?:bool}
	 */
	public static function listar(int $idAdmin, ?string $tipo = null, ?string $formato = null, int $limite = 120): array {
		if (!self::tabelaOk()) {
			return [
				'success' => false,
				'sql_ok' => false,
				'message' => 'Execute database/social_fase_a_produto.sql',
			];
		}
		SocialBiblioteca::garantirColunaFormato();
		$tipo = ($tipo === 'image' || $tipo === 'video') ? $tipo : null;
		$formato = in_array($formato, ['feed', 'story'], true) ? $formato : null;
		$itens = [];
		foreach (SocialBiblioteca::listByAdmin($idAdmin, $tipo, $formato, $limite) as $b) {
			$path = (string)($b->path_local ?? '');
			$itens[] = [
				'id' => (int)$b->id,
				'titulo' => (string)($b->titulo ?? ''),
				'tipo' => $b->tipo,
				'formato' => self::formatoExibicao($b),
				'path' => $path,
				'url' => $b->urlPublica(),
				'mime' => $b->mime,
				'bytes' => (int)($b->bytes ?? 0),
				'created_at' => $b->created_at,
				'usos' => $path !== '' ? count(self::referenciasPath($idAdmin, $path)) : 0,
			];
		}
		return [
			'success' => true,
			'sql_ok' => true,
			'formato_col_ok' => SocialBiblioteca::colunaFormatoExiste(),
			'itens' => $itens,
		];
	}

	/**
	 * @return array{success:bool,message?:string,path?:string,url?:string,tipo?:string,formato?:string,mime?:string,bytes?:int,biblioteca_id?:int|null}
	 */
	public static function upload(int $idAdmin, array $file, string $formato, ?int $userId): array {
		if (!self::tabelaOk()) {
			return ['success' => false, 'message' => 'Execute database/social_fase_a_produto.sql'];
		}
		SocialBiblioteca::garantirColunaFormato();
		$saved = SocialMediaStorage::salvarUpload($idAdmin, $file);
		if (!$saved) {
			return ['success' => false, 'message' => 'Upload inválido (use imagem ≤8MB ou vídeo ≤100MB).'];
		}
		if (!in_array($formato, ['feed', 'story'], true)) {
			$formato = $saved['tipo'] === 'image' ? 'feed' : '';
		}
		$bib = new SocialBiblioteca();
		$bib->id_admin = $idAdmin;
		$bib->titulo = trim((string)($file['name'] ?? '')) ?: null;
		$bib->tipo = $saved['tipo'];
		$bib->formato = $formato ?: null;
		$bib->path_local = $saved['relative'];
		$bib->mime = $saved['mime'];
		$bib->bytes = $saved['bytes'];
		$bib->created_by = $userId ?: null;
		$bibId = $bib->salvar();

		return [
			'success' => true,
			'path' => $saved['relative'],
			'url' => $saved['url'],
			'tipo' => $saved['tipo'],
			'formato' => $formato ?: null,
			'mime' => $saved['mime'],
			'bytes' => $saved['bytes'],
			'biblioteca_id' => $bibId,
		];
	}

	public static function salvarTitulo(int $id, int $idAdmin, string $titulo, ?string $formato = null): array {
		if (!self::tabelaOk()) {
			return ['success' => false, 'message' => 'Biblioteca indisponível.'];
		}
		SocialBiblioteca::garantirColunaFormato();
		$ob = SocialBiblioteca::getById($id, $idAdmin);
		if (!$ob) {
			return ['success' => false, 'message' => 'Mídia não encontrada.'];
		}
		$ob->titulo = trim($titulo) ?: null;
		if ($formato !== null && ($ob->tipo ?? '') === 'image') {
			$fmt = trim($formato);
			$ob->formato = in_array($fmt, ['feed', 'story'], true) ? $fmt : 'feed';
		}
		$ob->salvar();
		return [
			'success' => true,
			'message' => 'Mídia atualizada.',
			'formato' => self::formatoExibicao($ob),
		];
	}

	/** Normaliza formato para API/UI (imagens sem valor = quadrado). */
	public static function formatoExibicao(SocialBiblioteca $b): ?string {
		if (($b->tipo ?? '') !== 'image') {
			return null;
		}
		$fmt = trim((string)($b->formato ?? ''));
		return in_array($fmt, ['feed', 'story'], true) ? $fmt : 'feed';
	}

	public static function excluir(int $id, int $idAdmin): array {
		if (!self::tabelaOk()) {
			return ['success' => false, 'message' => 'Biblioteca indisponível.'];
		}
		$ob = SocialBiblioteca::getById($id, $idAdmin);
		if (!$ob) {
			return ['success' => false, 'message' => 'Mídia não encontrada.'];
		}
		$path = (string)($ob->path_local ?? '');
		if ($path !== '') {
			$usos = self::referenciasPath($idAdmin, $path);
			if ($usos) {
				return [
					'success' => false,
					'message' => 'Não é possível excluir: mídia em uso.',
					'usos' => $usos,
				];
			}
		}
		$ob->excluir();
		return ['success' => true, 'message' => 'Removida da biblioteca.'];
	}

	/** @return array{total_itens:int,total_bytes:int,total_bytes_fmt:string} */
	public static function estatisticas(int $idAdmin): array {
		if (!self::tabelaOk()) {
			return ['total_itens' => 0, 'total_bytes' => 0, 'total_bytes_fmt' => '0 B'];
		}
		$row = (new Database('social_biblioteca'))->select(
			'id_admin = '.(int)$idAdmin,
			null,
			null,
			'COUNT(*) AS qtd, COALESCE(SUM(bytes),0) AS total_bytes'
		)->fetch(\PDO::FETCH_ASSOC);
		$bytes = (int)($row['total_bytes'] ?? 0);
		return [
			'total_itens' => (int)($row['qtd'] ?? 0),
			'total_bytes' => $bytes,
			'total_bytes_fmt' => self::formatarBytes($bytes),
		];
	}

	/**
	 * @return array<int,array{tipo:string,ref:string,detalhe:string}>
	 */
	public static function referenciasPath(int $idAdmin, string $path): array {
		$path = trim($path);
		if ($path === '') {
			return [];
		}
		$usos = [];
		$esc = addslashes($path);

		try {
			$sql = '
				SELECT sp.id, sp.status, sp.agendado_em
				FROM social_post_midias m
				INNER JOIN social_posts sp ON sp.id = m.id_post AND sp.id_admin = m.id_admin
				WHERE m.id_admin = '.(int)$idAdmin.'
				  AND m.path_local = "'.$esc.'"
				LIMIT 20
			';
			$stmt = (new Database('social_post_midias'))->execute($sql);
			while ($r = $stmt->fetch(\PDO::FETCH_ASSOC)) {
				$usos[] = [
					'tipo' => 'post',
					'ref' => '#'.(int)$r['id'],
					'detalhe' => 'Post '.(string)($r['status'] ?? '').' · '.substr((string)($r['agendado_em'] ?? ''), 0, 16),
				];
			}
		} catch (\Throwable $e) {
			// tabela pode não existir
		}

		try {
			$like = addslashes(str_replace(['%', '_'], ['\\%', '\\_'], $path));
			$rows = (new Database('campanhas'))->select(
				'id_admin = '.(int)$idAdmin.' AND segmento LIKE "%'.$like.'%"',
				'id DESC',
				20,
				'id, titulo, status'
			);
			while ($r = $rows->fetch(\PDO::FETCH_ASSOC)) {
				$usos[] = [
					'tipo' => 'campanha',
					'ref' => '#'.(int)$r['id'],
					'detalhe' => trim((string)($r['titulo'] ?? '')).' ('.(string)($r['status'] ?? '').')',
				];
			}
		} catch (\Throwable $e) {
			// ignore
		}

		return $usos;
	}

	public static function formatarBytes(int $bytes): string {
		if ($bytes < 1024) {
			return $bytes.' B';
		}
		if ($bytes < 1048576) {
			return round($bytes / 1024, 1).' KB';
		}
		return round($bytes / 1048576, 2).' MB';
	}

	public static function usuarioPodeAcessar(array $modulosEfetivos): bool {
		return in_array('Redes sociais', $modulosEfetivos, true)
			|| in_array('Campanhas', $modulosEfetivos, true);
	}
}
