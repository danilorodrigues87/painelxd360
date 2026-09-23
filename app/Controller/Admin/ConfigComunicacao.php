<?php

namespace App\Controller\Admin;

use App\Utils\View;
use App\Session\User\Login as SessionUser;
use App\Common\Helpers\TenantHelper;
use App\Common\Communication\Email;
use App\Common\Communication\CobrancaEmailService;
use App\Common\Communication\AniversarioEmailService;
use App\Common\Communication\WhatsappEscolaService;
use App\Common\Helpers\EmailAuditoriaHelper;
use App\Common\Helpers\EmailValidator;
use App\Model\Entity\EscolaIntegracoes;
use App\Model\Entity\EmailAniversarioLog;
use App\Model\Entity\ComunicacaoWorkerRun;

class ConfigComunicacao extends Page {

	private static function assertDiretor($request, bool $api = false): bool {
		$user = SessionUser::getUserLogedData();
		if (($user['usuario']['nivel'] ?? '') !== 'Diretor') {
			if (!$api) {
				$request->getRouter()->redirect('/painel');
			}
			return false;
		}
		return true;
	}

	public static function index($request) {
		if (!self::assertDiretor($request)) {
			return '';
		}

		$content = View::render('admin/modules/config/comunicacao', []);
		return parent::getPanel('Comunicação', $content, 'config');
	}

	public static function getInfo($request) {
		if (!self::assertDiretor($request, true)) {
			return json_encode(['success' => false, 'message' => 'Acesso negado.']);
		}

		$postVars = $request->getPostVars();
		$acao = $postVars['acao'] ?? '';

		if ($acao === 'carregar') {
			return self::carregarConfig();
		}

		if ($acao === 'salvar') {
			return self::salvarConfig($postVars);
		}

		if ($acao === 'testar') {
			return self::testarEnvio($postVars);
		}

		if ($acao === 'preview_cobranca') {
			return self::previewCobranca($postVars);
		}

		if ($acao === 'executar_cobranca') {
			return self::executarCobranca();
		}

		if ($acao === 'auditar_emails') {
			return self::auditarEmails();
		}

		if ($acao === 'preview_aniversario') {
			return self::previewAniversario($postVars);
		}

		if ($acao === 'executar_aniversario') {
			return self::executarAniversario();
		}

		if ($acao === 'whatsapp_status') {
			return self::whatsappStatus();
		}

		if ($acao === 'whatsapp_checklist') {
			return self::whatsappChecklist();
		}

		if ($acao === 'whatsapp_conectar') {
			return self::whatsappConectar();
		}

		if ($acao === 'whatsapp_qr') {
			return self::whatsappQr();
		}

		if ($acao === 'whatsapp_salvar') {
			return self::whatsappSalvar($postVars);
		}

		if ($acao === 'whatsapp_testar') {
			return self::whatsappTestar($postVars);
		}

		if ($acao === 'whatsapp_desconectar') {
			return self::whatsappDesconectar($postVars);
		}

		if ($acao === 'whatsapp_recriar') {
			return self::whatsappRecriar();
		}

		if ($acao === 'whatsapp_sincronizar') {
			return self::whatsappSincronizar();
		}

		return json_encode(['success' => false, 'message' => 'Ação inválida.']);
	}

