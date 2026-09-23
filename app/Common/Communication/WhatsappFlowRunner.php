<?php

namespace App\Common\Communication;

use App\Model\Entity\WhatsappConversa;
use App\Model\Entity\WhatsappFluxo;
use App\Model\Entity\WhatsappFluxoSessao;
use App\Model\Entity\WhatsappFluxoLog;
use App\Model\Entity\WhatsappSetor;
use App\Model\Entity\CrmLeads;
use App\Model\Entity\CrmFunis;
use App\Model\Entity\CrmHistorico;

/**
 * Motor de fluxos WhatsApp (Fase A + B).
 * Retorna true se a mensagem foi tratada pelo fluxo.
 */
class WhatsappFlowRunner {

	private const MAX_PASSOS = 40;
	private const DELAY_MAX_SEG = 3;
	private const TIMEOUT_HORAS_PADRAO = 24;

	/** @var bool */
	private static $dryRun = false;
	/** @var list<array{from:string,tipo:string,texto?:string,detalhe?:string}> */
	private static $dryOut = [];
	/** @var WhatsappFluxoSessao|null */
	private static $drySessao = null;

	/** @return bool true = tratado pelo fluxo */
	public static function aoReceberMensagem(WhatsappConversa $conversa, ?string $texto, bool $fromMe): bool {
		if ($fromMe) {
			return false;
		}
		if (!WhatsappFluxo::tabelaExiste() || !WhatsappFluxoSessao::tabelaExiste()) {
			return false;
		}
		if (!WhatsappConversa::temColunasChatbot()) {
			return false;
		}

		$estado = (string)($conversa->chatbot_estado ?: 'novo');
		$status = (string)($conversa->status ?: '');

		if ($estado === 'humano' && $status !== 'fechada') {
			return false;
		}
		if ($conversa->id_atendente && $estado !== 'encerrado' && $status !== 'fechada') {
			return false;
		}

		$texto = trim((string)$texto);
		$idAdmin = (int)$conversa->id_admin;

		if ($texto !== '' && self::isOptOut($texto)) {
			self::encerrarFluxo($conversa, null, 'opt_out');
			self::botTexto($conversa, 'Ok, encerrei o atendimento automático. Digite *menu* se quiser falar com um setor.');
			return true;
		}

		$sessao = self::obterSessao((int)$conversa->id);
		if ($sessao && $estado === 'fluxo') {
			$fluxo = WhatsappFluxo::getByIdAdmin((int)$sessao->fluxo_id, $idAdmin);
			if (!$fluxo || !(int)$fluxo->ativo) {
				self::apagarSessao((int)$conversa->id);
				return false;
			}

			if (self::sessaoExpirada($fluxo, $sessao)) {
				return self::aplicarTimeout($conversa, $fluxo, $sessao);
			}

			if (self::isPedidoMenu($texto)) {
				self::encerrarFluxo($conversa, $fluxo, 'menu');
				return false;
			}
			return self::continuar($conversa, $fluxo, $sessao, $texto);
		}

		if (!in_array($estado, ['novo', '', 'encerrado', 'aguardando_setor'], true) && $status !== 'fechada') {
			return false;
		}

		$fluxo = self::encontrarFluxo($idAdmin, $texto, $estado, $status);
		if (!$fluxo) {
			return false;
		}

		return self::iniciar($conversa, $fluxo, $texto);
	}

