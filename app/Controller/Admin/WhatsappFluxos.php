<?php

namespace App\Controller\Admin;

use App\Utils\View;
use App\Common\Helpers\TenantHelper;
use App\Common\Communication\WhatsappMediaStorage;
use App\Common\Communication\WhatsappFlowRunner;
use App\Common\Communication\WhatsappFlowTemplates;
use App\Model\Entity\WhatsappFluxo;
use App\Model\Entity\WhatsappSetor;

class WhatsappFluxos extends Page {

	private static function json(array $data): string {
		return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
	}

	public static function index($request) {
		if (!WhatsappFluxo::tabelaExiste()) {
			$content = View::render('admin/modules/whatsapp/fluxos-sql', []);
			return parent::getPanel('WhatsApp', $content, 'whatsapp', $request);
		}
		$setores = [];
		if (WhatsappSetor::tabelaExiste()) {
			WhatsappSetor::garantirPadroes(TenantHelper::getIdAdmin());
			foreach (WhatsappSetor::listarAtivos(TenantHelper::getIdAdmin()) as $s) {
				$setores[] = [
					'id'   => (int)$s['id'],
					'nome' => (string)$s['nome'],
				];
			}
		}
		$content = View::render('admin/modules/whatsapp/fluxos', [
			'setores_json'   => json_encode($setores, JSON_UNESCAPED_UNICODE),
			'templates_json' => json_encode(WhatsappFlowTemplates::todos(), JSON_UNESCAPED_UNICODE),
		]);
		return parent::getPanel('WhatsApp', $content, 'whatsapp', $request);
	}

	public static function getInfo($request) {
		header('Content-Type: application/json; charset=utf-8');
		if (!WhatsappFluxo::tabelaExiste()) {
			return self::json([
				'success' => false,
				'sql_ok'  => false,
				'message' => 'Módulo de fluxos ainda não está liberado. Fale com o suporte.',
			]);
		}

		$post = $request->getPostVars();
		$acao = (string)($post['acao'] ?? '');

		switch ($acao) {
			case 'listar':
				return self::listar($post);
			case 'salvar':
				return self::salvar($post);
			case 'excluir':
				return self::excluir($post);
			case 'toggle':
				return self::toggle($post);
			case 'upload_midia':
				return self::uploadMidia();
			case 'templates':
				return self::json(['success' => true, 'itens' => WhatsappFlowTemplates::todos()]);
			case 'aplicar_template':
				return self::aplicarTemplate($post);
			case 'simular':
				return self::simular($post);
			case 'processar_timeouts':
				$r = WhatsappFlowRunner::processarTimeouts(TenantHelper::getIdAdmin());
				return self::json([
					'success' => true,
					'message' => 'Timeouts: '.$r['ok'].' processado(s), '.$r['erro'].' erro(s).',
					'resultado' => $r,
				]);
			default:
				return self::json(['success' => false, 'message' => 'Ação inválida.']);
		}
	}

	private static function listar(array $post = []): string {
		$idAdmin = TenantHelper::getIdAdmin();
		$page = max(1, (int)($post['page'] ?? 1));
		$limit = 20;
		$todos = WhatsappFluxo::listar($idAdmin);
		$total = count($todos);
		$pages = max(1, (int)ceil($total / $limit));
		if ($page > $pages) {
			$page = $pages;
		}
		$slice = array_slice($todos, ($page - 1) * $limit, $limit);
		$itens = [];
		foreach ($slice as $f) {
			$def = $f->definicaoArray();
			$itens[] = [
				'id'         => (int)$f->id,
				'nome'       => (string)$f->nome,
				'ativo'      => (int)$f->ativo,
				'prioridade' => (int)$f->prioridade,
				'trigger'    => $def['trigger'] ?? [],
				'settings'   => $def['settings'] ?? ['timeout_horas' => 24, 'timeout_acao' => 'humano'],
				'start'      => (string)($def['start'] ?? ''),
				'nodes'      => $def['nodes'] ?? new \stdClass(),
				'updated_at' => (string)$f->updated_at,
			];
		}
		return self::json([
			'success' => true,
			'itens' => $itens,
			'pagination' => [
				'page' => $page,
				'pages' => $pages,
				'total' => $total,
				'limit' => $limit,
			],
		]);
	}