	private static function carregarConfig(): string {
		if (!EscolaIntegracoes::tabelaExiste()) {
			$sistema = Email::getRemetenteSistema();
			return json_encode([
				'success' => true,
				'aviso'   => 'A tabela escola_integracoes ainda não foi criada. Execute o SQL no phpMyAdmin para salvar as configurações.',
				'config'  => [
					'smtp_host'            => '',
					'smtp_port'            => 587,
					'smtp_user'            => '',
					'smtp_from_email'      => '',
					'smtp_from_name'       => '',
					'smtp_encryption'      => 'tls',
					'smtp_ativo'           => 0,
					'email_delay_segundos' => 3,
					'email_max_hora'       => 80,
					'tem_senha'            => false,
				],
				'sistema' => [
					'from_email'  => $sistema['email'],
					'from_name'   => $sistema['nome'],
					'configurado' => !empty($sistema['email']),
				],
				'modo_envio' => 'sistema',
				'cobranca' => self::formatCobrancaConfig(null),
				'templates_padrao' => CobrancaEmailService::getTemplatesPadrao(),
			]);
		}

		$idAdmin = TenantHelper::getIdAdmin();
		$integracao = EscolaIntegracoes::getByIdAdmin($idAdmin);
		$sistema = Email::getRemetenteSistema();

		$dados = [
			'smtp_host'            => '',
			'smtp_port'            => 587,
			'smtp_user'            => '',
			'smtp_from_email'      => '',
			'smtp_from_name'       => '',
			'smtp_encryption'      => 'tls',
			'smtp_ativo'           => 0,
			'email_delay_segundos' => 3,
			'email_max_hora'       => 80,
			'tem_senha'            => false,
		];

		if ($integracao instanceof EscolaIntegracoes) {
			$dados = [
				'smtp_host'            => $integracao->smtp_host ?? '',
				'smtp_port'            => (int)($integracao->smtp_port ?? 587),
				'smtp_user'            => $integracao->smtp_user ?? '',
				'smtp_from_email'      => $integracao->smtp_from_email ?? '',
				'smtp_from_name'       => $integracao->smtp_from_name ?? '',
				'smtp_encryption'      => $integracao->smtp_encryption ?? 'tls',
				'smtp_ativo'           => (int)($integracao->smtp_ativo ?? 0),
				'email_delay_segundos' => (int)($integracao->email_delay_segundos ?? 3),
				'email_max_hora'       => (int)($integracao->email_max_hora ?? 80),
				'tem_senha'            => !empty($integracao->getSenhaDescriptografada()),
			];
		}

		$modoEnvio = 'sistema';
		$avisoSmtp = null;
		if ($integracao instanceof EscolaIntegracoes && $integracao->temSmtpConfigurado()) {
			$modoEnvio = 'escola';
		} elseif ($integracao instanceof EscolaIntegracoes && (int)$integracao->smtp_ativo === 1) {
			$avisoSmtp = 'O SMTP da escola está marcado como ativo, mas a senha não está legível (ex.: APP_KEY mudou) ou está incompleto. Os envios estão usando o e-mail do .env. Desative o switch ou regrave a senha.';
		}

		return json_encode([
			'success' => true,
			'config'  => $dados,
			'cobranca'=> self::formatCobrancaConfig($integracao),
			'aniversario' => self::formatAniversarioConfig($integracao),
			'whatsapp' => WhatsappEscolaService::status($idAdmin),
			'templates_padrao' => CobrancaEmailService::getTemplatesPadrao(),
			'templates_aniversario' => AniversarioEmailService::getTemplatePadrao(),
			'aviso_smtp' => $avisoSmtp,
			'sistema' => [
				'from_email' => $sistema['email'],
				'from_name'  => $sistema['nome'],
				'configurado' => !empty($sistema['email']),
			],
			'modo_envio' => $modoEnvio,
			'cron_comunicacao' => self::metaCronComunicacao($idAdmin),
		]);
	}

	private static function metaCronComunicacao(int $idAdmin): array {
		$tokenOk = defined('SYSTEM_TOKEN') && SYSTEM_TOKEN !== '';
		$base = rtrim((string)URL, '/');
		$tokenHint = $tokenOk ? '***' : 'SEU_SYSTEM_TOKEN';

		return [
			'token_ok' => $tokenOk,
			'worker_ok' => ComunicacaoWorkerRun::tabelaExiste(),
			'cobranca' => [
				'url_cron' => $base.'/cron/cobranca?token='.$tokenHint,
				'cron_cli' => '0 8 * * * php worker/cobranca.php',
				'ultima' => ComunicacaoWorkerRun::ultima('cobranca', $idAdmin),
			],
			'aniversario' => [
				'url_cron' => $base.'/cron/aniversario?token='.$tokenHint,
				'cron_cli' => '5 8 * * * php worker/aniversario.php',
				'ultima' => ComunicacaoWorkerRun::ultima('aniversario', $idAdmin),
			],
			'hint' => $tokenOk
				? 'Configure os crons no cPanel com SYSTEM_TOKEN do .env (1x/dia).'
				: 'Defina SYSTEM_TOKEN no .env para usar cron HTTP no cPanel.',
		];
	}