	/**
	 * Simula um fluxo sem enviar WhatsApp nem gravar CRM/sessão real.
	 *
	 * @param list<string> $mensagensUsuario
	 * @return array{success:bool,mensagens:list<array>,message?:string}
	 */
	public static function simular(int $idAdmin, array $definicao, array $mensagensUsuario): array {
		if (empty($definicao['nodes']) || empty($definicao['start'])) {
			return ['success' => false, 'message' => 'Definição inválida.', 'mensagens' => []];
		}

		self::$dryRun = true;
		self::$dryOut = [];
		self::$drySessao = null;

		$fake = new WhatsappConversa();
		$fake->id = 0;
		$fake->id_admin = $idAdmin;
		$fake->telefone = '5511999999999';
		$fake->nome_contato = 'Simulação';
		$fake->chatbot_estado = 'novo';
		$fake->status = 'aberta';

		$fluxo = new WhatsappFluxo();
		$fluxo->id = 0;
		$fluxo->id_admin = $idAdmin;
		$fluxo->ativo = 1;
		$fluxo->nome = 'Simulação';
		$fluxo->definicao = $definicao;

		$fila = array_values(array_map(static function ($m) {
			return trim((string)$m);
		}, $mensagensUsuario));
		if ($fila === []) {
			$fila = [''];
		}

		$primeira = array_shift($fila);
		self::$dryOut[] = ['from' => 'user', 'tipo' => 'text', 'texto' => $primeira];
		self::iniciar($fake, $fluxo, $primeira);

		foreach ($fila as $msg) {
			if (!self::$drySessao || (string)($fake->chatbot_estado ?? '') !== 'fluxo') {
				break;
			}
			if ((int)self::$drySessao->aguardando !== 1) {
				break;
			}
			self::$dryOut[] = ['from' => 'user', 'tipo' => 'text', 'texto' => $msg];
			if (self::isOptOut($msg)) {
				self::encerrarFluxo($fake, $fluxo, 'opt_out');
				self::botTexto($fake, 'Ok, encerrei o atendimento automático.');
				break;
			}
			self::continuar($fake, $fluxo, self::$drySessao, $msg);
		}

		$out = self::$dryOut;
		self::$dryRun = false;
		self::$dryOut = [];
		self::$drySessao = null;

		return ['success' => true, 'mensagens' => $out];
	}

	/** Processa sessões aguardando há tempo demais (cron ou botão manual). */
	public static function processarTimeouts(int $idAdmin = 0, int $limite = 50): array {
		if (!WhatsappFluxoSessao::tabelaExiste()) {
			return ['ok' => 0, 'erro' => 0];
		}
		$where = 'aguardando = 1';
		if ($idAdmin > 0) {
			$where .= ' AND id_admin = '.(int)$idAdmin;
		}
		$st = (new \App\Model\Db\Database('whatsapp_fluxo_sessoes'))
			->select($where, 'updated_at ASC', (string)max(1, $limite));
		$ok = 0;
		$erro = 0;
		while ($row = $st->fetchObject(WhatsappFluxoSessao::class)) {
			if (!$row instanceof WhatsappFluxoSessao) {
				continue;
			}
			$fluxo = WhatsappFluxo::getByIdAdmin((int)$row->fluxo_id, (int)$row->id_admin);
			if (!$fluxo) {
				WhatsappFluxoSessao::apagarPorConversa((int)$row->conversa_id);
				continue;
			}
			if (!self::sessaoExpirada($fluxo, $row)) {
				continue;
			}
			$conversa = WhatsappConversa::getById((int)$row->conversa_id, (int)$row->id_admin);
			if (!$conversa) {
				WhatsappFluxoSessao::apagarPorConversa((int)$row->conversa_id);
				continue;
			}
			try {
				self::aplicarTimeout($conversa, $fluxo, $row);
				$ok++;
			} catch (\Throwable $e) {
				$erro++;
			}
		}
		return ['ok' => $ok, 'erro' => $erro];
	}

	private static function isOptOut(string $texto): bool {
		$t = mb_strtolower(trim($texto), 'UTF-8');
		return in_array($t, ['sair', 'parar', 'cancelar', 'stop'], true);
	}

	private static function isPedidoMenu(string $texto): bool {
		$t = mb_strtolower(trim($texto), 'UTF-8');
		return in_array($t, ['menu', '0', 'inicio', 'início'], true);
	}

	private static function settings(WhatsappFluxo $fluxo): array {
		$def = $fluxo->definicaoArray();
		$s = $def['settings'] ?? [];
		return is_array($s) ? $s : [];
	}

