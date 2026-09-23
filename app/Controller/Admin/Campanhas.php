<?php

namespace App\Controller\Admin;

use App\Utils\View;
use App\Common\Helpers\TenantHelper;
use App\Common\Helpers\CampanhaPacingHelper;
use App\Common\Helpers\CampanhaSegmentoHelper;
use App\Common\Helpers\EmailValidator;
use App\Common\Communication\CampanhaWorker;
use App\Common\Communication\WhatsappEscolaService;
use App\Common\Communication\WhatsappMediaStorage;
use App\Common\Helpers\SocialMediaStorage;
use App\Common\Helpers\SocialBibliotecaService;
use App\Model\Entity\Campanhas as EntityCampanhas;
use App\Model\Entity\CampanhaFila;
use App\Model\Entity\CampanhaWorkerRun;
use App\Model\Entity\CrmLeads;
use App\Model\Entity\SocialBiblioteca;
use App\Model\Entity\User as EntityUser;
use App\Common\Communication\EvolutionApiService;

class Campanhas extends Page {

	private static $statusLabels = [
		'rascunho'   => 'Rascunho',
		'agendada'   => 'Agendada',
		'enviando'   => 'Enviando',
		'concluida'  => 'Concluída',
		'pausada'    => 'Pausada',
		'cancelada'  => 'Cancelada',
	];

	public static function index($request) {
		$content = View::render('admin/modules/campanhas/index', []);
		return parent::getPanel('Campanhas', $content, 'marketing');
	}

	public static function getInfo($request) {
		if (!EntityCampanhas::tabelaExiste()) {
			return json_encode([
				'success' => false,
				'message' => 'Crie as tabelas campanhas e campanha_fila no phpMyAdmin.',
			]);
		}

		// Libera lock da sessão PHP para listar/processar em paralelo com iniciar/retomar
		TenantHelper::getIdAdmin();
		if (session_status() === PHP_SESSION_ACTIVE) {
			session_write_close();
		}

		$postVars = $request->getPostVars();
		$acao = $postVars['acao'] ?? '';

		switch ($acao) {
			case 'listar':
				return self::listar($postVars);
			case 'salvar':
				return self::salvar($postVars);
			case 'preview':
				return self::preview($postVars);
			case 'iniciar':
				return self::iniciar($postVars);
			case 'pausar':
				return self::pausar($postVars);
			case 'cancelar':
				return self::cancelar($postVars);
			case 'detalhes':
				return self::detalhes($postVars);
			case 'relatorio':
				return self::relatorio($postVars);
			case 'exportar_relatorio':
				return self::exportarRelatorio($postVars);
			case 'processar':
				return self::processarFila($postVars);
			case 'listar_grupos_wa':
				return self::listarGruposWa();
			case 'biblioteca_listar':
				return self::bibliotecaListar($postVars);
			default:
				return json_encode(['success' => false, 'message' => 'Ação inválida.']);
		}
	}

	private static function listarGruposWa(): string {
		$idAdmin = TenantHelper::getIdAdmin();
		$res = WhatsappEscolaService::listarGruposEListas($idAdmin);
		return json_encode([
			'success' => !empty($res['ok']),
			'message' => $res['message'] ?? '',
			'itens' => $res['itens'] ?? [],
		], JSON_UNESCAPED_UNICODE);
	}

	private static function listar(array $postVars): string {
		$idAdmin = TenantHelper::getIdAdmin();
		$canal = self::normalizarCanal($postVars['canal'] ?? '');
		$where = 'id_admin = '.(int)$idAdmin;
		if ($canal !== '') {
			$where .= ' AND canal = "'.addslashes($canal).'"';
		}

		// Fila avança via poll/cron na página — não bloquear o listar com envio WhatsApp
		$page = max(1, (int)($postVars['page'] ?? 1));
		$limit = 20;
		$rowCount = EntityCampanhas::get($where, null, null, 'COUNT(*) as q')->fetch(\PDO::FETCH_ASSOC);
		$total = (int)($rowCount['q'] ?? 0);
		$pages = max(1, (int)ceil($total / $limit));
		if ($page > $pages) {
			$page = $pages;
		}
		$offset = ($page - 1) * $limit;
		$results = EntityCampanhas::get($where, 'id DESC', $offset.','.$limit);

		$lista = [];
		while ($row = $results->fetchObject(EntityCampanhas::class)) {
			$lista[] = self::formatarCampanha($row);
		}

		return json_encode([
			'success'   => true,
			'campanhas' => $lista,
			'pacing'    => CampanhaWorker::infoPacingGrupo($idAdmin),
			'pacing_1a1' => CampanhaWorker::infoPacing1a1($idAdmin),
			'expediente' => CampanhaPacingHelper::infoExpediente($idAdmin),
			'cron'      => self::metaCron(),
			'pagination' => [
				'page' => $page,
				'pages' => $pages,
				'total' => $total,
				'limit' => $limit,
			],
		]);
	}

