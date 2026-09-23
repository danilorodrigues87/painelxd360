<?php

namespace App\Common\Communication;

use App\Common\Helpers\AgentAnalyticsHelper;
use App\Common\Helpers\LmsAiService;
use App\Common\Helpers\ModuleGateHelper;
use App\Model\Entity\AgentEscolaConfig;
use App\Model\Entity\AgentTelegramMensagem;
use App\Model\Entity\EscolaIntegracoes;
use App\Model\Db\Database;

/**
 * Agente Telegram nativo por escola (read-only + LLM compartilhada).
 */
class TelegramAgentService {

	public const MAX_MSGS_HORA = 30;
	public const HISTORICO = 8;

	/**
	 * Processa um update do Telegram (webhook ou getUpdates).
	 * Palavras-chave respondem sem IA. Texto livre só usa IA se telegram_ia_ativo=1.
	 * @return array{ok:bool,skipped?:bool,message?:string}
	 */
	public static function processarUpdate(int $idAdmin, array $update): array {
		$message = $update['message'] ?? null;
		if (!is_array($message)) {
			return ['ok' => true, 'skipped' => true, 'message' => 'sem_mensagem'];
		}
		if (!empty($message['from']['is_bot'])) {
			return ['ok' => true, 'skipped' => true];
		}

		$chatId = (string)($message['chat']['id'] ?? '');
		$text = trim((string)($message['text'] ?? ''));
		if ($chatId === '' || $text === '') {
			return ['ok' => true, 'skipped' => true, 'message' => 'vazio'];
		}

		$gate = self::escolaPodeResponder($idAdmin);
		if (!$gate['ok']) {
			return ['ok' => false, 'message' => $gate['message']];
		}

		/** @var AgentEscolaConfig $cfg */
		$cfg = $gate['config'];
		$token = $cfg->getTelegramBotTokenDescriptografado();
		$api = new TelegramBotApi((string)$token);

		if (!self::chatAutorizado($cfg, $chatId)) {
			$api->sendMessage($chatId, 'Este chat não está autorizado. Peça ao Diretor para cadastrar o Chat ID em Configurações de IA.');
			return ['ok' => true, 'skipped' => true, 'message' => 'chat_nao_autorizado'];
		}

		if (AgentTelegramMensagem::contarUltimaHora($idAdmin, $chatId) >= self::MAX_MSGS_HORA) {
			$api->sendMessage($chatId, 'Limite de mensagens por hora atingido. Tente mais tarde.');
			return ['ok' => true, 'skipped' => true, 'message' => 'rate_limit'];
		}

		// 1) Palavras-chave / comandos — sempre sem IA
		$porKeyword = self::responderPalavraChave($idAdmin, $text);
		if ($porKeyword !== null) {
			$api->sendMessage($chatId, $porKeyword);
			return ['ok' => true, 'message' => 'keyword'];
		}

		// 2) Texto livre
		if (!$cfg->iaLivreAtiva()) {
			$api->sendMessage(
				$chatId,
				"A IA está desligada nesta escola.\n\n"
				."Use uma palavra-chave da lista — digite /ajuda para ver os comandos."
			);
			return ['ok' => true, 'message' => 'ia_desligada'];
		}

		$ai = EscolaIntegracoes::getByIdAdmin($idAdmin);
		$key = ($ai instanceof EscolaIntegracoes) ? $ai->getAiApiKeyDescriptografada() : null;
		if (!$key) {
			$api->sendMessage(
				$chatId,
				"IA ligada, mas falta a chave em Configurações de IA.\n"
				."Enquanto isso, use /ajuda e as palavras-chave."
			);
			return ['ok' => true, 'message' => 'sem_chave_ia'];
		}

		AgentTelegramMensagem::salvar($idAdmin, $chatId, 'user', $text);
		$api->sendMessage($chatId, 'Consultando com a IA…');

		$dados = self::montarContextoDados($idAdmin, $text);
		$historico = AgentTelegramMensagem::ultimas($idAdmin, $chatId, self::HISTORICO);

		$system = self::systemPrompt($dados);
		$messages = [];
		foreach ($historico as $h) {
			$messages[] = ['role' => $h['role'], 'content' => $h['content']];
		}
		if (empty($messages) || end($messages)['content'] !== $text) {
			$messages[] = ['role' => 'user', 'content' => $text];
		}

		$resposta = LmsAiService::chatComCredencial($idAdmin, $messages, $system, 'telegram');
		if ($resposta === null || trim($resposta) === '') {
			$resposta = 'Não consegui consultar a IA agora'
				.(LmsAiService::getLastError() ? ' ('.LmsAiService::getLastError().')' : '')
				.'. Tente /resumo ou /ajuda (sem IA).';
		}

		$resposta = trim($resposta);
		AgentTelegramMensagem::salvar($idAdmin, $chatId, 'assistant', $resposta);
		$api->sendMessage($chatId, $resposta);

		return ['ok' => true, 'message' => 'ia'];
	}

