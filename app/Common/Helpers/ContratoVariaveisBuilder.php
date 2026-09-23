<?php

namespace App\Common\Helpers;

use App\Model\Entity\CategoryCourses;
use App\Model\Entity\EstadoCidades;
use App\Model\Entity\Matriculas as EntityMatri;
use App\Model\Entity\Responsaveis as EntityRes;
use App\Model\Entity\Trilhas as EntityTrilhas;
use App\Model\Entity\User as EntityUser;

class ContratoVariaveisBuilder {

	public static function resolverCidadeUf(int $cidadeId, int $estadoId): string {
		$cidadeNome = '';
		$estadoSigla = '';
		if ($cidadeId > 0) {
			$cidade = EstadoCidades::getCidades('id = '.$cidadeId)->fetchObject();
			if (is_object($cidade)) {
				$cidadeNome = (string)($cidade->nome ?? '');
			}
		}
		if ($estadoId > 0) {
			$estado = EstadoCidades::getEstados('id = '.$estadoId)->fetchObject();
			if (is_object($estado)) {
				$estadoSigla = (string)($estado->sigla ?? '');
			}
		}
		return trim($cidadeNome.($estadoSigla !== '' ? '/'.$estadoSigla : ''));
	}

	public static function montarEnderecoEscola(array $escola, string $cidadeUf = ''): string {
		$partes = [];
		foreach ([
			trim((string)($escola['endereco'] ?? '')),
			trim((string)($escola['numero'] ?? '')),
			trim((string)($escola['bairro'] ?? '')),
			$cidadeUf,
		] as $parte) {
			if ($parte !== '') {
				$partes[] = $parte;
			}
		}
		return $partes ? implode(', ', $partes) : 'endereço não informado';
	}

	/**
	 * @return array<string,string>
	 */
	public static function montarFromMatricula(int $matriculaId, array $escolaSession): array {
		$dados = (array) EntityMatri::getMatriculaById($matriculaId);
		$aluno = (array) EntityUser::getUserById((int)($dados['id_aluno'] ?? 0));
		$trilha = (array) EntityTrilhas::getTrilhaById((int)($dados['id_trilha'] ?? 0));

		$responsavel = null;
		$nasc = (string)($aluno['nascimento'] ?? '');
		if ($nasc !== '' && $nasc !== '0000-00-00') {
			try {
				$idade = (new \DateTime())->diff(new \DateTime($nasc))->y;
				if ($idade < 18 && !empty($aluno['id_responsavel'])) {
					$responsavel = (array) EntityRes::getResById((int)$aluno['id_responsavel']);
				}
			} catch (\Throwable $e) {
				// ignora data inválida
			}
		}

		$cidadeUf = self::resolverCidadeUf(
			(int)($escolaSession['cidade'] ?? 0),
			(int)($escolaSession['estado'] ?? 0)
		);

		return self::montar([
			'escola'      => $escolaSession,
			'cidade_uf'   => $cidadeUf,
			'matricula'   => $dados,
			'aluno'       => $aluno,
			'trilha'      => $trilha,
			'responsavel' => $responsavel,
		]);
	}