	private static function aplicarTemplate(array $post): string {
		$idTpl = trim((string)($post['template_id'] ?? ''));
		$tpl = WhatsappFlowTemplates::getById($idTpl);
		if (!$tpl) {
			return self::json(['success' => false, 'message' => 'Template não encontrado.']);
		}
		$idAdmin = TenantHelper::getIdAdmin();
		$fluxo = new WhatsappFluxo();
		$fluxo->id_admin = $idAdmin;
		$fluxo->nome = $tpl['nome'];
		$fluxo->prioridade = 50;
		$fluxo->ativo = 0;
		$fluxo->definicao = $tpl['definicao'];
		if (!$fluxo->salvar()) {
			return self::json(['success' => false, 'message' => 'Falha ao criar a partir do template.']);
		}
		return self::json([
			'success' => true,
			'message' => 'Template aplicado (inativo). Edite e ative quando estiver pronto.',
			'id' => (int)$fluxo->id,
		]);
	}

	private static function simular(array $post): string {
		$idAdmin = TenantHelper::getIdAdmin();
		$definicaoRaw = $post['definicao'] ?? '';
		$msgsRaw = $post['mensagens'] ?? '[]';
		if (is_string($definicaoRaw)) {
			$def = json_decode($definicaoRaw, true);
		} else {
			$def = is_array($definicaoRaw) ? $definicaoRaw : null;
		}
		if (is_string($msgsRaw)) {
			$msgs = json_decode($msgsRaw, true);
		} else {
			$msgs = is_array($msgsRaw) ? $msgsRaw : [];
		}
		if (!is_array($def)) {
			return self::json(['success' => false, 'message' => 'Definição inválida para simular.']);
		}
		if (!is_array($msgs)) {
			$msgs = [];
		}
		$err = self::validarDefinicao($def);
		if ($err !== null) {
			return self::json(['success' => false, 'message' => $err]);
		}
		$res = WhatsappFlowRunner::simular($idAdmin, $def, $msgs);
		return self::json($res);
	}

	private static function salvar(array $post): string {
		$idAdmin = TenantHelper::getIdAdmin();
		$id = (int)($post['id'] ?? 0);
		$nome = trim((string)($post['nome'] ?? ''));
		$prioridade = (int)($post['prioridade'] ?? 100);
		$ativo = !empty($post['ativo']) ? 1 : 0;
		$definicaoRaw = $post['definicao'] ?? '';

		if ($nome === '') {
			return self::json(['success' => false, 'message' => 'Informe o nome do fluxo.']);
		}

		if (is_string($definicaoRaw)) {
			$def = json_decode($definicaoRaw, true);
		} elseif (is_array($definicaoRaw)) {
			$def = $definicaoRaw;
		} else {
			$def = null;
		}
		if (!is_array($def) || empty($def['nodes']) || empty($def['start'])) {
			return self::json(['success' => false, 'message' => 'Definição do fluxo inválida (precisa de passos e início).']);
		}

		$err = self::validarDefinicao($def);
		if ($err !== null) {
			return self::json(['success' => false, 'message' => $err]);
		}

		$st = is_array($def['settings'] ?? null) ? $def['settings'] : [];
		$horas = (int)($st['timeout_horas'] ?? 24);
		if ($horas < 0) {
			$horas = 0;
		}
		if ($horas > 168) {
			$horas = 168;
		}
		$acaoTo = (string)($st['timeout_acao'] ?? 'humano');
		if (!in_array($acaoTo, ['humano', 'encerrar'], true)) {
			$acaoTo = 'humano';
		}
		$def['settings'] = [
			'timeout_horas' => $horas,
			'timeout_acao'  => $acaoTo,
		];

		if ($id > 0) {
			$fluxo = WhatsappFluxo::getByIdAdmin($id, $idAdmin);
			if (!$fluxo) {
				return self::json(['success' => false, 'message' => 'Fluxo não encontrado.']);
			}
		} else {
			$fluxo = new WhatsappFluxo();
			$fluxo->id_admin = $idAdmin;
		}

		$fluxo->nome = $nome;
		$fluxo->prioridade = $prioridade;
		$fluxo->ativo = $ativo;
		$fluxo->definicao = $def;
		if (!$fluxo->salvar()) {
			return self::json(['success' => false, 'message' => 'Falha ao salvar.']);
		}

		return self::json(['success' => true, 'message' => 'Fluxo salvo.', 'id' => (int)$fluxo->id]);
	}

