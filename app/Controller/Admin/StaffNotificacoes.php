<?php

namespace App\Controller\Admin;

use App\Session\User\Login as SessionUser;
use App\Common\Helpers\OneSignalHelper;
use App\Common\Helpers\StaffNotificacaoService;
use App\Common\Helpers\TenantHelper;
use App\Model\Entity\StaffNotificacao;
use App\Model\Entity\StaffPushSubscription;

class StaffNotificacoes extends Page {

	private static function contextoUsuario(): ?array {
		$user = SessionUser::getUserLogedData();
		if (empty($user['usuario']['id'])) {
			return null;
		}
		return [
			'id_usuario' => (int)$user['usuario']['id'],
			'id_admin'   => TenantHelper::getIdAdmin(),
			'nivel'      => (string)($user['usuario']['nivel'] ?? ''),
			'acesso'     => is_array($user['usuario']['acesso'] ?? null) ? $user['usuario']['acesso'] : [],
		];
	}

	public static function getInfo($request): string {
		$ctx = self::contextoUsuario();
		if (!$ctx) {
			return json_encode(['success' => false, 'message' => 'Não autenticado.']);
		}

		if (!StaffNotificacao::tabelaExiste()) {
			return json_encode([
				'success'    => true,
				'sql_ok'     => false,
				'habilitado' => true,
				'nao_lidas'  => 0,
				'itens'      => [],
				'message'    => 'Execute database/staff_notificacoes.sql no phpMyAdmin.',
			], JSON_UNESCAPED_UNICODE);
		}

		$post = $request->getPostVars();
		$acao = (string)($post['acao'] ?? 'listar');
		$tipos = StaffNotificacaoService::tiposPermitidosUsuario(
			$ctx['id_admin'],
			$ctx['acesso'],
			$ctx['nivel']
		);
		$habilitado = !empty($tipos);

		if ($acao === 'contagem') {
			return json_encode([
				'success'   => true,
				'sql_ok'    => true,
				'habilitado'=> $habilitado,
				'nao_lidas' => $habilitado
					? StaffNotificacao::contarNaoLidas($ctx['id_admin'], $ctx['id_usuario'], $tipos)
					: 0,
			], JSON_UNESCAPED_UNICODE);
		}

		if ($acao === 'marcar_lida') {
			$id = (int)($post['id'] ?? 0);
			StaffNotificacao::marcarLida($id, $ctx['id_usuario'], $ctx['id_admin']);
			return json_encode([
				'success'   => true,
				'nao_lidas' => StaffNotificacao::contarNaoLidas($ctx['id_admin'], $ctx['id_usuario'], $tipos),
			], JSON_UNESCAPED_UNICODE);
		}

		if ($acao === 'marcar_todas') {
			StaffNotificacao::marcarTodasLidas($ctx['id_admin'], $ctx['id_usuario'], $tipos);
			return json_encode([
				'success'   => true,
				'nao_lidas' => 0,
			], JSON_UNESCAPED_UNICODE);
		}

		if ($acao === 'push_config') {
			$canWa = in_array('whatsapp_mensagem', $tipos, true);
			$canMeta = in_array('meta_messenger', $tipos, true) || in_array('meta_instagram', $tipos, true);
			return json_encode([
				'success'      => true,
				'habilitado'   => $habilitado,
				'onesignal'    => OneSignalHelper::sdkHabilitado(),
				'push_sql_ok'  => StaffPushSubscription::tabelaExiste(),
				'id_usuario'   => $ctx['id_usuario'],
				'id_admin'     => $ctx['id_admin'],
				'external_id'  => 'u'.$ctx['id_usuario'],
				'tags'         => [
					'id_admin' => (string)$ctx['id_admin'],
					'can_wa'   => $canWa ? '1' : '0',
					'can_meta' => $canMeta ? '1' : '0',
				],
			], JSON_UNESCAPED_UNICODE);
		}

		if ($acao === 'registrar_push') {
			$subId = trim((string)($post['subscription_id'] ?? ''));
			$salvo = false;
			if ($subId !== '' && StaffPushSubscription::tabelaExiste()) {
				$salvo = StaffPushSubscription::salvar($ctx['id_usuario'], $ctx['id_admin'], $subId);
			}
			return json_encode([
				'success' => true,
				'salvo'   => $salvo,
			], JSON_UNESCAPED_UNICODE);
		}

		$itens = StaffNotificacao::listarParaUsuario($ctx['id_admin'], $ctx['id_usuario'], $tipos, 40);
		foreach ($itens as &$item) {
			$item['tipo_label'] = StaffNotificacaoService::labelTipo((string)$item['tipo']);
			$item['tipo_icon'] = StaffNotificacaoService::iconeTipo((string)$item['tipo']);
		}
		unset($item);

		return json_encode([
			'success'    => true,
			'sql_ok'     => true,
			'habilitado' => $habilitado,
			'nao_lidas'  => $habilitado
				? StaffNotificacao::contarNaoLidas($ctx['id_admin'], $ctx['id_usuario'], $tipos)
				: 0,
			'itens'      => $habilitado ? $itens : [],
		], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
	}
}
