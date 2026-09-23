<?php

namespace App\Controller\Api\Editor;

use App\Model\Entity\LmsCurso;
use App\Model\Entity\LmsModulo;
use App\Model\Entity\LmsAula;
use App\Model\Entity\LmsAulaCena;

class Aulas {

	private static function ok($data, int $code = 200): array {
		return [
			'code' => $code,
			'json' => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
		];
	}

	private static function err(string $msg, int $code = 400): array {
		return self::ok(['message' => $msg], $code);
	}

	private static function idAdmin($request): int {
		return (int)($request->editorIdAdmin ?? 0);
	}

	/** Cria aula interativa no curso (módulo padrão). Body: { title, idCurso?, description? } */
	public static function criar($request) {
		$idAdmin = self::idAdmin($request);
		if ($idAdmin <= 0) {
			return self::err('Tenant inválido.', 403);
		}
		if (!LmsAula::temColunaInterativa()) {
			return self::err('Colunas interativas ausentes. Execute database/lms_aulas_interativas.sql.', 501);
		}

		$post = $request->getPostVars();
		if (!is_array($post) || empty($post)) {
			$raw = file_get_contents('php://input');
			$decoded = is_string($raw) ? json_decode($raw, true) : null;
			$post = is_array($decoded) ? $decoded : [];
		}

		$claims = is_array($request->jwtClaims ?? null) ? $request->jwtClaims : [];
		$idCurso = (int)($post['idCurso'] ?? $post['id_curso'] ?? $claims['id_curso'] ?? 0);
		if ($idCurso <= 0) {
			return self::err('Informe o curso (abra o editor a partir do painel do curso).');
		}

		$curso = LmsCurso::getByIdAdmin($idCurso, $idAdmin);
		if (!$curso) {
			return self::err('Curso não encontrado.', 404);
		}

		$mod = \App\Common\Helpers\LmsHelper::garantirModuloPadrao($idCurso, $idAdmin);
		$aula = new LmsAula();
		$aula->id_modulo = (int)$mod->id;
		$aula->id_admin = $idAdmin;
		$titulo = trim((string)($post['title'] ?? $post['titulo'] ?? 'Nova aula interativa'));
		$aula->titulo = $titulo !== '' ? $titulo : 'Nova aula interativa';
		$aula->descricao = trim((string)($post['description'] ?? $post['descricao'] ?? ''));
		$aula->ordem = 0;
		$aula->bloqueado = 0;
		$aula->tipo_conteudo = 'interativa';
		$aula->interativa_status = 'rascunho';
		$aula->voz_narracao = trim((string)($post['voice'] ?? 'alloy')) ?: 'alloy';
		$id = (int)$aula->salvar();
		if ($id <= 0) {
			return self::err('Falha ao criar aula.', 500);
		}

		return self::getAula($request, (string)$id);
	}

	/** Lista cursos do tenant com resumo das aulas. */
	public static function listar($request) {
		$idAdmin = self::idAdmin($request);
		if ($idAdmin <= 0) {
			return self::err('Tenant inválido.', 403);
		}

		$order = LmsCurso::temColunaTitulo() ? 'titulo ASC, id DESC' : 'id DESC';
		$stmt = LmsCurso::get('id_admin = '.$idAdmin, $order);
		$courses = [];
		while ($curso = $stmt->fetchObject(LmsCurso::class)) {
			$aulas = [];
			foreach (LmsModulo::listByCurso((int)$curso->id, $idAdmin) as $mod) {
				foreach (LmsAula::listByModulo((int)$mod->id, $idAdmin) as $aula) {
					$scenesCount = 0;
					$tipo = 'video';
					$status = null;
					if (LmsAula::temColunaInterativa()) {
						$tipo = (string)($aula->tipo_conteudo ?? 'video');
						$status = (string)($aula->interativa_status ?? 'rascunho');
					}
					if ($tipo === 'interativa' && LmsAulaCena::tabelaExiste()) {
						$scenesCount = count(LmsAulaCena::listByAula((int)$aula->id, $idAdmin));
					}
					$aulas[] = [
						'id' => (string)$aula->id,
						'title' => (string)$aula->titulo,
						'moduleId' => (string)$mod->id,
						'moduleTitle' => (string)$mod->titulo,
						'tipoConteudo' => $tipo,
						'interativaStatus' => $status,
						'scenesCount' => $scenesCount,
					];
				}
			}
			$courses[] = [
				'id' => (string)$curso->id,
				'title' => $curso->nomeExibicao(),
				'aulas' => $aulas,
			];
		}

		return self::ok(['courses' => $courses]);
	}

