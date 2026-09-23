<?php

namespace App\Common\Communication;

use App\Model\Entity\EscolaIntegracoes;
use App\Model\Entity\WhatsappNumero;

/**
 * Orquestra instância Evolution por escola (criar, QR, status, teste).
 */
class WhatsappEscolaService {

	public static function status(int $idAdmin): array {
		$api = EvolutionApiService::fromEnv();
		$integracao = EscolaIntegracoes::getByIdAdmin($idAdmin);
		$instance = self::nomeInstanciaEscola($idAdmin, $integracao);
		$instance = $api->resolverNomeInstancia($instance);

		$out = [
			'configurado_env' => $api->isConfigured(),
			'evolution_url'   => $api->isConfigured() ? $api->getBaseUrl() : '',
			'colunas_ok'      => EscolaIntegracoes::temColunasEvolution(),
			'tabelas_ok'      => \App\Model\Entity\WhatsappConversa::tabelaExiste()
				&& \App\Model\Entity\WhatsappMensagem::tabelaExiste(),
			'instance'        => $instance,
			'status'          => ($integracao instanceof EscolaIntegracoes)
				? (string)($integracao->evolution_status ?? 'disconnected')
				: 'disconnected',
			'ativo'           => ($integracao instanceof EscolaIntegracoes)
				? (int)($integracao->evolution_ativo ?? 0)
				: 0,
			'numero'          => ($integracao instanceof EscolaIntegracoes)
				? (string)($integracao->evolution_numero ?? '')
				: '',
			'delay'           => ($integracao instanceof EscolaIntegracoes)
				? (int)($integracao->whatsapp_delay_segundos ?? 60)
				: 60,
			'max_hora'        => ($integracao instanceof EscolaIntegracoes)
				? (int)($integracao->whatsapp_max_hora ?? 20)
				: 20,
			'grupo_delay'     => ($integracao instanceof EscolaIntegracoes && EscolaIntegracoes::temColunaWhatsappGrupoDelay())
				? (int)($integracao->whatsapp_grupo_delay_segundos ?? 600)
				: 600,
			'grupo_delay_ok'  => EscolaIntegracoes::temColunaWhatsappGrupoDelay(),
			'variar_texto'    => ($integracao instanceof EscolaIntegracoes && EscolaIntegracoes::temColunaWhatsappVariarTexto())
				? (int)($integracao->whatsapp_variar_texto ?? 0)
				: 0,
			'variar_texto_ok' => EscolaIntegracoes::temColunaWhatsappVariarTexto(),
			'webhook_url'     => EvolutionApiService::webhookUrl($idAdmin),
			'webhook_ok'      => null,
			'conectado'       => false,
			'qrcode'          => null,
			'erro'            => null,
			'horario_inicio'  => ($integracao instanceof EscolaIntegracoes)
				? (string)($integracao->whatsapp_horario_inicio ?? '')
				: '',
			'horario_fim'     => ($integracao instanceof EscolaIntegracoes)
				? (string)($integracao->whatsapp_horario_fim ?? '')
				: '',
			'dias'            => ($integracao instanceof EscolaIntegracoes)
				? (string)($integracao->whatsapp_dias ?? '1,2,3,4,5')
				: '1,2,3,4,5',
			'msg_fora'        => ($integracao instanceof EscolaIntegracoes)
				? (string)($integracao->whatsapp_msg_fora ?? '')
				: '',
			'horario_ok'      => EscolaIntegracoes::temColunasHorarioWhatsapp(),
			'campanha_respeitar_expediente' => ($integracao instanceof EscolaIntegracoes && EscolaIntegracoes::temColunaCampanhaRespeitarExpediente())
				? (int)($integracao->campanha_respeitar_expediente ?? 0)
				: 0,
			'campanha_respeitar_expediente_ok' => EscolaIntegracoes::temColunaCampanhaRespeitarExpediente(),
			'menu_ok'         => EscolaIntegracoes::temColunasMenuWhatsapp(),
			'menu'            => self::getConfigMenu($idAdmin),
		];

		if (!$api->isConfigured()) {
			$out['erro'] = 'Configure EVOLUTION_URL e EVOLUTION_API_KEY no .env do servidor.';
			return $out;
		}

		if (!EscolaIntegracoes::temColunasEvolution()) {
			$out['erro'] = 'Execute o SQL das colunas Evolution no phpMyAdmin.';
			return $out;
		}

		$state = $api->connectionState($instance);
		if ($state !== null && $api->getLastHttpCode() < 400) {
			$estado = EvolutionApiService::extrairEstado($state);
			$out['status'] = $estado;
			$out['conectado'] = in_array($estado, ['open', 'connected'], true);
			self::persistirStatus($idAdmin, $instance, $estado, $integracao);
			// Webhook só depois de pareado — durante o QR ele derruba a conexão
			if ($out['conectado']) {
				$out['webhook_ok'] = self::garantirWebhook($api, $instance, $idAdmin);
				if (!$out['webhook_ok']) {
					$out['erro'] = 'WhatsApp conectado, mas o webhook não foi aplicado. '
						.'Confira se a URL abaixo é acessível pela Evolution e clique em “Conectar / QR” novamente.';
				}
			}
		} elseif ($api->getLastHttpCode() === 404) {
			// connectionState pode falhar no meio do QR; confirma na lista antes de alarmar
			if ($api->instanciaExiste($instance)) {
				$out['status'] = 'connecting';
				$out['erro'] = null;
			} else {
				$out['status'] = 'not_created';
				$out['erro'] = 'Instância não existe na Evolution. Use “Conectar / QR” ou “Trocar número”.';
				self::persistirStatus($idAdmin, $instance, 'not_created', $integracao, 0, '');
			}
		} else {
			$out['erro'] = $api->getLastError();
		}

		return $out;
	}

	public static function criarOuConectar(int $idAdmin): array {
		return self::conectarInterno($idAdmin, false);
	}