	private static function sessaoExpirada(WhatsappFluxo $fluxo, WhatsappFluxoSessao $sessao): bool {
		if ((int)$sessao->aguardando !== 1) {
			return false;
		}
		$horas = (int)(self::settings($fluxo)['timeout_horas'] ?? self::TIMEOUT_HORAS_PADRAO);
		if ($horas <= 0) {
			return false;
		}
		$ref = strtotime((string)($sessao->updated_at ?: $sessao->created_at));
		if ($ref === false) {
			return false;
		}
		return (time() - $ref) >= ($horas * 3600);
	}

	private static function aplicarTimeout(
		WhatsappConversa $conversa,
		WhatsappFluxo $fluxo,
		WhatsappFluxoSessao $sessao
	): bool {
		$acao = (string)(self::settings($fluxo)['timeout_acao'] ?? 'humano');
		WhatsappFluxoLog::registrar(
			(int)$conversa->id_admin,
			(int)$conversa->id,
			(int)$fluxo->id,
			(string)$sessao->node_atual,
			'timeout',
			$acao
		);
		if ($acao === 'encerrar') {
			self::botTexto($conversa, 'Não recebemos sua resposta a tempo. Encerramos o atendimento automático. Digite *menu* para recomeçar.');
			self::encerrarFluxo($conversa, $fluxo, 'timeout');
			return true;
		}
		self::botTexto($conversa, 'Não recebemos sua resposta a tempo. Vamos te encaminhar para um atendente.');
		self::apagarSessao((int)$conversa->id);
		self::atualizarConversa($conversa, [
			'chatbot_estado' => 'fila',
			'status'         => 'aberta',
			'id_atendente'   => null,
		]);
		return true;
	}

	private static function encontrarFluxo(int $idAdmin, string $texto, string $estado, string $status): ?WhatsappFluxo {
		$fluxos = WhatsappFluxo::listarAtivos($idAdmin);
		if (!$fluxos) {
			return null;
		}

		$primeira = in_array($estado, ['novo', '', 'encerrado'], true) || $status === 'fechada';
		$candidatosKeyword = [];
		$candidatoPrimeira = null;

		foreach ($fluxos as $f) {
			$def = $f->definicaoArray();
			$tr = $def['trigger'] ?? [];
			$tipo = (string)($tr['tipo'] ?? 'keyword');

			if ($tipo === 'keyword') {
				$palavra = trim((string)($tr['palavra'] ?? ''));
				if ($palavra === '' || $texto === '') {
					continue;
				}
				$modo = (string)($tr['modo'] ?? 'contem');
				if (self::matchPalavra($texto, $palavra, $modo)) {
					$candidatosKeyword[] = $f;
				}
				continue;
			}

			if (in_array($tipo, ['primeira_msg', 'saudacao'], true) && $primeira) {
				if (!WhatsappEscolaService::menuAutomaticoAtivo($idAdmin)) {
					continue;
				}
				if ($tipo === 'saudacao' && $texto !== '' && !self::isSaudacao($texto)) {
					continue;
				}
				if ($candidatoPrimeira === null) {
					$candidatoPrimeira = $f;
				}
			}
		}

		if ($candidatosKeyword) {
			return $candidatosKeyword[0];
		}
		return $candidatoPrimeira;
	}

	private static function isSaudacao(string $texto): bool {
		$t = mb_strtolower(trim($texto), 'UTF-8');
		return in_array($t, ['oi', 'olá', 'ola', 'bom dia', 'boa tarde', 'boa noite', 'hey', 'hello'], true)
			|| $t === '';
	}

	private static function matchPalavra(string $texto, string $palavra, string $modo): bool {
		$t = mb_strtolower(trim($texto), 'UTF-8');
		$p = mb_strtolower(trim($palavra), 'UTF-8');
		if ($p === '') {
			return false;
		}
		if ($modo === 'exato') {
			return $t === $p;
		}
		if ($modo === 'inicia') {
			return mb_strpos($t, $p) === 0;
		}
		return mb_strpos($t, $p) !== false;
	}

