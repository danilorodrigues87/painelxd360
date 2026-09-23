<?php

namespace App\Controller\Admin;

use App\Utils\View;
use App\Session\User\Login as SessionUser;
use App\Common\Helpers\TenantHelper;
use App\Common\Communication\WhatsappChatbotService;
use App\Common\Communication\WhatsappMediaStorage;
use App\Common\Communication\EvolutionApiService;
use App\Model\Entity\WhatsappConversa;
use App\Model\Entity\WhatsappMensagem;
use App\Model\Entity\WhatsappSetor;
use App\Model\Entity\WhatsappAtendente;
use App\Model\Entity\User;
use App\Model\Db\Database;

class WhatsappInbox extends Page {

	private static function user(): array {
		return SessionUser::getUserLogedData();
	}

	private static function idAdmin(): int {
		return (int)TenantHelper::getIdAdmin();
	}

	private static function isDiretor(): bool {
		return (self::user()['usuario']['nivel'] ?? '') === 'Diretor';
	}

	public static function index($request) {
		$content = View::render('admin/modules/whatsapp/inbox', []);
		return parent::getPanel('WhatsApp', $content, 'whatsapp', $request);
	}

	public static function getInfo($request) {
		$post = $request->getPostVars();
		$acao = $post['acao'] ?? '';

		$map = [
			'listar'           => 'listar',
			'mensagens'        => 'mensagens',
			'enviar'           => 'enviar',
			'enviar_midia'     => 'enviarMidia',
			'assumir'          => 'assumir',
			'iniciar_atendimento' => 'iniciarAtendimento',
			'transferir'       => 'transferir',
			'atendentes_setor' => 'atendentesSetor',
			'fechar'           => 'fechar',
			'fechar_todas'     => 'fecharTodas',
			'setores_listar'   => 'setoresListar',
			'setor_salvar'     => 'setorSalvar',
			'atendentes_listar'=> 'atendentesListar',
			'atendente_vincular' => 'atendenteVincular',
			'atendente_remover'=> 'atendenteRemover',
			'usuarios_lista'   => 'usuariosLista',
		];

		if (!isset($map[$acao])) {
			return self::json(['success' => false, 'message' => 'Ação inválida.']);
		}

		$method = $map[$acao];
		return self::$method($post);
	}

	private static function json(array $data): string {
		return json_encode($data, JSON_UNESCAPED_UNICODE);
	}

	private static function listar(array $post): string {
		$idAdmin = self::idAdmin();
		$user = self::user();
		$uid = (int)($user['usuario']['id'] ?? 0);
		$nivel = (string)($user['usuario']['nivel'] ?? '');
		$setores = WhatsappAtendente::setoresDoUsuario($idAdmin, $uid);
		$filtro = (string)($post['filtro'] ?? 'todas');
		$busca = trim((string)($post['busca'] ?? ''));

		$lista = WhatsappConversa::listarInbox($idAdmin, $uid, $nivel, $setores, 80, $filtro, $busca);
		$indicadores = WhatsappConversa::indicadores($idAdmin, $uid, $nivel, $setores);

		return self::json([
			'success' => true,
			'conversas' => $lista,
			'filtro' => $filtro,
			'busca' => $busca,
			'indicadores' => $indicadores,
			'meta' => [
				'is_diretor' => self::isDiretor(),
				'chatbot_ok' => WhatsappConversa::temColunasChatbot(),
				'setores_ok' => WhatsappSetor::tabelaExiste(),
				'atendentes_ok' => WhatsappAtendente::tabelaExiste(),
				'nao_lida_ok' => WhatsappConversa::temColunaNaoLida(),
			],
		]);
	}