	/**
	 * Apaga a instância na Evolution e cria de novo (troca de número / após exclusão manual).
	 */
	public static function recriarInstancia(int $idAdmin): array {
		return self::conectarInterno($idAdmin, true);
	}

	/**
	 * Sincroniza status com a Evolution (útil quando painel desconectado mas sessão aberta lá).
	 */
	public static function sincronizarInstancia(int $idAdmin): array {
		$api = EvolutionApiService::fromEnv();
		if (!$api->isConfigured()) {
			return ['ok' => false, 'message' => 'Evolution não configurada no .env.'];
		}

		$integracao = EscolaIntegracoes::getByIdAdmin($idAdmin);
		$instance = self::nomeInstanciaEscola($idAdmin, $integracao);
		$instance = $api->resolverNomeInstancia($instance);
		$webhook = EvolutionApiService::webhookUrl($idAdmin);

		$state = $api->connectionState($instance);
		$http = $api->getLastHttpCode();
		if ($state === null || $http >= 400) {
			if ($http === 404 && !$api->instanciaExiste($instance)) {
				self::persistirStatus($idAdmin, $instance, 'not_created', $integracao, 0, '');
				return [
					'ok' => true,
					'message' => 'Nenhuma instância ativa na Evolution para esta escola.',
					'instance' => $instance,
					'status' => 'not_created',
					'conectado' => false,
					'webhook_url' => $webhook,
				];
			}
			return ['ok' => false, 'message' => $api->getLastError() ?: 'Falha ao consultar Evolution.'];
		}

		$estado = EvolutionApiService::extrairEstado($state);
		$conectado = in_array($estado, ['open', 'connected'], true);
		$numero = self::extrairNumeroInstancia($api, $instance, $state);

		$ativo = $conectado ? 1 : 0;
		self::persistirStatus($idAdmin, $instance, $estado, $integracao, $ativo, $numero);

		$webhookOk = null;
		if ($conectado) {
			$webhookOk = self::garantirWebhook($api, $instance, $idAdmin);
		}

		return [
			'ok' => true,
			'message' => $conectado
				? ($webhookOk
					? 'Sincronizado: WhatsApp conectado e webhook aplicado.'
					: 'Conectado na Evolution, mas falhou aplicar webhook — confira se a URL abaixo é acessível pelo servidor Evolution.')
				: 'Sincronizado: status '.$estado.'.',
			'instance' => $instance,
			'status' => $estado,
			'conectado' => $conectado,
			'webhook_url' => $webhook,
			'webhook_ok' => $webhookOk,
			'numero' => $numero,
		];
	}

	/**
	 * Traduz falhas da Evolution (especialmente HTTP 500) e revalida conexão ao vivo.
	 */
	private static function tratarFalhaEvolution(
		EvolutionApiService $api,
		int $idAdmin,
		string $instance,
		string $fallback = 'Falha na Evolution API.'
	): string {
		$http = $api->getLastHttpCode();
		$err = trim($api->getLastError() ?: $fallback);

		$pareceConexao = $http >= 500
			|| stripos($err, 'internal server error') !== false
			|| stripos($err, 'disconnected') !== false
			|| stripos($err, 'not connected') !== false
			|| stripos($err, 'connection closed') !== false
			|| stripos($err, 'session') !== false
			|| stripos($err, 'instável') !== false
			|| stripos($err, 'desconectado') !== false;

		if (!$pareceConexao) {
			return $err;
		}

		$state = $api->connectionState($instance);
		$estado = is_array($state) && $api->getLastHttpCode() < 400
			? EvolutionApiService::extrairEstado($state)
			: 'unknown';
		$conectado = in_array($estado, ['open', 'connected'], true);

		if (!$conectado) {
			$integracao = EscolaIntegracoes::getByIdAdmin($idAdmin);
			self::persistirStatus($idAdmin, $instance, $estado ?: 'disconnected', $integracao, 0);
			return 'WhatsApp desconectado na Evolution (status: '.$estado.'). '
				.'Vá em Comunicação → Conectar / QR antes de enviar campanhas.';
		}

		if ($http >= 500 || stripos($err, 'internal server error') !== false || stripos($err, 'instável') !== false) {
			return 'WhatsApp instável na Evolution — a sessão parece aberta, mas a API falhou. '
				.'Reconecte em Comunicação → Conectar / QR ou Trocar número.';
		}

		return $err;
	}

	private static function nomeInstanciaEscola(int $idAdmin, $integracao = null): string {
		if ($integracao instanceof EscolaIntegracoes && !empty($integracao->evolution_instance)) {
			return (string)$integracao->evolution_instance;
		}
		return EvolutionApiService::nomeInstancia($idAdmin);
	}

	private static function extrairNumeroInstancia(EvolutionApiService $api, string $instance, ?array $state): string {
		$meta = $api->obterDadosInstanciaLista($instance);
		if (is_array($meta)) {
			foreach (['owner', 'number', 'phone', 'wuid'] as $k) {
				if (!empty($meta[$k])) {
					return EvolutionApiService::normalizarTelefone((string)$meta[$k]);
				}
			}
		}
		if (is_array($state)) {
			foreach (['owner', 'wuid'] as $k) {
				if (!empty($state[$k])) {
					return EvolutionApiService::normalizarTelefone((string)$state[$k]);
				}
				if (!empty($state['instance'][$k])) {
					return EvolutionApiService::normalizarTelefone((string)$state['instance'][$k]);
				}
			}
		}
		return '';
	}