	private static function iniciar(WhatsappConversa $conversa, WhatsappFluxo $fluxo, string $textoEntrada): bool {
		$def = $fluxo->definicaoArray();
		$start = (string)($def['start'] ?? '');
		if ($start === '' || empty($def['nodes'][$start])) {
			return false;
		}

		self::apagarSessao((int)$conversa->id);
		$sessao = new WhatsappFluxoSessao();
		$sessao->id_admin = (int)$conversa->id_admin;
		$sessao->conversa_id = (int)$conversa->id;
		$sessao->fluxo_id = (int)$fluxo->id;
		$sessao->node_atual = $start;
		$sessao->aguardando = 0;
		$sessao->variaveis = [
			'ultima_resposta' => $textoEntrada,
			'telefone'        => (string)($conversa->telefone ?? ''),
			'nome_contato'    => (string)($conversa->nome_contato ?? ''),
		];
		$sessao->passos = 0;
		self::salvarSessao($sessao);

		self::atualizarConversa($conversa, [
			'chatbot_estado' => 'fluxo',
			'status'         => 'aberta',
			'setor_id'       => null,
			'id_atendente'   => null,
			'assigned_at'    => null,
		]);

		if (!self::$dryRun) {
			WhatsappFluxoLog::registrar(
				(int)$conversa->id_admin,
				(int)$conversa->id,
				(int)$fluxo->id,
				$start,
				'start',
				$fluxo->nome
			);
		}

		return self::executarAteEspera($conversa, $fluxo, $sessao);
	}

	private static function continuar(
		WhatsappConversa $conversa,
		WhatsappFluxo $fluxo,
		WhatsappFluxoSessao $sessao,
		string $texto
	): bool {
		$vars = $sessao->variaveisArray();
		$vars['ultima_resposta'] = $texto;
		$sessao->variaveis = $vars;

		$def = $fluxo->definicaoArray();
		$nodeId = (string)$sessao->node_atual;
		$node = $def['nodes'][$nodeId] ?? null;
		if (!$node) {
			self::encerrarFluxo($conversa, $fluxo, 'node_invalido');
			return true;
		}

		$type = (string)($node['type'] ?? '');
		$config = is_array($node['config'] ?? null) ? $node['config'] : [];

		if (!empty($sessao->aguardando)) {
			$next = self::resolverResposta($type, $config, $texto);
			if ($next === null) {
				self::botTexto($conversa, 'Não entendi. Digite uma das opções ou *sair* para encerrar.');
				if ($type === 'ask_options') {
					$txt = self::interp((string)($config['texto'] ?? ''), $vars);
					if ($txt === '') {
						$txt = self::montarTextoOpcoes($config);
					}
					if ($txt !== '') {
						self::botTexto($conversa, $txt);
					}
				}
				self::salvarSessao($sessao);
				return true;
			}
			if ($type === 'ask_text' && !empty($config['var'])) {
				$vars[(string)$config['var']] = $texto;
				$sessao->variaveis = $vars;
			}
			$sessao->node_atual = $next;
			$sessao->aguardando = 0;
			self::salvarSessao($sessao);
			if (!self::$dryRun) {
				WhatsappFluxoLog::registrar(
					(int)$conversa->id_admin,
					(int)$conversa->id,
					(int)$fluxo->id,
					$nodeId,
					'resposta',
					mb_substr($texto, 0, 80)
				);
			}
			return self::executarAteEspera($conversa, $fluxo, $sessao);
		}

		return self::executarAteEspera($conversa, $fluxo, $sessao);
	}

	private static function resolverResposta(string $type, array $config, string $texto): ?string {
		if ($type === 'ask_text') {
			return (string)($config['next'] ?? '');
		}
		if ($type === 'ask_options') {
			$opcoes = $config['opcoes'] ?? [];
			if (!is_array($opcoes)) {
				return null;
			}
			$t = trim($texto);
			foreach ($opcoes as $op) {
				$num = trim((string)($op['num'] ?? ''));
				if ($num !== '' && $t === $num) {
					return (string)($op['next'] ?? '');
				}
			}
			$tNorm = mb_strtolower($t, 'UTF-8');
			foreach ($opcoes as $op) {
				$label = mb_strtolower(trim((string)($op['label'] ?? '')), 'UTF-8');
				if ($label !== '' && $label === $tNorm) {
					return (string)($op['next'] ?? '');
				}
			}
			return null;
		}
		return (string)($config['next'] ?? '');
	}

