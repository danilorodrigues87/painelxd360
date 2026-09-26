<?php

namespace App\Common\Helpers;

use App\Common\ProductModules;
use App\Model\Entity\ClientesAssinantes;
use App\Model\Entity\PlanosAssinatura;
use App\Model\Entity\SaasEmpresaXd360;
use App\Model\Entity\User as EntityUser;

class SaasContratoVariaveisBuilder {

	/**
	 * @return array<string,string>
	 */
	public static function montarFromEscola(ClientesAssinantes $escola): array {
		return self::montar($escola, null);
	}

	/**
	 * @param array<string,mixed> $opts id_escola, id_plano
	 * @return array<string,string>
	 */
	public static function dadosExemplo(array $opts = []): array {
		$idEscola = (int)($opts['id_escola'] ?? 0);
		$idPlano = (int)($opts['id_plano'] ?? 0);

		if ($idEscola > 0) {
			$escola = ClientesAssinantes::getEscolaById($idEscola);
			if ($escola instanceof ClientesAssinantes) {
				return self::montar($escola, $idPlano > 0 ? $idPlano : null);
			}
		}

		return self::montarExemploFicticio($idPlano);
	}

	/**
	 * @return array{ok:bool,faltando:string[],vars:array<string,string>}
	 */
	public static function montarComMeta(ClientesAssinantes $escola): array {
		$vars = self::montarFromEscola($escola);
		$faltando = self::listarPendencias($escola);
		return [
			'ok'       => empty($faltando),
			'faltando' => $faltando,
			'vars'     => $vars,
		];
	}

	/** @return string[] */
	public static function listarPendencias(?ClientesAssinantes $escola = null): array {
		$faltando = [];
		$emp = SaasEmpresaXd360::get();
		$check = SaasEmpresaXd360Helper::checarCompleto($emp);
		if (!$check['ok']) {
			$faltando = array_merge($faltando, $check['faltando']);
		}
		if ($escola instanceof ClientesAssinantes) {
			$cnpj = preg_replace('/\D+/', '', (string)($escola->cpf_cnpj ?? ''));
			if ($cnpj === '') {
				$faltando[] = 'CNPJ do cliente';
			}
			$dir = self::buscarDiretor((int)$escola->id);
			if (!$dir instanceof EntityUser) {
				$faltando[] = 'Administrador ativo do cliente';
			} elseif (strlen(preg_replace('/\D+/', '', (string)($dir->cpf ?? ''))) !== 11) {
				$faltando[] = 'CPF do administrador (Perfil)';
			}
		}
		return array_values(array_unique($faltando));
	}

	/**
	 * @return array<string,string>
	 */
	private static function montar(ClientesAssinantes $escola, ?int $planoOverride): array {
		$plano = self::resolverPlano($escola, $planoOverride);
		$cidadeUf = self::cidadeUfEscola($escola);
		$baseUrl = rtrim((string)(defined('URL') ? URL : ''), '/');
		$emp = SaasEmpresaXd360Helper::getOuDefaults();
		$diretor = self::buscarDiretor((int)$escola->id);

		return [
			'URL'                        => $baseUrl,
			'licenciante'                => self::htmlLicenciante($emp),
			'representante_licenciante'  => self::htmlRepresentanteLicenciante($emp),
			'licenciada'                 => self::htmlLicenciada($escola, $cidadeUf),
			'representante_licenciada'   => self::htmlRepresentanteLicenciada($diretor, $escola),
			'plano_resumo'               => self::htmlPlanoResumo($escola, $plano),
			'plano_descricao'            => self::htmlPlanoDescricao($plano),
			'modulos_contratados'        => self::htmlModulos($escola, $plano),
			'condicoes_financeiras'      => self::htmlCondicoesFinanceiras($escola, $plano),
			'foro'                       => htmlspecialchars(SaasEmpresaXd360Helper::resolverForo($emp), ENT_QUOTES, 'UTF-8'),
			'assinaturas'                => self::htmlAssinaturas($emp, $diretor, (string)$escola->nome),
			'trial_dias'                 => (string)SaasAssinaturaService::TRIAL_DIAS_DEFAULT,
			'grace_dias'                 => (string)SaasAssinaturaService::GRACE_DIAS,
			'url_privacidade'            => $baseUrl.'/privacidade',
			'data_contrato'              => self::htmlDataContrato(date('Y-m-d'), $cidadeUf),
		];
	}