	/**
	 * Dados fictícios para pré-visualização.
	 *
	 * @param array<string,mixed> $escolaSession
	 * @param array<string,mixed> $opts id_categoria, menor, pagamento, clausulas_override
	 * @return array<string,string>
	 */
	public static function dadosExemplo(array $escolaSession, array $opts = []): array {
		$idCat = (int)($opts['id_categoria'] ?? 0);
		$menor = !empty($opts['menor']);
		$pagamento = (string)($opts['pagamento'] ?? 'parcelado');

		$nomeTrilha = 'Curso Exemplo';
		if ($idCat > 0) {
			$cat = CategoryCourses::getCategoryById($idCat);
			if ($cat instanceof CategoryCourses) {
				$nomeTrilha = (string)$cat->nome;
			}
		}

		$hoje = new \DateTime();
		$inicio = $hoje->format('Y-m-d');
		$fim = (clone $hoje)->modify('+12 months')->format('Y-m-d');
		$parcelado = ($pagamento === 'parcelado');

		$matricula = [
			'inicio'                => $inicio,
			'fim'                   => $fim,
			'modulos'               => 'Módulo 1, Módulo 2, Módulo 3',
			'carga_horaria'         => '120',
			'dia_semana'            => 'Segunda e Quarta',
			'horarios'              => '14:00 às 15:40',
			'qtd_parcelas'          => $parcelado ? 12 : 1,
			'valor'                 => 199.90,
			'dia_vencimento'        => 10,
			'primeiro_mes'          => (int)$hoje->format('m'),
			'primeiro_ano'          => (int)$hoje->format('Y'),
			'aulas_semanais'        => 2,
			'desconto_pontualidade' => $parcelado ? 1 : 0,
			'bolsista'              => $pagamento === 'bolsista' ? 1 : 0,
			'multa_atraso_pct'      => EncargosContratoHelper::DEFAULT_MULTA_ATRASO,
			'juros_mora_pct_mes'    => EncargosContratoHelper::DEFAULT_JUROS_MES,
			'multa_cancelamento_pct'=> EncargosContratoHelper::DEFAULT_MULTA_CANCEL,
			'carencia_dias'         => EncargosContratoHelper::DEFAULT_CARENCIA,
		];

		$nascAluno = $menor
			? (clone $hoje)->modify('-15 years')->format('Y-m-d')
			: (clone $hoje)->modify('-25 years')->format('Y-m-d');

		$aluno = [
			'nome'       => 'Maria Silva (exemplo)',
			'nascimento' => $nascAluno,
			'rg'         => '12.345.678-9',
			'cpf'        => '123.456.789-00',
			'whatsapp'   => '(15) 99999-0000',
			'email'      => 'maria.exemplo@email.com',
			'endereco'   => 'Rua Exemplo, 100 — Centro',
		];

		$responsavel = null;
		if ($menor) {
			$responsavel = [
				'nome'       => 'João Silva (responsável)',
				'nascimento' => (clone $hoje)->modify('-45 years')->format('Y-m-d'),
				'rg'         => '98.765.432-1',
				'cpf'        => '987.654.321-00',
				'whatsapp'   => '(15) 98888-0000',
				'email'      => 'joao.responsavel@email.com',
			];
		}

		$trilha = [
			'nome'         => $nomeTrilha,
			'id_categoria' => $idCat,
		];

		$cidadeUf = self::resolverCidadeUf(
			(int)($escolaSession['cidade'] ?? 0),
			(int)($escolaSession['estado'] ?? 0)
		);

		$ctx = [
			'escola'              => $escolaSession,
			'cidade_uf'           => $cidadeUf,
			'matricula'           => $matricula,
			'aluno'               => $aluno,
			'trilha'              => $trilha,
			'responsavel'         => $responsavel,
			'clausulas_override'  => $opts['clausulas_override'] ?? null,
		];

		return self::montar($ctx);
	}

	/**
	 * @param array{
	 *   escola: array,
	 *   cidade_uf?: string,
	 *   matricula: array,
	 *   aluno: array,
	 *   trilha: array,
	 *   responsavel?: array|null,
	 *   clausulas_override?: array|null
	 * } $ctx
	 * @return array<string,string>
	 */
	public static function montar(array $ctx): array {
		$escola = $ctx['escola'] ?? [];
		$cidadeUf = (string)($ctx['cidade_uf'] ?? '');
		if ($cidadeUf === '') {
			$cidadeUf = self::resolverCidadeUf(
				(int)($escola['cidade'] ?? 0),
				(int)($escola['estado'] ?? 0)
			);
		}
		$enderecoEscola = self::montarEnderecoEscola($escola, $cidadeUf);
		$dados = $ctx['matricula'] ?? [];
		$aluno = $ctx['aluno'] ?? [];
		$trilha = $ctx['trilha'] ?? [];
		$responsavel = $ctx['responsavel'] ?? null;

		$categoriaRow = self::carregarCategoriaRow(
			(int)($trilha['id_categoria'] ?? 0),
			$ctx['clausulas_override'] ?? null
		);

		$clausulas = ContratoClausulaHelper::montarClausulas($categoriaRow, $dados);

		return array_merge([
			'URL'           => defined('URL') ? URL : '',
			'title'         => 'Contrato',
			'contratada'    => self::htmlContratada($escola, $enderecoEscola),
			'contratante'   => self::htmlContratante($aluno, is_array($responsavel) ? $responsavel : null),
			'curso'         => self::htmlCurso($dados, $trilha, $categoriaRow),
			'data_contrato' => self::htmlDataContrato((string)($dados['inicio'] ?? date('Y-m-d')), $cidadeUf),
		], $clausulas);
	}

	/**
	 * @param array<string,mixed>|null $override
	 * @return array<string,mixed>|null
	 */
	private static function carregarCategoriaRow(int $idCategoria, ?array $override): ?array {
		if (is_array($override)) {
			return $override;
		}
		if ($idCategoria <= 0 || !CategoryCourses::temColunaContrato()) {
			return null;
		}
		$cat = CategoryCourses::getCategoryById($idCategoria);
		if (!$cat instanceof CategoryCourses) {
			return null;
		}
		return CategoryCourses::rowToContratoArray($cat);
	}

