<?php

namespace App\Controller\Admin;

use App\Utils\View;
use App\Http\Response;
use App\Session\User\Login as SessionUser;
use App\Common\Helpers\TenantHelper;
use App\Common\Helpers\ChamadoHelper;
use App\Common\Helpers\ChamadoAnexoHelper;
use App\Common\Helpers\DateTimeHelper;
use App\Model\Entity\Chamado;
use App\Model\Entity\ChamadoMensagem;
use App\Model\Entity\User;

class Suporte extends Page {

	public static function index($request) {
		if (!Chamado::tabelaExiste() || !ChamadoMensagem::tabelaExiste()) {
			$content = View::render('admin/modules/suporte/sql', []);
			return parent::getPanel('Suporte', $content, 'Suporte', $request);
		}

		$content = View::render('admin/modules/suporte/index', [
			'categorias_json' => json_encode(ChamadoHelper::categoriasLista(), JSON_UNESCAPED_UNICODE),
			'status_json'     => json_encode(ChamadoHelper::statusLista(), JSON_UNESCAPED_UNICODE),
		]);
		return parent::getPanel('Suporte', $content, 'Suporte', $request);
	}

	public static function getInfo($request) {
		header('Content-Type: application/json; charset=utf-8');

		if (!Chamado::tabelaExiste() || !ChamadoMensagem::tabelaExiste()) {
			return json_encode([
				'success' => false,
				'sql_ok'  => false,
				'message' => 'Módulo de suporte ainda não está liberado. Fale com o suporte.',
			], JSON_UNESCAPED_UNICODE);
		}

		$post = $request->getPostVars();
		$acao = (string)($post['acao'] ?? '');

		switch ($acao) {
			case 'listar':
				return self::listar($post);
			case 'abrir':
				return self::abrir($post);
			case 'detalhe':
				return self::detalhe($post);
			case 'responder':
				return self::responder($post);
			default:
				return json_encode(['success' => false, 'message' => 'Ação inválida.'], JSON_UNESCAPED_UNICODE);
		}
	}