	/** @return array<string,string> */
	private static function montarExemploFicticio(int $idPlano): array {
		$escola = new ClientesAssinantes();
		$escola->id = 0;
		$escola->nome = 'Empresa Modelo Ltda.';
		$escola->cpf_cnpj = '00000000000191';
		$escola->email = 'admin@empresamodelo.com.br';
		$escola->telefone = '(14) 99999-0000';
		$escola->endereco = 'Rua Exemplo';
		$escola->numero = '100';
		$escola->bairro = 'Centro';
		$escola->cep = '18300000';
		$escola->plan_id = $idPlano > 0 ? $idPlano : 2;
		$escola->dia_vencimento_assinatura = 10;
		$escola->assinatura_status = 'trial';
		$escola->trial_ate = date('Y-m-d', strtotime('+'.SaasAssinaturaService::TRIAL_DIAS_DEFAULT.' days'));

		$vars = self::montar($escola, $idPlano > 0 ? $idPlano : null);
		$vars['representante_licenciada'] = '<p><strong>Representante legal da LICENCIADA:</strong> '
			.'Maria Silva, CPF 123.456.789-00, na qualidade de Administradora do cliente.</p>';
		return $vars;
	}

	private static function resolverPlano(ClientesAssinantes $escola, ?int $override): ?PlanosAssinatura {
		$planId = $override !== null && $override > 0
			? $override
			: (ClientesAssinantes::temColunaPlanId() ? (int)($escola->plan_id ?? 0) : 0);
		if ($planId <= 0) {
			return null;
		}
		$plano = PlanosAssinatura::getById($planId);
		return $plano instanceof PlanosAssinatura ? $plano : null;
	}

	private static function cidadeUfEscola(ClientesAssinantes $escola): string {
		$nome = trim((string)($escola->cidade_nome ?? ''));
		$uf = strtoupper(trim((string)($escola->uf ?? '')));
		if ($nome !== '' && $uf !== '') {
			return $nome.'/'.$uf;
		}
		return ContratoVariaveisBuilder::resolverCidadeUf(
			(int)($escola->cidade ?? 0),
			(int)($escola->estado ?? 0)
		);
	}

	private static function buscarDiretor(int $idAdmin): ?EntityUser {
		if ($idAdmin <= 0) {
			return null;
		}
		$row = EntityUser::getUser(
			'id_admin = '.$idAdmin.' AND nivel = "Diretor" AND ativo = "s"',
			'id ASC',
			'1'
		)->fetchObject(EntityUser::class);
		return $row instanceof EntityUser ? $row : null;
	}

	private static function htmlLicenciante(SaasEmpresaXd360 $emp): string {
		$razao = htmlspecialchars((string)$emp->razao_social, ENT_QUOTES, 'UTF-8');
		$fantasia = htmlspecialchars((string)$emp->nome_fantasia, ENT_QUOTES, 'UTF-8');
		$cnpj = htmlspecialchars(SaasEmpresaXd360Helper::formatCnpj($emp->cnpj ?? ''), ENT_QUOTES, 'UTF-8');
		$end = htmlspecialchars(SaasEmpresaXd360Helper::resolverEndereco($emp), ENT_QUOTES, 'UTF-8');
		$site = htmlspecialchars(trim((string)($emp->site ?? '')) ?: 'https://xd360.com.br', ENT_QUOTES, 'UTF-8');
		$email = htmlspecialchars(trim((string)($emp->email ?? '')) ?: '—', ENT_QUOTES, 'UTF-8');
		$tel = htmlspecialchars(trim((string)($emp->telefone ?? '')) ?: '—', ENT_QUOTES, 'UTF-8');

		return '<p><strong>LICENCIANTE:</strong> '.$razao.' — '.$fantasia
			.', pessoa jurídica de direito privado, inscrita no CNPJ sob nº <strong>'.$cnpj
			.'</strong>, com sede em '.$end.', operadora da plataforma SaaS XD360, site '
			.'<a href="'.$site.'">'.$site.'</a>, e-mail '.$email.', telefone '.$tel.'.</p>';
	}