	/**
	 * @return array{ok:bool,message?:string,config?:AgentEscolaConfig}
	 */
	public static function escolaPodeResponder(int $idAdmin): array {
		$idAdmin = (int)$idAdmin;
		if ($idAdmin <= 0) {
			return ['ok' => false, 'message' => 'Escola inválida.'];
		}
		if (!in_array('assistente_ia', ModuleGateHelper::getSlugsEscola($idAdmin), true)) {
			return ['ok' => false, 'message' => 'Módulo assistente_ia não liberado.'];
		}
		if (!AgentEscolaConfig::tabelaExiste()) {
			return ['ok' => false, 'message' => 'Execute database/agent_escola_config.sql.'];
		}
		$cfg = AgentEscolaConfig::getByIdAdmin($idAdmin);
		if (!$cfg instanceof AgentEscolaConfig) {
			return ['ok' => false, 'message' => 'Assistente não configurado.'];
		}
		if ((int)$cfg->llm_ativo !== 1) {
			return ['ok' => false, 'message' => 'Assistente desativado na escola.'];
		}
		$token = $cfg->getTelegramBotTokenDescriptografado();
		if (!$token) {
			return ['ok' => false, 'message' => 'Token do Telegram ausente.'];
		}
		$chatAllow = trim((string)($cfg->telegram_chat_id ?? ''));
		if ($chatAllow === '') {
			return ['ok' => false, 'message' => 'Cadastre o Chat ID autorizado.'];
		}
		// Chave de IA só é obrigatória se a IA livre estiver ligada
		if ($cfg->iaLivreAtiva()) {
			$ai = EscolaIntegracoes::getByIdAdmin($idAdmin);
			$key = ($ai instanceof EscolaIntegracoes) ? $ai->getAiApiKeyDescriptografada() : null;
			if (!$key) {
				// Ainda pode usar keywords; gate "pronto" parcial — liberamos o bot
				// (texto livre avisará falta de chave)
			}
		}
		return ['ok' => true, 'config' => $cfg];
	}

	public static function chatAutorizado(AgentEscolaConfig $cfg, string $chatId): bool {
		$raw = trim((string)($cfg->telegram_chat_id ?? ''));
		if ($raw === '') {
			return false;
		}
		$chatId = trim($chatId);
		$parts = preg_split('/[\s,;]+/', $raw) ?: [];
		foreach ($parts as $p) {
			$p = trim((string)$p);
			if ($p !== '' && hash_equals($p, $chatId)) {
				return true;
			}
		}
		return false;
	}