	private static function conectarInterno(int $idAdmin, bool $forcarRecriar): array {
		$api = EvolutionApiService::fromEnv();
		if (!$api->isConfigured()) {
			return ['ok' => false, 'message' => 'Evolution não configurada no .env.'];
		}
		if (!EscolaIntegracoes::temColunasEvolution()) {
			return ['ok' => false, 'message' => 'Execute o SQL das colunas Evolution antes.'];
		}

		$integracao = EscolaIntegracoes::getByIdAdmin($idAdmin);
		$instance = self::nomeInstanciaEscola($idAdmin, $integracao);
		$instance = $api->resolverNomeInstancia($instance);
		$webhook = EvolutionApiService::webhookUrl($idAdmin);
		$logs = [];

		$state = $api->connectionState($instance);
		$httpState = $api->getLastHttpCode();
		$existe = $state !== null && $httpState < 400;
		$estadoAtual = $existe ? EvolutionApiService::extrairEstado($state) : '';
		$logs[] = 'state:HTTP '.$httpState.($estadoAtual !== '' ? ' '.$estadoAtual : '');

		// Já conectado: sincroniza painel + webhook (não tenta recriar)
		if ($existe && in_array($estadoAtual, ['open', 'connected'], true)) {
			$numero = self::extrairNumeroInstancia($api, $instance, $state);
			self::persistirStatus($idAdmin, $instance, $estadoAtual, $integracao, 1, $numero);
			$webhookOk = self::garantirWebhook($api, $instance, $idAdmin);

			if ($forcarRecriar) {
				$remocao = $api->removerInstanciaDetalhado($instance);
				$removido = !empty($remocao['ok']);
				$logs[] = 'remove:'.($removido ? 'ok' : 'fail');
				if (!empty($remocao['passos'])) {
					$logs[] = implode(' | ', $remocao['passos']);
				}
				if (!$removido) {
					return [
						'ok' => false,
						'message' => 'Não foi possível remover a instância na Evolution (instância: '.$instance.'). '
							.($remocao['ultimo_erro'] ?? $api->getLastError() ?: '')
							.' Desvincule em WhatsApp → Aparelhos conectados no celular, '
							.'depois use “Remover instância” ou apague manualmente no painel da Evolution. '
							.'['.implode(' | ', $logs).']',
						'instance' => $instance,
						'status' => $estadoAtual,
						'qrcode' => null,
						'conectado' => true,
						'webhook_url' => $webhook,
						'webhook_ok' => $webhookOk,
					];
				}
				$existe = false;
				$estadoAtual = '';
				$forcarRecriar = true;
			} else {
				return [
					'ok' => true,
					'message' => $webhookOk
						? 'WhatsApp conectado e webhook aplicado.'
						: 'WhatsApp conectado, mas o webhook não foi confirmado — clique em “Sincronizar”.',
					'instance' => $instance,
					'status' => $estadoAtual,
					'qrcode' => null,
					'conectado' => true,
					'webhook_url' => $webhook,
					'webhook_ok' => $webhookOk,
				];
			}
		}

		// Só apaga/recria se pedido (Trocar número) ou se realmente não existe.
		// NÃO recriar só por "connecting" — isso apaga o QR no meio do scan.
		if (!$existe && !$forcarRecriar && $httpState === 404 && $api->instanciaExiste($instance)) {
			$existe = true;
			$estadoAtual = 'connecting';
			$logs[] = 'state:existe-via-lista';
		}

		$precisaRecriar = $forcarRecriar || !$existe;

		// Sessão fechada após desconectar: não recria — só gera novo QR na instância existente
		if ($existe && !$forcarRecriar && in_array($estadoAtual, ['close', 'closed', 'disconnected', 'refused'], true)) {
			$precisaRecriar = false;
			$logs[] = 'reconnect:'.$estadoAtual;
		}

		$created = null;
		if ($precisaRecriar) {
			if ($existe || $forcarRecriar) {
				$removido = $api->removerInstancia($instance);
				$logs[] = 'remove:'.($removido ? 'ok' : 'fail HTTP '.$api->getLastHttpCode());
				if (!$removido && $api->instanciaExiste($instance)) {
					$logs[] = 'fallback:connect-existente';
					$precisaRecriar = false;
				}
			}

			if ($precisaRecriar) {
				$created = self::criarInstanciaComRetry($api, $instance, $logs);
				if ($created === null && $api->instanciaExiste($instance)) {
					$logs[] = 'create:ja-existe-connect';
					$precisaRecriar = false;
				} elseif ($created === null) {
					self::persistirStatus($idAdmin, $instance, 'error', $integracao, 0, '');
					return [
						'ok' => false,
						'message' => 'Não foi possível criar a instância na Evolution. '
							.($api->getLastError() ?: '').' ['.implode(' | ', $logs).']',
					];
				}
			}
		}

		// Preferir QR do create (já vem com qrcode:true). Evitar connect+webhook no meio do pareamento.
		$qr = EvolutionApiService::montarQrParaExibicao($created);
		$connect = null;
		if ($qr === null) {
			usleep(500000);
			$connect = $api->obterQrComRetry($instance, 3, 600);
			$logs[] = 'connect:HTTP '.$api->getLastHttpCode();

			if (($connect === null || $api->getLastHttpCode() >= 400)
				&& ($api->getLastHttpCode() === 404 || stripos((string)$api->getLastError(), 'not found') !== false)
			) {
				$created = self::criarInstanciaComRetry($api, $instance, $logs);
				$qr = EvolutionApiService::montarQrParaExibicao($created);
				if ($qr === null) {
					$connect = $api->obterQrComRetry($instance, 3, 600);
					$logs[] = 'connect2:HTTP '.$api->getLastHttpCode();
				}
			}
			if ($qr === null) {
				$qr = EvolutionApiService::montarQrParaExibicao($connect);
			}
		} else {
			$logs[] = 'qr:from-create';
		}

		// NÃO chamar setWebhook aqui — reinicia o socket Baileys e invalida o QR
		$estado = EvolutionApiService::extrairEstado($connect)
			?: EvolutionApiService::extrairEstado($created)
			?: 'connecting';

		self::persistirStatus($idAdmin, $instance, $estado, $integracao, 1, '');

		$conectado = in_array($estado, ['open', 'connected'], true);
		if ($conectado) {
			self::garantirWebhook($api, $instance, $idAdmin);
		}

		if (!$qr && !$conectado) {
			return [
				'ok' => false,
				'message' => 'Falha ao obter QR Code. '
					.($api->getLastError() ? $api->getLastError().' ' : '')
					.'Tente “Trocar número”. ['.implode(' | ', $logs).']',
				'instance' => $instance,
				'status' => $estado,
				'qrcode' => null,
				'conectado' => false,
				'webhook_url' => $webhook,
			];
		}

		return [
			'ok'       => true,
			'message'  => $conectado
				? 'WhatsApp já está conectado.'
				: 'Escaneie o QR agora (válido por ~40s). Ele atualiza sozinho na tela. Não feche esta página.',
			'instance' => $instance,
			'status'   => $estado,
			'qrcode'   => $qr,
			'conectado'=> $conectado,
			'webhook_url' => $webhook,
		];
	}