	/** Tutorial completo para o L-Editor. */
	public static function getAula($request, $id) {
		$idAdmin = self::idAdmin($request);
		$aula = LmsAula::getByIdAdmin((int)$id, $idAdmin);
		if (!$aula instanceof LmsAula) {
			return self::err('Aula não encontrada.', 404);
		}

		$cursoNome = '';
		$mod = LmsModulo::getByIdAdmin((int)$aula->id_modulo, $idAdmin);
		if ($mod && !empty($mod->id_curso)) {
			$curso = LmsCurso::getByIdAdmin((int)$mod->id_curso, $idAdmin);
			if ($curso) {
				$cursoNome = $curso->nomeExibicao();
			}
		}

		$scenes = [];
		$updatedAt = null;
		foreach (LmsAulaCena::listByAula((int)$aula->id, $idAdmin) as $cena) {
			$interacao = $cena->interacao;
			if (is_string($interacao)) {
				$decoded = json_decode($interacao, true);
				$interacao = is_array($decoded) ? $decoded : [];
			}
			if (!is_array($interacao)) {
				$interacao = [];
			}
			if (!empty($interacao['object']) && is_string($interacao['object'])) {
				$obj = trim($interacao['object']);
				if ($obj !== '' && preg_match('#^https?://#i', $obj)) {
					$objClient = \App\Common\Helpers\BunnyStorageHelper::clientMediaUrl($obj, 'image');
					if ($objClient !== null && $objClient !== '') {
						$interacao['object'] = $objClient;
					}
				}
			}
			$src = (string)$cena->media_url;
			$bunnyVid = trim((string)($cena->media_bunny_video_id ?? ''));
			if ($bunnyVid !== '') {
				$play = \App\Common\Helpers\BunnyStreamHelper::urlPlayback($idAdmin, $bunnyVid, 7200);
				if (!empty($play['playbackUrl'])) {
					$src = (string)$play['playbackUrl'];
				}
			} elseif ($src !== '') {
				$kind = (string)($cena->media_kind ?: 'image');
				$client = \App\Common\Helpers\BunnyStorageHelper::clientMediaUrl($src, $kind);
				if ($client !== null && $client !== '') {
					$src = $client;
				}
			}
			$item = [
				'id' => (string)$cena->id,
				'media' => [
					'kind' => (string)($cena->media_kind ?: 'image'),
					'src' => $src,
				],
				'autoAdvance' => !empty($cena->auto_advance),
				'instruction' => (string)($cena->instrucao ?? ''),
				'hideInstructionBox' => LmsAulaCena::temColunaOcultarInstrucao() && !empty($cena->ocultar_instrucao),
				'tone' => (string)($cena->tone ?: 'light'),
				'interaction' => $interacao,
			];
			if (LmsAulaCena::temColunaAutoNarracao() && $cena->auto_narracao !== null && $cena->auto_narracao !== '') {
				$item['autoNarration'] = !empty($cena->auto_narracao);
			}
			if (LmsAulaCena::temColunaDelayRevelar() && $cena->delay_revelar_ms !== null && $cena->delay_revelar_ms !== '') {
				$item['revealDelayMs'] = max(0, (int)$cena->delay_revelar_ms);
			}
			if (LmsAulaCena::temColunaDuracaoMs() && $cena->duracao_ms !== null && $cena->duracao_ms !== '') {
				$item['sceneDurationMs'] = max(0, (int)$cena->duracao_ms);
			}
			if (!empty($cena->narracao_url)) {
				$narr = (string)$cena->narracao_url;
				$client = \App\Common\Helpers\BunnyStorageHelper::clientMediaUrl($narr, 'audio');
				$item['narrationUrl'] = ($client !== null && $client !== '') ? $client : $narr;
			}
			if ($bunnyVid !== '') {
				$item['mediaBunnyVideoId'] = $bunnyVid;
			}
			$scenes[] = $item;
			if (!empty($cena->atualizado_em) && ($updatedAt === null || $cena->atualizado_em > $updatedAt)) {
				$updatedAt = $cena->atualizado_em;
			}
		}

		$voice = 'alloy';
		if (LmsAula::temColunaInterativa() && !empty($aula->voz_narracao)) {
			$voice = (string)$aula->voz_narracao;
		}

		return self::ok([
			'id' => (string)$aula->id,
			'title' => (string)$aula->titulo,
			'course' => $cursoNome,
			'description' => (string)($aula->descricao ?? ''),
			'voice' => $voice,
			'scenes' => $scenes,
			'updatedAt' => $updatedAt ? date('c', strtotime($updatedAt)) : null,
			'interactiveStatus' => LmsAula::temColunaInterativa()
				? (string)($aula->interativa_status ?? 'rascunho')
				: null,
			'autoNarration' => LmsAula::temColunaInterativaAutoNarracao()
				? !empty($aula->interativa_auto_narracao)
				: true,
			'defaultRevealDelayMs' => LmsAula::temColunaInterativaDelayMs()
				? max(0, (int)($aula->interativa_delay_ms ?? 2000))
				: 2000,
			'defaultSceneDurationMs' => LmsAula::temColunaInterativaDuracaoMs()
				? max(0, (int)($aula->interativa_duracao_ms ?? 4000))
				: 4000,
		]);
	}