	private static function htmlRepresentanteLicenciante(SaasEmpresaXd360 $emp): string {
		$rep = SaasEmpresaXd360Helper::resolverRepresentanteLegal($emp);
		if (!$rep || trim($rep['nome']) === '') {
			return '<p><em>Representante legal da LICENCIANTE: pendente de cadastro em Master → Dados jurídicos XD360.</em></p>';
		}
		$nome = htmlspecialchars($rep['nome'], ENT_QUOTES, 'UTF-8');
		$cpf = htmlspecialchars(SaasEmpresaXd360Helper::formatCpf($rep['cpf'] ?? ''), ENT_QUOTES, 'UTF-8');
		$cargo = htmlspecialchars($rep['cargo'] ?: 'Administrador', ENT_QUOTES, 'UTF-8');
		$rg = trim((string)($rep['rg'] ?? ''));
		$rgHtml = $rg !== '' ? ', RG '.htmlspecialchars($rg, ENT_QUOTES, 'UTF-8') : '';

		return '<p><strong>Representante legal da LICENCIANTE:</strong> '.$nome
			.', CPF '.$cpf.$rgHtml.', na qualidade de '.$cargo.' da XD360.</p>';
	}

	private static function htmlLicenciada(ClientesAssinantes $escola, string $cidadeUf): string {
		$nome = htmlspecialchars((string)$escola->nome, ENT_QUOTES, 'UTF-8');
		$docRaw = preg_replace('/\D+/', '', (string)($escola->cpf_cnpj ?? ''));
		if (strlen($docRaw) === 11) {
			$docLabel = 'pessoa física, inscrita no CPF sob nº';
			$docFmt = SaasEmpresaXd360Helper::formatCpf($docRaw);
		} elseif (strlen($docRaw) === 14) {
			$docLabel = 'pessoa jurídica (empresa ou MEI), inscrita no CNPJ sob nº';
			$docFmt = SaasEmpresaXd360Helper::formatCnpj($docRaw);
		} else {
			$docLabel = 'inscrita no CPF/CNPJ sob nº';
			$docFmt = trim((string)($escola->cpf_cnpj ?? '')) ?: '—';
		}
		$cnpj = htmlspecialchars($docFmt, ENT_QUOTES, 'UTF-8');
		$email = htmlspecialchars(trim((string)($escola->email ?? '')) ?: '—', ENT_QUOTES, 'UTF-8');
		$tel = htmlspecialchars(trim((string)($escola->telefone ?? '')) ?: '—', ENT_QUOTES, 'UTF-8');
		$end = htmlspecialchars(
			ContratoVariaveisBuilder::montarEnderecoEscola((array)$escola, $cidadeUf),
			ENT_QUOTES,
			'UTF-8'
		);

		return '<p><strong>LICENCIADA:</strong> '.$nome
			.', '.$docLabel.' <strong>'.$cnpj
			.'</strong>, com endereço em '.$end
			.', e-mail '.$email.', telefone '.$tel.'.</p>';
	}

	private static function htmlRepresentanteLicenciada(?EntityUser $diretor, ClientesAssinantes $escola): string {
		if (!$diretor instanceof EntityUser) {
			return '<p><em>Quem assina pela LICENCIADA: cadastre o responsável no cliente.</em></p>';
		}
		$nome = htmlspecialchars(trim((string)$diretor->nome), ENT_QUOTES, 'UTF-8');
		$cpf = htmlspecialchars(SaasEmpresaXd360Helper::formatCpf($diretor->cpf ?? ''), ENT_QUOTES, 'UTF-8');
		$email = htmlspecialchars(trim((string)($diretor->email ?? '')), ENT_QUOTES, 'UTF-8');
		$doc = preg_replace('/\D+/', '', (string)($escola->cpf_cnpj ?? ''));
		if (strlen($doc) === 11) {
			return '<p><strong>Assinatura:</strong> '.$nome
				.', CPF '.$cpf.', e-mail '.$email.', assina em nome próprio.</p>';
		}
		return '<p><strong>Responsável que assina pela LICENCIADA:</strong> '.$nome
			.', CPF '.$cpf.', e-mail '.$email.'.</p>';
	}