	private static function executarAteEspera(
		WhatsappConversa $conversa,
		WhatsappFluxo $fluxo,
		WhatsappFluxoSessao $sessao
	): bool {
		$def = $fluxo->definicaoArray();
		$nodes = $def['nodes'] ?? [];
		if (!is_array($nodes)) {
			self::encerrarFluxo($conversa, $fluxo, 'definicao_invalida');
			return true;
		}

		for ($i = 0; $i < self::MAX_PASSOS; $i++) {
			$sessao->passos = (int)$sessao->passos + 1;
			if ((int)$sessao->passos > self::MAX_PASSOS) {
				self::botTexto($conversa, 'Limite de passos do fluxo atingido. Encerrando.');
				self::encerrarFluxo($conversa, $fluxo, 'max_passos');
				return true;
			}

			$nodeId = (string)$sessao->node_atual;
			if ($nodeId === '' || empty($nodes[$nodeId])) {
				self::encerrarFluxo($conversa, $fluxo, 'fim');
				return true;
			}

			$node = $nodes[$nodeId];
			$type = (string)($node['type'] ?? '');
			$config = is_array($node['config'] ?? null) ? $node['config'] : [];
			$vars = $sessao->variaveisArray();

			if (!self::$dryRun) {
				WhatsappFluxoLog::registrar(
					(int)$conversa->id_admin,
					(int)$conversa->id,
					(int)$fluxo->id,
					$nodeId,
					'node',
					$type
				);
			}

			switch ($type) {
				case 'send_text':
					$txt = self::interp((string)($config['texto'] ?? ''), $vars);
					if ($txt !== '') {
						self::botTexto($conversa, $txt);
					}
					$sessao->node_atual = (string)($node['next'] ?? ($config['next'] ?? ''));
					$sessao->aguardando = 0;
					self::salvarSessao($sessao);
					break;

				case 'send_media':
					self::enviarMidiaNo($conversa, $config, $vars);
					$sessao->node_atual = (string)($node['next'] ?? ($config['next'] ?? ''));
					$sessao->aguardando = 0;
					self::salvarSessao($sessao);
					break;

				case 'ask_text':
				case 'ask_options':
					$txt = self::interp((string)($config['texto'] ?? ''), $vars);
					if ($type === 'ask_options' && $txt === '') {
						$txt = self::montarTextoOpcoes($config);
					}
					if ($txt !== '') {
						self::botTexto($conversa, $txt);
					}
					$sessao->aguardando = 1;
					self::salvarSessao($sessao);
					return true;

				case 'condition':
					$ok = self::avaliarCondicao($config, $vars, (int)$conversa->id_admin);
					$sessao->node_atual = $ok
						? (string)($config['next_true'] ?? '')
						: (string)($config['next_false'] ?? '');
					$sessao->aguardando = 0;
					self::salvarSessao($sessao);
					break;

				case 'delay':
					$seg = (int)($config['segundos'] ?? 0);
					if ($seg > 0 && !self::$dryRun) {
						$seg = min($seg, self::DELAY_MAX_SEG);
						sleep($seg);
					} elseif ($seg > 0 && self::$dryRun) {
						self::$dryOut[] = ['from' => 'bot', 'tipo' => 'system', 'texto' => '(delay '.$seg.'s)'];
					}
					$sessao->node_atual = (string)($node['next'] ?? ($config['next'] ?? ''));
					$sessao->aguardando = 0;
					self::salvarSessao($sessao);
					break;

				case 'criar_lead':
					$msg = self::criarLeadCrm($conversa, $config, $vars);
					if (self::$dryRun) {
						self::$dryOut[] = ['from' => 'bot', 'tipo' => 'system', 'texto' => $msg];
					}
					$sessao->node_atual = (string)($node['next'] ?? ($config['next'] ?? ''));
					$sessao->aguardando = 0;
					self::salvarSessao($sessao);
					break;

				case 'set_var':
					$key = preg_replace('/[^a-zA-Z0-9_]/', '', (string)($config['var'] ?? ''));
					if ($key !== '') {
						$vars[$key] = self::interp((string)($config['valor'] ?? ''), $vars);
						$sessao->variaveis = $vars;
					}
					$sessao->node_atual = (string)($node['next'] ?? ($config['next'] ?? ''));
					$sessao->aguardando = 0;
					self::salvarSessao($sessao);
					break;

				case 'goto_setor':
					$setorId = (int)($config['setor_id'] ?? 0);
					self::enviarParaSetor($conversa, $fluxo, $setorId, $config, $vars);
					return true;

				case 'goto_humano':
					$msg = self::interp((string)($config['texto'] ?? 'Aguarde, em breve um atendente irá responder.'), $vars);
					if ($msg !== '') {
						self::botTexto($conversa, $msg);
					}
					self::apagarSessao((int)$conversa->id);
					self::atualizarConversa($conversa, [
						'chatbot_estado' => 'fila',
						'status'         => 'aberta',
						'id_atendente'   => null,
					]);
					if (!self::$dryRun) {
						WhatsappFluxoLog::registrar(
							(int)$conversa->id_admin,
							(int)$conversa->id,
							(int)$fluxo->id,
							$nodeId,
							'humano',
							null
						);
					}
					return true;

				case 'end':
					$msg = self::interp((string)($config['texto'] ?? ''), $vars);
					if ($msg !== '') {
						self::botTexto($conversa, $msg);
					}
					self::encerrarFluxo($conversa, $fluxo, 'end');
					return true;

				default:
					self::encerrarFluxo($conversa, $fluxo, 'tipo_desconhecido');
					return true;
			}

			if ((string)$sessao->node_atual === '' || (string)$sessao->node_atual === $nodeId) {
				if ((string)$sessao->node_atual === $nodeId) {
					self::encerrarFluxo($conversa, $fluxo, 'loop');
					return true;
				}
				self::encerrarFluxo($conversa, $fluxo, 'fim');
				return true;
			}
		}

		self::encerrarFluxo($conversa, $fluxo, 'max_passos');
		return true;
	}