	public static function downloadAnexo($request, $idMensagem) {
		$idAdmin = TenantHelper::getIdAdmin();
		$idMensagem = (int)$idMensagem;
		$msg = ChamadoMensagem::getById($idMensagem);
		if (!$msg || empty($msg->anexo_path)) {
			return new Response(404, 'Anexo não encontrado.');
		}
		$chamado = Chamado::getByIdAdmin((int)$msg->chamado_id, $idAdmin);
		if (!$chamado) {
			return new Response(403, 'Acesso negado.');
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
		$idAdmin = TenantHelper::getIdAdmin();
		$status = trim((string)($post['status'] ?? ''));
		$busca = trim((string)($post['busca'] ?? ''));

		$where = 'id_admin = '.(int)$idAdmin;
		if ($status !== '' && ChamadoHelper::statusValido($status)) {
			$where .= " AND status = '".addslashes($status)."'";
		}
		if ($busca !== '') {
			$like = addslashes($busca);
			$where .= " AND (numero LIKE '%{$like}%' OR assunto LIKE '%{$like}%')";
		}

		$itens = [];
		$st = Chamado::get($where, 'updated_at DESC, id DESC', '100');
		while ($ob = $st->fetchObject(Chamado::class)) {
			$itens[] = self::resumoChamado($ob);
		}

		return json_encode([
			'success' => true,
			'sql_ok'  => true,
			'itens'   => $itens,
		], JSON_UNESCAPED_UNICODE);
	}

	private static function abrir(array $post): string {
		$idAdmin = TenantHelper::getIdAdmin();
		$user = SessionUser::getUserLogedData();
		$usuarioId = (int)($user['usuario']['id'] ?? 0);
		if ($usuarioId <= 0 || $idAdmin <= 0) {
			return json_encode(['success' => false, 'message' => 'Sessão inválida.'], JSON_UNESCAPED_UNICODE);
		}

		$categoria = trim((string)($post['categoria'] ?? ''));
		$assunto = trim((string)($post['assunto'] ?? ''));
		$mensagem = trim((string)($post['mensagem'] ?? ''));

		if (!ChamadoHelper::categoriaValida($categoria)) {
			return json_encode(['success' => false, 'message' => 'Selecione uma categoria.'], JSON_UNESCAPED_UNICODE);
		}
		if ($assunto === '' || mb_strlen($assunto) > 160) {
			return json_encode(['success' => false, 'message' => 'Informe um assunto (até 160 caracteres).'], JSON_UNESCAPED_UNICODE);
		}
		if ($mensagem === '') {
			return json_encode(['success' => false, 'message' => 'Escreva a mensagem do chamado.'], JSON_UNESCAPED_UNICODE);
		}

		$anexoPath = null;
		$anexoNome = null;
		if (!empty($_FILES['anexo']) && is_array($_FILES['anexo']) && ($_FILES['anexo']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
			$saved = ChamadoAnexoHelper::salvarUpload($idAdmin, $_FILES['anexo']);
			if ($saved === null) {
				return json_encode([
					'success' => false,
					'message' => 'Anexo inválido. Envie uma imagem (JPG, PNG, WebP ou GIF) de até 5 MB.',
				], JSON_UNESCAPED_UNICODE);
			}
			$anexoPath = $saved['relative'];
			$anexoNome = $saved['nome'];
		}

		$chamado = new Chamado();
		$chamado->id_admin = $idAdmin;
		$chamado->usuario_id = $usuarioId;
		$chamado->categoria = $categoria;
		$chamado->assunto = $assunto;
		$chamado->status = 'aberto';
		if (!$chamado->cadastrar()) {
			return json_encode(['success' => false, 'message' => 'Não foi possível abrir o chamado.'], JSON_UNESCAPED_UNICODE);
		}

		$msg = new ChamadoMensagem();
		$msg->chamado_id = (int)$chamado->id;
		$msg->autor_tipo = 'escola';
		$msg->autor_id = $usuarioId;
		$msg->mensagem = $mensagem;
		$msg->anexo_path = $anexoPath;
		$msg->anexo_nome = $anexoNome;
		if (!$msg->cadastrar()) {
			return json_encode(['success' => false, 'message' => 'Chamado criado, mas a mensagem falhou. Tente responder de novo.'], JSON_UNESCAPED_UNICODE);
		}

		return json_encode([
			'success' => true,
			'message' => 'Chamado aberto: '.$chamado->numero,
			'id'      => (int)$chamado->id,
			'numero'  => $chamado->numero,
		], JSON_UNESCAPED_UNICODE);
	}

	private static function detalhe(array $post): string {
		$idAdmin = TenantHelper::getIdAdmin();
		$id = (int)($post['id'] ?? 0);
		$chamado = Chamado::getByIdAdmin($id, $idAdmin);
		if (!$chamado) {
			return json_encode(['success' => false, 'message' => 'Chamado não encontrado.'], JSON_UNESCAPED_UNICODE);
		}
		return json_encode([
			'success' => true,
			'chamado' => self::detalhePayload($chamado),
		], JSON_UNESCAPED_UNICODE);
	}

	private static function responder(array $post): string {
		$idAdmin = TenantHelper::getIdAdmin();
		$user = SessionUser::getUserLogedData();
		$usuarioId = (int)($user['usuario']['id'] ?? 0);
		$id = (int)($post['id'] ?? 0);
		$mensagem = trim((string)($post['mensagem'] ?? ''));

		$chamado = Chamado::getByIdAdmin($id, $idAdmin);
		if (!$chamado) {
			return json_encode(['success' => false, 'message' => 'Chamado não encontrado.'], JSON_UNESCAPED_UNICODE);
		}
		if (!ChamadoHelper::escolaPodeResponder((string)$chamado->status)) {
			return json_encode(['success' => false, 'message' => 'Este chamado já foi finalizado e não recebe mais mensagens.'], JSON_UNESCAPED_UNICODE);
		}
		if ($mensagem === '') {
			return json_encode(['success' => false, 'message' => 'Escreva uma mensagem.'], JSON_UNESCAPED_UNICODE);
		}

		$anexoPath = null;
		$anexoNome = null;
		if (!empty($_FILES['anexo']) && is_array($_FILES['anexo']) && ($_FILES['anexo']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
			$saved = ChamadoAnexoHelper::salvarUpload($idAdmin, $_FILES['anexo']);
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
		$msg->autor_tipo = 'escola';
		$msg->autor_id = $usuarioId;
		$msg->mensagem = $mensagem;
		$msg->anexo_path = $anexoPath;
		$msg->anexo_nome = $anexoNome;
		if (!$msg->cadastrar()) {
			return json_encode(['success' => false, 'message' => 'Falha ao enviar a mensagem.'], JSON_UNESCAPED_UNICODE);
		}

		$chamado->atualizarStatus('em_andamento');

		return json_encode([
			'success' => true,
			'message' => 'Mensagem enviada.',
			'chamado' => self::detalhePayload(Chamado::getByIdAdmin($id, $idAdmin)),
		], JSON_UNESCAPED_UNICODE);
	}

	private static function resumoChamado(Chamado $ob): array {
		return [
			'id'              => (int)$ob->id,
			'numero'          => (string)$ob->numero,
			'categoria'       => (string)$ob->categoria,
			'categoria_label' => ChamadoHelper::labelCategoria((string)$ob->categoria),
			'assunto'         => (string)$ob->assunto,
			'status'          => (string)$ob->status,
			'status_label'    => ChamadoHelper::labelStatus((string)$ob->status),
			'created_at'      => self::dataHoraBr($ob->created_at),
			'updated_at'      => self::dataHoraBr($ob->updated_at),
		];
	}

	private static function detalhePayload(?Chamado $chamado): array {
		if (!$chamado) {
			return [];
		}
		$mensagens = [];
		foreach (ChamadoMensagem::listarPorChamado((int)$chamado->id) as $m) {
			$autorNome = 'Usuário';
			if ($m->autor_tipo === 'master') {
				$autorNome = 'Suporte CTI';
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
					? (URL.'/painel/suporte/anexo/'.(int)$m->id)
					: null,
				'created_at' => self::dataHoraBr($m->created_at),
			];
		}

		$payload = self::resumoChamado($chamado);
		$payload['pode_responder'] = ChamadoHelper::escolaPodeResponder((string)$chamado->status);
		$payload['mensagens'] = $mensagens;
		return $payload;
	}

	private static function dataHoraBr($dataHora): string {
		if ($dataHora === null || $dataHora === '') {
			return '';
		}
		return DateTimeHelper::databr($dataHora).' '.DateTimeHelper::extrairHorario($dataHora);
	}
}