	/** Processa 1 item se houver campanha enviando (respeita pacing de grupos). */
	private static function tickFilaLeve(int $idAdmin): void {
		$results = EntityCampanhas::get(
			'id_admin = '.(int)$idAdmin.' AND status = "enviando"'
		);
		$temAtiva = false;
		$soGrupos = true;
		while ($c = $results->fetchObject(EntityCampanhas::class)) {
			$temAtiva = true;
			if ($c->ehCampanhaGrupos()) {
				CampanhaWorker::reabastecerFilaGrupos($c);
			} else {
				$soGrupos = false;
			}
		}
		if (!$temAtiva) {
			return;
		}

		$config = \App\Model\Entity\EscolaIntegracoes::getByIdAdmin($idAdmin);
		if (CampanhaPacingHelper::respeitarExpediente($config instanceof \App\Model\Entity\EscolaIntegracoes ? $config : null)) {
			$exp = WhatsappEscolaService::estaForaExpediente($idAdmin);
			if (!empty($exp['fora'])) {
				return;
			}
		}

		$pacing = CampanhaWorker::infoPacingGrupo($idAdmin);
		if ($soGrupos && empty($pacing['pode_enviar'])) {
			return;
		}
		CampanhaWorker::processar($idAdmin, 1, false);
		$ativos = EntityCampanhas::get('id_admin = '.(int)$idAdmin.' AND status = "enviando"');
		while ($c = $ativos->fetchObject(EntityCampanhas::class)) {
			$c->recalcularTotais();
		}
	}

	private static function formatarCampanha(EntityCampanhas $c, bool $contagensAoVivo = false): array {
		$id = (int)$c->id;
		$idAdmin = (int)$c->id_admin;
		$canal = ($c->canal ?? 'email') === 'whatsapp' ? 'whatsapp' : 'email';
		$aoVivo = $contagensAoVivo || (string)$c->status === 'enviando';

		if ($aoVivo) {
			$resumo = CampanhaFila::resumoPorCampanha($id, $idAdmin);
			$total = $resumo['total'];
			$enviados = $resumo['enviados'];
			$erros = $resumo['erros'];
			$pendentes = $resumo['pendentes'];
		} else {
			$total = (int)$c->total;
			$enviados = (int)$c->enviados;
			$erros = (int)$c->erros;
			$pendentes = CampanhaFila::contarPorCampanha($id, $idAdmin, 'pendente');
		}

		$progressoPct = ($total > 0 && !$c->ehCampanhaGrupos())
			? min(100, (int)round((($total - $pendentes) / $total) * 100))
			: null;

		return [
			'id'          => $id,
			'titulo'      => $c->titulo,
			'assunto'     => $c->assunto,
			'canal'       => $canal,
			'canal_label' => $canal === 'whatsapp' ? 'WhatsApp' : 'E-mail',
			'status'      => $c->status,
			'status_label'=> self::$statusLabels[$c->status] ?? $c->status,
			'total'       => $total,
			'enviados'    => $enviados,
			'erros'       => $erros,
			'pendentes'   => $pendentes,
			'progresso_pct' => $progressoPct,
			'eh_grupos'   => $c->ehCampanhaGrupos() ? 1 : 0,
			'criada_em'   => $c->criada_em ? date('d/m/Y H:i', strtotime($c->criada_em)) : '',
			'segmento'    => json_decode($c->segmento ?? '{}', true) ?: [],
			'pacing_resumo' => CampanhaPacingHelper::resumoParaUi($c),
			'mensagem'    => $c->mensagem,
			'midia'       => self::extrairMidiaSegmento($c->segmento ?? null),
		];
	}

