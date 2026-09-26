<?php

namespace App\Controller\Master;

use App\Utils\View;
use App\Http\Response;
use App\Session\User\Login as SessionUser;
use App\Common\Helpers\ChamadoHelper;
use App\Common\Helpers\ChamadoAnexoHelper;
use App\Common\Helpers\DateTimeHelper;
use App\Model\Entity\Chamado;
use App\Model\Entity\ChamadoMensagem;
use App\Model\Entity\ClientesAssinantes;
use App\Model\Entity\User;

class Chamados extends Page {

	public static function index($request) {
		if (!Chamado::tabelaExiste() || !ChamadoMensagem::tabelaExiste()) {
			$content = View::render('master/modules/chamados/sql', []);
			return parent::getPanel('Chamados — Master', $content, 'chamados');
		}

		$escolas = [];
		$results = ClientesAssinantes::getEscolas(null, 'nome ASC');
		while ($e = $results->fetchObject(ClientesAssinantes::class)) {
			$escolas[] = [
				'id'   => (int)$e->id,
				'nome' => (string)$e->nome,
			];
		}

		$content = View::render('master/modules/chamados/index', [
			'escolas_json'    => json_encode($escolas, JSON_UNESCAPED_UNICODE),
			'categorias_json' => json_encode(ChamadoHelper::categoriasLista(), JSON_UNESCAPED_UNICODE),
			'status_json'     => json_encode(ChamadoHelper::statusLista(), JSON_UNESCAPED_UNICODE),
			'abertos'         => (string)Chamado::contarAbertos(),
		]);
		return parent::getPanel('Chamados — Master', $content, 'chamados');
	}

	public static function getInfo($request) {
		header('Content-Type: application/json; charset=utf-8');

		if (!Chamado::tabelaExiste() || !ChamadoMensagem::tabelaExiste()) {
			return json_encode([
				'success' => false,
				'sql_ok'  => false,
				'message' => 'Execute database/xd360/15_chamados_suporte.sql no phpMyAdmin.',
			], JSON_UNESCAPED_UNICODE);
		}

		$post = $request->getPostVars();
		$acao = (string)($post['acao'] ?? '');

		switch ($acao) {
			case 'listar':
				return self::listar($post);
			case 'detalhe':
				return self::detalhe($post);
			case 'responder':
				return self::responder($post);
			case 'status':
				return self::alterarStatus($post);
			default:
				return json_encode(['success' => false, 'message' => 'Ação inválida.'], JSON_UNESCAPED_UNICODE);
		}
	}

	public static function downloadAnexo($request, $idMensagem) {
		$idMensagem = (int)$idMensagem;
		$msg = ChamadoMensagem::getById($idMensagem);
		if (!$msg || empty($msg->anexo_path)) {
			return new Response(404, 'Anexo não encontrado.');
		}
		$abs = ChamadoAnexoHelper::caminhoAbsoluto((string)$msg->anexo_path);
		if ($abs === null) {
			return new Response(404, 'Arquivo não encontrado.');
		}
		$bin = file_get_contents($abs);
		if ($bin === false) {
			return new Response(500, 'Falha ao ler anexo.');
		}
		$mime = ChamadoAnexoHelper::mimePorArquivo($abs);
		$nome = basename((string)($msg->anexo_nome ?: $msg->anexo_path));
		$resp = new Response(200, $bin, $mime);
		$resp->addHeader('Content-Disposition', 'inline; filename="'.str_replace('"', '', $nome).'"');
		$resp->addHeader('Content-Length', (string)strlen($bin));
		$resp->addHeader('Cache-Control', 'private, max-age=3600');
		return $resp;
	}