	private static function salvarConfig(array $postVars): string {
		if (!EscolaIntegracoes::tabelaExiste()) {
			return json_encode([
				'success' => false,
				'message' => 'Crie a tabela escola_integracoes no phpMyAdmin antes de salvar.',
			]);
		}

		$idAdmin = TenantHelper::getIdAdmin();
		$existente = EscolaIntegracoes::getByIdAdmin($idAdmin);

		$host = trim($postVars['smtp_host'] ?? '');
		$user = trim($postVars['smtp_user'] ?? '');
		$fromEmail = trim($postVars['smtp_from_email'] ?? '');
		$fromName = trim($postVars['smtp_from_name'] ?? '');
		$encryption = $postVars['smtp_encryption'] ?? 'tls';
		$ativo = !empty($postVars['smtp_ativo']) ? 1 : 0;
		$senha = trim($postVars['smtp_pass'] ?? '');
		if ($senha !== '') {
			$senha = str_replace(' ', '', $senha);
		}

		if (!in_array($encryption, ['tls', 'ssl', 'none'], true)) {
			$encryption = 'tls';
		}

		if ($ativo && (empty($host) || empty($user) || empty($fromEmail))) {
			return json_encode([
				'success' => false,
				'message' => 'Preencha servidor, usuário e e-mail remetente para ativar o SMTP da escola.',
			]);
		}

		if ($fromEmail !== '' && !filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
			return json_encode(['success' => false, 'message' => 'E-mail remetente inválido.']);
		}

		if ($ativo && $senha === '' && !($existente instanceof EscolaIntegracoes && $existente->getSenhaDescriptografada())) {
			return json_encode(['success' => false, 'message' => 'Informe a senha SMTP ou app password.']);
		}

		$ob = new EscolaIntegracoes;
		$ob->id_admin = $idAdmin;
		$ob->smtp_host = $host;
		$ob->smtp_port = (int)($postVars['smtp_port'] ?? 587);
		$ob->smtp_user = $user;
		$ob->smtp_from_email = $fromEmail;
		$ob->smtp_from_name = $fromName;
		$ob->smtp_encryption = $encryption;
		$ob->smtp_ativo = $ativo;
		$ob->email_delay_segundos = max(1, (int)($postVars['email_delay_segundos'] ?? 3));
		$ob->email_max_hora = max(1, (int)($postVars['email_max_hora'] ?? 80));

		if ($senha !== '') {
			$ob->smtp_pass = $senha;
		}

		self::aplicarCobrancaNoObjeto($ob, $postVars);
		self::aplicarAniversarioNoObjeto($ob, $postVars);

		if (!$ob->salvar()) {
			$msg = EscolaIntegracoes::getUltimoErro() ?: 'Não foi possível salvar as configurações.';
			return json_encode(['success' => false, 'message' => $msg]);
		}

		return json_encode(['success' => true, 'message' => 'Configurações salvas com sucesso.']);
	}

	private static function testarEnvio(array $postVars): string {
		$idAdmin = TenantHelper::getIdAdmin();
		$destino = trim($postVars['email_teste'] ?? '');

		if ($destino === '' || !EmailValidator::isValido($destino)) {
			return json_encode(['success' => false, 'message' => 'Informe um e-mail válido para o teste.']);
		}

		$override = [
			'smtp_host'       => trim($postVars['smtp_host'] ?? ''),
			'smtp_port'       => (int)($postVars['smtp_port'] ?? 587),
			'smtp_user'       => trim($postVars['smtp_user'] ?? ''),
			'smtp_from_email' => trim($postVars['smtp_from_email'] ?? ''),
			'smtp_from_name'  => trim($postVars['smtp_from_name'] ?? ''),
			'smtp_encryption' => $postVars['smtp_encryption'] ?? 'tls',
			'smtp_ativo'      => !empty($postVars['smtp_ativo']) ? 1 : 0,
			'manter_senha'    => true,
		];

		$senha = $postVars['smtp_pass'] ?? '';
		if ($senha !== '') {
			$senha = str_replace(' ', '', trim($senha));
			$override['smtp_pass'] = $senha;
		}

		$email = Email::escolaParaTeste($idAdmin, $override);
		$usandoSistema = $email->isUsandoSistema();

		$assunto = 'Teste de e-mail - Painel CTI';
		$corpo = '<p>Este é um e-mail de teste enviado pelo painel CTI.</p>'
			.'<p>'.($usandoSistema
				? 'Remetente: e-mail padrão do sistema.'
				: 'Remetente: SMTP configurado pela escola.').'</p>';

		$enviado = $email->sendEmail($destino, $assunto, $corpo);

		if (!$enviado) {
			return json_encode([
				'success' => false,
				'message' => $email->getError() ?: 'Falha ao enviar e-mail de teste.',
			]);
		}

		return json_encode([
			'success' => true,
			'message' => $usandoSistema
				? 'Teste enviado usando o e-mail padrão do sistema.'
				: 'Teste enviado usando o SMTP da escola.',
			'modo' => $usandoSistema ? 'sistema' : 'escola',
		]);
	}