	public static function textoAjuda(): string {
		return "Sou o assistente operacional da escola (somente consulta).\n\n"
			."Como funciona\n"
			."• Digite uma palavra-chave abaixo — a resposta vem direto do painel, sem gastar tokens de IA.\n"
			."• Se a IA estiver ligada, você também pode perguntar em linguagem natural (ex.: \"como está o financeiro hoje?\").\n"
			."• Não faço baixas, matrículas nem envio de WhatsApp.\n\n"
			."Palavras-chave (sem IA)\n"
			."/ajuda — esta mensagem\n"
			."/resumo — visão geral do dia\n"
			."/agenda — aulas de hoje\n"
			."/inadimplentes — títulos em atraso (mês)\n"
			."/inadimplentes semana — atraso da semana\n"
			."/receber — a receber (semana)\n"
			."/receber mes — a receber no mês\n"
			."/crm — funil de leads\n"
			."/matriculas — matrículas ativas\n"
			."/whatsapp — fila do inbox\n\n"
			."Também aceito sem barra: resumo, agenda, crm, etc.";
	}

	/**
	 * Resposta por palavra-chave (sem IA). Null = não é keyword.
	 */
	public static function responderPalavraChave(int $idAdmin, string $text): ?string {
		$raw = trim($text);
		$norm = mb_strtolower($raw);
		$norm = preg_replace('/\s+/u', ' ', $norm) ?? $norm;
		// Remove @botusername se vier em comando
		$norm = preg_replace('/^(\/[a-z_]+)@[a-z0-9_]+/u', '$1', $norm) ?? $norm;

		if ($norm === '/start' || $norm === '/ajuda' || $norm === 'ajuda' || $norm === 'help' || $norm === '/help') {
			return self::textoAjuda();
		}

		if ($norm === '/resumo' || $norm === 'resumo' || $norm === '/hoje' || $norm === 'hoje') {
			return self::formatarResumo($idAdmin);
		}
		if ($norm === '/agenda' || $norm === 'agenda' || $norm === '/aulas' || $norm === 'aulas') {
			return self::formatarAgenda($idAdmin);
		}
		if (preg_match('#^(/inadimplentes|inadimplentes|/atraso|atraso)(\s+(mes|mês|semana|hoje))?$#u', $norm, $m)) {
			$periodo = 'mes';
			$extra = isset($m[3]) ? trim((string)$m[3]) : '';
			if ($extra === 'semana') {
				$periodo = 'semana';
			} elseif ($extra === 'hoje') {
				$periodo = 'hoje';
			}
			return self::formatarInadimplentes($idAdmin, $periodo);
		}
		if (preg_match('#^(/receber|receber|/areceber|a receber)(\s+(mes|mês|semana|hoje))?$#u', $norm, $m)) {
			$periodo = 'semana';
			$extra = isset($m[3]) ? trim((string)$m[3]) : '';
			if ($extra === 'mes' || $extra === 'mês') {
				$periodo = 'mes';
			} elseif ($extra === 'hoje') {
				$periodo = 'hoje';
			}
			return self::formatarAReceber($idAdmin, $periodo);
		}
		if ($norm === '/crm' || $norm === 'crm' || $norm === '/leads' || $norm === 'leads' || $norm === 'funil') {
			return self::formatarCrm($idAdmin);
		}
		if ($norm === '/matriculas' || $norm === 'matriculas' || $norm === '/matricula' || $norm === 'matricula') {
			return self::formatarMatriculas($idAdmin);
		}
		if ($norm === '/whatsapp' || $norm === 'whatsapp' || $norm === '/wa' || $norm === 'inbox') {
			return self::formatarWhatsapp($idAdmin);
		}

		return null;
	}