	/** Configura webhook apenas com sessão já aberta (nunca no meio do QR). */
	private static function garantirWebhook(EvolutionApiService $api, string $instance, int $idAdmin): bool {
		$url = EvolutionApiService::webhookUrl($idAdmin);

		if ($api->webhookEstaConfigurado($instance, $url)) {
			return true;
		}

		for ($t = 0; $t < 3; $t++) {
			if ($t > 0) {
				usleep(600000);
			}
			$api->setWebhook($instance, $url);
			if ($api->getLastHttpCode() < 400 && $api->webhookEstaConfigurado($instance, $url)) {
				return true;
			}
		}

		error_log('[WhatsApp] setWebhook falhou escola '.$idAdmin.' instância '.$instance.': '
			.($api->getLastError() ?: 'HTTP '.$api->getLastHttpCode()));

		return false;
	}

	private static function criarInstanciaComRetry(EvolutionApiService $api, string $instance, array &$logs): ?array {
		$created = $api->createInstance($instance, null);
		$logs[] = 'create:HTTP '.$api->getLastHttpCode().' '.($api->getLastError() ?: 'ok');

		if ($created !== null && $api->getLastHttpCode() < 400) {
			return $created;
		}

		$msg = (string)($api->getLastError() ?: '');
		$jaExiste = stripos($msg, 'already') !== false
			|| stripos($msg, 'exist') !== false
			|| stripos($msg, 'já') !== false
			|| $api->getLastHttpCode() === 403;

		if ($jaExiste || $api->getLastHttpCode() === 403) {
			if ($api->instanciaExiste($instance)) {
				$logs[] = 'create:403-instancia-existente';
				return null;
			}
			$api->removerInstancia($instance);
			$logs[] = 'delete-retry:'.($api->instanciaExiste($instance) ? 'fail' : 'ok');
			usleep(1500000);
			$created = $api->createInstance($instance, null);
			$logs[] = 'create2:HTTP '.$api->getLastHttpCode().' '.($api->getLastError() ?: 'ok');
			if ($created !== null && $api->getLastHttpCode() < 400) {
				return $created;
			}
			if ($api->instanciaExiste($instance)) {
				$logs[] = 'create2:usa-existente';
				return null;
			}
		}

		return null;
	}

	public static function obterQr(int $idAdmin): array {
		$api = EvolutionApiService::fromEnv();
		if (!$api->isConfigured()) {
			return ['ok' => false, 'message' => 'Evolution não configurada no .env.'];
		}

		$integracao = EscolaIntegracoes::getByIdAdmin($idAdmin);
		$instance = self::nomeInstanciaEscola($idAdmin, $integracao);
		$instance = $api->resolverNomeInstancia($instance);

		$state = $api->connectionState($instance);
		if ($api->getLastHttpCode() === 404 && !$api->instanciaExiste($instance)) {
			// Só cria se realmente não existir — evita apagar QR por 404 falso
			return self::criarOuConectar($idAdmin);
		}

		// Só atualiza o QR — não apaga a instância
		$connect = $api->obterQrComRetry($instance, 2, 400);
		if ($connect === null && $api->getLastHttpCode() >= 400) {
			if ($api->getLastHttpCode() === 404 && !$api->instanciaExiste($instance)) {
				return self::criarOuConectar($idAdmin);
			}
			return ['ok' => false, 'message' => $api->getLastError() ?: 'Falha ao obter QR.'];
		}

		$qr = EvolutionApiService::montarQrParaExibicao($connect);
		$estado = EvolutionApiService::extrairEstado($connect);
		self::persistirStatus($idAdmin, $instance, $estado ?: 'connecting', $integracao);

		$conectado = in_array($estado, ['open', 'connected'], true);
		if ($conectado) {
			self::garantirWebhook($api, $instance, $idAdmin);
		}

		return [
			'ok'     => true,
			'qrcode' => $qr,
			'status' => $estado ?: 'connecting',
			'conectado' => $conectado,
			'message'=> $conectado
				? 'Já conectado — QR não é necessário. Para outro número use “Trocar número”.'
				: ($qr ? 'QR atualizado.' : 'Sem QR no momento. Use “Trocar número” se persistir.'),
		];
	}