	private static function validarDefinicao(array $def): ?string {
		$tr = $def['trigger'] ?? [];
		$tipo = (string)($tr['tipo'] ?? 'keyword');
		if (!in_array($tipo, ['keyword', 'primeira_msg', 'saudacao'], true)) {
			return 'Tipo de gatilho inválido.';
		}
		if ($tipo === 'keyword' && trim((string)($tr['palavra'] ?? '')) === '') {
			return 'Informe a palavra-chave do gatilho.';
		}
		$nodes = $def['nodes'];
		if (!is_array($nodes) || count($nodes) > 50) {
			return 'Quantidade de passos inválida (máx. 50).';
		}
		$start = (string)$def['start'];
		if (!isset($nodes[$start])) {
			return 'O passo inicial não existe.';
		}
		$tiposOk = [
			'send_text', 'send_media', 'ask_text', 'ask_options', 'condition', 'delay',
			'criar_lead', 'set_var', 'goto_setor', 'goto_humano', 'end',
		];
		foreach ($nodes as $id => $n) {
			if (!is_array($n)) {
				return 'Passo inválido: '.$id;
			}
			$t = (string)($n['type'] ?? '');
			if (!in_array($t, $tiposOk, true)) {
				return 'Tipo de passo inválido: '.$t;
			}
		}
		return null;
	}

	private static function excluir(array $post): string {
		$idAdmin = TenantHelper::getIdAdmin();
		$id = (int)($post['id'] ?? 0);
		$fluxo = WhatsappFluxo::getByIdAdmin($id, $idAdmin);
		if (!$fluxo) {
			return self::json(['success' => false, 'message' => 'Fluxo não encontrado.']);
		}
		$fluxo->excluir();
		return self::json(['success' => true, 'message' => 'Fluxo excluído.']);
	}

	private static function toggle(array $post): string {
		$idAdmin = TenantHelper::getIdAdmin();
		$id = (int)($post['id'] ?? 0);
		$fluxo = WhatsappFluxo::getByIdAdmin($id, $idAdmin);
		if (!$fluxo) {
			return self::json(['success' => false, 'message' => 'Fluxo não encontrado.']);
		}
		$fluxo->ativo = empty($fluxo->ativo) ? 1 : 0;
		$fluxo->definicao = $fluxo->definicaoArray();
		$fluxo->salvar();
		return self::json(['success' => true, 'ativo' => (int)$fluxo->ativo]);
	}

	private static function uploadMidia(): string {
		$idAdmin = TenantHelper::getIdAdmin();
		if (empty($_FILES['arquivo']) || !is_array($_FILES['arquivo'])) {
			return self::json(['success' => false, 'message' => 'Envie um arquivo.']);
		}
		$saved = WhatsappMediaStorage::salvarUpload($idAdmin, $_FILES['arquivo']);
		if ($saved === null) {
			return self::json(['success' => false, 'message' => 'Falha no upload (tipo/tamanho inválido).']);
		}
		$mime = (string)($saved['mimetype'] ?? '');
		$tipo = 'document';
		if (strpos($mime, 'image/') === 0) {
			$tipo = 'image';
		} elseif (strpos($mime, 'audio/') === 0) {
			$tipo = 'audio';
		}
		return self::json([
			'success'  => true,
			'path'     => $saved['relative'],
			'url'      => $saved['url'],
			'tipo'     => $tipo,
			'mimetype' => $mime,
			'nome'     => basename((string)($_FILES['arquivo']['name'] ?? $saved['relative'])),
		]);
	}
}