	private static function formatCobrancaConfig($integracao): array {
		$padrao = [
			'cobranca_ativo'             => 0,
			'cobranca_dias_antes'        => '3,5',
			'cobranca_aviso_vencimento'  => 1,
			'cobranca_dias_depois'       => '1,3,7',
			'cobranca_enviar_responsavel'=> 1,
			'cobranca_whatsapp_ativo'    => 0,
			'cobranca_assunto_antes'     => '',
			'cobranca_assunto_vencimento'=> '',
			'cobranca_assunto_atraso'    => '',
			'cobranca_msg_antes'         => '',
			'cobranca_msg_vencimento'    => '',
			'cobranca_msg_atraso'        => '',
			'colunas_ok'                 => EscolaIntegracoes::temColunasCobranca(),
			'log_ok'                     => \App\Model\Entity\EmailCobrancaLog::tabelaExiste(),
			'wa_automacao_ok'            => EscolaIntegracoes::temColunasWhatsappAutomacao(),
		];

		if ($integracao instanceof EscolaIntegracoes && EscolaIntegracoes::temColunasCobranca()) {
			$padrao['cobranca_ativo'] = (int)($integracao->cobranca_ativo ?? 0);
			$padrao['cobranca_dias_antes'] = $integracao->cobranca_dias_antes ?? '3,5';
			$padrao['cobranca_aviso_vencimento'] = (int)($integracao->cobranca_aviso_vencimento ?? 1);
			$padrao['cobranca_dias_depois'] = $integracao->cobranca_dias_depois ?? '1,3,7';
			$padrao['cobranca_enviar_responsavel'] = (int)($integracao->cobranca_enviar_responsavel ?? 1);
			$padrao['cobranca_whatsapp_ativo'] = (int)($integracao->cobranca_whatsapp_ativo ?? 0);
			$padrao['cobranca_assunto_antes'] = $integracao->cobranca_assunto_antes ?? '';
			$padrao['cobranca_assunto_vencimento'] = $integracao->cobranca_assunto_vencimento ?? '';
			$padrao['cobranca_assunto_atraso'] = $integracao->cobranca_assunto_atraso ?? '';
			$padrao['cobranca_msg_antes'] = $integracao->cobranca_msg_antes ?? '';
			$padrao['cobranca_msg_vencimento'] = $integracao->cobranca_msg_vencimento ?? '';
			$padrao['cobranca_msg_atraso'] = $integracao->cobranca_msg_atraso ?? '';
		}

		return $padrao;
	}

