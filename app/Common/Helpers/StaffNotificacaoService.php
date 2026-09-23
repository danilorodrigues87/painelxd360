<?php

namespace App\Common\Helpers;

use App\Model\Entity\MetaConversa;
use App\Model\Entity\StaffNotificacao;

/**
 * Notificações in-app para funcionários (painel admin).
 */
class StaffNotificacaoService {

	/**
	 * @return array<int,string>
	 */
	public static function tiposPermitidosUsuario(int $idAdmin, array $acessoUsuario, string $nivel): array {
		$tipos = [];
		$slugs = ModuleGateHelper::getSlugsEscola($idAdmin);

		$verWa = in_array('whatsapp', $slugs, true)
			&& ($nivel === 'Diretor' || ModuleGateHelper::podeAcessar('WhatsApp', $idAdmin, $acessoUsuario));
		if ($verWa) {
			$tipos[] = 'whatsapp_mensagem';
		}

		$verMeta = in_array('social', $slugs, true)
			&& (
				ModuleGateHelper::podeAcessar('Redes sociais', $idAdmin, $acessoUsuario)
				|| $nivel === 'Diretor'
			);
		if ($verMeta) {
			$tipos[] = 'meta_messenger';
			$tipos[] = 'meta_instagram';
		}

		return $tipos;
	}

	public static function novaMensagemWhatsapp(
		int $idAdmin,
		int $conversaId,
		?string $nomeContato,
		?string $preview,
		?string $waMessageId = null
	): void {
		if (!StaffNotificacao::tabelaExiste() || $idAdmin <= 0 || $conversaId <= 0) {
			return;
		}
		$nome = trim((string)$nomeContato);
		$titulo = $nome !== '' ? 'WhatsApp: '.$nome : 'Nova mensagem no WhatsApp';
		$msg = trim((string)$preview);
		if ($msg === '') {
			$msg = 'Você recebeu uma nova mensagem.';
		}
		$ref = $waMessageId !== null && $waMessageId !== ''
			? 'wa:'.md5($waMessageId)
			: 'wa:conv:'.$conversaId.':'.time();

		$link = '/painel/whatsapp?conversa='.(int)$conversaId;
		$id = StaffNotificacao::criar([
			'id_admin'  => $idAdmin,
			'tipo'      => 'whatsapp_mensagem',
			'titulo'    => $titulo,
			'mensagem'  => mb_substr($msg, 0, 500),
			'link'      => $link,
			'ref_chave' => $ref,
		]);
		if ($id !== null) {
			OneSignalPushService::enviarEscola($idAdmin, 'whatsapp_mensagem', $titulo, $msg, $link);
		}
	}

	public static function novaMensagemMeta(
		int $idAdmin,
		int $conversaId,
		string $canal,
		?string $nomeContato,
		?string $preview,
		?string $metaMessageId = null
	): void {
		if (!StaffNotificacao::tabelaExiste() || $idAdmin <= 0 || $conversaId <= 0) {
			return;
		}
		$canalNorm = MetaConversa::normalizarCanal($canal);
		$tipo = $canalNorm === 'instagram' ? 'meta_instagram' : 'meta_messenger';
		$rotulo = $canalNorm === 'instagram' ? 'Instagram Direct' : 'Messenger';
		$nome = trim((string)$nomeContato);
		$titulo = $nome !== '' ? $rotulo.': '.$nome : 'Nova mensagem no '.$rotulo;
		$msg = trim((string)$preview);
		if ($msg === '') {
			$msg = 'Você recebeu uma nova mensagem.';
		}
		$ref = $metaMessageId !== null && $metaMessageId !== ''
			? 'meta:'.md5($metaMessageId)
			: 'meta:conv:'.$conversaId.':'.time();

		$link = '/painel/social/mensagens?conversa='.(int)$conversaId;
		$id = StaffNotificacao::criar([
			'id_admin'  => $idAdmin,
			'tipo'      => $tipo,
			'titulo'    => $titulo,
			'mensagem'  => mb_substr($msg, 0, 500),
			'link'      => $link,
			'ref_chave' => $ref,
		]);
		if ($id !== null) {
			OneSignalPushService::enviarEscola($idAdmin, $tipo, $titulo, $msg, $link);
		}
	}

	public static function labelTipo(string $tipo): string {
		switch ($tipo) {
			case 'whatsapp_mensagem':
				return 'WhatsApp';
			case 'meta_messenger':
				return 'Messenger';
			case 'meta_instagram':
				return 'Instagram';
			default:
				return 'Sistema';
		}
	}

	public static function iconeTipo(string $tipo): string {
		switch ($tipo) {
			case 'whatsapp_mensagem':
				return 'fab fa-whatsapp text-success';
			case 'meta_messenger':
				return 'fab fa-facebook-messenger text-primary';
			case 'meta_instagram':
				return 'fab fa-instagram text-danger';
			default:
				return 'fas fa-bell text-secondary';
		}
	}
}