	private static function htmlPlanoResumo(ClientesAssinantes $escola, ?PlanosAssinatura $plano): string {
		$contratos = \App\Model\Entity\SaasContrato::vigentesDoCliente((int)$escola->id);
		if (!empty($contratos)) {
			$html = '';
			foreach ($contratos as $c) {
				$fmt = SaasContratoService::formatar($c);
				$html .= '<p><strong>Plano contratado:</strong> '.htmlspecialchars($fmt['plano_nome'], ENT_QUOTES, 'UTF-8')
					.' ('.htmlspecialchars($fmt['produto_label'], ENT_QUOTES, 'UTF-8').')<br>'
					.'<strong>Valor da parcela:</strong> R$ '.$fmt['valor_br']
					.' <em>(valor gravado neste contrato; alteração posterior do catálogo não o modifica)</em>.<br>'
					.'<strong>Vigência:</strong> '.htmlspecialchars((string)($fmt['inicio_br'] ?: $fmt['inicio']), ENT_QUOTES, 'UTF-8')
					.' a '.htmlspecialchars((string)($fmt['fim_br'] ?: $fmt['fim']), ENT_QUOTES, 'UTF-8').'</p>';
			}
			return $html;
		}
		$valor = SaasAssinaturaService::resolverValorMensal($escola);
		$valorBr = $valor > 0 ? 'R$ '.number_format($valor, 2, ',', '.') : 'conforme proposta comercial';
		$custom = ClientesAssinantes::temColunaValorMensalCustom()
			&& (float)($escola->valor_mensal_custom ?? 0) > 0;

		$nome = $plano instanceof PlanosAssinatura
			? htmlspecialchars((string)$plano->nome, ENT_QUOTES, 'UTF-8')
			: 'Personalizado';

		$statusLabel = self::labelStatusAssinatura(
			ClientesAssinantes::temColunasAssinatura() ? (string)($escola->assinatura_status ?? 'ativa') : 'ativa',
			$escola
		);
		$customNota = $custom
			? ' <em>(valor mensal customizado acordado com a XD360, prevalecendo sobre a tabela do plano)</em>.'
			: '.';

		return '<p><strong>Plano contratado:</strong> '.$nome.'<br>'
			.'<strong>Valor mensal de referência:</strong> '.$valorBr.$customNota.'<br>'
			.'<strong>Situação da assinatura:</strong> '.$statusLabel.'</p>';
	}

	private static function htmlPlanoDescricao(?PlanosAssinatura $plano): string {
		if (!$plano instanceof PlanosAssinatura) {
			return '<p><strong>Descrição do plano:</strong> Plano personalizado negociado individualmente com a XD360, '
				.'com escopo e funcionalidades definidos em proposta comercial.</p>';
		}

		$nome = htmlspecialchars((string)$plano->nome, ENT_QUOTES, 'UTF-8');
		$detalhada = PlanosAssinatura::getDescricaoDetalhada($plano);
		$resumo = trim((string)($plano->descricao ?? ''));

		$html = '<p><strong>Plano:</strong> '.$nome.'</p>';

		if ($detalhada !== '') {
			$html .= '<div class="plano-descricao-detalhada"><strong>Funcionalidades e escopo contratados:</strong>'
				.self::textoParaHtml($detalhada).'</div>';
		} elseif ($resumo !== '') {
			$html .= '<p><strong>Resumo:</strong> '.htmlspecialchars($resumo, ENT_QUOTES, 'UTF-8').'</p>';
		} else {
			$html .= '<p><em>Descrição detalhada do plano não cadastrada. Consulte a lista de módulos abaixo.</em></p>';
		}

		return $html;
	}