	public static function salvarLimites(int $idAdmin, array $dados): array {
		if (!EscolaIntegracoes::temColunasEvolution()) {
			return ['ok' => false, 'message' => 'Execute o SQL das colunas Evolution antes.'];
		}

		$existente = EscolaIntegracoes::getByIdAdmin($idAdmin);
		$ob = $existente instanceof EscolaIntegracoes ? $existente : new EscolaIntegracoes;
		$ob->id_admin = $idAdmin;
		$ob->touchEvolution = true;
		$ob->smtp_pass = null;
		$ob->evolution_ativo = !empty($dados['evolution_ativo']) ? 1 : 0;
		$ob->whatsapp_delay_segundos = max(30, (int)($dados['whatsapp_delay_segundos'] ?? 60));
		$ob->whatsapp_max_hora = max(1, (int)($dados['whatsapp_max_hora'] ?? 20));
		// UI envia minutos; persistimos segundos (mín. 1 min)
		if (EscolaIntegracoes::temColunaWhatsappGrupoDelay()) {
			$minutosGrupo = (int)($dados['whatsapp_grupo_delay_minutos'] ?? 0);
			if ($minutosGrupo <= 0 && isset($dados['whatsapp_grupo_delay_segundos'])) {
				$minutosGrupo = (int)ceil(((int)$dados['whatsapp_grupo_delay_segundos']) / 60);
			}
			if ($minutosGrupo <= 0) {
				$minutosGrupo = 60;
			}
			$ob->whatsapp_grupo_delay_segundos = max(60, $minutosGrupo * 60);
		}
		if (EscolaIntegracoes::temColunaWhatsappVariarTexto()) {
			$ob->whatsapp_variar_texto = !empty($dados['whatsapp_variar_texto']) ? 1 : 0;
		}
		$ob->whatsapp_horario_inicio = trim((string)($dados['whatsapp_horario_inicio'] ?? '')) ?: null;
		$ob->whatsapp_horario_fim = trim((string)($dados['whatsapp_horario_fim'] ?? '')) ?: null;
		$ob->whatsapp_dias = trim((string)($dados['whatsapp_dias'] ?? '1,2,3,4,5')) ?: '1,2,3,4,5';
		$ob->whatsapp_msg_fora = trim((string)($dados['whatsapp_msg_fora'] ?? ''));
		if (EscolaIntegracoes::temColunaCampanhaRespeitarExpediente()) {
			$ob->campanha_respeitar_expediente = !empty($dados['campanha_respeitar_expediente']) ? 1 : 0;
		}
		if (EscolaIntegracoes::temColunasMenuWhatsapp()) {
			$ob->whatsapp_menu_ativo = !empty($dados['whatsapp_menu_ativo']) ? 1 : 0;
			$ob->whatsapp_menu_manual_ativo = !empty($dados['whatsapp_menu_manual_ativo']) ? 1 : 0;
			$ob->whatsapp_menu_titulo = trim((string)($dados['whatsapp_menu_titulo'] ?? '')) ?: null;
			$ob->whatsapp_menu_rodape = trim((string)($dados['whatsapp_menu_rodape'] ?? '')) ?: null;
			$ob->whatsapp_menu_msg_invalida = trim((string)($dados['whatsapp_menu_msg_invalida'] ?? '')) ?: null;
			$palavras = trim((string)($dados['whatsapp_menu_palavras'] ?? ''));
			$ob->whatsapp_menu_palavras = $palavras !== '' ? $palavras : null;
		}

		if (!$ob->salvar()) {
			return ['ok' => false, 'message' => EscolaIntegracoes::getUltimoErro() ?: 'Falha ao salvar.'];
		}

		return ['ok' => true, 'message' => 'Configurações de WhatsApp salvas.'];
	}

	/**
	 * @return array{
	 *   menu_ativo:bool,
	 *   menu_manual_ativo:bool,
	 *   titulo:string,
	 *   rodape:string,
	 *   msg_invalida:string,
	 *   palavras:list<string>
	 * }
	 */
	public static function getConfigMenu(int $idAdmin): array {
		$defaults = [
			'menu_ativo'         => true,
			'menu_manual_ativo'  => true,
			'titulo'             => 'Olá! Sou o assistente virtual. Escolha o setor digitando o *número*:',
			'rodape'             => 'Digite *menu* a qualquer momento para ver as opções novamente.',
			'msg_invalida'       => 'Opção inválida. Digite o *número* do setor ou *menu* para ver as opções novamente.',
			'palavras'           => ['menu', '0', 'inicio', 'início', 'oi', 'olá', 'ola', 'bom dia', 'boa tarde', 'boa noite'],
		];

		if (!EscolaIntegracoes::temColunasMenuWhatsapp()) {
			return $defaults;
		}

		$cfg = EscolaIntegracoes::getByIdAdmin($idAdmin);
		if (!$cfg instanceof EscolaIntegracoes) {
			return $defaults;
		}

		$palavrasRaw = trim((string)($cfg->whatsapp_menu_palavras ?? ''));
		$palavras = $defaults['palavras'];
		if ($palavrasRaw !== '') {
			$parsed = array_values(array_filter(array_map(static function ($p) {
				return mb_strtolower(trim($p), 'UTF-8');
			}, explode(',', $palavrasRaw))));
			if ($parsed !== []) {
				$palavras = $parsed;
			}
		}

		$titulo = trim((string)($cfg->whatsapp_menu_titulo ?? ''));
		$rodape = trim((string)($cfg->whatsapp_menu_rodape ?? ''));
		$msgInvalida = trim((string)($cfg->whatsapp_menu_msg_invalida ?? ''));

		return [
			'menu_ativo'        => (int)($cfg->whatsapp_menu_ativo ?? 1) === 1,
			'menu_manual_ativo' => (int)($cfg->whatsapp_menu_manual_ativo ?? 1) === 1,
			'titulo'            => $titulo !== '' ? $titulo : $defaults['titulo'],
			'rodape'            => $rodape !== '' ? $rodape : $defaults['rodape'],
			'msg_invalida'      => $msgInvalida !== '' ? $msgInvalida : $defaults['msg_invalida'],
			'palavras'          => $palavras,
		];
	}

	public static function menuAutomaticoAtivo(int $idAdmin): bool {
		return self::getConfigMenu($idAdmin)['menu_ativo'];
	}

	public static function testarEnvio(int $idAdmin, string $telefone, string $mensagem = ''): array {
		$texto = trim($mensagem) !== '' ? trim($mensagem) : 'Teste de WhatsApp — Painel CTI.';
		return self::enviarTexto($idAdmin, $telefone, $texto);
	}