	private static function htmlContratada(array $escola, string $enderecoEscola): string {
		$logoEscola = BrandingHelper::urlLogoEscola($escola['logo'] ?? null);
		$html = '<img src="'.htmlspecialchars($logoEscola, ENT_QUOTES, 'UTF-8').'" width="100" alt="Logo">';
		$html .= '<h2 style="text-align: center;">Contrato de Prestação de Serviços Educacionais</h2><hr><br>';
		$html .= '<b>CONTRATADA</b><br>';
		$html .= '<b>Escola: </b><span>'.htmlspecialchars((string)($escola['nome'] ?? ''), ENT_QUOTES, 'UTF-8')
			.' - <b>CNPJ: </b>'.htmlspecialchars((string)($escola['cpf_cnpj'] ?? ''), ENT_QUOTES, 'UTF-8').'</span><br>';
		$html .= '<b>Telefone: </b><span>'.htmlspecialchars((string)($escola['telefone'] ?? ''), ENT_QUOTES, 'UTF-8').'</span>'
			.' <b>Endereço: </b><span>'.htmlspecialchars($enderecoEscola, ENT_QUOTES, 'UTF-8').'</span><br>';
		$html .= '<b>Site: </b><span>'.htmlspecialchars((string)($escola['site'] ?? ''), ENT_QUOTES, 'UTF-8').'</span>'
			.'<b> Email: </b><span>'.htmlspecialchars((string)($escola['email'] ?? ''), ENT_QUOTES, 'UTF-8').'</span><br><br>';
		return $html;
	}

	/**
	 * @param array<string,mixed> $aluno
	 * @param array<string,mixed>|null $responsavel
	 */
	private static function htmlContratante(array $aluno, ?array $responsavel): string {
		$nascStr = (string)($aluno['nascimento'] ?? '');
		$nascFmt = '—';
		if ($nascStr !== '' && $nascStr !== '0000-00-00') {
			try {
				$nascFmt = (new \DateTime($nascStr))->format('d/m/Y');
			} catch (\Throwable $e) {
				$nascFmt = $nascStr;
			}
		}

		$html = '<b>CONTRATANTE/ALUNO</b><br>';
		$html .= '<b>Aluno: </b><span>'.htmlspecialchars((string)($aluno['nome'] ?? ''), ENT_QUOTES, 'UTF-8')
			.'</span><b> Data de Nascimento: </b><span>'.$nascFmt.'</span><br>';

		if (!empty($aluno['rg'])) {
			$html .= '<b>RG: </b><span>'.htmlspecialchars((string)$aluno['rg'], ENT_QUOTES, 'UTF-8').'</span>';
		}
		if (!empty($aluno['cpf'])) {
			$html .= ' <b>CPF: </b><span class="mascara-cpf"> '.htmlspecialchars((string)$aluno['cpf'], ENT_QUOTES, 'UTF-8').' </span>';
		}

		$html .= '<b>Telefone: </b><span class="mascara-celular"> '
			.htmlspecialchars((string)($aluno['whatsapp'] ?? ''), ENT_QUOTES, 'UTF-8')
			.' </span><b> Email: </b><span>'.htmlspecialchars((string)($aluno['email'] ?? ''), ENT_QUOTES, 'UTF-8').'<br></span>';

		if ($responsavel) {
			$nascResFmt = '—';
			$nascRes = (string)($responsavel['nascimento'] ?? '');
			if ($nascRes !== '' && $nascRes !== '0000-00-00') {
				try {
					$nascResFmt = (new \DateTime($nascRes))->format('d/m/Y');
				} catch (\Throwable $e) {
					$nascResFmt = $nascRes;
				}
			}
			$html .= '<b>Responssável: </b><span>'.htmlspecialchars((string)($responsavel['nome'] ?? ''), ENT_QUOTES, 'UTF-8').'</span>';
			$html .= '<b> Data de Nascimento: </b><span>'.$nascResFmt.'</span><br>';
			$html .= '<b>RG: </b><span>'.htmlspecialchars((string)($responsavel['rg'] ?? ''), ENT_QUOTES, 'UTF-8').'</span>';
			$html .= '<b> CPF: </b><span>'.htmlspecialchars((string)($responsavel['cpf'] ?? ''), ENT_QUOTES, 'UTF-8').'</span><br>';
			$html .= '<b>Telefone: </b><span>'.htmlspecialchars((string)($responsavel['whatsapp'] ?? ''), ENT_QUOTES, 'UTF-8').'</span>';
			$html .= ' <b> Email: </b><span>'.htmlspecialchars((string)($responsavel['email'] ?? ''), ENT_QUOTES, 'UTF-8').'</span><br>';
		}

		$html .= '<b>Endereço: </b><span>'.htmlspecialchars((string)($aluno['endereco'] ?? ''), ENT_QUOTES, 'UTF-8').'</span><hr>';
		return $html;
	}

