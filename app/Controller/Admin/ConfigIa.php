<?php

namespace App\Controller\Admin;

use App\Utils\View;
use App\Session\User\Login as SessionUser;
use App\Common\Helpers\TenantHelper;
use App\Common\Helpers\ModuleGateHelper;
use App\Common\Communication\TelegramAgentService;
use App\Common\Communication\TelegramBotApi;
use App\Model\Entity\AgentEscolaConfig;
use App\Model\Entity\AgentTelegramMensagem;
use App\Model\Entity\EscolaIntegracoes;

/**
 * Configurações de IA unificadas: credenciais compartilhadas + toggles por módulo do plano.
 */
class ConfigIa extends Page {

	/**
	 * @return array{ok:bool,ead:bool,assistente:bool,whatsapp:bool,id_admin:int}
	 */
	private static function contextoAcesso(): array {
		$user = SessionUser::getUserLogedData();
		$idAdmin = (int)($user['usuario']['id_admin'] ?? 0);
		$out = [
			'ok' => false,
			'ead' => false,
			'assistente' => false,
			'whatsapp' => false,
			'id_admin' => $idAdmin,
		];
		if (($user['usuario']['nivel'] ?? '') !== 'Diretor' || $idAdmin <= 0) {
			return $out;
		}
		$slugs = ModuleGateHelper::getSlugsEscola($idAdmin);
		$mods = ModuleGateHelper::getModulosEfetivos($idAdmin, $user['usuario']['acesso'] ?? []);
		$out['ead'] = in_array('ead', $slugs, true)
			&& in_array('Cursos Online', $mods, true);
		$out['assistente'] = in_array('assistente_ia', $slugs, true);
		$out['whatsapp'] = in_array('whatsapp', $slugs, true);
		$out['ok'] = $out['ead'] || $out['assistente'] || $out['whatsapp'];
		return $out;
	}

	private static function assertAcesso($request, bool $api = false): ?array {
		$ctx = self::contextoAcesso();
		if (!$ctx['ok']) {
			if (!$api) {
				$request->getRouter()->redirect('/painel');
			}
			return null;
		}
		return $ctx;
	}

	public static function index($request) {
		$ctx = self::assertAcesso($request);
		if ($ctx === null) {
			return '';
		}
		$content = View::render('admin/modules/config/ia', [
			'show_ead' => $ctx['ead'] ? '1' : '0',
			'show_assistente' => $ctx['assistente'] ? '1' : '0',
			'show_whatsapp' => $ctx['whatsapp'] ? '1' : '0',
			'card_ead_class' => $ctx['ead'] ? '' : 'd-none',
			'card_assistente_class' => $ctx['assistente'] ? '' : 'd-none',
			'card_whatsapp_class' => $ctx['whatsapp'] ? '' : 'd-none',
			'alert_agent_sql_class' => ($ctx['assistente'] && !AgentEscolaConfig::tabelaExiste()) ? '' : 'd-none',
		]);
		return parent::getPanel('Configurações de IA', $content, 'config', $request);
	}

	public static function getInfo($request) {
		header('Content-Type: application/json; charset=utf-8');
		$ctx = self::assertAcesso($request, true);
		if ($ctx === null) {
			return json_encode(['success' => false, 'message' => 'Acesso negado.']);
		}
		$post = $request->getPostVars();
		$acao = $post['acao'] ?? '';
		if ($acao === 'carregar') {
			return self::carregar($ctx);
		}
		if ($acao === 'salvar') {
			return self::salvar($ctx, $post);
		}
		if ($ctx['assistente']) {
			if ($acao === 'telegram_status') {
				return self::telegramStatus($ctx);
			}
			if ($acao === 'telegram_webhook_ativar') {
				return self::telegramWebhookAtivar($ctx);
			}
			if ($acao === 'telegram_webhook_desativar') {
				return self::telegramWebhookDesativar($ctx);
			}
			if ($acao === 'telegram_testar') {
				return self::telegramTestar($ctx);
			}
		}
		return json_encode(['success' => false, 'message' => 'Ação inválida.']);
	}