	private static function mensagens(array $post): string {
		$idAdmin = self::idAdmin();
		$id = (int)($post['conversa_id'] ?? 0);
		$conv = WhatsappConversa::getById($id, $idAdmin);
		if (!$conv) {
			return self::json(['success' => false, 'message' => 'Conversa não encontrada.']);
		}
		if (!self::podeVer($conv)) {
			return self::json(['success' => false, 'message' => 'Sem permissão para ver esta conversa.']);
		}

		$conv->marcarLida();

		$rows = (new Database('whatsapp_mensagens'))
			->select('conversa_id = '.$id.' AND id_admin = '.$idAdmin, 'id ASC', '200')
			->fetchAll(\PDO::FETCH_ASSOC);

		foreach ($rows as &$row) {
			if (!empty($row['media_url'])) {
				$rel = (string)$row['media_url'];
				if (strpos($rel, 'http://') !== 0 && strpos($rel, 'https://') !== 0) {
					$row['media_url_full'] = WhatsappMediaStorage::urlPublica($rel);
				} else {
					$row['media_url_full'] = $rel;
				}
			}
		}
		unset($row);

		return self::json([
			'success' => true,
			'conversa' => [
				'id' => (int)$conv->id,
				'telefone' => $conv->telefone,
				'nome_contato' => $conv->nome_contato,
				'status' => $conv->status,
				'setor_id' => $conv->setor_id,
				'id_atendente' => $conv->id_atendente,
				'chatbot_estado' => $conv->chatbot_estado ?? null,
			],
			'mensagens' => $rows ?: [],
		]);
	}

	private static function enviar(array $post): string {
		$texto = trim((string)($post['texto'] ?? ''));
		if ($texto === '') {
			return self::json(['success' => false, 'message' => 'Digite uma mensagem.']);
		}

		$conv = self::obterConversaParaEnvio($post);
		if (is_string($conv)) {
			return $conv;
		}

		$ok = WhatsappChatbotService::enviarTexto($conv, $texto);
		if (!$ok) {
			return self::json(['success' => false, 'message' => 'Falha ao enviar pelo WhatsApp. Verifique a conexão.']);
		}

		return self::json(['success' => true, 'message' => 'Enviado.']);
	}

	private static function enviarMidia(array $post): string {
		$tipo = (string)($post['tipo_midia'] ?? 'image');
		if (!in_array($tipo, ['image', 'audio', 'document'], true)) {
			return self::json(['success' => false, 'message' => 'Tipo de mídia inválido.']);
		}

		$conv = self::obterConversaParaEnvio($post);
		if (is_string($conv)) {
			return $conv;
		}

		$idAdmin = self::idAdmin();
		$arquivo = null;
		$fileName = null;

		if (!empty($_FILES['arquivo']) && is_array($_FILES['arquivo'])) {
			$fileName = basename((string)($_FILES['arquivo']['name'] ?? 'documento'));
			$arquivo = WhatsappMediaStorage::salvarUpload($idAdmin, $_FILES['arquivo']);
		} elseif (!empty($post['base64'])) {
			$arquivo = WhatsappMediaStorage::salvarBase64(
				$idAdmin,
				(string)$post['base64'],
				$tipo,
				$post['mimetype'] ?? null
			);
			$fileName = trim((string)($post['file_name'] ?? '')) ?: null;
		}

		if (!$arquivo) {
			return self::json(['success' => false, 'message' => 'Não foi possível processar o arquivo (máx. 15 MB).']);
		}

		$caption = trim((string)($post['caption'] ?? ''));
		if ($tipo === 'audio') {
			$ok = WhatsappChatbotService::enviarAudio($conv, $arquivo);
		} elseif ($tipo === 'document') {
			$ok = WhatsappChatbotService::enviarDocumento(
				$conv,
				$arquivo,
				$caption !== '' ? $caption : null,
				$fileName
			);
		} else {
			$ok = WhatsappChatbotService::enviarImagem($conv, $arquivo, $caption !== '' ? $caption : null);
		}

		if (!$ok) {
			$detalhe = WhatsappChatbotService::getLastError();
			return self::json([
				'success' => false,
				'message' => $detalhe
					? ('Falha ao enviar mídia: '.$detalhe)
					: 'Falha ao enviar mídia pelo WhatsApp.',
				'debug_tipo' => $tipo,
			]);
		}

		return self::json(['success' => true, 'message' => 'Mídia enviada.']);
	}