	private static function listar(array $post): string {
		$status = trim((string)($post['status'] ?? ''));
		$categoria = trim((string)($post['categoria'] ?? ''));
		$idAdmin = (int)($post['id_admin'] ?? 0);
		$busca = trim((string)($post['busca'] ?? ''));

		$whereParts = ['1=1'];
		if ($status !== '' && ChamadoHelper::statusValido($status)) {
			$whereParts[] = "status = '".addslashes($status)."'";
		}
		if ($categoria !== '' && ChamadoHelper::categoriaValida($categoria)) {
			$whereParts[] = "categoria = '".addslashes($categoria)."'";
		}
		if ($idAdmin > 0) {
			$whereParts[] = 'id_admin = '.$idAdmin;
		}
		if ($busca !== '') {
			$like = addslashes($busca);
			$whereParts[] = "(numero LIKE '%{$like}%' OR assunto LIKE '%{$like}%')";
		}

		$nomesEscola = [];
		$er = ClientesAssinantes::getEscolas(null, null, null, 'id, nome');
		while ($e = $er->fetch(\PDO::FETCH_ASSOC)) {
			$nomesEscola[(int)$e['id']] = (string)$e['nome'];
		}

		$itens = [];
		$st = Chamado::get(implode(' AND ', $whereParts), 'updated_at DESC, id DESC', '200');
		while ($ob = $st->fetchObject(Chamado::class)) {
			$itens[] = [
				'id'              => (int)$ob->id,
				'numero'          => (string)$ob->numero,
				'id_admin'        => (int)$ob->id_admin,
				'escola_nome'     => $nomesEscola[(int)$ob->id_admin] ?? ('Escola #'.$ob->id_admin),
				'categoria'       => (string)$ob->categoria,
				'categoria_label' => ChamadoHelper::labelCategoria((string)$ob->categoria),
				'assunto'         => (string)$ob->assunto,
				'status'          => (string)$ob->status,
				'status_label'    => ChamadoHelper::labelStatus((string)$ob->status),
				'created_at'      => self::dataHoraBr($ob->created_at),
				'updated_at'      => self::dataHoraBr($ob->updated_at),
			];
		}

		return json_encode([
			'success' => true,
			'itens'   => $itens,
			'abertos' => Chamado::contarAbertos(),
		], JSON_UNESCAPED_UNICODE);
	}

	private static function detalhe(array $post): string {
		$id = (int)($post['id'] ?? 0);
		$chamado = Chamado::getById($id);
		if (!$chamado) {
			return json_encode(['success' => false, 'message' => 'Chamado não encontrado.'], JSON_UNESCAPED_UNICODE);
		}
		return json_encode([
			'success' => true,
			'chamado' => self::detalhePayload($chamado),
		], JSON_UNESCAPED_UNICODE);
	}