	/**
	 * Se agent_escola_config tem LLM e ai_* está vazio, copia uma vez.
	 */
	private static function migrarLlmParaAi(int $idAdmin): void {
		if (!EscolaIntegracoes::temColunasAi() || !AgentEscolaConfig::tabelaExiste()) {
			return;
		}
		$cfg = EscolaIntegracoes::getByIdAdmin($idAdmin);
		$keyAi = ($cfg instanceof EscolaIntegracoes) ? $cfg->getAiApiKeyDescriptografada() : null;
		if ($keyAi) {
			return;
		}
		$agent = AgentEscolaConfig::getByIdAdmin($idAdmin);
		if (!$agent instanceof AgentEscolaConfig) {
			return;
		}
		$keyLlm = $agent->getLlmApiKeyDescriptografada();
		if (!$keyLlm) {
			return;
		}
		if (!$cfg instanceof EscolaIntegracoes) {
			$cfg = new EscolaIntegracoes();
			$cfg->id_admin = $idAdmin;
		}
		$provider = (string)($agent->llm_provider ?? '');
		$cfg->ai_provider = in_array($provider, ['openai', 'gemini', 'outro'], true) ? $provider : ($cfg->ai_provider ?: null);
		if (trim((string)($cfg->ai_model ?? '')) === '' && trim((string)($agent->llm_model ?? '')) !== '') {
			$cfg->ai_model = trim((string)$agent->llm_model);
		}
		$cfg->salvarAi($keyLlm);
	}

	private static function carregar(array $ctx): string {
		$idAdmin = (int)$ctx['id_admin'];
		self::migrarLlmParaAi($idAdmin);

		$colOk = EscolaIntegracoes::temColunasAi();
		$cfg = EscolaIntegracoes::getByIdAdmin($idAdmin);
		$keyMask = '';
		$keySalva = false;
		if ($cfg instanceof EscolaIntegracoes) {
			$plain = $cfg->getAiApiKeyDescriptografada();
			if ($plain) {
				$keySalva = true;
				$len = strlen($plain);
				$keyMask = $len > 8
					? substr($plain, 0, 4).str_repeat('*', max(4, $len - 8)).substr($plain, -4)
					: '********';
			}
		}

		$assistente = [
			'llm_ativo' => false,
			'telegram_ia_ativo' => true,
			'telegram_ia_coluna_ok' => AgentEscolaConfig::temColunaTelegramIa(),
			'telegram_bot_username' => '',
			'telegram_chat_id' => '',
			'telegram_notas' => '',
			'telegram_token_salvo' => false,
			'telegram_token_mask' => '',
			'telegram_pronto' => false,
			'tabela_ok' => AgentEscolaConfig::tabelaExiste(),
		];

		if ($ctx['assistente'] && AgentEscolaConfig::tabelaExiste()) {
			$agent = AgentEscolaConfig::getByIdAdmin($idAdmin);
			if ($agent instanceof AgentEscolaConfig) {
				$pub = $agent->toEscolaPublicArray();
				$assistente['llm_ativo'] = !empty($pub['llm_ativo']);
				$assistente['telegram_ia_ativo'] = !empty($pub['telegram_ia_ativo']);
				$assistente['telegram_ia_coluna_ok'] = !empty($pub['telegram_ia_coluna_ok']);
				$assistente['telegram_bot_username'] = (string)($pub['telegram_bot_username'] ?? '');
				$assistente['telegram_chat_id'] = (string)($pub['telegram_chat_id'] ?? '');
				$assistente['telegram_notas'] = (string)($pub['telegram_notas'] ?? '');
				$assistente['telegram_token_salvo'] = !empty($pub['telegram_token_salvo']);
				$assistente['telegram_token_mask'] = (string)($pub['telegram_token_mask'] ?? '');
				$assistente['telegram_pronto'] = !empty($pub['telegram_pronto']);
			}
		}

		$variarTexto = 0;
		$variarOk = EscolaIntegracoes::temColunaWhatsappVariarTexto();
		if ($ctx['whatsapp'] && $cfg instanceof EscolaIntegracoes && $variarOk) {
			$variarTexto = (int)($cfg->whatsapp_variar_texto ?? 0);
		}

		$credPronta = $cfg instanceof EscolaIntegracoes
			&& $cfg->getAiApiKeyDescriptografada()
			&& trim((string)($cfg->ai_provider ?? '')) !== '';

		$telegramNativo = null;
		if ($ctx['assistente']) {
			$st = TelegramAgentService::statusWebhook($idAdmin);
			$gate = $st['gate'] ?? [];
			$telegramNativo = [
				'webhook_url' => $st['webhook_url'] ?? TelegramBotApi::webhookUrl($idAdmin),
				'https_ok' => !empty($st['https_ok']),
				'pronto' => !empty($st['pronto']),
				'gate_ok' => !empty($gate['ok']),
				'gate_message' => (string)($gate['message'] ?? ''),
				'bot_username' => (string)($st['bot']['result']['username'] ?? ''),
				'webhook_url_ativa' => (string)($st['webhook_info']['result']['url'] ?? ''),
				'historico_ok' => AgentTelegramMensagem::tabelaExiste(),
			];
		}

		return json_encode([
			'success' => true,
			'coluna_ok' => $colOk,
			'modulos' => [
				'ead' => (bool)$ctx['ead'],
				'assistente' => (bool)$ctx['assistente'],
				'whatsapp' => (bool)$ctx['whatsapp'],
			],
			'ai_provider' => $cfg instanceof EscolaIntegracoes ? ($cfg->ai_provider ?: '') : '',
			'ai_model' => $cfg instanceof EscolaIntegracoes ? ($cfg->ai_model ?: '') : '',
			'key_salva' => $keySalva,
			'key_mask' => $keyMask,
			'credencial_pronta' => (bool)$credPronta,
			'ai_ativo' => $cfg instanceof EscolaIntegracoes ? (int)$cfg->ai_ativo : 0,
			'ai_pedagogica_pronta' => $cfg instanceof EscolaIntegracoes && $cfg->temAiAtivo(),
			'assistente' => $assistente,
			'telegram_nativo' => $telegramNativo,
			'whatsapp_variar_texto' => $variarTexto,
			'whatsapp_variar_ok' => $variarOk,
		], JSON_UNESCAPED_UNICODE);
	}