	private static function montarTextoOpcoes(array $config): string {
		$linhas = [];
		$intro = trim((string)($config['intro'] ?? 'Escolha uma opção digitando o *número*:'));
		if ($intro !== '') {
			$linhas[] = $intro;
		}
		foreach ($config['opcoes'] ?? [] as $op) {
			$num = (string)($op['num'] ?? '');
			$label = (string)($op['label'] ?? '');
			if ($num === '') {
				continue;
			}
			$linhas[] = '*'.$num.'* - '.($label !== '' ? $label : 'Opção '.$num);
		}
		return implode("\n", $linhas);
	}

	private static function avaliarCondicao(array $config, array $vars, int $idAdmin): bool {
		$op = (string)($config['op'] ?? 'contem');
		if ($op === 'fora_expediente') {
			$exp = WhatsappEscolaService::estaForaExpediente($idAdmin);
			return !empty($exp['fora']);
		}
		$campo = (string)($config['campo'] ?? 'ultima_resposta');
		$valor = mb_strtolower(trim((string)($config['valor'] ?? '')), 'UTF-8');
		$atual = mb_strtolower(trim((string)($vars[$campo] ?? '')), 'UTF-8');
		if ($op === 'exato' || $op === 'igual') {
			return $atual === $valor;
		}
		if ($op === 'inicia') {
			return $valor !== '' && mb_strpos($atual, $valor) === 0;
		}
		return $valor !== '' && mb_strpos($atual, $valor) !== false;
	}

