<?php

namespace App\Common\Helpers;

use App\Model\Entity\EscolaIntegracoes;
use App\Model\Entity\SocialBiblioteca;
use App\Model\Entity\SocialPost;
use App\Model\Entity\SocialPostMidia;
use App\Model\Entity\SocialPublishLog;
use App\Model\Entity\SocialWorkerRun;

/**
 * Publica posts agendados via Graph API e limpa mídia local do post
 * (arquivos da biblioteca são preservados).
 */
class SocialPublishService {

	/** Origem da execução atual (cli|cron|manual|poll). */
	private static string $origemAtual = 'worker';

	/**
	 * @return array{processados:int,ok:int,erro:int,detalhes:array}
	 */
	public static function processar(int $idAdmin = 0, int $limite = 10, string $origem = 'cli'): array {
		self::$origemAtual = $origem;
		$resumo = ['processados' => 0, 'ok' => 0, 'erro' => 0, 'detalhes' => []];
		if (!SocialPost::tabelaExiste()) {
			$resumo['detalhes'][] = 'Tabela social_posts ausente.';
			if ($origem !== 'poll') {
				SocialWorkerRun::registrar($origem, $idAdmin, $resumo);
			}
			return $resumo;
		}
		foreach (SocialPost::listProntosParaPublicar($limite, $idAdmin) as $post) {
			$resumo['processados']++;
			$r = self::publicarUm($post, $origem);
			if (!empty($r['ok'])) {
				$resumo['ok']++;
			} else {
				$resumo['erro']++;
			}
			$resumo['detalhes'][] = [
				'id' => (int)$post->id,
				'ok' => !empty($r['ok']),
				'message' => $r['message'] ?? '',
			];
		}
		// Poll silencioso: só registra se processou algo (evita poluir "última execução")
		if ($origem !== 'poll' || $resumo['processados'] > 0) {
			SocialWorkerRun::registrar($origem, $idAdmin, $resumo);
		}
		return $resumo;
	}

	/**
	 * @return array{ok:bool,message?:string}
	 */
	public static function publicarUm(SocialPost $post, string $origem = ''): array {
		if ($origem !== '') {
			self::$origemAtual = $origem;
		}
		if (!$post->claimPublicando()) {
			return ['ok' => false, 'message' => 'Já em processamento ou não agendado.'];
		}

		$cfg = EscolaIntegracoes::getByIdAdmin((int)$post->id_admin);
		if (!$cfg instanceof EscolaIntegracoes || !EscolaIntegracoes::temColunasMeta()) {
			return self::falha($post, 'Meta não configurado (SQL/colunas).');
		}
		$token = $cfg->getMetaPageTokenDescriptografada();
		$pageId = trim((string)($cfg->meta_page_id ?? ''));
		$igId = trim((string)($cfg->meta_ig_user_id ?? ''));
		if (!$token || $pageId === '') {
			return self::falha($post, 'Page/token ausentes. Reconecte em Configurações → Redes sociais.');
		}

		$formato = in_array((string)($post->formato ?? 'feed'), ['feed', 'story', 'reel', 'carousel'], true)
			? (string)$post->formato
			: 'feed';
		if (!SocialPost::temColunaFormato()) {
			$formato = 'feed';
		}

		$midias = SocialPostMidia::listByPost((int)$post->id, (int)$post->id_admin);
		$itens = [];
		foreach ($midias as $m) {
			$url = $m->urlPublica();
			if ($url === '') {
				continue;
			}
			$itens[] = [
				'url' => $url,
				'tipo' => (($m->tipo ?? 'image') === 'video') ? 'video' : 'image',
			];
		}
		if (!$itens) {
			return self::falha($post, 'Post sem mídia (upload ou URL).');
		}

		$valid = self::validarMidiasFormato($formato, $itens);
		if ($valid !== null) {
			return self::falha($post, $valid);
		}

		$caption = (string)($post->caption ?? '');
		$canais = (string)$post->canais;
		$querFb = $canais === 'facebook' || $canais === 'ambos';
		$querIg = $canais === 'instagram' || $canais === 'ambos';

		if ($formato === 'story' || $formato === 'reel') {
			$querFb = false;
			$querIg = true;
		}

		if ($querFb && (int)$cfg->meta_fb_ativo !== 1) {
			return self::falha($post, 'Facebook desativado nas configurações da escola.');
		}
		if ($querIg && (int)$cfg->meta_ig_ativo !== 1) {
			return self::falha($post, 'Instagram desativado nas configurações da escola.');
		}
		if ($querIg && $igId === '') {
			return self::falha($post, 'Instagram User ID ausente. Reconecte a conta.');
		}

		$erros = [];
		if ($querFb) {
			$fb = self::publicarFacebook($pageId, $token, $formato, $itens, $caption);
			if (empty($fb['ok'])) {
				if (!empty($fb['auth_error'])) {
					return self::falha($post, 'Token Meta inválido/revogado. Reconecte a conta. '.($fb['message'] ?? ''));
				}
				$erros[] = 'FB: '.($fb['message'] ?? 'falha');
			} else {
				$post->fb_post_id = (string)($fb['id'] ?? '');
			}
		}
		if ($querIg) {
			$ig = self::publicarInstagram($igId, $token, $formato, $itens, $caption);
			if (empty($ig['ok'])) {
				if (!empty($ig['auth_error'])) {
					return self::falha($post, 'Token Meta inválido/revogado. Reconecte a conta. '.($ig['message'] ?? ''));
				}
				$erros[] = 'IG: '.($ig['message'] ?? 'falha');
			} else {
				$post->ig_media_id = (string)($ig['id'] ?? '');
			}
		}

		if ($erros && !$post->fb_post_id && !$post->ig_media_id) {
			return self::falha($post, implode(' | ', $erros));
		}

		$post->status = 'publicado';
		$post->publicado_em = date('Y-m-d H:i:s');
		$post->erro_msg = $erros ? implode(' | ', $erros) : null;
		$post->salvar();

		foreach ($midias as $m) {
			if (!empty($m->path_local) && !self::pathNaBiblioteca((int)$post->id_admin, (string)$m->path_local)) {
				SocialMediaStorage::apagar((string)$m->path_local);
				$m->path_local = null;
				$m->salvar();
			}
		}

		$msg = $erros ? 'Publicado parcialmente: '.implode(' | ', $erros) : 'Publicado.';
		SocialPublishLog::registrar([
			'id_admin' => (int)$post->id_admin,
			'id_post' => (int)$post->id,
			'origem' => self::$origemAtual,
			'status' => $erros ? 'parcial' : 'ok',
			'mensagem' => $msg,
			'fb_post_id' => $post->fb_post_id,
			'ig_media_id' => $post->ig_media_id,
			'formato' => $formato,
			'canais' => $canais,
		]);

		return ['ok' => true, 'message' => $msg];
	}