	private static function salvar(array $postVars): string {
		$idAdmin = TenantHelper::getIdAdmin();
		$usuarioId = TenantHelper::getUsuarioId();

		$titulo = trim($postVars['titulo'] ?? '');
		$assunto = trim($postVars['assunto'] ?? '');
		$mensagem = trim($postVars['mensagem'] ?? '');
		$id = (int)($postVars['id'] ?? 0);
		$removerMidia = !empty($postVars['remover_midia']);

		if ($titulo === '') {
			return json_encode(['success' => false, 'message' => 'Preencha o título.']);
		}

		$ob = null;
		$editavelCompleto = true;
		$editavelConteudo = true;

		if ($id > 0) {
			$ob = EntityCampanhas::getById($id, $idAdmin);
			if (!$ob instanceof EntityCampanhas) {
				return json_encode(['success' => false, 'message' => 'Campanha não encontrada.']);
			}
			$statusAtual = (string)$ob->status;
			$editavelConteudo = in_array($statusAtual, ['rascunho', 'pausada', 'enviando'], true);
			$editavelCompleto = ($statusAtual === 'rascunho');
			if (!$editavelConteudo) {
				return json_encode(['success' => false, 'message' => 'Esta campanha não pode ser editada neste status.']);
			}
		}

		if ($id > 0 && !$editavelCompleto) {
			// Em envio: só conteúdo (mensagem/mídia/título)
			$canal = ($ob->canal ?? 'email') === 'whatsapp' ? 'whatsapp' : 'email';
			$segmento = json_decode($ob->segmento ?? '{}', true) ?: [];
			if ($canal === 'whatsapp' && $assunto === '') {
				$assunto = $titulo;
			}
			if ($canal === 'email' && ($assunto === '' || $mensagem === '')) {
				return json_encode(['success' => false, 'message' => 'Preencha assunto e mensagem do e-mail.']);
			}
			if ($removerMidia) {
				unset($segmento['midia']);
			}
		} else {
			$canal = self::normalizarCanal($postVars['canal'] ?? 'email') ?: 'email';
			$tipoSegmento = $postVars['segmento_tipo'] ?? 'alunos_matriculados';
			$statusLead = $postVars['status_lead'] ?? '';

			if ($canal === 'email' && ($assunto === '' || $mensagem === '')) {
				return json_encode(['success' => false, 'message' => 'Preencha assunto e mensagem do e-mail.']);
			}
			if ($canal === 'whatsapp' && $assunto === '') {
				$assunto = $titulo;
			}
			if (!array_key_exists($tipoSegmento, CampanhaSegmentoHelper::getTipos())) {
				return json_encode(['success' => false, 'message' => 'Segmento inválido.']);
			}
			if ($tipoSegmento === 'whatsapp_grupos' && $canal !== 'whatsapp') {
				return json_encode(['success' => false, 'message' => 'Grupos/listas só podem ser usados no canal WhatsApp.']);
			}
			if ($tipoSegmento === 'emails_invalidos_alunos' && $canal !== 'whatsapp') {
				return json_encode(['success' => false, 'message' => 'Alunos com e-mail inválido só podem ser contatados pelo WhatsApp.']);
			}

			$segmento = [
				'tipo'        => $tipoSegmento,
				'status_lead' => $statusLead,
			];
			if ($tipoSegmento === 'inadimplentes') {
				$segmento = array_merge($segmento, CampanhaSegmentoHelper::normalizarSegmentoInadimplentes($postVars));
			}
			if (in_array($tipoSegmento, ['aniversariantes_mes', 'aniversariantes_dia'], true)) {
				$segmento = array_merge($segmento, CampanhaSegmentoHelper::normalizarSegmentoAniversariantes($postVars));
			}
			if ($tipoSegmento === 'whatsapp_grupos') {
				$destinos = self::parseDestinosGrupos($postVars);
				if (empty($destinos)) {
					return json_encode(['success' => false, 'message' => 'Selecione ao menos um grupo ou lista de transmissão.']);
				}
				$segmento['destinos'] = $destinos;
			}
			$segmento['pacing'] = CampanhaPacingHelper::parseFromPost($postVars, $canal);

			if ($id > 0) {
				$segAntigo = json_decode($ob->segmento ?? '{}', true) ?: [];
				if (!$removerMidia && empty($_FILES['arquivo']['tmp_name']) && empty($postVars['midia_biblioteca_path']) && !empty($segAntigo['midia'])) {
					$segmento['midia'] = $segAntigo['midia'];
				}
			} else {
				$ob = new EntityCampanhas;
				$ob->id_admin = $idAdmin;
				$ob->criada_por = $usuarioId;
				$ob->tipo = 'manual';
				$ob->status = 'rascunho';
			}
		}

		if ($canal === 'whatsapp' && !$removerMidia && !empty($_FILES['arquivo']) && is_array($_FILES['arquivo'])) {
			$midiaTipo = strtolower(trim((string)($postVars['midia_tipo'] ?? '')));
			if (!in_array($midiaTipo, ['image', 'document', 'audio'], true)) {
				$ft = (string)($_FILES['arquivo']['type'] ?? '');
				if (strpos($ft, 'image/') === 0) {
					$midiaTipo = 'image';
				} elseif (strpos($ft, 'audio/') === 0) {
					$midiaTipo = 'audio';
				} else {
					$midiaTipo = 'document';
				}
			}
			$saved = WhatsappMediaStorage::salvarUpload($idAdmin, $_FILES['arquivo']);
			if (!$saved) {
				return json_encode(['success' => false, 'message' => 'Falha ao salvar mídia (máx. 15 MB).']);
			}
			$segmento['midia'] = [
				'tipo' => $midiaTipo,
				'path' => $saved['relative'],
				'nome' => basename((string)($_FILES['arquivo']['name'] ?? $saved['relative'])),
				'mime' => $saved['mimetype'] ?? null,
				'url'  => $saved['url'] ?? WhatsappMediaStorage::urlPublica($saved['relative']),
				'origem' => 'upload',
			];
		} elseif ($canal === 'whatsapp' && !$removerMidia) {
			$bibPath = trim((string)($postVars['midia_biblioteca_path'] ?? ''));
			if ($bibPath !== '' && empty($_FILES['arquivo']['tmp_name'])) {
				$midiaBib = self::resolverMidiaBiblioteca($idAdmin, $bibPath);
				if (!$midiaBib) {
					return json_encode(['success' => false, 'message' => 'Mídia da biblioteca inválida ou não encontrada.']);
				}
				$segmento['midia'] = $midiaBib;
			}
		}

		if ($canal === 'whatsapp' && $mensagem === '' && empty($segmento['midia'])) {
			return json_encode(['success' => false, 'message' => 'Informe uma mensagem e/ou anexe imagem, documento ou áudio.']);
		}

		$ob->canal = $canal;
		$ob->titulo = $titulo;
		$ob->assunto = $assunto;
		$ob->mensagem = $mensagem;
		$ob->segmento = json_encode($segmento, JSON_UNESCAPED_UNICODE);

		if ($id > 0) {
			$ok = $ob->atualizar();
		} else {
			$ok = $ob->cadastrar();
		}

		if (!$ok) {
			return json_encode(['success' => false, 'message' => 'Não foi possível salvar a campanha.']);
		}

		return json_encode([
			'success'  => true,
			'message'  => !$editavelCompleto ? 'Mensagem/mídia atualizadas. Valem para os próximos envios.' : 'Campanha salva.',
			'campanha' => self::formatarCampanha($ob, true),
		]);
	}