	private static function criarLeadCrm(WhatsappConversa $conversa, array $config, array $vars): string {
		if (self::$dryRun) {
			$nome = self::interp('{{'.($config['nome_var'] ?? 'nome').'}}', $vars);
			$curso = self::interp('{{'.($config['curso_var'] ?? 'curso').'}}', $vars);
			return '[simulação] Lead CRM: '.($nome ?: 'sem nome').' / '.($curso ?: 'sem curso');
		}

		try {
			$tel = preg_replace('/\D+/', '', (string)$conversa->telefone);
			if (strlen($tel) < 10) {
				return 'Telefone inválido para CRM.';
			}

			$nomeVar = (string)($config['nome_var'] ?? 'nome');
			$cursoVar = (string)($config['curso_var'] ?? 'curso');
			$nome = trim((string)($vars[$nomeVar] ?? ''));
			if ($nome === '') {
				$nome = trim((string)($conversa->nome_contato ?? ''));
			}
			if ($nome === '') {
				$nome = 'Lead WhatsApp '.$tel;
			}
			$curso = trim((string)($vars[$cursoVar] ?? ''));
			$origem = trim((string)($config['origem'] ?? 'WhatsApp bot'));
			if ($origem === '') {
				$origem = 'WhatsApp bot';
			}

			$idAdmin = (int)$conversa->id_admin;
			$funilId = (int)($config['funil_id'] ?? 0);
			if ($funilId <= 0) {
				$funil = CrmFunis::getFunis(
					'id_admin = '.$idAdmin.' AND ativo = 1',
					'id ASC',
					'1'
				)->fetchObject(CrmFunis::class);
				if (!$funil) {
					$nf = new CrmFunis();
					$nf->id_admin = $idAdmin;
					$nf->nome = 'Geral';
					$nf->ativo = 1;
					$nf->cadastrar();
					$funilId = (int)$nf->id;
				} else {
					$funilId = (int)$funil->id;
				}
			}

			$exist = CrmLeads::getLeads(
				'id_admin = '.$idAdmin.' AND whatsapp = "'.addslashes($tel).'"',
				'id DESC',
				'1'
			)->fetchObject(CrmLeads::class);

			if ($exist instanceof CrmLeads) {
				$exist->nome = $nome;
				$exist->curso_interesse = $curso !== '' ? $curso : $exist->curso_interesse;
				$exist->origem = $origem;
				$exist->atualizarDados();
				$leadId = (int)$exist->id;
				$acao = 'atualizado';
			} else {
				$lead = new CrmLeads();
				$lead->id_admin = $idAdmin;
				$lead->usuario_id = 0;
				$lead->visibilidade = 'publico';
				$lead->funil_id = $funilId;
				$lead->nome = $nome;
				$lead->whatsapp = $tel;
				$lead->curso_interesse = $curso !== '' ? $curso : null;
				$lead->origem = $origem;
				$lead->status = 'novo';
				$lead->status_wa = 'pendente';
				$lead->cadastrar();
				$leadId = (int)$lead->id;
				$acao = 'criado';
			}

			if (class_exists(CrmHistorico::class) && $leadId > 0) {
				try {
					$h = new CrmHistorico();
					$h->lead_id = $leadId;
					$h->usuario_id = 0;
					$h->acao = 'whatsapp_fluxo';
					$h->observacao = 'Lead '.$acao.' automaticamente pelo fluxo do WhatsApp.';
					$h->cadastrar();
				} catch (\Throwable $e) {
					// histórico opcional
				}
			}

			WhatsappFluxoLog::registrar(
				$idAdmin,
				(int)$conversa->id,
				0,
				null,
				'crm_lead',
				$acao.'#'.$leadId
			);

			return 'Lead CRM '.$acao.' (#'.$leadId.').';
		} catch (\Throwable $e) {
			return 'Falha ao gravar lead CRM.';
		}
	}

	private static function enviarMidiaNo(WhatsappConversa $conversa, array $config, array $vars): void {
		$tipo = (string)($config['tipo'] ?? 'image');
		$path = (string)($config['path'] ?? '');
		$caption = self::interp((string)($config['caption'] ?? ''), $vars);
		if ($path === '') {
			return;
		}
		if (self::$dryRun) {
			self::$dryOut[] = [
				'from' => 'bot',
				'tipo' => 'media',
				'texto' => '['.$tipo.'] '.$path.($caption !== '' ? ' — '.$caption : ''),
			];
			return;
		}
		$arquivo = [
			'relative' => ltrim($path, '/'),
			'url' => WhatsappMediaStorage::urlPublica(ltrim($path, '/')),
			'mimetype' => $config['mimetype'] ?? null,
		];
		if ($tipo === 'audio') {
			WhatsappChatbotService::enviarAudio($conversa, $arquivo);
		} elseif ($tipo === 'document') {
			WhatsappChatbotService::enviarDocumento($conversa, $arquivo, $caption ?: null, $config['nome'] ?? null);
		} else {
			WhatsappChatbotService::enviarImagem($conversa, $arquivo, $caption !== '' ? $caption : null);
		}
	}