	/** @return WhatsappConversa|string */
	private static function obterConversaParaEnvio(array $post) {
		$idAdmin = self::idAdmin();
		$user = self::user();
		$uid = (int)($user['usuario']['id'] ?? 0);
		$id = (int)($post['conversa_id'] ?? 0);

		$conv = WhatsappConversa::getById($id, $idAdmin);
		if (!$conv || !self::podeVer($conv)) {
			return self::json(['success' => false, 'message' => 'Conversa não encontrada.']);
		}

		if (!self::isDiretor() && (int)$conv->id_atendente !== $uid) {
			if (!(int)$conv->id_atendente) {
				$conv->atualizar([
					'id_atendente'   => $uid,
					'status'         => 'em_atendimento',
					'chatbot_estado' => 'humano',
					'assigned_at'    => date('Y-m-d H:i:s'),
				]);
			} else {
				return self::json(['success' => false, 'message' => 'Assuma a conversa antes de responder.']);
			}
		} else {
			$upd = ['chatbot_estado' => 'humano', 'status' => 'em_atendimento'];
			if (!(int)$conv->id_atendente) {
				$upd['id_atendente'] = $uid;
				$upd['assigned_at'] = date('Y-m-d H:i:s');
			}
			$conv->atualizar($upd);
		}

		return $conv;
	}

	private static function assumir(array $post): string {
		$idAdmin = self::idAdmin();
		$uid = (int)(self::user()['usuario']['id'] ?? 0);
		$id = (int)($post['conversa_id'] ?? 0);
		$conv = WhatsappConversa::getById($id, $idAdmin);
		if (!$conv || !self::podeVer($conv)) {
			return json_encode(['success' => false, 'message' => 'Conversa não encontrada.']);
		}

		if ((int)$conv->id_atendente && (int)$conv->id_atendente !== $uid && !self::isDiretor()) {
			return json_encode(['success' => false, 'message' => 'Conversa já está com outro atendente.']);
		}

		$conv->atualizar([
			'id_atendente'   => $uid,
			'status'         => 'em_atendimento',
			'chatbot_estado' => 'humano',
			'assigned_at'    => date('Y-m-d H:i:s'),
		]);

		return json_encode(['success' => true, 'message' => 'Conversa assumida.']);
	}

	/**
	 * Abre (ou cria) conversa a partir de aluno, responsável ou lead e assume o atendimento.
	 * Sem módulo/conexão: devolve fallback para WhatsApp Web (wa.me).
	 */
	private static function iniciarAtendimento(array $post): string {
		$telefoneRaw = (string)($post['telefone'] ?? '');
		$telefone = EvolutionApiService::normalizarTelefone($telefoneRaw);
		$nome = trim((string)($post['nome'] ?? ''));
		$fallbackWa = $telefone !== '' ? 'https://wa.me/'.$telefone : '';

		$responderWeb = function (string $message) use ($fallbackWa) {
			return self::json([
				'success'     => false,
				'usar_web'    => true,
				'fallback_wa' => $fallbackWa,
				'message'     => $message,
			]);
		};

		if ($telefone === '' || strlen($telefone) < 12) {
			return self::json(['success' => false, 'message' => 'WhatsApp inválido ou incompleto.']);
		}

		$user = self::user();
		$idAdmin = self::idAdmin();
		$acesso = $user['usuario']['acesso'] ?? [];
		if (!is_array($acesso)) {
			$acesso = [];
		}
		if (!\App\Common\Helpers\ModuleGateHelper::podeAcessar('WhatsApp', $idAdmin, $acesso)) {
			return $responderWeb('Módulo WhatsApp não liberado para esta escola.');
		}

		if (!WhatsappConversa::tabelaExiste()) {
			return $responderWeb('Inbox WhatsApp não configurado.');
		}

		$statusWa = \App\Common\Communication\WhatsappEscolaService::status($idAdmin);
		if (empty($statusWa['conectado'])) {
			return $responderWeb('WhatsApp da escola não está conectado.');
		}

		$uid = (int)($user['usuario']['id'] ?? 0);
		$conv = WhatsappConversa::findOrCreate($idAdmin, $telefone, $nome !== '' ? $nome : null);
		if (!$conv instanceof WhatsappConversa) {
			return $responderWeb('Não foi possível abrir a conversa no painel.');
		}

		$upd = [
			'status'         => 'em_atendimento',
			'chatbot_estado' => 'humano',
			'id_atendente'   => $uid,
			'assigned_at'    => date('Y-m-d H:i:s'),
		];
		if ($nome !== '') {
			$upd['nome_contato'] = $nome;
		}
		$conv->atualizar($upd);

		return self::json([
			'success'     => true,
			'message'     => 'Atendimento iniciado.',
			'conversa_id' => (int)$conv->id,
			'redirect'    => rtrim((string)URL, '/').'/painel/whatsapp?conversa='.(int)$conv->id,
		]);
	}