	private static function preview(array $postVars): string {
		$idAdmin = TenantHelper::getIdAdmin();
		$canal = self::normalizarCanal($postVars['canal'] ?? 'email') ?: 'email';
		$segmento = self::montarSegmento($postVars);
		$destinatarios = CampanhaSegmentoHelper::resolverDestinatarios($idAdmin, $segmento, $canal);
		$amostra = array_slice($destinatarios, 0, 5);

		return json_encode([
			'success' => true,
			'total'   => count($destinatarios),
			'amostra' => $amostra,
			'canal'   => $canal,
		]);
	}

	private static function iniciar(array $postVars): string {
		$idAdmin = TenantHelper::getIdAdmin();
		$id = (int)($postVars['id'] ?? 0);

		$ob = EntityCampanhas::getById($id, $idAdmin);
		if (!$ob instanceof EntityCampanhas) {
			return json_encode(['success' => false, 'message' => 'Campanha não encontrada.']);
		}

		if (!in_array($ob->status, ['rascunho', 'pausada'], true)) {
			return json_encode(['success' => false, 'message' => 'Campanha não pode ser iniciada neste status.']);
		}

		$canal = ($ob->canal ?? 'email') === 'whatsapp' ? 'whatsapp' : 'email';

		if ($canal === 'whatsapp') {
			$statusWa = WhatsappEscolaService::status($idAdmin);
			if (empty($statusWa['conectado'])) {
				return json_encode([
					'success' => false,
					'message' => 'WhatsApp não está conectado. Pareie o número em Configurações → Comunicação antes de iniciar.',
				]);
			}
		}

		if ($ob->status === 'pausada') {
			$ob->status = 'enviando';
			$ob->agendada_para = null;
			$ob->atualizar();
			$isGrupo = $ob->ehCampanhaGrupos();
			if ($isGrupo) {
				CampanhaWorker::agendarContinuacaoGrupos($idAdmin, $id);
			}
			$ob = EntityCampanhas::getById($id, $idAdmin);
			$pend = CampanhaFila::contarPorCampanha($id, $idAdmin, 'pendente');
			$pacing = CampanhaWorker::infoPacingGrupo($idAdmin);
			return json_encode([
				'success'  => true,
				'retomada' => true,
				'message'  => $isGrupo
					? 'Campanha retomada. Reenvio recorrente ativo (~'.$pacing['delay_minutos'].' min).'
					: 'Campanha retomada.'.($pend > 0 ? ' '.$pend.' mensagem(ns) na fila.' : ' Nenhuma pendência na fila.'),
				'campanha' => self::formatarCampanha($ob, true),
				'pacing'   => $pacing,
			]);
		}

		$segmento = json_decode($ob->segmento ?? '{}', true) ?: [];
		$isGrupo = ($segmento['tipo'] ?? '') === 'whatsapp_grupos';
		$destinatarios = CampanhaSegmentoHelper::resolverDestinatarios($idAdmin, $segmento, $canal);

		if (empty($destinatarios)) {
			$tipoSeg = $segmento['tipo'] ?? '';
			if ($tipoSeg === 'emails_invalidos_alunos') {
				$msg = 'Nenhum aluno com e-mail inválido e WhatsApp válido neste segmento.';
			} elseif ($canal === 'whatsapp') {
				$msg = 'Nenhum destinatário com WhatsApp válido neste segmento.';
			} else {
				$msg = 'Nenhum destinatário com e-mail válido para este segmento.';
			}
			return json_encode(['success' => false, 'message' => $msg]);
		}

		CampanhaFila::limparPendentesCampanha($id, $idAdmin);

		$itens = [];
		foreach ($destinatarios as $dest) {
			$itens[] = [
				'campanha_id'       => $id,
				'id_admin'          => $idAdmin,
				'destinatario_tipo' => $dest['destinatario_tipo'],
				'destinatario_id'   => $dest['destinatario_id'] ?? null,
				'nome'              => $dest['nome'] ?? '',
				'contato'           => $dest['contato'],
				'curso'             => $dest['curso'] ?? '',
			];
		}

		CampanhaFila::inserirLote($itens);

		$ob->status = 'enviando';
		$ob->agendada_para = null;
		$ob->atualizar();
		$ob->recalcularTotais();

		if ($isGrupo) {
			CampanhaWorker::agendarContinuacaoGrupos($idAdmin, $id);
		}

		$ob = EntityCampanhas::getById($id, $idAdmin);
		$pend = CampanhaFila::contarPorCampanha($id, $idAdmin, 'pendente');
		$pacing = CampanhaWorker::infoPacingGrupo($idAdmin);
		$msg = $isGrupo
			? 'Campanha iniciada (recorrente). Envio em segundo plano (~'.$pacing['delay_minutos'].' min entre rodadas).'
			: 'Campanha iniciada. '.$pend.' mensagem(ns) na fila. O envio continua em segundo plano.';

		return json_encode([
			'success'  => true,
			'message'  => $msg,
			'campanha' => self::formatarCampanha($ob, true),
			'pacing'   => $pacing,
		]);
	}