	private static function enviarParaSetor(
		WhatsappConversa $conversa,
		WhatsappFluxo $fluxo,
		int $setorId,
		array $config,
		array $vars
	): void {
		$idAdmin = (int)$conversa->id_admin;
		$setor = $setorId > 0 ? WhatsappSetor::getById($setorId, $idAdmin) : null;
		$msg = self::interp(trim((string)($config['texto'] ?? '')), $vars);
		if ($setor) {
			if ($msg === '') {
				$msg = trim((string)($setor->mensagem_fila ?? ''));
			}
			if ($msg === '') {
				$msg = 'Você foi direcionado para *'.$setor->nome.'*. Aguarde, em breve um atendente irá responder.';
			}
			self::botTexto($conversa, $msg);
			self::apagarSessao((int)$conversa->id);
			self::atualizarConversa($conversa, [
				'chatbot_estado' => 'fila',
				'setor_id'       => (int)$setor->id,
				'status'         => 'aberta',
				'id_atendente'   => null,
			]);
		} else {
			if ($msg === '') {
				$msg = 'Aguarde, em breve um atendente irá responder.';
			}
			self::botTexto($conversa, $msg);
			self::apagarSessao((int)$conversa->id);
			self::atualizarConversa($conversa, [
				'chatbot_estado' => 'fila',
				'status'         => 'aberta',
				'id_atendente'   => null,
			]);
		}
		if (!self::$dryRun) {
			WhatsappFluxoLog::registrar(
				$idAdmin,
				(int)$conversa->id,
				(int)$fluxo->id,
				null,
				'setor',
				$setor ? (string)$setor->nome : 'geral'
			);
		}
	}

	private static function encerrarFluxo(WhatsappConversa $conversa, ?WhatsappFluxo $fluxo, string $motivo): void {
		self::apagarSessao((int)$conversa->id);
		self::atualizarConversa($conversa, [
			'chatbot_estado' => 'encerrado',
			'status'         => 'aberta',
		]);
		if ($fluxo && !self::$dryRun) {
			WhatsappFluxoLog::registrar(
				(int)$conversa->id_admin,
				(int)$conversa->id,
				(int)$fluxo->id,
				null,
				'end',
				$motivo
			);
		}
	}

	private static function botTexto(WhatsappConversa $conversa, string $texto): void {
		if (self::$dryRun) {
			self::$dryOut[] = ['from' => 'bot', 'tipo' => 'text', 'texto' => $texto];
			return;
		}
		WhatsappChatbotService::enviarTexto($conversa, $texto);
	}

	private static function atualizarConversa(WhatsappConversa $conversa, array $dados): void {
		foreach ($dados as $k => $v) {
			$conversa->$k = $v;
		}
		if (self::$dryRun) {
			return;
		}
		$conversa->atualizar($dados);
	}

	private static function obterSessao(int $conversaId): ?WhatsappFluxoSessao {
		if (self::$dryRun) {
			return self::$drySessao;
		}
		return WhatsappFluxoSessao::getByConversa($conversaId);
	}

	private static function salvarSessao(WhatsappFluxoSessao $sessao): void {
		if (self::$dryRun) {
			self::$drySessao = $sessao;
			$sessao->updated_at = date('Y-m-d H:i:s');
			return;
		}
		$sessao->salvar();
	}

	private static function apagarSessao(int $conversaId): void {
		if (self::$dryRun) {
			self::$drySessao = null;
			return;
		}
		WhatsappFluxoSessao::apagarPorConversa($conversaId);
	}

	private static function interp(string $texto, array $vars): string {
		return preg_replace_callback('/\{\{\s*([a-zA-Z0-9_]+)\s*\}\}/', static function ($m) use ($vars) {
			$key = $m[1];
			return isset($vars[$key]) ? (string)$vars[$key] : '';
		}, $texto) ?? $texto;
	}
}