	/**
	 * Grupos (@g.us) e listas de transmissão (@broadcast) da Evolution.
	 * @return array{ok:bool,message?:string,itens:array<int,array{jid:string,nome:string,kind:string}>}
	 */
	public static function listarGruposEListas(int $idAdmin): array {
		$status = self::status($idAdmin);
		if (empty($status['conectado'])) {
			return ['ok' => false, 'message' => 'WhatsApp não está conectado.', 'itens' => []];
		}

		$api = EvolutionApiService::fromEnv();
		$instance = (string)$status['instance'];
		$itens = [];
		$vistos = [];

		$grupos = $api->fetchAllGroups($instance, false);
		$fetchHttp = $api->getLastHttpCode();
		$fetchOk = is_array($grupos) && $fetchHttp < 400;
		if (is_array($grupos)) {
			$rows = isset($grupos[0]) || $grupos === [] ? $grupos : ($grupos['groups'] ?? $grupos['data'] ?? [$grupos]);
			foreach ($rows as $g) {
				if (!is_array($g)) {
					continue;
				}
				$jid = (string)($g['id'] ?? $g['jid'] ?? $g['groupId'] ?? '');
				if ($jid !== '' && strpos($jid, '@') === false) {
					$jid .= '@g.us';
				}
				$jid = EvolutionApiService::normalizarDestino($jid);
				if (!EvolutionApiService::isJidGrupoOuLista($jid) || isset($vistos[$jid])) {
					continue;
				}
				$vistos[$jid] = true;
				$itens[] = [
					'jid'  => $jid,
					'nome' => (string)($g['subject'] ?? $g['name'] ?? $g['pushName'] ?? $jid),
					'kind' => 'grupo',
				];
			}
		}

		$chats = $api->findChats($instance);
		if (is_array($chats)) {
			$rows = isset($chats[0]) || $chats === [] ? $chats : ($chats['chats'] ?? $chats['data'] ?? [$chats]);
			foreach ($rows as $c) {
				if (!is_array($c)) {
					continue;
				}
				$jid = (string)($c['id'] ?? $c['remoteJid'] ?? $c['jid'] ?? '');
				$jid = EvolutionApiService::normalizarDestino($jid);
				if ($jid === '' || isset($vistos[$jid])) {
					continue;
				}
				if (strpos(strtolower($jid), '@broadcast') === false && strpos(strtolower($jid), '@g.us') === false) {
					continue;
				}
				$vistos[$jid] = true;
				$kind = strpos(strtolower($jid), '@broadcast') !== false ? 'lista' : 'grupo';
				$itens[] = [
					'jid'  => $jid,
					'nome' => (string)($c['name'] ?? $c['pushName'] ?? $c['subject'] ?? $jid),
					'kind' => $kind,
				];
			}
		}

		usort($itens, static function ($a, $b) {
			return strcasecmp($a['nome'], $b['nome']);
		});

		$total = count($itens);
		if ($total === 0) {
			$msg = 'Nenhum grupo/lista retornado.';
			if (!$fetchOk) {
				$msg = self::tratarFalhaEvolution($api, $idAdmin, $instance, 'Falha ao listar grupos na Evolution.');
			} else {
				$msg .= ' Confira se a Evolution tem permissão de grupos e se há listas no aparelho.';
			}
			return ['ok' => false, 'message' => $msg, 'itens' => []];
		}

		$message = $total.' destino(s) encontrados.';
		if (!$fetchOk) {
			$message = $total.' destino(s) via chats recentes (lista incompleta). '
				.self::tratarFalhaEvolution($api, $idAdmin, $instance, 'Falha ao buscar todos os grupos.');
		}

		return [
			'ok' => true,
			'itens' => $itens,
			'message' => $message,
			'lista_incompleta' => !$fetchOk,
		];
	}

	/** Checklist operacional (UI Comunicação). */
	public static function checklist(int $idAdmin): array {
		$status = self::status($idAdmin);
		$api = EvolutionApiService::fromEnv();
		$itens = [];

		$itens[] = [
			'ok' => !empty($status['configurado_env']),
			'label' => 'Credenciais EVOLUTION_URL / EVOLUTION_API_KEY no .env',
		];
		$itens[] = [
			'ok' => !empty($status['colunas_ok']),
			'label' => 'Colunas Evolution em escola_integracoes',
		];
		$itens[] = [
			'ok' => !empty($status['tabelas_ok']),
			'label' => 'Tabelas whatsapp_conversas / whatsapp_mensagens',
		];
		$itens[] = [
			'ok' => !empty($status['conectado']),
			'label' => 'Instância conectada (status open)',
			'detalhe' => 'Status atual: '.($status['status'] ?? '—'),
		];
		$itens[] = [
			'ok' => !empty($status['webhook_url']),
			'label' => 'URL do webhook (painel)',
			'detalhe' => (string)($status['webhook_url'] ?? ''),
		];
		$itens[] = [
			'ok' => !empty($status['webhook_ok']) || empty($status['conectado']),
			'label' => 'Webhook aplicado na Evolution',
			'detalhe' => !empty($status['conectado'])
				? (!empty($status['webhook_ok']) ? 'OK' : 'Falhou — clique Conectar / QR ou Atualizar status')
				: 'Conecte o WhatsApp primeiro',
		];
		$itens[] = [
			'ok' => !empty($status['numero']),
			'label' => 'Número pareado preenchido',
			'detalhe' => (string)($status['numero'] ?? 'ainda vazio'),
		];

		$fora = self::estaForaExpediente($idAdmin);
		$itens[] = [
			'ok' => true,
			'label' => 'Horário de atendimento',
			'detalhe' => $fora['configurado']
				? ($fora['fora'] ? 'Fora do expediente agora' : 'Dentro do expediente agora')
				: 'Não configurado (atende 24h)',
		];

		$itens[] = [
			'ok' => true,
			'label' => 'Se travar em Connecting',
			'detalhe' => 'Use “Trocar número”, delete a instância no painel Evolution se preciso, escaneie o QR sem mexer no webhook.',
		];
		$itens[] = [
			'ok' => true,
			'label' => 'Cron / fila de campanhas',
			'detalhe' => 'php worker/campanhas.php (e-mail + WhatsApp). Cobrança: php worker/cobranca.php',
		];

		return [
			'conectado' => !empty($status['conectado']),
			'status' => $status,
			'itens' => $itens,
			'api_ok' => $api->isConfigured(),
		];
	}