	private static function pausar(array $postVars): string {
		$idAdmin = TenantHelper::getIdAdmin();
		$id = (int)($postVars['id'] ?? 0);
		$ob = EntityCampanhas::getById($id, $idAdmin);

		if (!$ob instanceof EntityCampanhas || $ob->status !== 'enviando') {
			return json_encode(['success' => false, 'message' => 'Campanha não está em envio.']);
		}

		$ob->status = 'pausada';
		$ob->atualizar();

		return json_encode(['success' => true, 'message' => 'Campanha pausada.', 'campanha' => self::formatarCampanha($ob)]);
	}

	private static function cancelar(array $postVars): string {
		$idAdmin = TenantHelper::getIdAdmin();
		$id = (int)($postVars['id'] ?? 0);
		$ob = EntityCampanhas::getById($id, $idAdmin);

		if (!$ob instanceof EntityCampanhas) {
			return json_encode(['success' => false, 'message' => 'Campanha não encontrada.']);
		}

		$pend = CampanhaFila::contarPorCampanha($id, $idAdmin, 'pendente');
		CampanhaFila::cancelarPendentes($id, $idAdmin);

		// Sem pendentes e já houve envio: encerra como concluída; senão cancela
		if ($pend <= 0 && (int)$ob->enviados > 0) {
			$ob->status = 'concluida';
			$msg = 'Campanha encerrada.';
		} else {
			$ob->status = 'cancelada';
			$msg = 'Campanha cancelada. Pendentes removidos da fila.';
		}
		$ob->atualizar();
		$ob->recalcularTotais();

		return json_encode(['success' => true, 'message' => $msg, 'campanha' => self::formatarCampanha($ob)]);
	}

	private static function detalhes(array $postVars): string {
		$idAdmin = TenantHelper::getIdAdmin();
		$id = (int)($postVars['id'] ?? 0);
		$ob = EntityCampanhas::getById($id, $idAdmin);

		if (!$ob instanceof EntityCampanhas) {
			return json_encode(['success' => false, 'message' => 'Campanha não encontrada.']);
		}

		if ((string)$ob->status === 'enviando') {
			$ob->recalcularTotais();
			$ob = EntityCampanhas::getById($id, $idAdmin);
		}

		$erros = [];
		$results = CampanhaFila::get(
			'campanha_id = '.(int)$id.' AND id_admin = '.(int)$idAdmin.' AND status = "erro"',
			'id DESC',
			'10'
		);

		while ($row = $results->fetchObject(CampanhaFila::class)) {
			$erros[] = [
				'nome'    => $row->nome,
				'contato' => $row->contato,
				'erro'    => $row->erro_msg,
			];
		}

		return json_encode([
			'success'  => true,
			'campanha' => self::formatarCampanha($ob, true),
			'erros'    => $erros,
			'mensagem' => $ob->mensagem,
			'assunto'  => $ob->assunto,
			'resumo_relatorio' => CampanhaFila::resumoRelatorio($id, $idAdmin),
		]);
	}