	private static function formatarResumo(int $idAdmin): string {
		$r = AgentAnalyticsHelper::resumo($idAdmin);
		$inad = $r['inadimplentes_mes'] ?? [];
		$linhas = [
			'Resumo — '.($r['data'] ?? date('Y-m-d')),
			'',
			'Matrículas ativas: '.(int)($r['matriculas_ativas'] ?? 0),
			'Alunos cadastrados: '.(int)($r['alunos_cadastrados'] ?? 0),
			'Recebido hoje: '.($r['recebido_hoje_br'] ?? 'R$ 0,00'),
			'A receber (semana): '.($r['a_receber_semana_br'] ?? 'R$ 0,00'),
			'Inadimplentes (mês): '.(int)($inad['qtd_titulos'] ?? 0).' título(s) — '.($inad['total_br'] ?? 'R$ 0,00'),
			'Agenda hoje: '.(int)($r['agenda_hoje']['qtd'] ?? 0).' aula(s)',
		];
		$crm = $r['crm'] ?? [];
		if (!empty($crm)) {
			$linhas[] = 'CRM leads: '.(int)($crm['total'] ?? 0).' (conversão '.($crm['conversao_pct'] ?? 0).'%)';
		}
		$wa = $r['whatsapp'] ?? [];
		if (!empty($wa['disponivel'])) {
			$linhas[] = 'WhatsApp fila: '.(int)($wa['fila'] ?? 0).' | não lidas: '.(int)($wa['nao_lidas'] ?? 0);
		}
		$linhas[] = '';
		$linhas[] = 'Sem IA · /ajuda para mais comandos';
		return implode("\n", $linhas);
	}

	private static function formatarAgenda(int $idAdmin): string {
		$a = AgentAnalyticsHelper::agendaHoje($idAdmin, 25);
		if (empty($a['disponivel'])) {
			return "Agenda indisponível neste momento.";
		}
		$qtd = (int)($a['qtd'] ?? 0);
		$linhas = ['Agenda de hoje — '.$qtd.' aula(s)', ''];
		$itens = $a['itens'] ?? [];
		if (empty($itens)) {
			$linhas[] = 'Nenhuma aula listada para hoje.';
		} else {
			$i = 1;
			foreach ($itens as $it) {
				$aluno = trim((string)($it['aluno'] ?? '')) ?: ('Aluno #'.(int)($it['id_aluno'] ?? 0));
				$status = trim((string)($it['status'] ?? ''));
				$linhas[] = $i.'. '.$aluno.($status !== '' ? ' ('.$status.')' : '');
				$i++;
				if ($i > 25) {
					break;
				}
			}
			if ($qtd > count($itens)) {
				$linhas[] = '… e mais '.($qtd - count($itens));
			}
		}
		$linhas[] = '';
		$linhas[] = 'Sem IA · /ajuda';
		return implode("\n", $linhas);
	}

	private static function formatarInadimplentes(int $idAdmin, string $periodo): string {
		$d = AgentAnalyticsHelper::inadimplentesLista($idAdmin, $periodo, 15);
		$rotulo = ['mes' => 'mês', 'semana' => 'semana', 'hoje' => 'hoje'][$periodo] ?? $periodo;
		$linhas = [
			'Inadimplentes ('.$rotulo.')',
			'Títulos: '.(int)($d['qtd_titulos'] ?? 0).' · Total: '.($d['total_br'] ?? 'R$ 0,00'),
			'',
		];
		$itens = $d['itens'] ?? [];
		if (empty($itens)) {
			$linhas[] = 'Nenhum título neste período.';
		} else {
			foreach ($itens as $it) {
				$venc = !empty($it['vencimento']) ? date('d/m/Y', strtotime((string)$it['vencimento'])) : '—';
				$desc = trim((string)($it['descricao'] ?? '')) ?: 'Título';
				$linhas[] = '• '.$desc.' — '.($it['valor_br'] ?? '').' (venc. '.$venc.')';
			}
			if ((int)($d['qtd_titulos'] ?? 0) > count($itens)) {
				$linhas[] = '… lista resumida (top '.count($itens).')';
			}
		}
		$linhas[] = '';
		$linhas[] = 'Sem IA · /inadimplentes semana | /ajuda';
		return implode("\n", $linhas);
	}