	/**
	 * @return array{fora:bool,configurado:bool,mensagem:string}
	 */
	public static function estaForaExpediente(int $idAdmin): array {
		$cfg = EscolaIntegracoes::getByIdAdmin($idAdmin);
		$out = ['fora' => false, 'configurado' => false, 'mensagem' => ''];
		if (!$cfg instanceof EscolaIntegracoes || !EscolaIntegracoes::temColunasHorarioWhatsapp()) {
			return $out;
		}

		$inicio = trim((string)($cfg->whatsapp_horario_inicio ?? ''));
		$fim = trim((string)($cfg->whatsapp_horario_fim ?? ''));
		$dias = trim((string)($cfg->whatsapp_dias ?? ''));
		if ($inicio === '' || $fim === '') {
			return $out;
		}

		$out['configurado'] = true;
		$out['mensagem'] = trim((string)($cfg->whatsapp_msg_fora ?? ''))
			?: 'Olá! Nosso atendimento pelo WhatsApp funciona em horário comercial. Retornaremos assim que possível.';

		$diaSemana = (int)date('N'); // 1=seg … 7=dom
		$diasOk = [];
		foreach (preg_split('/[^0-9]+/', $dias) ?: [] as $p) {
			$n = (int)$p;
			if ($n >= 1 && $n <= 7) {
				$diasOk[] = $n;
			}
		}
		if ($diasOk && !in_array($diaSemana, $diasOk, true)) {
			$out['fora'] = true;
			return $out;
		}

		$agora = date('H:i');
		$ini = substr($inicio, 0, 5);
		$fi = substr($fim, 0, 5);
		if ($ini <= $fi) {
			$out['fora'] = ($agora < $ini || $agora >= $fi);
		} else {
			// cruza meia-noite
			$out['fora'] = ($agora < $ini && $agora >= $fi);
		}
		return $out;
	}

	/**
	 * Envio de texto sem conversa de inbox (campanhas, cobrança, aniversário, teste).
	 * @return array{ok:bool,message:string}
	 */
	public static function enviarTexto(int $idAdmin, string $telefone, string $mensagem): array {
		$status = self::status($idAdmin);
		if (!$status['conectado']) {
			return ['ok' => false, 'message' => 'WhatsApp não está conectado.'];
		}

		$texto = trim($mensagem);
		if ($texto === '') {
			return ['ok' => false, 'message' => 'Mensagem vazia.'];
		}

		$api = EvolutionApiService::fromEnv();
		$res = $api->sendText($status['instance'], $telefone, $texto);

		if ($res === null || $api->getLastHttpCode() >= 400) {
			return [
				'ok' => false,
				'message' => self::tratarFalhaEvolution($api, $idAdmin, (string)$status['instance'], 'Falha ao enviar mensagem.'),
			];
		}

		return ['ok' => true, 'message' => 'Mensagem enviada.'];
	}

	/**
	 * Envio de campanha WhatsApp (texto e/ou mídia) para telefone, grupo ou lista.
	 * @param array{tipo?:string,path?:string,nome?:string,mime?:string}|null $midia
	 * @return array{ok:bool,message:string}
	 */
	public static function enviarCampanha(int $idAdmin, string $destino, string $texto, ?array $midia = null): array {
		$status = self::status($idAdmin);
		if (empty($status['conectado'])) {
			return ['ok' => false, 'message' => 'WhatsApp não está conectado.'];
		}

		$instance = (string)$status['instance'];
		$api = EvolutionApiService::fromEnv();
		$destino = EvolutionApiService::normalizarDestino($destino);
		if ($destino === '') {
			return ['ok' => false, 'message' => 'Destino inválido.'];
		}

		$texto = trim($texto);
		$tipoMidia = strtolower((string)($midia['tipo'] ?? ''));
		$pathRel = ltrim(str_replace('\\', '/', (string)($midia['path'] ?? '')), '/');
		$pathAbs = $pathRel !== '' ? self::caminhoUploadAbsoluto($pathRel) : null;
		$mime = $midia['mime'] ?? null;
		$nome = $midia['nome'] ?? ($pathAbs ? basename($pathAbs) : null);

		if (in_array($tipoMidia, ['image', 'document', 'audio'], true)) {
			if ($pathAbs === null || !is_file($pathAbs)) {
				return ['ok' => false, 'message' => 'Arquivo de mídia da campanha não encontrado no servidor.'];
			}

			if ($tipoMidia === 'audio') {
				$res = $api->sendAudio($instance, $destino, $pathAbs, is_string($mime) ? $mime : null);
				if ($res === null || $api->getLastHttpCode() >= 400) {
					return ['ok' => false, 'message' => $api->getLastError() ?: 'Falha ao enviar áudio.'];
				}
				if ($texto !== '') {
					$api->sendText($instance, $destino, $texto);
				}
				return ['ok' => true, 'message' => 'Áudio enviado.'];
			}

			$mediatype = $tipoMidia === 'document' ? 'document' : 'image';
			$res = $api->sendMedia(
				$instance,
				$destino,
				$pathAbs,
				$mediatype,
				is_string($mime) ? $mime : null,
				$texto !== '' ? $texto : null,
				is_string($nome) ? $nome : null
			);
			if ($res === null || $api->getLastHttpCode() >= 400) {
				return ['ok' => false, 'message' => self::tratarFalhaEvolution($api, $idAdmin, $instance, 'Falha ao enviar mídia.')];
			}
			return ['ok' => true, 'message' => 'Mídia enviada.'];
		}

		if ($texto === '') {
			return ['ok' => false, 'message' => 'Mensagem vazia.'];
		}

		$res = $api->sendText($instance, $destino, $texto);
		if ($res === null || $api->getLastHttpCode() >= 400) {
			return [
				'ok' => false,
				'message' => self::tratarFalhaEvolution($api, $idAdmin, $instance, 'Falha ao enviar mensagem.'),
			];
		}
		return ['ok' => true, 'message' => 'Mensagem enviada.'];
	}