	private static function transferir(array $post): string {
		$idAdmin = self::idAdmin();
		$id = (int)($post['conversa_id'] ?? 0);
		$setorId = (int)($post['setor_id'] ?? 0);
		$atendenteId = (int)($post['atendente_id'] ?? 0);
		$conv = WhatsappConversa::getById($id, $idAdmin);
		if (!$conv || !self::podeVer($conv)) {
			return json_encode(['success' => false, 'message' => 'Conversa não encontrada.']);
		}

		$setor = WhatsappSetor::getById($setorId, $idAdmin);
		if (!$setor) {
			return json_encode(['success' => false, 'message' => 'Setor inválido.']);
		}

		// Com atendente: atribui direto; sem: volta para a fila do setor
		if ($atendenteId > 0) {
			if (!self::isDiretor() && !WhatsappAtendente::usuarioNoSetor($idAdmin, $atendenteId, $setorId)) {
				return json_encode(['success' => false, 'message' => 'Atendente não vinculado a este setor.']);
			}
			$conv->atualizar([
				'setor_id'       => $setorId,
				'id_atendente'   => $atendenteId,
				'status'         => 'em_atendimento',
				'chatbot_estado' => 'humano',
				'assigned_at'    => date('Y-m-d H:i:s'),
			]);
			$msg = 'Você foi transferido para *'.$setor->nome.'*. Em breve um atendente dará continuidade.';
			WhatsappChatbotService::enviarTexto($conv, $msg);
			return json_encode(['success' => true, 'message' => 'Transferido para atendente do setor '.$setor->nome.'.']);
		}

		$conv->atualizar([
			'setor_id'       => $setorId,
			'id_atendente'   => null,
			'status'         => 'aberta',
			'chatbot_estado' => 'fila',
			'assigned_at'    => null,
		]);

		$msg = $setor->mensagem_fila ?: ('Você foi transferido para *'.$setor->nome.'*. Aguarde um atendente.');
		WhatsappChatbotService::enviarTexto($conv, $msg);

		return json_encode(['success' => true, 'message' => 'Transferido para a fila de '.$setor->nome.'.']);
	}

	private static function atendentesSetor(array $post): string {
		$idAdmin = self::idAdmin();
		$setorId = (int)($post['setor_id'] ?? 0);
		if ($setorId <= 0 || !WhatsappAtendente::tabelaExiste()) {
			return self::json(['success' => true, 'atendentes' => []]);
		}
		return self::json([
			'success' => true,
			'atendentes' => WhatsappAtendente::listarPorSetor($idAdmin, $setorId),
		]);
	}

	private static function fechar(array $post): string {
		$idAdmin = self::idAdmin();
		$id = (int)($post['conversa_id'] ?? 0);
		$conv = WhatsappConversa::getById($id, $idAdmin);
		if (!$conv) {
			return self::json(['success' => false, 'message' => 'Conversa não encontrada.']);
		}
		if (!self::podeVer($conv)) {
			return self::json(['success' => false, 'message' => 'Sem permissão para encerrar esta conversa.']);
		}

		$conv->atualizar([
			'status'         => 'fechada',
			'chatbot_estado' => 'encerrado',
			'id_atendente'   => null,
			'setor_id'       => null,
			'assigned_at'    => null,
		]);

		return self::json(['success' => true, 'message' => 'Conversa encerrada. Na próxima mensagem do cliente o menu reinicia.']);
	}

	private static function fecharTodas(array $post): string {
		$user = self::user();
		$uid = (int)($user['usuario']['id'] ?? 0);
		$nivel = (string)($user['usuario']['nivel'] ?? '');
		$setores = WhatsappAtendente::setoresDoUsuario(self::idAdmin(), $uid);

		$fechadas = WhatsappConversa::fecharTodasEmAndamento(self::idAdmin(), $uid, $nivel, $setores);

		return self::json([
			'success'  => true,
			'fechadas' => $fechadas,
			'message'  => $fechadas === 0
				? 'Nenhuma conversa em andamento para encerrar.'
				: $fechadas.' conversa(s) encerrada(s). Na próxima mensagem do cliente o menu reinicia.',
		]);
	}

