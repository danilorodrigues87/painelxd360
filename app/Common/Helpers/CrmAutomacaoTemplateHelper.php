<?php

namespace App\Common\Helpers;

use App\Model\Entity\EscolaIntegracoes;
use App\Model\Entity\ClientesAssinantes;

/**
 * Mensagens automáticas de WhatsApp ao mudar status do lead no CRM (Fase 5+).
 * NULL no banco → texto padrão CTI; flags desligadas → não envia.
 */
class CrmAutomacaoTemplateHelper {

	private static $statusComMensagem = [
		'novo',
		'em_atendimento',
		'matriculado',
	];

	public static function catalogoVariaveis(): array {
		return [
			'nome'          => 'Primeiro nome do lead (ou “olá” se vazio)',
			'nome_completo' => 'Nome completo do lead',
			'curso'         => 'Curso de interesse cadastrado no lead',
			'escola'        => 'Nome fantasia da escola',
		];
	}

	public static function labelsStatus(): array {
		return [
			'novo'           => 'Novo',
			'em_atendimento' => 'Em atendimento',
			'matriculado'    => 'Matriculado',
		];
	}

	public static function mensagemPadrao(string $status): ?string {
		switch ($status) {
			case 'novo':
				return 'Olá, {{nome}}! Recebemos seu contato. Em breve um atendente da escola falará com você.';
			case 'em_atendimento':
				return 'Olá, {{nome}}! Seu atendimento foi iniciado. Podemos te ajudar com dúvidas sobre cursos e horários.';
			case 'matriculado':
				return 'Parabéns, {{nome}}! Sua matrícula foi registrada. Se precisar de algo, é só responder esta mensagem.';
			default:
				return null;
		}
	}

	/** Resolve template bruto (custom ou padrão) para um status. */
	public static function resolverTemplate(int $idAdmin, string $status): ?string {
		if (!in_array($status, self::$statusComMensagem, true)) {
			return null;
		}

		$padrao = self::mensagemPadrao($status);
		if (!EscolaIntegracoes::temColunasCrmAutomacao()) {
			return $padrao;
		}

		$integ = EscolaIntegracoes::getByIdAdmin($idAdmin);
		if (!$integ instanceof EscolaIntegracoes) {
			return $padrao;
		}

		if ((int)($integ->crm_wa_automacao_ativo ?? 1) !== 1) {
			return null;
		}

		$cfg = self::mapaCamposStatus($status);
		if ($cfg === null) {
			return null;
		}

		if ((int)($integ->{$cfg['enviar']} ?? 1) !== 1) {
			return null;
		}

		$custom = trim((string)($integ->{$cfg['msg']} ?? ''));
		return $custom !== '' ? $custom : $padrao;
	}

	/** Mensagem final com variáveis substituídas (null = não enviar). */
	public static function mensagemParaLead(int $idAdmin, string $status, $lead): ?string {
		$template = self::resolverTemplate($idAdmin, $status);
		if ($template === null || trim($template) === '') {
			return null;
		}
		return self::aplicar($template, $lead, $idAdmin);
	}

	public static function aplicar(string $template, $lead, int $idAdmin): string {
		$nomeCompleto = trim((string)($lead->nome ?? ''));
		$primeiro = $nomeCompleto !== '' ? explode(' ', $nomeCompleto)[0] : 'olá';
		$curso = trim((string)($lead->curso_interesse ?? ''));
		if ($curso === '') {
			$curso = 'nossos cursos';
		}

		$escolaNome = 'nossa escola';
		$escola = ClientesAssinantes::getEscolaById($idAdmin);
		if ($escola instanceof ClientesAssinantes) {
			$n = trim((string)($escola->nome ?? ''));
			if ($n !== '') {
				$escolaNome = $n;
			}
		}

		$mapa = [
			'nome'          => $primeiro,
			'nome_completo' => $nomeCompleto !== '' ? $nomeCompleto : 'Cliente',
			'curso'         => $curso,
			'escola'        => $escolaNome,
		];

		$out = $template;
		foreach ($mapa as $chave => $valor) {
			$out = str_replace('{{'.$chave.'}}', $valor, $out);
		}
		return $out;
	}