	private static function responder(array $post): string {
		$user = SessionUser::getUserLogedData();
		$usuarioId = (int)($user['usuario']['id'] ?? 0);
		$id = (int)($post['id'] ?? 0);
		$mensagem = trim((string)($post['mensagem'] ?? ''));

		$chamado = Chamado::getById($id);
		if (!$chamado) {
			return json_encode(['success' => false, 'message' => 'Chamado não encontrado.'], JSON_UNESCAPED_UNICODE);
		}
		if (!ChamadoHelper::masterPodeResponder((string)$chamado->status)) {
			return json_encode(['success' => false, 'message' => 'Este chamado já foi finalizado. Altere o status para responder de novo.'], JSON_UNESCAPED_UNICODE);
		}
		if ($mensagem === '') {
			return json_encode(['success' => false, 'message' => 'Escreva uma mensagem.'], JSON_UNESCAPED_UNICODE);
		}

		$anexoPath = null;
		$anexoNome = null;
		if (!empty($_FILES['anexo']) && is_array($_FILES['anexo']) && ($_FILES['anexo']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
			$saved = ChamadoAnexoHelper::salvarUpload((int)$chamado->id_admin, $_FILES['anexo']);
			if ($saved === null) {
				return json_encode([
					'success' => false,
					'message' => 'Anexo inválido. Envie uma imagem (JPG, PNG, WebP ou GIF) de até 5 MB.',
				], JSON_UNESCAPED_UNICODE);
			}
			$anexoPath = $saved['relative'];
			$anexoNome = $saved['nome'];
		}

		$msg = new ChamadoMensagem();
		$msg->chamado_id = (int)$chamado->id;
		$msg->autor_tipo = 'master';
		$msg->autor_id = $usuarioId;
		$msg->mensagem = $mensagem;
		$msg->anexo_path = $anexoPath;
		$msg->anexo_nome = $anexoNome;
		if (!$msg->cadastrar()) {
			return json_encode(['success' => false, 'message' => 'Falha ao enviar a mensagem.'], JSON_UNESCAPED_UNICODE);
		}

		if (!in_array((string)$chamado->status, ['resolvido', 'fechado'], true)) {
			$chamado->atualizarStatus('aguardando_escola');
		} else {
			$chamado->tocarUpdatedAt();
		}

		return json_encode([
			'success' => true,
			'message' => 'Resposta enviada.',
			'chamado' => self::detalhePayload(Chamado::getById($id)),
		], JSON_UNESCAPED_UNICODE);
	}

	private static function alterarStatus(array $post): string {
		$id = (int)($post['id'] ?? 0);
		$status = trim((string)($post['status'] ?? ''));
		if (!ChamadoHelper::statusValido($status)) {
			return json_encode(['success' => false, 'message' => 'Status inválido.'], JSON_UNESCAPED_UNICODE);
		}
		$chamado = Chamado::getById($id);
		if (!$chamado) {
			return json_encode(['success' => false, 'message' => 'Chamado não encontrado.'], JSON_UNESCAPED_UNICODE);
		}
		$chamado->atualizarStatus($status);
		return json_encode([
			'success' => true,
			'message' => 'Status atualizado.',
			'chamado' => self::detalhePayload(Chamado::getById($id)),
			'abertos' => Chamado::contarAbertos(),
		], JSON_UNESCAPED_UNICODE);
	}

	private static function detalhePayload(?Chamado $chamado): array {
		if (!$chamado) {
			return [];
		}
		$escola = ClientesAssinantes::getEscolaById((int)$chamado->id_admin);
		$escolaNome = $escola ? (string)$escola->nome : ('Escola #'.$chamado->id_admin);
		$abriu = User::getUserById((int)$chamado->usuario_id);
		$abriuNome = $abriu && !empty($abriu->nome) ? (string)$abriu->nome : 'Usuário';

		$mensagens = [];
		foreach (ChamadoMensagem::listarPorChamado((int)$chamado->id) as $m) {
			$autorNome = 'Usuário';
			if ($m->autor_tipo === 'master') {
				$autorNome = 'Suporte CTI';
				$u = User::getUserById((int)$m->autor_id);
				if ($u && !empty($u->nome)) {
					$autorNome = (string)$u->nome.' (suporte)';
				}
			} else {
				$u = User::getUserById((int)$m->autor_id);
				if ($u && !empty($u->nome)) {
					$autorNome = (string)$u->nome;
				}
			}
			$mensagens[] = [
				'id'         => (int)$m->id,
				'autor_tipo' => (string)$m->autor_tipo,
				'autor_nome' => $autorNome,
				'mensagem'   => (string)$m->mensagem,
				'anexo'      => !empty($m->anexo_path),
				'anexo_nome' => (string)($m->anexo_nome ?? ''),
				'anexo_url'  => !empty($m->anexo_path)
					? (URL.'/master/chamados/anexo/'.(int)$m->id)
					: null,
				'created_at' => self::dataHoraBr($m->created_at),
			];
		}

		return [
			'id'              => (int)$chamado->id,
			'numero'          => (string)$chamado->numero,
			'id_admin'        => (int)$chamado->id_admin,
			'escola_nome'     => $escolaNome,
			'aberto_por'      => $abriuNome,
			'categoria'       => (string)$chamado->categoria,
			'categoria_label' => ChamadoHelper::labelCategoria((string)$chamado->categoria),
			'assunto'         => (string)$chamado->assunto,
			'status'          => (string)$chamado->status,
			'status_label'    => ChamadoHelper::labelStatus((string)$chamado->status),
			'created_at'      => self::dataHoraBr($chamado->created_at),
			'updated_at'      => self::dataHoraBr($chamado->updated_at),
			'pode_responder'  => ChamadoHelper::masterPodeResponder((string)$chamado->status),
			'mensagens'       => $mensagens,
		];
	}

	private static function dataHoraBr($dataHora): string {
		if ($dataHora === null || $dataHora === '') {
			return '';
		}
		return DateTimeHelper::databr($dataHora).' '.DateTimeHelper::extrairHorario($dataHora);
	}
}