	private static function aplicarCobrancaNoObjeto(EscolaIntegracoes $ob, array $postVars): void {
		if (!EscolaIntegracoes::temColunasCobranca()) {
			return;
		}

		$ob->cobranca_ativo = !empty($postVars['cobranca_ativo']) ? 1 : 0;
		$ob->cobranca_dias_antes = trim($postVars['cobranca_dias_antes'] ?? '3,5');
		$ob->cobranca_aviso_vencimento = !empty($postVars['cobranca_aviso_vencimento']) ? 1 : 0;
		$ob->cobranca_dias_depois = trim($postVars['cobranca_dias_depois'] ?? '1,3,7');
		$ob->cobranca_enviar_responsavel = !empty($postVars['cobranca_enviar_responsavel']) ? 1 : 0;
		$ob->cobranca_whatsapp_ativo = !empty($postVars['cobranca_whatsapp_ativo']) ? 1 : 0;
		$ob->cobranca_assunto_antes = trim($postVars['cobranca_assunto_antes'] ?? '');
		$ob->cobranca_assunto_vencimento = trim($postVars['cobranca_assunto_vencimento'] ?? '');
		$ob->cobranca_assunto_atraso = trim($postVars['cobranca_assunto_atraso'] ?? '');
		$ob->cobranca_msg_antes = trim($postVars['cobranca_msg_antes'] ?? '');
		$ob->cobranca_msg_vencimento = trim($postVars['cobranca_msg_vencimento'] ?? '');
		$ob->cobranca_msg_atraso = trim($postVars['cobranca_msg_atraso'] ?? '');
	}

	private static function previewCobranca(array $postVars = []): string {
		$idAdmin = TenantHelper::getIdAdmin();
		$preview = CobrancaEmailService::preview($idAdmin, $postVars);
		return json_encode(['success' => true, 'preview' => $preview]);
	}

	private static function executarCobranca(): string {
		$idAdmin = TenantHelper::getIdAdmin();
		$resumo = CobrancaEmailService::processar($idAdmin, false);
		ComunicacaoWorkerRun::registrar('cobranca', 'painel', $idAdmin, $resumo);
		return json_encode([
			'success' => true,
			'message' => 'Enviados: '.($resumo['enviados'] ?? 0).'. Erros: '.($resumo['erros'] ?? 0).'.',
			'resumo'  => $resumo,
		]);
	}

	private static function auditarEmails(): string {
		$idAdmin = TenantHelper::getIdAdmin();
		$relatorio = EmailAuditoriaHelper::auditarContatosEscola($idAdmin, 150);
		return json_encode(['success' => true, 'auditoria' => $relatorio]);
	}

	private static function formatAniversarioConfig($integracao): array {
		$tpl = AniversarioEmailService::getTemplatePadrao();
		$padrao = [
			'aniversario_ativo' => 0,
			'aniversario_apenas_matriculados' => 1,
			'aniversario_whatsapp_ativo' => 0,
			'aniversario_assunto' => '',
			'aniversario_mensagem' => '',
			'colunas_ok' => EscolaIntegracoes::temColunasAniversario(),
			'log_ok' => EmailAniversarioLog::tabelaExiste(),
			'wa_automacao_ok' => EscolaIntegracoes::temColunasWhatsappAutomacao(),
			'templates' => $tpl,
		];

		if ($integracao instanceof EscolaIntegracoes && EscolaIntegracoes::temColunasAniversario()) {
			$padrao['aniversario_ativo'] = (int)($integracao->aniversario_ativo ?? 0);
			$padrao['aniversario_apenas_matriculados'] = (int)($integracao->aniversario_apenas_matriculados ?? 1);
			$padrao['aniversario_whatsapp_ativo'] = (int)($integracao->aniversario_whatsapp_ativo ?? 0);
			$padrao['aniversario_assunto'] = $integracao->aniversario_assunto ?? '';
			$padrao['aniversario_mensagem'] = $integracao->aniversario_mensagem ?? '';
		}

		return $padrao;
	}

	private static function aplicarAniversarioNoObjeto(EscolaIntegracoes $ob, array $postVars): void {
		if (!EscolaIntegracoes::temColunasAniversario()) {
			return;
		}
		$ob->aniversario_ativo = !empty($postVars['aniversario_ativo']) ? 1 : 0;
		$ob->aniversario_apenas_matriculados = !empty($postVars['aniversario_apenas_matriculados']) ? 1 : 0;
		$ob->aniversario_whatsapp_ativo = !empty($postVars['aniversario_whatsapp_ativo']) ? 1 : 0;
		$ob->aniversario_assunto = trim($postVars['aniversario_assunto'] ?? '');
		$ob->aniversario_mensagem = trim($postVars['aniversario_mensagem'] ?? '');
	}