	private static function relatorio(array $postVars): string {
		$idAdmin = TenantHelper::getIdAdmin();
		$id = (int)($postVars['id'] ?? 0);
		$ob = EntityCampanhas::getById($id, $idAdmin);

		if (!$ob instanceof EntityCampanhas) {
			return json_encode(['success' => false, 'message' => 'Campanha não encontrada.']);
		}

		$canal = ($ob->canal ?? 'email') === 'whatsapp' ? 'whatsapp' : 'email';
		$dados = CampanhaFila::listarRelatorio($id, $idAdmin, [
			'status' => $postVars['status'] ?? '',
			'busca'  => $postVars['busca'] ?? '',
			'page'   => (int)($postVars['page'] ?? 1),
			'limit'  => (int)($postVars['limit'] ?? 25),
		]);

		$itens = [];
		foreach ($dados['itens'] as $row) {
			$itens[] = self::formatarItemRelatorio($row, $canal);
		}

		return json_encode([
			'success'    => true,
			'campanha'   => self::formatarCampanha($ob, true),
			'itens'      => $itens,
			'resumo'     => CampanhaFila::resumoRelatorio($id, $idAdmin),
			'pagination' => $dados['pagination'],
			'canal'      => $canal,
		], JSON_UNESCAPED_UNICODE);
	}

	private static function exportarRelatorio(array $postVars): string {
		$idAdmin = TenantHelper::getIdAdmin();
		$id = (int)($postVars['id'] ?? 0);
		$ob = EntityCampanhas::getById($id, $idAdmin);

		if (!$ob instanceof EntityCampanhas) {
			return json_encode(['success' => false, 'message' => 'Campanha não encontrada.']);
		}

		$canal = ($ob->canal ?? 'email') === 'whatsapp' ? 'whatsapp' : 'email';
		$status = trim((string)($postVars['status'] ?? ''));
		$where = 'campanha_id = '.(int)$id.' AND id_admin = '.(int)$idAdmin;
		if ($status !== '' && in_array($status, ['enviado', 'erro', 'pendente', 'cancelado'], true)) {
			$where .= ' AND status = "'.addslashes($status).'"';
		}
		$order = 'FIELD(status, "erro", "pendente", "enviado", "cancelado"), id DESC';
		$results = CampanhaFila::get($where, $order, '5000');

		$linhas = [];
		while ($row = $results->fetchObject(CampanhaFila::class)) {
			$linhas[] = self::formatarItemRelatorio($row, $canal);
		}

		return json_encode([
			'success'  => true,
			'titulo'   => $ob->titulo,
			'canal'    => $canal,
			'itens'    => $linhas,
		], JSON_UNESCAPED_UNICODE);
	}

	private static function formatarItemRelatorio(CampanhaFila $row, string $canalCampanha): array {
		$contatos = self::contatosAlternativosDestinatario(
			(string)($row->destinatario_tipo ?? ''),
			(int)($row->destinatario_id ?? 0)
		);
		$contatoUsado = trim((string)($row->contato ?? ''));
		$canalAlt = '';
		$contatoAlt = '';
		if ($row->status === 'erro') {
			if ($canalCampanha === 'whatsapp' && $contatos['email'] !== '') {
				$canalAlt = 'email';
				$contatoAlt = $contatos['email'];
			} elseif ($canalCampanha === 'email' && $contatos['whatsapp'] !== '') {
				$canalAlt = 'whatsapp';
				$contatoAlt = $contatos['whatsapp'];
			}
		}

		$statusLabels = [
			'pendente'  => 'Pendente',
			'enviado'   => 'Enviado',
			'erro'      => 'Erro',
			'cancelado' => 'Cancelado',
		];

		return [
			'id'                => (int)$row->id,
			'nome'              => (string)($row->nome ?? ''),
			'destinatario_tipo' => (string)($row->destinatario_tipo ?? ''),
			'destinatario_id'   => (int)($row->destinatario_id ?? 0),
			'contato'           => $contatoUsado,
			'email'             => $contatos['email'],
			'whatsapp'          => $contatos['whatsapp'],
			'status'            => (string)$row->status,
			'status_label'      => $statusLabels[$row->status] ?? (string)$row->status,
			'erro'              => (string)($row->erro_msg ?? ''),
			'enviado_em'        => $row->enviado_em ? date('d/m/Y H:i', strtotime($row->enviado_em)) : '',
			'curso'             => (string)($row->curso ?? ''),
			'canal_usado'       => $canalCampanha,
			'canal_alternativo' => $canalAlt,
			'contato_alternativo' => $contatoAlt,
		];
	}