	private static function caminhoUploadAbsoluto(string $relative): ?string {
		$relative = ltrim(str_replace(['..', '\\'], ['', '/'], $relative), '/');
		if ($relative === '' || strpos($relative, 'uploads/') !== 0) {
			return null;
		}
		$root = rtrim(str_replace('\\', '/', realpath(__DIR__.'/../../../') ?: (__DIR__.'/../../..')), '/');
		$abs = $root.'/'.$relative;
		return is_file($abs) ? $abs : null;
	}

	/**
	 * @param bool $apagarInstancia true = remove na Evolution (necessário para trocar número com certeza)
	 */
	public static function desconectar(int $idAdmin, bool $apagarInstancia = false): array {
		$api = EvolutionApiService::fromEnv();
		if (!$api->isConfigured()) {
			return ['ok' => false, 'message' => 'Evolution não configurada no .env.'];
		}

		$integracao = EscolaIntegracoes::getByIdAdmin($idAdmin);
		$instance = self::nomeInstanciaEscola($idAdmin, $integracao);
		$instance = $api->resolverNomeInstancia($instance);

		$passos = [];
		$instanceExiste = $api->instanciaExiste($instance);
		$passos[] = 'existe:'.($instanceExiste ? 'sim' : 'não');

		if (!$instanceExiste) {
			self::persistirStatus($idAdmin, $instance, 'disconnected', $integracao, 0, '');
			return [
				'ok' => true,
				'message' => 'Nenhuma instância ativa na Evolution. Use “Conectar / QR” para parear.',
			];
		}

		$logoutOk = false;
		if (!$apagarInstancia) {
			$api->logout($instance);
			$httpLogout = $api->getLastHttpCode();
			$logoutOk = ($httpLogout >= 200 && $httpLogout < 300) || $httpLogout === 404;
			$passos[] = 'logout:HTTP '.$httpLogout.($logoutOk ? '' : ' '.($api->getLastError() ?: ''));
		}

		$removido = false;
		$remocao = ['ok' => false, 'passos' => [], 'ultimo_erro' => null];
		if ($apagarInstancia || !$logoutOk) {
			$remocao = $api->removerInstanciaDetalhado($instance);
			$removido = !empty($remocao['ok']);
			$passos = array_merge($passos, $remocao['passos'] ?? []);
		}

		if ($apagarInstancia || !$logoutOk) {
			if (!$removido) {
				self::persistirStatus($idAdmin, $instance, 'disconnected', $integracao, 0, '');
				$detalhe = (string)($remocao['ultimo_erro'] ?? $api->getLastError() ?: '');
				return [
					'ok' => false,
					'message' => 'Não foi possível remover a instância na Evolution (instância: '.$instance.'). '
						.($detalhe !== '' ? $detalhe.' ' : '')
						.'Desvincule em WhatsApp → Aparelhos conectados e tente “Remover instância” novamente. '
						.'['.implode(' | ', $passos).']',
				];
			}
			self::persistirStatus($idAdmin, $instance, 'disconnected', $integracao, 0, '');
			return [
				'ok' => true,
				'message' => 'Instância removida na Evolution. Clique em “Conectar / QR” para parear um novo número.',
			];
		}

		self::persistirStatus($idAdmin, $instance, 'disconnected', $integracao, 0, '');

		return [
			'ok' => true,
			'message' => 'Sessão desconectada na Evolution. Clique em “Conectar / QR” para parear novamente.',
		];
	}

	public static function atualizarStatusConexao(int $idAdmin, string $estado, ?string $numero = null): void {
		$integracao = EscolaIntegracoes::getByIdAdmin($idAdmin);
		$api = EvolutionApiService::fromEnv();
		$instance = self::nomeInstanciaEscola($idAdmin, $integracao);
		if ($api->isConfigured()) {
			$instance = $api->resolverNomeInstancia($instance);
		}
		$ativo = in_array($estado, ['open', 'connected'], true) ? 1 : null;
		self::persistirStatus($idAdmin, $instance, $estado, $integracao, $ativo, $numero);

		if (in_array($estado, ['open', 'connected'], true)) {
			if ($api->isConfigured()) {
				self::garantirWebhook($api, $instance, $idAdmin);
			}
		}
	}

	private static function persistirStatus(
		int $idAdmin,
		string $instance,
		string $estado,
		$integracao = null,
		?int $ativo = null,
		?string $numero = null
	): void {
		if (!EscolaIntegracoes::temColunasEvolution()) {
			return;
		}

		$ob = $integracao instanceof EscolaIntegracoes ? $integracao : new EscolaIntegracoes;
		$ob->id_admin = $idAdmin;
		$ob->touchEvolution = true;
		$ob->smtp_pass = null;
		$ob->evolution_instance = $instance;
		$ob->evolution_status = $estado;
		if ($ativo !== null) {
			$ob->evolution_ativo = $ativo;
		} elseif (!($integracao instanceof EscolaIntegracoes)) {
			$ob->evolution_ativo = 1;
		}
		// null = não altera; '' = limpa número
		if ($numero !== null) {
			$ob->evolution_numero = $numero;
		}
		if (!isset($ob->whatsapp_delay_segundos)) {
			$ob->whatsapp_delay_segundos = 5;
		}
		if (!isset($ob->whatsapp_max_hora)) {
			$ob->whatsapp_max_hora = 40;
		}
		$ob->salvar();

		WhatsappNumero::syncFromIntegracao(
			$idAdmin,
			$instance,
			$estado,
			($numero !== null && $numero !== '') ? $numero : null
		);
	}
}