	private static function textoParaHtml(string $texto): string {
		$linhas = preg_split('/\r\n|\r|\n/', trim($texto)) ?: [];
		$items = [];
		$paragrafos = [];
		foreach ($linhas as $linha) {
			$linha = trim($linha);
			if ($linha === '') {
				continue;
			}
			if (preg_match('/^[-*•]\s+/', $linha)) {
				$items[] = '<li>'.htmlspecialchars(preg_replace('/^[-*•]\s+/', '', $linha), ENT_QUOTES, 'UTF-8').'</li>';
			} else {
				if (!empty($items)) {
					$paragrafos[] = '<ul>'.implode('', $items).'</ul>';
					$items = [];
				}
				$paragrafos[] = '<p>'.htmlspecialchars($linha, ENT_QUOTES, 'UTF-8').'</p>';
			}
		}
		if (!empty($items)) {
			$paragrafos[] = '<ul>'.implode('', $items).'</ul>';
		}
		return implode('', $paragrafos);
	}

	private static function labelStatusAssinatura(string $status, ClientesAssinantes $escola): string {
		if (SaasAssinaturaService::emTrialAtivo($escola)) {
			$ate = ClientesAssinantes::temColunaTrialAte() ? trim((string)($escola->trial_ate ?? '')) : '';
			$ateBr = $ate !== '' ? DateTimeHelper::databr($ate) : '—';
			return 'Período de experiência (trial) até '.$ateBr;
		}
		$map = [
			'ativa'     => 'Ativa',
			'suspensa'  => 'Suspensa por inadimplência',
			'trial'     => 'Trial encerrado — aguardando regularização',
			'cancelada' => 'Cancelada',
		];
		return $map[$status] ?? ucfirst($status);
	}

	private static function htmlModulos(?ClientesAssinantes $escola, ?PlanosAssinatura $plano): string {
		if ($escola instanceof ClientesAssinantes) {
			$contratos = \App\Model\Entity\SaasContrato::vigentesDoCliente((int)$escola->id);
			if (!empty($contratos)) {
				$items = '';
				foreach ($contratos as $c) {
					$fmt = SaasContratoService::formatar($c);
					$extra = '';
					if (!empty($fmt['modulos'])) {
						$extra = ' — módulos: '.htmlspecialchars(implode(', ', $fmt['modulos']), ENT_QUOTES, 'UTF-8');
					}
					$items .= '<li>'.htmlspecialchars($fmt['produto_label'].' / '.$fmt['plano_nome'], ENT_QUOTES, 'UTF-8').$extra.'</li>';
				}
				return '<p><strong>Produtos e módulos contratados:</strong></p><ul>'.$items.'</ul>';
			}
		}
		$slugs = [];
		if ($plano instanceof PlanosAssinatura) {
			if ($plano->temTodosModulos()) {
				return '<p><strong>Produtos contratados:</strong> todos os produtos disponíveis no catálogo '
					.'comercial da XD360.</p>';
			}
			$slugs = $plano->getSlugs();
		} elseif ($escola instanceof ClientesAssinantes && !empty($escola->modulos_liberados)) {
			$decoded = json_decode((string)$escola->modulos_liberados, true);
			if (is_array($decoded)) {
				$validos = array_flip(ProductModules::getSlugs());
				foreach ($decoded as $s) {
					$s = (string)$s;
					if (isset($validos[$s])) {
						$slugs[] = $s;
					}
				}
			}
		}

		if (empty($slugs)) {
			return '<p><strong>Produtos contratados:</strong> conforme liberação registrada no cadastro do cliente '
				.'no Painel Master XD360.</p>';
		}

		$items = '';
		foreach ($slugs as $slug) {
			$label = ProductModules::slugParaLabel($slug) ?: $slug;
			$items .= '<li>'.htmlspecialchars($label, ENT_QUOTES, 'UTF-8').'</li>';
		}

		return '<p><strong>Produtos liberados no painel:</strong></p><ul>'.$items.'</ul>';
	}