	/** @return array{email:string,whatsapp:string} */
	private static function contatosAlternativosDestinatario(string $tipo, int $id): array {
		$vazio = ['email' => '', 'whatsapp' => ''];
		if ($id <= 0) {
			return $vazio;
		}

		if ($tipo === 'aluno') {
			$user = EntityUser::getUserById($id);
			if (!$user instanceof EntityUser) {
				return $vazio;
			}
			return [
				'email'    => EmailValidator::normalizar($user->email ?? ''),
				'whatsapp' => EvolutionApiService::normalizarTelefone((string)($user->whatsapp ?? '')),
			];
		}

		if ($tipo === 'lead') {
			$lead = CrmLeads::getLeadById($id);
			if (!$lead instanceof CrmLeads) {
				return $vazio;
			}
			return [
				'email'    => EmailValidator::normalizar($lead->email ?? ''),
				'whatsapp' => EvolutionApiService::normalizarTelefone((string)($lead->whatsapp ?? '')),
			];
		}

		return $vazio;
	}

	private static function processarFila(array $postVars): string {
		$idAdmin = TenantHelper::getIdAdmin();
		$silencioso = !empty($postVars['silencioso']);
		// Poll web: 1 msg por request; manual/cron pode usar limite maior
		$limiteMax = $silencioso ? 1 : 10;
		$limite = min($limiteMax, max(1, (int)($postVars['limite'] ?? 5)));
		$resumo = CampanhaWorker::processar($idAdmin, $limite, !$silencioso);

		$results = EntityCampanhas::get('id_admin = '.(int)$idAdmin.' AND status = "enviando"');
		while ($c = $results->fetchObject(EntityCampanhas::class)) {
			$c->recalcularTotais();
		}

		$pacing = CampanhaWorker::infoPacingGrupo($idAdmin);
		$pacing1a1 = CampanhaWorker::infoPacing1a1($idAdmin);
		$expediente = CampanhaPacingHelper::infoExpediente($idAdmin);
		$msg = 'Processados: '.$resumo['processados'].'. Enviados: '.$resumo['enviados'].'. Erros: '.$resumo['erros'].'.';
		if ((int)$resumo['enviados'] === 0 && !empty($resumo['escolas'][$idAdmin]['whatsapp']['motivo'])) {
			$motivo = $resumo['escolas'][$idAdmin]['whatsapp']['motivo'];
			if ($motivo === 'fora_expediente') {
				$msg .= ' Fora do expediente — envios retomam no horário configurado em Comunicação.';
			} elseif ($motivo === 'pacing_grupo' && $pacing['proximo_em_segundos'] > 0) {
				$min = (int)ceil($pacing['proximo_em_segundos'] / 60);
				$msg .= ' Aguardando intervalo de grupos (~'.$min.' min).';
			} elseif ($motivo === 'pacing_1a1' && $pacing1a1['proximo_em_segundos'] > 0) {
				$msg .= ' Aguardando intervalo 1:1 (~'.$pacing1a1['proximo_em_segundos'].' s).';
			} elseif ($motivo === 'limite_hora') {
				$msg .= ' Limite por hora atingido — aguarde ou ajuste em Comunicação / campanha.';
			}
		}

		return json_encode([
			'success' => true,
			'message' => $msg,
			'resumo'  => $resumo,
			'pacing'  => $pacing,
			'pacing_1a1' => $pacing1a1,
			'expediente' => $expediente,
		]);
	}

	private static function montarSegmento(array $postVars): array {
		$seg = [
			'tipo'        => $postVars['segmento_tipo'] ?? 'alunos_matriculados',
			'status_lead' => $postVars['status_lead'] ?? '',
		];
		if (($seg['tipo'] ?? '') === 'inadimplentes') {
			$seg = array_merge($seg, CampanhaSegmentoHelper::normalizarSegmentoInadimplentes($postVars));
		}
		if (in_array($seg['tipo'] ?? '', ['aniversariantes_mes', 'aniversariantes_dia'], true)) {
			$seg = array_merge($seg, CampanhaSegmentoHelper::normalizarSegmentoAniversariantes($postVars));
		}
		if (($seg['tipo'] ?? '') === 'whatsapp_grupos') {
			$seg['destinos'] = self::parseDestinosGrupos($postVars);
		}
		return $seg;
	}

	/** @deprecated use CampanhaSegmentoHelper::normalizarSegmentoInadimplentes */
	private static function normalizarParcelasAtrasoMin($valor): int {
		$n = CampanhaSegmentoHelper::normalizarSegmentoInadimplentes([
			'parcelas_atraso_modo' => 'min',
			'parcelas_atraso_qtd' => $valor,
		]);
		return (int)$n['qtd'];
	}

	private static function parseDestinosGrupos(array $postVars): array {
		$raw = $postVars['destinos_json'] ?? '[]';
		if (is_array($raw)) {
			$data = $raw;
		} else {
			$data = json_decode((string)$raw, true);
		}
		if (!is_array($data)) {
			return [];
		}
		$out = [];
		foreach ($data as $d) {
			if (!is_array($d)) {
				continue;
			}
			$jid = EvolutionApiService::normalizarDestino((string)($d['jid'] ?? ''));
			if (!EvolutionApiService::isJidGrupoOuLista($jid)) {
				continue;
			}
			$out[] = [
				'jid'  => $jid,
				'nome' => trim((string)($d['nome'] ?? '')) ?: $jid,
				'kind' => (strpos(strtolower($jid), '@broadcast') !== false || ($d['kind'] ?? '') === 'lista')
					? 'lista'
					: 'grupo',
			];
		}
		return $out;
	}