	private static function pathNaBiblioteca(int $idAdmin, string $path): bool {
		return SocialBiblioteca::pathNaBiblioteca($idAdmin, $path);
	}

	/**
	 * @param array<int,array{url:string,tipo:string}> $itens
	 */
	private static function validarMidiasFormato(string $formato, array $itens): ?string {
		$n = count($itens);
		if ($formato === 'carousel') {
			if ($n < 2 || $n > 10) {
				return 'Carrossel exige de 2 a 10 mídias.';
			}
			return null;
		}
		if ($n < 1) {
			return 'Envie ao menos uma mídia.';
		}
		$tipo = $itens[0]['tipo'];
		if ($formato === 'reel' && $tipo !== 'video') {
			return 'Reel exige um vídeo.';
		}
		if ($formato === 'feed' && $tipo !== 'image') {
			return 'Feed (FB/IG) nesta versão exige imagem. Use Reel para vídeo ou Carrossel.';
		}
		return null;
	}

	/**
	 * @param array<int,array{url:string,tipo:string}> $itens
	 * @return array{ok:bool,id?:string,message?:string,auth_error?:bool}
	 */
	private static function publicarFacebook(
		string $pageId,
		string $token,
		string $formato,
		array $itens,
		string $caption
	): array {
		if ($formato === 'story' || $formato === 'reel') {
			return ['ok' => false, 'message' => 'Story/Reel não publicam no Facebook nesta versão.'];
		}
		if ($formato === 'carousel') {
			$ids = [];
			foreach ($itens as $i => $item) {
				if ($item['tipo'] !== 'image') {
					return ['ok' => false, 'message' => 'Carrossel no Facebook: use apenas imagens nesta versão.'];
				}
				$cap = $i === 0 ? $caption : '';
				$fb = MetaGraphHelper::publicarFotoPage($pageId, $token, $item['url'], $cap);
				if (empty($fb['ok'])) {
					return $fb;
				}
				$ids[] = (string)($fb['id'] ?? '');
			}
			return ['ok' => true, 'id' => implode(',', array_filter($ids))];
		}
		$first = $itens[0];
		if ($first['tipo'] !== 'image') {
			return ['ok' => false, 'message' => 'Facebook feed: apenas imagem.'];
		}
		return MetaGraphHelper::publicarFotoPage($pageId, $token, $first['url'], $caption);
	}

	/**
	 * @param array<int,array{url:string,tipo:string}> $itens
	 * @return array{ok:bool,id?:string,message?:string,auth_error?:bool}
	 */
	private static function publicarInstagram(
		string $igId,
		string $token,
		string $formato,
		array $itens,
		string $caption
	): array {
		if ($formato === 'story') {
			return MetaGraphHelper::publicarStoryInstagram($igId, $token, $itens[0]['url'], $itens[0]['tipo']);
		}
		if ($formato === 'reel') {
			return MetaGraphHelper::publicarReelInstagram($igId, $token, $itens[0]['url'], $caption);
		}
		if ($formato === 'carousel') {
			return MetaGraphHelper::publicarCarouselInstagram($igId, $token, $itens, $caption);
		}
		return MetaGraphHelper::publicarImagemInstagram($igId, $token, $itens[0]['url'], $caption);
	}

	private static function falha(SocialPost $post, string $msg): array {
		$post->status = 'erro';
		$post->erro_msg = mb_substr($msg, 0, 500);
		$post->salvar();
		SocialPublishLog::registrar([
			'id_admin' => (int)$post->id_admin,
			'id_post' => (int)$post->id,
			'origem' => self::$origemAtual,
			'status' => 'erro',
			'mensagem' => $msg,
			'formato' => $post->formato ?? null,
			'canais' => $post->canais ?? null,
		]);
		return ['ok' => false, 'message' => $msg];
	}
}