	private static function formatarAReceber(int $idAdmin, string $periodo): string {
		$d = AgentAnalyticsHelper::aReceber($idAdmin, $periodo);
		$rotulo = ['mes' => 'mês', 'semana' => 'semana', 'hoje' => 'hoje'][$periodo] ?? $periodo;
		return "A receber (".$rotulo.")\n"
			."Títulos: ".(int)($d['qtd_titulos'] ?? 0)."\n"
			."Total: ".($d['total_br'] ?? 'R$ 0,00')."\n\n"
			."Sem IA · /receber mes | /ajuda";
	}

	private static function formatarCrm(int $idAdmin): string {
		$c = AgentAnalyticsHelper::crmCards($idAdmin);
		$linhas = [
			'CRM — funil de leads',
			'Total: '.(int)($c['total'] ?? 0).' · Conversão: '.($c['conversao_pct'] ?? 0).'%',
			'',
		];
		foreach (($c['por_status'] ?? []) as $s) {
			$linhas[] = '• '.($s['label'] ?? $s['slug']).': '.(int)($s['qtd'] ?? 0);
		}
		$linhas[] = '';
		$linhas[] = 'Sem IA · /ajuda';
		return implode("\n", $linhas);
	}

	private static function formatarMatriculas(int $idAdmin): string {
		$m = AgentAnalyticsHelper::matriculasResumo($idAdmin);
		return "Matrículas\n"
			."Ativas: ".(int)($m['ativas'] ?? 0)."\n"
			."Novas no mês: ".(int)($m['novas_mes'] ?? 0)."\n\n"
			."Sem IA · /ajuda";
	}

	private static function formatarWhatsapp(int $idAdmin): string {
		$w = AgentAnalyticsHelper::whatsapp($idAdmin);
		if (empty($w['disponivel'])) {
			return "WhatsApp inbox indisponível nesta escola.\n\nSem IA · /ajuda";
		}
		return "WhatsApp inbox\n"
			."Fila: ".(int)($w['fila'] ?? 0)."\n"
			."Não lidas: ".(int)($w['nao_lidas'] ?? 0)."\n"
			."Abertas: ".(int)($w['abertas'] ?? 0)."\n\n"
			."Sem IA · /ajuda";
	}

	/**
	 * Monta pacote read-only conforme a pergunta (+ sempre resumo).
	 */
	public static function montarContextoDados(int $idAdmin, string $pergunta): array {
		$p = mb_strtolower($pergunta);
		$dados = [
			'resumo' => AgentAnalyticsHelper::resumo($idAdmin),
		];

		if (preg_match('/agenda|aula|hor[aá]rio|professor/u', $p)) {
			$dados['agenda_hoje'] = AgentAnalyticsHelper::agendaHoje($idAdmin, 40);
		}
		if (preg_match('/inadimpl|atrasad|atraso|d[eé]bit/u', $p)) {
			$periodo = 'mes';
			if (preg_match('/semana/u', $p)) {
				$periodo = 'semana';
			} elseif (preg_match('/hoje/u', $p)) {
				$periodo = 'hoje';
			}
			$dados['inadimplentes'] = AgentAnalyticsHelper::inadimplentesLista($idAdmin, $periodo, 30);
		}
		if (preg_match('/receber|a receber|venc(e|imento)/u', $p)) {
			$periodo = preg_match('/hoje/u', $p) ? 'hoje' : (preg_match('/m[eê]s/u', $p) ? 'mes' : 'semana');
			$dados['a_receber'] = AgentAnalyticsHelper::aReceber($idAdmin, $periodo);
		}
		if (preg_match('/crm|lead|funil|prospect/u', $p)) {
			$dados['crm'] = AgentAnalyticsHelper::crmCards($idAdmin);
		}
		if (preg_match('/matr[ií]cula/u', $p)) {
			$dados['matriculas'] = AgentAnalyticsHelper::matriculasResumo($idAdmin);
		}
		if (preg_match('/whatsapp|inbox|fila.*atend/u', $p)) {
			$dados['whatsapp'] = AgentAnalyticsHelper::whatsapp($idAdmin);
		}

		return $dados;
	}