	private static function telegramStatus(array $ctx): string {
		$st = TelegramAgentService::statusWebhook((int)$ctx['id_admin']);
		return json_encode(['success' => true, 'telegram_nativo' => $st], JSON_UNESCAPED_UNICODE);
	}

	private static function telegramWebhookAtivar(array $ctx): string {
		$res = TelegramAgentService::ativarWebhook((int)$ctx['id_admin']);
		return json_encode([
			'success' => !empty($res['ok']),
			'message' => $res['message'] ?? '',
			'telegram_nativo' => $res,
		], JSON_UNESCAPED_UNICODE);
	}

	private static function telegramWebhookDesativar(array $ctx): string {
		$res = TelegramAgentService::desativarWebhook((int)$ctx['id_admin']);
		return json_encode([
			'success' => !empty($res['ok']),
			'message' => $res['message'] ?? '',
			'telegram_nativo' => $res,
		], JSON_UNESCAPED_UNICODE);
	}

	private static function telegramTestar(array $ctx): string {
		$idAdmin = (int)$ctx['id_admin'];
		$gate = TelegramAgentService::escolaPodeResponder($idAdmin);
		if (!$gate['ok']) {
			return json_encode(['success' => false, 'message' => $gate['message'] ?? 'Não pronto.']);
		}
		$cfg = $gate['config'];
		$token = $cfg->getTelegramBotTokenDescriptografado();
		$chatId = trim((string)($cfg->telegram_chat_id ?? ''));
		$parts = preg_split('/[\s,;]+/', $chatId) ?: [];
		$primeiro = trim((string)($parts[0] ?? ''));
		if ($primeiro === '') {
			return json_encode(['success' => false, 'message' => 'Cadastre um Chat ID.']);
		}
		$api = new TelegramBotApi((string)$token);
		$res = $api->sendMessage($primeiro, 'Teste do assistente CTI: conexão OK ✅');
		if (empty($res['ok'])) {
			return json_encode([
				'success' => false,
				'message' => $res['description'] ?? 'Falha ao enviar mensagem de teste.',
			]);
		}
		return json_encode(['success' => true, 'message' => 'Mensagem de teste enviada para '.$primeiro.'.']);
	}

