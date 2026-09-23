<?php

namespace App\Controller\Admin;

use App\Utils\View;
use App\Session\User\Login as SessionUser;
use App\Common\Helpers\TenantHelper;
use App\Common\Helpers\ModuleGateHelper;
use App\Common\Helpers\MetaGraphHelper;
use App\Common\Helpers\MetaMessagingService;
use App\Model\Entity\EscolaIntegracoes;
use App\Model\Entity\MetaConversa;
use App\Model\Entity\MetaMensagem;
use App\Model\Db\Database;

/**
 * Inbox Meta — Messenger + Instagram Direct (Fase B).
 */
class MetaInbox extends Page {

	private static function assertAcesso($request, bool $api = false): bool {
		$user = SessionUser::getUserLogedData();
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

	private static function json(array $data): string {
		return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
	}

	private static function idAdmin(): int {
		return TenantHelper::getIdAdmin();
	}

	private static function userId(): int {
		$user = SessionUser::getUserLogedData();
		return (int)($user['usuario']['id'] ?? 0);
	}

	public static function index($request) {
		if (!self::assertAcesso($request)) {
			return '';
		}
		$content = View::render('admin/modules/social/mensagens', []);
		return parent::getPanel('Redes sociais', $content, 'marketing', $request);
	}

	public static function getInfo($request) {
		if (!self::assertAcesso($request, true)) {
			return self::json(['success' => false, 'message' => 'Acesso negado.']);
		}

		$post = $request->getPostVars();
		$acao = (string)($post['acao'] ?? '');

		$map = [
			'listar'     => 'listar',
			'mensagens'  => 'mensagens',
			'enviar'     => 'enviar',
			'arquivar'   => 'arquivar',
			'reabrir'    => 'reabrir',
			'status_meta'=> 'statusMeta',
		];

		if (!isset($map[$acao])) {
			return self::json(['success' => false, 'message' => 'Ação inválida.']);
		}

		return self::{$map[$acao]}($post);
	}

	private static function listar(array $post): string {
		if (!MetaConversa::tabelaExiste()) {
			return self::json([
				'success' => false,
				'message' => 'Execute database/meta_messaging.sql no phpMyAdmin.',
			]);
		}

		$idAdmin = self::idAdmin();
		$filtro = (string)($post['filtro'] ?? 'todas');
		$busca = trim((string)($post['busca'] ?? ''));

		return self::json([
			'success'    => true,
			'conversas'  => MetaConversa::listarInbox($idAdmin, 80, $filtro, $busca),
			'indicadores'=> MetaConversa::indicadores($idAdmin),
			'filtro'     => $filtro,
			'busca'      => $busca,
			'meta'       => self::metaConexao($idAdmin),
		]);
	}

	private static function mensagens(array $post): string {
		$idAdmin = self::idAdmin();
		$id = (int)($post['conversa_id'] ?? 0);
		$conv = MetaConversa::getById($id, $idAdmin);
		if (!$conv instanceof MetaConversa) {
			return self::json(['success' => false, 'message' => 'Conversa não encontrada.']);
		}

		$conv->marcarLida();

		$rows = [];
		if (MetaMensagem::tabelaExiste()) {
			$rows = (new Database('meta_mensagens'))
				->select('conversa_id = '.$id.' AND id_admin = '.$idAdmin, 'id ASC', '200')
				->fetchAll(\PDO::FETCH_ASSOC) ?: [];
		}

		foreach ($rows as &$row) {
			$row['direction_label'] = ($row['direction'] ?? '') === 'out' ? 'Enviada' : 'Recebida';
		}
		unset($row);

		return self::json([
			'success' => true,
			'conversa' => [
				'id'               => (int)$conv->id,
				'canal'            => $conv->canal,
				'canal_label'      => MetaConversa::labelCanal((string)$conv->canal),
				'nome_contato'     => $conv->nome_contato,
				'foto_url'         => $conv->foto_url,
				'participant_id'   => $conv->participant_id,
				'status'           => $conv->status,
				'ultima_mensagem'  => $conv->ultima_mensagem,
				'ultima_mensagem_em'=> $conv->ultima_mensagem_em,
			],
			'mensagens' => $rows,
		]);
	}

	private static function enviar(array $post): string {
		$texto = trim((string)($post['texto'] ?? ''));
		if ($texto === '') {
			return self::json(['success' => false, 'message' => 'Digite uma mensagem.']);
		}

		$idAdmin = self::idAdmin();
		$id = (int)($post['conversa_id'] ?? 0);
		$conv = MetaConversa::getById($id, $idAdmin);
		if (!$conv instanceof MetaConversa) {
			return self::json(['success' => false, 'message' => 'Conversa não encontrada.']);
		}
		if ((string)($conv->status ?? '') === 'arquivada') {
			$conv->reabrir();
		}

		$res = MetaMessagingService::enviarResposta($conv, $texto, self::userId());
		if (empty($res['ok'])) {
			return self::json([
				'success' => false,
				'message' => (string)($res['message'] ?? 'Falha ao enviar.'),
			]);
		}

		return self::json([
			'success' => true,
			'message' => 'Mensagem enviada.',
			'meta_message_id' => $res['meta_message_id'] ?? null,
		]);
	}

	private static function arquivar(array $post): string {
		$idAdmin = self::idAdmin();
		$id = (int)($post['conversa_id'] ?? 0);
		$conv = MetaConversa::getById($id, $idAdmin);
		if (!$conv instanceof MetaConversa) {
			return self::json(['success' => false, 'message' => 'Conversa não encontrada.']);
		}
		$conv->arquivar();
		return self::json(['success' => true, 'message' => 'Conversa arquivada.']);
	}

	private static function reabrir(array $post): string {
		$idAdmin = self::idAdmin();
		$id = (int)($post['conversa_id'] ?? 0);
		$conv = MetaConversa::getById($id, $idAdmin);
		if (!$conv instanceof MetaConversa) {
			return self::json(['success' => false, 'message' => 'Conversa não encontrada.']);
		}
		$conv->reabrir();
		return self::json(['success' => true, 'message' => 'Conversa reaberta.']);
	}

	private static function statusMeta(array $post): string {
		return self::json([
			'success' => true,
			'meta'    => self::metaConexao(self::idAdmin()),
		]);
	}

	/**
	 * @return array<string,mixed>
	 */
	private static function metaConexao(int $idAdmin): array {
		$tabelasOk = MetaConversa::tabelaExiste() && MetaMensagem::tabelaExiste();
		$cfg = EscolaIntegracoes::getByIdAdmin($idAdmin);
		$conectado = $cfg instanceof EscolaIntegracoes && $cfg->temMetaPronto();
		$pageName = $cfg instanceof EscolaIntegracoes ? (string)($cfg->meta_page_name ?? '') : '';
		$igUser = $cfg instanceof EscolaIntegracoes ? (string)($cfg->meta_ig_username ?? '') : '';

		return [
			'tabelas_ok'   => $tabelasOk,
			'conectado'    => $conectado,
			'page_name'    => $pageName,
			'ig_username'  => $igUser,
			'conectado_em' => $cfg instanceof EscolaIntegracoes ? (string)($cfg->meta_conectado_em ?? '') : '',
			'app_ok'       => MetaGraphHelper::appConfigurado(),
		];
	}
}