	private static function normalizarCanal($canal): string {
		$canal = strtolower(trim((string)$canal));
		if ($canal === 'whatsapp' || $canal === 'email') {
			return $canal;
		}
		return '';
	}

	private static function extrairMidiaSegmento($segmentoRaw): ?array {
		$seg = is_array($segmentoRaw) ? $segmentoRaw : (json_decode((string)$segmentoRaw, true) ?: []);
		$m = $seg['midia'] ?? null;
		if (!is_array($m) || empty($m['path'])) {
			return null;
		}
		$path = ltrim(str_replace('\\', '/', (string)$m['path']), '/');
		$url = $m['url'] ?? null;
		if (!$url) {
			if (strpos($path, 'uploads/social/') === 0) {
				$url = SocialMediaStorage::urlPublica($path);
			} else {
				$url = WhatsappMediaStorage::urlPublica($path);
			}
		}
		return [
			'tipo' => (string)($m['tipo'] ?? 'document'),
			'path' => $path,
			'nome' => (string)($m['nome'] ?? basename($path)),
			'mime' => $m['mime'] ?? null,
			'url'  => $url,
			'origem' => $m['origem'] ?? (strpos($path, 'uploads/social/') === 0 ? 'biblioteca' : 'upload'),
		];
	}

	private static function metaCron(): array {
		$ultima = CampanhaWorkerRun::ultima();
		$tokenOk = defined('SYSTEM_TOKEN') && SYSTEM_TOKEN !== '';
		$base = rtrim((string)URL, '/').'/cron/campanhas?token=';
		$tokenHint = $tokenOk ? '***' : 'SEU_SYSTEM_TOKEN';
		return [
			'tabela_ok' => CampanhaWorkerRun::tabelaExiste(),
			'token_configurado' => $tokenOk,
			'ultima' => $ultima,
			'url_cron' => $base.$tokenHint.'&limite=1',
			'hint' => !$tokenOk
				? 'Defina SYSTEM_TOKEN no .env e configure o cron no cPanel (a cada 1 min).'
				: (empty($ultima)
					? 'Com o painel fechado, campanhas dependem do cron no cPanel. Configure a URL abaixo.'
					: 'Cron registrado. Campanhas seguem mesmo com o painel fechado.'),
		];
	}

	private static function bibliotecaListar(array $postVars): string {
		$idAdmin = TenantHelper::getIdAdmin();
		$tipo = trim((string)($postVars['tipo'] ?? 'image'));
		$tipo = ($tipo === 'image' || $tipo === 'video') ? $tipo : 'image';
		$formato = trim((string)($postVars['formato'] ?? ''));
		$formato = in_array($formato, ['feed', 'story'], true) ? $formato : null;
		return json_encode(SocialBibliotecaService::listar($idAdmin, $tipo, $formato, 120), JSON_UNESCAPED_UNICODE);
	}

	/** @return array{tipo:string,path:string,nome:string,mime:?string,url:string,origem:string}|null */
	private static function resolverMidiaBiblioteca(int $idAdmin, string $pathRel): ?array {
		$pathRel = ltrim(str_replace(['..', '\\'], ['', '/'], trim($pathRel)), '/');
		if ($pathRel === '' || strpos($pathRel, 'uploads/social/') !== 0) {
			return null;
		}
		$prefix = 'uploads/social/'.(int)$idAdmin.'/';
		if (strpos($pathRel, $prefix) !== 0 && !SocialBiblioteca::pathNaBiblioteca($idAdmin, $pathRel)) {
			return null;
		}
		$abs = SocialMediaStorage::caminhoAbsoluto($pathRel);
		if (!is_file($abs)) {
			return null;
		}
		$bib = null;
		if (SocialBiblioteca::tabelaExiste()) {
			$rows = SocialBiblioteca::listByAdmin($idAdmin, null, null, 500);
			foreach ($rows as $b) {
				if ((string)$b->path_local === $pathRel) {
					$bib = $b;
					break;
				}
			}
		}
		$finfo = new \finfo(FILEINFO_MIME_TYPE);
		$mime = (string)($finfo->file($abs) ?: ($bib->mime ?? 'image/jpeg'));
		$tipo = strpos($mime, 'image/') === 0 ? 'image' : 'document';
		$nome = $bib && $bib->titulo ? (string)$bib->titulo : basename($pathRel);
		return [
			'tipo' => $tipo,
			'path' => $pathRel,
			'nome' => $nome,
			'mime' => $mime ?: null,
			'url'  => SocialMediaStorage::urlPublica($pathRel),
			'origem' => 'biblioteca',
		];
	}
}