	/** Salva tutorial (PUT). Body: Tutorial do front. */
	public static function salvarAula($request, $id) {
		$idAdmin = self::idAdmin($request);
		$aula = LmsAula::getByIdAdmin((int)$id, $idAdmin);
		if (!$aula instanceof LmsAula) {
			return self::err('Aula não encontrada.', 404);
		}
		if (!LmsAula::temColunaInterativa()) {
			return self::err('Colunas interativas ausentes. Execute database/lms_aulas_interativas.sql.', 501);
		}

		$body = $request->getPostVars() ?: [];
		if (!is_array($body)) {
			$body = [];
		}

		if (isset($body['title'])) {
			$aula->titulo = trim((string)$body['title']);
		}
		if (array_key_exists('description', $body)) {
			$aula->descricao = (string)($body['description'] ?? '');
		}
		if (isset($body['voice'])) {
			$aula->voz_narracao = trim((string)$body['voice']) ?: 'alloy';
		}

		$status = (string)($body['interactiveStatus'] ?? $body['status'] ?? $aula->interativa_status ?? 'rascunho');
		if ($status === 'published' || $status === 'publicada') {
			$aula->interativa_status = 'publicada';
		} else {
			$aula->interativa_status = 'rascunho';
		}
		if (LmsAula::temColunaInterativaAutoNarracao() && array_key_exists('autoNarration', $body)) {
			$aula->interativa_auto_narracao = !empty($body['autoNarration']) ? 1 : 0;
		}
		if (LmsAula::temColunaInterativaDelayMs() && array_key_exists('defaultRevealDelayMs', $body)) {
			$ms = (int)($body['defaultRevealDelayMs'] ?? 2000);
			$aula->interativa_delay_ms = max(0, min(60000, $ms));
		}
		if (LmsAula::temColunaInterativaDuracaoMs() && array_key_exists('defaultSceneDurationMs', $body)) {
			$dur = (int)($body['defaultSceneDurationMs'] ?? 4000);
			$aula->interativa_duracao_ms = max(0, min(120000, $dur));
		}
		$aula->tipo_conteudo = 'interativa';
		$aula->salvar();

		$oldCenas = LmsAulaCena::listByAula((int)$aula->id, $idAdmin);
		$oldAssets = self::collectSceneAssets($oldCenas);

		$scenesIn = $body['scenes'] ?? [];
		if (!is_array($scenesIn)) {
			$scenesIn = [];
		}
		$normalized = [];
		foreach ($scenesIn as $sc) {
			if (!is_array($sc)) {
				continue;
			}
			$media = $sc['media'] ?? [];
			if (!is_array($media)) {
				$media = [];
			}
			$kind = (string)($media['kind'] ?? $sc['media_kind'] ?? 'image');
			$src = trim((string)($media['src'] ?? $sc['media_url'] ?? ''));
			if ($src !== '' && $kind !== 'video') {
				$src = self::urlParaBanco($src);
			}
			$interacao = $sc['interaction'] ?? $sc['interacao'] ?? [];
			if (!is_array($interacao)) {
				$interacao = [];
			}
			if (!empty($interacao['object']) && is_string($interacao['object'])) {
				$obj = trim($interacao['object']);
				if ($obj !== '' && preg_match('#^https?://#i', $obj)) {
					$interacao['object'] = self::urlParaBanco($obj);
				}
			}
			$narracao = $sc['narrationUrl'] ?? $sc['narracaoUrl'] ?? $sc['narracao_url'] ?? null;
			if (is_string($narracao)) {
				$narracao = trim($narracao);
			}
			if ($narracao === '' || $narracao === null) {
				$narracao = null;
			} else {
				$narracao = self::urlParaBanco((string)$narracao);
			}
			$bunnyVid = $sc['mediaBunnyVideoId'] ?? $sc['media_bunny_video_id'] ?? null;
			if (is_string($bunnyVid)) {
				$bunnyVid = trim($bunnyVid) !== '' ? trim($bunnyVid) : null;
			}
			$row = [
				'id' => (string)($sc['id'] ?? ''),
				'media_kind' => $kind,
				'media_url' => $src,
				'auto_advance' => !empty($sc['autoAdvance'] ?? $sc['auto_advance'] ?? false),
				'instrucao' => (string)($sc['instruction'] ?? $sc['instrucao'] ?? ''),
				'ocultar_instrucao' => !empty($sc['hideInstructionBox'] ?? $sc['ocultar_instrucao'] ?? false),
				'tone' => (string)($sc['tone'] ?? 'light'),
				'interacao' => $interacao,
				'narracao_url' => $narracao,
				'media_bunny_video_id' => $bunnyVid,
			];
			if (LmsAulaCena::temColunaAutoNarracao() && array_key_exists('autoNarration', $sc)) {
				if ($sc['autoNarration'] === null || $sc['autoNarration'] === '') {
					$row['auto_narracao'] = null;
				} else {
					$row['auto_narracao'] = !empty($sc['autoNarration']) ? 1 : 0;
				}
			}
			if (LmsAulaCena::temColunaDelayRevelar() && array_key_exists('revealDelayMs', $sc)) {
				if ($sc['revealDelayMs'] === null || $sc['revealDelayMs'] === '') {
					$row['delay_revelar_ms'] = null;
				} else {
					$row['delay_revelar_ms'] = max(0, min(60000, (int)$sc['revealDelayMs']));
				}
			}
			if (LmsAulaCena::temColunaDuracaoMs() && array_key_exists('sceneDurationMs', $sc)) {
				if ($sc['sceneDurationMs'] === null || $sc['sceneDurationMs'] === '') {
					$row['duracao_ms'] = null;
				} else {
					$row['duracao_ms'] = max(0, min(120000, (int)$sc['sceneDurationMs']));
				}
			}
			$normalized[] = $row;
		}

		try {
			LmsAulaCena::replaceAllForAula((int)$aula->id, $idAdmin, $normalized);
		} catch (\Throwable $e) {
			return self::err('Falha ao salvar cenas: '.$e->getMessage(), 500);
		}

		$newAssets = self::collectNormalizedAssets($normalized);
		self::purgeOrphanAssets($idAdmin, $oldAssets, $newAssets);

		return self::getAula($request, $id);
	}