	/** Configuração para a tela de edição (Diretor). */
	public static function configuracaoEscola(int $idAdmin): array {
		$colOk = EscolaIntegracoes::temColunasCrmAutomacao();
		$integ = $colOk ? EscolaIntegracoes::getByIdAdmin($idAdmin) : null;

		$statuses = [];
		foreach (self::$statusComMensagem as $status) {
			$cfg = self::mapaCamposStatus($status);
			$padrao = self::mensagemPadrao($status) ?? '';
			$custom = '';
			$enviar = 1;
			$usandoPadrao = true;

			if ($integ instanceof EscolaIntegracoes && $cfg !== null) {
				$enviar = (int)($integ->{$cfg['enviar']} ?? 1);
				$custom = trim((string)($integ->{$cfg['msg']} ?? ''));
				$usandoPadrao = ($custom === '');
			}

			$statuses[$status] = [
				'label'         => self::labelsStatus()[$status] ?? $status,
				'enviar'        => $enviar,
				'mensagem'      => $usandoPadrao ? $padrao : $custom,
				'padrao'        => $padrao,
				'usando_padrao' => $usandoPadrao,
			];
		}

		$vars = [];
		foreach (self::catalogoVariaveis() as $k => $desc) {
			$vars[] = ['chave' => $k, 'descricao' => $desc];
		}

		return [
			'colunas_ok'          => $colOk,
			'automacao_ativo'     => $integ instanceof EscolaIntegracoes
				? (int)($integ->crm_wa_automacao_ativo ?? 1)
				: 1,
			'statuses'            => $statuses,
			'variaveis'           => $vars,
		];
	}

	public static function salvarConfiguracao(int $idAdmin, array $post): bool {
		if (!EscolaIntegracoes::temColunasCrmAutomacao()) {
			return false;
		}

		$dados = [
			'crm_wa_automacao_ativo' => !empty($post['automacao_ativo']) ? 1 : 0,
			'crm_wa_enviar_novo' => !empty($post['enviar_novo']) ? 1 : 0,
			'crm_wa_enviar_em_atendimento' => !empty($post['enviar_em_atendimento']) ? 1 : 0,
			'crm_wa_enviar_matriculado' => !empty($post['enviar_matriculado']) ? 1 : 0,
		];

		foreach (self::$statusComMensagem as $status) {
			$cfg = self::mapaCamposStatus($status);
			if ($cfg === null) {
				continue;
			}
			$chavePost = 'msg_'.$status;
			$texto = trim((string)($post[$chavePost] ?? ''));
			$padrao = self::mensagemPadrao($status) ?? '';
			$dados[$cfg['msg']] = ($texto === '' || $texto === $padrao) ? null : $texto;
		}

		return EscolaIntegracoes::salvarCrmAutomacao($idAdmin, $dados);
	}

	public static function restaurarPadrao(int $idAdmin): bool {
		if (!EscolaIntegracoes::temColunasCrmAutomacao()) {
			return false;
		}
		return EscolaIntegracoes::salvarCrmAutomacao($idAdmin, [
			'crm_wa_automacao_ativo' => 1,
			'crm_wa_enviar_novo' => 1,
			'crm_wa_enviar_em_atendimento' => 1,
			'crm_wa_enviar_matriculado' => 1,
			'crm_wa_msg_novo' => null,
			'crm_wa_msg_em_atendimento' => null,
			'crm_wa_msg_matriculado' => null,
		]);
	}

	private static function mapaCamposStatus(string $status): ?array {
		switch ($status) {
			case 'novo':
				return ['enviar' => 'crm_wa_enviar_novo', 'msg' => 'crm_wa_msg_novo'];
			case 'em_atendimento':
				return ['enviar' => 'crm_wa_enviar_em_atendimento', 'msg' => 'crm_wa_msg_em_atendimento'];
			case 'matriculado':
				return ['enviar' => 'crm_wa_enviar_matriculado', 'msg' => 'crm_wa_msg_matriculado'];
			default:
				return null;
		}
	}
}