	/**
	 * @param array<string,mixed> $dados
	 * @param array<string,mixed> $trilha
	 * @param array<string,mixed>|null $categoriaRow
	 */
	private static function htmlCurso(array $dados, array $trilha, ?array $categoriaRow): string {
		try {
			$dataInicio = new \DateTime((string)($dados['inicio'] ?? 'now'));
			$termino = new \DateTime((string)($dados['fim'] ?? 'now'));
		} catch (\Throwable $e) {
			$dataInicio = new \DateTime();
			$termino = new \DateTime();
		}

		$html = '<br><br><b>Formação em: </b><span>'.htmlspecialchars((string)($trilha['nome'] ?? ''), ENT_QUOTES, 'UTF-8').' </span><br><br>';
		$html .= '<span>( '.htmlspecialchars((string)($dados['modulos'] ?? ''), ENT_QUOTES, 'UTF-8').' )</span><br><br>';
		$html .= '<span>Carga Horária <b>'.htmlspecialchars((string)($dados['carga_horaria'] ?? ''), ENT_QUOTES, 'UTF-8').' Horas</b></span><br>';
		$html .= '<span>Inicio das aulas dia <b>'.$dataInicio->format('d/m/Y').'</b></span><br>';
		$html .= '<spam>Dia(s) de aula(s) escolhido(s): <b>'.htmlspecialchars((string)($dados['dia_semana'] ?? ''), ENT_QUOTES, 'UTF-8')
			.'</b> no horário <b>'.htmlspecialchars((string)($dados['horarios'] ?? ''), ENT_QUOTES, 'UTF-8').'</b></span><br>';
		$html .= '<span>Termino previsto para dia <b>'.$termino->format('d/m/Y').'</b></span><br>';

		$html .= self::htmlPagamento($dados, $categoriaRow);
		return $html;
	}

	/**
	 * @param array<string,mixed> $dados
	 * @param array<string,mixed>|null $categoriaRow
	 */
	private static function htmlPagamento(array $dados, ?array $categoriaRow): string {
		$row = is_array($categoriaRow) ? $categoriaRow : [];
		$tokens = ContratoClausulaHelper::contextoTokens($dados);
		$sugeridos = ContratoClausulaHelper::modelosSugeridos();

		$ehBolsista = EntityMatri::temColunaBolsista() && !empty($dados['bolsista']);
		$parcelado = (int)($dados['qtd_parcelas'] ?? 0) > 1;

		if ($ehBolsista) {
			$tpl = trim((string)($row['contrato_pagamento_bolsista'] ?? ''));
			return ContratoClausulaHelper::aplicarTokens(
				$tpl !== '' ? $tpl : $sugeridos['pagamento_bolsista'],
				$tokens
			);
		}

		if ($parcelado) {
			$tpl = trim((string)($row['contrato_pagamento_parcelado'] ?? ''));
			$html = ContratoClausulaHelper::aplicarTokens(
				$tpl !== '' ? $tpl : $sugeridos['pagamento_parcelado'],
				$tokens
			);
			if (!empty($dados['desconto_pontualidade'])) {
				$obsTpl = trim((string)($row['contrato_obs_pontualidade'] ?? ''));
				$html .= ContratoClausulaHelper::aplicarTokens(
					$obsTpl !== '' ? $obsTpl : ContratoClausulaHelper::textoObsPontualidadePadrao(),
					$tokens
				);
			}
			return $html;
		}

		$tpl = trim((string)($row['contrato_pagamento_vista'] ?? ''));
		return ContratoClausulaHelper::aplicarTokens(
			$tpl !== '' ? $tpl : $sugeridos['pagamento_vista'],
			$tokens
		);
	}

	private static function htmlDataContrato(string $inicio, string $cidadeUf): string {
		$dia = DateTimeHelper::extraiDia($inicio);
		$ano = DateTimeHelper::extraiAno($inicio);
		$mesSemZero = ltrim(DateTimeHelper::extraiMes($inicio), '0');
		$mes = DateTimeHelper::imprimeMes($mesSemZero);
		$local = $cidadeUf !== '' ? htmlspecialchars($cidadeUf, ENT_QUOTES, 'UTF-8') : 'Local';
		return '<p style="text-align: right;"><b>'.$local.'</b> dia '.$dia.' de '.$mes.' de '.$ano.'</p><br>';
	}
}