	private static function previewAniversario(array $postVars = []): string {
		$idAdmin = TenantHelper::getIdAdmin();
		$preview = AniversarioEmailService::preview($idAdmin, $postVars);
		return json_encode(['success' => true, 'preview' => $preview]);
	}

	private static function executarAniversario(): string {
		$idAdmin = TenantHelper::getIdAdmin();
		$resumo = AniversarioEmailService::processar($idAdmin, false);
		ComunicacaoWorkerRun::registrar('aniversario', 'painel', $idAdmin, $resumo);
		return json_encode([
			'success' => empty($resumo['erro']),
			'message' => isset($resumo['erro'])
				? $resumo['erro']
				: 'Enviados: '.($resumo['enviados'] ?? 0).'. Erros: '.($resumo['erros'] ?? 0).'.',
			'resumo' => $resumo,
		]);
	}

	private static function whatsappStatus(): string {
		$idAdmin = TenantHelper::getIdAdmin();
		return json_encode([
			'success' => true,
			'whatsapp' => WhatsappEscolaService::status($idAdmin),
			'checklist' => WhatsappEscolaService::checklist($idAdmin),
		], JSON_UNESCAPED_UNICODE);
	}

	private static function whatsappChecklist(): string {
		$idAdmin = TenantHelper::getIdAdmin();
		return json_encode([
			'success' => true,
			'checklist' => WhatsappEscolaService::checklist($idAdmin),
		], JSON_UNESCAPED_UNICODE);
	}

	private static function whatsappConectar(): string {
		$idAdmin = TenantHelper::getIdAdmin();
		$res = WhatsappEscolaService::criarOuConectar($idAdmin);
		return json_encode([
			'success' => !empty($res['ok']),
			'message' => $res['message'] ?? '',
			'whatsapp'=> $res,
		]);
	}

	private static function whatsappQr(): string {
		$idAdmin = TenantHelper::getIdAdmin();
		$res = WhatsappEscolaService::obterQr($idAdmin);
		return json_encode([
			'success' => !empty($res['ok']),
			'message' => $res['message'] ?? '',
			'whatsapp'=> $res,
		]);
	}

	private static function whatsappSalvar(array $postVars): string {
		$idAdmin = TenantHelper::getIdAdmin();
		$res = WhatsappEscolaService::salvarLimites($idAdmin, $postVars);
		return json_encode([
			'success' => !empty($res['ok']),
			'message' => $res['message'] ?? '',
		]);
	}

	private static function whatsappTestar(array $postVars): string {
		$idAdmin = TenantHelper::getIdAdmin();
		$tel = trim($postVars['whatsapp_teste'] ?? '');
		$msg = trim($postVars['whatsapp_msg_teste'] ?? '');
		$res = WhatsappEscolaService::testarEnvio($idAdmin, $tel, $msg);
		return json_encode([
			'success' => !empty($res['ok']),
			'message' => $res['message'] ?? '',
		]);
	}

	private static function whatsappDesconectar(array $postVars = []): string {
		$idAdmin = TenantHelper::getIdAdmin();
		$apagar = !empty($postVars['apagar_instancia']);
		$res = WhatsappEscolaService::desconectar($idAdmin, $apagar);
		return json_encode([
			'success' => !empty($res['ok']),
			'message' => $res['message'] ?? '',
			'warning' => !empty($res['warning']),
		], JSON_UNESCAPED_UNICODE);
	}

	private static function whatsappRecriar(): string {
		$idAdmin = TenantHelper::getIdAdmin();
		$res = WhatsappEscolaService::recriarInstancia($idAdmin);
		return json_encode([
			'success' => !empty($res['ok']),
			'message' => $res['message'] ?? '',
			'whatsapp'=> $res,
		], JSON_UNESCAPED_UNICODE);
	}

	private static function whatsappSincronizar(): string {
		$idAdmin = TenantHelper::getIdAdmin();
		$res = WhatsappEscolaService::sincronizarInstancia($idAdmin);
		return json_encode([
			'success' => !empty($res['ok']),
			'message' => $res['message'] ?? '',
			'whatsapp'=> $res,
		], JSON_UNESCAPED_UNICODE);
	}
}