	/**
	 * URL de mídia Storage para gravar no banco: sempre CDN canônica.
	 * Nunca grava URL de proxy (o token expira e a mídia fica irrecuperável).
	 */
	private static function urlParaBanco(string $url): string {
		$helper = \App\Common\Helpers\BunnyStorageHelper::class;
		$path = $helper::resolveInterativaPath($url);
		$out = $url;
		if ($path !== null) {
			$cdn = $helper::publicUrl($path);
			if ($cdn !== '') {
				$out = $cdn;
			}
		} elseif (str_contains($url, '/bunny-file')) {
			// Proxy sem token resolvível: URL morta, não persistir
			$out = '';
		}
		return $out;
	}

	/**
	 * @param iterable<object> $cenas
	 * @return array{urls:array<string,true>,videos:array<string,true>}
	 */
	private static function collectSceneAssets($cenas): array {
		$urls = [];
		$videos = [];
		foreach ($cenas as $cena) {
			foreach (self::urlsFromCenaRow($cena) as $u) {
				$urls[$u] = true;
			}
			$vid = trim((string)($cena->media_bunny_video_id ?? ''));
			if ($vid !== '') {
				$videos[$vid] = true;
			}
		}
		return ['urls' => $urls, 'videos' => $videos];
	}

	/**
	 * @param array<int,array<string,mixed>> $normalized
	 * @return array{urls:array<string,true>,videos:array<string,true>}
	 */
	private static function collectNormalizedAssets(array $normalized): array {
		$urls = [];
		$videos = [];
		foreach ($normalized as $sc) {
			$mediaUrl = trim((string)($sc['media_url'] ?? ''));
			if ($mediaUrl !== '') {
				$urls[$mediaUrl] = true;
			}
			$narr = trim((string)($sc['narracao_url'] ?? ''));
			if ($narr !== '') {
				$urls[$narr] = true;
			}
			$inter = $sc['interacao'] ?? [];
			if (is_array($inter) && !empty($inter['object']) && is_string($inter['object'])) {
				$obj = trim($inter['object']);
				if ($obj !== '' && preg_match('#^https?://#i', $obj)) {
					$urls[$obj] = true;
				}
			}
			$vid = trim((string)($sc['media_bunny_video_id'] ?? ''));
			if ($vid !== '') {
				$videos[$vid] = true;
			}
		}
		return ['urls' => $urls, 'videos' => $videos];
	}