	private static function htmlCondicoesFinanceiras(ClientesAssinantes $escola, ?PlanosAssinatura $plano): string {
		$contratos = \App\Model\Entity\SaasContrato::vigentesDoCliente((int)$escola->id);
		if (!empty($contratos)) {
			$lis = '';
			foreach ($contratos as $c) {
				$fmt = SaasContratoService::formatar($c);
				$lis .= '<li><strong>'.htmlspecialchars($fmt['plano_nome'], ENT_QUOTES, 'UTF-8').':</strong> '
					.$fmt['qtd_parcelas'].' parcela(s) de R$ '.$fmt['valor_br']
					.', duração '.$fmt['duracao_meses'].' mês(es), ciclo '.htmlspecialchars($fmt['ciclo'], ENT_QUOTES, 'UTF-8').'.</li>';
			}
			return '<p>As condições abaixo são as gravadas no contrato. Mudança de preço no catálogo vale só para contratos novos ou para renovação em que a XD360 optar pelo valor atual do catálogo.</p>'
				.'<ul>'.$lis
				.'<li><strong>Pagamento de cada parcela:</strong> Pix, cartão de crédito ou boleto, no Checkout Transparente Mercado Pago da área Assinatura.</li>'
				.'<li><strong>Tolerância:</strong> '.SaasAssinaturaService::GRACE_DIAS.' dias após o vencimento antes da suspensão.</li>'
				.'</ul>';
		}
		$valor = SaasAssinaturaService::resolverValorMensal($escola);
		$valorBr = $valor > 0 ? 'R$ '.number_format($valor, 2, ',', '.') : 'valor definido em proposta';
		$dia = ClientesAssinantes::temColunasAssinatura()
			? max(1, min(28, (int)($escola->dia_vencimento_assinatura ?? 10)))
			: 10;
		$trial = SaasAssinaturaService::TRIAL_DIAS_DEFAULT;
		$grace = SaasAssinaturaService::GRACE_DIAS;

		$planoNome = $plano instanceof PlanosAssinatura
			? htmlspecialchars((string)$plano->nome, ENT_QUOTES, 'UTF-8')
			: 'Personalizado';

		return '<p>As condições comerciais abaixo complementam o plano <strong>'.$planoNome.'</strong>:</p>'
			.'<ul>'
			.'<li><strong>Periodicidade:</strong> mensal, por competência (mês/ano), vencimento todo dia '.$dia.';</li>'
			.'<li><strong>Valor base:</strong> '.$valorBr.' por mês;</li>'
			.'<li><strong>Pagamento:</strong> PIX via Mercado Pago na área Assinatura;</li>'
			.'<li><strong>Trial:</strong> '.$trial.' dias quando concedido;</li>'
			.'<li><strong>Tolerância:</strong> '.$grace.' dias após vencimento antes da suspensão.</li>'
			.'</ul>';
	}

	private static function htmlAssinaturas(SaasEmpresaXd360 $emp, ?EntityUser $diretor, string $nomeEscola): string {
		$repData = SaasEmpresaXd360Helper::resolverRepresentanteLegal($emp);
		$repCti = ($repData && trim($repData['nome']) !== '')
			? $repData['nome']
			: 'Representante XD360';
		$repEsc = ($diretor instanceof EntityUser && trim((string)$diretor->nome) !== '')
			? (string)$diretor->nome
			: 'Representante legal — '.trim($nomeEscola);

		$repCti = htmlspecialchars($repCti, ENT_QUOTES, 'UTF-8');
		$repEsc = htmlspecialchars($repEsc, ENT_QUOTES, 'UTF-8');

		return '<div class="assinaturas"><table><tr>'
			.'<td><div class="linha-ass"></div><strong>LICENCIANTE</strong><br>XD360<br>'.$repCti.'</td>'
			.'<td><div class="linha-ass"></div><strong>LICENCIADA</strong><br>'
			.htmlspecialchars(trim($nomeEscola), ENT_QUOTES, 'UTF-8').'<br>'.$repEsc.'</td>'
			.'</tr></table></div>';
	}

	private static function htmlDataContrato(string $data, string $cidadeUf): string {
		$dia = DateTimeHelper::extraiDia($data);
		$ano = DateTimeHelper::extraiAno($data);
		$mesSemZero = ltrim(DateTimeHelper::extraiMes($data), '0');
		$mes = DateTimeHelper::imprimeMes($mesSemZero);
		$local = $cidadeUf !== '' ? htmlspecialchars($cidadeUf, ENT_QUOTES, 'UTF-8') : 'Local';

		return '<p style="text-align: right;"><strong>'.$local.'</strong>, '.$dia.' de '.$mes.' de '.$ano.'.</p>';
	}
}