	private static function systemPrompt(array $dados): string {
		$json = json_encode($dados, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		if ($json === false) {
			$json = '{}';
		}
		// Limita contexto enviado ao LLM
		if (mb_strlen($json) > 12000) {
			$json = mb_substr($json, 0, 12000).'…';
		}

		return "Você é o assistente operacional de uma escola no painel CTI. "
			."Responda em português do Brasil, de forma clara e objetiva. "
			."Use APENAS os dados do JSON abaixo. Não invente números, nomes ou valores. "
			."Se o dado não estiver no JSON, diga que não tem essa informação no painel. "
			."Você é SOMENTE LEITURA: não diga que vai dar baixa, matricular, enviar cobrança ou alterar cadastro. "
			."Valores monetários: prefira o campo *_br quando existir. "
			."Formato: texto simples (Telegram), sem markdown pesado.\n\n"
			."DADOS_JSON:\n".$json;
	}

	/** Ativa webhook HTTPS do bot da escola. */
	public static function ativarWebhook(int $idAdmin): array {
		$gate = self::escolaPodeResponder($idAdmin);
		if (!$gate['ok']) {
			// Token+chat obrigatórios; ainda assim permite setWebhook se tiver token
			$cfg = AgentEscolaConfig::getByIdAdmin($idAdmin);
			if (!$cfg instanceof AgentEscolaConfig || !$cfg->getTelegramBotTokenDescriptografado()) {
				return ['ok' => false, 'message' => $gate['message'] ?? 'Configure o bot primeiro.'];
			}
		} else {
			$cfg = $gate['config'];
		}
		$token = $cfg->getTelegramBotTokenDescriptografado();
		$api = new TelegramBotApi((string)$token);
		$url = TelegramBotApi::webhookUrl($idAdmin);
		if (stripos($url, 'https://') !== 0) {
			return [
				'ok' => false,
				'message' => 'Webhook exige HTTPS público. Em local (XAMPP), use o worker: php worker/telegram_agent.php',
				'webhook_url' => $url,
			];
		}
		$res = $api->setWebhook($url);
		if (empty($res['ok'])) {
			return [
				'ok' => false,
				'message' => $res['description'] ?? 'Falha ao definir webhook.',
				'webhook_url' => $url,
			];
		}
		return [
			'ok' => true,
			'message' => 'Webhook ativado.',
			'webhook_url' => $url,
			'telegram' => $res,
		];
	}

	public static function desativarWebhook(int $idAdmin): array {
		$cfg = AgentEscolaConfig::getByIdAdmin($idAdmin);
		if (!$cfg instanceof AgentEscolaConfig) {
			return ['ok' => false, 'message' => 'Config não encontrada.'];
		}
		$token = $cfg->getTelegramBotTokenDescriptografado();
		if (!$token) {
			return ['ok' => false, 'message' => 'Token ausente.'];
		}
		$api = new TelegramBotApi($token);
		$res = $api->deleteWebhook(false);
		return [
			'ok' => !empty($res['ok']),
			'message' => !empty($res['ok']) ? 'Webhook removido.' : ($res['description'] ?? 'Falha'),
			'telegram' => $res,
		];
	}

	public static function statusWebhook(int $idAdmin): array {
		$cfg = AgentEscolaConfig::getByIdAdmin($idAdmin);
		$url = TelegramBotApi::webhookUrl($idAdmin);
		$out = [
			'webhook_url' => $url,
			'https_ok' => stripos($url, 'https://') === 0,
			'pronto' => false,
			'gate' => self::escolaPodeResponder($idAdmin),
			'webhook_info' => null,
			'bot' => null,
		];
		if (!$cfg instanceof AgentEscolaConfig) {
			return $out;
		}
		$token = $cfg->getTelegramBotTokenDescriptografado();
		if (!$token) {
			return $out;
		}
		$api = new TelegramBotApi($token);
		$me = $api->getMe();
		$info = $api->getWebhookInfo();
		$out['bot'] = $me;
		$out['webhook_info'] = $info;
		$out['pronto'] = !empty($out['gate']['ok']);
		return $out;
	}

	/** Long-poll: processa updates pendentes de uma escola. */
	public static function processarPollEscola(int $idAdmin, int $timeout = 0): array {
		$gate = self::escolaPodeResponder($idAdmin);
		if (!$gate['ok']) {
			return ['ok' => false, 'message' => $gate['message'], 'processados' => 0];
		}
		$cfg = $gate['config'];
		$token = $cfg->getTelegramBotTokenDescriptografado();
		$api = new TelegramBotApi((string)$token);

		$offset = self::lerOffset($idAdmin);
		$res = $api->getUpdates($offset, $timeout, 20);
		if (empty($res['ok'])) {
			return [
				'ok' => false,
				'message' => $res['description'] ?? 'getUpdates falhou (webhook ativo? deleteWebhook antes do poll)',
				'processados' => 0,
			];
		}

		$n = 0;
		$maxUpdateId = $offset > 0 ? $offset - 1 : 0;
		foreach (($res['result'] ?? []) as $upd) {
			if (!is_array($upd)) {
				continue;
			}
			$uid = (int)($upd['update_id'] ?? 0);
			if ($uid > $maxUpdateId) {
				$maxUpdateId = $uid;
			}
			self::processarUpdate($idAdmin, $upd);
			$n++;
		}
		if ($maxUpdateId > 0) {
			self::salvarOffset($idAdmin, $maxUpdateId + 1);
		}

		return ['ok' => true, 'processados' => $n, 'offset' => $maxUpdateId + 1];
	}

	/** Lista escolas com assistente potencialmente ativo para o worker. */
	public static function listarEscolasParaPoll(): array {
		if (!AgentEscolaConfig::tabelaExiste()) {
			return [];
		}
		$sql = 'SELECT id_admin FROM agent_escola_config WHERE llm_ativo = 1 AND telegram_bot_token IS NOT NULL AND telegram_bot_token != "" AND telegram_chat_id IS NOT NULL AND telegram_chat_id != ""';
		$rows = (new Database('agent_escola_config'))->execute($sql)->fetchAll(\PDO::FETCH_ASSOC) ?: [];
		$ids = [];
		foreach ($rows as $r) {
			$id = (int)($r['id_admin'] ?? 0);
			if ($id > 0 && in_array('assistente_ia', ModuleGateHelper::getSlugsEscola($id), true)) {
				$ids[] = $id;
			}
		}
		return $ids;
	}

	private static function lerOffset(int $idAdmin): int {
		if (!self::temColunaOffset()) {
			return 0;
		}
		$row = (new Database('agent_escola_config'))
			->select('id_admin = '.(int)$idAdmin, null, null, 'telegram_update_offset')
			->fetch(\PDO::FETCH_ASSOC);
		return max(0, (int)($row['telegram_update_offset'] ?? 0));
	}

	private static function salvarOffset(int $idAdmin, int $offset): void {
		if (!self::temColunaOffset()) {
			return;
		}
		(new Database('agent_escola_config'))->update(
			'id_admin = '.(int)$idAdmin,
			['telegram_update_offset' => max(0, $offset)]
		);
	}

	private static function temColunaOffset(): bool {
		static $cache = null;
		if ($cache !== null) {
			return $cache;
		}
		try {
			$row = (new Database('agent_escola_config'))->execute(
				"SHOW COLUMNS FROM agent_escola_config LIKE 'telegram_update_offset'"
			)->fetch(\PDO::FETCH_ASSOC);
			$cache = !empty($row);
		} catch (\Throwable $e) {
			$cache = false;
		}
		return $cache;
	}
}