	/** @return list<string> */
	private static function urlsFromCenaRow(object $cena): array {
		$out = [];
		$media = trim((string)($cena->media_url ?? ''));
		if ($media !== '' && preg_match('#^https?://#i', $media)) {
			$out[] = $media;
		}
		$narr = trim((string)($cena->narracao_url ?? ''));
		if ($narr !== '' && preg_match('#^https?://#i', $narr)) {
			$out[] = $narr;
		}
		$interacao = $cena->interacao ?? null;
		if (is_string($interacao)) {
			$decoded = json_decode($interacao, true);
			$interacao = is_array($decoded) ? $decoded : [];
		}
		if (is_array($interacao) && !empty($interacao['object']) && is_string($interacao['object'])) {
			$obj = trim($interacao['object']);
			if ($obj !== '' && preg_match('#^https?://#i', $obj)) {
				$out[] = $obj;
			}
		}
		return $out;
	}

	/**
	 * @param array{urls:array<string,true>,videos:array<string,true>} $old
	 * @param array{urls:array<string,true>,videos:array<string,true>} $new
	 */
	private static function purgeOrphanAssets(int $idAdmin, array $old, array $new): void {
		$helper = \App\Common\Helpers\BunnyStorageHelper::class;
		// Compara por path do Storage: URL de proxy e URL de CDN apontam para o mesmo arquivo
		$emUso = [];
		foreach (array_keys($new['urls']) as $url) {
			$p = $helper::resolveInterativaPath((string)$url);
			if ($p !== null) {
				$emUso[$p] = true;
			}
		}
		foreach (array_keys($old['urls']) as $url) {
			$path = $helper::resolveInterativaPath((string)$url);
			// Não resolveu o path: nunca apagar às cegas
			if ($path === null || isset($emUso[$path])) {
				continue;
			}
			try {
				$helper::delete($path);
			} catch (\Throwable $e) {
				/* best-effort */
			}
		}
		foreach (array_keys($old['videos']) as $vid) {
			if (isset($new['videos'][$vid])) {
				continue;
			}
			try {
				\App\Common\Helpers\BunnyStreamHelper::excluirVideo($idAdmin, (string)$vid);
			} catch (\Throwable $e) {
				/* best-effort */
			}
		}
	}