	private static function salvar(array $ctx, array $post): string {
		if (!EscolaIntegracoes::temColunasAi()) {
			return json_encode([
				'success' => false,
				'message' => 'Execute o SQL das colunas de IA (lms_ead / escola_integracoes.ai_*) no phpMyAdmin.',
			]);
		}

		$idAdmin = (int)$ctx['id_admin'];
		$cfg = EscolaIntegracoes::getByIdAdmin($idAdmin);
		if (!$cfg instanceof EscolaIntegracoes) {
			$cfg = new EscolaIntegracoes();
			$cfg->id_admin = $idAdmin;
		}

		// Credenciais compartilhadas
		$provider = (string)($post['ai_provider'] ?? '');
		$cfg->ai_provider = in_array($provider, ['openai', 'gemini', 'outro'], true) ? $provider : null;
		$cfg->ai_model = trim((string)($post['ai_model'] ?? ''));
		$key = trim((string)($post['ai_api_key'] ?? ''));

		// Toggle pedagógica (só se módulo EAD)
		if ($ctx['ead']) {
			$cfg->ai_ativo = !empty($post['ai_ativo']) ? 1 : 0;
		}

		if (!$cfg->salvarAi($key !== '' ? $key : null)) {
			return json_encode(['success' => false, 'message' => EscolaIntegracoes::getUltimoErro() ?: 'Falha ao salvar credenciais.']);
		}

		// Recarrega após salvar (chave nova)
		$cfg = EscolaIntegracoes::getByIdAdmin($idAdmin);
		if (!$cfg instanceof EscolaIntegracoes) {
			return json_encode(['success' => false, 'message' => 'Falha ao recarregar configuração.']);
		}

		// Toggle variar texto WhatsApp
		if ($ctx['whatsapp'] && EscolaIntegracoes::temColunaWhatsappVariarTexto()) {
			$cfg->whatsapp_variar_texto = !empty($post['whatsapp_variar_texto']) ? 1 : 0;
			if (!$cfg->salvar()) {
				return json_encode([
					'success' => false,
					'message' => EscolaIntegracoes::getUltimoErro() ?: 'Falha ao salvar variação WhatsApp.',
				]);
			}
		} elseif ($ctx['whatsapp'] && !empty($post['whatsapp_variar_texto'])) {
			return json_encode([
				'success' => false,
				'message' => 'Execute database/whatsapp_anti_ban.sql no phpMyAdmin para liberar variação de texto.',
			]);
		}

		// Assistente: Telegram nativo + flag llm_ativo (+ espelho llm_* na mesma tabela)
		if ($ctx['assistente']) {
			if (!AgentEscolaConfig::tabelaExiste()) {
				return json_encode([
					'success' => false,
					'message' => 'Execute database/agent_escola_config.sql no phpMyAdmin.',
				]);
			}
			$assistenteAtivo = !empty($post['assistente_ativo']) ? 1 : 0;
			$tgToken = trim((string)($post['telegram_bot_token'] ?? ''));
			$agent = AgentEscolaConfig::getByIdAdmin($idAdmin);
			if (!$agent instanceof AgentEscolaConfig) {
				$agent = new AgentEscolaConfig();
				$agent->id_admin = $idAdmin;
			}
			$agent->llm_ativo = $assistenteAtivo;
			$agent->telegram_ia_ativo = !empty($post['telegram_ia_ativo']) ? 1 : 0;
			$agent->llm_provider = $cfg->ai_provider ?: null;
			$agent->llm_model = $cfg->ai_model ?: null;
			$agent->telegram_bot_username = trim((string)($post['telegram_bot_username'] ?? ''));
			$agent->telegram_chat_id = trim((string)($post['telegram_chat_id'] ?? ''));
			$agent->telegram_notas = trim((string)($post['telegram_notas'] ?? ''));
			$keyEspelho = $cfg->getAiApiKeyDescriptografada();
			if (!$agent->salvarPelaEscola(
				$keyEspelho ?: null,
				$tgToken !== '' ? $tgToken : null
			)) {
				return json_encode([
					'success' => false,
					'message' => AgentEscolaConfig::getUltimoErro() ?: 'Falha ao salvar Assistente / Telegram.',
				]);
			}
		}

		return json_encode([
			'success' => true,
			'message' => 'Configurações de IA salvas.',
			'ai_pedagogica_pronta' => $cfg->temAiAtivo(),
			'credencial_pronta' => (bool)$cfg->getAiApiKeyDescriptografada(),
		], JSON_UNESCAPED_UNICODE);
	}
}
