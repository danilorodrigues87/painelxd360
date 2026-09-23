<?php

namespace App\Controller\Admin;

use App\Utils\View;
use App\Session\User\Login as SessionUser;
use App\Common\Helpers\TenantHelper;
use App\Common\Helpers\ModuleGateHelper;
use App\Common\Helpers\MetaGraphHelper;
use App\Common\Helpers\MetaWebhookDebug;
use App\Model\Entity\EscolaIntegracoes;
use App\Model\Entity\SocialAutomacao;
use App\Model\Entity\SocialAutomacaoLog;
use App\Model\Entity\MetaWebhookLog;
use App\Model\Entity\SocialPost;
use App\Model\Entity\SocialWorkerRun;
use App\Model\Db\Database;

class ConfigSocial extends Page {

	private static function assertAcesso($request, bool $api = false): bool {
		$user = SessionUser::getUserLogedData();
		if (($user['usuario']['nivel'] ?? '') !== 'Diretor') {
			if (!$api) {
				$request->getRouter()->redirect('/painel');
			}
			return false;
		}
		$idAdmin = (int)($user['usuario']['id_admin'] ?? 0);
		$mods = ModuleGateHelper::getModulosEfetivos($idAdmin, $user['usuario']['acesso'] ?? []);
		if (!in_array('social', ModuleGateHelper::getSlugsEscola($idAdmin), true)
			|| !in_array('Redes sociais', $mods, true)) {
			if (!$api) {
				$request->getRouter()->redirect('/painel');
			}
			return false;
		}
		return true;
	}

	public static function index($request) {
		if (!self::assertAcesso($request)) {
			return '';
		}
		$content = View::render('admin/modules/config/social', []);
		return parent::getPanel('Redes sociais', $content, 'config', $request);
	}

	public static function getInfo($request) {
		if (!self::assertAcesso($request, true)) {
			return json_encode(['success' => false, 'message' => 'Acesso negado.']);
		}
		$post = $request->getPostVars();
		$acao = $post['acao'] ?? '';
		if ($acao === 'carregar') {
			return self::carregar();
		}
		if ($acao === 'salvar') {
			return self::salvar($post);
		}
		if ($acao === 'testar') {
			return self::testar();
		}
		if ($acao === 'oauth_url') {
			return self::oauthUrl();
		}
		if ($acao === 'desconectar') {
			return self::desconectar();
		}
		if ($acao === 'listar_automacoes') {
			return self::listarAutomacoes();
		}
		if ($acao === 'salvar_automacao') {
			return self::salvarAutomacao($post);
		}
		if ($acao === 'excluir_automacao') {
			return self::excluirAutomacao($post);
		}
		if ($acao === 'log_automacoes') {
			return self::logAutomacoes();
		}
		if ($acao === 'subscribe_webhooks') {
			return self::subscribeWebhooks();
		}
		if ($acao === 'webhook_debug_status') {
			return self::webhookDebugStatus();
		}
		return json_encode(['success' => false, 'message' => 'Ação inválida.']);
	}

	private static function mask(?string $plain): string {
		if (!$plain) {
			return '';
		}
		$len = strlen($plain);
		return $len > 8
			? substr($plain, 0, 4).str_repeat('*', max(4, $len - 8)).substr($plain, -4)
			: '********';
	}