	private static function setoresListar(array $post): string {
		$idAdmin = self::idAdmin();
		if (!WhatsappSetor::tabelaExiste()) {
			return json_encode(['success' => false, 'message' => 'Execute o SQL de setores no phpMyAdmin.']);
		}
		WhatsappSetor::garantirPadroes($idAdmin);
		return json_encode(['success' => true, 'setores' => WhatsappSetor::listarTodos($idAdmin)]);
	}

	private static function setorSalvar(array $post): string {
		if (!self::isDiretor()) {
			return json_encode(['success' => false, 'message' => 'Apenas o Diretor gerencia setores.']);
		}
		$idAdmin = self::idAdmin();
		$id = (int)($post['id'] ?? 0);
		$nome = trim((string)($post['nome'] ?? ''));
		if ($nome === '') {
			return json_encode(['success' => false, 'message' => 'Informe o nome do setor.']);
		}

		$slug = trim((string)($post['slug'] ?? ''));
		if ($slug === '') {
			$slug = preg_replace('/[^a-z0-9]+/', '-', strtolower(iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $nome) ?: $nome));
			$slug = trim((string)$slug, '-') ?: 'setor';
		}

		$ob = null;
		if ($id) {
			$ob = WhatsappSetor::getById($id, $idAdmin);
			if (!$ob) {
				return json_encode(['success' => false, 'message' => 'Setor não encontrado.']);
			}
		} else {
			$ob = new WhatsappSetor;
		}
		$ob->id_admin = $idAdmin;
		$ob->nome = $nome;
		$ob->slug = $slug;
		$ob->ordem = (int)($post['ordem'] ?? 0);
		$ob->ativo = !empty($post['ativo']) ? 1 : 0;
		$ob->mensagem_fila = trim((string)($post['mensagem_fila'] ?? ''));
		$ob->salvar();

		return json_encode(['success' => true, 'message' => 'Setor salvo.']);
	}

	private static function atendentesListar(array $post): string {
		$idAdmin = self::idAdmin();
		if (!WhatsappAtendente::tabelaExiste()) {
			return json_encode(['success' => false, 'message' => 'Execute o SQL de atendentes.']);
		}
		return json_encode(['success' => true, 'atendentes' => WhatsappAtendente::listarPorEscola($idAdmin)]);
	}

	private static function atendenteVincular(array $post): string {
		if (!self::isDiretor()) {
			return json_encode(['success' => false, 'message' => 'Apenas o Diretor vincula atendentes.']);
		}
		$idAdmin = self::idAdmin();
		$usuarioId = (int)($post['usuario_id'] ?? 0);
		$setorId = (int)($post['setor_id'] ?? 0);
		if (!$usuarioId || !$setorId) {
			return json_encode(['success' => false, 'message' => 'Selecione usuário e setor.']);
		}
		WhatsappAtendente::vincular($idAdmin, $usuarioId, $setorId);
		return json_encode(['success' => true, 'message' => 'Atendente vinculado.']);
	}

	private static function atendenteRemover(array $post): string {
		if (!self::isDiretor()) {
			return json_encode(['success' => false, 'message' => 'Apenas o Diretor remove vínculos.']);
		}
		WhatsappAtendente::desvincular(self::idAdmin(), (int)($post['id'] ?? 0));
		return json_encode(['success' => true, 'message' => 'Vínculo removido.']);
	}

	private static function usuariosLista(array $post): string {
		$idAdmin = self::idAdmin();
		$rows = User::getUser(
			'id_admin = '.$idAdmin." AND nivel NOT IN ('Cliente','Empresa') AND ativo = 's'",
			'nome ASC',
			null,
			'id, nome, nivel'
		)->fetchAll(\PDO::FETCH_ASSOC);

		return json_encode(['success' => true, 'usuarios' => $rows ?: []]);
	}

	private static function podeVer(WhatsappConversa $conv): bool {
		$user = self::user();
		$uid = (int)($user['usuario']['id'] ?? 0);
		$nivel = (string)($user['usuario']['nivel'] ?? '');
		$setores = WhatsappAtendente::setoresDoUsuario(self::idAdmin(), $uid);

		return WhatsappConversa::usuarioPodeVerConversa($conv, $nivel, $uid, $setores);
	}
}