	/** Upload multipart → Bunny Storage (imagem/áudio) ou Stream (vídeo). */
	public static function upload($request) {
		$idAdmin = self::idAdmin($request);
		if ($idAdmin <= 0) {
			return self::err('Tenant inválido.', 403);
		}

		$files = $request->getFileVars();
		$file = $files['file'] ?? $files['media'] ?? $files['upload'] ?? null;
		if (!is_array($file) || empty($file['name']) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
			return self::err('Envie um arquivo (campo file).');
		}

		$size = (int)($file['size'] ?? 0);
		if ($size <= 0) {
			return self::err('Arquivo vazio.');
		}

		$orig = (string)$file['name'];
		$ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
		$imageExt = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
		$audioExt = ['mp3', 'wav', 'ogg', 'm4a'];
		$videoExt = ['mp4', 'webm', 'mov'];
		$allowed = array_merge($imageExt, $audioExt, $videoExt);
		if ($ext === '' || !in_array($ext, $allowed, true)) {
			return self::err('Extensão não permitida.');
		}

		$tmp = (string)$file['tmp_name'];
		$mimeBrowser = (string)($file['type'] ?? '');
		$mime = \App\Common\Helpers\BunnyStorageHelper::mimeFromExtension($ext, $mimeBrowser ?: 'application/octet-stream');
		$isVideo = in_array($ext, $videoExt, true);
		$isAudio = in_array($ext, $audioExt, true);

		if ($isVideo) {
			if ($size > 500 * 1024 * 1024) {
				return self::err('Vídeo deve ter no máximo 500 MB.');
			}
			if (!\App\Common\Helpers\BunnyStreamHelper::pronto($idAdmin)) {
				return self::err('Bunny Stream não configurado no Master.', 503);
			}
			$created = \App\Common\Helpers\BunnyStreamHelper::criarVideo($idAdmin, pathinfo($orig, PATHINFO_FILENAME) ?: 'Cena');
			if (empty($created['ok'])) {
				return self::err($created['message'] ?? 'Falha ao criar vídeo no Stream.', 502);
			}
			$guid = (string)$created['videoId'];
			$up = \App\Common\Helpers\BunnyStreamHelper::uploadArquivo($idAdmin, $guid, $tmp, $mime ?: 'video/mp4');
			if (empty($up['ok'])) {
				\App\Common\Helpers\BunnyStreamHelper::excluirVideo($idAdmin, $guid);
				return self::err($up['message'] ?? 'Falha no upload Stream.', 502);
			}
			$play = \App\Common\Helpers\BunnyStreamHelper::urlPlayback($idAdmin, $guid, 7200);
			$url = !empty($play['playbackUrl']) ? (string)$play['playbackUrl'] : ('bunny:'.$guid);
			return self::ok([
				'url' => $url,
				'kind' => 'video',
				'bunnyVideoId' => $guid,
			]);
		}

		if ($isAudio) {
			if ($size > 20 * 1024 * 1024) {
				return self::err('Áudio deve ter no máximo 20 MB.');
			}
		} else {
			if ($size > 8 * 1024 * 1024) {
				return self::err('Imagem deve ter no máximo 8 MB.');
			}
		}

		if (!\App\Common\Helpers\BunnyStorageHelper::pronto()) {
			return self::err('Bunny Storage não configurado no Master.', 503);
		}

		$idCurso = (int)($request->editorIdCurso ?? 0);
		$folder = $isAudio ? 'audio' : 'image';
		$remote = 'interativa/'.$idAdmin.'/'.($idCurso > 0 ? $idCurso.'/' : '').$folder.'/'.bin2hex(random_bytes(8)).'.'.$ext;
		$up = \App\Common\Helpers\BunnyStorageHelper::upload($tmp, $remote, $mime);
		if (empty($up['ok'])) {
			return self::err($up['message'] ?? 'Falha no upload Storage.', 502);
		}

		return self::ok([
			'url' => (string)$up['url'],
			'playUrl' => \App\Common\Helpers\BunnyStorageHelper::proxyUrlForPath($remote),
			'kind' => $isAudio ? 'audio' : 'image',
			'path' => (string)($up['path'] ?? $remote),
		]);
	}
}