	private static function carregar(): string {
		$idAdmin = TenantHelper::getIdAdmin();
		$colOk = EscolaIntegracoes::temColunasMeta();
		$cfg = EscolaIntegracoes::getByIdAdmin($idAdmin);
		$tokenMask = '';
		if ($cfg instanceof EscolaIntegracoes) {
			$tokenMask = self::mask($cfg->getMetaPageTokenDescriptografada());
		}
		$wh = ($cfg instanceof EscolaIntegracoes && !empty($cfg->meta_webhook_token))
			? (string)$cfg->meta_webhook_token
			: '';
		$webhookUrl = $wh !== ''
			? rtrim((string)URL, '/').'/webhook/meta/'.$idAdmin.'/'.$wh
			: '';

		$webhookUrlGlobal = rtrim((string)URL, '/').'/webhook/meta';

		$metaPronto = $cfg instanceof EscolaIntegracoes && $cfg->temMetaPronto();
		$tokenExpires = $cfg instanceof EscolaIntegracoes ? (string)($cfg->meta_token_expires_at ?? '') : '';
		$tokenExpirado = $tokenExpires !== '' && strtotime($tokenExpires) !== false && strtotime($tokenExpires) < time();
		$estado = self::resolverEstadoMeta($colOk, MetaGraphHelper::appConfigurado(), $tokenMask !== '', $metaPronto, $tokenExpirado);

		return json_encode([
			'success' => true,
			'coluna_ok' => $colOk,
			'app_ok' => MetaGraphHelper::appConfigurado(),
			'auto_sql_ok' => SocialAutomacao::tabelaExiste(),
			'meta_fb_ativo' => $cfg instanceof EscolaIntegracoes ? (int)$cfg->meta_fb_ativo : 0,
			'meta_ig_ativo' => $cfg instanceof EscolaIntegracoes ? (int)$cfg->meta_ig_ativo : 0,
			'meta_auto_ativo' => ($cfg instanceof EscolaIntegracoes && EscolaIntegracoes::temColunaMetaAuto())
				? (int)($cfg->meta_auto_ativo ?? 0) : 0,
			'meta_page_id' => $cfg instanceof EscolaIntegracoes ? (string)($cfg->meta_page_id ?? '') : '',
			'meta_page_name' => $cfg instanceof EscolaIntegracoes ? (string)($cfg->meta_page_name ?? '') : '',
			'meta_ig_user_id' => $cfg instanceof EscolaIntegracoes ? (string)($cfg->meta_ig_user_id ?? '') : '',
			'meta_ig_username' => $cfg instanceof EscolaIntegracoes ? (string)($cfg->meta_ig_username ?? '') : '',
			'token_salvo' => $tokenMask !== '',
			'token_mask' => $tokenMask,
			'meta_pronto' => $metaPronto,
			'meta_conectado_em' => $cfg instanceof EscolaIntegracoes ? (string)($cfg->meta_conectado_em ?? '') : '',
			'meta_token_expires_at' => $tokenExpires,
			'token_expirado' => $tokenExpirado,
			'estado' => $estado,
			'webhook_url' => $webhookUrl,
			'webhook_url_global' => $webhookUrlGlobal,
			'operacao' => [
				'ultima_publicacao' => self::ultimaPublicacao($idAdmin),
				'worker_ultima' => SocialWorkerRun::ultima($idAdmin),
				'posts_publicados' => self::contarPublicados($idAdmin),
			],
			'cron_social' => self::metaCronSocial(),
			'links' => [
				'agenda' => rtrim((string)URL, '/').'/painel/social',
				'biblioteca' => rtrim((string)URL, '/').'/painel/marketing/biblioteca',
				'mensagens' => rtrim((string)URL, '/').'/painel/marketing/mensagens',
			],
			'checklist' => self::montarChecklist(
				$colOk,
				SocialAutomacao::tabelaExiste(),
				MetaGraphHelper::appConfigurado(),
				$tokenMask !== '',
				$metaPronto,
				$cfg instanceof EscolaIntegracoes ? (int)$cfg->meta_fb_ativo : 0,
				$cfg instanceof EscolaIntegracoes ? (int)$cfg->meta_ig_ativo : 0,
				$wh !== ''
			),
		], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
	}

	private static function resolverEstadoMeta(
		bool $colOk,
		bool $appOk,
		bool $tokenSalvo,
		bool $metaPronto,
		bool $tokenExpirado
	): string {
		if (!$colOk) {
			return 'sql_pendente';
		}
		if (!$appOk) {
			return 'app_indisponivel';
		}
		if ($metaPronto && !$tokenExpirado) {
			return 'conectado';
		}
		if ($tokenExpirado) {
			return 'token_expirado';
		}
		if ($tokenSalvo) {
			return 'incompleto';
		}
		return 'desconectado';
	}

	/** @return array<string,bool> */
	private static function montarChecklist(
		bool $colOk,
		bool $autoSqlOk,
		bool $appOk,
		bool $tokenSalvo,
		bool $metaPronto,
		int $fbAtivo,
		int $igAtivo,
		bool $webhookTokenOk
	): array {
		return [
			'sql_meta' => $colOk,
			'sql_automacoes' => $autoSqlOk,
			'app_servidor' => $appOk,
			'oauth_conectado' => $tokenSalvo && $metaPronto,
			'canais_ativos' => $fbAtivo === 1 || $igAtivo === 1,
			'webhook_token' => $webhookTokenOk,
		];
	}

	/** @return array|null */
	private static function ultimaPublicacao(int $idAdmin): ?array {
		if (!SocialPost::tabelaExiste() || $idAdmin <= 0) {
			return null;
		}
		try {
			$row = (new Database('social_posts'))->select(
				'id_admin = '.(int)$idAdmin.' AND status = "publicado"',
				'publicado_em DESC',
				1,
				'id, canais, formato, caption, publicado_em'
			)->fetch(\PDO::FETCH_ASSOC);
			return is_array($row) ? $row : null;
		} catch (\Throwable $e) {
			return null;
		}
	}

	private static function contarPublicados(int $idAdmin): int {
		if (!SocialPost::tabelaExiste() || $idAdmin <= 0) {
			return 0;
		}
		try {
			$row = (new Database('social_posts'))->select(
				'id_admin = '.(int)$idAdmin.' AND status = "publicado"',
				null,
				null,
				'COUNT(*) AS qtd'
			)->fetch(\PDO::FETCH_ASSOC);
			return (int)($row['qtd'] ?? 0);
		} catch (\Throwable $e) {
			return 0;
		}
	}

	private static function metaCronSocial(): array {
		$tokenOk = defined('SYSTEM_TOKEN') && SYSTEM_TOKEN !== '';
		$base = rtrim((string)URL, '/');
		$tokenHint = $tokenOk ? '***' : 'SEU_SYSTEM_TOKEN';

		return [
			'token_ok' => $tokenOk,
			'worker_ok' => SocialWorkerRun::tabelaExiste(),
			'url_cron' => $base.'/cron/social?token='.$tokenHint,
			'cron_cli' => '*/5 * * * * php worker/social.php',
			'hint' => $tokenOk
				? 'Configure no cPanel a cada 5 minutos para publicar com o painel fechado.'
				: 'Defina SYSTEM_TOKEN no .env para usar cron HTTP.',
		];
	}

	private static function salvar(array $post): string {
		if (!EscolaIntegracoes::temColunasMeta()) {
			return json_encode([
				'success' => false,
				'message' => 'Execute database/escola_integracoes_meta.sql no phpMyAdmin.',
			]);
		}
		$idAdmin = TenantHelper::getIdAdmin();
		$cfg = EscolaIntegracoes::getByIdAdmin($idAdmin);
		if (!$cfg instanceof EscolaIntegracoes) {
			$cfg = new EscolaIntegracoes();
			$cfg->id_admin = $idAdmin;
		}
		$cfg->meta_fb_ativo = !empty($post['meta_fb_ativo']) ? 1 : 0;
		$cfg->meta_ig_ativo = !empty($post['meta_ig_ativo']) ? 1 : 0;
		if (EscolaIntegracoes::temColunaMetaAuto()) {
			$cfg->meta_auto_ativo = !empty($post['meta_auto_ativo']) ? 1 : 0;
		}
		$pageId = trim((string)($post['meta_page_id'] ?? ''));
		$igId = trim((string)($post['meta_ig_user_id'] ?? ''));
		if ($pageId !== '') {
			$cfg->meta_page_id = $pageId;
		}
		if (isset($post['meta_page_name'])) {
			$cfg->meta_page_name = trim((string)$post['meta_page_name']) ?: null;
		}
		if ($igId !== '') {
			$cfg->meta_ig_user_id = $igId;
		}
		if (isset($post['meta_ig_username'])) {
			$cfg->meta_ig_username = trim((string)$post['meta_ig_username']) ?: null;
		}
		$token = trim((string)($post['meta_page_token'] ?? ''));
		if (!$cfg->salvarMeta($token !== '' ? $token : null)) {
			return json_encode(['success' => false, 'message' => EscolaIntegracoes::getUltimoErro() ?: 'Falha ao salvar.']);
		}
		$cfg = EscolaIntegracoes::getByIdAdmin($idAdmin);
		$subscribeMsg = '';
		if ($cfg instanceof EscolaIntegracoes) {
			$pageId = trim((string)($cfg->meta_page_id ?? ''));
			$pageToken = $cfg->getMetaPageTokenDescriptografada();
			if ($pageId !== '' && $pageToken) {
				$igId = trim((string)($cfg->meta_ig_user_id ?? ''));
				$sub = MetaGraphHelper::subscribeAllWebhooks($pageId, $pageToken, $igId !== '' ? $igId : null);
				$subscribeMsg = !empty($sub['ok'])
					? ' Webhooks Page+Instagram re-inscritos.'
					: (' Aviso webhooks: '.($sub['message'] ?? 'falha'));
			}
		}
		return json_encode([
			'success' => true,
			'message' => 'Configuração salva.'.$subscribeMsg,
			'meta_pronto' => $cfg instanceof EscolaIntegracoes && $cfg->temMetaPronto(),
		], JSON_UNESCAPED_UNICODE);
	}

	private static function testar(): string {
		$idAdmin = TenantHelper::getIdAdmin();
		$res = MetaGraphHelper::testarEscola($idAdmin);
		return json_encode([
			'success' => !empty($res['ok']),
			'message' => $res['message'] ?? ($res['ok'] ? 'OK' : 'Falha'),
			'page_name' => $res['page_name'] ?? null,
			'ig_username' => $res['ig_username'] ?? null,
			'auth_error' => !empty($res['auth_error']),
			'token_type' => $res['token_type'] ?? null,
			'token_scopes' => $res['token_scopes'] ?? [],
			'token_app_id' => $res['token_app_id'] ?? null,
			'token_page_id' => $res['token_page_id'] ?? null,
			'scopes_faltando' => $res['scopes_faltando'] ?? [],
			'app_mismatch' => !empty($res['app_mismatch']),
			'token_nao_page' => !empty($res['token_nao_page']),
			'token_diag_erro' => $res['token_diag_erro'] ?? null,
			'webhook_fields' => $res['webhook_fields'] ?? [],
			'webhook_messages_ok' => array_key_exists('webhook_messages_ok', $res) ? (bool)$res['webhook_messages_ok'] : null,
			'webhook_diag_erro' => $res['webhook_diag_erro'] ?? null,
			'app_id_esperado' => MetaGraphHelper::appId() !== '' ? MetaGraphHelper::appId() : null,
		], JSON_UNESCAPED_UNICODE);
	}

	private static function oauthUrl(): string {
		if (!MetaGraphHelper::appConfigurado()) {
			return json_encode([
				'success' => false,
				'message' => 'A conexão com Facebook ainda não está disponível. Fale com o suporte.',
			]);
		}
		$idAdmin = TenantHelper::getIdAdmin();
		$state = bin2hex(random_bytes(16));
		if (session_status() !== PHP_SESSION_ACTIVE) {
			@session_start();
		}
		$_SESSION['meta_oauth_state'] = $state;
		$_SESSION['meta_oauth_id_admin'] = $idAdmin;
		return json_encode([
			'success' => true,
			'url' => MetaGraphHelper::oauthAuthorizeUrl($idAdmin, $state),
		], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
	}

	private static function desconectar(): string {
		$idAdmin = TenantHelper::getIdAdmin();
		$cfg = EscolaIntegracoes::getByIdAdmin($idAdmin);
		if (!$cfg instanceof EscolaIntegracoes) {
			return json_encode(['success' => true, 'message' => 'Já desconectado.']);
		}
		$cfg->meta_fb_ativo = 0;
		$cfg->meta_ig_ativo = 0;
		$cfg->meta_page_id = null;
		$cfg->meta_page_name = null;
		$cfg->meta_ig_user_id = null;
		$cfg->meta_ig_username = null;
		$cfg->meta_conectado_em = null;
		$cfg->meta_token_expires_at = null;
		// Limpa token criptografando string vazia via update direto
		if (!EscolaIntegracoes::temColunasMeta()) {
			return json_encode(['success' => false, 'message' => 'SQL Meta ausente.']);
		}
		$db = new \App\Model\Db\Database('escola_integracoes');
		$db->update('id_admin = '.(int)$idAdmin, [
			'meta_fb_ativo' => 0,
			'meta_ig_ativo' => 0,
			'meta_page_id' => null,
			'meta_page_name' => null,
			'meta_ig_user_id' => null,
			'meta_ig_username' => null,
			'meta_page_token' => null,
			'meta_conectado_em' => null,
			'meta_token_expires_at' => null,
		]);
		return json_encode(['success' => true, 'message' => 'Conta desconectada.']);
	}

	/** Callback OAuth Meta (GET). */
	public static function oauthCallback($request) {
		if (!self::assertAcesso($request)) {
			return '';
		}
		$q = $request->getQueryParams() ?: [];
		$code = (string)($q['code'] ?? '');
		$state = (string)($q['state'] ?? '');
		$err = (string)($q['error_description'] ?? $q['error'] ?? '');
		if ($err !== '') {
			$request->getRouter()->redirect('/painel/config/social?oauth=erro&msg='.rawurlencode($err));
			return '';
		}
		if (session_status() !== PHP_SESSION_ACTIVE) {
			@session_start();
		}
		$expected = (string)($_SESSION['meta_oauth_state'] ?? '');
		if ($code === '' || $state === '' || $expected === '' || !hash_equals($expected, $state)) {
			$request->getRouter()->redirect('/painel/config/social?oauth=erro&msg='.rawurlencode('State OAuth inválido.'));
			return '';
		}
		unset($_SESSION['meta_oauth_state']);

		$tok = MetaGraphHelper::trocarCodePorToken($code);
		if (empty($tok['ok']) || empty($tok['access_token'])) {
			$request->getRouter()->redirect('/painel/config/social?oauth=erro&msg='.rawurlencode($tok['message'] ?? 'Falha no code.'));
			return '';
		}
		$long = MetaGraphHelper::longLivedUserToken((string)$tok['access_token']);
		$userToken = !empty($long['ok']) && !empty($long['access_token'])
			? (string)$long['access_token']
			: (string)$tok['access_token'];

		$pages = MetaGraphHelper::listarPages($userToken);
		if (empty($pages['ok']) || empty($pages['pages'])) {
			$request->getRouter()->redirect('/painel/config/social?oauth=erro&msg='.rawurlencode($pages['message'] ?? 'Nenhuma Page encontrada.'));
			return '';
		}

		// Usa a primeira Page (UI de escolha pode vir depois)
		$p = $pages['pages'][0];
		$idAdmin = TenantHelper::getIdAdmin();
		$cfg = EscolaIntegracoes::getByIdAdmin($idAdmin);
		if (!$cfg instanceof EscolaIntegracoes) {
			$cfg = new EscolaIntegracoes();
			$cfg->id_admin = $idAdmin;
		}
		$cfg->meta_page_id = $p['page_id'];
		$cfg->meta_page_name = $p['page_name'];
		$cfg->meta_ig_user_id = $p['ig_user_id'] !== '' ? $p['ig_user_id'] : null;
		$cfg->meta_ig_username = $p['ig_username'] !== '' ? $p['ig_username'] : null;
		$cfg->meta_fb_ativo = 1;
		$cfg->meta_ig_ativo = $p['ig_user_id'] !== '' ? 1 : 0;
		$cfg->meta_conectado_em = date('Y-m-d H:i:s');
		if (!empty($long['expires_in'])) {
			$cfg->meta_token_expires_at = date('Y-m-d H:i:s', time() + (int)$long['expires_in']);
		}
		$cfg->salvarMeta($p['page_token']);

		// Assina webhooks da Page (feed/messages) para keyword→DM
		MetaGraphHelper::subscribeAllWebhooks(
			(string)$p['page_id'],
			(string)$p['page_token'],
			($p['ig_user_id'] ?? '') !== '' ? (string)$p['ig_user_id'] : null
		);

		$n = count($pages['pages']);
		$request->getRouter()->redirect('/painel/config/social?oauth=ok&pages='.$n);
		return '';
	}

	private static function listarAutomacoes(): string {
		if (!SocialAutomacao::tabelaExiste()) {
			return json_encode([
				'success' => false,
				'sql_ok' => false,
				'message' => 'Execute database/social_automacoes.sql no phpMyAdmin.',
			]);
		}
		$idAdmin = TenantHelper::getIdAdmin();
		$itens = [];
		foreach (SocialAutomacao::listByAdmin($idAdmin) as $a) {
			$itens[] = [
				'id' => (int)$a->id,
				'palavra_chave' => (string)$a->palavra_chave,
				'match_modo' => (string)$a->match_modo,
				'mensagem_dm' => (string)$a->mensagem_dm,
				'canais' => (string)$a->canais,
				'ativo' => (int)$a->ativo,
			];
		}
		return json_encode(['success' => true, 'sql_ok' => true, 'itens' => $itens], JSON_UNESCAPED_UNICODE);
	}

	private static function salvarAutomacao(array $post): string {
		if (!SocialAutomacao::tabelaExiste()) {
			return json_encode(['success' => false, 'message' => 'Execute database/social_automacoes.sql']);
		}
		$idAdmin = TenantHelper::getIdAdmin();
		$id = (int)($post['id'] ?? 0);
		$kw = trim((string)($post['palavra_chave'] ?? ''));
		$msg = trim((string)($post['mensagem_dm'] ?? ''));
		if ($kw === '' || $msg === '') {
			return json_encode(['success' => false, 'message' => 'Palavra-chave e mensagem obrigatórias.']);
		}
		$ob = $id > 0 ? SocialAutomacao::getById($id, $idAdmin) : new SocialAutomacao();
		if ($id > 0 && !$ob) {
			return json_encode(['success' => false, 'message' => 'Regra não encontrada.']);
		}
		$ob->id_admin = $idAdmin;
		$ob->palavra_chave = $kw;
		$ob->match_modo = (string)($post['match_modo'] ?? 'contem');
		$ob->mensagem_dm = $msg;
		$ob->canais = (string)($post['canais'] ?? 'ambos');
		$ob->ativo = !empty($post['ativo']) ? 1 : 0;
		$ob->salvar();
		return json_encode(['success' => true, 'message' => 'Regra salva.', 'id' => (int)$ob->id]);
	}

	private static function excluirAutomacao(array $post): string {
		$idAdmin = TenantHelper::getIdAdmin();
		$ob = SocialAutomacao::getById((int)($post['id'] ?? 0), $idAdmin);
		if (!$ob) {
			return json_encode(['success' => false, 'message' => 'Não encontrada.']);
		}
		$ob->excluir();
		return json_encode(['success' => true, 'message' => 'Excluída.']);
	}

	private static function logAutomacoes(): string {
		$idAdmin = TenantHelper::getIdAdmin();
		$status = MetaWebhookDebug::status($idAdmin);
		$webhookDebug = $status['recent_db'];
		if (!$webhookDebug && !empty($status['recent_file'])) {
			$webhookDebug = $status['recent_file'];
		} elseif (!empty($status['recent_file'])) {
			$webhookDebug = array_merge($status['recent_file'], $webhookDebug);
		}
		$itens = SocialAutomacao::tabelaExiste()
			? SocialAutomacaoLog::listRecentes($idAdmin, 40)
			: [];
		return json_encode([
			'success' => true,
			'itens' => $itens,
			'webhook_debug' => array_slice($webhookDebug, 0, 40),
			'debug_status' => [
				'code_version' => $status['code_version'],
				'db_ok' => $status['db_ok'],
				'file_ok' => $status['file_ok'],
				'file_path' => $status['file_path'],
				'file_size' => $status['file_size'],
			],
		], JSON_UNESCAPED_UNICODE);
	}

	private static function webhookDebugStatus(): string {
		$idAdmin = TenantHelper::getIdAdmin();
		$status = MetaWebhookDebug::status($idAdmin);
		MetaWebhookDebug::logEvento('painel/ping', 'Teste manual do painel · id_admin='.$idAdmin);
		$status = MetaWebhookDebug::status($idAdmin);
		return json_encode(['success' => true, 'status' => $status], JSON_UNESCAPED_UNICODE);
	}

	private static function subscribeWebhooks(): string {
		$idAdmin = TenantHelper::getIdAdmin();
		$cfg = EscolaIntegracoes::getByIdAdmin($idAdmin);
		if (!$cfg instanceof EscolaIntegracoes) {
			return json_encode(['success' => false, 'message' => 'Meta não configurado.']);
		}
		$token = $cfg->getMetaPageTokenDescriptografada();
		$pageId = trim((string)($cfg->meta_page_id ?? ''));
		if (!$token || $pageId === '') {
			return json_encode(['success' => false, 'message' => 'Page/token ausentes.']);
		}
		$igId = trim((string)($cfg->meta_ig_user_id ?? ''));
		$r = MetaGraphHelper::subscribeAllWebhooks($pageId, $token, $igId !== '' ? $igId : null);
		$pageOk = !empty($r['page']['ok']);
		return json_encode([
			'success' => $pageOk,
			'partial' => !empty($r['partial']),
			'message' => $r['message'] ?? '',
			'page_ok' => $pageOk,
			'ig_ok'   => $igId === '' ? null : !empty($r['ig']['ok']),
		], JSON_UNESCAPED_UNICODE);
	}
}
